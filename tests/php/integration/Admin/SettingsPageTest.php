<?php
/**
 * Integration tests for the settings screen.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration\Admin;

use Sit_Cwm\Admin\Dashboard;
use Sit_Cwm\Admin\Settings as SettingsPage;
use Sit_Cwm\Content\PostMeta;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WPDieException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu gating, Settings API nonce and capability, sanitizing, and the effect
 * of disabling a post type.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Admin\Settings
 * @covers \Sit_Cwm\Core\Settings::available_post_types
 */
final class SettingsPageTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Service under test.
	 *
	 * @var SettingsPage
	 */
	private $page;

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $store;

	/**
	 * Authorization.
	 *
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Loads the admin APIs, resets admin state and builds the service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		$this->reset_admin_state();

		$this->store       = new Settings();
		$this->permissions = new PermissionManager( $this->posts, $this->store );
		$this->page        = new SettingsPage( $this->store, $this->permissions );
	}

	/**
	 * Unregisters the setting and restores global admin state.
	 *
	 * @return void
	 */
	public function tear_down() {
		if ( isset( get_registered_settings()[ Settings::OPTION ] ) ) {
			unregister_setting( SettingsPage::OPTION_GROUP, Settings::OPTION );
		}

		$this->reset_admin_state();

		parent::tear_down();
	}

	/**
	 * `register()` hooks the menu, the settings and the option page capability.
	 *
	 * @return void
	 */
	public function test_register_hooks() {
		$this->page->register();

		$this->assertSame( 11, has_action( 'admin_menu', array( $this->page, 'add_menu' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( $this->page, 'register_settings' ) ) );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, applied here the way options.php applies it.
		$this->assertSame( Capabilities::MANAGE_WORKFLOWS, apply_filters( 'option_page_capability_' . SettingsPage::OPTION_GROUP, 'manage_options' ) );
	}

	/**
	 * Only users who manage the plugin get the submenu item.
	 *
	 * @return void
	 */
	public function test_menu_only_for_managers() {
		wp_set_current_user( self::fixture_user( 'editor' ) );
		$this->page->add_menu();

		$this->assertSame( '', $this->page->hook_suffix() );
		$this->assertNull( $this->submenu_item() );

		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->page->add_menu();

		$item = $this->submenu_item();

		$this->assertNotSame( '', $this->page->hook_suffix() );
		$this->assertNotNull( $item );
		$this->assertSame( Capabilities::MANAGE_WORKFLOWS, $item[1] );
	}

	/**
	 * Direct access without the capability dies.
	 *
	 * @return void
	 */
	public function test_render_dies_without_capability() {
		wp_set_current_user( self::fixture_user( 'editor' ) );

		$this->expectException( WPDieException::class );

		$this->page->render();
	}

	/**
	 * The form carries a nonce for the option group, and `options.php`'s
	 * referer check rejects a forged or absent nonce.
	 *
	 * @return void
	 */
	public function test_form_nonce_is_required() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		ob_start();
		settings_fields( SettingsPage::OPTION_GROUP );
		$fields = ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="_wpnonce" value="([a-f0-9]+)"/', $fields );
		preg_match( '/name="_wpnonce" value="([a-f0-9]+)"/', $fields, $match );
		$this->assertNotFalse( wp_verify_nonce( $match[1], SettingsPage::OPTION_GROUP . '-options' ) );

		foreach ( array( 'forged', null ) as $nonce ) {
			unset( $_REQUEST['_wpnonce'] );

			if ( null !== $nonce ) {
				$_REQUEST['_wpnonce'] = $nonce;
			}

			$died = false;

			try {
				check_admin_referer( SettingsPage::OPTION_GROUP . '-options' );
			} catch ( WPDieException $e ) {
				$died = true;
			}

			$this->assertTrue( $died, null === $nonce ? 'absent nonce' : 'forged nonce' );
		}

		unset( $_REQUEST['_wpnonce'] );
	}

	/**
	 * Unknown post types are dropped, and a message explains it.
	 *
	 * @return void
	 */
	public function test_unknown_post_type_is_not_stored() {
		$clean = $this->page->sanitize(
			array(
				'post_types'               => array( 'post', 'nonexistent_cpt' ),
				'delete_data_on_uninstall' => '1',
			)
		);

		$this->assertSame(
			array(
				'post_types'               => array( 'post' ),
				'delete_data_on_uninstall' => true,
			),
			$clean
		);
		$this->assertContains( 'sit_cwm_unknown_post_types', array_column( get_settings_errors( Settings::OPTION ), 'code' ) );

		// Through the option API while the setting is registered.
		$this->page->register_settings();
		update_option( Settings::OPTION, array( 'post_types' => array( 'page', 'nonexistent_cpt' ) ) );

		$this->assertSame( array( 'page' ), $this->store->get( 'post_types' ) );
	}

	/**
	 * Scalars where arrays are expected never fatal and leave a valid option.
	 *
	 * @return void
	 */
	public function test_scalar_input_keeps_a_valid_option() {
		$this->assertTrue( $this->store->update( array( 'post_types' => array( 'page' ) ) ) );

		$this->assertSame( $this->store->all(), $this->page->sanitize( 'post' ) );
		$this->assertSame( array( 'page' ), $this->page->sanitize( array( 'post_types' => 'post' ) )['post_types'] );
		$this->assertSame( array(), $this->page->sanitize( array( 'post_types' => array( array( 'post' ) ) ) )['post_types'] );
		$this->assertFalse( $this->page->sanitize( array( 'delete_data_on_uninstall' => array( '1' ) ) )['delete_data_on_uninstall'] );
	}

	/**
	 * A form with every checkbox cleared submits nothing, which disables all.
	 *
	 * @return void
	 */
	public function test_cleared_form_disables_everything() {
		$this->assertSame(
			array(
				'post_types'               => array(),
				'delete_data_on_uninstall' => false,
			),
			$this->page->sanitize( null )
		);
	}

	/**
	 * Unknown keys are dropped entirely.
	 *
	 * @return void
	 */
	public function test_unknown_keys_are_dropped() {
		$clean = $this->page->sanitize(
			array(
				'post_types' => array( 'post' ),
				'evil'       => '<script>',
			)
		);

		$this->assertSame( array( 'post_types', 'delete_data_on_uninstall' ), array_keys( $clean ) );
	}

	/**
	 * Attachments, revisions, menu items and core's `wp_*` types are never offered.
	 *
	 * @return void
	 */
	public function test_internal_post_types_cannot_be_enabled() {
		$available = $this->store->available_post_types();

		$this->assertArrayHasKey( 'post', $available );
		$this->assertArrayHasKey( 'page', $available );

		foreach ( array( 'attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' ) as $excluded ) {
			$this->assertArrayNotHasKey( $excluded, $available, $excluded );
		}
	}

	/**
	 * The option is not exposed through `/wp/v2/settings`.
	 *
	 * @return void
	 */
	public function test_setting_is_not_in_rest() {
		$this->page->register_settings();

		$this->assertFalse( get_registered_settings()[ Settings::OPTION ]['show_in_rest'] );
	}

	/**
	 * The screen renders a Settings API form with escaped, checked fields.
	 *
	 * @return void
	 */
	public function test_render_prints_settings_form() {
		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->store->update( array( 'post_types' => array( 'page' ) ) );
		$this->page->register_settings();

		ob_start();
		$this->page->render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'options.php' ) ) . '"', $html );
		// Core's settings_fields() quotes these attributes with apostrophes.
		$this->assertMatchesRegularExpression( '/name=[\'"]option_page[\'"] value=[\'"]sit_cwm_settings_group[\'"]/', $html );
		$this->assertMatchesRegularExpression( '/name="sit_cwm_settings\[post_types\]\[\]" value="page"\s+checked=\'checked\'/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="post"\s+checked/', $html );
		$this->assertStringContainsString( 'name="sit_cwm_settings[delete_data_on_uninstall]"', $html );
	}

	/**
	 * The help tab explains the status flow and permissions.
	 *
	 * @return void
	 */
	public function test_help_tab() {
		set_current_screen( 'editorial-workflow_page_sit-cwm-settings' );

		$this->page->add_help_tab();

		$tab = get_current_screen()->get_help_tab( SettingsPage::HELP_TAB_ID );

		$this->assertNotNull( $tab );
		$this->assertStringContainsString( 'Needs Changes', $tab['content'] );
	}

	/**
	 * Disabling a post type removes its workflow fields from core's REST
	 * schema and 404s its workflow route, while the stored data stays.
	 *
	 * @return void
	 */
	public function test_disabling_a_post_type_keeps_its_data() {
		$admin = self::fixture_user( 'admin' );
		$page  = $this->create_managed_post(
			array(
				'post_type'       => 'page',
				'post_author'     => $admin,
				'workflow_status' => 'review',
				'due_date'        => '2026-10-01',
			)
		);

		$this->activity->log_status_change( $page, 'writing', 'review', $admin );

		$this->assertTrue( $this->store->update( array( 'post_types' => array( 'post' ) ) ) );

		// Simulate the next request, which registers meta for enabled types only.
		$meta = new PostMeta( new StatusManager(), $this->permissions, $this->posts, $this->store );

		foreach ( array( PostRepository::META_STATUS, PostRepository::META_REVIEWER, PostRepository::META_DUE_DATE ) as $key ) {
			unregister_post_meta( 'page', $key );
		}

		$meta->register_meta();
		$this->reset_rest_server();

		try {
			$this->assertFalse( registered_meta_key_exists( 'post', PostRepository::META_STATUS, 'page' ) );
			$this->assertTrue( registered_meta_key_exists( 'post', PostRepository::META_STATUS, 'post' ) );

			wp_set_current_user( $admin );

			$schema = $this->rest_request( 'OPTIONS', '/wp/v2/pages' )->get_data()['schema']['properties'];

			$this->assertArrayNotHasKey( PostRepository::META_STATUS, $schema['meta']['properties'] ?? array() );
			$this->assert_error_response( $this->rest_request( 'GET', '/sit-cwm/v1/posts/' . $page . '/workflow' ), 'sit_cwm_not_managed', 404 );

			$this->assertSame( 'review', get_metadata_raw( 'post', $page, PostRepository::META_STATUS, true ) );
			$this->assertSame( '2026-10-01', get_metadata_raw( 'post', $page, PostRepository::META_DUE_DATE, true ) );
			$this->assert_activity_count( $page, 1 );
		} finally {
			$meta->register_for_post_type( 'page' );
		}
	}

	/**
	 * The container builds the service for the admin phase.
	 *
	 * @return void
	 */
	public function test_container_wires_settings_page() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( SettingsPage::class, $plugin->container()->get( 'admin.settings' ) );
		$this->assertContains( 'admin.settings', Plugin::BOOT_PHASES['admin'] );
	}

	/**
	 * The registered settings submenu entry, if any.
	 *
	 * @return array|null
	 */
	private function submenu_item(): ?array {
		global $submenu;

		foreach ( (array) ( $submenu[ Dashboard::MENU_SLUG ] ?? array() ) as $item ) {
			if ( isset( $item[2] ) && SettingsPage::MENU_SLUG === $item[2] ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Clears menu, Settings API and screen globals.
	 *
	 * @return void
	 */
	private function reset_admin_state() {
		global $menu, $submenu, $admin_page_hooks, $_registered_pages, $wp_settings_sections, $wp_settings_fields, $wp_settings_errors, $current_screen;

		$menu                 = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$submenu              = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$admin_page_hooks     = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$_registered_pages    = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates menu registration between tests.
		$wp_settings_sections = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates Settings API registration between tests.
		$wp_settings_fields   = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates Settings API registration between tests.
		$wp_settings_errors   = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates settings errors between tests.
		$current_screen       = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates the current screen between tests.
	}
}
