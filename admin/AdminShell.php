<?php
/**
 * The shared header and tabs on the plugin's admin screens.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Admin;

use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Interfaces\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints the branded header (logo, plugin name, version chip and one tab per
 * Workflow page) and marks plugin screens with the `sit-cwm-admin` body class
 * that the design tokens hang on (F15).
 *
 * The shell reads the global `$plugin_page` instead of taking the pages as
 * dependencies: every page calls the shell, so depending on them would put a
 * cycle in the container. WordPress sets `$plugin_page` from `?page=` before
 * `admin_menu`; it is only ever compared with the allow-list, never printed.
 *
 * Tabs are built from the submenu WordPress already holds: a page is a tab
 * when its slug is in `TAB_SLUGS` or an add-on's `sit_cwm_admin_tabs` entry, it
 * is registered under the Workflow menu and the current user has the capability
 * it was registered with (the same check core uses to print the menu).
 *
 * @since 1.2.0
 * @api
 */
final class AdminShell implements Bootable {

	/**
	 * Build entry with the shell's stylesheet.
	 *
	 * @since 1.2.0
	 * @internal
	 * @var string
	 */
	const ENTRY = 'admin-shell';

	/**
	 * Body class added on plugin screens; the design tokens are declared on it.
	 *
	 * @since 1.2.0
	 * @internal
	 * @var string
	 */
	const BODY_CLASS = 'sit-cwm-admin';

	/**
	 * Core plugin screens, in tab order.
	 *
	 * @since 1.2.0
	 * @internal
	 * @var string[]
	 */
	const TAB_SLUGS = array( Dashboard::MENU_SLUG, GovernanceReportPage::MENU_SLUG, Settings::MENU_SLUG, GettingStartedPage::MENU_SLUG );

	/**
	 * Asset loader.
	 *
	 * @since 1.2.0
	 * @var Assets
	 */
	private $assets;

	/**
	 * Promotion of Pro (the Add-Ons tab and the "Pro" link), if wired.
	 *
	 * @since 1.2.0
	 * @var Promotions|null
	 */
	private $promotions;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @internal
	 *
	 * @param Assets          $assets     Asset loader.
	 * @param Promotions|null $promotions Promotion of Pro; null prints none.
	 */
	public function __construct( Assets $assets, ?Promotions $promotions = null ) {
		$this->assets     = $assets;
		$this->promotions = $promotions;
	}

	/**
	 * Attaches the body class and stylesheet hooks.
	 *
	 * @since 1.2.0
	 * @internal
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'admin_body_class', array( $this, 'filter_body_class' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the current admin page is one of the plugin's own screens.
	 *
	 * Pages other code adds under the Workflow menu (such as Freemius's
	 * Account page) are not plugin screens, unless they join through
	 * `sit_cwm_admin_tabs`.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @return bool
	 */
	public function is_plugin_screen(): bool {
		global $plugin_page;

		return is_string( $plugin_page ) && in_array( $plugin_page, $this->allowed_slugs(), true );
	}

