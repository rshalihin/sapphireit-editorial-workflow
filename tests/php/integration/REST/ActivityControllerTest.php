<?php
/**
 * Integration tests for the activity REST controller.
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
use Sit_Cwm\REST\ActivityController;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\StatusManager;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `sit-cwm/v1` activity and comment routes, dispatched through the REST server.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\REST\ActivityController
 * @covers \Sit_Cwm\REST\ActivityFormatter
 * @covers \Sit_Cwm\REST\UserSummaries
 */
final class ActivityControllerTest extends WP_UnitTestCase {

	/**
	 * Workflow meta persistence, for arranging state.
	 *
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Activity history, for arranging and asserting entries.
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
	 * Logged-out requests are 401 on both routes.
	 *
	 * @return void
	 */
	public function test_logged_out_is_unauthorized() {
		$post = $this->make_post( $this->make_user( 'author' ) );

		wp_set_current_user( 0 );

		$this->assert_error_response( $this->get_activity( $post ), 'rest_forbidden', 401 );
		$this->assert_error_response( $this->post_comment( $post, array( 'message' => 'Hi' ) ), 'rest_forbidden', 401 );
	}

	/**
	 * A subscriber gets 403 on a post they can read.
	 *
	 * @return void
	 */
	public function test_subscriber_is_forbidden() {
		$post = $this->make_post( $this->make_user( 'author' ), 'publish' );

		wp_set_current_user( $this->make_user( 'subscriber' ) );

		$this->assert_error_response( $this->get_activity( $post ), 'sit_cwm_forbidden', 403 );
		$this->assert_error_response( $this->post_comment( $post, array( 'message' => 'Hi' ) ), 'sit_cwm_forbidden', 403 );
		$this->assertSame( 0, $this->activity->count_for_post( $post ) );
	}

	/**
	 * The assigned reviewer reads the activity of a post they don't own; an
	 * author cannot read another author's draft at all.
	 *
	 * @return void
	 */
	public function test_assigned_reviewer_reads_activity_of_post_they_do_not_own() {
		$author   = $this->make_user( 'author' );
		$reviewer = $this->make_user( 'editor' );
		$post     = $this->make_post( $author );

		$this->posts->set_reviewer_id( $post, $reviewer );
		$this->activity->log_comment( $post, 'Please review', $author );

		wp_set_current_user( $reviewer );

		$response = $this->get_activity( $post );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $response->get_data() );

		wp_set_current_user( $this->make_user( 'author' ) );

