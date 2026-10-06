<?php
/**
 * Plugin deactivation routine.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on plugin deactivation.
 *
 * Never drops tables, deletes meta or removes capabilities; that belongs to
 * `uninstall.php` only.
 *
 * @since 1.0.0
 */
final class Deactivator {

	/**
	 * WP-Cron hooks scheduled by the plugin. None in v1.0 yet.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const CRON_HOOKS = array();

	/**
	 * Deactivation callback registered via `register_deactivation_hook()`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		flush_rewrite_rules();
	}
}
