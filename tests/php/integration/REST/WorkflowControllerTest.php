<?php
/**
 * Integration tests for the workflow REST controller.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration\REST;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Database;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\REST\WorkflowController;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\StatusManager;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `sit-cwm/v1` workflow and status routes, dispatched through the REST server.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\REST\WorkflowController
 * @covers \Sit_Cwm\REST\AbstractController
 */
final class WorkflowControllerTest extends WP_UnitTestCase {

	/**
	 * Workflow meta persistence, for arranging and asserting state.
	 *
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Activity history, for asserting nothing was logged.
	 *
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Ensures the real activity table exists.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		( new Database() )->install();

		parent::set_up_before_class();
	}

	/**
	 * Grants default capabilities and starts a fresh REST server.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Capabilities::add_caps();

		$this->posts    = new PostRepository( new StatusManager(), new Settings() );
		$this->activity = new ActivityLogger( new Database() );

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		rest_get_server();
	}

	/**
	 * Discards the REST server.
	 *
	 * @return void
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.

		parent::tear_down();
	}

	/**
	 * Logged-out requests are 401 on every route.
	 *
	 * @return void
	 */
	public function test_logged_out_is_unauthorized() {
		$post = $this->make_post( $this->make_user( 'author' ) );

		wp_set_current_user( 0 );

		$this->assert_error_response( $this->get( $post ), 'rest_forbidden', 401 );
		$this->assert_error_response( $this->post( $post, array( 'reviewer_id' => 0 ) ), 'rest_forbidden', 401 );
		$this->assert_error_response( $this->request( 'GET', '/sit-cwm/v1/statuses' ), 'rest_forbidden', 401 );
	}

	/**
	 * A subscriber gets 403 on a post they can read, 404 on one they cannot.
	 *
	 * @return void
	 */
	public function test_subscriber_is_forbidden_or_not_found() {
		$author    = $this->make_user( 'author' );
		$published = $this->make_post( $author, '', 'publish' );
		$draft     = $this->make_post( $author );

		wp_set_current_user( $this->make_user( 'subscriber' ) );

		$this->assert_error_response( $this->get( $published ), 'sit_cwm_forbidden', 403 );
		$this->assert_error_response( $this->get( $draft ), 'sit_cwm_not_managed', 404 );
		$this->assert_error_response( $this->post( $draft, array( 'reviewer_id' => 0 ) ), 'sit_cwm_not_managed', 404 );
	}

	/**
	 * Missing posts, unmanaged post types and unreadable posts are all 404.
	 *
	 * @return void
	 */
	public function test_unresolvable_posts_are_not_found() {
		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );

