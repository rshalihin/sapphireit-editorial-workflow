<?php
/**
 * Integration tests for workflow post meta registration.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Content\PostMeta;
use Sit_Cwm\Content\PostRepository;
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
 * Meta registration, REST exposure and the direct-write security guards.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Content\PostMeta
 */
final class PostMetaTest extends WP_UnitTestCase {

	/**
	 * Custom post type used to test per-type registration.
	 *
	 * @var string
	 */
	const PROBE_TYPE = 'sit_cwm_probe';

	/**
	 * Workflow meta keys.
	 *
	 * @var string[]
	 */
	const KEYS = array( PostRepository::META_STATUS, PostRepository::META_REVIEWER, PostRepository::META_DUE_DATE );

	/**
	 * Service under test.
	 *
	 * @var PostMeta
	 */
	private $meta;

	/**
	 * Repository for asserting stored values.
	 *
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Grants capabilities, builds services and resets the REST server.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		Capabilities::add_caps();

		$statuses    = new StatusManager();
		$settings    = new Settings();
		$this->posts = new PostRepository( $statuses, $settings );
		$this->meta  = new PostMeta( $statuses, new PermissionManager( $this->posts, $settings ), $this->posts, $settings );

		// WP_UnitTestCase::tear_down() unregisters every meta key, so the
		// registration the plugin did on `init` has to be repeated per test.
		$this->meta->register_meta();

		$this->reset_rest_server();
	}

	/**
	 * Removes the probe post type and its meta.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( self::KEYS as $key ) {
			unregister_post_meta( self::PROBE_TYPE, $key );
		}

		if ( post_type_exists( self::PROBE_TYPE ) ) {
			unregister_post_type( self::PROBE_TYPE );
		}

		$this->reset_rest_server();

		parent::tear_down();
	}

	/**
	 * Registration covers all three keys for both default post types.
	 *
	 * @return void
	 */
	public function test_meta_registered_for_default_post_types() {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			$registered = get_registered_meta_keys( 'post', $post_type );

			foreach ( self::KEYS as $key ) {
				$this->assertArrayHasKey( $key, $registered, "{$post_type}: {$key}" );
				$this->assertTrue( $registered[ $key ]['single'] );
				$this->assertNotEmpty( $registered[ $key ]['show_in_rest'] );
			}
		}
	}

	/**
	 * Meta appears in the REST response only once the post type is enabled.
	 *
	 * @return void
	 */
	public function test_meta_in_rest_for_enabled_post_types_only() {
		register_post_type(
			self::PROBE_TYPE,
			array(
				'public'       => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'custom-fields' ),
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = self::factory()->post->create( array( 'post_type' => self::PROBE_TYPE ) );
		$this->reset_rest_server();

		$meta = $this->get_meta( $post, 'edit', self::PROBE_TYPE );
		foreach ( self::KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $meta, $key );
		}

		add_filter(
			'sit_cwm_enabled_post_types',
			static function ( $types ) {
				$types[] = self::PROBE_TYPE;
				return $types;
			}
		);
		$this->meta->register_meta();

		$meta = $this->get_meta( $post, 'edit', self::PROBE_TYPE );
		$this->assertSame( 'draft', $meta[ PostRepository::META_STATUS ] );
		$this->assertSame( 0, $meta[ PostRepository::META_REVIEWER ] );
		$this->assertSame( '', $meta[ PostRepository::META_DUE_DATE ] );
	}

	/**
	 * Stored values appear in the edit context and never in the public view
	 * context.
	 *
	 * @return void
	 */
	public function test_meta_hidden_from_view_context() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->posts->set_status( $post, 'published' );
		$this->posts->set_reviewer_id( $post, $editor );
		$this->posts->set_due_date( $post, '2026-09-20' );

		wp_set_current_user( $editor );
		$meta = $this->get_meta( $post, 'edit' );
		$this->assertSame( 'published', $meta[ PostRepository::META_STATUS ] );
		$this->assertSame( $editor, $meta[ PostRepository::META_REVIEWER ] );
		$this->assertSame( '2026-09-20', $meta[ PostRepository::META_DUE_DATE ] );

		wp_set_current_user( 0 );
		$meta = $this->get_meta( $post, 'view' );
		foreach ( self::KEYS as $key ) {
			$this->assertArrayNotHasKey( $key, $meta, $key );
		}
	}

	/**
	 * Security regression guard: core's REST endpoint can never write the
	 * workflow status, not even for an administrator, and not by deleting it.
	 *
	 * @return void
	 */
	public function test_direct_rest_write_of_status_is_rejected() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post  = self::factory()->post->create( array( 'post_author' => $admin ) );
		wp_set_current_user( $admin );

		$response = $this->update_meta( $post, array( PostRepository::META_STATUS => 'approved' ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'draft', $this->posts->get_status( $post ) );

		$this->posts->set_status( $post, 'review' );

		$response = $this->update_meta( $post, array( PostRepository::META_STATUS => null ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'review', $this->posts->get_status( $post ) );
	}

	/**
	 * Every meta capability on the status key requires `do_not_allow`, which
	 * even multisite super admins cannot pass; other keys are unaffected.
	 *
	 * @return void
	 */
	public function test_status_meta_caps_are_locked() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post  = self::factory()->post->create( array( 'post_author' => $admin ) );

		foreach ( PostMeta::STATUS_LOCKED_CAPS as $cap ) {
			$this->assertContains( 'do_not_allow', map_meta_cap( $cap, $admin, $post, PostRepository::META_STATUS ), $cap );
			$this->assertNotContains( 'do_not_allow', map_meta_cap( $cap, $admin, $post, PostRepository::META_DUE_DATE ), $cap );
		}
	}

	/**
	 * An editor can set reviewer and due date through core's meta REST.
	 *
	 * @return void
	 */
	public function test_authorized_user_can_write_reviewer_and_due_date() {
		$editor   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$reviewer = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post     = self::factory()->post->create( array( 'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ) ) );
		wp_set_current_user( $editor );

		$response = $this->update_meta(
			$post,
			array(
				PostRepository::META_REVIEWER => $reviewer,
				PostRepository::META_DUE_DATE => '2026-09-20',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $reviewer, $this->posts->get_reviewer_id( $post ) );
		$this->assertSame( '2026-09-20', $this->posts->get_due_date( $post ) );
	}

	/**
	 * An author, lacking `sit_cwm_assign_reviewer`, gets 403 on their own post.
	 *
	 * @return void
	 */
	public function test_unauthorized_user_gets_403_for_reviewer_and_due_date() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post   = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'draft',
			)
		);
		wp_set_current_user( $author );

		$response = $this->update_meta( $post, array( PostRepository::META_REVIEWER => $editor ) );
		$this->assertSame( 403, $response->get_status() );

		$response = $this->update_meta( $post, array( PostRepository::META_DUE_DATE => '2026-09-20' ) );
		$this->assertSame( 403, $response->get_status() );

		$this->assertSame( 0, $this->posts->get_reviewer_id( $post ) );
		$this->assertSame( '', $this->posts->get_due_date( $post ) );
	}

	/**
	 * REST writes pass through the shared sanitizers; malformed dates are 400.
	 *
	 * @return void
	 */
	public function test_rest_writes_are_sanitized_and_validated() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post   = self::factory()->post->create();
		wp_set_current_user( $editor );

		$this->posts->set_reviewer_id( $post, $editor );
		$this->posts->set_due_date( $post, '2026-09-20' );

		$response = $this->update_meta(
			$post,
			array(
				PostRepository::META_REVIEWER => 999999,
				PostRepository::META_DUE_DATE => '2026-02-31',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->posts->get_reviewer_id( $post ) );
		$this->assertSame( '', $this->posts->get_due_date( $post ) );

		$response = $this->update_meta( $post, array( PostRepository::META_DUE_DATE => 'tomorrow' ) );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * `meta` object of a single-post REST response.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $context   REST context.
	 * @param string $post_type Post type (for the route base).
	 * @return array
	 */
	private function get_meta( int $post_id, string $context, string $post_type = 'post' ): array {
		$request = new WP_REST_Request( 'GET', $this->route( $post_id, $post_type ) );
		$request->set_param( 'context', $context );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$data = $response->get_data();

		return isset( $data['meta'] ) ? (array) $data['meta'] : array();
	}

	/**
	 * Updates a post's meta through core's REST endpoint.
	 *
	 * @param int   $post_id Post id.
	 * @param array $meta    Meta values.
	 * @return WP_REST_Response
	 */
	private function update_meta( int $post_id, array $meta ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', $this->route( $post_id ) );
		$request->set_body_params( array( 'meta' => $meta ) );

		return rest_do_request( $request );
	}

	/**
	 * Single-item route for a post.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $post_type Post type.
	 * @return string
	 */
	private function route( int $post_id, string $post_type = 'post' ): string {
		$base = 'post' === $post_type ? 'posts' : $post_type;

		return "/wp/v2/{$base}/{$post_id}";
	}

	/**
	 * Discards the REST server so routes are rebuilt on next use.
	 *
	 * @return void
	 */
	private function reset_rest_server() {
		global $wp_rest_server;

		// Post types cache their REST controller and controllers cache their
		// schema, so registering or unregistering meta is invisible without this.
		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			$post_type->rest_controller = null;
		}

		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core global; reset for test isolation.
	}
}
