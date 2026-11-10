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
	 * Editors get the Dashboard tab and the Help link, not Settings.
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
	 * Managers get every tab, in `TAB_SLUGS` order, labelled with the menu
	 * titles. Getting Started is no tab: it is the Help link in the aside.
	 *
	 * @return void
	 */
	public function test_tabs_follow_tab_slugs_order() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$html = $this->header_on( Dashboard::MENU_SLUG );

		$dashboard = strpos( $html, 'page=sit-cwm-dashboard"' );
		$settings  = strpos( $html, 'page=sit-cwm-settings"' );

		$this->assertNotFalse( $dashboard );
		$this->assertNotFalse( $settings );
		$this->assertLessThan( $settings, $dashboard );
		$this->assertStringContainsString( '<nav class="sit-cwm-shell__tabs" aria-label="Editorial Workflow">', $html );
		$this->assertSame( array( Dashboard::MENU_SLUG, GovernanceReportPage::MENU_SLUG, SettingsPage::MENU_SLUG ), AdminShell::TAB_SLUGS );
		$this->assertSame( array( Dashboard::MENU_SLUG, SettingsPage::MENU_SLUG ), array_keys( $this->shell->tabs() ) );
		$this->assertStringNotContainsString( '>Getting Started</a>', $html );
	}

	/**
	 * The Help link opens Getting Started, sits in the aside after the tabs,
	 * and is marked current on its own page.
	 *
	 * @return void
	 */
	public function test_help_link_opens_getting_started() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$html = $this->header_on( Dashboard::MENU_SLUG );

		$this->assertMatchesRegularExpression( '#<div class="sit-cwm-shell__aside"><a class="sit-cwm-shell__help" href="[^"]*page=sit-cwm-getting-started">Help</a>#', $html );
		$this->assertGreaterThan( strpos( $html, '</nav>' ), strpos( $html, 'sit-cwm-shell__help' ) );

		$html = $this->header_on( GettingStartedPage::MENU_SLUG );

		$this->assertMatchesRegularExpression( '#<a class="sit-cwm-shell__help is-current" href="[^"]*page=sit-cwm-getting-started" aria-current="page">Help</a>#', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		$this->assertSame( AdminShell::HELP_SLUG, GettingStartedPage::MENU_SLUG );
		$this->assertContains( AdminShell::HELP_SLUG, AdminShell::SCREEN_SLUGS );
	}

	/**
	 * Without the Getting Started page registered, there is no Help link.
	 *
	 * @return void
	 */
	public function test_no_help_link_without_the_page() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->reset_admin_state();
		$this->dashboard->add_menu();
		$GLOBALS['plugin_page'] = Dashboard::MENU_SLUG; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the requested page.

		$this->assertSame( '', $this->shell->help_link() );
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

		foreach ( AdminShell::SCREEN_SLUGS as $slug ) {
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
		$this->assertMatchesRegularExpression( '#<div class="sit-cwm-shell__aside"><a class="sit-cwm-shell__help"[^>]*>Help</a></div>#', $html );
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
	 * The API revision tells add-ons which seams this build offers.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_api_revision_constant_is_two() {
		$this->assertTrue( defined( 'SIT_CWM_API_REVISION' ) );
		$this->assertSame( 2, SIT_CWM_API_REVISION );
	}

	/**
	 * An add-on's tab sits after the core tabs.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filtered_tab_appears_after_core_tabs() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->register_menus();
		$this->register_submenu( 'sit-cwm-pro-workflows', 'sit_cwm_manage_workflows' );
		add_filter( 'sit_cwm_admin_tabs', array( $this, 'add_workflows_tab' ) );

		$slugs = array_keys( $this->shell->tabs() );

		$this->assertSame( 'sit-cwm-pro-workflows', end( $slugs ) );
		$this->assertSame( array_search( 'sit-cwm-settings', $slugs, true ) + 1, array_search( 'sit-cwm-pro-workflows', $slugs, true ) );
		$this->assertSame( 'Workflows', $this->shell->tabs()['sit-cwm-pro-workflows'] );
	}

	/**
	 * A slug nobody registered under the Workflow menu gets no tab.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filtered_tab_hidden_when_not_registered() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->register_menus();
		add_filter( 'sit_cwm_admin_tabs', array( $this, 'add_workflows_tab' ) );

		$this->assertArrayNotHasKey( 'sit-cwm-pro-workflows', $this->shell->tabs() );
	}

	/**
	 * A registered page stays hidden from users without its capability.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filtered_tab_hidden_without_the_capability() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->register_menus();
		$this->register_submenu( 'sit-cwm-pro-workflows', 'sit_cwm_manage_workflows' );
		add_filter( 'sit_cwm_admin_tabs', array( $this, 'add_workflows_tab' ) );

		wp_set_current_user( self::fixture_user( 'editor' ) );

		$this->assertArrayNotHasKey( 'sit-cwm-pro-workflows', $this->shell->tabs() );
	}

	/**
	 * The filter cannot drop or rename a core tab.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_core_tabs_cannot_be_removed_or_relabelled() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->register_menus();
		add_filter(
			'sit_cwm_admin_tabs',
			static function () {
				return array( 'sit-cwm-dashboard' => 'Hacked' );
			}
		);

		$tabs = $this->shell->tabs();

		$this->assertNotSame( 'Hacked', $tabs['sit-cwm-dashboard'] );
		$this->assertArrayHasKey( 'sit-cwm-settings', $tabs );
		$this->assertSame( array( 'sit-cwm-dashboard', 'sit-cwm-settings' ), array_keys( $tabs ) );
	}

	/**
	 * An add-on's slug gets the body class, so its screen gets the tokens.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_filtered_slug_counts_as_plugin_screen() {
		add_filter( 'sit_cwm_admin_tabs', array( $this, 'add_workflows_tab' ) );
		$GLOBALS['plugin_page'] = 'sit-cwm-pro-workflows'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the add-on's screen.

		$this->assertTrue( $this->shell->is_plugin_screen() );
		$this->assertStringContainsString( 'sit-cwm-admin', $this->shell->filter_body_class( '' ) );
	}

	/**
	 * Slugs outside the plugin's prefix, and labels that are not text, are dropped.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_invalid_filtered_tabs_are_dropped() {
		add_filter(
			'sit_cwm_admin_tabs',
			static function () {
				return array(
					'other-plugin-page' => 'Other',
					'sit-cwm-Upper'     => 'Upper',
					'sit-cwm-empty'     => '   ',
					'sit-cwm-array'     => array( 'x' ),
					7                   => 'Numeric',
				);
			}
		);

		foreach ( array( 'other-plugin-page', 'sit-cwm-Upper', 'sit-cwm-empty', 'sit-cwm-array' ) as $slug ) {
			$GLOBALS['plugin_page'] = $slug; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the requested page.
			$this->assertFalse( $this->shell->is_plugin_screen(), $slug );
		}
	}

	/**
	 * A filter that returns something other than an array changes nothing.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_non_array_filter_result_is_ignored() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->register_menus();
		add_filter( 'sit_cwm_admin_tabs', '__return_null' );

		$this->assertArrayHasKey( 'sit-cwm-dashboard', $this->shell->tabs() );
	}

	/**
	 * The add-on tab prints in the header, marked current on its own screen.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_header_prints_filtered_tab_on_its_own_screen() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->register_menus();
		$this->register_submenu( 'sit-cwm-pro-workflows', 'sit_cwm_manage_workflows' );
		add_filter( 'sit_cwm_admin_tabs', array( $this, 'add_workflows_tab' ) );
		$GLOBALS['plugin_page'] = 'sit-cwm-pro-workflows'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the add-on's screen.

		ob_start();
		$this->shell->render_header();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/page=sit-cwm-pro-workflows"[^>]*aria-current="page">Workflows</', $html );
	}

	/**
	 * Filter callback: an add-on's tab.
	 *
	 * @since 1.2.0
	 *
	 * @param array<string, string> $tabs Tabs so far.
	 * @return array<string, string>
	 */
	public function add_workflows_tab( $tabs ) {
		$tabs['sit-cwm-pro-workflows'] = 'Workflows';

		return $tabs;
	}

	/**
	 * Registers the core pages as the current user.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function register_menus() {
		$this->reset_admin_state();

		$this->dashboard->add_menu();
		$this->settings_page->add_menu();
		$this->getting_started->add_menu();
	}

	/**
	 * Registers a page under the Workflow menu, as an add-on would.
	 *
	 * @since 1.2.0
	 *
	 * @param string $slug       Page slug.
	 * @param string $capability Capability the page needs.
	 * @return void
	 */
	private function register_submenu( string $slug, string $capability ) {
		add_submenu_page( Dashboard::MENU_SLUG, 'Workflows', 'Workflows', $capability, $slug, '__return_null' );
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
