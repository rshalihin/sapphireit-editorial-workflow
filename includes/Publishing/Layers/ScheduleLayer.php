<?php
/**
 * Publish gate Layer C: scheduled publishing, and the transition backstop.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Publishing\Layers;

use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Publishing\GateDecision;
use Sit_Cwm\Publishing\GateLedger;
use Sit_Cwm\Publishing\GateRecorder;
use Sit_Cwm\Publishing\PublishGate;
use Sit_Cwm\Workflow\PermissionManager;
use Throwable;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layer C and the backstop (E3): the two ways into `publish` that never pass
 * `wp_insert_post_data`.
 *
 * - Layer C judges the scheduled-publish cron on `publish_future_post` at 5,
 *   before core's `check_and_publish_future_post()` at 10, so a refused post
 *   is put back to `draft` and never goes live. Nobody is logged in there, so
 *   the attempt is judged as user `0`, unless the post was scheduled under
 *   the bypass capability by someone who still holds it (E5, E8).
 * - The backstop catches any other entry into `publish` on
 *   `transition_post_status` at 20, after the publication logger (E6).
 *
 * Writes Layer A, B or C already judged are left to them via the ledger.
 *
 * @since 1.1.0
 * @internal
 */
final class ScheduleLayer implements Bootable {

	/**
	 * Publish gate.
	 *
	 * @since 1.1.0
	 * @var PublishGate
	 */
	private $gate;

	/**
	 * Per-request ledger.
	 *
	 * @since 1.1.0
	 * @var GateLedger
	 */
	private $ledger;

	/**
	 * Gate recorder.
	 *
	 * @since 1.1.0
	 * @var GateRecorder
	 */
	private $recorder;

	/**
	 * Permission layer, for the scheduler's bypass capability.
	 *
	 * @since 1.1.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Plugin settings.
	 *
	 * @since 1.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.1.0
	 *
	 * @param PublishGate       $gate        Publish gate.
	 * @param GateLedger        $ledger      Per-request ledger.
	 * @param GateRecorder      $recorder    Gate recorder.
	 * @param PermissionManager $permissions Permission layer.
	 * @param Settings          $settings    Plugin settings.
	 */
	public function __construct( PublishGate $gate, GateLedger $ledger, GateRecorder $recorder, PermissionManager $permissions, Settings $settings ) {
		$this->gate        = $gate;
		$this->ledger      = $ledger;
		$this->recorder    = $recorder;
		$this->permissions = $permissions;
		$this->settings    = $settings;
	}

	/**
	 * Hooks the scheduled-publish cron and the transition backstop (E3, E6).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'publish_future_post', array( $this, 'guard_scheduled' ), 5 );
		add_action( 'transition_post_status', array( $this, 'backstop' ), 20, 3 );
		add_action( 'transition_post_status', array( $this, 'forget_schedule_bypass' ), 30, 3 );
	}

	/**
	 * Unhooks; mirrors `register()`.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_action( 'publish_future_post', array( $this, 'guard_scheduled' ), 5 );
		remove_action( 'transition_post_status', array( $this, 'backstop' ), 20 );
		remove_action( 'transition_post_status', array( $this, 'forget_schedule_bypass' ), 30 );
	}

	/**
	 * Judges a scheduled post at go-live, before core publishes it (Layer C).
	 * Hooked on `publish_future_post` at 5.
	 *
	 * A refused post is put back to `draft`, so core's
	 * `check_and_publish_future_post()` finds it no longer `future` and leaves
	 * it alone. A bypass is recorded here and core then publishes.
	 *
	 * Fails open (E5): on any exception core publishes, the failure is
	 * recorded, and the backstop is told the go-live was judged so it does
	 * not judge or record it again.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $post_id Post id, as scheduled by core.
	 * @return void
	 */
	public function guard_scheduled( $post_id ): void {
		$post_id = absint( $post_id );

		try {
			$this->judge_scheduled( $post_id );
		} catch ( Throwable $e ) {
			$this->recorder->failed_open( 'schedule', $post_id, $e );

			if ( $post_id > 0 ) {
				$this->ledger->judge( $post_id, GateDecision::failed_open( '', '' ) );
			}
		}
	}

