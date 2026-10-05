<?php
/**
 * Settings screen.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Admin;

use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings as SettingsStore;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Editorial Workflow → Settings": the post types the workflow applies to and
 * the uninstall data preference. Nothing more in v1.0.
 *
 * Built on the Settings API: the form posts to `options.php`, which verifies
 * the nonce printed by `settings_fields()` and the capability returned for the
 * option group, so there is no hand-rolled `admin_post_` handler. Every value
 * passes through `sanitize()`, the only place settings input is trusted-ized.
 *
 * Settings are not exposed through `/wp/v2/settings` (`show_in_rest` false).
 *
 * @since 1.0.0
 */
final class Settings implements Bootable {

	/**
	 * Menu slug (`admin.php?page=sit-cwm-settings`).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const MENU_SLUG = 'sit-cwm-settings';

	/**
	 * Settings API option group.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION_GROUP = 'sit_cwm_settings_group';

	/**
	 * Settings section id.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SECTION = 'sit_cwm_general';

	/**
	 * Contextual help tab id.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const HELP_TAB_ID = 'sit-cwm-workflow-help';

	/**
	 * Settings store.
	 *
	 * @since 1.0.0
	 * @var SettingsStore
	 */
	private $settings;

	/**
	 * Workflow authorization.
	 *
	 * @since 1.0.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Hook suffix of the registered page; empty until the menu is added.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param SettingsStore     $settings    Settings store.
	 * @param PermissionManager $permissions Workflow authorization.
	 */
	public function __construct( SettingsStore $settings, PermissionManager $permissions ) {
		$this->settings    = $settings;
		$this->permissions = $permissions;
	}

