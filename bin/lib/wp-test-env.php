<?php
/**
 * Helpers shared by the Docker-free test-environment scripts in `bin/`.
 *
 * Standalone CLI code: WordPress is not loaded here, so ext/curl, ext/zip and
 * ext/mysqli are used directly instead of the WP HTTP API and `$wpdb`.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

/**
 * Prints a line to stdout.
 *
 * @since 1.0.0
 *
 * @param string $message Message.
 * @return void
 */
function sit_cwm_say( string $message ): void {
	fwrite( STDOUT, $message . PHP_EOL );
}

/**
 * Prints a message to stderr and exits with a failure code.
 *
 * @since 1.0.0
 *
 * @param string $message Message.
 * @return void
 */
function sit_cwm_fail( string $message ): void {
	fwrite( STDERR, 'Error: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Parses `--key=value` / `--flag` arguments against a defaults map.
 *
 * @since 1.0.0
 *
 * @param array $argv     Raw arguments, including the script name.
 * @param array $defaults Known options and their default values.
 * @return array Options.
 */
function sit_cwm_cli_options( array $argv, array $defaults ): array {
	$opts = $defaults;

	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( 0 !== strpos( $arg, '--' ) ) {
			sit_cwm_fail( 'Unexpected argument "' . $arg . '". Options use --key=value.' );
		}

		$pair  = explode( '=', substr( $arg, 2 ), 2 );
		$key   = $pair[0];
		$value = isset( $pair[1] ) ? $pair[1] : '1';

		if ( ! array_key_exists( $key, $defaults ) ) {
			sit_cwm_fail( 'Unknown option "--' . $key . '". Known: --' . implode( ', --', array_keys( $defaults ) ) . '.' );
		}

		$opts[ $key ] = is_bool( $defaults[ $key ] ) ? true : $value;
	}

	return $opts;
}

/**
 * Exits unless every required PHP extension is loaded.
 *
 * @since 1.0.0
 *
 * @param string[] $extensions Extension names.
 * @return void
 */
function sit_cwm_require_extensions( array $extensions ): void {
	$missing = array();

	foreach ( $extensions as $extension ) {
		if ( ! extension_loaded( $extension ) ) {
			$missing[] = $extension;
		}
	}

	if ( array() !== $missing ) {
		sit_cwm_fail(
			'PHP ' . PHP_VERSION . ' (' . PHP_BINARY . ') is missing: ' . implode( ', ', $missing ) . '.' . PHP_EOL
			. 'Run this with a Laragon PHP build that has them, for example' . PHP_EOL
			. '    G:/laragon/bin/php/php-8.3.33-Win32-vs16-x64/php.exe bin/install-wp-tests.php'
		);
	}
}

/**
 * The core version matching the installed wp-phpunit test library.
 *
 * The test library and core must come from the same release: a 6.9 library
 * against 7.1 core fails in ways that look like plugin bugs.
 *
 * @since 1.0.0
 *
 * @param string $root Plugin root directory.
 * @return string Version such as `6.9`, or `latest` when it cannot be read.
 */
function sit_cwm_default_wp_version( string $root ): string {
	$lock = $root . '/composer.lock';

	if ( is_readable( $lock ) ) {
		$data     = json_decode( (string) file_get_contents( $lock ), true );
		$packages = array_merge(
			isset( $data['packages'] ) ? $data['packages'] : array(),
			isset( $data['packages-dev'] ) ? $data['packages-dev'] : array()
		);

		foreach ( $packages as $package ) {
			if ( 'wp-phpunit/wp-phpunit' !== $package['name'] ) {
				continue;
			}

			$matches = array();

			if ( preg_match( '/^(\d+\.\d+)/', ltrim( (string) $package['version'], 'v' ), $matches ) ) {
				return $matches[1];
			}
		}
	}

	return 'latest';
}

/**
 * Turns `latest` into a concrete version number.
 *
 * @since 1.0.0
 *
 * @param string $version Version or `latest`.
 * @return string Concrete version.
 */
function sit_cwm_resolve_wp_version( string $version ): string {
	if ( 'latest' !== $version ) {
		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $version ) ) {
			sit_cwm_fail( 'Invalid --wp value "' . $version . '". Use 6.9, 6.9.1 or latest.' );
		}

		return $version;
	}

	$data = json_decode( sit_cwm_http_get( 'https://api.wordpress.org/core/version-check/1.7/' ), true );

	if ( empty( $data['offers'][0]['current'] ) ) {
		sit_cwm_fail( 'Could not resolve the latest WordPress version from api.wordpress.org.' );
	}

	return (string) $data['offers'][0]['current'];
}

