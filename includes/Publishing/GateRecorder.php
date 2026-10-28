<?php
/**
 * Publish gate recorder.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Publishing;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Interfaces\Bootable;
use Throwable;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes what the gate layers decided: refusal and bypass rows, the refusal
 * notice, the public actions, the scheduled-bypass marker and fail-open
 * diagnostics (E5, E7, E8, E11).
 *
 * The layers decide whether a write goes ahead; this class only records it.
 * Every public method swallows its own failures, so a broken listener or a
 * failed insert can neither block a publish nor undo a refusal.
 *
 * @since 1.1.0
 * @internal
 */
final class GateRecorder implements Bootable {

	/**
	 * Activity history.
	 *
	 * @since 1.1.0
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Per-request ledger.
	 *
	 * @since 1.1.0
	 * @var GateLedger
	 */
	private $ledger;

	/**
	 * Refusal notices.
	 *
	 * @since 1.1.0
	 * @var RefusalNotices
	 */
	private $notices;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.1.0
	 *
	 * @param ActivityLogger $activity Activity history.
	 * @param GateLedger     $ledger   Per-request ledger.
	 * @param RefusalNotices $notices  Refusal notices.
	 */
	public function __construct( ActivityLogger $activity, GateLedger $ledger, RefusalNotices $notices ) {
		$this->activity = $activity;
		$this->ledger   = $ledger;
		$this->notices  = $notices;
	}

