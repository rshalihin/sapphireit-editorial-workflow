<?php
/**
 * Integration tests for the dashboard collection REST controller.
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
use Sit_Cwm\REST\PostsController;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\StatusManager;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /sit-cwm/v1/posts`, dispatched through the REST server.
 *
 * Each test tags its posts with a unique title word and searches for it, so
 * content left in the test database by core's installer never interferes.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\REST\PostsController
 * @covers \Sit_Cwm\Content\PostRepository::query_posts
 * @covers \Sit_Cwm\Content\PostRepository::dashboard_meta_query
 * @covers \Sit_Cwm\Content\PostRepository::status_order_sql
 */
final class PostsControllerTest extends WP_UnitTestCase {

	/**
	 * Workflow meta persistence, for arranging state.
	 *
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Activity history, for arranging entries.
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
	 * Logged out is 401, a subscriber is 403.
	 *
	 * @return void
	 */
	public function test_logged_out_and_subscriber_are_rejected() {
		wp_set_current_user( 0 );
		$this->assert_error_response( $this->get_posts(), 'rest_forbidden', 401 );

		wp_set_current_user( $this->make_user( 'subscriber' ) );
		$this->assert_error_response( $this->get_posts(), 'sit_cwm_forbidden', 403 );
	}

	/**
	 * `status=review&reviewer_id=N` returns exactly the matching set.
	 *
	 * @return void
	 */
	public function test_status_and_reviewer_filters_return_exact_set() {
		$editor   = $this->make_user( 'editor' );
		$reviewer = $this->make_user( 'editor' );
		$other    = $this->make_user( 'editor' );

		$match = $this->make_post( $editor, 'filterprobe', 'review', $reviewer );
		$this->make_post( $editor, 'filterprobe', 'review', $other );
		$this->make_post( $editor, 'filterprobe', 'writing', $reviewer );
		$this->make_post( $editor, 'filterprobe', 'review' );

		wp_set_current_user( $editor );

		$response = $this->get_posts(
			array(
				'status'      => 'review',
				'reviewer_id' => $reviewer,
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $match ), array_column( $response->get_data(), 'post_id' ) );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
	}

	/**
	 * Posts without stored status meta match the default status; several
	 * statuses may be requested at once.
	 *
	 * @return void
	 */
	public function test_draft_filter_includes_posts_without_status_meta() {
		$editor  = $this->make_user( 'editor' );
		$fresh   = $this->make_post( $editor, 'draftprobe' );
		$writing = $this->make_post( $editor, 'draftprobe', 'writing' );
		$this->make_post( $editor, 'draftprobe', 'review' );

		wp_set_current_user( $editor );

		$this->assertSame(
			array( $fresh ),
			$this->ids(
				array(
					'search' => 'draftprobe',
					'status' => 'draft',
				)
			)
		);
		$this->assertEqualsCanonicalizing(
			array( $fresh, $writing ),
			$this->ids(
				array(
					'search' => 'draftprobe',
					'status' => array( 'draft', 'writing' ),
				)
			)
		);
	}

	/**
	 * An author sees only their own posts plus posts they review; an editor
	 * sees everything.
	 *
	 * @return void
	 */
	public function test_author_sees_only_own_and_assigned_posts() {
		$author = $this->make_user( 'author' );
		$second = $this->make_user( 'author' );
		$editor = $this->make_user( 'editor' );

		$own      = $this->make_post( $author, 'scopeprobe' );
		$foreign  = $this->make_post( $second, 'scopeprobe' );
		$assigned = $this->make_post( $second, 'scopeprobe', '', $author );

		wp_set_current_user( $author );

		$this->assertEqualsCanonicalizing( array( $own, $assigned ), $this->ids( array( 'search' => 'scopeprobe' ) ) );
		$this->assertSame(
			array( $assigned ),
			$this->ids(
				array(
					'search' => 'scopeprobe',
					'author' => $second,
				)
			)
		);

		wp_set_current_user( $editor );

		$this->assertEqualsCanonicalizing( array( $own, $foreign, $assigned ), $this->ids( array( 'search' => 'scopeprobe' ) ) );
	}

