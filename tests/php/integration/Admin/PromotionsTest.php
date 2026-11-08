<?php
/**
 * Integration tests for the promotion of Pro inside Free (phase 49).
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Admin;

use ReflectionProperty;
use Sit_Cwm\Admin\ActivityExport;
use Sit_Cwm\Admin\AdminShell;
use Sit_Cwm\Admin\Dashboard;
use Sit_Cwm\Admin\GettingStartedPage;
use Sit_Cwm\Admin\GovernanceReportPage;
use Sit_Cwm\Admin\PluginActionLinks;
use Sit_Cwm\Admin\Promotions;
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
use WPDieException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stage is derived; every promotion is manager-only, plugin-screen-only,
 * dismissible and absent from the editor, site-wide notices and the front end.
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Admin\Promotions
 */
final class PromotionsTest extends TestCase {

	use CreatesWorkflowPosts;
	use RestoresPluginInstance;

	/**
	 * Service under test; its redirect is recorded instead of sent.
	 *
	 * @var Promotions
	 */
	private $promotions;

	/**
	 * Shell wired with the service.
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
	 * Governance report screen.
	 *
	 * @var GovernanceReportPage
	 */
	private $report;

	/**
	 * Getting Started screen.
	 *
	 * @var GettingStartedPage
	 */
	private $getting_started;

	/**
	 * Workflow authorization.
	 *
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * The SDK's `has_addons` flag before the test.
	 *
	 * @var bool
	 */
	private $had_addons;

	/**
	 * Builds the service and the screens that print it.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$this->remember_plugin_instance();
		$this->reset_admin_state();

		$this->had_addons = sit_cwm_fs()->has_addons();

		$statuses          = new StatusManager();
		$settings          = new Settings();
		$this->permissions = new PermissionManager( $this->posts, $settings );
		$assets            = new Assets( $statuses, $this->permissions, dirname( __DIR__, 2 ) . '/fixtures/build', 'http://example.org/build/', '/languages' );
		$export            = new ActivityExport( $this->permissions, new ActivityExporter( $this->activity, new ActivityFormatter( $statuses ), new UserSummaries() ) );

		$this->promotions = new class( $this->permissions ) extends Promotions {

			/**
			 * Where `handle_dismiss()` sent the user.
			 *
			 * @var string[]
			 */
			public $redirects = array();