/**
 * Default cache directory for downloaded core checkouts (outside the repo).
 *
 * @since 1.0.0
 *
 * @return string Directory path without a trailing slash.
 */
function sit_cwm_default_cache_dir(): string {
	$base = (string) getenv( 'SIT_CWM_WP_TESTS_DIR' );

	if ( '' === $base ) {
		$base = (string) getenv( 'LOCALAPPDATA' );
	}

	if ( '' === $base ) {
		$base = sys_get_temp_dir();
	}

	return rtrim( str_replace( '\\', '/', $base ), '/' ) . '/sit-cwm-wp-tests';
}

/**
 * Performs a GET request and returns the body.
 *
 * @since 1.0.0
 *
 * @param string      $url  URL.
 * @param string|null $sink Optional file to stream the body into.
 * @return string Body, or an empty string when the body was streamed to $sink.
 */
function sit_cwm_http_get( string $url, ?string $sink = null ): string {
	$handle = curl_init( $url );
	$file   = null;

	curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 15 );
	curl_setopt( $handle, CURLOPT_TIMEOUT, 900 );
	curl_setopt( $handle, CURLOPT_USERAGENT, 'sit-cwm-test-env' );

	if ( null === $sink ) {
		curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
	} else {
		$file = fopen( $sink, 'wb' );

		if ( false === $file ) {
			sit_cwm_fail( 'Cannot write to ' . $sink . '.' );
		}

		curl_setopt( $handle, CURLOPT_FILE, $file );
	}

	$body   = curl_exec( $handle );
	$error  = (string) curl_error( $handle );
	$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

	curl_close( $handle );

	if ( null !== $file ) {
		fclose( $file );
	}

	if ( '' !== $error ) {
		sit_cwm_fail( 'Download failed for ' . $url . ': ' . $error );
	}

	if ( 200 !== $status ) {
		sit_cwm_fail( 'Download failed for ' . $url . ': HTTP ' . $status );
	}

	return null === $sink ? (string) $body : '';
}

/**
 * Deletes a directory tree.
 *
 * @since 1.0.0
 *
 * @param string $dir Directory.
 * @return void
 */
function sit_cwm_rmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}

	rmdir( $dir );
}

/**
 * Downloads and extracts a WordPress core checkout, cached by version.
 *
 * @since 1.0.0
 *
 * @param string $version WordPress version.
 * @param string $cache   Cache directory.
 * @param bool   $force   Re-download even when already cached.
 * @return string ABSPATH of the checkout, with a trailing slash.
 */
function sit_cwm_install_core( string $version, string $cache, bool $force ): string {
	$target = $cache . '/wordpress-' . $version;

	if ( $force ) {
		sit_cwm_rmdir( $target );
	}

	if ( is_file( $target . '/wp-includes/version.php' ) ) {
		sit_cwm_say( 'Core already downloaded, skipping.' );

		return $target . '/';
	}

	if ( ! is_dir( $cache ) && ! mkdir( $cache, 0777, true ) && ! is_dir( $cache ) ) {
		sit_cwm_fail( 'Cannot create ' . $cache . '.' );
	}

	$zip = $cache . '/wordpress-' . $version . '.zip';
	$url = 'https://wordpress.org/wordpress-' . $version . '.zip';

	sit_cwm_say( 'Downloading ' . $url . ' ...' );
	sit_cwm_http_get( $url, $zip );

	sit_cwm_say( 'Extracting ...' );
	$archive = new ZipArchive();

	if ( true !== $archive->open( $zip ) ) {
		sit_cwm_fail( 'Cannot open ' . $zip . '.' );
	}

	sit_cwm_rmdir( $cache . '/wordpress' );
	$archive->extractTo( $cache );
	$archive->close();
	unlink( $zip );

	if ( ! rename( $cache . '/wordpress', $target ) ) {
		sit_cwm_fail( 'Cannot move the extracted core to ' . $target . '.' );
	}

	return $target . '/';
}

