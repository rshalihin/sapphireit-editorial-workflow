<?php
/**
 * Integration tests for publish gate Layer B (the save guard).
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Publishing;

use RuntimeException;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Publishing\GateDecision;
use Sit_Cwm\Publishing\GateLedger;
use Sit_Cwm\Publishing\GateRecorder;
use Sit_Cwm\Publishing\Layers\SaveLayer;
use Sit_Cwm\Publishing\PublicationLogger;
use Sit_Cwm\Publishing\PublishGate;
use Sit_Cwm\Publishing\RefusalNotices;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every `wp_insert_post()` / `wp_update_post()` that would move managed content
 * into `publish`/`future` is judged; refusals are downgraded, and refusals and
 * bypasses are recorded once (roadmap §3.1A Layer B, E4, E5).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Publishing\Layers\SaveLayer
 * @covers \Sit_Cwm\Publishing\GateRecorder
 * @covers \Sit_Cwm\Publishing\GateLedger
 */
final class SaveLayerTest extends TestCase {

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
	 * Layer under test.
	 *
	 * @var SaveLayer
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

		require_once ABSPATH . 'wp-admin/includes/post.php';

		$this->remove_booted_publishing_services();

		$settings       = new Settings();
		$gate           = new PublishGate( $settings, $this->posts, new PermissionManager( $this->posts, $settings ) );
		$this->ledger   = new GateLedger();
		$this->notices  = new RefusalNotices();
		$this->recorder = new GateRecorder( $this->activity, $this->ledger, $this->notices );
		$this->layer    = new SaveLayer( $gate, $this->ledger, $this->recorder );
		$this->logger   = new PublicationLogger( $this->activity, $this->posts, $settings, $gate );

		$this->recorder->register();
		$this->notices->register();
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
		$this->notices->unregister();
		$this->recorder->unregister();
		remove_action( 'transition_post_status', array( $this->logger, 'handle_transition' ), 10 );

