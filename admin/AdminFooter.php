<?php
/**
 * The admin footer on the plugin's screens.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Admin;

use Sit_Cwm\Core\Interfaces\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces core's footer text and version on the dashboard and settings screens.
 *
 * The left side asks for a WordPress.org review; the right side shows the
 * plugin's version instead of WordPress's. Every other admin screen keeps
 * core's footer untouched.
 *
 * Screens are matched against the hook suffixes the pages registered, so the
 * check survives a translated menu title (which changes the settings screen id).
 *
 * @since 1.1.0
 */
final class AdminFooter implements Bootable {

	/**
	 * The plugin's WordPress.org review form, with no rating preselected.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const REVIEW_URL = 'https://wordpress.org/support/plugin/sapphireit-editorial-workflow/reviews/#new-post';

	/**
	 * Priority of the `update_footer` filter.
	 *
	 * Core adds `core_update_footer` at the default priority 10, so this runs after it.
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const VERSION_PRIORITY = 11;

	/**
	 * Dashboard screen.
	 *
	 * @since 1.1.0
	 * @var Dashboard
	 */
	private $dashboard;

	/**
	 * Settings screen.
	 *
	 * @since 1.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param Dashboard $dashboard Dashboard screen.
	 * @param Settings  $settings  Settings screen.
	 */
	public function __construct( Dashboard $dashboard, Settings $settings ) {
		$this->dashboard = $dashboard;
		$this->settings  = $settings;
	}

	/**
	 * Attaches the footer text and version filters.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'admin_footer_text', array( $this, 'filter_footer_text' ) );
		add_filter( 'update_footer', array( $this, 'filter_version' ), self::VERSION_PRIORITY );
	}

	/**
	 * The review request, on the plugin's screens only.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text Footer text from core or an earlier filter.
	 * @return string
	 */
	public function filter_footer_text( $text ) {
		if ( ! $this->is_plugin_screen() ) {
			return $text;
		}

		$link = sprintf(
			'<a href="%1$s" class="sit-cwm-rating-link" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
			esc_url( self::REVIEW_URL ),
			esc_html__( 'review', 'sapphireit-editorial-workflow' ),
			esc_html__( '(opens in a new tab)', 'sapphireit-editorial-workflow' )
		);

		return sprintf(
			/* translators: 1: plugin name, 2: link to the WordPress.org review form, reading "review". */
			esc_html__( 'If %1$s helps your editorial team, please consider leaving a %2$s on WordPress.org. Thank you!', 'sapphireit-editorial-workflow' ),
			'<strong>' . esc_html__( 'SapphireIT Editorial Workflow', 'sapphireit-editorial-workflow' ) . '</strong>',
			$link
		);
	}

	/**
	 * The plugin's version, on the plugin's screens only.
	 *
	 * @since 1.1.0
	 *
	 * @param string $content Footer version text from core or an earlier filter.
	 * @return string
	 */
	public function filter_version( $content ) {
		if ( ! $this->is_plugin_screen() ) {
			return $content;
		}

		return sprintf(
			/* translators: %s: plugin version number. */
			esc_html__( 'Version %s', 'sapphireit-editorial-workflow' ),
			esc_html( SIT_CWM_VERSION )
		);
	}

	/**
	 * Whether the current admin screen is the dashboard or the settings page.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	private function is_plugin_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen ) {
			return false;
		}

		$suffixes = array_filter( array( $this->dashboard->hook_suffix(), $this->settings->hook_suffix() ) );

		return in_array( $screen->id, $suffixes, true );
	}
}