/**
 * Copies a directory tree.
 *
 * @since 1.0.0
 *
 * @param string $from Source directory.
 * @param string $to   Destination directory.
 * @return void
 */
function sit_cwm_copy_tree( string $from, string $to ): void {
	if ( ! is_dir( $to ) && ! mkdir( $to, 0777, true ) && ! is_dir( $to ) ) {
		sit_cwm_fail( 'Cannot create ' . $to . '.' );
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $items as $item ) {
		$target = $to . '/' . $items->getSubPathname();

		if ( $item->isDir() ) {
			if ( ! is_dir( $target ) && ! mkdir( $target, 0777, true ) && ! is_dir( $target ) ) {
				sit_cwm_fail( 'Cannot create ' . $target . '.' );
			}

			continue;
		}

		if ( ! copy( $item->getPathname(), $target ) ) {
			sit_cwm_fail( 'Cannot copy ' . $item->getPathname() . '.' );
		}
	}
}

/**
 * Fetches the eight authentication salts, falling back to local randomness.
 *
 * @since 1.0.0
 *
 * @return string PHP `define()` lines, one per salt.
 */
function sit_cwm_salts(): string {
	$keys = array(
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	);

	$lines = array();

	foreach ( $keys as $key ) {
		$lines[] = "define( '" . $key . "', '" . bin2hex( random_bytes( 32 ) ) . "' );";
	}

	return implode( "\n", $lines );
}

/**
 * Writes a `wp-config.php` for a scratch site.
 *
 * WP_HOME and WP_SITEURL are pinned so the site answers on the URL Playwright
 * targets, whatever is stored in the options table.
 *
 * @since 1.0.0
 *
 * @param string $path WordPress root.
 * @param array  $opts Keys: db, db-user, db-pass, db-host, prefix, url.
 * @return void
 */
function sit_cwm_write_wp_config( string $path, array $opts ): void {
	$lines = array(
		'<?php',
		'/**',
		' * Generated by bin/setup-e2e-site.php for the Playwright suite.',
		' *',
		' * Scratch site: the end-to-end specs delete every post and user in it.',
		' */',
		'',
		"define( 'DB_NAME', '" . $opts['db'] . "' );",
		"define( 'DB_USER', '" . $opts['db-user'] . "' );",
		"define( 'DB_PASSWORD', '" . $opts['db-pass'] . "' );",
		"define( 'DB_HOST', '" . $opts['db-host'] . "' );",
		"define( 'DB_CHARSET', 'utf8mb4' );",
		"define( 'DB_COLLATE', '' );",
		'',
		sit_cwm_salts(),
		'',
		'$table_prefix = ' . "'" . $opts['prefix'] . "';",
		'',
		"define( 'WP_HOME', '" . $opts['url'] . "' );",
		"define( 'WP_SITEURL', '" . $opts['url'] . "' );",
		"define( 'WP_DEBUG', true );",
		"define( 'WP_DEBUG_DISPLAY', true );",
		"define( 'WP_DEBUG_LOG', true );",
		"define( 'SCRIPT_DEBUG', true );",
		"define( 'DISABLE_WP_CRON', true );",
		"define( 'AUTOMATIC_UPDATER_DISABLED', true );",
		'',
		'// Update and API checks to wordpress.org block admin and REST requests',
		'// until they time out when the web PHP cannot reach it, which turns into',
		'// random multi-second stalls and flaky specs. The suite needs no outbound',
		'// HTTP.',
		"define( 'WP_HTTP_BLOCK_EXTERNAL', true );",
		'',
		"if ( ! defined( 'ABSPATH' ) ) {",
		"\tdefine( 'ABSPATH', __DIR__ . '/' );",
		'}',
		'',
		"require_once ABSPATH . 'wp-settings.php';",
		'',
	);

	if ( false === file_put_contents( $path . '/wp-config.php', implode( "\n", $lines ) ) ) {
		sit_cwm_fail( 'Cannot write ' . $path . '/wp-config.php.' );
	}
}