	/**
	 * Reverts any other entry into `publish` the gate refuses (the backstop).
	 * Hooked on `transition_post_status` at 20, after the publication logger.
	 *
	 * Covers what bypassed both `wp_insert_post()` and the cron, e.g. a direct
	 * `wp_publish_post()` on a draft, which writes the status with a raw query.
	 * Honest limit: by the time this runs the post *was* live, briefly, and
	 * the log says so: published → refused → unpublished.
	 *
	 * New posts are Layer B's; a live post is never demoted (E4). Writes
	 * already judged in this request and the layers' own reverts are skipped.
	 *
	 * Fails open (E5): on any exception the post stays live and the failure
	 * is recorded.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Previous post status.
	 * @param mixed $post       Post object.
	 * @return void
	 */
	public function backstop( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || in_array( $old_status, array( 'publish', 'new' ), true ) || ! $post instanceof WP_Post ) {
			return;
		}

		try {
			$this->judge_backstop( (string) $old_status, $post );
		} catch ( Throwable $e ) {
			$this->recorder->failed_open( 'backstop', (int) $post->ID, $e );
		}
	}

	/**
	 * Drops the scheduled-bypass marker once a post leaves `future`, whether
	 * it went live or was unscheduled (E5). Hooked on `transition_post_status`
	 * at 30.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $new_status New post status.
	 * @param mixed $old_status Previous post status.
	 * @param mixed $post       Post object.
	 * @return void
	 */
	public function forget_schedule_bypass( $new_status, $old_status, $post ): void {
		if ( 'future' !== $old_status || 'future' === $new_status || ! $post instanceof WP_Post ) {
			return;
		}

		try {
			$this->recorder->forget_schedule_bypass( (int) $post->ID );
		} catch ( Throwable $e ) {
			return;
		}
	}

	/**
	 * The go-live judgement itself; see `guard_scheduled()`.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function judge_scheduled( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'future' !== $post->post_status || ! $this->settings->is_post_type_enabled( $post->post_type ) ) {
			return;
		}

		$decision = $this->gate->evaluate( (int) $post->ID, $post->post_type, 0 );

		if ( ! $decision->applies() ) {
			return;
		}

		$user_id = 0;

		// User 0 never holds the bypass, so a refusal here may still be
		// honoured for whoever scheduled the post under it (E5).
		if ( $decision->is_refused() ) {
			$scheduler = $this->recorder->schedule_bypass_user( (int) $post->ID );

			if ( $scheduler > 0 && $this->permissions->can_bypass_publish_gate( $scheduler ) ) {
				$decision = new GateDecision( GateDecision::OUTCOME_BYPASSED, $decision->mode(), $decision->workflow_status(), GateDecision::REASON_CAPABILITY );
				$user_id  = $scheduler;
			}
		}

		$this->ledger->judge( (int) $post->ID, $decision );

		if ( $decision->is_refused() ) {
			$this->revert( (int) $post->ID );
			$this->recorder->refused( (int) $post->ID, $decision, 'future', 'publish', 'schedule', 0, (int) $post->post_author );
			$this->recorder->forget_schedule_bypass( (int) $post->ID );
		} elseif ( $decision->is_bypassed() ) {
			$this->recorder->bypassed( (int) $post->ID, $decision, 'future', 'schedule', $user_id );
		}
	}

	/**
	 * The backstop judgement itself; see `backstop()`.
	 *
	 * @since 1.1.0
	 *
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post that just went live.
	 * @return void
	 */
	private function judge_backstop( string $old_status, WP_Post $post ): void {
		if ( ! $this->settings->is_post_type_enabled( $post->post_type ) || $this->ledger->is_reverting() || null !== $this->ledger->judged( (int) $post->ID ) ) {
			return;
		}

		$user_id  = get_current_user_id();
		$decision = $this->gate->evaluate( (int) $post->ID, $post->post_type, $user_id );

		if ( ! $decision->applies() ) {
			return;
		}

		if ( $decision->is_refused() ) {
			$this->recorder->refused( (int) $post->ID, $decision, $old_status, 'publish', 'backstop', $user_id, $user_id > 0 ? $user_id : (int) $post->post_author );
			$this->revert( (int) $post->ID );
		} elseif ( $decision->is_bypassed() ) {
			$this->recorder->bypassed( (int) $post->ID, $decision, $old_status, 'backstop', $user_id );
		}
	}

	/**
	 * Puts a post back to `draft` with the ledger's reverting flag set, so no
	 * layer judges the revert itself.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function revert( int $post_id ): void {
		$this->ledger->while_reverting(
			static function () use ( $post_id ) {
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'draft',
					)
				);
			}
		);
	}
}
