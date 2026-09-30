<?php
/**
 * Workflow orchestrator.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Workflow;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single entry point for every workflow read model and mutation.
 *
 * REST controllers, the editor sidebar, bulk actions and Pro add-ons go
 * through this class. It is the only place where structural validity
 * (TransitionManager) meets authorization (PermissionManager), so neither can
 * be bypassed by the other.
 *
 * Every mutator follows the same shape: post gate → authorization → input
 * validation → persist → log exactly one activity entry → fire the D10 action.
 * A failing mutator persists nothing, logs nothing and fires nothing.
 *
 * Disclosure rule (step 20.2): a post that is missing, not workflow-enabled,
 * or not readable by the acting user yields the same `sit_cwm_not_managed`
 * 404, so a 403 never confirms that a hidden post exists.
 *
 * @since 1.0.0
 */
final class WorkflowManager {

	/**
	 * Status registry.
	 *
	 * @since 1.0.0
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Transition map.
	 *
	 * @since 1.0.0
	 * @var TransitionManager
	 */
	private $transitions;

	/**
	 * Authorization.
	 *
	 * @since 1.0.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Workflow meta persistence.
	 *
	 * @since 1.0.0
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Activity history.
	 *
	 * @since 1.0.0
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param StatusManager     $statuses    Status registry.
	 * @param TransitionManager $transitions Transition map.
	 * @param PermissionManager $permissions Authorization.
	 * @param PostRepository    $posts       Workflow meta persistence.
	 * @param ActivityLogger    $activity    Activity history.
	 */
	public function __construct(
		StatusManager $statuses,
		TransitionManager $transitions,
		PermissionManager $permissions,
		PostRepository $posts,
		ActivityLogger $activity
	) {
		$this->statuses    = $statuses;
		$this->transitions = $transitions;
		$this->permissions = $permissions;
		$this->posts       = $posts;
		$this->activity    = $activity;
	}

	/**
	 * Current workflow status of a post.
	 *
	 * Performs no authorization; callers exposing it must check access.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return string A registered status slug; the default for unmanaged posts.
	 */
	public function get_status( int $post_id ): string {
		return $this->posts->get_status( $post_id );
	}

	/**
	 * Full workflow state of a post, shaped for REST (D9).
	 *
	 * The `capabilities` block and `available_transitions` exist so the UI can
	 * hide controls as a courtesy. They are never trusted: every mutator
	 * re-checks everything server-side.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param int|null $user_id User the capabilities are computed for; null for
	 *                          the current user.
	 * @return array Workflow state; empty when the post is missing, not
	 *               workflow-enabled, or not readable by the user.
	 */
	public function get_workflow( int $post_id, ?int $user_id = null ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( null !== $this->check_post( $post_id, $user_id ) ) {
			return array();
		}

		$state = $this->posts->get_workflow( $post_id );

		if ( array() === $state ) {
			return array();
		}

		$transitions = $this->get_available_transitions( $post_id, $user_id );

		return array(
			'post_id'               => $state['post_id'],
			'post_title'            => $state['post_title'],
			'post_type'             => $state['post_type'],
			'post_status'           => $state['post_status'],
			'edit_link'             => (string) get_edit_post_link( $state['post_id'], 'raw' ),
			'status'                => $state['status'],
			'status_label'          => $this->statuses->label( $state['status'] ),
			'status_is_unknown'     => $this->posts->has_unknown_status( $state['post_id'] ),
			'reviewer'              => $this->reviewer_summary( $state['reviewer_id'] ),
			'due_date'              => $state['due_date'],
			'available_transitions' => $transitions,
			'capabilities'          => $this->get_capability_hints( $post_id, $transitions, $user_id ),
		);
	}

	/**
	 * What a user may do on a post, as display hints for the UI.
	 *
	 * Shared by the workflow payload and the dashboard rows so both expose the
	 * same shape. Never trusted: every mutator re-checks server-side.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id     Post id.
	 * @param array    $transitions The user's available transitions for the post.
	 * @param int|null $user_id     User id; null for the current user.
	 * @return array{can_change_status: bool, can_assign_reviewer: bool, can_set_due_date: bool, can_comment: bool, can_view_activity: bool}
	 */
	public function get_capability_hints( int $post_id, array $transitions, ?int $user_id = null ): array {
		$user_id = $this->resolve_user_id( $user_id );

		return array(
			'can_change_status'   => array() !== $transitions,
			'can_assign_reviewer' => $this->permissions->can_assign_reviewer( $post_id, $user_id ),
			'can_set_due_date'    => $this->permissions->can_set_due_date( $post_id, $user_id ),
			'can_comment'         => $this->permissions->can_comment( $post_id, $user_id ),
			'can_view_activity'   => $this->permissions->can_view_activity( $post_id, $user_id ),
		);
	}

