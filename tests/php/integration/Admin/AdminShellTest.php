<?php
/**
 * Integration tests for the shared admin header and tabs.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Admin;

use Sit_Cwm\Admin\ActivityExport;
use Sit_Cwm\Admin\AdminShell;
use Sit_Cwm\Admin\Dashboard;
use Sit_Cwm\Admin\GettingStartedPage;
use Sit_Cwm\Admin\GovernanceReportPage;
use Sit_Cwm\Admin\Settings as SettingsPage;
use Sit_Cwm\Audit\ActivityExporter;
use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\REST\ActivityFormatter;
use Sit_Cwm\REST\UserSummaries;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Tests\Traits\RestoresPluginInstance;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The header prints on plugin screens only, with one tab per page the user can open.
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Admin\AdminShell
 */
final class AdminShellTest extends TestCase {

	use CreatesWorkflowPosts;
	use RestoresPluginInstance;

	/**
	 * Service under test.
	 *
	 * @var AdminShell
	 */
	private $shell;

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
	private $settings_page;

	/**
	 * Getting Started screen.
	 *
	 * @var GettingStartedPage
	 */
	private $getting_started;

	/**
	 * Builds the shell and the three pages.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->remember_plugin_instance();
		$this->reset_admin_state();

		$statuses    = new StatusManager();
		$settings    = new Settings();
		$permissions = new PermissionManager( $this->posts, $settings );
		$assets      = new Assets( $statuses, $permissions, dirname( __DIR__, 2 ) . '/fixtures/build', 'http://example.org/build/', '/languages' );
		$export      = new ActivityExport( $permissions, new ActivityExporter( $this->activity, new ActivityFormatter( $statuses ), new UserSummaries() ) );

		$this->shell           = new AdminShell( $assets );
		$this->dashboard       = new Dashboard( $assets, $permissions, $export, $this->shell );
		$this->settings_page   = new SettingsPage( $settings, $permissions, $this->shell );
		$this->getting_started = new GettingStartedPage( $this->shell, $permissions, $settings, $statuses );
	}

	/**
	 * Restores global admin state.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->reset_admin_state();
		$this->restore_plugin_instance();

		parent::tear_down();
	}

	/**
	 * `register()` hooks the body class and the stylesheet.
	 *
	 * @return void
	 */
	public function test_register_hooks_body_class_and_enqueue() {
		$this->shell->register();

		$this->assertSame( 10, has_filter( 'admin_body_class', array( $this->shell, 'filter_body_class' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->shell, 'enqueue' ) ) );
	}

	/**
	 * Editors get the Dashboard and Getting Started tabs, not Settings.
	 *
	 * @return void
	 */
	public function test_header_lists_only_tabs_the_user_can_open() {
		wp_set_current_user( self::fixture_user( 'editor' ) );
		$html = $this->header_on( Dashboard::MENU_SLUG );

		$this->assertStringContainsString( 'page=sit-cwm-dashboard', $html );
		$this->assertStringContainsString( 'page=sit-cwm-getting-started', $html );
		$this->assertStringNotContainsString( 'page=sit-cwm-settings', $html );
	}

	/**
	 * Managers get every tab, in `TAB_SLUGS` order, labelled with the menu titles.
	 *
	 * @return void
	 */
	public function test_tabs_follow_tab_slugs_order() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$html = $this->header_on( Dashboard::MENU_SLUG );

		$dashboard = strpos( $html, 'page=sit-cwm-dashboard"' );
		$settings  = strpos( $html, 'page=sit-cwm-settings"' );
		$started   = strpos( $html, 'page=sit-cwm-getting-started"' );

