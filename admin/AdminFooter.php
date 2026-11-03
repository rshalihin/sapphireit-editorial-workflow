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
 * Replaces core's footer text and version on the plugin's screens.
 *
 * The left side asks for a WordPress.org review; the right side shows the
 * plugin's version instead of WordPress's. Every other admin screen keeps
 * core's footer untouched.
 *
 * Screens are matched by `AdminShell::is_plugin_screen()` (the requested page
 * slug against the tab allow-list), so the check survives a translated menu
 * title, which changes the screen id.
 *
 * @since 1.1.0
 * @internal
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
	 * Shared admin header, which knows the plugin screens.
	 *
	 * @since 1.2.0
	 * @var AdminShell
	 */
	private $shell;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Takes the shell instead of the dashboard and settings pages.
	 *
	 * @param AdminShell $shell Shared admin header.
	 */
	public function __construct( AdminShell $shell ) {
		$this->shell = $shell;
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
	 * Whether the current admin screen is one of the plugin's own screens.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Asks the shell, so every tab (Getting Started included) matches
	 *              and pages others add under the menu (Freemius's Account) do not.
	 *
	 * @return bool
	 */
	private function is_plugin_screen(): bool {
		return $this->shell->is_plugin_screen();
	}
}