	/**
	 * Transitions from the post's current status that the user may perform.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param int|null $user_id User id; null for the current user.
	 * @return array<int, array{slug: string, label: string, is_forward: bool, is_rollback: bool}>
	 *         In transition-map order; empty when the post is not accessible.
	 */
	public function get_available_transitions( int $post_id, ?int $user_id = null ): array {
		$user_id = $this->resolve_user_id( $user_id );

		if ( null !== $this->check_post( $post_id, $user_id ) ) {
			return array();
		}

		$from      = $this->posts->get_status( $post_id );
		$available = array();

		foreach ( $this->transitions->targets_for( $from ) as $to ) {
			if ( $this->permissions->can_change_status( $post_id, $to, $user_id ) ) {
				$available[ $to ] = $this->transitions->describe( $from, $to );
			}
		}

		/**
		 * Filters the transitions offered to a user for a post.
		 *
		 * Display only: entries may be removed, reordered or relabelled, but
		 * slugs not in the computed list are dropped, and `transition()`
		 * re-validates everything regardless of what is offered.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $transitions Transition descriptions.
		 * @param int    $post_id     Post id.
		 * @param string $from        Current workflow status.
		 * @param int    $user_id     Acting user id.
		 */
		$filtered = apply_filters( 'sit_cwm_available_transitions', array_values( $available ), $post_id, $from, $user_id );

		if ( ! is_array( $filtered ) ) {
			return array_values( $available );
		}

		$result = array();

		foreach ( $filtered as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['slug'] ) || ! is_string( $entry['slug'] ) || ! isset( $available[ $entry['slug'] ] ) ) {
				continue;
			}

			$slug = $entry['slug'];