	/**
	 * Hooks the release of held new-post decisions, before the publication
	 * logger (E6 priority table).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'release_pending' ), 5, 3 );
	}

	/**
	 * Unhooks; mirrors `register()`.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_action( 'transition_post_status', array( $this, 'release_pending' ), 5 );
	}

	/**
	 * Records a refused publish attempt, queues the notice and fires
	 * `sit_cwm_publish_gate_refused`.
	 *
	 * @since 1.1.0
	 *
	 * @param int          $post_id        Post id.
	 * @param GateDecision $d              Decision.
	 * @param string       $stored         Stored post status (`new` for a new post).
	 * @param string       $attempted      Attempted status: `publish` or `future`.
	 * @param string       $layer          Layer: `rest`, `save`, `schedule` or `backstop`.
	 * @param int          $user_id        Acting user; `0` for cron and CLI without a user.
	 * @param int          $notify_user_id User to show the notice to; `0` for nobody.
	 * @return void
	 */
	public function refused( int $post_id, GateDecision $d, string $stored, string $attempted, string $layer, int $user_id, int $notify_user_id ): void {
		try {
			$this->activity->log_publication_event(
				$post_id,
				'publish_gate_refused',
				$stored,
				$attempted,
				$this->context( $d, $layer ),
				$user_id
			);

			$this->notices->queue( $notify_user_id, $post_id );

			/**
			 * Fires when the publish gate refuses to let content go live.
			 *
			 * @since 1.1.0
			 *
			 * @param int          $post_id  Post id.
			 * @param GateDecision $decision Decision.
			 * @param int          $user_id  Acting user; `0` for cron and CLI without a user.
			 * @param string       $layer    `rest`, `save`, `schedule` or `backstop`.
			 */
			do_action( 'sit_cwm_publish_gate_refused', $post_id, $d, $user_id, $layer );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Records unapproved content going live and fires
	 * `sit_cwm_publish_gate_bypassed`.
	 *
	 * @since 1.1.0
	 *
	 * @param int          $post_id Post id.
	 * @param GateDecision $d       Decision; its reason is `log_only` or `capability`.
	 * @param string       $stored  Stored post status (`new` for a new post).
	 * @param string       $layer   Layer: `rest`, `save`, `schedule` or `backstop`.
	 * @param int          $user_id Acting user.
	 * @return void
	 */
	public function bypassed( int $post_id, GateDecision $d, string $stored, string $layer, int $user_id ): void {
		try {
			$this->activity->log_publication_event(
				$post_id,
				'publish_gate_bypassed',
				$stored,
				'publish',
				array_merge( $this->context( $d, $layer ), array( 'reason' => $d->reason() ) ),
				$user_id
			);

			/**
			 * Fires when unapproved content goes live past the publish gate.
			 *
			 * @since 1.1.0
			 *
			 * @param int          $post_id  Post id.
			 * @param GateDecision $decision Decision; reason `log_only` or `capability`.
			 * @param int          $user_id  Acting user.
			 * @param string       $layer    `rest`, `save`, `schedule` or `backstop`.
			 */
			do_action( 'sit_cwm_publish_gate_bypassed', $post_id, $d, $user_id, $layer );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Remembers who scheduled a failing post under the bypass capability, so
	 * Layer C can honour it at go-live (E5, E8).
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post id.
	 * @param int $user_id Bypass holder.
	 * @return void
	 */
	public function remember_schedule_bypass( int $post_id, int $user_id ): void {
		try {
			update_post_meta( $post_id, PostRepository::META_PUBLISH_BYPASS, $user_id );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * The user who scheduled a post under the bypass capability.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post id.
	 * @return int User id; `0` when none.
	 */
	public function schedule_bypass_user( int $post_id ): int {
		return absint( get_post_meta( $post_id, PostRepository::META_PUBLISH_BYPASS, true ) );
	}

	/**
	 * Forgets the scheduled-bypass marker.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function forget_schedule_bypass( int $post_id ): void {
		try {
			delete_post_meta( $post_id, PostRepository::META_PUBLISH_BYPASS );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Records a layer that failed and let the publish through (E5, roadmap A4).
	 *
	 * Best effort, and never raises.
	 *
	 * @since 1.1.0
	 *
	 * @param string    $layer   Layer that failed.
	 * @param int       $post_id Post id; `0` when not known yet.
	 * @param Throwable $error   What was thrown.
	 * @return void
	 */
	public function failed_open( string $layer, int $post_id, Throwable $error ): void {
		try {
			if ( $post_id > 0 ) {
				// The workflow status is read on its own: it may be what failed.
				try {
					$workflow_status = (string) get_post_meta( $post_id, PostRepository::META_STATUS, true );
				} catch ( Throwable $e ) {
					$workflow_status = '';
				}

				$this->activity->log_publication_event(
					$post_id,
					'publish_gate_bypassed',
					(string) get_post_status( $post_id ),
					'publish',
					array(
						'workflow_status' => $workflow_status,
						'layer'           => $layer,
						'source'          => RequestSource::detect(),
						'reason'          => GateDecision::REASON_ERROR,
					),
					get_current_user_id()
				);
			}

			/**
			 * Fires when a publish gate layer throws and fails open: the
			 * publish it was judging is allowed.
			 *
			 * @since 1.1.0
			 *
			 * @param string    $layer   `rest`, `save`, `schedule` or `backstop`.
			 * @param Throwable $error   What was thrown.
			 * @param int       $post_id Post id; `0` when not known yet.
			 */
			do_action( 'sit_cwm_publish_gate_failed', $layer, $error, $post_id );

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Developer diagnostics; the publish was allowed.
				error_log( sprintf( 'SapphireIT Editorial Workflow: publish gate %1$s failed open after %2$s: %3$s', $layer, get_class( $error ), $error->getMessage() ) );
			}
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * Records a decision held for a post that had no id when it was judged,
	 * now that its first status transition names the id. Hooked on
	 * `transition_post_status` at 5, before the publication row (E6).
	 *
	 * | Held decision           | New status | Recorded                      |
	 * |-------------------------|------------|-------------------------------|
	 * | refused                 | any        | refusal                       |
	 * | bypassed                | `publish`  | bypass                        |
	 * | bypassed, `capability`  | `future`   | scheduled-bypass marker (E5)  |
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Previous post status; `new` for a new post.
	 * @param mixed $post       Post object.
	 * @return void
	 */
	public function release_pending( $new_status, $old_status, $post ): void {
		if ( 'new' !== $old_status || ! $post instanceof WP_Post ) {
			return;
		}

		try {
			$held = $this->ledger->release( $post->post_type );

			if ( null === $held ) {
				return;
			}

			$decision = $held['decision'];
			$post_id  = (int) $post->ID;
			$user_id  = get_current_user_id();

			if ( $decision->is_refused() ) {
				$this->refused( $post_id, $decision, 'new', $held['attempted'], 'save', $user_id, $user_id );
			} elseif ( $decision->is_bypassed() && 'publish' === $new_status ) {
				$this->bypassed( $post_id, $decision, 'new', 'save', $user_id );
			} elseif ( $decision->is_bypassed() && 'future' === $new_status && GateDecision::REASON_CAPABILITY === $decision->reason() ) {
				$this->remember_schedule_bypass( $post_id, $user_id );
			}
		} catch ( Throwable $e ) {
			$this->failed_open( 'save', (int) $post->ID, $e );
		}
	}

	/**
	 * Activity context shared by refusal and bypass rows (E7).
	 *
	 * @since 1.1.0
	 *
	 * @param GateDecision $d     Decision.
	 * @param string       $layer Layer.
	 * @return array<string, string>
	 */
	private function context( GateDecision $d, string $layer ): array {
		return array(
			'workflow_status' => $d->workflow_status(),
			'gate_mode'       => $d->mode(),
			'layer'           => $layer,
			'source'          => RequestSource::detect(),
		);
	}
}