	/**
	 * Injection attempts and out-of-schema values are rejected by the schema.
	 *
	 * @return void
	 */
	public function test_invalid_arguments_are_rejected() {
		wp_set_current_user( $this->make_user( 'editor' ) );

		$invalid = array(
			array( 'orderby' => '; DROP TABLE wp_posts' ),
			array( 'order' => 'sideways' ),
			array( 'status' => 'bogus' ),
			array( 'post_type' => 'attachment' ),
			array( 'due_before' => '2026-02-31' ),
			array( 'reviewer_id' => -1 ),
			array( 'per_page' => 101 ),
		);

		foreach ( $invalid as $query ) {
			$this->assert_error_response( $this->get_posts( $query ), 'rest_invalid_param', 400, (string) wp_json_encode( $query ) );
		}
	}

	/**
	 * Due date sorting puts undated posts last in both directions; status
	 * sorting follows the workflow order, not the alphabet.
	 *
	 * @return void
	 */
	public function test_orderby_due_date_and_status() {
		$editor = $this->make_user( 'editor' );
		$late   = $this->make_post( $editor, 'sortprobe', 'approved', 0, '2026-09-30' );
		$none   = $this->make_post( $editor, 'sortprobe' );
		$early  = $this->make_post( $editor, 'sortprobe', 'review', 0, '2026-09-10' );

		wp_set_current_user( $editor );

		$query = array(
			'search'  => 'sortprobe',
			'orderby' => 'due_date',
		);

		$this->assertSame( array( $early, $late, $none ), $this->ids( array_merge( $query, array( 'order' => 'asc' ) ) ) );
		$this->assertSame( array( $late, $early, $none ), $this->ids( array_merge( $query, array( 'order' => 'desc' ) ) ) );
		$this->assertSame(
			array( $none, $early, $late ),
			$this->ids(
				array(
					'search'  => 'sortprobe',
					'orderby' => 'status',
					'order'   => 'asc',
				)
			)
		);
	}