			$result[ $slug ] = array(
				'slug'        => $slug,
				'label'       => isset( $entry['label'] ) && is_string( $entry['label'] ) && '' !== $entry['label'] ? $entry['label'] : $available[ $slug ]['label'],
				'is_forward'  => $available[ $slug ]['is_forward'],
				'is_rollback' => $available[ $slug ]['is_rollback'],
			);
		}

		return array_values( $result );
	}

	/**
	 * Whether a user may move a post from `$from` to `$to` right now.
	 *
	 * Runs the same checks as `transition()`, without writes or actions.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param string   $from    Status the caller believes is current.
	 * @param string   $to      Target status slug.
	 * @param int|null $user_id User id; null for the current user.
	 * @return bool
	 */
	public function can_transition( int $post_id, string $from, string $to, ?int $user_id = null ): bool {
		return true === $this->check_transition( $post_id, $from, $to, $user_id );
	}

	/**
	 * Like `can_transition()`, but returns the reason a transition is refused.
	 *
	 * Lets the REST layer reject a request before applying any part of it,
	 * with the same error `transition()` would return. Writes nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param string   $from    Status the caller believes is current.
	 * @param string   $to      Target status slug.
	 * @param int|null $user_id User id; null for the current user.
	 * @return true|WP_Error
	 */
	public function check_transition( int $post_id, string $from, string $to, ?int $user_id = null ) {
		$error = $this->validate_transition( $post_id, $from, $to, $this->resolve_user_id( $user_id ) );

		return null === $error ? true : $error;
	}

	/**
	 * Moves a post to a new workflow status.
	 *
	 * Re-runs every check itself (never trusts an earlier `can_transition()`),
	 * so a caller cannot race between checking and writing.
	 *
	 * `$from` is an optimistic-concurrency guard: it must equal the status
	 * stored now, so two editors acting on the same stale state cannot both
	 * succeed. The comparison is application-level, not a database lock.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param string   $from    Status the caller saw as current.
	 * @param string   $to      Target status slug.
	 * @param int|null $user_id Acting user id; null for the current user.
	 * @return true|WP_Error
	 */
	public function transition( int $post_id, string $from, string $to, ?int $user_id = null ) {
		$user_id = $this->resolve_user_id( $user_id );
		$error   = $this->validate_transition( $post_id, $from, $to, $user_id );

		if ( null !== $error ) {
			return $error;
		}

		if ( ! $this->posts->set_status( $post_id, $to ) ) {
			return $this->update_failed();
		}

		$this->activity->log_status_change( $post_id, $from, $to, $user_id );

		/**
		 * Fires after a post's workflow status changed.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id Post id.
		 * @param string $from    Previous status slug.
		 * @param string $to      New status slug.
		 * @param int    $user_id Acting user id.
		 */
		do_action( 'sit_cwm_status_changed', $post_id, $from, $to, $user_id );

		return true;
	}

	/**
	 * Sets or clears a post's reviewer.
	 *
	 * Authorization runs before the reviewer id is checked, so users without
	 * the capability cannot probe which user ids exist. The reviewer must be
	 * an existing user holding `sit_cwm_review_content`. Assigning the current
	 * reviewer again is a no-op that logs nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id     Post id.
	 * @param int      $reviewer_id Reviewer user id; `0` clears.
	 * @param int|null $user_id     Acting user id; null for the current user.
	 * @return true|WP_Error
	 */
	public function assign_reviewer( int $post_id, int $reviewer_id, ?int $user_id = null ) {
		$user_id = $this->resolve_user_id( $user_id );
		$error   = $this->check_post( $post_id, $user_id );

		if ( null !== $error ) {
			return $error;
		}

		if ( ! $this->permissions->can_assign_reviewer( $post_id, $user_id ) ) {
			return $this->forbidden();
		}

		if ( 0 !== $reviewer_id && $this->posts->sanitize_reviewer_id( $reviewer_id ) !== $reviewer_id ) {
			return new WP_Error(
				'sit_cwm_invalid_user',
				__( 'The selected reviewer does not exist.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 400 )
			);
		}

		if ( 0 !== $reviewer_id && ! $this->permissions->can_be_reviewer( $reviewer_id ) ) {
			return new WP_Error(
				'sit_cwm_invalid_user',
				__( 'The selected user is not allowed to review content.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 400 )
			);
		}

		$previous = $this->posts->get_reviewer_id( $post_id );

		if ( $previous === $reviewer_id ) {
			return true;
		}

		if ( ! $this->posts->set_reviewer_id( $post_id, $reviewer_id ) ) {
			return $this->update_failed();
		}

		$this->activity->log_reviewer_assigned( $post_id, $reviewer_id, $previous, $user_id );

		/** This action is documented in includes/Content/PostRepository.php */
		do_action( 'sit_cwm_reviewer_assigned', $post_id, $reviewer_id, $previous, $user_id );

		return true;
	}

	/**
	 * Sets or clears a post's due date.
	 *
	 * Setting the current date again is a no-op that logs nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param string   $date    `Y-m-d`; `''` clears.
	 * @param int|null $user_id Acting user id; null for the current user.
	 * @return true|WP_Error
	 */
	public function set_due_date( int $post_id, string $date, ?int $user_id = null ) {
		$user_id = $this->resolve_user_id( $user_id );
		$error   = $this->check_post( $post_id, $user_id );

		if ( null !== $error ) {
			return $error;
		}

		if ( ! $this->permissions->can_set_due_date( $post_id, $user_id ) ) {
			return $this->forbidden();
		}

		$date = trim( $date );

		if ( '' !== $date && $this->posts->sanitize_due_date( $date ) !== $date ) {
			return new WP_Error(
				'sit_cwm_invalid_date',
				__( 'The due date must be a valid date in YYYY-MM-DD format.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 400 )
			);
		}

		$previous = $this->posts->get_due_date( $post_id );

		if ( $previous === $date ) {
			return true;
		}

		if ( ! $this->posts->set_due_date( $post_id, $date ) ) {
			return $this->update_failed();
		}

		$this->activity->log_due_date_changed( $post_id, $date, $previous, $user_id );

		/**
		 * Fires after a post's due date changed.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id  Post id.
		 * @param string $date     New `Y-m-d` date; `''` when cleared.
		 * @param string $previous Previous `Y-m-d` date; `''` when none.
		 * @param int    $user_id  Acting user id.
		 */
		do_action( 'sit_cwm_due_date_changed', $post_id, $date, $previous, $user_id );

		return true;
	}

	/**
	 * Adds a workflow comment to a post's activity history.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $post_id Post id.
	 * @param string   $message Comment body; post-safe HTML is kept.
	 * @param int|null $user_id Acting user id; null for the current user.
	 * @return int|WP_Error Activity id of the comment.
	 */
	public function add_comment( int $post_id, string $message, ?int $user_id = null ) {
		$user_id = $this->resolve_user_id( $user_id );
		$error   = $this->check_post( $post_id, $user_id );

		if ( null !== $error ) {
			return $error;
		}

		if ( ! $this->permissions->can_comment( $post_id, $user_id ) ) {
			return $this->forbidden();
		}

		$message = trim( wp_kses_post( $message ) );

		if ( '' === trim( wp_strip_all_tags( $message ) ) ) {
			return new WP_Error(
				'sit_cwm_empty_comment',
				__( 'The comment cannot be empty.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 400 )
			);
		}

		$activity_id = $this->activity->log_comment( $post_id, $message, $user_id );

		if ( $activity_id <= 0 ) {
			return $this->update_failed();
		}

		/**
		 * Fires after a workflow comment was added.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id     Post id.
		 * @param int    $activity_id Activity id of the comment.
		 * @param string $message     Sanitized comment body.
		 * @param int    $user_id     Acting user id.
		 */
		do_action( 'sit_cwm_comment_added', $post_id, $activity_id, $message, $user_id );

		return $activity_id;
	}

	/**
	 * Shared transition checks, in the documented order: post gate (404),
	 * stale `$from` (409), unknown target (400), illegal edge (400),
	 * authorization (403).
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id Post id.
	 * @param string $from    Status the caller saw as current.
	 * @param string $to      Target status slug.
	 * @param int    $user_id Resolved acting user id.
	 * @return WP_Error|null Null when the transition may proceed.
	 */
	private function validate_transition( int $post_id, string $from, string $to, int $user_id ): ?WP_Error {
		$error = $this->check_post( $post_id, $user_id );

		if ( null !== $error ) {
			return $error;
		}

		if ( $this->posts->get_status( $post_id ) !== $from ) {
			return new WP_Error(
				'sit_cwm_status_conflict',
				__( 'The workflow status was changed by someone else. Reload and try again.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 409 )
			);
		}

		if ( ! $this->statuses->exists( $to ) ) {
			return new WP_Error(
				'sit_cwm_invalid_status',
				__( 'The requested workflow status does not exist.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->transitions->is_valid( $from, $to ) ) {
			return new WP_Error(
				'sit_cwm_invalid_transition',
				__( 'This workflow transition is not allowed.', 'sapphireit-editorial-workflow' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->permissions->can_change_status( $post_id, $to, $user_id ) ) {
			return $this->forbidden();
		}

		return null;
	}

	/**
	 * Post gate shared by every method: the post must exist, be
	 * workflow-enabled, and be readable by the user. All three failures
	 * produce the identical 404.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @param int $user_id Resolved acting user id.
	 * @return WP_Error|null Null when the post is accessible.
	 */
	private function check_post( int $post_id, int $user_id ): ?WP_Error {
		if ( $this->posts->is_managed( $post_id ) && $this->permissions->can_read_post( $post_id, $user_id ) ) {
			return null;
		}

		return new WP_Error(
			'sit_cwm_not_managed',
			__( 'No workflow was found for this content.', 'sapphireit-editorial-workflow' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Resolves the acting user id.
	 *
	 * @since 1.0.0
	 *
	 * @param int|null $user_id User id; null for the current user.
	 * @return int User id; `0` when logged out or invalid.
	 */
	private function resolve_user_id( ?int $user_id ): int {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		return max( 0, $user_id );
	}

	/**
	 * Public reviewer summary; never includes login or email.
	 *
	 * @since 1.0.0
	 *
	 * @param int $reviewer_id Reviewer user id.
	 * @return array{id: int, name: string, avatar: string}|null Null when none.
	 */
	private function reviewer_summary( int $reviewer_id ): ?array {
		$user = $reviewer_id > 0 ? get_userdata( $reviewer_id ) : false;

		if ( ! $user instanceof WP_User ) {
			return null;
		}

		return array(
			'id'     => $user->ID,
			'name'   => $user->display_name,
			'avatar' => (string) get_avatar_url( $user->ID, array( 'size' => 48 ) ),
		);
	}

	/**
	 * Authorization failure.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Error
	 */
	private function forbidden(): WP_Error {
		return new WP_Error(
			'sit_cwm_forbidden',
			__( 'You are not allowed to perform this workflow action.', 'sapphireit-editorial-workflow' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Persistence failure after all checks passed.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Error
	 */
	private function update_failed(): WP_Error {
		return new WP_Error(
			'sit_cwm_update_failed',
			__( 'The workflow could not be saved. Please try again.', 'sapphireit-editorial-workflow' ),
			array( 'status' => 500 )
		);
	}
}
