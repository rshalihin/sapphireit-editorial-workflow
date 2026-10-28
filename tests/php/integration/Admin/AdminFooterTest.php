<?php
/**
 * Integration tests for the plugin screens' admin footer.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Admin;

use Sit_Cwm\Admin\AdminFooter;
use Sit_Cwm\Admin\Dashboard;
use Sit_Cwm\Admin\Settings as SettingsPage;
use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The dashboard and settings screens' footer asks for a review and shows the
 * plugin's version; every other admin screen keeps core's footer.
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Admin\AdminFooter
 */
final class AdminFooterTest extends TestCase {

	/**
	 * Dashboard screen.
	 *
	 * @var Dashboard
	 */
	private $dashboard;

	/**
	 * Settings screen.
	 *
	 * @var SettingsPage
	 */
	private $page;

	/**
	 * Service under test.
	 *
	 * @var AdminFooter
	 */
	private $footer;

	/**
	 * Loads the admin APIs, registers both menus as an administrator and builds the service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		$this->reset_admin_state();

		$statuses    = new StatusManager();
		$settings    = new Settings();
		$permissions = new PermissionManager( $this->posts, $settings );
		$assets      = new Assets(
			$statuses,
			$permissions,
			dirname( __DIR__, 2 ) . '/fixtures/build',
			'http://example.org/build/',
			'/languages'
		);

		$this->dashboard = new Dashboard( $assets, $permissions );
		$this->page      = new SettingsPage( $settings, $permissions );
		$this->footer    = new AdminFooter( $this->dashboard, $this->page );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->dashboard->add_menu();
		$this->page->add_menu();
	}

	/**
	 * Restores global admin state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->reset_admin_state();

		parent::tear_down();
	}

	/**
	 * Both filters are hooked, the version one after core's `core_update_footer`.
	 *
	 * @return void
	 */
	public function test_register_adds_both_filters() {
		$this->footer->register();

		$this->assertSame( 10, has_filter( 'admin_footer_text', array( $this->footer, 'filter_footer_text' ) ) );
		$this->assertSame( AdminFooter::VERSION_PRIORITY, has_filter( 'update_footer', array( $this->footer, 'filter_version' ) ) );
		$this->assertGreaterThan( has_filter( 'update_footer', 'core_update_footer' ), AdminFooter::VERSION_PRIORITY );

		remove_filter( 'admin_footer_text', array( $this->footer, 'filter_footer_text' ) );
		remove_filter( 'update_footer', array( $this->footer, 'filter_version' ), AdminFooter::VERSION_PRIORITY );
	}

	/**
	 * On both plugin screens the footer names the plugin, links to its review
	 * form and shows the plugin's version.
	 *
	 * @return void
	 */
	public function test_plugin_screens_ask_for_a_review_and_show_the_version() {
		foreach ( array( $this->dashboard->hook_suffix(), $this->page->hook_suffix() ) as $screen ) {
			$this->assertNotSame( '', $screen );
			set_current_screen( $screen );

			$text = $this->footer->filter_footer_text( 'core text' );

			$this->assertStringContainsString( '<strong>SapphireIT Editorial Workflow</strong>', $text, $screen );
			$this->assertStringContainsString( 'href="' . esc_url( AdminFooter::REVIEW_URL ) . '"', $text, $screen );
			$this->assertStringContainsString( 'class="sit-cwm-rating-link"', $text, $screen );
			$this->assertStringContainsString( 'rel="noopener noreferrer"', $text, $screen );
			$this->assertStringContainsString( '>review<span class="screen-reader-text"> (opens in a new tab)</span></a>', $text, $screen );
			$this->assertStringNotContainsString( 'core text', $text, $screen );

			$this->assertSame( 'Version ' . SIT_CWM_VERSION, $this->footer->filter_version( 'Version 6.8' ), $screen );
		}
	}

	/**
	 * The review link is neutral: no rating is preselected or asked for.
	 *
	 * @return void
	 */
	public function test_the_review_request_does_not_ask_for_a_rating() {
		set_current_screen( $this->dashboard->hook_suffix() );

		$text = $this->footer->filter_footer_text( 'core text' );

		$this->assertStringNotContainsString( 'rate=', AdminFooter::REVIEW_URL );
		$this->assertStringNotContainsString( '&#9733;', $text );
		$this->assertStringNotContainsString( 'five star', strtolower( $text ) );
	}

	/**
	 * Any other screen, or no screen at all, keeps whatever it was given.
	 *
	 * @return void
	 */
	public function test_other_screens_keep_the_core_footer() {
		$this->assertSame( 'core text', $this->footer->filter_footer_text( 'core text' ) );
		$this->assertSame( 'Version 6.8', $this->footer->filter_version( 'Version 6.8' ) );

		set_current_screen( 'edit-post' );

		$this->assertSame( 'core text', $this->footer->filter_footer_text( 'core text' ) );
		$this->assertSame( 'Version 6.8', $this->footer->filter_version( 'Version 6.8' ) );
	}

	/**
	 * For a user who got neither menu, no screen matches an empty hook suffix.
	 *
	 * @return void
	 */
	public function test_unregistered_menus_match_no_screen() {
		$this->reset_admin_state();

		$settings    = new Settings();
		$permissions = new PermissionManager( $this->posts, $settings );
		$dashboard   = new Dashboard( new Assets( new StatusManager(), $permissions, '', '', '' ), $permissions );
		$page        = new SettingsPage( $settings, $permissions );
		$footer      = new AdminFooter( $dashboard, $page );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$dashboard->add_menu();
		$page->add_menu();

		$this->assertSame( '', $dashboard->hook_suffix() );
		$this->assertSame( '', $page->hook_suffix() );

		set_current_screen( 'edit-post' );

		$this->assertSame( 'core text', $footer->filter_footer_text( 'core text' ) );
		$this->assertSame( 'Version 6.8', $footer->filter_version( 'Version 6.8' ) );
	}

	/**
	 * The container builds the service for the admin phase.
	 *
	 * @return void
	 */
	public function test_container_wires_footer() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( AdminFooter::class, $plugin->container()->get( 'admin.footer' ) );
		$this->assertContains( 'admin.footer', Plugin::BOOT_PHASES['admin'] );
	}

	/**
	 * Resets menu globals and the current screen.
	 *
	 * @return void
	 */
	private function reset_admin_state() {
		global $menu, $submenu, $admin_page_hooks, $_registered_pages, $current_screen;

		$menu              = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$submenu           = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$admin_page_hooks  = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$_registered_pages = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$current_screen    = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates the current screen between tests.
	}
}
