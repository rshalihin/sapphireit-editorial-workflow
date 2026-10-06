#!/usr/bin/env php
<?php
/**
 * Verifies that the plugin version is identical in all four places it lives.
 *
 * A mismatch between the plugin header and `readme.txt`'s `Stable tag` is the
 * classic WordPress release bug: the update users are offered is not the code
 * that ships. This fails the release build instead.
 *
 * Usage:
 *   php bin/check-version.php            # all four must agree
 *   php bin/check-version.php 1.0.0      # …and must equal this version
 *   php bin/check-version.php v1.0.0     # a leading "v" is stripped
 *
 * Exit code 0 when everything agrees, 1 otherwise.
 *
 * Development tool: excluded from the release zip. Runs without WordPress.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- CLI script: no WordPress, no HTML output, nothing to escape for.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/**
 * Reads a file, or stops with an error.
 *
 * @since 1.0.0
 *
 * @param string $path Absolute path.
 * @return string
 */
function sit_cwm_version_read( string $path ): string {
	$contents = is_readable( $path ) ? file_get_contents( $path ) : false;

	if ( false === $contents ) {
		fwrite( STDERR, "Cannot read {$path}\n" );
		exit( 1 );
	}

	return $contents;
}

/**
 * Extracts the first capture group of a pattern.
 *
 * @since 1.0.0
 *
 * @param string $pattern Regular expression with one capture group.
 * @param string $subject Text to search.
 * @return string|null Trimmed match, or null when the pattern does not match.
 */
function sit_cwm_version_match( string $pattern, string $subject ): ?string {
	return preg_match( $pattern, $subject, $matches ) ? trim( $matches[1] ) : null;
}

/**
 * The version declared in each of the four locations.
 *
 * @since 1.0.0
 *
 * @param string $root Plugin root directory.
 * @return array<string, string|null> Location description => version or null.
 */
function sit_cwm_version_locations( string $root ): array {
	$plugin  = sit_cwm_version_read( $root . '/sapphireit-editorial-workflow.php' );
	$readme  = sit_cwm_version_read( $root . '/readme.txt' );
	$package = json_decode( sit_cwm_version_read( $root . '/package.json' ), true );

	return array(
		'sapphireit-editorial-workflow.php (plugin header)' => sit_cwm_version_match( '/^\s*\*\s*Version:\s*(.+)$/m', $plugin ),
		'sapphireit-editorial-workflow.php (SIT_CWM_VERSION)' => sit_cwm_version_match( "/define\(\s*'SIT_CWM_VERSION'\s*,\s*'([^']+)'/", $plugin ),
		'package.json (version)'  => is_array( $package ) && isset( $package['version'] ) ? (string) $package['version'] : null,
		'readme.txt (Stable tag)' => sit_cwm_version_match( '/^Stable tag:\s*(.+)$/m', $readme ),
	);
}

/**
 * Reports every location and returns whether they all agree.
 *
 * @since 1.0.0
 *
 * @param array<string, string|null> $locations Location description => version.
 * @param string|null                $expected  Version they must equal; null to
 *                                              take the first one found.
 * @return bool True when every location agrees.
 */
function sit_cwm_version_report( array $locations, ?string $expected ): bool {
	$failed = false;

	foreach ( $locations as $where => $version ) {
		if ( null === $version ) {
			fwrite( STDERR, sprintf( "MISSING  %s\n", $where ) );
			$failed = true;
			continue;
		}

		if ( null === $expected ) {
			$expected = $version;
		}

		$matches = $version === $expected;
		$failed  = $failed || ! $matches;

		printf( "%-8s %-52s %s\n", $matches ? 'ok' : 'MISMATCH', $where, $version );
	}

	if ( $failed ) {
		fwrite( STDERR, sprintf( "\nVersion mismatch. Expected %s in all four locations.\n", (string) $expected ) );

		return false;
	}

	printf( "\nVersion %s is consistent in all four locations.\n", (string) $expected );

	return true;
}

/*
 * An empty argument counts as "not given", so CI can pass the tag name
 * unconditionally and have it ignored on a non-tag run.
 */
$sit_cwm_expected = isset( $argv[1] ) ? ltrim( trim( $argv[1] ), 'vV' ) : '';
$sit_cwm_expected = '' === $sit_cwm_expected ? null : $sit_cwm_expected;

exit( sit_cwm_version_report( sit_cwm_version_locations( dirname( __DIR__ ) ), $sit_cwm_expected ) ? 0 : 1 );