			/**
			 * Records the redirect instead of sending it.
			 *
			 * @param string $url Local URL.
			 * @return void
			 */
			protected function redirect( string $url ): void {
				$this->redirects[] = $url;
			}
		};

		$this->shell           = new AdminShell( $assets, $this->promotions );
		$this->dashboard       = new Dashboard( $assets, $this->permissions, $export, $this->shell, $this->promotions );
		$this->settings_page   = new SettingsPage( $settings, $this->permissions, $this->shell, $this->promotions );
		$this->report          = new GovernanceReportPage( $assets, $this->permissions, $export, $this->shell, $this->promotions );
		$this->getting_started = new GettingStartedPage( $this->shell, $this->permissions, $settings, $statuses );

		$this->set_sdk_addons( true );
	}

	/**
	 * Restores the SDK flag and the admin globals.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->set_sdk_addons( $this->had_addons );
		$this->reset_admin_state();
		unset( $_GET['hint'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );
		$this->restore_plugin_instance();

		parent::tear_down();
	}

	/**
	 * Nothing is for sale: `none`.
	 *
	 * @return void
	 */
	public function test_stage_is_none_when_the_sdk_sells_nothing() {
		$this->set_sdk_addons( false );

		$this->assertFalse( sit_cwm_fs()->has_paid_plan() );
		$this->assertSame( Promotions::STAGE_NONE, $this->promotions->stage() );
	}

	/**
	 * Add-ons on and Pro not active: `on_sale`.
	 *
	 * @return void
	 */
	public function test_stage_is_on_sale_when_addons_are_on_and_pro_is_not_active() {
		$this->assertFalse( function_exists( 'sit_cwm_pro_fs' ) );
		$this->assertSame( Promotions::STAGE_ON_SALE, $this->promotions->stage() );
	}

	/**
	 * Pro booted: `owned`, even with add-ons on sale.
	 *
	 * @return void
	 */
	public function test_stage_is_owned_once_pro_has_loaded() {
		do_action( 'sit_cwm_pro_loaded', null );

		$this->assertSame( Promotions::STAGE_OWNED, $this->promotions->stage() );
	}

	/**
	 * `sit_cwm_show_promotions` returning false: `owned`. It cannot turn
	 * promotion on before anything is for sale.
	 *
	 * @return void
	 */
	public function test_filter_false_means_owned_and_true_cannot_force_a_sale() {
		add_filter( 'sit_cwm_show_promotions', '__return_false' );
		$this->assertSame( Promotions::STAGE_OWNED, $this->promotions->stage() );
		remove_filter( 'sit_cwm_show_promotions', '__return_false' );

		$this->set_sdk_addons( false );
		add_filter( 'sit_cwm_show_promotions', '__return_true' );
		$this->assertSame( Promotions::STAGE_NONE, $this->promotions->stage() );
	}

	/**
	 * Every hint shows to a manager on a plugin screen while on sale, with a
	 * dismiss link to admin-post.php carrying a nonce.
	 *
	 * @return void
	 */
	public function test_each_hint_shows_to_managers_on_plugin_screens() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->on_screen( Dashboard::MENU_SLUG );

		foreach ( Promotions::HINT_KEYS as $key ) {
			$html = $this->promotions->hint( $key );

			$this->assertStringContainsString( 'class="sit-cwm-hint" data-sit-cwm-hint="' . $key . '"', $html );
			$this->assertStringContainsString( esc_url( Promotions::PRO_URL ), $html );
			$this->assertStringContainsString( 'admin-post.php?action=' . Promotions::DISMISS_ACTION . '&#038;hint=' . $key, $html );
			$this->assertStringContainsString( '_wpnonce=' . wp_create_nonce( Promotions::DISMISS_ACTION ), $html );
			$this->assertStringNotContainsString( '<script', $html );
		}

		$this->assertSame( '', $this->promotions->hint( 'unknown' ) );
	}

	/**
	 * Non-managers never see a hint, even on a plugin screen.
	 *
	 * @return void
	 */
	public function test_hints_are_for_managers_only() {
		$this->on_screen( Dashboard::MENU_SLUG );

		foreach ( array( 'editor', 'author', 'subscriber' ) as $role ) {
			wp_set_current_user( self::fixture_user( $role ) );

			foreach ( Promotions::HINT_KEYS as $key ) {
				$this->assertSame( '', $this->promotions->hint( $key ), "{$role} saw the {$key} hint." );
			}
		}

		wp_set_current_user( 0 );
		$this->assertSame( '', $this->promotions->hint( 'overdue' ) );
	}

	/**
	 * Off plugin screens (core screens, the SDK's own pages) nothing shows.
	 *
	 * @return void
	 */
	public function test_hints_only_on_plugin_screens() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		foreach ( array( null, 'some-other-page', 'sit-cwm-settings-account', 'sit-cwm-settings-addons', 'sit-cwm-pro-workflows' ) as $page ) {
			$GLOBALS['plugin_page'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the requested page.

			$this->assertSame( '', $this->promotions->hint( 'export' ), 'Hint shown on ' . var_export( $page, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Assertion message.
			$this->assertSame( '', $this->promotions->header_link() );
			$this->assertSame( array(), $this->promotions->addons_tab() );
		}
	}

	/**
	 * Outside `on_sale` nothing shows, to anyone.
	 *
	 * @return void
	 */
	public function test_nothing_shows_when_none_or_owned() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->on_screen( SettingsPage::MENU_SLUG );

		$this->set_sdk_addons( false );
		$this->assert_shows_nothing();

		$this->set_sdk_addons( true );
		add_filter( 'sit_cwm_show_promotions', '__return_false' );
		$this->assert_shows_nothing();
	}

	/**
	 * A dismissed hint never shows again for that user, and only that hint.
	 *
	 * @return void
	 */
	public function test_dismissed_hint_never_shows_again_for_that_user() {
		$admin = self::fixture_user( 'admin' );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->on_screen( Dashboard::MENU_SLUG );

		$this->assertTrue( $this->promotions->dismiss( $admin, 'overdue' ) );
		$this->assertTrue( $this->promotions->dismiss( $admin, 'overdue' ) );
		$this->assertSame( array( 'overdue' ), get_user_meta( $admin, Promotions::DISMISSED_META, true ) );

		wp_set_current_user( $admin );
		$this->assertSame( '', $this->promotions->hint( 'overdue' ) );
		$this->assertNotSame( '', $this->promotions->hint( 'export' ) );

		wp_set_current_user( $other );
		$this->assertNotSame( '', $this->promotions->hint( 'overdue' ) );

		$this->assertFalse( $this->promotions->dismiss( $admin, 'unknown' ) );
		$this->assertFalse( $this->promotions->dismiss( 0, 'export' ) );
	}

	/**
	 * Garbage in the user meta is ignored.
	 *
	 * @return void
	 */
	public function test_unknown_stored_keys_are_ignored() {
		$admin = self::fixture_user( 'admin' );
		update_user_meta( $admin, Promotions::DISMISSED_META, array( 'export', '<script>', 42 ) );

		$this->assertTrue( $this->promotions->is_dismissed( $admin, 'export' ) );
		$this->assertFalse( $this->promotions->is_dismissed( $admin, '<script>' ) );

		update_user_meta( $admin, Promotions::DISMISSED_META, 'export' );
		$this->assertFalse( $this->promotions->is_dismissed( $admin, 'export' ) );
	}

	/**
	 * `register()` hooks the logged-in admin-post action only.
	 *
	 * @return void
	 */
	public function test_register_hooks_the_dismiss_handler_for_logged_in_users_only() {
		$this->promotions->register();

		$this->assertSame( 10, has_action( 'admin_post_' . Promotions::DISMISS_ACTION, array( $this->promotions, 'handle_dismiss' ) ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_' . Promotions::DISMISS_ACTION ) );
	}

	/**
	 * The handler checks the nonce first: none, or another action's, dies 403.
	 *
	 * @return void
	 */
	public function test_dismiss_without_a_valid_nonce_dies_and_writes_nothing() {
		$admin = self::fixture_user( 'admin' );
		wp_set_current_user( $admin );
		$_GET['hint'] = 'export';

		$this->assert_dismiss_dies_with_403();

		$this->set_nonce( wp_create_nonce( 'sit_cwm_something_else' ) );
		$this->assert_dismiss_dies_with_403();

		$this->assertSame( '', get_user_meta( $admin, Promotions::DISMISSED_META, true ) );
	}

	/**
	 * A valid nonce is not authorization.
	 *
	 * @return void
	 */
	public function test_dismiss_by_a_non_manager_dies() {
		$editor = self::fixture_user( 'editor' );
		wp_set_current_user( $editor );
		$_GET['hint'] = 'export';
		$this->set_nonce( wp_create_nonce( Promotions::DISMISS_ACTION ) );

		$this->assert_dismiss_dies_with_403();
		$this->assertSame( '', get_user_meta( $editor, Promotions::DISMISSED_META, true ) );
	}

	/**
	 * A manager with a valid nonce dismisses the hint and is sent back.
	 *
	 * @return void
	 */
	public function test_dismiss_records_the_hint_and_redirects_back() {
		$admin = self::fixture_user( 'admin' );
		wp_set_current_user( $admin );
		$_GET['hint'] = 'notifications';
		$this->set_nonce( wp_create_nonce( Promotions::DISMISS_ACTION ) );

		$this->promotions->handle_dismiss();

		$this->assertTrue( $this->promotions->is_dismissed( $admin, 'notifications' ) );
		$this->assertCount( 1, $this->promotions->redirects );
		$this->assertSame( admin_url( 'admin.php?page=' . Dashboard::MENU_SLUG ), $this->promotions->redirects[0] );
	}

	/**
	 * Each screen prints its own hint, and only its own.
	 *
	 * @return void
	 */
	public function test_each_placement_prints_its_hint() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$this->on_screen( Dashboard::MENU_SLUG );
		$dashboard = $this->render( array( $this->dashboard, 'render' ) );
		$this->assertStringContainsString( 'data-sit-cwm-hint="overdue"', $dashboard );
		$this->assertLessThan( strpos( $dashboard, 'id="' . Dashboard::ROOT_ID . '"' ), strpos( $dashboard, 'data-sit-cwm-hint="overdue"' ) );

		$this->on_screen( GovernanceReportPage::MENU_SLUG );
		$report = $this->render( array( $this->report, 'render' ) );
		$this->assertStringContainsString( 'data-sit-cwm-hint="export"', $report );
		$this->assertStringNotContainsString( 'data-sit-cwm-hint="overdue"', $report );

		$this->on_screen( SettingsPage::MENU_SLUG );
		$workflow      = $this->render( array( $this->settings_page, 'render_section' ) );
		$notifications = $this->render( array( $this->settings_page, 'render_notifications_section' ) );
		$this->assertStringContainsString( 'data-sit-cwm-hint="workflows"', $workflow );
		$this->assertStringContainsString( 'data-sit-cwm-hint="notifications"', $notifications );
	}

	/**
	 * The header gets one plain "Pro" link and the Add-Ons tab last, for a
	 * manager who can open the Add-Ons page.
	 *
	 * @return void
	 */
	public function test_header_has_a_plain_pro_link_and_the_addons_tab_last() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->register_menus();
		$addons = sit_cwm_fs()->get_menu_slug() . '-' . Promotions::ADDONS_PAGE;
		add_submenu_page( Dashboard::MENU_SLUG, 'Add-Ons', 'Add-Ons', 'manage_options', $addons, '__return_null' );

		$this->assertSame( 'sit-cwm-settings-addons', $addons );

		$this->on_screen( Dashboard::MENU_SLUG, false );
		$html = $this->header();

		$this->assertSame( 1, substr_count( $html, 'class="sit-cwm-shell__pro"' ) );
		$this->assertMatchesRegularExpression( '#<div class="sit-cwm-shell__aside"><a class="sit-cwm-shell__pro" href="[^"]+"[^>]*>Pro<#', $html );
		$this->assertStringNotContainsString( 'button', $html );

		$tabs = $this->shell->tabs();
		$this->assertSame( $addons, array_key_last( $tabs ) );
		$this->assertSame( 'Add-Ons', $tabs[ $addons ] );
		$this->assertStringContainsString( 'page=' . $addons . '"', $html );
	}

	/**
	 * Without the Add-Ons page's own capability the tab stays hidden; without
	 * promotion the aside stays empty.
	 *
	 * @return void
	 */
	public function test_addons_tab_follows_the_page_capability_and_the_stage() {
		$manager = $this->create_user_with_caps( array( 'sit_cwm_manage_workflows' ) );
		wp_set_current_user( $manager );
		$this->register_menus();
		$addons = sit_cwm_fs()->get_menu_slug() . '-' . Promotions::ADDONS_PAGE;
		add_submenu_page( Dashboard::MENU_SLUG, 'Add-Ons', 'Add-Ons', 'manage_options', $addons, '__return_null' );

		$this->on_screen( SettingsPage::MENU_SLUG, false );
		$this->assertArrayNotHasKey( $addons, $this->shell->tabs() );

		wp_set_current_user( self::fixture_user( 'admin' ) );
		add_filter( 'sit_cwm_show_promotions', '__return_false' );

		$this->assertArrayNotHasKey( $addons, $this->shell->tabs() );
		$this->assertStringContainsString( '<div class="sit-cwm-shell__aside"></div>', $this->header() );
	}

	/**
	 * The plugin row gets "Go Pro" last, for managers only, only on sale.
	 *
	 * @return void
	 */
	public function test_go_pro_action_link() {
		$links = new PluginActionLinks( $this->permissions, $this->promotions );

		wp_set_current_user( self::fixture_user( 'admin' ) );
		$row = $links->filter_action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate', 'sit_cwm_go_pro' ), array_keys( $row ) );
		$this->assertStringContainsString( '>Go Pro<', $row['sit_cwm_go_pro'] );
		$this->assertStringContainsString( esc_url( Promotions::PRO_URL ), $row['sit_cwm_go_pro'] );

		add_filter( 'sit_cwm_show_promotions', '__return_false' );
		$this->assertArrayNotHasKey( 'sit_cwm_go_pro', $links->filter_action_links( array() ) );
		remove_filter( 'sit_cwm_show_promotions', '__return_false' );

		wp_set_current_user( self::fixture_user( 'editor' ) );
		$this->assertSame( array(), $links->filter_action_links( array() ) );
	}

	/**
	 * Nothing reaches the block editor or the classic meta box: the editor
	 * bootstrap carries no promotion, and no editor code knows the class.
	 *
	 * @return void
	 */
	public function test_nothing_is_rendered_in_the_editors() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->on_screen( Dashboard::MENU_SLUG );

		$bootstrap = (string) wp_json_encode( Plugin::instance()->container()->get( 'assets' )->bootstrap_data() );

		foreach ( array_merge( $this->promotion_needles(), array( Promotions::PRO_URL, 'Go Pro', 'Learn about Pro' ) ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $bootstrap );
		}

		foreach ( array( 'includes/Editor', 'src/sidebar', 'src/classic', 'src/components' ) as $dir ) {
			$this->assert_dir_has_no_promotion( $dir );
		}
	}

	/**
	 * No site-wide notice, no dashboard widget, and nothing on the front end.
	 *
	 * @return void
	 */
	public function test_no_notices_widgets_or_front_end_output() {
		global $wp_filter;

		$this->promotions->register();

		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices', 'wp_dashboard_setup', 'wp_network_dashboard_setup', 'the_content', 'wp_footer', 'render_block' ) as $hook ) {
			foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];

					$this->assertFalse( is_array( $function ) && $function[0] instanceof Promotions, "Promotions hooks {$hook}." );
				}
			}
		}

		foreach ( array( 'includes/Disclosure', 'blocks', 'src/disclosure' ) as $dir ) {
			$this->assert_dir_has_no_promotion( $dir );
		}
	}

	/**
	 * All promotion code lives in `admin/Promotions.php`: nothing else holds
	 * the copy, the URL or the dismiss action.
	 *
	 * @return void
	 */
	public function test_all_promotion_code_lives_in_one_class() {
		foreach ( array( 'admin', 'includes', 'src' ) as $dir ) {
			$this->assert_dir_has_no_promotion( $dir, array( 'Promotions.php' ) );
		}
	}

	/**
	 * The container wires one instance into the shell and the pages, and boots
	 * it in the admin phase.
	 *
	 * @return void
	 */
	public function test_container_wires_promotions() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( Promotions::class, $plugin->container()->get( 'admin.promotions' ) );
		$this->assertContains( 'admin.promotions', Plugin::BOOT_PHASES['admin'] );
		$this->assertSame( 'admin.shell', Plugin::BOOT_PHASES['admin'][0] );
	}

	/**
	 * Asserts no header link, tab, hint or action link shows.
	 *
	 * @return void
	 */
	private function assert_shows_nothing(): void {
		foreach ( Promotions::HINT_KEYS as $key ) {
			$this->assertSame( '', $this->promotions->hint( $key ) );
		}

		$this->assertSame( '', $this->promotions->header_link() );
		$this->assertSame( array(), $this->promotions->addons_tab() );
		$this->assertSame( '', $this->promotions->action_link() );
	}

	/**
	 * Asserts `handle_dismiss()` dies with 403 and redirects nowhere.
	 *
	 * @return void
	 */
	private function assert_dismiss_dies_with_403(): void {
		try {
			$this->promotions->handle_dismiss();
			$this->fail( 'handle_dismiss() did not die.' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 403, $e->getCode() );
		}

		$this->assertSame( array(), $this->promotions->redirects );
	}

	/**
	 * Asserts no file under a directory mentions the promotion's copy, URL,
	 * classes or dismiss action.
	 *
	 * @param string   $dir    Directory relative to the plugin root.
	 * @param string[] $except Basenames to skip.
	 * @return void
	 */
	private function assert_dir_has_no_promotion( string $dir, array $except = array() ): void {
		$path = SIT_CWM_PLUGIN_DIR . $dir;

		if ( ! is_dir( $path ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( ! preg_match( '/\.(php|js|jsx|json)$/', $file->getFilename() ) || in_array( $file->getFilename(), $except, true ) ) {
				continue;
			}

			$code = (string) file_get_contents( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.

			foreach ( $this->promotion_needles() as $needle ) {
				$this->assertStringNotContainsString( $needle, $code, $file->getPathname() . ' contains ' . $needle );
			}
		}
	}

	/**
	 * Strings only promotion code contains.
	 *
	 * @return string[]
	 */
	private function promotion_needles(): array {
		// The copy is matched as a PHP string literal, so prose in docblocks does
		// not count. The product URL is not here: Getting Started links to the
		// same site for its docs and help, which is not promotion.
		return array( Promotions::DISMISS_ACTION, Promotions::DISMISSED_META, 'sit-cwm-hint', 'sit-cwm-shell__pro', "'Go Pro'", "'Learn about Pro'" );
	}

	/**
	 * Flips the SDK's `has_addons` flag, which the init array sets.
	 *
	 * @param bool $on Whether add-ons are on.
	 * @return void
	 */
	private function set_sdk_addons( bool $on ): void {
		$property = new ReflectionProperty( \Freemius::class, '_has_addons' );
		$property->setAccessible( true );
		$property->setValue( sit_cwm_fs(), $on );
	}

	/**
	 * Simulates a request for a plugin screen.
	 *
	 * @param string $slug  Page slug.
	 * @param bool   $reset Whether to reset the menus first.
	 * @return void
	 */
	private function on_screen( string $slug, bool $reset = true ): void {
		if ( $reset ) {
			$this->reset_admin_state();
		}

		set_current_screen( 'dashboard' );
		$GLOBALS['plugin_page'] = $slug; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the requested page.
	}

	/**
	 * Buffers a render callback.
	 *
	 * @param callable $callback Callback.
	 * @return string
	 */
	private function render( callable $callback ): string {
		ob_start();
		call_user_func( $callback );

		return (string) ob_get_clean();
	}

	/**
	 * Buffers the header.
	 *
	 * @return string
	 */
	private function header(): string {
		return $this->render( array( $this->shell, 'render_header' ) );
	}

	/**
	 * Registers the core pages as the current user.
	 *
	 * @return void
	 */
	private function register_menus(): void {
		$this->reset_admin_state();

		$this->dashboard->add_menu();
		$this->report->add_menu();
		$this->settings_page->add_menu();
		$this->getting_started->add_menu();
	}

	/**
	 * Puts a nonce on the request, as the dismiss link would.
	 *
	 * @param string $nonce Nonce.
	 * @return void
	 */
	private function set_nonce( string $nonce ): void {
		$_GET['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;
	}

	/**
	 * Resets menu globals, the current screen and `$plugin_page`.
	 *
	 * @return void
	 */
	private function reset_admin_state(): void {
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
