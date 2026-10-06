<?php
/**
 * Integration tests for the reviewer list REST controller.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration\REST;

use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\REST\UserController;
use Sit_Cwm\Workflow\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `GET /sit-cwm/v1/users`, dispatched through the REST server.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\REST\UserController
 * @covers \Sit_Cwm\REST\UserSummaries
 */
final class UserControllerTest extends WP_UnitTestCase {

	/**
	 * Custom role holding the review capability but no editing capabilities.
	 *
	 * @var string
	 */
	const REVIEW_ONLY_ROLE = 'sit_cwm_test_review_only';

	/**
	 * Grants default capabilities and starts a fresh REST server.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Capabilities::add_caps();

		add_role(
			self::REVIEW_ONLY_ROLE,
			'Review only',
			array(
				'read'                       => true,
				Capabilities::REVIEW_CONTENT => true,
			)
		);

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		rest_get_server();
	}

	/**
	 * Removes the custom role and discards the REST server.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_role( self::REVIEW_ONLY_ROLE );
		remove_all_filters( 'sit_cwm_assignable_reviewers' );

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.

		parent::tear_down();
	}

	/**
	 * Logged out is 401; subscribers and authors cannot enumerate users.
	 *
	 * @return void
	 */
	public function test_users_without_assign_capability_cannot_list() {
		$post = self::factory()->post->create(
			array(
				'post_author' => $this->make_user( 'author' ),
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( 0 );
		$this->assert_error_response( $this->get_users(), 'rest_forbidden', 401 );

		wp_set_current_user( $this->make_user( 'subscriber' ) );
		$this->assert_error_response( $this->get_users(), 'sit_cwm_forbidden', 403 );

		$author = $this->make_user( 'author' );
		$own    = self::factory()->post->create( array( 'post_author' => $author ) );

		wp_set_current_user( $author );
		$this->assert_error_response( $this->get_users(), 'sit_cwm_forbidden', 403 );
		$this->assert_error_response( $this->get_users( array( 'post_id' => $own ) ), 'sit_cwm_forbidden', 403 );
		$this->assert_error_response( $this->get_users( array( 'post_id' => $post ) ), 'sit_cwm_not_managed', 404 );
	}

	/**
	 * An editor gets only users with the review capability, as id, name and
	 * avatar — never an email address.
	 *
	 * @return void
	 */
	public function test_editor_lists_only_reviewers_without_private_fields() {
		$editor = self::factory()->user->create(
			array(
				'role'       => 'editor',
				'user_email' => 'editor-private@example.org',
			)
		);
		$other  = $this->make_user( 'editor' );
		$author = $this->make_user( 'author' );
		$sub    = $this->make_user( 'subscriber' );

		wp_set_current_user( $editor );

		$response = $this->get_users( array( 'per_page' => 100 ) );
		$data     = $response->get_data();
		$ids      = array_column( $data, 'id' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $editor, $ids );
		$this->assertContains( $other, $ids );
		$this->assertNotContains( $author, $ids );
		$this->assertNotContains( $sub, $ids );

		foreach ( $data as $user ) {
			$this->assertSame( array( 'id', 'name', 'avatar' ), array_keys( $user ) );
			$this->assertTrue( user_can( $user['id'], Capabilities::REVIEW_CONTENT ) );
		}

		$this->assertStringNotContainsString( 'editor-private@example.org', (string) wp_json_encode( $data ) );
	}

	/**
	 * With `post_id`, reviewers who cannot edit that post are left out.
	 *
	 * @return void
	 */
	public function test_post_id_scopes_to_users_who_can_review_that_post() {
		$editor      = $this->make_user( 'editor' );
		$review_only = $this->make_user( self::REVIEW_ONLY_ROLE );
		$post        = self::factory()->post->create( array( 'post_author' => $this->make_user( 'author' ) ) );

		wp_set_current_user( $editor );

		$unscoped = array_column( $this->get_users( array( 'per_page' => 100 ) )->get_data(), 'id' );
		$scoped   = array_column(
			$this->get_users(
				array(
					'per_page' => 100,
					'post_id'  => $post,
				)
			)->get_data(),
			'id'
		);

		$this->assertContains( $review_only, $unscoped );
		$this->assertNotContains( $review_only, $scoped );
		$this->assertContains( $editor, $scoped );
		$this->assert_error_response( $this->get_users( array( 'post_id' => 999999 ) ), 'sit_cwm_not_managed', 404 );
	}

	/**
	 * Search matches display names, never email addresses; `per_page` is capped.
	 *
	 * @return void
	 */
	public function test_search_matches_names_not_emails() {
		$editor = $this->make_user( 'editor' );
		$zelda  = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Zelda Probe',
				'user_email'   => 'hidden-mailbox@example.org',
			)
		);

		wp_set_current_user( $editor );

		$this->assertSame( array( $zelda ), array_column( $this->get_users( array( 'search' => 'Zelda' ) )->get_data(), 'id' ) );
		$this->assertSame( array(), $this->get_users( array( 'search' => 'hidden-mailbox' ) )->get_data() );
		$this->assertCount( 1, $this->get_users( array( 'per_page' => 1 ) )->get_data() );
		$this->assert_error_response( $this->get_users( array( 'per_page' => 101 ) ), 'rest_invalid_param', 400 );
	}

	/**
	 * `sit_cwm_assignable_reviewers` receives the query and cannot widen the
	 * response fields.
	 *
	 * @return void
	 */
	public function test_assignable_reviewers_filter() {
		$editor = $this->make_user( 'editor' );
		$other  = $this->make_user( 'editor' );

		add_filter(
			'sit_cwm_assignable_reviewers',
			static function ( $query ) use ( $other ) {
				$query['include'] = array( $other );
				$query['fields']  = 'all_with_meta';

				return $query;
			}
		);

		wp_set_current_user( $editor );

		$data = $this->get_users()->get_data();

		$this->assertSame( array( $other ), array_column( $data, 'id' ) );
		$this->assertSame( array( 'id', 'name', 'avatar' ), array_keys( $data[0] ) );
	}

	/**
	 * The container builds the controller.
	 *
	 * @return void
	 */
	public function test_container_wires_controller() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( UserController::class, $plugin->container()->get( 'rest.users' ) );
	}

	/**
	 * Dispatches a GET for the reviewer list.
	 *
	 * @param array $query Query parameters.
	 * @return WP_REST_Response
	 */
	private function get_users( array $query = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/sit-cwm/v1/users' );
		$request->set_query_params( $query );

		return rest_do_request( $request );
	}

	/**
	 * Asserts an error response with a code and HTTP status.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param string           $code     Expected error code.
	 * @param int              $status   Expected HTTP status.
	 * @return void
	 */
	private function assert_error_response( WP_REST_Response $response, string $code, int $status ) {
		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $code, $response->as_error()->get_error_code() );
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
}
