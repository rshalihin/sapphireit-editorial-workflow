<?php
/**
 * Sample WordPress test-suite config for local (non wp-env) integration runs.
 *
 * Copy to `tests/php/wp-tests-config.php` (git-ignored) and adjust.
 * WARNING: the test suite DROPS ALL TABLES in this database on every run.
 * Never point it at your development site's database.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant names are dictated by the WP test library.

// Path to a WordPress core checkout (the Laragon site works; it is only read).
define( 'ABSPATH', dirname( __DIR__, 5 ) . '/' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

// A dedicated, disposable database.
define( 'DB_NAME', 'sit_cwm_tests' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', 'localhost' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Required name.

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