	/**
	 * Due date range, post type and pagination.
	 *
	 * @return void
	 */
	public function test_due_range_post_type_and_pagination() {
		$editor = $this->make_user( 'editor' );
		$inside = $this->make_post( $editor, 'rangeprobe', '', 0, '2026-09-15' );
		$this->make_post( $editor, 'rangeprobe', '', 0, '2026-09-25' );
		$this->make_post( $editor, 'rangeprobe' );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_author' => $editor,
				'post_title'  => 'rangeprobe page',
			)
		);

		wp_set_current_user( $editor );

		$this->assertSame(
			array( $inside ),
			$this->ids(
				array(
					'search'     => 'rangeprobe',
					'due_after'  => '2026-09-10',
					'due_before' => '2026-09-20',
				)
			)
		);
		$this->assertSame(
			array( $page ),
			$this->ids(
				array(
					'search'    => 'rangeprobe',
					'post_type' => 'page',
				)
			)
		);

		$response = $this->get_posts(
			array(
				'search'   => 'rangeprobe',
				'per_page' => 3,
				'page'     => 2,
			)
		);

		$this->assertCount( 1, $response->get_data() );
		$this->assertSame( '4', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $response->get_headers()['X-WP-TotalPages'] );
	}

	/**
	 * A row carries the documented fields, derived server-side.
	 *
	 * @return void
	 */
	public function test_row_shape() {
		$author   = self::factory()->user->create(
			array(
				'role'         => 'author',
				'display_name' => 'Ada Author',
			)
		);
		$reviewer = $this->make_user( 'editor' );
		$editor   = $this->make_user( 'editor' );
		$post     = $this->make_post( $author, 'shapeprobe', 'review', $reviewer, '2020-01-01' );

		$this->activity->log_comment( $post, 'Ping', $author );

		wp_set_current_user( $editor );

		$rows = $this->get_posts( array( 'search' => 'shapeprobe' ) )->get_data();

		$this->assertCount( 1, $rows );

		$row = $rows[0];

		$this->assertSame(
			array( 'post_id', 'title', 'post_type', 'post_status', 'author', 'status', 'status_label', 'status_is_unknown', 'reviewer', 'due_date', 'is_overdue', 'last_activity', 'edit_link', 'available_transitions', 'capabilities' ),
			array_keys( $row )
		);
		$this->assertSame(
			array(
				'can_change_status'   => true,
				'can_assign_reviewer' => true,
				'can_set_due_date'    => true,
				'can_comment'         => true,
				'can_view_activity'   => true,
			),
			$row['capabilities']
		);
		$this->assertSame(
			array(
				'id'   => $author,
				'name' => 'Ada Author',
			),
			$row['author']
		);
		$this->assertSame( 'Review', $row['status_label'] );
		$this->assertSame( $reviewer, $row['reviewer']['id'] );
		$this->assertTrue( $row['is_overdue'] );
		$this->assertSame( 'comment_added', $row['last_activity']['action'] );
		$this->assertSame( $author, $row['last_activity']['user']['id'] );
		$this->assertNotSame( '', $row['edit_link'] );
		$this->assertSame( array( 'approved', 'needs_changes' ), array_column( $row['available_transitions'], 'slug' ) );
	}

	/**
	 * The query count does not grow with the number of rows, and stays within
	 * the step 21 budget.
	 *
	 * @return void
	 */
	public function test_query_count_does_not_grow_with_page_size() {
		$editor = $this->make_user( 'editor' );

		$this->seed( 'fewprobe', 2 );
		$this->seed( 'manyprobe', 10 );

		wp_set_current_user( $editor );

		$few  = $this->count_queries( array( 'search' => 'fewprobe' ) );
		$many = $this->count_queries( array( 'search' => 'manyprobe' ) );

		$this->assertSame( $few, $many );
		$this->assertLessThanOrEqual( 8, $many );
	}

	/**
	 * The container builds the controller.
	 *
	 * @return void
	 */
	public function test_container_wires_controller() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( PostsController::class, $plugin->container()->get( 'rest.posts' ) );
	}

	/**
	 * Creates posts with distinct authors, reviewers, due dates and activity.
	 *
	 * @param string $word  Title word.
	 * @param int    $count Number of posts.
	 * @return void
	 */
	private function seed( string $word, int $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			$author = $this->make_user( 'author' );
			$post   = $this->make_post( $author, $word, 'review', $this->make_user( 'editor' ), '2026-10-01' );

			$this->activity->log_comment( $post, 'Comment ' . $i, $author );
		}
	}

	/**
	 * Queries run by one cold-cache collection request.
	 *
	 * Options and the current user are loaded before counting, as WordPress
	 * bootstrap loads them before any REST route runs.
	 *
	 * @param array $query Query parameters.
	 * @return int
	 */
	private function count_queries( array $query ): int {
		global $wpdb;

		wp_cache_flush();
		wp_load_alloptions();
		get_userdata( get_current_user_id() );

		$before   = $wpdb->num_queries;
		$response = $this->get_posts( $query );

		$this->assertSame( 200, $response->get_status() );

		return $wpdb->num_queries - $before;
	}

	/**
	 * Post ids of a collection request.
	 *
	 * @param array $query Query parameters.
	 * @return int[]
	 */
	private function ids( array $query ): array {
		$response = $this->get_posts( $query );

		$this->assertSame( 200, $response->get_status() );

		return array_column( $response->get_data(), 'post_id' );
	}

	/**
	 * Dispatches a GET for the collection.
	 *
	 * @param array $query Query parameters.
	 * @return WP_REST_Response
	 */
	private function get_posts( array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/sit-cwm/v1/posts' );
		$request->set_query_params( $query );

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
	 * Creates a draft post with workflow state.
	 *
	 * @param int    $author_id   Author user id.
	 * @param string $word        Title word used for searching.
	 * @param string $status      Workflow status; empty leaves none stored.
	 * @param int    $reviewer_id Reviewer id; 0 for none.
	 * @param string $due_date    Due date; empty for none.
	 * @return int Post id.
	 */
	private function make_post( int $author_id, string $word, string $status = '', int $reviewer_id = 0, string $due_date = '' ): int {
		$post = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
				'post_title'  => $word . ' ' . wp_generate_password( 6, false ),
			)
		);

		if ( '' !== $status ) {
			$this->posts->set_status( $post, $status );
		}

		if ( $reviewer_id > 0 ) {
			$this->posts->set_reviewer_id( $post, $reviewer_id );
		}

		if ( '' !== $due_date ) {
			$this->posts->set_due_date( $post, $due_date );
		}

		return $post;
	}
}
