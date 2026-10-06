<?php
/**
 * Uninstall routine for SapphireIT Editorial Workflow.
 *
 * Removes plugin data only when the site owner opted in via
 * `sit_cwm_settings['delete_data_on_uninstall']`. Deactivation never removes
 * data; this file runs only when the plugin is deleted.
 *
 * Disabling a post type in the settings never deletes anything either: its
 * workflow meta and activity rows stay in place until this routine runs with
 * the opt-in set, which then removes them for every post type.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The main plugin file (and its autoloader) is not loaded during uninstall.
require_once __DIR__ . '/includes/Core/Database.php';
require_once __DIR__ . '/includes/Workflow/Capabilities.php';

/**
 * Removes all SapphireIT Editorial Workflow data from the current site, if the
 * site opted in to data deletion.
 *
 * @since 1.0.0
 *
 * @return void
 */
function sit_cwm_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'sit_cwm_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	// Activity table (D8) and the `sit_cwm_db_version` option.
	( new \Sit_Cwm\Core\Database() )->drop();

	// All `_sit_cwm_*` post meta (D6).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk meta removal on uninstall; no API equivalent for a key prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( '_sit_cwm_' ) . '%'
		)
	);

	// Custom capabilities (D5), from every role.
	\Sit_Cwm\Workflow\Capabilities::remove_caps();

	delete_option( 'sit_cwm_settings' );

	wp_cache_flush();
}

if ( is_multisite() ) {
	$sit_cwm_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $sit_cwm_site_ids as $sit_cwm_site_id ) {
		switch_to_blog( $sit_cwm_site_id );
		sit_cwm_uninstall_site();
		restore_current_blog();
	}
} else {
	sit_cwm_uninstall_site();
}