/**
 * Connects to MySQL, exiting with a readable message on failure.
 *
 * @since 1.0.0
 *
 * @param string $host     Host, optionally `host:port`.
 * @param string $user     User.
 * @param string $password Password.
 * @param string $database Database to select, or an empty string for none.
 * @return mysqli Connection.
 */
function sit_cwm_mysql( string $host, string $user, string $password, string $database = '' ) {
	$parts = explode( ':', $host, 2 );
	$port  = isset( $parts[1] ) ? (int) $parts[1] : 3306;

	mysqli_report( MYSQLI_REPORT_OFF );

	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Connection errors are reported by hand below.
	$connection = @new mysqli( $parts[0], $user, $password, $database, $port );

	if ( $connection->connect_errno ) {
		sit_cwm_fail(
			'Cannot connect to MySQL at ' . $host . ' as ' . $user . ': ' . $connection->connect_error . PHP_EOL
			. 'Is Laragon (MySQL) running?'
		);
	}

	return $connection;
}

/**
 * Creates a database when it does not exist yet.
 *
 * @since 1.0.0
 *
 * @param string $host     Host.
 * @param string $user     User.
 * @param string $password Password.
 * @param string $database Database name.
 * @return void
 */
function sit_cwm_create_database( string $host, string $user, string $password, string $database ): void {
	if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $database ) ) {
		sit_cwm_fail( 'Refusing to create a database named "' . $database . '".' );
	}

	$connection = sit_cwm_mysql( $host, $user, $password );
	$connection->query( 'CREATE DATABASE IF NOT EXISTS `' . $database . '` DEFAULT CHARACTER SET utf8mb4' );

	if ( '' !== (string) $connection->error ) {
		sit_cwm_fail( 'Cannot create the database: ' . $connection->error );
	}

	$connection->close();
	sit_cwm_say( 'Database ' . $database . ' is ready.' );
}

/**
 * Writes `tests/php/wp-tests-config.php`.
 *
 * @since 1.0.0
 *
 * @param string $path    Destination path.
 * @param string $abspath Core checkout, with a trailing slash.
 * @param array  $opts    CLI options (db, user, pass, host, prefix).
 * @return void
 */
function sit_cwm_write_tests_config( string $path, string $abspath, array $opts ): void {
	$lines = array(
		'<?php',
		'/**',
		' * Generated by bin/install-wp-tests.php -- do not edit by hand.',
		' *',
		' * WARNING: the WordPress test suite DROPS ALL TABLES in DB_NAME on every',
		' * run. Never point this at a site database.',
		' *',
		' * @package Sit_Cwm\\Tests',
		' * @since   1.0.0',
		' */',
		'',
		'// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constant names are dictated by the WP test library.',
		'',
		"define( 'ABSPATH', '" . $abspath . "' );",
		'',
		"define( 'WP_DEFAULT_THEME', 'default' );",
		"define( 'WP_DEBUG', true );",
		'',
		"define( 'DB_NAME', '" . $opts['db'] . "' );",
		"define( 'DB_USER', '" . $opts['user'] . "' );",
		"define( 'DB_PASSWORD', '" . $opts['pass'] . "' );",
		"define( 'DB_HOST', '" . $opts['host'] . "' );",
		"define( 'DB_CHARSET', 'utf8mb4' );",
		"define( 'DB_COLLATE', '' );",
		'',
		"\$table_prefix = '" . $opts['prefix'] . "'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Required name.",
		'',
		"define( 'WP_TESTS_DOMAIN', 'example.org' );",
		"define( 'WP_TESTS_EMAIL', 'admin@example.org' );",
		"define( 'WP_TESTS_TITLE', 'Test Blog' );",
		"define( 'WP_PHP_BINARY', '" . str_replace( '\\', '/', PHP_BINARY ) . "' );",
		"define( 'WPLANG', '' );",
		'',
	);

	if ( false === file_put_contents( $path, implode( "\n", $lines ) ) ) {
		sit_cwm_fail( 'Cannot write ' . $path . '.' );
	}
}
