<?php
/**
 * Integration tests for publish gate Layer A (REST refusal, publish link,
 * `publish_post` meta capability).
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Publishing;

use RuntimeException;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Publishing\GateLedger;
use Sit_Cwm\Publishing\GateRecorder;
use Sit_Cwm\Publishing\Layers\RestLayer;
use Sit_Cwm\Publishing\Layers\SaveLayer;
use Sit_Cwm\Publishing\PublicationLogger;
use Sit_Cwm\Publishing\PublishGate;
use Sit_Cwm\Publishing\RefusalNotices;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use Sit_Cwm\Workflow\TransitionManager;
use Sit_Cwm\Workflow\WorkflowManager;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The block editor loses its publish link and `wp/v2` refuses by the same
 * rule the save guard applies, and `publish_post` agrees (roadmap §3.1A
 * Layer A, E3, E4, E5, E14).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Publishing\Layers\RestLayer
 */
final class RestLayerTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * E14 refusal message.
	 *
	 * @var string
	 */
	const MESSAGE = 'This content has to be approved in the editorial workflow before it can be published.';

	/**
	 * Gate recorder.
	 *
	 * @var GateRecorder
	 */
	private $recorder;

	/**
	 * Refusal notices.
	 *
	 * @var RefusalNotices
	 */
	private $notices;

	/**
	 * Save layer (Layer B), which a REST write reaches after Layer A.
	 *
	 * @var SaveLayer
	 */
	private $save;

	/**
	 * Layer under test.
	 *
	 * @var RestLayer
	 */
	private $layer;

	/**
	 * Publication logger, for publication-row assertions.
	 *
	 * @var PublicationLogger
	 */
	private $logger;

	/**
	 * Replaces the booted plugin's publishing services with a chain under
	 * test, then starts a REST server so the per-type filters are attached.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->remove_booted_publishing_services();

		$settings       = new Settings();
		$gate           = new PublishGate( $settings, $this->posts, new PermissionManager( $this->posts, $settings ) );
		$ledger         = new GateLedger();
		$this->notices  = new RefusalNotices();
		$this->recorder = new GateRecorder( $this->activity, $ledger, $this->notices );
		$this->save     = new SaveLayer( $gate, $ledger, $this->recorder );
		$this->layer    = new RestLayer( $gate, $ledger, $this->recorder, $settings );
		$this->logger   = new PublicationLogger( $this->activity, $this->posts, $settings, $gate );

		$this->recorder->register();
		$this->notices->register();
		$this->save->register();
		$this->layer->register();
		$this->logger->register();

		$this->reset_rest_server();
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
	 * Below `approved` in `enforce`, an author's edit-context response has no
	 * publish link, so the block editor offers Submit for Review.
	 *
	 * @return void
	 */
	public function test_edit_context_drops_publish_link_when_gate_would_refuse() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );

		$response = $this->get( "/wp/v2/posts/$post_id", array( 'context' => 'edit' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( RestLayer::PUBLISH_REL, $response->get_links() );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * The link stays whenever the gate would let the publish through.
	 *
	 * @return void
	 */
	public function test_publish_link_stays_when_approved_or_log_only_or_bypass_or_off() {
		$cases = array(
			'enforce + approved' => array( 'enforce', 'approved', 'author' ),
			'log_only + review'  => array( 'log_only', 'review', 'author' ),
			'enforce + bypass'   => array( 'enforce', 'draft', 'admin' ),
			'off + draft'        => array( 'off', 'draft', 'author' ),
		);

		foreach ( $cases as $label => list( $mode, $workflow_status, $user ) ) {
			$this->mode( $mode );
			wp_set_current_user( self::fixture_user( $user ) );
			$post_id = $this->draft_at( $workflow_status, self::fixture_user( 'author' ) );

			$response = $this->get( "/wp/v2/posts/$post_id", array( 'context' => 'edit' ) );

			$this->assertArrayHasKey( RestLayer::PUBLISH_REL, $response->get_links(), $label );
		}
	}

	/**
	 * Live and scheduled posts keep the link: updating them is not a
	 * transition into a published status (E4).
	 *
	 * @return void
	 */
	public function test_publish_link_stays_for_live_and_scheduled_posts() {
		wp_set_current_user( self::fixture_user( 'author' ) );

		foreach ( array( 'publish', 'future' ) as $status ) {
			$this->mode( 'off' );
			$post_id = $this->create_managed_post(
				array(
					'post_status'     => $status,
					'post_author'     => self::fixture_user( 'author' ),
					'post_date'       => 'future' === $status ? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) : gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
					'workflow_status' => 'writing',
				)
			);
			$this->assertSame( $status, get_post_status( $post_id ) );
			$this->mode( 'enforce' );

			$response = $this->get( "/wp/v2/posts/$post_id", array( 'context' => 'edit' ) );

			$this->assertArrayHasKey( RestLayer::PUBLISH_REL, $response->get_links(), $status );
		}
	}

	/**
	 * The view context is never touched (it carries no publish link anyway).
	 *
	 * @return void
	 */
	public function test_view_context_is_not_evaluated() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id   = $this->create_managed_post(
			array(
				'post_status'     => 'publish',
				'workflow_status' => 'published',
			)
		);
		$evaluated = 0;
		add_filter(
			'sit_cwm_publish_gate_passes',
			static function ( $passes ) use ( &$evaluated ) {
				++$evaluated;
				return $passes;
			}
		);

		$response = $this->get( "/wp/v2/posts/$post_id" );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $evaluated );
	}

	/**
	 * Review Focus 4: an agent creating straight into `publish` gets a clean
	 * 403 and nothing is created.
	 *
	 * @return void
	 */
	public function test_rest_create_as_publish_is_refused_with_403() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );

		$response = $this->post(
			'/wp/v2/posts',
			array(
				'title'  => 'From an agent',
				'status' => 'publish',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( RestLayer::ERROR_CODE, $response->as_error()->get_error_code() );
		$this->assertSame( 'sit_cwm_publish_gated', $response->as_error()->get_error_code() );
		$this->assertSame( self::MESSAGE, $response->as_error()->get_error_message() );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'title'       => 'From an agent',
					'post_status' => 'any',
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * An update into `publish` is refused, the post stays a draft, and the
	 * refusal is recorded exactly once, by Layer A.
	 *
	 * @return void
	 */
	public function test_rest_update_to_publish_is_refused_and_recorded_once() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );

		$response = $this->post( "/wp/v2/posts/$post_id", array( 'status' => 'publish' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'sit_cwm_publish_gated', $response->as_error()->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'rest', $rows[0]->get_context()['layer'] );
		$this->assertSame( 'draft', $rows[0]->get_old_value() );
		$this->assertSame( 'publish', $rows[0]->get_new_value() );
		$this->assertSame( 'review', $rows[0]->get_context()['workflow_status'] );
		$this->assertSame( self::fixture_user( 'author' ), $rows[0]->get_user_id() );
		// The editor shows the REST error itself, so no admin notice is queued.
		$this->assertSame( array(), $this->notices->pending( self::fixture_user( 'author' ) ) );
	}

	/**
	 * Scheduling through REST is refused the same way.
	 *
	 * @return void
	 */
	public function test_rest_update_to_future_is_refused() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );

		$response = $this->post(
			"/wp/v2/posts/$post_id",
			array(
				'status'   => 'future',
				'date_gmt' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'sit_cwm_publish_gated', $response->as_error()->get_error_code() );
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'future', $rows[0]->get_new_value() );
	}

	/**
	 * At `approved` the publish goes through with one publication row and no
	 * gate rows.
	 *
	 * @return void
	 */
	public function test_rest_publish_at_approved_succeeds_with_one_publication_row() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
		$post_id = $this->draft_at( 'approved', self::fixture_user( 'author' ) );

		$response = $this->post( "/wp/v2/posts/$post_id", array( 'status' => 'publish' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( 1, $this->activity->count_for_post( $post_id, array( 'action' => 'post_published' ) ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * An administrator's bypass through REST is recorded exactly once: Layer
	 * B reuses Layer A's judgement through the ledger.
	 *
	 * @return void
	 */
	public function test_rest_admin_bypass_succeeds_and_is_recorded_once() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$post_id = $this->draft_at( 'draft', self::fixture_user( 'author' ) );

		$response = $this->post( "/wp/v2/posts/$post_id", array( 'status' => 'publish' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'rest', $rows[0]->get_context()['layer'] );
		$this->assertSame( 'capability', $rows[0]->get_context()['reason'] );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_refused' ) ) );
	}

	/**
	 * An administrator's bypass on a REST create is recorded exactly once,
	 * by Layer B under the new id.
	 *
	 * @return void
	 */
	public function test_rest_admin_bypass_on_create_is_recorded_once() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$response = $this->post(
			'/wp/v2/posts',
			array(
				'title'  => 'Admin create',
				'status' => 'publish',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$post_id = (int) $response->get_data()['id'];
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'save', $rows[0]->get_context()['layer'] );
	}

	/**
	 * An administrator scheduling through REST stores the bypass marker
	 * instead of a bypass row (E5, E8).
	 *
	 * @return void
	 */
	public function test_rest_admin_scheduling_stores_bypass_marker() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$post_id = $this->draft_at( 'draft', self::fixture_user( 'author' ) );

		$response = $this->post(
			"/wp/v2/posts/$post_id",
			array(
				'status'   => 'future',
				'date_gmt' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'future', get_post_status( $post_id ) );
		$this->assertSame( self::fixture_user( 'admin' ), $this->recorder->schedule_bypass_user( $post_id ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * Review Focus 1: editing a live post in re-review is not gated.
	 *
	 * @return void
	 */
	public function test_rest_update_of_published_post_is_not_gated() {
		$this->mode( 'off' );
		$post_id = $this->create_managed_post(
			array(
				'post_status'     => 'publish',
				'post_author'     => self::fixture_user( 'author' ),
				'workflow_status' => 'writing',
			)
		);
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );

		$response = $this->post(
			"/wp/v2/posts/$post_id",
			array(
				'title'  => 'Fix',
				'status' => 'publish',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( 'Fix', get_the_title( $post_id ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * Pages are covered when their own mode gates them.
	 *
	 * @return void
	 */
	public function test_rest_pages_are_covered_when_page_is_gated() {
		$this->mode( 'off', 'enforce' );
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$response = $this->post(
			'/wp/v2/pages',
			array(
				'title'  => 'Gated page',
				'status' => 'publish',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'sit_cwm_publish_gated', $response->as_error()->get_error_code() );
	}

	/**
	 * A post type whose mode is off is left alone.
	 *
	 * @return void
	 */
	public function test_ungated_type_is_untouched() {
		$this->mode( 'enforce', 'off' );
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$response = $this->post(
			'/wp/v2/pages',
			array(
				'title'  => 'Free page',
				'status' => 'publish',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( (int) $response->get_data()['id'] ) );
	}

	/**
	 * A post type the workflow does not manage gets no filters at all.
	 *
	 * @return void
	 */
	public function test_filters_are_attached_only_for_enabled_types() {
		$this->assertSame( 10, has_filter( 'rest_prepare_post', array( $this->layer, 'filter_prepare' ) ) );
		$this->assertSame( 10, has_filter( 'rest_pre_insert_post', array( $this->layer, 'filter_pre_insert' ) ) );
		$this->assertSame( 10, has_filter( 'map_meta_cap', array( $this->layer, 'filter_meta_cap' ) ) );
		$this->assertSame( 20, has_action( 'rest_api_init', array( $this->layer, 'register_post_type_filters' ) ) );
		$this->assertFalse( has_filter( 'rest_pre_insert_attachment', array( $this->layer, 'filter_pre_insert' ) ) );
	}

	/**
	 * `publish_post` agrees with the gate.
	 *
	 * @return void
	 */
	public function test_publish_post_meta_cap_follows_the_gate() {
		$this->mode( 'enforce' );
		$post_id = $this->draft_at( 'draft', self::fixture_user( 'author' ) );

		$this->assertFalse( user_can( self::fixture_user( 'author' ), 'publish_post', $post_id ) );
		$this->assertTrue( user_can( self::fixture_user( 'admin' ), 'publish_post', $post_id ) );

		$this->posts->set_status( $post_id, 'approved' );

		$this->assertTrue( user_can( self::fixture_user( 'author' ), 'publish_post', $post_id ) );
		$this->assert_no_gate_rows( $post_id );
	}

	/**
	 * Every other capability, and `publish_post` on live posts, is untouched.
	 *
	 * @return void
	 */
	public function test_meta_cap_filter_does_not_touch_other_caps_or_live_posts() {
		$this->mode( 'off' );
		$live = $this->create_managed_post(
			array(
				'post_status'     => 'publish',
				'post_author'     => self::fixture_user( 'author' ),
				'workflow_status' => 'writing',
			)
		);
		$this->mode( 'enforce' );
		$draft  = $this->draft_at( 'draft', self::fixture_user( 'author' ) );
		$author = self::fixture_user( 'author' );

		$this->assertTrue( user_can( $author, 'edit_post', $draft ) );
		$this->assertTrue( user_can( $author, 'publish_posts' ) );
		$this->assertTrue( user_can( $author, 'publish_post', $live ) );
		$this->assertFalse( user_can( self::fixture_user( 'contributor' ), 'publish_post', $draft ), 'Never grants.' );
	}

	/**
	 * Regression on D5: the workflow's own `approved → published` step,
	 * which checks `publish_post`, still works in `enforce`.
	 *
	 * @return void
	 */
	public function test_workflow_published_transition_still_works_at_approved() {
		$this->mode( 'enforce' );
		$settings = new Settings();
		$statuses = new StatusManager();
		$workflow = new WorkflowManager(
			$statuses,
			new TransitionManager( $statuses ),
			new PermissionManager( $this->posts, $settings ),
			$this->posts,
			$this->activity
		);
		$post_id  = $this->draft_at( 'approved', self::fixture_user( 'author' ) );

		$this->assertTrue( $workflow->transition( $post_id, 'approved', 'published', self::fixture_user( 'editor' ) ) );
		$this->assert_status( $post_id, 'published' );
	}

	/**
	 * Roadmap A4: an exception inside Layer A lets the publish through, fires
	 * the failure action, records one error row, and keeps the link.
	 *
	 * @return void
	 */
	public function test_exception_in_rest_layer_fails_open() {
		$this->mode( 'enforce' );
		wp_set_current_user( self::fixture_user( 'author' ) );
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
			static function ( $layer, $error, $failed_post_id ) use ( &$failed ) {
				$failed[] = array( $layer, $error->getMessage(), $failed_post_id );
			},
			10,
			3
		);

		$link = $this->get( "/wp/v2/posts/$post_id", array( 'context' => 'edit' ) );

		$this->assertArrayHasKey( RestLayer::PUBLISH_REL, $link->get_links() );
		$this->assertSame( array( array( 'rest', 'boom', 0 ) ), $failed );
		$this->assertSame( 0, $this->activity->count_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) ), 'Display failures write no row.' );

		$failed   = array();
		$response = $this->post( "/wp/v2/posts/$post_id", array( 'status' => 'publish' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( array( 'rest', 'boom', $post_id ), $failed[0] );
		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'publish_gate_bypassed' ) );
		$this->assertCount( 1, $rows, 'Layer B does not judge or record the same write again.' );
		$this->assertSame( 'error', $rows[0]->get_context()['reason'] );
		$this->assertSame( 'rest', $rows[0]->get_context()['layer'] );
	}

	/**
	 * A failing meta-cap check leaves the capabilities unchanged.
	 *
	 * @return void
	 */
	public function test_exception_in_meta_cap_leaves_caps_unchanged() {
		$this->mode( 'enforce' );
		$post_id = $this->draft_at( 'review', self::fixture_user( 'author' ) );
		add_filter(
			'sit_cwm_publish_gate_passes',
			static function () {
				throw new RuntimeException( 'boom' );
			}
		);

		$this->assertTrue( user_can( self::fixture_user( 'author' ), 'publish_post', $post_id ) );
	}

	/**
	 * Sets the `post` and `page` gate modes.
	 *
	 * @param string $post_mode Gate mode for posts.
	 * @param string $page_mode Gate mode for pages.
	 * @return void
	 */
	private function mode( string $post_mode, string $page_mode = 'off' ): void {
		$this->assertTrue(
			( new Settings() )->update(
				array(
					'publish_gate' => array(
						'post' => $post_mode,
						'page' => $page_mode,
					),
				)
			)
		);
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
	 * Dispatches a GET.
	 *
	 * @param string $route  Route.
	 * @param array  $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function get( string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', $route );
		$request->set_query_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * Dispatches a JSON POST.
	 *
	 * @param string $route Route.
	 * @param array  $body  JSON body.
	 * @return WP_REST_Response
	 */
	private function post( string $route, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_do_request( $request );
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
