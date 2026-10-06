#!/usr/bin/env php
<?php
/**
 * Builds the distributable plugin zip from the working tree.
 *
 * Everything listed in `.distignore` is left out; everything else is added
 * under a `sapphireit-editorial-workflow/` top-level directory, which is what
 * WordPress expects when the zip is uploaded through Plugins → Add New.
 *
 * The same script runs locally and in `.github/workflows/release.yml`, so the
 * zip a developer inspects is produced exactly the way CI produces the one it
 * publishes. It needs only PHP with `ZipArchive` — no rsync, no zip binary, no
 * WordPress.
 *
 * Usage:
 *   php bin/build-zip.php                    # → dist/sapphireit-editorial-workflow.zip
 *   php bin/build-zip.php --out=build        # → build/sapphireit-editorial-workflow.zip
 *   php bin/build-zip.php --list             # print what would ship, build nothing
 *
 * Exit code 0 on success, 1 on failure.
 *
 * Development tool: excluded from the release zip.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- CLI script: no WordPress, no HTML output, nothing to escape for.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "The zip extension is required (ext-zip / ZipArchive).\n" );
	exit( 1 );
}

if ( ! defined( 'SIT_CWM_SLUG' ) ) {
	/**
	 * Plugin slug, used as the zip's top-level directory.
	 *
	 * @since 1.0.0
	 */
	define( 'SIT_CWM_SLUG', 'sapphireit-editorial-workflow' );
}

/**
 * Reads `.distignore` into a list of patterns.
 *
 * Blank lines and `#` comments are skipped, matching rsync's `--exclude-from`.
 *
 * @since 1.0.0
 *
 * @param string $path Absolute path to `.distignore`.
 * @return string[]
 */
function sit_cwm_zip_patterns( string $path ): array {
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "Cannot read {$path}\n" );
		exit( 1 );
	}

	$patterns = array();

	foreach ( file( $path, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( $line );

		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}

		$patterns[] = rtrim( $line, '/' );
	}

	return $patterns;
}

/**
 * Whether a repository-relative path is excluded.
 *
 * A pattern containing a slash matches that exact path or anything beneath it.
 * A pattern without one matches any path segment (`tests` excludes `tests/` and
 * `foo/tests/`), or globs the basename (`*.zip`). This mirrors how rsync treats
 * a slash-less exclude pattern.
 *
 * @since 1.0.0
 *
 * @param string   $relative Path relative to the plugin root, `/` separated.
 * @param string[] $patterns Patterns from `.distignore`.
 * @return bool
 */
function sit_cwm_zip_is_excluded( string $relative, array $patterns ): bool {
	$segments = explode( '/', $relative );
	$basename = end( $segments );

	foreach ( $patterns as $pattern ) {
		if ( false !== strpos( $pattern, '/' ) ) {
			if ( $relative === $pattern || 0 === strpos( $relative, $pattern . '/' ) ) {
				return true;
			}

			continue;
		}

		if ( in_array( $pattern, $segments, true ) ) {
			return true;
		}

		if ( false !== strpos( $pattern, '*' ) && fnmatch( $pattern, $basename ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Every shippable file, relative to the plugin root, sorted.
 *
 * @since 1.0.0
 *
 * @param string   $root     Plugin root directory.
 * @param string[] $patterns Exclusion patterns.
 * @return string[]
 */
function sit_cwm_zip_collect( string $root, array $patterns ): array {
	$files    = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			static function ( $file ) use ( $root, $patterns ) {
				// Pruning excluded directories here keeps node_modules unwalked.
				return ! sit_cwm_zip_is_excluded( sit_cwm_zip_relative( $root, $file->getPathname() ), $patterns );
			}
		),
		RecursiveIteratorIterator::LEAVES_ONLY
	);

	foreach ( $iterator as $file ) {
		if ( $file->isFile() ) {
			$files[] = sit_cwm_zip_relative( $root, $file->getPathname() );
		}
	}

	sort( $files );

	return $files;
}

/**
 * Normalises an absolute path to a `/`-separated repository-relative one.
 *
 * @since 1.0.0
 *
 * @param string $root Plugin root directory.
 * @param string $path Absolute path.
 * @return string
 */
function sit_cwm_zip_relative( string $root, string $path ): string {
	return ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
}

/**
 * Resolves `--out=`, defaulting to `dist/` under the plugin root.
 *
 * @since 1.0.0
 *
 * @param string   $root Plugin root directory.
 * @param string[] $args Command-line arguments.
 * @return string Absolute output directory, without a trailing slash.
 */
function sit_cwm_zip_out_dir( string $root, array $args ): string {
	foreach ( $args as $arg ) {
		if ( 0 !== strpos( $arg, '--out=' ) ) {
			continue;
		}

		$out = rtrim( str_replace( '\\', '/', substr( $arg, 6 ) ), '/' );

		if ( '' === $out ) {
			return $root . '/dist';
		}

		return preg_match( '#^([A-Za-z]:)?/#', $out ) ? $out : $root . '/' . $out;
	}

	return $root . '/dist';
}

/**
 * Writes the zip and reports what it contains.
 *
 * @since 1.0.0
 *
 * @param string   $root  Plugin root directory.
 * @param string   $out   Output directory.
 * @param string[] $files Repository-relative paths to include.
 * @return bool True on success.
 */
function sit_cwm_zip_write( string $root, string $out, array $files ): bool {
	if ( ! is_dir( $out ) && ! mkdir( $out, 0777, true ) && ! is_dir( $out ) ) {
		fwrite( STDERR, "Cannot create {$out}\n" );

		return false;
	}

	$path = $out . '/' . SIT_CWM_SLUG . '.zip';

	if ( file_exists( $path ) && ! unlink( $path ) ) {
		fwrite( STDERR, "Cannot replace {$path}\n" );

		return false;
	}

	$zip = new ZipArchive();

	if ( true !== $zip->open( $path, ZipArchive::CREATE ) ) {
		fwrite( STDERR, "Cannot create {$path}\n" );

		return false;
	}

	foreach ( $files as $relative ) {
		$zip->addFile( $root . '/' . $relative, SIT_CWM_SLUG . '/' . $relative );
	}

	if ( ! $zip->close() ) {
		fwrite( STDERR, "Failed to write {$path}\n" );

		return false;
	}

	printf(
		"%s\n%d files, %s KB\n",
		$path,
		count( $files ),
		number_format( filesize( $path ) / 1024, 1 )
	);

	return true;
}

$sit_cwm_root  = str_replace( '\\', '/', dirname( __DIR__ ) );
$sit_cwm_args  = array_slice( $argv, 1 );
$sit_cwm_files = sit_cwm_zip_collect( $sit_cwm_root, sit_cwm_zip_patterns( $sit_cwm_root . '/.distignore' ) );

if ( array() === $sit_cwm_files ) {
	fwrite( STDERR, "Nothing to package — check .distignore.\n" );
	exit( 1 );
}

if ( in_array( '--list', $sit_cwm_args, true ) ) {
	printf( "%s\n\n%d files would ship.\n", implode( "\n", $sit_cwm_files ), count( $sit_cwm_files ) );
	exit( 0 );
}

exit( sit_cwm_zip_write( $sit_cwm_root, sit_cwm_zip_out_dir( $sit_cwm_root, $sit_cwm_args ), $sit_cwm_files ) ? 0 : 1 );
