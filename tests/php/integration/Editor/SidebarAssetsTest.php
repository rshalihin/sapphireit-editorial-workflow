<?php
/**
 * Integration tests for the block editor sidebar assets.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration\Editor;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Editor\SidebarAssets;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The sidebar bundle loads only for managed posts the user may edit.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Editor\SidebarAssets
 */
final class SidebarAssetsTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var SidebarAssets
	 */
	private $sidebar;

	/**
	 * Resets registries, grants capabilities and builds the service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->reset_dependencies();
		delete_option( Settings::OPTION );
		Capabilities::add_caps();

		$statuses    = new StatusManager();
		$settings    = new Settings();
		$permissions = new PermissionManager( new PostRepository( $statuses, $settings ), $settings );
		$assets      = new Assets(
			$statuses,
			$permissions,
			dirname( __DIR__, 2 ) . '/fixtures/build',
			'http://example.org/build/',
			'/languages'
		);

		$this->sidebar = new SidebarAssets( $assets, $permissions );
	}

	/**
	 * Resets registries and the global post.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->reset_dependencies();
		unset( $GLOBALS['post'] );

		parent::tear_down();
	}

	/**
	 * `register()` hooks the block editor enqueue action.
	 *
	 * @return void
	 */
	public function test_register_hooks_block_editor_assets() {
		$this->sidebar->register();

		$this->assertSame( 10, has_action( 'enqueue_block_editor_assets', array( $this->sidebar, 'enqueue' ) ) );
	}

	/**
	 * A user who can edit a managed post gets the sidebar.
	 *
	 * @return void
	 */
	public function test_enqueues_for_editable_managed_post() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->edit_post( $author, $this->make_post( 'post', $author ) );

		$this->assertTrue( wp_script_is( 'sit-cwm-sidebar-js', 'enqueued' ) );
	}

	/**
	 * No sidebar for users who cannot edit the post.
	 *
	 * @return void
	 */
	public function test_skips_when_user_cannot_edit_post() {
		$post = $this->make_post( 'post', self::factory()->user->create( array( 'role' => 'author' ) ) );

		$this->edit_post( self::factory()->user->create( array( 'role' => 'contributor' ) ), $post );

		$this->assertFalse( wp_script_is( 'sit-cwm-sidebar-js', 'registered' ) );
	}

	/**
	 * No sidebar for post types that are not workflow-enabled.
	 *
	 * @return void
	 */
	public function test_skips_unmanaged_post_type() {
		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->edit_post( $admin, $this->make_post( 'page', $admin ) );

		$this->assertFalse( wp_script_is( 'sit-cwm-sidebar-js', 'registered' ) );
	}

	/**
	 * No sidebar outside a post context (site editor, widgets).
	 *
	 * @return void
	 */
	public function test_skips_without_post() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		unset( $GLOBALS['post'] );

		$this->sidebar->enqueue();

		$this->assertFalse( wp_script_is( 'sit-cwm-sidebar-js', 'registered' ) );
	}

	/**
	 * Runs the enqueue as a user editing a post.
	 *
	 * @param int $user_id User id.
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function edit_post( int $user_id, int $post_id ) {
		wp_set_current_user( $user_id );
		$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the post being edited.

		$this->sidebar->enqueue();
	}

	/**
	 * Creates a draft.
	 *
	 * @param string $post_type Post type.
	 * @param int    $author_id Author user id.
	 * @return int Post id.
	 */
	private function make_post( string $post_type, int $author_id ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => $post_type,
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);
	}

	/**
	 * Removes every plugin script and style (and their inline data).
	 *
	 * @return void
	 */
	private function reset_dependencies() {
		foreach ( Assets::ENTRIES as $entry ) {
			wp_dequeue_script( 'sit-cwm-' . $entry . '-js' );
			wp_deregister_script( 'sit-cwm-' . $entry . '-js' );
			wp_dequeue_style( 'sit-cwm-' . $entry . '-css' );
			wp_deregister_style( 'sit-cwm-' . $entry . '-css' );
		}
	}
}
