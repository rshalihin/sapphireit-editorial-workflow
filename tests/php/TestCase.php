<?php
/**
 * Shared base class for integration tests.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostMeta;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Database;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Real activity table, default capabilities and settings, a fresh REST server
 * per test, plus factories and assertions for workflow state.
 *
 * @since 1.0.0
 */
abstract class TestCase extends WP_UnitTestCase {

	/**
	 * Workflow meta persistence, for arranging and asserting state.
	 *
	 * @var PostRepository
	 */
	protected $posts;

	/**
	 * Activity history, for arranging and asserting entries.
	 *
	 * @var ActivityLogger
	 */
	protected $activity;

	/**
	 * Ensures the real activity table exists.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		/*
		 * Parent first: it reconnects ($wpdb->db_connect()), which drops every
		 * temporary table the previous class left behind. Installing before
		 * that would let such a leftover shadow the real table -- dbDelta finds
		 * a table of that name, creates nothing, and the reconnect then takes
		 * it away, leaving this class with no activity table at all.
		 */
		parent::set_up_before_class();

		( new Database() )->install();
	}

	/**
	 * Resets settings, grants default capabilities and starts a fresh REST server.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Capabilities::add_caps();

		$statuses       = new StatusManager();
		$settings       = new Settings();
		$this->posts    = new PostRepository( $statuses, $settings );
		$this->activity = new ActivityLogger( new Database() );

		/*
		 * The plugin registers its post meta once, on `init`, but
		 * WP_UnitTestCase::tear_down() calls unregister_all_meta_keys() after
		 * every test. Without re-registering here, every test but the first
		 * would run against a site where the workflow meta is unknown to core:
		 * `_sit_cwm_status` would be missing from REST responses and core's
		 * meta endpoint would ignore writes to it instead of rejecting them,
		 * which silently turns the security guards in this suite green.
		 */
		$meta = new PostMeta( $statuses, new PermissionManager( $this->posts, $settings ), $this->posts, $settings );
		$meta->register_meta();

		$this->reset_rest_server();
	}

	/**
	 * Starts a fresh REST server, the way the next request would.
	 *
	 * Controllers cache their schema, so anything that changes registered meta
	 * has to reset the server before asserting on `OPTIONS` output.
	 *
	 * @return void
	 */
	protected function reset_rest_server(): void {
		/*
		 * A new server alone is not enough: WP_Post_Type caches its REST
		 * controller, and the controller caches its schema, so a post type
		 * whose meta changed would keep answering OPTIONS from the old schema.
		 */
		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			$post_type->rest_controller = null;
		}

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
	 * Creates a user holding exactly a role plus extra capabilities.
	 *
	 * @param string[] $caps Capabilities granted directly to the user.
	 * @param string   $role Role slug; `subscriber` by default.
	 * @return int User id.
	 */
	protected function create_user_with_caps( array $caps, string $role = 'subscriber' ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		$user    = get_userdata( $user_id );

		foreach ( $caps as $cap ) {
			$user->add_cap( $cap );
		}

		return $user_id;
	}

	/**
	 * Creates a post and optionally sets its workflow state.
	 *
	 * @param array $args Post fields for the factory, plus the optional
	 *                    `workflow_status`, `reviewer_id` and `due_date`.
	 * @return int Post id.
	 */
	protected function create_managed_post( array $args = array() ): int {
		$workflow = array_intersect_key( $args, array_flip( array( 'workflow_status', 'reviewer_id', 'due_date' ) ) );
		$post_id  = self::factory()->post->create( array_diff_key( $args, $workflow ) );

		if ( isset( $workflow['workflow_status'] ) ) {
			$this->posts->set_status( $post_id, $workflow['workflow_status'] );
		}

		if ( isset( $workflow['reviewer_id'] ) ) {
			$this->posts->set_reviewer_id( $post_id, (int) $workflow['reviewer_id'] );
		}

		if ( isset( $workflow['due_date'] ) ) {
			$this->posts->set_due_date( $post_id, (string) $workflow['due_date'] );
		}

		return $post_id;
	}

	/**
	 * Asserts how many activity entries a post has.
	 *
	 * @param int    $post_id  Post id.
	 * @param int    $expected Expected count.
	 * @param string $message  Optional failure message.
	 * @return void
	 */
	protected function assert_activity_count( int $post_id, int $expected, string $message = '' ): void {
		$this->assertSame( $expected, $this->activity->count_for_post( $post_id ), $message );
	}

	/**
	 * Asserts a post's stored workflow status.
	 *
	 * @param int    $post_id  Post id.
	 * @param string $expected Expected status slug.
	 * @param string $message  Optional failure message.
	 * @return void
	 */
	protected function assert_status( int $post_id, string $expected, string $message = '' ): void {
		$this->assertSame( $expected, $this->posts->get_status( $post_id ), $message );
	}

	/**
	 * Dispatches a request through the REST server.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $route  Route.
	 * @param array|null $body   JSON body, if any.
	 * @param array      $query  Query parameters.
	 * @return WP_REST_Response
	 */
	protected function rest_request( string $method, string $route, ?array $body = null, array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );

		if ( array() !== $query ) {
			$request->set_query_params( $query );
		}

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
	protected function assert_error_response( WP_REST_Response $response, string $code, int $status, string $message = '' ): void {
		$this->assertSame( $status, $response->get_status(), $message );
		$this->assertSame( $code, $response->as_error()->get_error_code(), $message );
	}
}
