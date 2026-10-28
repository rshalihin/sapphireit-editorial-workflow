<?php
/**
 * Integration tests for publish gate Layer C (scheduled publishing) and the
 * transition backstop.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Publishing;

use RuntimeException;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Publishing\GateLedger;
use Sit_Cwm\Publishing\GateRecorder;
use Sit_Cwm\Publishing\Layers\SaveLayer;
use Sit_Cwm\Publishing\Layers\ScheduleLayer;
use Sit_Cwm\Publishing\PublicationLogger;
use Sit_Cwm\Publishing\PublishGate;
use Sit_Cwm\Publishing\RefusalNotices;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A scheduled post is judged at go-live, before core publishes it, and any
 * other entry into `publish` that skipped Layers A–C is reverted after the
 * fact (roadmap §3.1A Layer C, E3, E5, E6).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Publishing\Layers\ScheduleLayer
 */
final class ScheduleLayerTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Per-request ledger.
	 *
	 * @var GateLedger
	 */
	private $ledger;

	/**
	 * Refusal notices.
	 *
	 * @var RefusalNotices
	 */
	private $notices;

	/**
	 * Gate recorder.
	 *
	 * @var GateRecorder
	 */
	private $recorder;

	/**
	 * Layer B, so scheduling goes through the real save path.
	 *
	 * @var SaveLayer
	 */
	private $save;

	/**
	 * Layer under test.
	 *
	 * @var ScheduleLayer
	 */
	private $layer;

	/**
	 * Publication logger, for ordering assertions.
	 *
	 * @var PublicationLogger
	 */
	private $logger;

	/**
	 * Replaces the booted plugin's publishing services with a chain under
	 * test, so every write is judged and logged exactly once.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->remove_booted_publishing_services();

		$settings       = new Settings();
		$permissions    = new PermissionManager( $this->posts, $settings );
		$gate           = new PublishGate( $settings, $this->posts, $permissions );
		$this->ledger   = new GateLedger();
		$this->notices  = new RefusalNotices();
		$this->recorder = new GateRecorder( $this->activity, $this->ledger, $this->notices );
		$this->save     = new SaveLayer( $gate, $this->ledger, $this->recorder );
		$this->layer    = new ScheduleLayer( $gate, $this->ledger, $this->recorder, $permissions, $settings );
		$this->logger   = new PublicationLogger( $this->activity, $this->posts, $settings, $gate );

		$this->recorder->register();
		$this->notices->register();
		$this->save->register();
		$this->layer->register();
		$this->logger->register();
	}

	/**
	 * Detaches the chain under test.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->layer->unregister();
		$this->save->unregister();
		$this->notices->unregister();
		$this->recorder->unregister();
		remove_action( 'transition_post_status', array( $this->logger, 'handle_transition' ), 10 );

		parent::tear_down();
	}

	/**
	 * Review Focus 2: a scheduled post sent back after it was scheduled is
	 * refused at go-live and never goes live, not even briefly.
	 *
	 * @return void
	 */
	public function test_scheduled_post_sent_back_after_scheduling_is_refused_at_go_live() {
		$author  = self::fixture_user( 'author' );
		$post_id = $this->scheduled( 'approved', $author );
		$this->mode( 'enforce' );
		$this->posts->set_status( $post_id, 'needs_changes' );

		$this->go_live( $post_id );

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'post_published' ) ), 'Never live, not even briefly.' );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'schedule', $rows[0]->get_context()['layer'] );
		$this->assertSame( 'needs_changes', $rows[0]->get_context()['workflow_status'] );
		$this->assertSame( 'future', $rows[0]->get_old_value() );
		$this->assertSame( 'publish', $rows[0]->get_new_value() );
		$this->assertSame( 0, $rows[0]->get_user_id() );
		$this->assertContains( $post_id, $this->notices->pending( $author ) );
	}

	/**
	 * An approved scheduled post goes live with one publication row and no
	 * gate row.
	 *
	 * @return void
	 */
	public function test_scheduled_approved_post_goes_live_with_one_publication_row() {
		$post_id = $this->scheduled( 'approved', self::fixture_user( 'author' ) );
		$this->mode( 'enforce' );

		$this->go_live( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'post_published' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]->get_user_id() );
		$this->assertSame( 'future', $rows[0]->get_old_value() );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * In `log_only` mode an unapproved scheduled post goes live and exactly
	 * one bypass is recorded, before the publication.
	 *
	 * @return void
	 */
	public function test_log_only_scheduled_post_below_approved_goes_live_and_records_bypass() {
		$post_id = $this->scheduled( 'review', self::fixture_user( 'author' ) );
		$this->mode( 'log_only' );

		$this->go_live( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'order' => 'ASC' ) );
		$this->assertSame( array( 'publish_gate_bypassed', 'post_published' ), $this->actions( $rows ) );
		$this->assertSame( 'log_only', $rows[0]->get_context()['reason'] );
		$this->assertSame( 'schedule', $rows[0]->get_context()['layer'] );
		$this->assertSame( 'future', $rows[0]->get_old_value() );
		$this->assertSame( 0, $rows[0]->get_user_id() );
	}

	/**
	 * A post scheduled under the bypass capability goes live at go-live, the
	 * bypass is recorded against the scheduler, and the marker is cleared (E5).
	 *
	 * @return void
	 */
	public function test_capability_scheduled_bypass_is_honoured_at_go_live_and_meta_cleared() {
		$admin   = self::fixture_user( 'admin' );
		$post_id = $this->scheduled_by_admin( $admin );

		$this->go_live( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'order' => 'ASC' ) );
		$this->assertSame( array( 'publish_gate_bypassed', 'post_published' ), $this->actions( $rows ) );
		$this->assertSame( 'capability', $rows[0]->get_context()['reason'] );
		$this->assertSame( 'schedule', $rows[0]->get_context()['layer'] );
		$this->assertSame( $admin, $rows[0]->get_user_id() );
		$this->assertSame( 0, $rows[1]->get_user_id() );
		$this->assertFalse( metadata_exists( 'post', $post_id, PostRepository::META_PUBLISH_BYPASS ) );
	}

	/**
	 * A scheduler who lost the bypass capability before go-live no longer
	 * carries the post past the gate.
	 *
	 * @return void
	 */
	public function test_capability_bypass_is_not_honoured_if_scheduler_lost_the_cap() {
		$admin   = self::fixture_user( 'admin' );
		$post_id = $this->scheduled_by_admin( $admin );
		$role    = get_role( 'administrator' );

		$role->remove_cap( Capabilities::BYPASS_PUBLISH_GATE );

		try {
			$this->go_live( $post_id );
		} finally {
			$role->add_cap( Capabilities::BYPASS_PUBLISH_GATE );
		}

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]->get_user_id() );
		$this->assertFalse( metadata_exists( 'post', $post_id, PostRepository::META_PUBLISH_BYPASS ) );
	}

	/**
	 * With the gate off nothing is judged at go-live.
	 *
	 * @return void
	 */
	public function test_off_mode_does_nothing_at_go_live() {
		$post_id = $this->scheduled( 'review', self::fixture_user( 'author' ), 'off' );

		$this->go_live( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( 1, $this->activity->count_for_post( $post_id, array( 'action' => 'post_published' ) ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * `wp_publish_post()` writes the status with a raw query, so only the
	 * backstop sees it: the post is reverted, and the log reads published →
	 * refused → unpublished, in that order (E6).
	 *
	 * @return void
	 */
	public function test_backstop_reverts_direct_wp_publish_post_on_a_draft() {
		$this->mode( 'enforce' );
		$author  = self::fixture_user( 'author' );
		$post_id = $this->draft_at( 'review', $author );
		wp_set_current_user( $author );

		wp_publish_post( $post_id );

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'order' => 'ASC' ) );
		$this->assertSame( array( 'post_published', 'publish_gate_refused', 'post_unpublished' ), $this->actions( $rows ) );
		$this->assertSame( 'backstop', $rows[1]->get_context()['layer'] );
		$this->assertSame( 'draft', $rows[1]->get_old_value() );
		$this->assertSame( $author, $rows[1]->get_user_id() );
		$this->assertContains( $post_id, $this->notices->pending( $author ) );
	}

	/**
	 * With no acting user, the backstop's notice goes to the post author.
	 *
	 * @return void
	 */
	public function test_backstop_without_user_notifies_author() {
		$this->mode( 'enforce' );
		$author  = self::fixture_user( 'author' );
		$post_id = $this->draft_at( 'review', $author );
		wp_set_current_user( 0 );

		wp_publish_post( $post_id );

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 0, $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) )[0]->get_user_id() );
		$this->assertContains( $post_id, $this->notices->pending( $author ) );
	}

	/**
	 * In `log_only` mode the backstop records the bypass and leaves the post
	 * live.
	 *
	 * @return void
	 */
	public function test_backstop_records_bypass_in_log_only() {
		$this->mode( 'log_only' );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );
		wp_set_current_user( self::fixture_user( 'author' ) );

		wp_publish_post( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'backstop', $rows[0]->get_context()['layer'] );
		$this->assertSame( 'log_only', $rows[0]->get_context()['reason'] );
	}

	/**
	 * A write Layer B already judged is not judged again by the backstop.
	 *
	 * @return void
	 */
	public function test_backstop_ignores_writes_layer_b_already_judged() {
		$this->mode( 'enforce' );
		$admin   = self::fixture_user( 'admin' );
		$post_id = $this->draft_at( 'draft', $admin );
		wp_set_current_user( $admin );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'save', $rows[0]->get_context()['layer'] );
	}

	/**
	 * New posts are Layer B's, and a live post is never demoted (roadmap A3).
	 *
	 * @return void
	 */
	public function test_backstop_ignores_new_posts_and_live_posts() {
		$post_id = $this->create_managed_post(
			array(
				'post_status'     => 'publish',
				'workflow_status' => 'review',
			)
		);
		$post    = get_post( $post_id );
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );

		$this->layer->backstop( 'publish', 'new', $post );
		$this->layer->backstop( 'publish', 'publish', $post );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * The backstop's own revert is not judged again: exactly one refusal.
	 *
	 * @return void
	 */
	public function test_backstop_does_not_judge_its_own_revert() {
		$this->mode( 'enforce' );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );
		wp_set_current_user( self::fixture_user( 'author' ) );

		wp_publish_post( $post_id );

		$this->assertSame( 1, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) ) );
		$this->assertSame( 1, $this->activity->count_for_post( $post_id, array( 'action' => 'post_unpublished' ) ) );

		$post = get_post( $post_id );
		$this->ledger->while_reverting(
			function () use ( $post ) {
				$this->layer->backstop( 'publish', 'draft', $post );
			}
		);

		$this->assertSame( 1, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) ) );
	}

	/**
	 * The scheduled-bypass marker goes when the post leaves `future`.
	 *
	 * @return void
	 */
	public function test_schedule_bypass_meta_is_cleared_when_post_leaves_future() {
		$admin   = self::fixture_user( 'admin' );
		$post_id = $this->scheduled_by_admin( $admin );
		$this->assertSame( $admin, $this->recorder->schedule_bypass_user( $post_id ) );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 0, $this->recorder->schedule_bypass_user( $post_id ) );
	}

	/**
	 * Roadmap A4: an exception at go-live lets core publish, and the failure
	 * is reported once, by the schedule layer only.
	 *
	 * @return void
	 */
	public function test_exception_in_schedule_layer_fails_open() {
		$post_id = $this->scheduled( 'review', self::fixture_user( 'author' ) );
		add_filter(
			'sit_cwm_publish_gate_passes',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);
		$failed = array();
		add_action(
			'sit_cwm_publish_gate_failed',
			static function ( $layer, $error, $failed_post_id ) use ( &$failed ) {
				$failed[] = array( $layer, $error->getMessage(), $failed_post_id );
			},
			10,
			3
		);

		$this->go_live( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( array( array( 'schedule', 'boom', $post_id ) ), $failed );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]->get_context()['reason'] );
		$this->assertSame( 'schedule', $rows[0]->get_context()['layer'] );
	}

	/**
	 * An exception in the backstop leaves the post live and is reported.
	 *
	 * @return void
	 */
	public function test_exception_in_backstop_fails_open() {
		$this->mode( 'enforce' );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );
		add_filter(
			'sit_cwm_publish_gate_passes',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);
		$failed = array();
		add_action(
			'sit_cwm_publish_gate_failed',
			static function ( $layer ) use ( &$failed ) {
				$failed[] = $layer;
			}
		);
		wp_set_current_user( self::fixture_user( 'author' ) );

		wp_publish_post( $post_id );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( array( 'backstop' ), $failed );
	}

	/**
	 * The layer hooks in at the E3/E6 priorities.
	 *
	 * @return void
	 */
	public function test_hooks_are_attached_at_their_priorities() {
		$this->assertSame( 5, has_action( 'publish_future_post', array( $this->layer, 'guard_scheduled' ) ) );
		$this->assertSame( 20, has_action( 'transition_post_status', array( $this->layer, 'backstop' ) ) );
		$this->assertSame( 30, has_action( 'transition_post_status', array( $this->layer, 'forget_schedule_bypass' ) ) );
	}

	/**
	 * Sets the `post` gate mode.
	 *
	 * @param string $mode Gate mode.
	 * @return void
	 */
	private function mode( string $mode ): void {
		$this->assertTrue( ( new Settings() )->update( array( 'publish_gate' => array( 'post' => $mode ) ) ) );
	}

	/**
	 * Creates a draft post at a workflow status.
	 *
	 * @param string $workflow_status Workflow status.
	 * @param int    $author          Post author.
	 * @return int Post id.
	 */
	private function draft_at( string $workflow_status, int $author ): int {
		return $this->create_managed_post(
			array(
				'post_status'     => 'draft',
				'post_author'     => $author,
				'workflow_status' => $workflow_status,
			)
		);
	}

	/**
	 * Creates a post scheduled a day ahead at a workflow status.
	 *
	 * Written with the gate off so Layer B does not intervene, then the gate
	 * is switched to `$mode`.
	 *
	 * @param string $workflow_status Workflow status.
	 * @param int    $author          Post author.
	 * @param string $mode            Gate mode to switch to afterwards.
	 * @return int Post id.
	 */
	private function scheduled( string $workflow_status, int $author, string $mode = 'enforce' ): int {
		$this->mode( 'off' );
		$post_id = $this->draft_at( $workflow_status, $author );
		$this->schedule( $post_id );
		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->mode( $mode );

		return $post_id;
	}

	/**
	 * An administrator schedules an unapproved post in `enforce` mode, which
	 * Layer B lets through and marks with the bypass meta (E5).
	 *
	 * @param int $admin Administrator id.
	 * @return int Post id.
	 */
	private function scheduled_by_admin( int $admin ): int {
		$this->mode( 'enforce' );
		$post_id = $this->draft_at( 'draft', self::fixture_user( 'author' ) );
		wp_set_current_user( $admin );
		$this->schedule( $post_id );
		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $admin, $this->recorder->schedule_bypass_user( $post_id ) );

		return $post_id;
	}

	/**
	 * Schedules a post a day ahead.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function schedule( int $post_id ): void {
		$date = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'future',
				'post_date'     => $date,
				'post_date_gmt' => $date,
				// Without it wp_update_post() clears a draft's date, and core
				// turns a dateless `future` into `publish`.
				'edit_date'     => true,
			)
		);
	}

	/**
	 * Runs the scheduled-publish cron for a post, as wp-cron does.
	 *
	 * Core's `check_and_publish_future_post()` leaves a post whose date is
	 * still ahead alone, so the date is moved into the past first, the way
	 * core's own tests do.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function go_live( int $post_id ): void {
		global $wpdb;

		$past = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture, as in core's tests.
			$wpdb->posts,
			array(
				'post_date'     => $past,
				'post_date_gmt' => $past,
			),
			array( 'ID' => $post_id )
		);
		clean_post_cache( $post_id );

		wp_set_current_user( 0 );
		// Exactly what wp-cron runs; core's check_and_publish_future_post() is hooked at 10.
		do_action( 'publish_future_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Simulates core's cron hook.
	}

	/**
	 * Asserts a post has no publish gate rows.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function assert_no_gate_rows( int $post_id ): void {
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) ) );
	}

	/**
	 * Action slugs of activity entries, in the given order.
	 *
	 * @param array $rows Activity entries.
	 * @return string[]
	 */
	private function actions( array $rows ): array {
		return array_map(
			static function ( $row ) {
				return $row->get_action();
			},
			$rows
		);
	}

	/**
	 * Removes every publishing service the booted plugin attached, on any hook.
	 *
	 * The test library restores the original hooks after each test.
	 *
	 * @return void
	 */
	private function remove_booted_publishing_services(): void {
		global $wp_filter;

		foreach ( $wp_filter as $hook => $hooks ) {
			foreach ( $hooks->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) && 0 === strpos( get_class( $callback['function'][0] ), 'Sit_Cwm\\Publishing\\' ) ) {
						remove_filter( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}
}