	/**
	 * Adds the token class to the admin body on plugin screens.
	 *
	 * @since 1.2.0
	 * @internal
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function filter_body_class( $classes ) {
		if ( ! $this->is_plugin_screen() ) {
			return $classes;
		}

		return (string) $classes . ' ' . self::BODY_CLASS;
	}

	/**
	 * Enqueues the shell stylesheet on plugin screens only.
	 *
	 * @since 1.2.0
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		$this->assets->enqueue( self::ENTRY );
	}

	/**
	 * Prints the header for the current screen; nothing off plugin screens.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @return void
	 */
	public function render_header(): void {
		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		global $plugin_page;

		echo '<div class="sit-cwm-shell">';

		printf(
			'<div class="sit-cwm-shell__brand"><img class="sit-cwm-shell__logo" src="%1$s" width="32" height="32" alt="" /><span class="sit-cwm-shell__name">%2$s</span><span class="sit-cwm-shell__version">%3$s</span></div>',
			esc_url( SIT_CWM_PLUGIN_URL . 'assets/images/logo.svg' ),
			esc_html__( 'Editorial Workflow', 'sapphireit-editorial-workflow' ),
			esc_html( SIT_CWM_VERSION )
		);

		printf( '<nav class="sit-cwm-shell__tabs" aria-label="%s">', esc_attr__( 'Editorial Workflow', 'sapphireit-editorial-workflow' ) );

		foreach ( $this->tabs() as $slug => $label ) {
			$current = $slug === $plugin_page;

			printf(
				'<a class="sit-cwm-shell__tab%1$s" href="%2$s"%3$s>%4$s</a>',
				$current ? ' is-current' : '',
				esc_url( menu_page_url( $slug, false ) ),
				$current ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}

		echo '</nav><div class="sit-cwm-shell__aside">';

		if ( null !== $this->promotions ) {
			echo $this->promotions->header_link(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- header_link() returns escaped markup.
		}

		echo '</div></div>';
	}

	/**
	 * Builds the title row under the header: the h1 and an optional subtitle
	 * on the left, page actions on the right, then `.wp-header-end` so core
	 * moves admin notices below it.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @param string $title    Page title, plain text (escaped here).
	 * @param string $subtitle Optional subtitle, plain text (escaped here).
	 * @param string $actions  Optional actions markup, already escaped by the caller.
	 * @return string
	 */
	public function page_head( string $title, string $subtitle = '', string $actions = '' ): string {
		$heading = sprintf( '<h1 class="wp-heading-inline">%s</h1>', esc_html( $title ) );

		if ( '' !== $subtitle ) {
			$heading .= sprintf( '<p class="sit-cwm-page-subtitle">%s</p>', esc_html( $subtitle ) );
		}

		return '<div class="sit-cwm-page-head"><div class="sit-cwm-page-title">' . $heading . '</div>'
			. ( '' !== $actions ? '<div class="sit-cwm-page-actions">' . $actions . '</div>' : '' )
			. '</div><hr class="wp-header-end">';
	}

	/**
	 * Tabs the current user can open: the core tabs in `TAB_SLUGS` order, with
	 * add-on tabs between Settings and Getting Started, and, while Pro is on
	 * sale, the Freemius Add-Ons page last.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @return array<string, string> Page slug => label (already translated).
	 */
	public function tabs(): array {
		global $submenu;

		$registered = array();

		foreach ( (array) ( $submenu[ Dashboard::MENU_SLUG ] ?? array() ) as $item ) {
			if ( isset( $item[0], $item[1], $item[2] ) && is_string( $item[2] ) ) {
				$registered[ $item[2] ] = $item;
			}
		}

		$extra = $this->filtered_tabs();
		$tabs  = array();

		foreach ( self::TAB_SLUGS as $slug ) {
			if ( GettingStartedPage::MENU_SLUG === $slug ) {
				foreach ( $extra as $extra_slug => $label ) {
					if ( isset( $registered[ $extra_slug ] ) && current_user_can( $registered[ $extra_slug ][1] ) ) {
						$tabs[ $extra_slug ] = $label;
					}
				}
			}

			if ( ! isset( $registered[ $slug ] ) || ! current_user_can( $registered[ $slug ][1] ) ) {
				continue;
			}

			$tabs[ $slug ] = wp_strip_all_tags( (string) $registered[ $slug ][0] );
		}

		// The Add-Ons page is the SDK's, so it is a tab but not a plugin screen.
		foreach ( null !== $this->promotions ? $this->promotions->addons_tab() : array() as $addons_slug => $label ) {
			if ( isset( $registered[ $addons_slug ] ) && current_user_can( $registered[ $addons_slug ][1] ) && ! isset( $tabs[ $addons_slug ] ) ) {
				$tabs[ $addons_slug ] = $label;
			}
		}

		return $tabs;
	}

	/**
	 * Every slug that counts as a plugin screen.
	 *
	 * @since 1.2.0
	 *
	 * @return string[]
	 */
	private function allowed_slugs(): array {
		return array_merge( self::TAB_SLUGS, array_keys( $this->filtered_tabs() ) );
	}

	/**
	 * Add-on tabs that pass validation. Core slugs are dropped, so a filter
	 * can neither remove nor relabel a core tab.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, string> Page slug => label.
	 */
	private function filtered_tabs(): array {
		/**
		 * Filters the add-on tabs in the shared header.
		 *
		 * A tab shows only when its page is registered under the Workflow menu
		 * and the user has the page's capability. Its slug also makes the page
		 * a plugin screen, so it gets the body class and the design tokens.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, string> $tabs Page slug => label. Slugs must match `^sit-cwm-[a-z0-9-]+$`.
		 */
		$filtered = apply_filters( 'sit_cwm_admin_tabs', array() );
		$tabs     = array();

		foreach ( is_array( $filtered ) ? $filtered : array() as $slug => $label ) {
			if ( ! is_string( $slug ) || ! is_string( $label ) || in_array( $slug, self::TAB_SLUGS, true ) || 1 !== preg_match( '/^sit-cwm-[a-z0-9-]+$/', $slug ) ) {
				continue;
			}

			$label = sanitize_text_field( $label );

			if ( '' !== $label ) {
				$tabs[ $slug ] = $label;
			}
		}

		return $tabs;
	}
}
