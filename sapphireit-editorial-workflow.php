<?php
/**
 * Plugin Name:       SapphireIT Editorial Workflow
 * Description:       Adds an editorial approval workflow (Draft, Writing, Review, Needs Changes, Approved, Published) with reviewers, due dates, comments and activity history to posts, pages and custom post types.
 * Version:           1.1.0
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
	define( 'SIT_CWM_VERSION', '1.1.0' );
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

if ( ! function_exists( 'sit_cwm_fs' ) ) {
	/**
	 * Returns the Freemius SDK instance for this plugin, initialising it on
	 * first call. The Pro add-on names this product as its parent.
	 *
	 * Free is WordPress.org compliant: nothing is sent unless an
	 * administrator opts in, and nothing is sold until the Pro launch
	 * release flips `has_addons` / `has_paid_plans` (F17, F18).
	 *
	 * @api
	 * @since 1.2.0
	 *
	 * @return Freemius
	 */
	function sit_cwm_fs() {
		global $sit_cwm_fs;

		if ( ! isset( $sit_cwm_fs ) ) {
			require_once SIT_CWM_PLUGIN_DIR . 'freemius/start.php';

			$sit_cwm_fs = fs_dynamic_init(
				array(
					'id'                  => '43919',
					'slug'                => 'sapphireit-editorial-workflow',
					'type'                => 'plugin',
					'public_key'          => 'pk_aad89809d49fcd9369c18ff7d1561',
					'is_premium'          => false,
					'has_premium_version' => false,
					'has_addons'          => false,
					'has_paid_plans'      => false,
					'is_org_compliant'    => true,
					'opt_in_moderation'   => array(
						'new'       => 100,
						'updates'   => false,
						'localhost' => true,
					),
					// The SDK shows its opt-in in place of the page `slug` names,
					// to anyone who can open that page. Settings is for managers
					// only, so the Dashboard every workflow user works in is never
					// replaced (F18).
					'menu'                => array(
						'slug'        => 'sit-cwm-settings',
						'parent'      => array( 'slug' => 'sit-cwm-dashboard' ),
						'first-path'  => 'admin.php?page=sit-cwm-getting-started',
						'account'     => true,
						'contact'     => false,
						'support'     => false,
						'addons'      => false,
						'pricing'     => false,
						'affiliation' => false,
					),
				)
			);
		}

		return $sit_cwm_fs;
	}

	sit_cwm_fs();

	if ( ! function_exists( 'sit_cwm_fs_pricing_url' ) ) {
		/**
		 * Points the SDK's pricing link at the Dashboard.
		 *
		 * Nothing is sold in 1.2.0 (R10), but the SDK still registers a hidden
		 * pricing page. With a pricing URL set it adds a link instead, and
		 * its page URL redirects here rather than loading the pricing app.
		 * The Pro launch release removes this filter.
		 *
		 * @internal
		 * @since 1.2.0
		 *
		 * @return string Dashboard URL.
		 */
		function sit_cwm_fs_pricing_url() {
			return admin_url( 'admin.php?page=sit-cwm-dashboard' );
		}
	}

	sit_cwm_fs()->add_filter( 'pricing_url', 'sit_cwm_fs_pricing_url' );

	if ( ! function_exists( 'sit_cwm_fs_optin_for_admins_only' ) ) {
		/**
		 * Keeps the opt-in away from managers who cannot answer it.
		 *
		 * Until an administrator opts in or skips, the SDK swaps the Settings
		 * page for its opt-in for anyone who can open Settings, including a
		 * manager without `activate_plugins`, who cannot opt in. For those
		 * users the SDK's menu step is dropped, so Settings renders as usual.
		 * Its other menu items (Account) need `manage_options` anyway.
		 *
		 * Runs just before the SDK's own `admin_menu` callback.
		 *
		 * @internal
		 * @since 1.2.0
		 *
		 * @return void
		 */
		function sit_cwm_fs_optin_for_admins_only() {
			$fs = sit_cwm_fs();

			if ( $fs->is_user_admin() || ! $fs->is_activation_mode() ) {
				return;
			}

			remove_action( 'admin_menu', array( $fs, '_prepare_admin_menu' ), WP_FS__LOWEST_PRIORITY );
		}
	}

	add_action( 'admin_menu', 'sit_cwm_fs_optin_for_admins_only', WP_FS__LOWEST_PRIORITY - 1 );

	/**
	 * Fires once the Freemius SDK is initialised for Free. The Pro add-on
	 * initialises its own SDK instance (with this product as parent) here.
	 *
	 * @api
	 * @since 1.2.0
	 */
	do_action( 'sit_cwm_fs_loaded' );

	sit_cwm_fs()->add_action( 'after_uninstall', array( \Sit_Cwm\Core\Uninstaller::class, 'run' ) );
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
 * Returns the booted plugin's service container, for add-ons.
 *
 * @since 1.2.0
 *
 * @return \Sit_Cwm\Core\Container|null Null before the plugin has booted (plugins_loaded, priority 10).
 */
function sit_cwm_container() {
	$plugin = \Sit_Cwm\Core\Plugin::instance();
	return null === $plugin ? null : $plugin->container();
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
