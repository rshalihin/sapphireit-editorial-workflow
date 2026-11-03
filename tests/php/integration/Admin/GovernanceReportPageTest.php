<?php
/**
 * Integration tests for the governance report screen.
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
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WPDieException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu placement and access, the page markup and the screen-only enqueue.
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Admin\GovernanceReportPage
 */
final class GovernanceReportPageTest extends TestCase {

	use CreatesWorkflowPosts;
	use RestoresPluginInstance;

	/**
	 * Hook suffix core derives for the submenu page.
	 *
	 * @var string
	 */
	const HOOK_SUFFIX = 'editorial-workflow_page_sit-cwm-report';

	/**
	 * Shared header.
	 *
	 * @var AdminShell
	 */
	private $shell;

	/**
	 * Dashboard page (the menu parent).
	 *
	 * @var Dashboard
	 */
	private $dashboard;

	/**
	 * Settings page.
	 *
	 * @var SettingsPage
	 */
	private $settings_page;

	/**
	 * Service under test.
	 *
	 * @var GovernanceReportPage
	 */
	private $report;

	/**
	 * Builds the pages.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->remember_plugin_instance();
		$this->reset_admin_state();
		Capabilities::add_caps();

		$statuses    = new StatusManager();
		$settings    = new Settings();
		$permissions = new PermissionManager( $this->posts, $settings );
		$assets      = new Assets( $statuses, $permissions, dirname( __DIR__, 2 ) . '/fixtures/build', 'http://example.org/build/', '/languages' );
		$export      = new ActivityExport( $permissions, new ActivityExporter( $this->activity, new ActivityFormatter( $statuses ), new UserSummaries() ) );

		$this->shell         = new AdminShell( $assets );
		$this->dashboard     = new Dashboard( $assets, $permissions, $export, $this->shell );
		$this->settings_page = new SettingsPage( $settings, $permissions, $this->shell );
		$this->report        = new GovernanceReportPage( $assets, $permissions, $export, $this->shell );
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
	 * `register()` hooks the menu between the dashboard (10) and settings
	 * (11), and the enqueue.
	 *
	 * @return void
	 */
	public function test_register_hooks_menu_and_enqueue() {
		$this->report->register();

		$this->assertSame( GovernanceReportPage::MENU_PRIORITY, has_action( 'admin_menu', array( $this->report, 'add_menu' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( $this->report, 'enqueue' ) ) );
	}

	/**
	 * Auditors get the submenu item, gated on the manage capability.
	 *
	 * @return void
	 */
	public function test_menu_registered_under_workflow_for_auditors_only() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->register_menus();

		$this->assertNotEmpty( menu_page_url( GovernanceReportPage::MENU_SLUG, false ) );

		$item = $this->submenu_item( GovernanceReportPage::MENU_SLUG );

		$this->assertNotNull( $item );
		$this->assertSame( 'Governance report', $item[0] );
		$this->assertSame( Capabilities::MANAGE_WORKFLOWS, $item[1] );
		$this->assertSame( self::HOOK_SUFFIX, $this->report->hook_suffix() );
	}

	/**
	 * Non-auditors get no menu item.
	 *
	 * @return void
	 */
	public function test_menu_not_registered_for_non_auditors() {
		foreach ( array( 'editor', 'author', 'subscriber' ) as $role ) {
			$this->reset_admin_state();
			wp_set_current_user( self::fixture_user( $role ) );
			$this->register_menus();

			$this->assertNull( $this->submenu_item( GovernanceReportPage::MENU_SLUG ), $role );
			$this->assertSame( '', $this->report->hook_suffix(), $role );
		}
	}

	/**
	 * The item sits right after the dashboard, before settings.
	 *
	 * @return void
	 */
	public function test_menu_sits_between_dashboard_and_settings() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->register_menus();

		global $submenu;

		$slugs = array_values( wp_list_pluck( $submenu[ Dashboard::MENU_SLUG ], 2 ) );