		parent::tear_down();
	}

	/**
	 * Below `approved`, an author's publish is refused and kept as a draft,
	 * recorded once with the layer and workflow status, and the author is told.
	 *
	 * @return void
	 */
	public function test_enforce_refuses_publish_below_approved_and_keeps_draft() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => self::fixture_user( 'author' ),
			)
		);
		$this->posts->set_status( $post_id, 'review' );

		$this->update( $post_id, 'publish' );

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'draft', $rows[0]->get_old_value() );
		$this->assertSame( 'publish', $rows[0]->get_new_value() );
		$this->assertSame( self::fixture_user( 'author' ), $rows[0]->get_user_id() );
		$this->assertSame( 'save', $rows[0]->get_context()['layer'] );
		$this->assertSame( 'review', $rows[0]->get_context()['workflow_status'] );
		$this->assertSame( 'enforce', $rows[0]->get_context()['gate_mode'] );
		$this->assertSame( 'other', $rows[0]->get_context()['source'] );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'post_published' ) ) );
		$this->assertContains( $post_id, $this->notices->pending( self::fixture_user( 'author' ) ) );
	}

	/**
	 * Scheduling below `approved` is refused the same way.
	 *
	 * @return void
	 */
	public function test_enforce_refuses_future_below_approved() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );

		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'future',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'edit_date'     => true,
			)
		);

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'future', $rows[0]->get_new_value() );
	}

	/**
	 * A refused pending post stays pending.
	 *
	 * @return void
	 */
	public function test_refusal_from_pending_keeps_pending() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'pending',
				'post_author' => self::fixture_user( 'author' ),
			)
		);
		$this->posts->set_status( $post_id, 'review' );

		$this->update( $post_id, 'publish' );

		$this->assertSame( 'pending', get_post_status( $post_id ) );
		$this->assertSame( 'pending', $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) )[0]->get_old_value() );
	}

	/**
	 * Approved content publishes with no gate row.
	 *
	 * @return void
	 */
	public function test_enforce_allows_publish_at_approved_with_no_gate_row() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'approved', self::fixture_user( 'author' ) );

		$this->update( $post_id, 'publish' );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( 1, $this->activity->count_for_post( $post_id, array( 'action' => 'post_published' ) ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * An administrator publishes unapproved content as a recorded bypass,
	 * logged before the publication itself.
	 *
	 * @return void
	 */
	public function test_enforce_admin_bypass_publishes_and_records_bypass_before_publication() {
		$this->mode( 'enforce' );
		$admin = self::fixture_user( 'admin' );
		wp_set_current_user( $admin );
		$post_id = $this->draft_at( 'draft', $admin );

		$this->update( $post_id, 'publish' );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'order' => 'ASC' ) );
		$this->assertSame( array( 'publish_gate_bypassed', 'post_published' ), $this->actions( $rows ) );
		$this->assertSame( 'capability', $rows[0]->get_context()['reason'] );
		$this->assertSame( 'save', $rows[0]->get_context()['layer'] );
		$this->assertSame( 'publish', $rows[0]->get_new_value() );
		$this->assertSame( $admin, $rows[0]->get_user_id() );
	}

	/**
	 * Scheduling under the bypass capability stores who did it; the bypass is
	 * recorded at go-live, not now (E5).
	 *
	 * @return void
	 */
	public function test_enforce_admin_scheduling_below_approved_stores_bypass_meta_not_a_bypass_row() {
		$this->mode( 'enforce' );
		$admin = self::fixture_user( 'admin' );
		wp_set_current_user( $admin );
		$post_id = $this->draft_at( 'review', $admin );

		$this->schedule( $post_id );

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $admin, (int) get_post_meta( $post_id, PostRepository::META_PUBLISH_BYPASS, true ) );
		$this->assertSame( $admin, $this->recorder->schedule_bypass_user( $post_id ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * In `log_only` mode the publish goes ahead and exactly one bypass is
	 * recorded.
	 *
	 * @return void
	 */
	public function test_log_only_allows_and_records_one_bypass() {
		$this->mode( 'log_only' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );

		$this->update( $post_id, 'publish' );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'log_only', $rows[0]->get_context()['reason'] );
		$this->assertSame( 'log_only', $rows[0]->get_context()['gate_mode'] );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) ) );
	}

	/**
	 * Scheduling in `log_only` mode records nothing yet: the bypass is
	 * recorded at go-live (E5).
	 *
	 * @return void
	 */
	public function test_log_only_scheduling_records_nothing_yet() {
		$this->mode( 'log_only' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );

		$this->schedule( $post_id );

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assert_no_gate_rows( $post_id );
		$this->assertSame( 0, $this->recorder->schedule_bypass_user( $post_id ) );
	}

	/**
	 * With the gate off nothing is judged or recorded by it.
	 *
	 * @return void
	 */
	public function test_off_mode_records_nothing_from_gate() {
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );

		$this->update( $post_id, 'publish' );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * Review Focus 1 / roadmap A3: a live post in re-review stays live.
	 *
	 * @return void
	 */
	public function test_published_post_in_rereview_stays_published_on_update() {
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => self::fixture_user( 'author' ),
			)
		);
		$this->posts->set_status( $post_id, 'writing' );
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_title'  => 'Correction',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( 'Correction', get_post( $post_id )->post_title );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) ) );
	}

	/**
	 * Review Focus 4: a post inserted directly as `publish` is refused, and the
	 * refusal is recorded under the id it ends up with.
	 *
	 * @return void
	 */
	public function test_new_post_inserted_as_publish_is_refused_and_logged_under_new_id() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );

		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Agent draft',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'new', $rows[0]->get_old_value() );
		$this->assertSame( 'publish', $rows[0]->get_new_value() );
		$this->assertSame( 'draft', $rows[0]->get_context()['workflow_status'] );
		$this->assertContains( $post_id, $this->notices->pending( self::fixture_user( 'author' ) ) );
		$this->assertNull( $this->ledger->release( 'post' ) );
	}

	/**
	 * Review Focus 4: an administrator's direct insert as `publish` is a
	 * bypass recorded under the new id, before the publication row.
	 *
	 * @return void
	 */
	public function test_new_post_inserted_as_publish_by_admin_logs_bypass_under_new_id() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Import',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'order' => 'ASC' ) );
		$this->assertSame( array( 'publish_gate_bypassed', 'post_published' ), $this->actions( $rows ) );
		$this->assertSame( 'capability', $rows[0]->get_context()['reason'] );
		$this->assertSame( 'new', $rows[0]->get_old_value() );
	}

	/**
	 * An administrator's direct insert as `future` stores the bypass marker
	 * on the new id and records no bypass yet.
	 *
	 * @return void
	 */
	public function test_new_post_inserted_as_future_by_admin_stores_bypass_meta() {
		$this->mode( 'enforce' );
		$admin = self::fixture_user( 'admin' );
		wp_set_current_user( $admin );

		$post_id = wp_insert_post(
			array(
				'post_title'    => 'Scheduled import',
				'post_status'   => 'future',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( $admin, $this->recorder->schedule_bypass_user( $post_id ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * WP-CLI without `--user` runs as user 0, which cannot bypass (E5).
	 *
	 * @return void
	 */
	public function test_cli_without_user_is_refused_in_enforce() {
		$this->mode( 'enforce' );
		wp_set_current_user( 0 );
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		$this->update( $post_id, 'publish' );

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]->get_user_id() );
	}

	/**
	 * Quick Edit (`wp_ajax_inline_save()` → `edit_post()`) is covered.
	 *
	 * @return void
	 */
	public function test_quick_edit_path_is_covered() {
		$this->mode( 'enforce' );
		$author = self::fixture_user( 'author' );
		wp_set_current_user( $author );
		$post_id = $this->draft_at( 'review', $author );

		// What wp_ajax_inline_save() hands to edit_post() after copying `_status`.
		edit_post(
			array(
				'post_ID'     => $post_id,
				'post_type'   => 'post',
				'post_title'  => 'Quick edited',
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( 'Quick edited', get_post( $post_id )->post_title );
		$this->assertSame( 1, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) ) );
	}

	/**
	 * Bulk Edit is covered, one refusal per post.
	 *
	 * @return void
	 */
	public function test_bulk_edit_path_is_covered() {
		$this->mode( 'enforce' );
		$author = self::fixture_user( 'author' );
		wp_set_current_user( $author );
		$a = $this->draft_at( 'review', $author );
		$b = $this->draft_at( 'writing', $author );

		bulk_edit_posts(
			array(
				'post'      => array( $a, $b ),
				'post_type' => 'post',
				'_status'   => 'publish',
			)
		);

		$this->assertSame( 'draft', get_post_status( $a ) );
		$this->assertSame( 'draft', get_post_status( $b ) );
		$this->assertSame( 1, $this->activity->count_for_post( $a, array( 'action' => 'publish_gate_refused' ) ) );
		$this->assertSame( 1, $this->activity->count_for_post( $b, array( 'action' => 'publish_gate_refused' ) ) );
	}

	/**
	 * Bulk Edit's "No change" status leaves a scheduled post alone (E4).
	 *
	 * @return void
	 */
	public function test_bulk_edit_with_no_status_change_leaves_scheduled_post_alone() {
		$author = self::fixture_user( 'author' );
		wp_set_current_user( $author );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'future',
				'post_author' => $author,
				'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			)
		);
		$this->posts->set_status( $post_id, 'review' );
		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->mode( 'enforce' );

		bulk_edit_posts(
			array(
				'post'       => array( $post_id ),
				'post_type'  => 'post',
				'_status'    => '-1',
				'tags_input' => 'x',
			)
		);

		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * Saving a revision is never judged.
	 *
	 * (DOING_AUTOSAVE cannot be defined per test; the constant branch is
	 * covered by phase 15's block-editor autosave during typing.)
	 *
	 * @return void
	 */
	public function test_revision_save_is_ignored() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Changed, so a revision is stored.',
			)
		);

		$revision_id = wp_save_post_revision( $post_id );

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assert_no_gate_rows( $post_id );

		if ( $revision_id ) {
			$this->assert_no_gate_rows( (int) $revision_id );
		}
	}

	/**
	 * A layer's own revert write is not judged again.
	 *
	 * @return void
	 */
	public function test_writes_while_reverting_are_not_judged() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );

		$this->ledger->while_reverting(
			function () use ( $post_id ) {
				$this->update( $post_id, 'publish' );
			}
		);

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * A decision Layer A already took in this request is reused: applied,
	 * but not recorded a second time.
	 *
	 * @return void
	 */
	public function test_decision_judged_earlier_in_request_is_applied_without_recording() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );
		$this->ledger->judge( $post_id, new GateDecision( GateDecision::OUTCOME_REFUSED, 'enforce', 'review', GateDecision::REASON_NONE ) );

		$this->update( $post_id, 'publish' );

		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assert_no_gate_rows( $post_id );
		$this->assertNull( $this->ledger->judged( $post_id ), 'Forgotten once the insert finished.' );
	}

	/**
	 * Roadmap A4: an exception inside the gate lets the publish through,
	 * fires the failure action and leaves a `bypassed`/`error` row.
	 *
	 * @return void
	 */
	public function test_exception_in_gate_fails_open_and_is_logged() {
		$this->mode( 'enforce' );
		add_filter(
			'sit_cwm_publish_gate_passes',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);
		$failed = array();
		add_action(
			'sit_cwm_publish_gate_failed',
			static function ( $layer, $error, $post_id ) use ( &$failed ) {
				$failed[] = array( $layer, $error->getMessage(), $post_id );
			},
			10,
			3
		);
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$this->posts->set_status( $post_id, 'review' );

		$this->update( $post_id, 'publish' );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( array( array( 'save', 'boom', $post_id ) ), $failed );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]->get_context()['reason'] );
		$this->assertSame( 'save', $rows[0]->get_context()['layer'] );
		$this->assertSame( 'review', $rows[0]->get_context()['workflow_status'] );
	}

	/**
	 * A failing recorder never blocks the save either.
	 *
	 * @return void
	 */
	public function test_exception_in_recording_listener_does_not_block() {
		$this->mode( 'log_only' );
		add_action(
			'sit_cwm_publish_gate_bypassed',
			static function () {
				throw new RuntimeException( 'listener' );
			}
		);
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );

		$this->update( $post_id, 'publish' );

		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}

	/**
	 * The refusal action fires once with the decision, user and layer.
	 *
	 * @return void
	 */
	public function test_refused_action_fires_once_with_decision() {
		$this->mode( 'enforce' );
		$author = self::fixture_user( 'author' );
		wp_set_current_user( $author );
		$post_id = $this->draft_at( 'review', $author );
		$calls   = array();
		add_action(
			'sit_cwm_publish_gate_refused',
			static function ( ...$args ) use ( &$calls ) {
				$calls[] = $args;
			},
			10,
			4
		);

		$this->update( $post_id, 'publish' );

		$this->assertCount( 1, $calls );
		$this->assertSame( $post_id, $calls[0][0] );
		$this->assertInstanceOf( GateDecision::class, $calls[0][1] );
		$this->assertTrue( $calls[0][1]->is_refused() );
		$this->assertSame( 'review', $calls[0][1]->workflow_status() );
		$this->assertSame( $author, $calls[0][2] );
		$this->assertSame( 'save', $calls[0][3] );
	}

	/**
	 * The layer and recorder hook in at the E6 priorities.
	 *
	 * @return void
	 */
	public function test_hooks_are_attached_at_their_priorities() {
		$this->assertSame( 10, has_filter( 'wp_insert_post_data', array( $this->layer, 'filter_insert_post_data' ) ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'wp_insert_post', array( $this->layer, 'end_insert' ) ) );
		$this->assertSame( 5, has_action( 'transition_post_status', array( $this->recorder, 'release_pending' ) ) );
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
	 * Updates a post's native status.
	 *
	 * @param int    $post_id Post id.
	 * @param string $status  Native status.
	 * @return void
	 */
	private function update( int $post_id, string $status ): void {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			)
		);
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
	 * Removes every publishing service the booted plugin attached.
	 *
	 * The test library restores the original hooks after each test.
	 *
	 * @return void
	 */
	private function remove_booted_publishing_services(): void {
		global $wp_filter;

		foreach ( array( 'wp_insert_post_data', 'wp_insert_post', 'transition_post_status', 'admin_notices' ) as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] ) && 0 === strpos( get_class( $callback['function'][0] ), 'Sit_Cwm\\Publishing\\' ) ) {
						remove_filter( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	}
}
