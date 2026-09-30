<?php
/**
 * Plugin Name:       SapphireIT Editorial Workflow
 * Plugin URI:        https://github.com/rshalihin/sapphireit-editorial-workflow
 * Description:       Adds an editorial approval workflow (Draft, Writing, Review, Needs Changes, Approved, Published) with reviewers, due dates, comments and activity history to posts, pages and custom post types.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            SapphireIT
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sapphireit-editorial-workflow
 * Domain Path:       /languages
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SIT_CWM_VERSION' ) ) {
	/**
	 * Plugin version. Kept in lockstep with the header and package.json.
	 *
	 * @since 1.0.0
	 */
	define( 'SIT_CWM_VERSION', '1.0.0' );
}

if ( ! defined( 'SIT_CWM_PLUGIN_FILE' ) ) {
	/**
	 * Absolute path to the main plugin file.
	 *
	 * @since 1.0.0
	 */
	define( 'SIT_CWM_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'SIT_CWM_PLUGIN_DIR' ) ) {
	/**
	 * Absolute path to the plugin directory, with trailing slash.
	 *
	 * @since 1.0.0
	 */
	define( 'SIT_CWM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'SIT_CWM_PLUGIN_URL' ) ) {
	/**
	 * URL to the plugin directory, with trailing slash.
	 *
	 * @since 1.0.0
	 */
	define( 'SIT_CWM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'SIT_CWM_DB_VERSION' ) ) {
	/**
	 * Schema version of the custom tables, compared against the
	 * `sit_cwm_db_version` option to trigger upgrades.
	 *
	 * @since 1.0.0
	 */
	define( 'SIT_CWM_DB_VERSION', '1.0.0' );
}

// Bail before loading any PHP 7.4+ code. Everything above parses on old PHP.
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action( 'admin_notices', 'sit_cwm_php_version_notice' );
	return;
}

if ( file_exists( SIT_CWM_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SIT_CWM_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register( 'sit_cwm_autoload' );
}

register_activation_hook( __FILE__, array( \Sit_Cwm\Core\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Sit_Cwm\Core\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', 'sit_cwm_boot', 10 );

/**
 * Builds the plugin and boots it.
 *
 * @since 1.0.0
 *
 * @return void
 */
function sit_cwm_boot() {
	$plugin = new \Sit_Cwm\Core\Plugin( new \Sit_Cwm\Core\Container() );
	$plugin->boot();
}

/**
 * Renders the "PHP too old" admin notice.
 *
 * @since 1.0.0
 *
 * @return void
 */
function sit_cwm_php_version_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: Required PHP version, 2: Current PHP version. */
				__( 'SapphireIT Editorial Workflow requires PHP %1$s or higher. This site runs PHP %2$s, so the plugin is not loaded.', 'sapphireit-editorial-workflow' ),
				'7.4',
				PHP_VERSION
			)
		)
	);
}

/**
 * PSR-4 fallback autoloader for `Sit_Cwm\` classes, used when the plugin runs
 * from a clone or release zip without `composer install`.
 *
 * Mirrors `composer.json`: `Sit_Cwm\Admin\` lives in `admin/`, everything
 * else in `includes/`.
 *
 * @since 1.0.0
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function sit_cwm_autoload( $class_name ) {
	$map = array(
		'Sit_Cwm\\Admin\\' => 'admin/',
		'Sit_Cwm\\'        => 'includes/',
	);

	foreach ( $map as $prefix => $dir ) {
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			continue;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = SIT_CWM_PLUGIN_DIR . $dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}

		return;
	}
}
