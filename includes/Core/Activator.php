<?php
/**
 * Plugin activation routine.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Core;

use Sit_Cwm\Workflow\Capabilities;
use WP_Site;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin activation. Idempotent: safe on every reactivation.
 *
 * @since 1.0.0
 */
final class Activator {

	/**
	 * Activation callback registered via `register_activation_hook()`.
	 *
	 * On a network-wide activation every site is set up; otherwise only the
	 * current one.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ): void {
		if ( ! is_multisite() || ! $network_wide ) {
			self::activate_site();
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::activate_site();
			restore_current_blog();
		}
	}

	/**
	 * Sets up a site created after a network-wide activation.
	 *
	 * Hooked on `wp_initialize_site` after core has created the site's tables.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Site $site The new site.
	 * @return void
	 */
	public static function initialize_site( WP_Site $site ): void {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( plugin_basename( SIT_CWM_PLUGIN_FILE ) ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );
		self::activate_site();
		restore_current_blog();
	}

	/**
	 * Default value of the `sit_cwm_settings` option (D7).
	 *
	 * @since 1.0.0
	 *
	 * @return array{post_types: string[], delete_data_on_uninstall: bool}
	 */
	public static function default_settings(): array {
		return ( new Settings() )->defaults();
	}

	/**
	 * Installs the activity table, records the schema version, seeds settings
	 * and grants role capabilities for the current site.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function activate_site(): void {
		( new Database() )->install();
		( new Settings() )->seed();
		Capabilities::add_caps();

		flush_rewrite_rules();
	}
}
