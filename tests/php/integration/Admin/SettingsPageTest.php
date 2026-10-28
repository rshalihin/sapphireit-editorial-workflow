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
use Sit_Cwm\Notifications\NotificationEvents;
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
				'publish_gate'             => array(),
				'notifications'            => array_fill_keys( NotificationEvents::all(), false ),
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
				'publish_gate'             => array(),
				'notifications'            => array_fill_keys( NotificationEvents::all(), false ),
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

		$this->assertSame( array( 'post_types', 'delete_data_on_uninstall', 'publish_gate', 'notifications' ), array_keys( $clean ) );
	}

	/**
	 * A programmatic partial save through the option API, while the setting
	 * is registered, keeps the gate modes and toggles it does not mention.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_programmatic_save_keeps_other_gate_modes_and_toggles() {
		$this->page->register_settings();

		$this->assertTrue(
			$this->store->update(
				array(
					'publish_gate'  => array( 'post' => 'enforce' ),
					'notifications' => array( 'approved' => true ),
				)
			)
		);
		$this->assertTrue( $this->store->update( array( 'publish_gate' => array( 'page' => 'log_only' ) ) ) );

		$this->assertSame( array( 'page' => 'log_only' ), $this->store->gate_modes() );
		$this->assertTrue( $this->store->is_notification_enabled( 'approved' ) );
		$this->assertFalse( $this->store->is_notification_enabled( 'comment_added' ) );
	}

	/**
	 * The publish gate and notifications sections and fields are registered.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_sections_and_fields_are_registered() {
		global $wp_settings_sections, $wp_settings_fields;

		$this->page->register_settings();

		$this->assertArrayHasKey( SettingsPage::SECTION_GATE, $wp_settings_sections[ SettingsPage::MENU_SLUG ] );
		$this->assertArrayHasKey( SettingsPage::SECTION_NOTIFICATIONS, $wp_settings_sections[ SettingsPage::MENU_SLUG ] );
		$this->assertSame( 'sit_cwm_publish_gate', SettingsPage::SECTION_GATE );
		$this->assertSame( 'sit_cwm_notifications', SettingsPage::SECTION_NOTIFICATIONS );
		$this->assertArrayHasKey( 'sit_cwm_publish_gate_modes', $wp_settings_fields[ SettingsPage::MENU_SLUG ][ SettingsPage::SECTION_GATE ] );
		$this->assertArrayHasKey( 'sit_cwm_notification_events', $wp_settings_fields[ SettingsPage::MENU_SLUG ][ SettingsPage::SECTION_NOTIFICATIONS ] );
	}

	/**
	 * One labelled select per enabled post type, with the stored mode selected.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_gate_field_renders_one_select_per_enabled_type_with_current_value() {
		$this->assertTrue( $this->store->update( array( 'publish_gate' => array( 'page' => 'log_only' ) ) ) );

		ob_start();
		$this->page->render_publish_gate_field();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'name="sit_cwm_settings[publish_gate][post]"', $html );
		$this->assertStringContainsString( 'name="sit_cwm_settings[publish_gate][page]"', $html );
		$this->assertStringContainsString( '<label for="sit-cwm-gate-post">', $html );
		$this->assertStringContainsString( '<label for="sit-cwm-gate-page">', $html );
		$this->assertStringContainsString( 'id="sit-cwm-gate-page"', $html );
		$this->assertSame( 2, preg_match_all( '/\sselected=/', $html ), 'one selected option per select' );
		$this->assertMatchesRegularExpression( '/name="sit_cwm_settings\[publish_gate\]\[page\]">(?:(?!<\/select>).)*<option value="log_only"\s+selected/s', $html );
		$this->assertMatchesRegularExpression( '/name="sit_cwm_settings\[publish_gate\]\[post\]">(?:(?!<\/select>).)*<option value="off"\s+selected/s', $html );
		$this->assertStringContainsString( '>Enforce</option>', $html );
	}

	/**
	 * With no enabled types there is nothing to choose, and the field says so.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_gate_field_without_enabled_types_explains_why() {
		$this->assertTrue( $this->store->update( array( 'post_types' => array() ) ) );

		ob_start();
		$this->page->render_publish_gate_field();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( '<select', $html );
		$this->assertStringContainsString( 'Enable a content type above first.', $html );
	}

	/**
	 * Every event gets a checkbox, all unchecked on a fresh install.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_notifications_field_renders_every_event_unchecked_by_default() {
		ob_start();
		$this->page->render_notifications_field();
		$html = ob_get_clean();

		foreach ( NotificationEvents::all() as $event ) {
			$this->assertStringContainsString( 'name="sit_cwm_settings[notifications][' . $event . ']"', $html );
		}

		$this->assertStringContainsString( '<legend class="screen-reader-text">', $html );
		$this->assertStringNotContainsString( 'checked', $html );
	}

	/**
	 * A stored toggle renders checked.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_notifications_field_checks_enabled_events() {
		$this->assertTrue( $this->store->update( array( 'notifications' => array( 'approved' => true ) ) ) );

		ob_start();
		$this->page->render_notifications_field();
		$html = ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="sit_cwm_settings\[notifications\]\[approved\]" value="1"\s+checked=/', $html );
		$this->assertSame( 1, substr_count( $html, 'checked=' ) );
	}

	/**
	 * Valid modes and toggles survive; an unchecked box is off.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_sanitize_keeps_valid_modes_and_toggles() {
		$clean = $this->page->sanitize(
			array(
				'post_types'    => array( 'post', 'page' ),
				'publish_gate'  => array(
					'post' => 'enforce',
					'page' => 'off',
				),
				'notifications' => array( 'approved' => '1' ),
			)
		);

		$this->assertSame(
			array(
				'post' => 'enforce',
				'page' => 'off',
			),
			$clean['publish_gate']
		);
		$this->assertTrue( $clean['notifications']['approved'] );
		$this->assertFalse( $clean['notifications']['comment_added'] );
		$this->assertSame( NotificationEvents::all(), array_keys( $clean['notifications'] ) );
		$this->assertEmpty( get_settings_errors( Settings::OPTION ) );
	}

	/**
	 * Unknown modes and post types are dropped, and a message explains it.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_sanitize_drops_bad_modes_and_unknown_types_with_a_settings_error() {
		$clean = $this->page->sanitize(
			array(
				'post_types'   => array( 'post' ),
				'publish_gate' => array(
					'post'            => 'block',
					'nonexistent_cpt' => 'enforce',
					'attachment'      => 'enforce',
					'page'            => array( 'enforce' ),
				),
			)
		);

		$this->assertSame( array(), $clean['publish_gate'] );
		$this->assertContains( 'sit_cwm_unknown_publish_gate', array_column( get_settings_errors( Settings::OPTION ), 'code' ) );
	}

	/**
	 * A scalar where the gate map is expected keeps the stored modes.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_sanitize_scalar_gate_value_keeps_stored_modes() {
		$this->assertTrue( $this->store->update( array( 'publish_gate' => array( 'post' => 'enforce' ) ) ) );

		$clean = $this->page->sanitize(
			array(
				'post_types'   => array( 'post' ),
				'publish_gate' => 'enforce',
			)
		);

		$this->assertSame( array( 'post' => 'enforce' ), $clean['publish_gate'] );
		$this->assertContains( 'sit_cwm_invalid_publish_gate', array_column( get_settings_errors( Settings::OPTION ), 'code' ) );
	}

	/**
	 * A mode for a type disabled in the same save is kept; it is inert while
	 * the type is disabled (E1).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_sanitize_keeps_modes_for_types_disabled_in_the_same_save() {
		$clean = $this->page->sanitize(
			array(
				'post_types'   => array( 'post' ),
				'publish_gate' => array( 'page' => 'enforce' ),
			)
		);

		$this->assertSame( array( 'post' ), $clean['post_types'] );
		$this->assertSame( array( 'page' => 'enforce' ), $clean['publish_gate'] );
	}

	/**
	 * A disabled type has no select on the form, so saving the form keeps its
	 * stored mode, which applies again when the type is re-enabled. A type
	 * that had a select gets exactly what was submitted.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_sanitize_keeps_stored_modes_for_types_not_shown_on_the_form() {
		$this->assertTrue(
			$this->store->update(
				array(
					'post_types'   => array( 'post' ),
					'publish_gate' => array(
						'post' => 'enforce',
						'page' => 'enforce',
					),
				)
			)
		);

		// Saved while page is disabled: page keeps its mode, post takes the new one.
		$clean = $this->page->sanitize(
			array(
				'post_types'   => array( 'post' ),
				'publish_gate' => array( 'post' => 'log_only' ),
			)
		);

		$this->assertSame(
			array(
				'post' => 'log_only',
				'page' => 'enforce',
			),
			$clean['publish_gate']
		);

		// Re-enabled in the next save: the kept mode comes back with it.
		$clean = $this->page->sanitize(
			array(
				'post_types'   => array( 'post', 'page' ),
				'publish_gate' => array( 'post' => 'off' ),
			)
		);

		$this->assertSame( array( 'post', 'page' ), $clean['post_types'] );
		$this->assertSame(
			array(
				'post' => 'off',
				'page' => 'enforce',
			),
			$clean['publish_gate']
		);

		// A type that had a select and was not submitted is not carried over.
		$this->assertSame( array( 'page' => 'enforce' ), $this->page->sanitize( array( 'post_types' => array( 'post' ) ) )['publish_gate'] );
		$this->assertTrue( $this->store->update( array( 'post_types' => array( 'post', 'page' ) ) ) );
		$this->assertSame( array(), $this->page->sanitize( array( 'post_types' => array( 'post', 'page' ) ) )['publish_gate'] );
	}

	/**
	 * Unknown events are ignored; scalar or forged toggles never fatal.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_sanitize_ignores_unknown_notification_events() {
		$clean = $this->page->sanitize(
			array(
				'notifications' => array(
					'slack'         => '1',
					'approved'      => array( '1' ),
					'comment_added' => 'true',
				),
			)
		);

		$this->assertArrayNotHasKey( 'slack', $clean['notifications'] );
		$this->assertFalse( $clean['notifications']['approved'] );
		$this->assertTrue( $clean['notifications']['comment_added'] );

		$this->assertSame(
			array_fill_keys( NotificationEvents::all(), false ),
			$this->page->sanitize( array( 'notifications' => '1' ) )['notifications']
		);
	}

	/**
	 * The new keys are saved through the same option group, so `options.php`
	 * still requires the manage capability.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_saving_without_manage_cap_still_fails() {
		$this->page->register();
		$this->page->register_settings();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, applied here the way options.php applies it.
		$capability = apply_filters( 'option_page_capability_' . SettingsPage::OPTION_GROUP, 'manage_options' );

		$this->assertSame( Capabilities::MANAGE_WORKFLOWS, $capability );
		$this->assertArrayHasKey( Settings::OPTION, get_registered_settings() );

		wp_set_current_user( self::fixture_user( 'editor' ) );
		$this->assertFalse( current_user_can( $capability ) );

		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->assertTrue( current_user_can( $capability ) );
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
	 * Back-office types (no `editor` support, or no REST) are not offered,
	 * but the filter can opt one back in. Internal types stay excluded.
	 *
	 * @return void
	 */
	public function test_non_editorial_post_types_are_not_offered() {
		register_post_type(
			'sit_cwm_order',
			array(
				'show_ui'      => true,
				'show_in_rest' => true,
				'supports'     => array( 'title' ),
			)
		);
		register_post_type(
			'sit_cwm_norest',
			array(
				'show_ui'      => true,
				'show_in_rest' => false,
				'supports'     => array( 'title', 'editor' ),
			)
		);

		$available = $this->store->available_post_types();

		$this->assertArrayNotHasKey( 'sit_cwm_order', $available );
		$this->assertArrayNotHasKey( 'sit_cwm_norest', $available );
		$this->assertFalse( $this->store->update( array( 'post_types' => array( 'sit_cwm_order' ) ) ) );

		$opt_in = static function ( $types ) {
			$types['sit_cwm_order'] = 'sit_cwm_order';
			$types['attachment']    = 'attachment';
			$types['sit_cwm_ghost'] = 'sit_cwm_ghost';

			return $types;
		};
		add_filter( 'sit_cwm_available_post_types', $opt_in );

		$available = $this->store->available_post_types();

		remove_filter( 'sit_cwm_available_post_types', $opt_in );
		unregister_post_type( 'sit_cwm_order' );
		unregister_post_type( 'sit_cwm_norest' );

		$this->assertArrayHasKey( 'sit_cwm_order', $available );
		$this->assertArrayNotHasKey( 'attachment', $available );
		$this->assertArrayNotHasKey( 'sit_cwm_ghost', $available );
	}

	/**
	 * A type that is already enabled but no longer eligible is kept on save
	 * and rendered with an "unsupported" note.
	 *
	 * @return void
	 */
	public function test_already_enabled_unsupported_type_is_kept() {
		register_post_type(
			'sit_cwm_legacy',
			array(
				'show_ui'      => true,
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);
		$this->assertTrue( $this->store->update( array( 'post_types' => array( 'post', 'sit_cwm_legacy' ) ) ) );

		remove_post_type_support( 'sit_cwm_legacy', 'editor' );

		$this->assertArrayNotHasKey( 'sit_cwm_legacy', $this->store->available_post_types() );
		$this->assertSame( array( 'sit_cwm_legacy' ), $this->store->unsupported_enabled_post_types() );
		$this->assertSame(
			array( 'post', 'sit_cwm_legacy' ),
			$this->page->sanitize( array( 'post_types' => array( 'post', 'sit_cwm_legacy' ) ) )['post_types']
		);

		wp_set_current_user( self::fixture_user( 'admin' ) );
		ob_start();
		$this->page->render_post_types_field();
		$html = ob_get_clean();

		unregister_post_type( 'sit_cwm_legacy' );

		$this->assertMatchesRegularExpression( '/value="sit_cwm_legacy"\s+checked=\'checked\'/', $html );
		$this->assertStringContainsString( 'not supported: kept because it is already enabled', $html );
	}

	/**
	 * Types without the block editor get no note: the classic meta box covers them.
	 *
	 * @return void
	 */
	public function test_classic_editor_types_have_no_note() {
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$classic = static function ( $use_block_editor, $post_type ) {
			return 'page' === $post_type ? false : $use_block_editor;
		};
		add_filter( 'use_block_editor_for_post_type', $classic, 10, 2 );

		ob_start();
		$this->page->render_post_types_field();
		$html = ob_get_clean();

		remove_filter( 'use_block_editor_for_post_type', $classic, 10 );

		$this->assertStringNotContainsString( 'dashboard only', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="page"[^>]*\/> [^<]+<\/label> <span/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="post"[^>]*\/> [^<]+<\/label> <span/', $html );
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
		$this->assertStringContainsString( '<h2>Publish gate</h2>', $html );
		$this->assertStringContainsString( '<h2>Email notifications</h2>', $html );
		$this->assertStringContainsString( 'name="sit_cwm_settings[publish_gate][page]"', $html );
		$this->assertStringNotContainsString( 'name="sit_cwm_settings[publish_gate][post]"', $html );
		$this->assertStringContainsString( 'name="sit_cwm_settings[notifications][approved]"', $html );
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
		$this->assertStringContainsString( 'Publish gate:', $tab['content'] );
		$this->assertStringContainsString( 'Email notifications:', $tab['content'] );
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

		$rows = $this->activity->count_for_post( $page );

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
			$this->assert_activity_count( $page, $rows );
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
