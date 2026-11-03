<?php
/**
 * Uninstall cleanup.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Core;

use Sit_Cwm\Workflow\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes plugin data when the plugin is deleted, only when the site owner
 * opted in via `sit_cwm_settings['delete_data_on_uninstall']`. Deactivation
 * never removes data.
 *
 * Disabling a post type in the settings never deletes anything either: its
 * workflow meta and activity rows stay in place until this cleanup runs with
 * the opt-in set, which then removes them for every post type.
 *
 * Hooked to the Freemius SDK's `after_uninstall` action (F18), which replaced
 * `uninstall.php` in 1.2.0. WordPress includes the main plugin file before it
 * fires a registered uninstall hook, so the autoloader exists, but
 * `plugins_loaded` has not fired: this class must not use the container or
 * `Plugin`.
 *
 * @internal
 * @since 1.2.0
 */
final class Uninstaller {

	/**
	 * Runs the cleanup on the current site, or on every site of a network.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! is_multisite() ) {
			self::uninstall_site();
			return;
		}

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			self::uninstall_site();
			restore_current_blog();
		}
	}

	/**
	 * Removes all plugin data from the current site, if the site opted in to
	 * data deletion.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private static function uninstall_site(): void {
		global $wpdb;

		$settings = get_option( 'sit_cwm_settings', array() );

		if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
			return;
		}

		// Activity table (D8) and the `sit_cwm_db_version` option.
		( new Database() )->drop();

		// All `_sit_cwm_*` post meta (D6).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk meta removal on uninstall; no API equivalent for a key prefix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_sit_cwm_' ) . '%'
			)
		);

		// Custom capabilities (D5), from every role.
		Capabilities::remove_caps();

		delete_option( 'sit_cwm_settings' );
		delete_option( Capabilities::REVISION_OPTION );

		wp_cache_flush();
	}
}
