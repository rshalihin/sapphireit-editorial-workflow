<?php
/**
 * Prepares a local WordPress site (Laragon, MAMP, a plain Apache vhost) for the
 * Playwright end-to-end suite, so `npm run test:e2e` does not need wp-env or
 * Docker.
 *
 * It loads the target site through `wp-load.php` and then: activates this
 * plugin, switches plain permalinks to post-name permalinks, and makes sure the
 * administrator account the specs log in as exists with a known password.
 *
 * With --install it first provisions that site: it downloads a WordPress core
 * checkout, creates its database, writes `wp-config.php` and installs
 * WordPress, so the destructive specs get a scratch site of their own instead
 * of your development site.
 *
 * Usage, from the plugin root:
 *
 *     php bin/setup-e2e-site.php
 *     php bin/setup-e2e-site.php --install --path=G:/laragon/www/cwm-e2e --url=http://cwm-e2e.test
 *
 * Options:
 *   --path=PATH        WordPress root. Default: the site this plugin lives in.
 *   --url=URL          Expected site URL. Reported when it differs from the
 *                      stored `siteurl`; only changed with --fix-url.
 *   --fix-url          Write --url into the `siteurl` and `home` options.
 *   --install          Provision a new site at --path (requires --url).
 *   --wp=X.Y[.Z]       Core version for --install. Default: latest.
 *   --db, --db-user, --db-pass, --db-host, --prefix
 *                      Database settings for --install.
 *   --title=TITLE      Site title for --install.
 *   --admin-user=NAME  Administrator the specs log in as. Default: admin.
 *   --admin-pass=PASS  Its password. Default: password.
 *   --admin-email=MAIL Email used when the account has to be created.
 *   --reset-password   Reset an existing account's password to --admin-pass.
 *
 * WARNING: the end-to-end specs call `deleteAllPosts()` and `deleteAllUsers()`.
 * Point them at a scratch site, never at a site whose content you care about.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/wp-test-env.php';

$sit_cwm_root = dirname( __DIR__ );

$sit_cwm_opts = sit_cwm_cli_options(
	$argv,
	array(
		'path'           => dirname( $sit_cwm_root, 3 ),
		'url'            => '',
		'fix-url'        => false,
		'admin-user'     => 'admin',
		'admin-pass'     => 'password',
		'admin-email'    => 'admin@example.org',
		'reset-password' => false,
		'install'        => false,
		'wp'             => 'latest',
		'db'             => 'cwm_e2e',
		'db-user'        => 'root',
		'db-pass'        => '',
		'db-host'        => '127.0.0.1',
		'prefix'         => 'wp_',
		'title'          => 'CWM end-to-end',
	)
);

$sit_cwm_path = rtrim( str_replace( '\\', '/', $sit_cwm_opts['path'] ), '/' );
$sit_cwm_url  = rtrim( (string) $sit_cwm_opts['url'], '/' );

if ( $sit_cwm_opts['install'] ) {
	if ( '' === $sit_cwm_url ) {
		sit_cwm_fail( '--install needs --url, the address the site will be served at (e.g. http://cwm-e2e.test).' );
	}

	sit_cwm_require_extensions( array( 'curl', 'zip', 'mysqli' ) );

	// 0a. Core files.
	if ( is_file( $sit_cwm_path . '/wp-load.php' ) ) {
		sit_cwm_say( 'WordPress already present in ' . $sit_cwm_path . ', keeping it.' );
	} else {
		$sit_cwm_source = sit_cwm_install_core(
			sit_cwm_resolve_wp_version( (string) $sit_cwm_opts['wp'] ),
			sit_cwm_default_cache_dir(),
			false
		);

		sit_cwm_say( 'Copying core to ' . $sit_cwm_path . ' ...' );
		sit_cwm_copy_tree( rtrim( $sit_cwm_source, '/' ), $sit_cwm_path );
	}

	// 0b. Database and configuration.
	sit_cwm_create_database(
		$sit_cwm_opts['db-host'],
		$sit_cwm_opts['db-user'],
		$sit_cwm_opts['db-pass'],
		$sit_cwm_opts['db']
	);

	if ( is_file( $sit_cwm_path . '/wp-config.php' ) ) {
		sit_cwm_say( 'wp-config.php already exists, keeping it.' );
	} else {
		$sit_cwm_opts['url'] = $sit_cwm_url;
		sit_cwm_write_wp_config( $sit_cwm_path, $sit_cwm_opts );
		sit_cwm_say( 'Wrote ' . $sit_cwm_path . '/wp-config.php.' );
	}

	// 0c. The plugin itself, linked rather than copied so edits are picked up.
	$sit_cwm_link = $sit_cwm_path . '/wp-content/plugins/' . basename( $sit_cwm_root );

	if ( ! is_dir( $sit_cwm_link ) ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unprivileged Windows shells cannot symlink; handled below.
		if ( @symlink( $sit_cwm_root, $sit_cwm_link ) ) {
			sit_cwm_say( 'Linked the plugin into ' . $sit_cwm_link . '.' );
		} else {
			sit_cwm_say( '' );
			sit_cwm_fail(
				'Cannot link the plugin into the new site (Windows needs Developer Mode' . PHP_EOL
				. 'or an elevated shell to create symlinks). Create a junction instead --' . PHP_EOL
				. 'no elevation required -- and run this command again:' . PHP_EOL . PHP_EOL
				. '    New-Item -ItemType Junction -Path "' . str_replace( '/', '\\', $sit_cwm_link ) . '"'
				. ' -Target "' . str_replace( '/', '\\', $sit_cwm_root ) . '"'
			);
		}
	}

	// Lets wp-settings.php boot against a database that has no tables yet.
	define( 'WP_INSTALLING', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant.
}

if ( ! is_file( $sit_cwm_path . '/wp-load.php' ) ) {
	sit_cwm_fail( 'No wp-load.php in ' . $sit_cwm_path . '. Pass --path=<WordPress root>, or --install to create it.' );
}

define( 'WP_USE_THEMES', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant.

require_once $sit_cwm_path . '/wp-load.php';

if ( ! is_blog_installed() ) {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sit_cwm_installed = wp_install(
		(string) $sit_cwm_opts['title'],
		(string) $sit_cwm_opts['admin-user'],
		(string) $sit_cwm_opts['admin-email'],
		true,
		'',
		(string) $sit_cwm_opts['admin-pass']
	);

	if ( empty( $sit_cwm_installed['user_id'] ) ) {
		sit_cwm_fail( 'WordPress could not be installed.' );
	}

	// Back to a normal request now that the tables exist.
	wp_installing( false );

	sit_cwm_say( 'Installed WordPress at ' . $sit_cwm_url . '.' );
	sit_cwm_say( '' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

sit_cwm_say( 'Site      : ' . site_url() );
sit_cwm_say( 'WordPress : ' . get_bloginfo( 'version' ) );
sit_cwm_say( '' );

// 1. Site URL. WP_SITEURL in wp-config.php, when defined, wins over the option.
if ( '' !== $sit_cwm_url && rtrim( site_url(), '/' ) !== $sit_cwm_url ) {
	if ( defined( 'WP_SITEURL' ) ) {
		sit_cwm_say( 'Note: this site is served at ' . site_url() . ', not ' . $sit_cwm_url . '.' );
		sit_cwm_say( '      WP_SITEURL in wp-config.php decides that; edit it there.' );
	} elseif ( $sit_cwm_opts['fix-url'] ) {
		update_option( 'siteurl', $sit_cwm_url );
		update_option( 'home', $sit_cwm_url );
		sit_cwm_say( 'Site URL set to ' . $sit_cwm_url . '.' );
	} else {
		sit_cwm_say( 'Note: siteurl is ' . site_url() . ', not ' . $sit_cwm_url . '.' );
		sit_cwm_say( '      Playwright follows redirects to the stored URL; pass --fix-url to change it.' );
	}
}

// 2. The plugin has to be active for the sidebar, dashboard and REST routes.
// Its folder may be a symlink or junction, so the path is built from the
// target site's plugin directory rather than from this file's real location.
$sit_cwm_plugin = basename( $sit_cwm_root ) . '/sapphireit-editorial-workflow.php';

if ( ! is_file( WP_PLUGIN_DIR . '/' . $sit_cwm_plugin ) ) {
	sit_cwm_fail(
		'The plugin is not in this site\'s plugins directory (' . WP_PLUGIN_DIR . ').' . PHP_EOL
		. 'Link it there first, then run this again.'
	);
}

if ( is_plugin_active( $sit_cwm_plugin ) ) {
	sit_cwm_say( 'Plugin already active (' . $sit_cwm_plugin . ').' );
} else {
	$sit_cwm_activated = activate_plugin( $sit_cwm_plugin );

	if ( is_wp_error( $sit_cwm_activated ) ) {
		sit_cwm_fail( 'Cannot activate ' . $sit_cwm_plugin . ': ' . $sit_cwm_activated->get_error_message() );
	}

	sit_cwm_say( 'Activated ' . $sit_cwm_plugin . '.' );
}

// 3. Pretty permalinks, so /wp-json/ routes resolve the way the specs expect.
require_once ABSPATH . 'wp-admin/includes/misc.php';

global $wp_rewrite;

if ( '' === (string) get_option( 'permalink_structure' ) ) {
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
	$wp_rewrite->flush_rules( false );

	sit_cwm_say( 'Permalinks set to /%postname%/.' );
} else {
	sit_cwm_say( 'Permalinks already pretty (' . get_option( 'permalink_structure' ) . ').' );
}

/*
 * WordPress only writes .htaccess when it can see mod_rewrite, which it never
 * can from the CLI, so the rules are written by hand. Apache only; on nginx,
 * add the usual try_files rule to the server block instead.
 */
