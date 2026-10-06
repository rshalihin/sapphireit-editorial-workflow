<?php
/**
 * Security regression suite (step 19.4).
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Database;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attacks that must never silently start working again. Each test is the
 * end-to-end form of a rule enforced deeper in the stack.
 *
 * @since 1.0.0
 *
 * @coversNothing
 */
final class NegativeSuiteTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Core's posts endpoint cannot write the workflow status meta.
	 *
	 * @return void
	 */
	public function test_core_posts_endpoint_cannot_write_workflow_status() {
		$editor = self::fixture_user( 'editor' );
		$post   = $this->create_managed_post(
			array(
				'post_author'     => $editor,
				'workflow_status' => 'review',
			)
		);

		wp_set_current_user( $editor );

		$response = $this->rest_request( 'POST', '/wp/v2/posts/' . $post, array( 'meta' => array( PostRepository::META_STATUS => 'published' ) ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assert_status( $post, 'review' );
		$this->assert_activity_count( $post, 0 );
	}

	/**
	 * An author approving their own post is 403 and writes nothing.
	 *
	 * @return void
	 */
	public function test_author_cannot_approve() {
		$author = self::fixture_user( 'author' );
		$post   = $this->create_managed_post(
			array(
				'post_author'     => $author,
				'workflow_status' => 'review',
			)
		);

		wp_set_current_user( $author );

		$this->assert_error_response( $this->workflow_post( $post, 'review', 'approved' ), 'sit_cwm_forbidden', 403 );
		$this->assert_status( $post, 'review' );
		$this->assert_activity_count( $post, 0 );
	}

	/**
	 * The state machine cannot be skipped, singly or in bulk.
	 *
	 * @return void
	 */
	public function test_state_machine_cannot_be_skipped() {
		$post = $this->create_managed_post(
			array(
				'post_author'     => self::fixture_user( 'author' ),
				'workflow_status' => 'writing',
			)
		);

		wp_set_current_user( self::fixture_user( 'admin' ) );

		$this->assert_error_response( $this->workflow_post( $post, 'writing', 'published' ), 'sit_cwm_invalid_transition', 400 );

		$batch = $this->rest_request(
			'POST',
			'/sit-cwm/v1/posts/batch',
			array(
				'post_ids' => array( $post ),
				'action'   => 'change_status',
				'payload'  => array( 'status' => 'published' ),
			)
		)->get_data();

		$this->assertSame( 'sit_cwm_invalid_transition', $batch['failed'][0]['code'] );
		$this->assert_status( $post, 'writing' );
	}

	/**
	 * A stale `from` is a 409.
	 *
	 * @return void
	 */
	public function test_stale_from_is_conflict() {
		$post = $this->create_managed_post(
			array(
				'post_author'     => self::fixture_user( 'author' ),
				'workflow_status' => 'review',
			)
		);

		wp_set_current_user( self::fixture_user( 'editor' ) );

		$this->assert_error_response( $this->workflow_post( $post, 'writing', 'review' ), 'sit_cwm_status_conflict', 409 );
		$this->assert_status( $post, 'review' );
	}

	/**
	 * A bulk request with one unauthorized id fails only that id.
	 *
	 * @return void
	 */
	public function test_bulk_with_unauthorized_id_fails_only_that_id() {
		$author = self::fixture_user( 'author' );
		$own    = $this->create_managed_post(
			array(
				'post_author'     => $author,
				'workflow_status' => 'writing',
			)
		);
		$other  = $this->create_managed_post(
			array(
				'post_author'     => self::fixture_user( 'editor' ),
				'post_status'     => 'publish',
				'workflow_status' => 'writing',
			)
		);

		wp_set_current_user( $author );

		$data = $this->rest_request(
			'POST',
			'/sit-cwm/v1/posts/batch',
			array(
				'post_ids' => array( $own, $other ),
				'action'   => 'change_status',
				'payload'  => array( 'status' => 'review' ),
			)
		)->get_data();

		$this->assertSame( array( $own ), $data['succeeded'] );
		$this->assertSame( array( $other ), array_column( $data['failed'], 'post_id' ) );
		$this->assertSame( 'sit_cwm_forbidden', $data['failed'][0]['code'] );
		$this->assert_status( $other, 'writing' );
	}

	/**
	 * Every `sit-cwm/v1` route has a permission callback that is not `__return_true`.
	 *
	 * @return void
	 */
	public function test_every_route_has_a_real_permission_callback() {
		$routes   = rest_get_server()->get_routes( 'sit-cwm/v1' );
		$expected = array(
			'/sit-cwm/v1/posts/(?P<post_id>[\d]+)/workflow',
			'/sit-cwm/v1/posts/(?P<post_id>[\d]+)/activity',
			'/sit-cwm/v1/posts/(?P<post_id>[\d]+)/comments',
			'/sit-cwm/v1/statuses',
			'/sit-cwm/v1/posts',
			'/sit-cwm/v1/posts/batch',
			'/sit-cwm/v1/users',
		);

		foreach ( $expected as $route ) {
			$this->assertArrayHasKey( $route, $routes );
		}

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
	 * SQL-injection attempts in sort, search and filter arguments are rejected
	 * by the schema or matched as plain text, and every table survives.
	 *
	 * @return void
	 */
	public function test_sql_injection_attempts_are_rejected_or_inert() {
		global $wpdb;

		$editor = self::fixture_user( 'editor' );
		$post   = $this->create_managed_post(
			array(
				'post_author' => $editor,
				'post_title'  => 'sqliprobe',
			)
		);

		wp_set_current_user( $editor );

		$rejected = array(
			'orderby' => array( '/sit-cwm/v1/posts', array( 'orderby' => "title; DROP TABLE {$wpdb->posts}" ) ),
			'order'   => array( '/sit-cwm/v1/posts', array( 'order' => 'asc, (SELECT SLEEP(5))' ) ),
			'status'  => array( '/sit-cwm/v1/posts', array( 'status' => "review') OR ('1'='1" ) ),
			'action'  => array( '/sit-cwm/v1/posts/' . $post . '/activity', array( 'action' => "status_changed' OR '1'='1" ) ),
		);

		foreach ( $rejected as $label => $case ) {
			$this->assert_error_response( $this->rest_request( 'GET', $case[0], null, $case[1] ), 'rest_invalid_param', 400, $label );
		}

		$search = $this->rest_request( 'GET', '/sit-cwm/v1/posts', null, array( 'search' => "sqliprobe' OR '1'='1" ) );

		$this->assertSame( 200, $search->get_status() );
		$this->assertSame( array(), $search->get_data() );

		$users = $this->rest_request( 'GET', '/sit-cwm/v1/users', null, array( 'search' => "' OR 1=1 -- " ) );

		$this->assertSame( 200, $users->get_status() );

		$this->assertNotNull( get_post( $post ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifying the plugin table survived; no API exists.
		$this->assertSame( ( new Database() )->table_name(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', ( new Database() )->table_name() ) ) );
	}

	/**
	 * Dispatches a status change for a post's workflow.
	 *
	 * @param int    $post_id Post id.
	 * @param string $from    Status the client saw.
	 * @param string $to      Target status.
	 * @return \WP_REST_Response
	 */
	private function workflow_post( int $post_id, string $from, string $to ) {
		return $this->rest_request(
			'POST',
			'/sit-cwm/v1/posts/' . $post_id . '/workflow',
			array(
				'from'   => $from,
				'status' => $to,
			)
		);
	}
}
