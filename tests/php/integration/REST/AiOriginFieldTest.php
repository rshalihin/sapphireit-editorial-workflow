<?php
/**
 * Integration tests for the `sit_cwm_ai_origin` field on `wp/v2` post routes.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\REST;

use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\REST\AiOriginField;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Tests\Traits\RestoresPluginInstance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An agent can declare a post's AI origin through core's post routes, in the
 * same call that creates the post (F9).
 *
 * Runs against the booted plugin, so the field and the creation stamp are the
 * ones a real request gets.
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\REST\AiOriginField
 */
final class AiOriginFieldTest extends TestCase {

	use CreatesWorkflowPosts;
	use RestoresPluginInstance;

	/**
	 * Remembers the booted plugin.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->remember_plugin_instance();
	}

	/**
	 * Restores the booted plugin.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->restore_plugin_instance();

		parent::tear_down();
	}

	/**
	 * Creating a draft with the field logs exactly one `post_api` declaration
	 * and no default stamp on top.
	 *
	 * @return void
	 */
	public function test_rest_create_with_ai_origin_logs_post_api_declaration() {
		( new Settings() )->update( array( 'ai_origin_defaults' => array( 'post' => 'human' ) ) );
		wp_set_current_user( self::fixture_user( 'author' ) );

		$response = $this->rest_request(
			'POST',
			'/wp/v2/posts',
			array(
				'title'              => 'Agent draft',
				'status'             => 'draft',
				AiOriginField::FIELD => 'ai_generated',
			)
		);
		$post_id  = $response->get_data()['id'];

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'ai_generated', $response->get_data()[ AiOriginField::FIELD ] );

		$rows = $this->activity->get_for_post( $post_id, array( 'action' => 'ai_origin_changed' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'via' => 'post_api' ), $rows[0]->get_context() );
		$this->assertSame( 'ai_generated', $this->posts->get_ai_origin( $post_id ) );
	}

	/**
	 * Creating without the field still gets the type's default stamp.
	 *
	 * @return void
	 */
	public function test_rest_create_without_field_gets_default() {
		( new Settings() )->update( array( 'ai_origin_defaults' => array( 'post' => 'human' ) ) );
		wp_set_current_user( self::fixture_user( 'author' ) );

		$response = $this->rest_request(
			'POST',
			'/wp/v2/posts',
			array(
				'title'  => 'Plain draft',
				'status' => 'draft',
			)
		);

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'human', $response->get_data()[ AiOriginField::FIELD ] );

		$rows = $this->activity->get_for_post( $response->get_data()['id'], array( 'action' => 'ai_origin_changed' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'via' => 'default' ), $rows[0]->get_context() );
	}

	/**
	 * Updating an existing post declares with via `post_api`; sending back
	 * `''` changes and logs nothing.
	 *
	 * @return void
	 */
	public function test_update_declares_and_empty_round_trip_is_a_no_op() {
		$post = $this->create_managed_post( array( 'post_author' => self::fixture_user( 'author' ) ) );

		wp_set_current_user( self::fixture_user( 'editor' ) );

		$this->assertSame( 200, $this->rest_request( 'POST', "/wp/v2/posts/{$post}", array( AiOriginField::FIELD => '' ) )->get_status() );
		$this->assertSame( '', $this->posts->get_ai_origin( $post ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post, array( 'action' => 'ai_origin_changed' ) ) );

		$response = $this->rest_request( 'POST', "/wp/v2/posts/{$post}", array( AiOriginField::FIELD => 'ai_assisted' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ai_assisted', $response->get_data()[ AiOriginField::FIELD ] );

		$rows = $this->activity->get_for_post( $post, array( 'action' => 'ai_origin_changed' ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( array( 'via' => 'post_api' ), $rows[0]->get_context() );
	}

	/**
	 * The field is absent from `view` responses, present in `edit`, and
	 * rejects values outside the enum.
	 *
	 * @return void
	 */
	public function test_field_is_edit_context_only_and_rejects_bad_values() {
		$post = $this->create_managed_post( array( 'post_status' => 'publish' ) );

		wp_set_current_user( 0 );

		$this->assertArrayNotHasKey( AiOriginField::FIELD, $this->rest_request( 'GET', "/wp/v2/posts/{$post}" )->get_data() );

		wp_set_current_user( self::fixture_user( 'admin' ) );

		$this->assertArrayNotHasKey( AiOriginField::FIELD, $this->rest_request( 'GET', "/wp/v2/posts/{$post}" )->get_data() );
		$this->assertSame( '', $this->rest_request( 'GET', "/wp/v2/posts/{$post}", null, array( 'context' => 'edit' ) )->get_data()[ AiOriginField::FIELD ] );
		$this->assertSame( 400, $this->rest_request( 'POST', "/wp/v2/posts/{$post}", array( AiOriginField::FIELD => 'robot' ) )->get_status() );
		$this->assertSame( '', $this->posts->get_ai_origin( $post ) );
	}

	/**
	 * A user who may edit the post but not declare gets 403, and nothing is
	 * written.
	 *
	 * @return void
	 */
	public function test_field_update_by_user_who_cannot_declare_returns_403() {
		$post        = $this->create_managed_post( array( 'post_author' => self::fixture_user( 'author' ) ) );
		$no_workflow = $this->create_user_with_caps( array( 'edit_posts', 'edit_others_posts', 'edit_published_posts' ) );

		wp_set_current_user( $no_workflow );

		$response = $this->rest_request( 'POST', "/wp/v2/posts/{$post}", array( AiOriginField::FIELD => 'human' ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'sit_cwm_forbidden', $response->as_error()->get_error_code() );
		$this->assertSame( '', $this->posts->get_ai_origin( $post ) );
		$this->assertSame( 0, $this->activity->count_for_post( $post, array( 'action' => 'ai_origin_changed' ) ) );
	}

	/**
	 * Only workflow-enabled post types get the field.
	 *
	 * @return void
	 */
	public function test_field_not_registered_for_unmanaged_types() {
		( new Settings() )->update( array( 'post_types' => array( 'post' ) ) );

		// A real request registers fields once; earlier tests' registrations are not part of it.
		unset( $GLOBALS['wp_rest_additional_fields'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST field registry.
		$this->reset_rest_server();

		$this->assertArrayHasKey( AiOriginField::FIELD, $GLOBALS['wp_rest_additional_fields']['post'] ?? array() );
		$this->assertArrayNotHasKey( AiOriginField::FIELD, $GLOBALS['wp_rest_additional_fields']['page'] ?? array() );
	}

	/**
	 * The container builds the field.
	 *
	 * @return void
	 */
	public function test_container_wires_field() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( AiOriginField::class, $plugin->container()->get( 'rest.ai_origin_field' ) );
	}
}
