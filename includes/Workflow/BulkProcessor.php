<?php
/**
 * Bulk workflow actions.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Workflow;

use Sit_Cwm\Content\PostRepository;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies one workflow action to many posts, one post at a time.
 *
 * There is deliberately no bulk shortcut: every post goes through the same
 * WorkflowManager method a single request would use, so authorization, the
 * post gate, transition validity, activity logging and the D10 actions all
 * run per post. A permission check is never hoisted out of the loop, which is
 * what keeps bulk actions from becoming a privilege-escalation path.
 *
 * Every post-gate failure (missing, trashed, not workflow-enabled, or not
 * readable by the acting user) is reported with the identical
 * `sit_cwm_invalid_post` error, so a batch response never confirms that a
 * hidden post exists.
 *
 * @since 1.0.0
 */
final class BulkProcessor {

	/**
	 * Largest number of posts one batch may touch.
	 *
	 * A hard cap so one request cannot walk the whole site; the REST route
	 * enforces the same limit in its schema.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_ITEMS = 100;

	/**
	 * Batches larger than this raise the memory limit to the admin limit.
	 *
	 * The time limit is never lifted: `MAX_ITEMS` is what bounds the runtime.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const LARGE_BATCH = 50;

	/**
	 * Supported actions => the payload key each one requires.
	 *
	 * @since 1.0.0
	 * @var array<string, string>
	 */
	const ACTIONS = array(
		'change_status'   => 'status',
		'assign_reviewer' => 'reviewer_id',
		'set_due_date'    => 'due_date',
	);

	/**
	 * Native post statuses that are never processed.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const SKIPPED_POST_STATUSES = array( 'trash', 'auto-draft' );

	/**
	 * Workflow orchestrator.
	 *
	 * @since 1.0.0
	 * @var WorkflowManager
	 */
	private $workflow;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param WorkflowManager $workflow Workflow orchestrator.
	 */
	public function __construct( WorkflowManager $workflow ) {
		$this->workflow = $workflow;
	}

