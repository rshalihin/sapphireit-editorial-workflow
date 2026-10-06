<?php
/**
 * Integration tests for the admin dashboard screen.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration\Admin;

use Sit_Cwm\Admin\Dashboard;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WP_UnitTestCase;
use WPDieException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu visibility, the screen-only enqueue guard and the page shell.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Admin\Dashboard
 * @covers \Sit_Cwm\Workflow\PermissionManager::can_access_dashboard
 */
final class DashboardTest extends WP_UnitTestCase {

	/**
	 * Hook suffix core derives for the top-level page.
	 *
	 * @var string
	 */
	const HOOK_SUFFIX = 'toplevel_page_sit-cwm-dashboard';

	/**
	 * Service under test.
	 *
	 * @var Dashboard
	 */
	private $dashboard;

	/**
	 * Loads the admin menu API, resets registries and builds the service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->reset_admin_state();
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

		$this->dashboard = new Dashboard( $assets, $permissions );
	}

	/**
	 * Resets registries and menu globals.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->reset_admin_state();

		parent::tear_down();
	}

	/**
	 * `register()` hooks the menu and the enqueue.
	 *
	 * @return void
	 */
	public function test_register_hooks_menu_and_enqueue() {
		$this->dashboard->register();

		$this->assertSame( 10, has_action( 'admin_menu', array( $this->dashboard, 'add_menu' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->dashboard, 'enqueue' ) ) );
	}

	/**
	 * Editors, authors and contributors get the menu, gated on the plugin
	 * capability rather than `manage_options`.
	 *
	 * @return void
	 */
	public function test_menu_registered_for_content_roles() {
		foreach ( array( 'administrator', 'editor', 'author', 'contributor' ) as $role ) {
			$this->reset_admin_state();
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

			$this->dashboard->add_menu();

			$this->assertSame( self::HOOK_SUFFIX, $this->dashboard->hook_suffix(), $role );

			$item = $this->menu_item();

			$this->assertNotNull( $item, $role );
			$this->assertSame( 'Editorial Workflow', $item[0] );
			$this->assertSame( Capabilities::VIEW_ACTIVITY, $item[1] );
			$this->assertSame( 'dashicons-clipboard', $item[6] );
		}
	}

	/**
	 * Subscribers and logged-out users get no menu.
	 *
	 * @return void
	 */
	public function test_menu_not_registered_for_subscribers() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->dashboard->add_menu();

		$this->assertSame( '', $this->dashboard->hook_suffix() );
		$this->assertNull( $this->menu_item() );

		wp_set_current_user( 0 );
		$this->dashboard->add_menu();

		$this->assertNull( $this->menu_item() );
	}

	/**
	 * A user holding the capability but unable to edit any workflow-enabled
	 * post type gets no menu.
	 *
	 * @return void
	 */
	public function test_menu_not_registered_without_editable_post_types() {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		get_userdata( $user )->add_cap( Capabilities::VIEW_ACTIVITY );
		wp_set_current_user( $user );

		$this->dashboard->add_menu();

		$this->assertNull( $this->menu_item() );
	}

	/**
	 * The bundle loads on the dashboard screen and nowhere else.
	 *
	 * @return void
	 */
	public function test_assets_enqueued_on_dashboard_screen_only() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		// Before the menu exists no screen matches, not even the dashboard's.
		$this->dashboard->enqueue( self::HOOK_SUFFIX );
		$this->assertFalse( wp_script_is( 'sit-cwm-dashboard-js', 'registered' ) );

		$this->dashboard->add_menu();

		foreach ( array( 'index.php', 'edit.php', 'post.php', 'toplevel_page_other', '' ) as $other ) {
			$this->dashboard->enqueue( $other );
			$this->assertFalse( wp_script_is( 'sit-cwm-dashboard-js', 'registered' ), $other );
		}

		$this->dashboard->enqueue( self::HOOK_SUFFIX );

		$this->assertTrue( wp_script_is( 'sit-cwm-dashboard-js', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'sit-cwm-sidebar-js', 'registered' ) );
	}

	/**
	 * The page is a static shell: a screen-reader heading and the React root.
	 *
	 * @return void
	 */
	public function test_render_prints_static_shell() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		ob_start();
		$this->dashboard->render();
		$html = ob_get_clean();

		$this->assertSame(
			'<div class="wrap sit-cwm-dashboard-wrap"><h1 class="screen-reader-text">Editorial Workflow</h1><div id="sit-cwm-dashboard"></div></div>',
			$html
		);
	}

	/**
	 * Rendering re-checks access.
	 *
	 * @return void
	 */
	public function test_render_denies_users_without_access() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( WPDieException::class );

		$this->dashboard->render();
	}

	/**
	 * The container builds the service for the admin phase.
	 *
	 * @return void
	 */
	public function test_container_wires_dashboard() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( Dashboard::class, $plugin->container()->get( 'admin.dashboard' ) );
		$this->assertContains( 'admin.dashboard', Plugin::BOOT_PHASES['admin'] );
	}

	/**
	 * The registered top-level menu entry for the dashboard slug.
	 *
	 * @return array|null
	 */
	private function menu_item(): ?array {
		global $menu;

		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && Dashboard::MENU_SLUG === $item[2] ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Removes plugin scripts/styles and clears the admin menu globals.
	 *
	 * @return void
	 */
	private function reset_admin_state() {
		global $menu, $admin_page_hooks, $_registered_pages;

		$menu              = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$admin_page_hooks  = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$_registered_pages = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.

		foreach ( Assets::ENTRIES as $entry ) {
			wp_dequeue_script( 'sit-cwm-' . $entry . '-js' );
			wp_deregister_script( 'sit-cwm-' . $entry . '-js' );
			wp_dequeue_style( 'sit-cwm-' . $entry . '-css' );
			wp_deregister_style( 'sit-cwm-' . $entry . '-css' );
		}
	}
}