		$this->assert_error_response( $this->get_activity( $post ), 'sit_cwm_not_managed', 404 );
	}

	/**
	 * Pagination slices newest first and reports core-style totals.
	 *
	 * @return void
	 */
	public function test_pagination_and_total_headers() {
		$editor = $this->make_user( 'editor' );
		$post   = $this->make_post( $editor );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->activity->log(
				$post,
				'comment_added',
				array(
					'message'    => 'Note ' . $i,
					'user_id'    => $editor,
					'created_at' => sprintf( '2026-01-0%d 10:00:00', $i ),
				)
			);
		}

		wp_set_current_user( $editor );

		$response = $this->get_activity(
			$post,
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$headers  = $response->get_headers();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'Note 3', 'Note 2' ), array_column( $response->get_data(), 'message' ) );
		$this->assertSame( '5', $headers['X-WP-Total'] );
		$this->assertSame( '3', $headers['X-WP-TotalPages'] );

		$this->assert_error_response( $this->get_activity( $post, array( 'per_page' => 101 ) ), 'rest_invalid_param', 400 );
		$this->assert_error_response( $this->get_activity( $post, array( 'page' => 0 ) ), 'rest_invalid_param', 400 );
	}

	/**
	 * The `action` filter narrows results and totals; items carry labels, an
	 * ISO-8601 UTC date and a user summary without private fields.
	 *
	 * @return void
	 */
	public function test_action_filter_and_item_shape() {
		$editor   = $this->make_user( 'editor' );
		$reviewer = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Rita Reviewer',
				'user_email'   => 'rita-private@example.org',
			)
		);
		$post     = $this->make_post( $editor );

		$this->activity->log_status_change( $post, 'writing', 'review', $editor );
		$this->activity->log_reviewer_assigned( $post, $reviewer, 0, $editor );
		$this->activity->log_due_date_changed( $post, '2026-09-20', '', $editor );
		$this->activity->log_comment( $post, 'Looks good', $editor );

		wp_set_current_user( $editor );

		$response = $this->get_activity( $post, array( 'action' => 'status_changed' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertCount( 1, $data );
		$this->assertSame(
			array( 'id', 'action', 'action_label', 'old_value', 'old_label', 'new_value', 'new_label', 'message', 'created_at', 'created_at_human', 'user_id', 'user' ),
			array_keys( $data[0] )
		);
		$this->assertSame( $editor, $data[0]['user_id'] );
		$this->assertSame( 'Status changed', $data[0]['action_label'] );
		$this->assertSame( 'Writing', $data[0]['old_label'] );
		$this->assertSame( 'Review', $data[0]['new_label'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data[0]['created_at'] );
		$this->assertNotSame( '', $data[0]['created_at_human'] );
		$this->assertSame( array( 'id', 'name', 'avatar' ), array_keys( $data[0]['user'] ) );
		$this->assertSame( $editor, $data[0]['user']['id'] );

		$assigned = $this->get_activity( $post, array( 'action' => 'reviewer_assigned' ) )->get_data();

		$this->assertSame( 'Rita Reviewer', $assigned[0]['new_label'] );
		$this->assertNull( $assigned[0]['old_label'] );
		$this->assertStringNotContainsString( 'rita-private@example.org', (string) wp_json_encode( $assigned ) );

		$this->assert_error_response( $this->get_activity( $post, array( 'action' => 'bogus' ) ), 'rest_invalid_param', 400 );
	}

	/**
	 * A comment containing a script is stored and returned sanitized, with 201.
	 *
	 * @return void
	 */
	public function test_comment_is_sanitized_and_created() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author );

		wp_set_current_user( $author );

		$response = $this->post_comment( $post, array( 'message' => '<script>alert(1)</script><strong>Ready</strong> for review' ) );
		$data     = $response->get_data();
		$entries  = $this->activity->get_for_post( $post );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'comment_added', $data['action'] );
		$this->assertStringNotContainsString( '<script', $data['message'] );
		$this->assertStringContainsString( '<strong>Ready</strong> for review', $data['message'] );
		$this->assertSame( $author, $data['user']['id'] );
		$this->assertCount( 1, $entries );
		$this->assertSame( $entries[0]->get_id(), $data['id'] );
		$this->assertSame( $entries[0]->get_message(), $data['message'] );
	}

	/**
	 * Missing, empty, whitespace-only, markup-only and oversized comments are
	 * 400 and log nothing.
	 *
	 * @return void
	 */
	public function test_invalid_comments_are_rejected() {
		$author = $this->make_user( 'author' );
		$post   = $this->make_post( $author );

		wp_set_current_user( $author );

		$this->assert_error_response( $this->post_comment( $post, array() ), 'rest_missing_callback_param', 400 );

		foreach ( array( '', '   ', '<p> </p>', str_repeat( 'a', 5001 ) ) as $message ) {
			$this->assert_error_response( $this->post_comment( $post, array( 'message' => $message ) ), 'rest_invalid_param', 400, $message );
		}

		$this->assertSame( 201, $this->post_comment( $post, array( 'message' => str_repeat( 'a', 5000 ) ) )->get_status() );
		$this->assertSame( 1, $this->activity->count_for_post( $post ) );
	}

	/**
	 * Both routes are registered with real permission callbacks.
	 *
	 * @return void
	 */
	public function test_routes_have_permission_callbacks() {
		$routes = rest_get_server()->get_routes( 'sit-cwm/v1' );

		foreach ( array( '/sit-cwm/v1/posts/(?P<post_id>[\d]+)/activity', '/sit-cwm/v1/posts/(?P<post_id>[\d]+)/comments' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );

			foreach ( $routes[ $route ] as $handler ) {
				$this->assertIsArray( $handler['permission_callback'], $route );
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

		$this->assertInstanceOf( ActivityController::class, $plugin->container()->get( 'rest.activity' ) );
	}

	/**
	 * Dispatches a GET for a post's activity.
	 *
	 * @param int   $post_id Post id.
	 * @param array $query   Query parameters.
	 * @return WP_REST_Response
	 */
	private function get_activity( int $post_id, array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/sit-cwm/v1/posts/' . $post_id . '/activity' );
		$request->set_query_params( $query );

		return rest_do_request( $request );
	}

	/**
	 * Dispatches a JSON POST adding a comment.
	 *
	 * @param int   $post_id Post id.
	 * @param array $body    JSON body.
	 * @return WP_REST_Response
	 */
	private function post_comment( int $post_id, array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/sit-cwm/v1/posts/' . $post_id . '/comments' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

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
	 * Creates a post.
	 *
	 * @param int    $author_id   Author user id.
	 * @param string $post_status Native post status.
	 * @return int Post id.
	 */
	private function make_post( int $author_id, string $post_status = 'draft' ): int {
		return self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => $post_status,
			)
		);
	}
}