		$this->assertSame( array( Dashboard::MENU_SLUG, GovernanceReportPage::MENU_SLUG, SettingsPage::MENU_SLUG ), array_slice( $slugs, 0, 3 ) );
	}

	/**
	 * The report is a plugin screen, its tab between Dashboard and Settings.
	 *
	 * @return void
	 */
	public function test_tab_follows_the_dashboard() {
		$this->assertSame(
			array( Dashboard::MENU_SLUG, GovernanceReportPage::MENU_SLUG, SettingsPage::MENU_SLUG, GettingStartedPage::MENU_SLUG ),
			AdminShell::TAB_SLUGS
		);
	}

	/**
	 * Rendering re-checks access.
	 *
	 * @return void
	 */
	public function test_render_dies_for_non_auditors() {
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$this->expectException( WPDieException::class );

		$this->report->render();
	}

	/**
	 * The page prints the shared header with its tab current, the heading,
	 * the export form and the React root.
	 *
	 * @return void
	 */
	public function test_render_prints_title_export_form_and_root() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->register_menus();
		$GLOBALS['plugin_page'] = GovernanceReportPage::MENU_SLUG; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the requested page.

		ob_start();
		$this->report->render();
		$html = (string) ob_get_clean();

		$this->assertStringStartsWith( '<div class="wrap sit-cwm-report-wrap"><div class="sit-cwm-shell">', $html );
		$this->assertStringContainsString( '<h1 class="wp-heading-inline">Governance report</h1>', $html );
		$this->assertStringContainsString( 'value="sit_cwm_export_activity"', $html );
		$this->assertStringContainsString( '<div id="sit-cwm-report"></div>', $html );
		$this->assertStringContainsString( '<p class="sit-cwm-page-subtitle">How content reached publication, from the activity log.</p>', $html );
		$this->assertStringContainsString( '<div class="sit-cwm-page-actions"><div id="sit-cwm-report-period" class="sit-cwm-report-period"></div><form method="post"', $html );
		$this->assertMatchesRegularExpression( '/page=sit-cwm-report"[^>]*aria-current="page"/', $html );
		$this->assertLessThan( strpos( $html, '<hr class="wp-header-end">' ), strpos( $html, '<form method="post"' ) );
		$this->assertLessThan( strpos( $html, 'id="sit-cwm-report"' ), strpos( $html, '<hr class="wp-header-end">' ) );
	}

	/**
	 * The bundle loads on the report screen and nowhere else.
	 *
	 * @return void
	 */
	public function test_enqueue_only_on_its_own_screen() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		// Before the menu exists no screen matches.
		$this->report->enqueue( self::HOOK_SUFFIX );
		$this->assertFalse( wp_script_is( 'sit-cwm-report-js', 'registered' ) );

		$this->register_menus();

		foreach ( array( 'index.php', 'edit.php', 'toplevel_page_sit-cwm-dashboard', '' ) as $other ) {
			$this->report->enqueue( $other );
			$this->assertFalse( wp_script_is( 'sit-cwm-report-js', 'registered' ), $other );
		}

		$this->report->enqueue( self::HOOK_SUFFIX );

		$this->assertTrue( wp_script_is( 'sit-cwm-report-js', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'sit-cwm-dashboard-js', 'registered' ) );
	}

	/**
	 * The container builds the page for the admin phase, after the shell.
	 *
	 * @return void
	 */
	public function test_container_wires_report() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( GovernanceReportPage::class, $plugin->container()->get( 'admin.report' ) );

		$admin = Plugin::BOOT_PHASES['admin'];

		$this->assertContains( 'admin.report', $admin );
		$this->assertLessThan( array_search( 'admin.report', $admin, true ), array_search( 'admin.shell', $admin, true ) );
		$this->assertContains( GovernanceReportPage::ENTRY, Assets::ENTRIES );
	}

	/**
	 * Registers the menus in `admin_menu` priority order.
	 *
	 * @return void
	 */
	private function register_menus() {
		$this->dashboard->add_menu();
		$this->report->add_menu();
		$this->settings_page->add_menu();
	}

	/**
	 * A registered Workflow submenu entry.
	 *
	 * @param string $slug Page slug.
	 * @return array|null
	 */
	private function submenu_item( string $slug ): ?array {
		global $submenu;

		foreach ( (array) ( $submenu[ Dashboard::MENU_SLUG ] ?? array() ) as $item ) {
			if ( isset( $item[2] ) && $slug === $item[2] ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Resets menu globals, plugin scripts and `$plugin_page`.
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

		foreach ( Assets::ENTRIES as $entry ) {
			wp_dequeue_script( 'sit-cwm-' . $entry . '-js' );
			wp_deregister_script( 'sit-cwm-' . $entry . '-js' );
			wp_dequeue_style( 'sit-cwm-' . $entry . '-css' );
			wp_deregister_style( 'sit-cwm-' . $entry . '-css' );
		}
	}
}
