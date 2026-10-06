<?php
/**
 * Installs a WordPress core checkout and a test database for the PHP
 * integration suite, without Docker or wp-env.
 *
 * Standalone CLI script: WordPress is not loaded, so ext/curl, ext/zip and
 * ext/mysqli are used directly. It is the local mirror of the "Download
 * WordPress" and "Write wp-tests-config.php" steps in
 * `.github/workflows/ci.yml`.
 *
 * Usage, from the plugin root (Laragon PHP, see DEVELOPMENT.md):
 *
 *     php bin/install-wp-tests.php
 *     php bin/install-wp-tests.php --wp=6.9 --db=sit_cwm_tests --user=root --host=127.0.0.1
 *
 * Options:
 *   --wp=X.Y[.Z]  WordPress version, or `latest`. Default: the major.minor of
 *                 the installed wp-phpunit/wp-phpunit, because the test library
 *                 and core must come from the same release.
 *   --dir=PATH    Where core checkouts are cached. Default: %LOCALAPPDATA%
 *                 (or the system temp dir) + /sit-cwm-wp-tests.
 *   --db, --user, --pass, --host, --prefix  Database settings.
 *   --force       Re-download core even if that version is already cached.
 *
 * WARNING: the WordPress test suite DROPS ALL TABLES in --db on every run.
 * Never point it at a site database.
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
		'wp'     => '',
		'dir'    => '',
		'db'     => 'sit_cwm_tests',
		'user'   => 'root',
		'pass'   => '',
		'host'   => '127.0.0.1',
		'prefix' => 'wptests_',
		'force'  => false,
	)
);

sit_cwm_require_extensions( array( 'curl', 'zip', 'mysqli' ) );

$sit_cwm_version = '' !== $sit_cwm_opts['wp'] ? $sit_cwm_opts['wp'] : sit_cwm_default_wp_version( $sit_cwm_root );
$sit_cwm_version = sit_cwm_resolve_wp_version( $sit_cwm_version );

$sit_cwm_cache = '' !== $sit_cwm_opts['dir']
	? rtrim( str_replace( '\\', '/', $sit_cwm_opts['dir'] ), '/' )
	: sit_cwm_default_cache_dir();

sit_cwm_say( 'WordPress version : ' . $sit_cwm_version );
sit_cwm_say( 'Core checkout     : ' . $sit_cwm_cache . '/wordpress-' . $sit_cwm_version );
sit_cwm_say( 'Database          : ' . $sit_cwm_opts['db'] . ' on ' . $sit_cwm_opts['host'] );
sit_cwm_say( '' );

$sit_cwm_abspath = sit_cwm_install_core( $sit_cwm_version, $sit_cwm_cache, (bool) $sit_cwm_opts['force'] );

sit_cwm_create_database(
	$sit_cwm_opts['host'],
	$sit_cwm_opts['user'],
	$sit_cwm_opts['pass'],
	$sit_cwm_opts['db']
);

$sit_cwm_config = $sit_cwm_root . '/tests/php/wp-tests-config.php';

sit_cwm_write_tests_config( $sit_cwm_config, $sit_cwm_abspath, $sit_cwm_opts );

sit_cwm_say( '' );
sit_cwm_say( 'Wrote ' . $sit_cwm_config );
sit_cwm_say( '' );
sit_cwm_say( 'Run the integration suite with:' );
sit_cwm_say( '    composer test:integration' );