		$this->assertNotFalse( $dashboard );
		$this->assertNotFalse( $settings );
		$this->assertNotFalse( $started );
		$this->assertLessThan( $settings, $dashboard );
		$this->assertLessThan( $started, $settings );
		$this->assertStringContainsString( '<nav class="sit-cwm-shell__tabs" aria-label="Editorial Workflow">', $html );
		$this->assertStringContainsString( '>Getting Started</a>', $html );
		$this->assertSame( array( Dashboard::MENU_SLUG, GovernanceReportPage::MENU_SLUG, SettingsPage::MENU_SLUG, GettingStartedPage::MENU_SLUG ), AdminShell::TAB_SLUGS );
	}

	/**
	 * The current page's tab carries `aria-current="page"`, and only it.
	 *
	 * @return void
	 */
	public function test_current_tab_is_marked() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$html = $this->header_on( SettingsPage::MENU_SLUG );

		$this->assertMatchesRegularExpression( '/page=sit-cwm-settings"[^>]*aria-current="page"/', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
	}

	/**
	 * Any other page gets no header.
	 *
	 * @return void
	 */
	public function test_header_prints_nothing_off_plugin_screens() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$this->assertSame( '', $this->header_on( 'some-other-page' ) );
		$this->assertSame( '', $this->header_on( 'sit-cwm-settings-account' ) );
	}

	/**
	 * `is_plugin_screen()` follows `$plugin_page` and the allow-list.
	 *
	 * @return void
	 */
	public function test_is_plugin_screen_uses_the_allow_list() {
		$GLOBALS['plugin_page'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates a core screen.
		$this->assertFalse( $this->shell->is_plugin_screen() );

		foreach ( AdminShell::TAB_SLUGS as $slug ) {
			$GLOBALS['plugin_page'] = $slug; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the plugin screen.
			$this->assertTrue( $this->shell->is_plugin_screen(), $slug );
		}

		$GLOBALS['plugin_page'] = 'sit-cwm-settings-account'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the Freemius account page.
		$this->assertFalse( $this->shell->is_plugin_screen() );
	}

	/**
	 * The body class is added on plugin screens only.
	 *
	 * @return void
	 */
	public function test_body_class_only_on_plugin_screens() {
		$GLOBALS['plugin_page'] = Dashboard::MENU_SLUG; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the plugin screen.
		$this->assertStringContainsString( ' sit-cwm-admin', $this->shell->filter_body_class( 'folded' ) );
		$this->assertStringStartsWith( 'folded', $this->shell->filter_body_class( 'folded' ) );

		$GLOBALS['plugin_page'] = 'some-other-page'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates another screen.
		$this->assertSame( 'folded', $this->shell->filter_body_class( 'folded' ) );
	}

	/**
	 * The stylesheet loads on plugin screens only.
	 *
	 * @return void
	 */
	public function test_enqueue_only_on_plugin_screens() {
		$GLOBALS['plugin_page'] = 'some-other-page'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates another screen.
		$this->shell->enqueue();
		$this->assertFalse( wp_script_is( 'sit-cwm-admin-shell-js', 'registered' ) );

		$GLOBALS['plugin_page'] = GettingStartedPage::MENU_SLUG; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the plugin screen.
		$this->shell->enqueue();
		$this->assertTrue( wp_script_is( 'sit-cwm-admin-shell-js', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'sit-cwm-admin-shell-css', 'enqueued' ) );
	}

	/**
	 * The header shows the logo, the plugin name and the version chip.
	 *
	 * @return void
	 */
	public function test_header_shows_the_version_chip() {
		wp_set_current_user( self::fixture_user( 'editor' ) );
		$html = $this->header_on( Dashboard::MENU_SLUG );

		$this->assertStringContainsString( '<span class="sit-cwm-shell__version">' . esc_html( SIT_CWM_VERSION ) . '</span>', $html );
		$this->assertStringContainsString( 'src="' . esc_url( SIT_CWM_PLUGIN_URL . 'assets/images/logo.svg' ) . '" width="32" height="32" alt=""', $html );
		$this->assertStringContainsString( 'Editorial Workflow', $html );
		$this->assertStringContainsString( '<div class="sit-cwm-shell__aside"></div>', $html );
	}

	/**
	 * The title row escapes its text, omits empty parts and ends with the
	 * marker core moves notices below.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_page_head_builds_the_title_row() {
		$this->assertSame(
			'<div class="sit-cwm-page-head"><div class="sit-cwm-page-title"><h1 class="wp-heading-inline">A &amp; B</h1></div></div><hr class="wp-header-end">',
			$this->shell->page_head( 'A & B' )
		);
		$this->assertSame(
			'<div class="sit-cwm-page-head"><div class="sit-cwm-page-title"><h1 class="wp-heading-inline">Title</h1><p class="sit-cwm-page-subtitle">&lt;b&gt;Sub&lt;/b&gt;</p></div><div class="sit-cwm-page-actions"><a href="#">Act</a></div></div><hr class="wp-header-end">',
			$this->shell->page_head( 'Title', '<b>Sub</b>', '<a href="#">Act</a>' )
		);
	}

	/**
	 * The shipped logo is a copy of the listing icon.
	 *
	 * @return void
	 */
	public function test_logo_ships_with_the_plugin() {
		$this->assertFileExists( SIT_CWM_PLUGIN_DIR . 'assets/images/logo.svg' );
	}

	/**
	 * The container builds the shell and boots it before the pages.
	 *
	 * @return void
	 */
	public function test_container_wires_shell_first() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( AdminShell::class, $plugin->container()->get( 'admin.shell' ) );

		$admin = Plugin::BOOT_PHASES['admin'];

		$this->assertContains( 'admin.shell', $admin );
		$this->assertLessThan( array_search( 'admin.dashboard', $admin, true ), array_search( 'admin.shell', $admin, true ) );
	}

	/**
	 * Registers every page's menu as the current user and buffers the header
	 * for a given `$plugin_page`.
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	private function header_on( string $slug ): string {
		$this->reset_admin_state();

		$this->dashboard->add_menu();
		$this->settings_page->add_menu();
		$this->getting_started->add_menu();

		$GLOBALS['plugin_page'] = $slug; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the requested page.

		ob_start();
		$this->shell->render_header();

		return (string) ob_get_clean();
	}

	/**
	 * Resets menu globals, the current screen and `$plugin_page`.
	 *
	 * @return void
	 */
	private function reset_admin_state() {
		global $menu, $submenu, $admin_page_hooks, $_registered_pages, $_parent_pages, $current_screen, $plugin_page;

		$menu              = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$submenu           = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$admin_page_hooks  = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$_registered_pages = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$_parent_pages     = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$current_screen    = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates the current screen between tests.
		$plugin_page       = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates the requested page between tests.
	}
}
