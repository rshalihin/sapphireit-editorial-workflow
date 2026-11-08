<?php
/**
 * Logs edits made after review started.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Content;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Publishing\RequestSource;
use Throwable;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Watches saves of workflow-enabled content. A change to the title, content or
 * excerpt outside the writing statuses leaves a `content_changed` row and fires
 * `sit_cwm_content_changed`, so the audit trail shows what happened after a
 * reviewer read the content.
 *
 * Free refuses nothing because of an edit; add-ons decide what an edit
 * invalidates. Autosaves that write to the post count as edits, so rows are
 * limited to one per post, user and workflow status every five minutes. The
 * action is not.
 *
 * @since 1.2.0
 * @internal
 */
final class ContentChangeLogger implements Bootable {

	/**
	 * Statuses where the author is expected to edit.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const EDITING_STATUSES = array( 'draft', 'writing', 'needs_changes' );

	/**
	 * Seconds between rows for one post and user.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const ROW_INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Workflow meta persistence.
	 *
	 * @since 1.2.0
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Activity history.
	 *
	 * @since 1.2.0
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param PostRepository $posts    Workflow meta persistence.
	 * @param ActivityLogger $activity Activity history.
	 */
	public function __construct( PostRepository $posts, ActivityLogger $activity ) {
		$this->posts    = $posts;
		$this->activity = $activity;
	}

	/**
	 * Attaches the save hook.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'post_updated', array( $this, 'handle_post_updated' ), 20, 3 );
	}

	/**
	 * Compares a post before and after a save.
	 *
	 * Never raises: logging must not break a save (the same principle as
	 * `PublicationLogger`). A logging failure does not stop the action either,
	 * because add-ons act on it.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $post_id Post id.
	 * @param mixed $after   Post after the save.
	 * @param mixed $before  Post before the save.
	 * @return void
	 */
	public function handle_post_updated( $post_id, $after, $before ): void {
		try {
			if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post ) {
				return;
			}

			$post_id = (int) $post_id;

			if ( wp_is_post_revision( $post_id ) || ! $this->posts->is_managed( $post_id ) ) {
				return;
			}

			$old_hash = $this->posts->fingerprint_of( $before );
			$new_hash = $this->posts->fingerprint_of( $after );

			if ( $old_hash === $new_hash ) {
				return;
			}

			$status = $this->posts->get_status( $post_id );

			if ( in_array( $status, self::EDITING_STATUSES, true ) ) {
				return;
			}

			$user_id = get_current_user_id();

			try {
				$this->maybe_log( $post_id, $old_hash, $new_hash, $user_id, $status );
			} catch ( Throwable $e ) {
				$this->report( $e );
			}

			/**
			 * Fires when content changes after review started.
			 *
			 * Free refuses nothing because of an edit. Fires for every such
			 * save, including ones the activity log skips as repeats.
			 *
			 * @since 1.2.0
			 *
			 * @param int    $post_id         Post id.
			 * @param string $old_hash        Fingerprint before the save.
			 * @param string $new_hash        Fingerprint after the save.
			 * @param int    $user_id         Acting user; `0` for the system.
			 * @param string $workflow_status Workflow status at the time.
			 */
			do_action( 'sit_cwm_content_changed', $post_id, $old_hash, $new_hash, $user_id, $status );
		} catch ( Throwable $e ) {
			$this->report( $e );
		}
	}

	/**
	 * Notes a failure for developers.
	 *
	 * @since 1.2.0
	 *
	 * @param Throwable $error The failure.
	 * @return void
	 */
	private function report( Throwable $error ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Developer diagnostics; the save went ahead.
			error_log( sprintf( 'SapphireIT Editorial Workflow: content change logging failed after %1$s: %2$s', get_class( $error ), $error->getMessage() ) );
		}
	}

	/**
	 * Writes a row unless this user saved this post at this status moments ago.
	 * The status is part of the key, so an edit right after an approval always
	 * leaves its own row.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $post_id  Post id.
	 * @param string $old_hash Fingerprint before the save.
	 * @param string $new_hash Fingerprint after the save.
	 * @param int    $user_id  Acting user.
	 * @param string $status   Workflow status.
	 * @return void
	 */
	private function maybe_log( int $post_id, string $old_hash, string $new_hash, int $user_id, string $status ): void {
		$key = "sit_cwm_cc_{$post_id}_{$user_id}_{$status}";

		if ( false !== get_transient( $key ) ) {
			return;
		}

		$logged = $this->activity->log(
			$post_id,
			'content_changed',
			array(
				'old_value' => $old_hash,
				'new_value' => $new_hash,
				'user_id'   => $user_id,
				'context'   => array(
					'workflow_status' => $status,
					'source'          => RequestSource::detect(),
				),
			)
		);

		if ( $logged > 0 ) {
			set_transient( $key, 1, self::ROW_INTERVAL );
		}
	}
}