	/**
	 * Applies an action to every post and reports each outcome.
	 *
	 * Duplicate ids are processed once. For `change_status` the transition
	 * starts from each post's own current status (a client cannot supply one
	 * `from` for many posts); posts whose status makes the move illegal fail
	 * with the usual transition error instead of being forced.
	 *
	 * Each success logs its own activity entry and fires its own action, exactly
	 * as a single request would; no aggregate event is fired.
	 *
	 * @since 1.0.0
	 *
	 * @param int[]    $post_ids Post ids.
	 * @param string   $action   One of the `ACTIONS` keys.
	 * @param array    $payload  Action payload, e.g. `[ 'status' => 'approved' ]`.
	 * @param int|null $user_id  Acting user id; null for the current user.
	 * @return array{succeeded: int[], failed: array<int, array{post_id: int, code: string, message: string, status: int}>, items: array[]}|WP_Error
	 *         WP_Error only when the batch itself is malformed; per-post
	 *         failures are reported in `failed`.
	 */
	public function process( array $post_ids, string $action, array $payload, ?int $user_id = null ) {
		$post_ids = $this->normalize_ids( $post_ids );

		if ( array() === $post_ids || count( $post_ids ) > self::MAX_ITEMS ) {
			return new WP_Error(
				'sit_cwm_invalid_batch',
				/* translators: %d: Maximum number of items in one batch. */
				sprintf( __( 'A batch must contain between 1 and %d items.', 'sapphireit-editorial-workflow' ), self::MAX_ITEMS ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( self::ACTIONS[ $action ] ) || ! array_key_exists( self::ACTIONS[ $action ], $payload ) ) {
			return new WP_Error(
				'sit_cwm_invalid_payload',
				__( 'The bulk action or its payload is not valid.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $post_ids ) > self::LARGE_BATCH ) {
			wp_raise_memory_limit( 'admin' );
		}

		$user_id = null === $user_id ? get_current_user_id() : max( 0, $user_id );
		$result  = array(
			'succeeded' => array(),
			'failed'    => array(),
			'items'     => array(),
		);

		$this->prime_caches( $post_ids, $action, $payload, $user_id );

		foreach ( $post_ids as $post_id ) {
			$outcome = $this->apply( $post_id, $action, $payload, $user_id );

			if ( is_wp_error( $outcome ) ) {
				$data = $outcome->get_error_data();

				$result['failed'][] = array(
					'post_id' => $post_id,
					'code'    => (string) $outcome->get_error_code(),
					'message' => $outcome->get_error_message(),
					'status'  => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500,
				);

				continue;
			}

			$result['succeeded'][] = $post_id;

			$state = $this->workflow->get_workflow( $post_id, $user_id );

			if ( array() !== $state ) {
				$result['items'][] = $state;
			}
		}

		return $result;
	}

	/**
	 * Runs the single-item WorkflowManager path for one post.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id Post id.
	 * @param string $action  Validated action.
	 * @param array  $payload Action payload.
	 * @param int    $user_id Acting user id.
	 * @return true|WP_Error
	 */
	private function apply( int $post_id, string $action, array $payload, int $user_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || in_array( $post->post_status, self::SKIPPED_POST_STATUSES, true ) ) {
			return $this->invalid_post();
		}

		switch ( $action ) {
			case 'change_status':
				$status = is_string( $payload['status'] ) ? $payload['status'] : '';
				// The post gate inside transition() runs before the stored status is compared.
				$outcome = $this->workflow->transition( $post_id, $this->workflow->get_status( $post_id ), $status, $user_id );
				break;

			case 'assign_reviewer':
				$reviewer_id = is_numeric( $payload['reviewer_id'] ) ? (int) $payload['reviewer_id'] : -1;
				$outcome     = $this->workflow->assign_reviewer( $post_id, $reviewer_id, $user_id );
				break;

			default:
				$date    = is_string( $payload['due_date'] ) ? $payload['due_date'] : '';
				$outcome = $this->workflow->set_due_date( $post_id, $date, $user_id );
				break;
		}

		if ( is_wp_error( $outcome ) && 'sit_cwm_not_managed' === $outcome->get_error_code() ) {
			return $this->invalid_post();
		}

		return is_wp_error( $outcome ) ? $outcome : true;
	}

	/**
	 * Loads every post, its meta and every user the batch will touch up front.
	 *
	 * Posts and meta take two queries; authors, reviewers, the payload reviewer
	 * and the acting user take two more (`cache_users()`). The per-post loop then
	 * reads users from the object cache, so no user query runs per post. This
	 * only warms caches: every permission check still runs inside the loop.
	 *
	 * @since 1.0.0
	 *
	 * @param int[]  $post_ids Normalized post ids.
	 * @param string $action   Validated action.
	 * @param array  $payload  Action payload.
	 * @param int    $user_id  Acting user id.
	 * @return void
	 */
	private function prime_caches( array $post_ids, string $action, array $payload, int $user_id ): void {
		_prime_post_caches( $post_ids, false, true );

		$user_ids = array( $user_id );

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$user_ids[] = (int) $post->post_author;
			// Raw read: meta is already cached, and registered defaults are not ids.
			$user_ids[] = (int) get_metadata_raw( 'post', $post_id, PostRepository::META_REVIEWER, true );
		}

		if ( 'assign_reviewer' === $action && is_numeric( $payload['reviewer_id'] ) ) {
			$user_ids[] = (int) $payload['reviewer_id'];
		}

		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );

		if ( array() !== $user_ids ) {
			cache_users( $user_ids );
		}
	}

	/**
	 * Unique positive ids, in request order.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_ids Raw ids.
	 * @return int[]
	 */
	private function normalize_ids( array $post_ids ): array {
		$ids = array();

		foreach ( $post_ids as $post_id ) {
			if ( is_bool( $post_id ) || ! is_scalar( $post_id ) ) {
				continue;
			}

			$post_id = filter_var( $post_id, FILTER_VALIDATE_INT );

			if ( false !== $post_id && $post_id > 0 ) {
				$ids[ $post_id ] = $post_id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * The uniform post-gate failure of a batch item.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Error
	 */
	private function invalid_post(): WP_Error {
		return new WP_Error(
			'sit_cwm_invalid_post',
			__( 'No workflow content was found with this ID.', 'sapphireit-editorial-workflow' ),
			array( 'status' => 404 )
		);
	}
}
