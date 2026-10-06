<?php
/**
 * Every sit-cwm/v1 route answers with data its published schema describes.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration\REST;

use Sit_Cwm\Tests\TestCase;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks each route's `OPTIONS` schema against a real response.
 *
 * Clients (the sidebar, the dashboard, Pro add-ons) read the schema to learn
 * the response shape, so a field returned but not declared, or declared with
 * the wrong type, is a contract bug even when every other test passes.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\REST\WorkflowController::get_item_schema
 * @covers \Sit_Cwm\REST\WorkflowController::get_statuses_schema
 * @covers \Sit_Cwm\REST\WorkflowController::transition_schema
 * @covers \Sit_Cwm\REST\PostsController::get_item_schema
 * @covers \Sit_Cwm\REST\ActivityController::get_item_schema
 * @covers \Sit_Cwm\REST\BatchController::get_item_schema
 * @covers \Sit_Cwm\REST\UserController::get_item_schema
 */
final class SchemaContractTest extends TestCase {

	/**
	 * Managed post the routes act on.
	 *
	 * @var int
	 */
	private $post_id;

	/**
	 * Reviewer assigned to the post.
	 *
	 * @var int
	 */
	private $reviewer_id;

	/**
	 * Logs in an administrator and creates a post with some history.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->reviewer_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->post_id     = $this->create_managed_post(
			array(
				'post_title'      => 'Contract post',
				'workflow_status' => 'writing',
				'reviewer_id'     => $this->reviewer_id,
				'due_date'        => '2026-10-01',
			)
		);

		$this->rest_request( 'POST', $this->route( 'comments' ), array( 'message' => 'First note.' ) );
	}

	/**
	 * Single-object routes.
	 *
	 * @return void
	 */
	public function test_object_routes_match_their_schema() {
		$cases = array(
			array( 'GET', $this->route( 'workflow' ), null ),
			array( 'POST', $this->route( 'workflow' ), array( 'due_date' => '2026-11-15' ) ),
			array( 'POST', $this->route( 'comments' ), array( 'message' => 'Second note.' ) ),
			array(
				'POST',
				'/sit-cwm/v1/posts/batch',
				array(
					'post_ids' => array( $this->post_id ),
					'action'   => 'set_due_date',
					'payload'  => array( 'due_date' => '2026-12-01' ),
				),
			),
		);

		foreach ( $cases as list( $method, $route, $body ) ) {
			$response = $this->rest_request( $method, $route, $body );

			$this->assertLessThan( 300, $response->get_status(), "$method $route" );
			$this->assert_matches_schema( $response->get_data(), $this->schema( $route ), "$method $route" );
		}
	}

	/**
	 * Collection routes: every item matches the item schema.
	 *
	 * @return void
	 */
	public function test_collection_routes_match_their_schema() {
		$routes = array(
			'/sit-cwm/v1/posts',
			$this->route( 'activity' ),
			'/sit-cwm/v1/users',
		);

		foreach ( $routes as $route ) {
			$response = $this->rest_request( 'GET', $route );
			$items    = $response->get_data();

			$this->assertSame( 200, $response->get_status(), $route );
			$this->assertNotEmpty( $items, $route );

			foreach ( $items as $item ) {
				$this->assert_matches_schema( $item, $this->schema( $route ), $route );
			}
		}
	}

	/**
	 * The status registry route matches its schema.
	 *
	 * @return void
	 */
	public function test_statuses_route_matches_its_schema() {
		$response = $this->rest_request( 'GET', '/sit-cwm/v1/statuses' );
		$schema   = $this->schema( '/sit-cwm/v1/statuses' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assert_matches_schema( $data, $schema, 'statuses' );

		foreach ( $data['statuses'] as $status ) {
			$this->assert_matches_schema( $status, $schema['properties']['statuses']['items'], 'status ' . $status['slug'] );
		}

		$this->assertNotEmpty( (array) $data['transitions'] );
	}

	/**
	 * Asserts a value validates against a schema and declares no extra keys.
	 *
	 * @param mixed  $value   Response data.
	 * @param array  $schema  JSON schema.
	 * @param string $context Failure message prefix.
	 * @return void
	 */
	private function assert_matches_schema( $value, array $schema, string $context ): void {
		$result = rest_validate_value_from_schema( $value, $schema, 'response' );

		$this->assertTrue( $result, $context . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );

		if ( is_array( $value ) && isset( $schema['properties'] ) ) {
			$this->assertSame(
				array(),
				array_values( array_diff( array_keys( $value ), array_keys( $schema['properties'] ) ) ),
				$context . ': fields returned but not declared in the schema.'
			);
		}
	}

	/**
	 * The schema a route publishes through `OPTIONS`.
	 *
	 * @param string $route Route.
	 * @return array
	 */
	private function schema( string $route ): array {
		$response = rest_do_request( new WP_REST_Request( 'OPTIONS', $route ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'schema', $data, "OPTIONS $route publishes no schema." );
		$this->assertNotEmpty( $data['schema']['title'] ?? '', "Schema of $route has no title." );

		return $data['schema'];
	}

	/**
	 * A per-post route of the managed post.
	 *
	 * @param string $leaf `workflow`, `activity` or `comments`.
	 * @return string
	 */
	private function route( string $leaf ): string {
		return '/sit-cwm/v1/posts/' . $this->post_id . '/' . $leaf;
	}
}