		$admin = $this->make_user( 'administrator' );
		$page  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $admin,
			)
		);

		wp_set_current_user( $admin );

		// Missing ids answer exactly like hidden posts, so ids cannot be enumerated.
		$this->assert_error_response( $this->get( 999999 ), 'sit_cwm_not_managed', 404 );
		$this->assert_error_response( $this->get( $page ), 'sit_cwm_not_managed', 404 );

		wp_set_current_user( $this->make_user( 'author' ) );

		$this->assert_error_response( $this->get( $this->make_post( $admin ) ), 'sit_cwm_not_managed', 404 );
	}

	/**
	 * GET returns the full workflow payload for the post's author.
	 *
	 * @return void
	 */
	public function test_get_returns_workflow() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author, 'writing' );

		wp_set_current_user( $author );

		$response = $this->get( $post );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $post, $data['post_id'] );
		$this->assertSame( 'writing', $data['status'] );
		$this->assertSame( array( 'review' ), array_column( $data['available_transitions'], 'slug' ) );
		$this->assertArrayHasKey( 'capabilities', $data );
	}

	/**
	 * The headline security test: an author cannot approve their own post by
	 * sending `approved`, and nothing is written.
	 *
	 * @return void
	 */
	public function test_author_cannot_approve_via_rest() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author, 'review' );

		wp_set_current_user( $author );

		$response = $this->post(
			$post,
			array(
				'status' => 'approved',
				'from'   => 'review',
			)
		);

		$this->assert_error_response( $response, 'sit_cwm_forbidden', 403 );
		$this->assertSame( 'review', get_post_meta( $post, PostRepository::META_STATUS, true ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post ) );
	}

	/**
	 * `status` without `from` is rejected before anything else.
	 *
	 * @return void
	 */
	public function test_status_without_from_is_bad_request() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $editor, 'review' );

		wp_set_current_user( $editor );

		$this->assert_error_response( $this->post( $post, array( 'status' => 'approved' ) ), 'rest_missing_callback_param', 400 );
		$this->assertSame( 'review', $this->posts->get_status( $post ) );
	}

	/**
	 * Illegal edges and unknown statuses are 400, a stale `from` is 409.
	 *
	 * @return void
	 */
	public function test_invalid_and_stale_transitions() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $editor, 'review' );

		wp_set_current_user( $editor );

		$this->assert_error_response(
			$this->post(
				$post,
				array(
					'status' => 'published',
					'from'   => 'review',
				)
			),
			'sit_cwm_invalid_transition',
			400
		);
		$this->assert_error_response(
			$this->post(
				$post,
				array(
					'status' => 'bogus',
					'from'   => 'review',
				)
			),
			'rest_invalid_param',
			400
		);
		$this->assert_error_response(
			$this->post(
				$post,
				array(
					'status' => 'review',
					'from'   => 'writing',
				)
			),
			'sit_cwm_status_conflict',
			409
		);

		$this->assertSame( 'review', $this->posts->get_status( $post ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post ) );
	}

	/**
	 * Unknown and negative reviewer ids are 400.
	 *
	 * @return void
	 */
	public function test_invalid_reviewer_is_bad_request() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $editor );

		wp_set_current_user( $editor );

		$this->assert_error_response( $this->post( $post, array( 'reviewer_id' => 999999 ) ), 'sit_cwm_invalid_user', 400 );
		$this->assert_error_response( $this->post( $post, array( 'reviewer_id' => -1 * $editor ) ), 'rest_invalid_param', 400 );
		$this->assertSame( 0, $this->posts->get_reviewer_id( $post ) );
	}

	/**
	 * Malformed and impossible due dates are 400.
	 *
	 * @return void
	 */
	public function test_invalid_due_date_is_bad_request() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $editor );

		wp_set_current_user( $editor );

		foreach ( array( '20-09-2026', '2026-02-31', 'tomorrow' ) as $date ) {
			$this->assert_error_response( $this->post( $post, array( 'due_date' => $date ) ), 'rest_invalid_param', 400, $date );
		}

		$this->assertSame( '', $this->posts->get_due_date( $post ) );
	}

	/**
	 * A request is authorized as a whole: an unauthorized reviewer change
	 * blocks an otherwise allowed status change in the same body.
	 *
	 * @return void
	 */
	public function test_unauthorized_field_blocks_whole_request() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author, 'writing' );

		wp_set_current_user( $author );

		$response = $this->post(
			$post,
			array(
				'status'      => 'review',
				'from'        => 'writing',
				'reviewer_id' => $author,
			)
		);

		$this->assert_error_response( $response, 'sit_cwm_forbidden', 403 );
		$this->assertSame( 'writing', $this->posts->get_status( $post ) );
		$this->assertSame( 0, $this->posts->get_reviewer_id( $post ) );
	}

	/**
	 * A POST with nothing to change is 400.
	 *
	 * @return void
	 */
	public function test_empty_update_is_bad_request() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $editor );

		wp_set_current_user( $editor );

		$this->assert_error_response( $this->post( $post, array( 'evil' => 'x' ) ), 'rest_missing_callback_param', 400 );
	}

	/**
	 * Fields outside the schema are ignored, never persisted.
	 *
	 * @return void
	 */
	public function test_unknown_fields_are_ignored() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author );

		wp_set_current_user( $author );

		$response = $this->post(
			$post,
			array(
				'status'          => 'writing',
				'from'            => 'draft',
				'post_status'     => 'publish',
				'_sit_cwm_status' => 'approved',
				'evil'            => 'x',
				'meta'            => array( '_sit_cwm_reviewer_id' => $author ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'writing', $this->posts->get_status( $post ) );
		$this->assertSame( 'draft', get_post_status( $post ) );
		$this->assertSame( 0, $this->posts->get_reviewer_id( $post ) );
		$this->assertFalse( metadata_exists( 'post', $post, 'evil' ) );
	}

	/**
	 * A combined POST applies every field and returns the fresh payload.
	 *
	 * @return void
	 */
	public function test_successful_update_returns_fresh_workflow() {
		$editor   = $this->make_user( 'editor' );
		$reviewer = $this->make_user( 'editor' );
		$post     = $this->make_post( $this->make_user( 'author' ), 'review' );

		wp_set_current_user( $editor );

		$response = $this->post(
			$post,
			array(
				'status'      => 'approved',
				'from'        => 'review',
				'reviewer_id' => $reviewer,
				'due_date'    => '2026-09-20',
			)
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'approved', $data['status'] );
		$this->assertSame( $reviewer, $data['reviewer']['id'] );
		$this->assertSame( '2026-09-20', $data['due_date'] );
		$this->assertSame( array( 'published', 'needs_changes' ), array_column( $data['available_transitions'], 'slug' ) );
		$this->assertSame(
			array( 'status_changed', 'due_date_set', 'reviewer_assigned' ),
			array_map(
				static function ( $entry ) {
					return $entry->get_action();
				},
				$this->activity->get_for_post( $post )
			)
		);
	}

	/**
	 * `/statuses` is for content editors: 403 for subscribers, metadata for authors.
	 *
	 * @return void
	 */
	public function test_statuses_route() {
		wp_set_current_user( $this->make_user( 'subscriber' ) );
		$this->assert_error_response( $this->request( 'GET', '/sit-cwm/v1/statuses' ), 'sit_cwm_forbidden', 403 );

		wp_set_current_user( $this->make_user( 'author' ) );
		$response = $this->request( 'GET', '/sit-cwm/v1/statuses' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'draft', $data['default_status'] );
		$this->assertSame( array( 'draft', 'writing', 'review', 'needs_changes', 'approved', 'published' ), array_column( $data['statuses'], 'slug' ) );
		$this->assertSame( array( 'approved', 'needs_changes' ), array_column( $data['transitions']->review, 'slug' ) );
	}

	/**
	 * Every `sit-cwm/v1` route has a real permission callback.
	 *
	 * @return void
	 */
	public function test_every_route_has_a_real_permission_callback() {
		$routes = rest_get_server()->get_routes( 'sit-cwm/v1' );

		$this->assertArrayHasKey( '/sit-cwm/v1/posts/(?P<post_id>[\d]+)/workflow', $routes );
		$this->assertArrayHasKey( '/sit-cwm/v1/statuses', $routes );

		foreach ( $routes as $route => $handlers ) {
			if ( '/sit-cwm/v1' === $route ) {
				continue; // Namespace index, registered by core.
			}

			foreach ( $handlers as $handler ) {
				$this->assertNotEmpty( $handler['permission_callback'], $route );
				$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
			}
		}
	}

	/**
	 * The container builds the controller.
	 *
	 * @return void
	 */
	public function test_container_wires_controller() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( WorkflowController::class, $plugin->container()->get( 'rest.workflow' ) );
	}

	/**
	 * Dispatches a GET for a post's workflow.
	 *
	 * @param int $post_id Post id.
	 * @return WP_REST_Response
	 */
	private function get( int $post_id ): WP_REST_Response {
		return $this->request( 'GET', '/sit-cwm/v1/posts/' . $post_id . '/workflow' );
	}

	/**
	 * Dispatches a JSON POST for a post's workflow.
	 *
	 * @param int   $post_id Post id.
	 * @param array $body    JSON body.
	 * @return WP_REST_Response
	 */
	private function post( int $post_id, array $body ): WP_REST_Response {
		return $this->request( 'POST', '/sit-cwm/v1/posts/' . $post_id . '/workflow', $body );
	}

	/**
	 * Dispatches a request through the REST server.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $route  Route.
	 * @param array|null $body   JSON body, if any.
	 * @return WP_REST_Response
	 */
	private function request( string $method, string $route, ?array $body = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Asserts an error response with a code and HTTP status.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param string           $code     Expected error code.
	 * @param int              $status   Expected HTTP status.
	 * @param string           $message  Optional failure message.
	 * @return void
	 */
	private function assert_error_response( WP_REST_Response $response, string $code, int $status, string $message = '' ) {
		$this->assertSame( $status, $response->get_status(), $message );
		$this->assertSame( $code, $response->as_error()->get_error_code(), $message );
	}

	/**
	 * Creates a user with a role.
	 *
	 * @param string $role Role slug.
	 * @return int User id.
	 */
	private function make_user( string $role ): int {
		return self::factory()->user->create( array( 'role' => $role ) );
	}

	/**
	 * Creates a post, optionally at a workflow status.
	 *
	 * @param int    $author_id   Author user id.
	 * @param string $status      Workflow status; empty leaves the default.
	 * @param string $post_status Native post status.
	 * @return int Post id.
	 */
	private function make_post( int $author_id, string $status = '', string $post_status = 'draft' ): int {
		$post = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => $post_status,
			)
		);

		if ( '' !== $status ) {
			$this->posts->set_status( $post, $status );
		}

		return $post;
	}
}