	/**
	 * Attaches the menu, settings registration and option page capability.
	 *
	 * The menu runs at priority 11 so the parent dashboard menu (priority 10)
	 * already exists.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 11 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'capability' ) );
	}

	/**
	 * Registers the submenu page for users who manage the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		if ( ! $this->permissions->can_manage() ) {
			return;
		}

		$hook_suffix = add_submenu_page(
			Dashboard::MENU_SLUG,
			__( 'Editorial Workflow Settings', 'sapphireit-editorial-workflow' ),
			__( 'Settings', 'sapphireit-editorial-workflow' ),
			Capabilities::MANAGE_WORKFLOWS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		if ( false === $hook_suffix ) {
			return;
		}

		$this->hook_suffix = (string) $hook_suffix;

		add_action( 'load-' . $this->hook_suffix, array( $this, 'add_help_tab' ) );
	}

	/**
	 * Hook suffix of the settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty when the menu was not registered for this user.
	 */
	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Capability `options.php` requires to save this option group, instead of
	 * core's default `manage_options`.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::MANAGE_WORKFLOWS;
	}

	/**
	 * Registers the setting, its section and its fields.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			SettingsStore::OPTION,
			array(
				'type'              => 'object',
				'description'       => __( 'SapphireIT Editorial Workflow settings.', 'sapphireit-editorial-workflow' ),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->settings->defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			self::SECTION,
			__( 'Workflow', 'sapphireit-editorial-workflow' ),
			array( $this, 'render_section' ),
			self::MENU_SLUG
		);

		add_settings_field(
			'sit_cwm_post_types',
			__( 'Enabled content types', 'sapphireit-editorial-workflow' ),
			array( $this, 'render_post_types_field' ),
			self::MENU_SLUG,
			self::SECTION
		);

		add_settings_field(
			'sit_cwm_delete_data_on_uninstall',
			__( 'Uninstall', 'sapphireit-editorial-workflow' ),
			array( $this, 'render_uninstall_field' ),
			self::MENU_SLUG,
			self::SECTION
		);
	}

	/**
	 * Sanitizes the whole option.
	 *
	 * Post types are intersected with the real list, the boolean is cast, the
	 * result is merged over the defaults and unknown keys are dropped. Invalid
	 * shapes never fatal: a scalar where an array is expected keeps the stored
	 * value and reports a settings error.
	 *
	 * Also runs for programmatic `update_option()` calls while the setting is
	 * registered, so it must accept an already clean settings array unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Submitted value.
	 * @return array{post_types: string[], delete_data_on_uninstall: bool}
	 */
	public function sanitize( $input ): array {
		$current = $this->settings->all();

		// With every checkbox cleared the browser submits nothing for the option.
		if ( null === $input ) {
			$input = array();
		}

		if ( ! is_array( $input ) ) {
			$this->add_error( 'sit_cwm_invalid_settings', __( 'The settings were not saved because the submitted data was invalid.', 'sapphireit-editorial-workflow' ) );

			return $current;
		}

		$clean = $this->settings->defaults();

		$clean['post_types']               = $this->sanitize_post_types( $input['post_types'] ?? array(), $current['post_types'] );
		$clean['delete_data_on_uninstall'] = isset( $input['delete_data_on_uninstall'] )
			&& is_scalar( $input['delete_data_on_uninstall'] )
			&& rest_sanitize_boolean( $input['delete_data_on_uninstall'] );

		return $clean;
	}

	/**
	 * Prints the settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->permissions->can_manage() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'sapphireit-editorial-workflow' ), 403 );
		}

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Editorial Workflow Settings', 'sapphireit-editorial-workflow' ) );

		// Only options-general.php screens print settings errors automatically.
		settings_errors();

		printf( '<form action="%s" method="post">', esc_url( admin_url( 'options.php' ) ) );
		settings_fields( self::OPTION_GROUP );
		do_settings_sections( self::MENU_SLUG );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Prints the section introduction.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_section(): void {
		printf(
			'<p>%s</p>',
			esc_html__( 'Choose which content goes through the editorial workflow.', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * Prints the enabled post types checkboxes.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_post_types_field(): void {
		$enabled = (array) $this->settings->get( 'post_types', array() );

		printf(
			'<fieldset><legend class="screen-reader-text">%s</legend>',
			esc_html__( 'Enabled content types', 'sapphireit-editorial-workflow' )
		);

		$unsupported = $this->settings->unsupported_enabled_post_types();

		foreach ( $this->settings->selectable_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( null === $object ) {
				continue;
			}

			printf(
				'<label><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s</label>%5$s<br />',
				esc_attr( SettingsStore::OPTION . '[post_types][]' ),
				esc_attr( $post_type ),
				checked( in_array( $post_type, $enabled, true ), true, false ),
				esc_html( $object->labels->name ),
				wp_kses_post( $this->post_type_note( in_array( $post_type, $unsupported, true ) ) )
			);
		}

		echo '</fieldset>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Turning a content type off stops the workflow for it. Its workflow statuses, reviewers, due dates and activity history are kept, and come back if you turn it on again.', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * Prints the delete-data-on-uninstall checkbox and its warning.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_uninstall_field(): void {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( SettingsStore::OPTION . '[delete_data_on_uninstall]' ),
			checked( (bool) $this->settings->get( 'delete_data_on_uninstall', false ), true, false ),
			esc_html__( 'Delete all workflow data when the plugin is deleted', 'sapphireit-editorial-workflow' )
		);

		printf(
			'<p class="description"><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Warning:', 'sapphireit-editorial-workflow' ),
			esc_html__( 'this permanently removes the activity history, workflow comments, statuses, reviewers, due dates and the plugin’s capabilities. It cannot be undone. Deactivating the plugin never deletes data.', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * Adds the contextual help tab to the settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_help_tab(): void {
		$screen = get_current_screen();

		if ( null === $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => self::HELP_TAB_ID,
				'title'   => __( 'Workflow', 'sapphireit-editorial-workflow' ),
				'content' => $this->help_content(),
			)
		);
	}

	/**
	 * Help tab markup: the status flow and who can do what by default (D5).
	 *
	 * @since 1.0.0
	 *
	 * @return string Escaped HTML.
	 */
	public function help_content(): string {
		$rules = array(
			__( 'Draft, Writing and Review: users who can change the workflow (by default administrators, editors, authors and contributors).', 'sapphireit-editorial-workflow' ),
			__( 'Needs Changes: users who can review content (by default administrators and editors).', 'sapphireit-editorial-workflow' ),
			__( 'Approved: users who can approve content (by default administrators and editors).', 'sapphireit-editorial-workflow' ),
			__( 'Published: users who can approve content and can also publish that post.', 'sapphireit-editorial-workflow' ),
			__( 'Reviewers and due dates: users who can assign reviewers (by default administrators and editors).', 'sapphireit-editorial-workflow' ),
		);

		$html  = '<p>' . esc_html__( 'Draft → Writing → Review → Approved → Published. A reviewer can send content in Review back to Needs Changes, which returns to Writing. Published content starts a new cycle in Writing.', 'sapphireit-editorial-workflow' ) . '</p>';
		$html .= '<ul>';

		foreach ( $rules as $rule ) {
			$html .= '<li>' . esc_html( $rule ) . '</li>';
		}

		$html .= '</ul>';
		$html .= '<p>' . esc_html__( 'Every action also requires permission to edit that piece of content. See WORKFLOW.md in the plugin folder for the full reference.', 'sapphireit-editorial-workflow' ) . '</p>';

		return $html;
	}

	/**
	 * Note printed after a post type's checkbox label, if any.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $unsupported Whether it is enabled but no longer offered.
	 * @return string Escaped HTML, or `''` when there is nothing to say.
	 */
	private function post_type_note( bool $unsupported ): string {
		if ( ! $unsupported ) {
			return '';
		}

		$note = __( '(not supported: kept because it is already enabled; once turned off it will not be offered again)', 'sapphireit-editorial-workflow' );

		return sprintf( ' <span class="description">%s</span>', esc_html( $note ) );
	}

	/**
	 * Intersects submitted post types with the post types that may be enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed    $value   Submitted value.
	 * @param string[] $current Stored post types, kept when the value is not a list.
	 * @return string[]
	 */
	private function sanitize_post_types( $value, array $current ): array {
		if ( ! is_array( $value ) ) {
			$this->add_error( 'sit_cwm_invalid_post_types', __( 'The enabled content types were not changed because the submitted value was invalid.', 'sapphireit-editorial-workflow' ) );

			return $current;
		}

		$available = $this->settings->selectable_post_types();
		$types     = array();
		$rejected  = false;

		foreach ( $value as $post_type ) {
			$slug = is_string( $post_type ) ? sanitize_key( $post_type ) : '';

			if ( '' !== $slug && isset( $available[ $slug ] ) ) {
				$types[ $slug ] = $slug;
			} else {
				$rejected = true;
			}
		}

		if ( $rejected ) {
			$this->add_error( 'sit_cwm_unknown_post_types', __( 'Content types that do not exist were ignored.', 'sapphireit-editorial-workflow' ) );
		}

		return array_values( $types );
	}

	/**
	 * Reports a settings error on the settings screen.
	 *
	 * `add_settings_error()` is only loaded in wp-admin; outside it the
	 * sanitized value is still returned, just without a message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code    Error code.
	 * @param string $message Translated message.
	 * @return void
	 */
	private function add_error( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( SettingsStore::OPTION, $code, $message );
		}
	}
}
