<?php
/**
 * PHPUnit bootstrap.
 *
 * Unit tests run without WordPress. The WordPress test library (integration
 * suite) is loaded only when the environment explicitly asks for it:
 *
 * - `WP_TESTS_DIR` points at a WordPress test library (e.g. inside wp-env), or
 * - `WP_PHPUNIT__TESTS_CONFIG` points at a wp-tests-config.php, in which case
 *   the bundled wp-phpunit library is used (`composer test:integration`).
 *
 * `WP_PHPUNIT__DIR` alone is not a signal: wp-phpunit's Composer-autoloaded
 * file sets it unconditionally before this bootstrap runs.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

$sit_cwm_root        = dirname( __DIR__, 2 );
$sit_cwm_tests_dir   = (string) getenv( 'WP_TESTS_DIR' );
$sit_cwm_tests_cfg   = (string) getenv( 'WP_PHPUNIT__TESTS_CONFIG' );
$sit_cwm_integration = ( '' !== $sit_cwm_tests_dir || '' !== $sit_cwm_tests_cfg );

if ( ! $sit_cwm_integration ) {
	if ( ! defined( 'ABSPATH' ) ) {
		// Unit mode: satisfy the ABSPATH guard at the top of every plugin file.
		define( 'ABSPATH', $sit_cwm_root . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core constant stub for unit tests.
	}

	require_once $sit_cwm_root . '/vendor/autoload.php';
	require_once __DIR__ . '/stubs/wordpress.php';
	return;
}

require_once $sit_cwm_root . '/vendor/autoload.php';

if ( '' === $sit_cwm_tests_dir ) {
	$sit_cwm_tests_dir = $sit_cwm_root . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $sit_cwm_root . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name required by the WP test library.
}

require_once $sit_cwm_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $sit_cwm_root ) {
		require $sit_cwm_root . '/sapphireit-editorial-workflow.php';
	}
);

require $sit_cwm_tests_dir . '/includes/bootstrap.php';