$sit_cwm_htaccess = $sit_cwm_path . '/.htaccess';
$sit_cwm_current  = is_file( $sit_cwm_htaccess ) ? (string) file_get_contents( $sit_cwm_htaccess ) : '';

if ( false === strpos( $sit_cwm_current, '# BEGIN WordPress' ) ) {
	insert_with_markers( $sit_cwm_htaccess, 'WordPress', explode( "\n", $wp_rewrite->mod_rewrite_rules() ) );
	sit_cwm_say( 'Wrote the WordPress rewrite rules to .htaccess.' );
}

// 4. The administrator the specs authenticate as.
$sit_cwm_admin = get_user_by( 'login', $sit_cwm_opts['admin-user'] );

if ( ! $sit_cwm_admin ) {
	$sit_cwm_id = wp_insert_user(
		array(
			'user_login' => $sit_cwm_opts['admin-user'],
			'user_pass'  => $sit_cwm_opts['admin-pass'],
			'user_email' => $sit_cwm_opts['admin-email'],
			'role'       => 'administrator',
		)
	);

	if ( is_wp_error( $sit_cwm_id ) ) {
		sit_cwm_fail( 'Cannot create the administrator: ' . $sit_cwm_id->get_error_message() );
	}

	sit_cwm_say( 'Created administrator "' . $sit_cwm_opts['admin-user'] . '".' );
} elseif ( $sit_cwm_opts['reset-password'] ) {
	wp_set_password( $sit_cwm_opts['admin-pass'], $sit_cwm_admin->ID );
	sit_cwm_say( 'Reset the password of "' . $sit_cwm_opts['admin-user'] . '".' );
} else {
	sit_cwm_say( 'Administrator "' . $sit_cwm_opts['admin-user'] . '" exists (password unchanged).' );
	sit_cwm_say( '      Pass --reset-password if the specs cannot log in.' );
}

// 5. What Playwright needs in its environment.
$sit_cwm_base = rtrim( site_url(), '/' );

sit_cwm_say( '' );
sit_cwm_say( 'Ready. Run the end-to-end suite against this site with:' );
sit_cwm_say( '' );
sit_cwm_say( '    npm run test:e2e:local -- --base-url=' . $sit_cwm_base );
sit_cwm_say( '' );
sit_cwm_say( 'or set the environment yourself (PowerShell):' );
sit_cwm_say( '' );
sit_cwm_say( '    $env:WP_BASE_URL = "' . $sit_cwm_base . '/"' );
sit_cwm_say( '    $env:WP_USERNAME = "' . $sit_cwm_opts['admin-user'] . '"' );
sit_cwm_say( '    $env:WP_PASSWORD = "' . $sit_cwm_opts['admin-pass'] . '"' );
sit_cwm_say( '    npm run test:e2e' );
