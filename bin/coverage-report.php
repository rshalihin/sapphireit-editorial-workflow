#!/usr/bin/env php
<?php
/**
 * Checks the release coverage minimums (plan step 19.1) against the reports
 * `composer test:coverage` writes to `coverage/`.
 *
 * - `unit.cov` + `integration.cov`: line coverage, merged, per area.
 * - `branches.cov`: path coverage of `WorkflowManager`, for the methods a
 *   status change runs through, which must cover 100 % of their branches.
 *
 * Tests declare `@covers`, so a line only counts when a test aimed at that
 * class ran it; code merely passed through by another class's test does not.
 *
 * Usage (after the three phpunit runs of `composer test:coverage`):
 *   php bin/coverage-report.php
 *
 * Exit code 0 when every minimum is met, 1 otherwise.
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

require dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Line coverage minimums, by source directory.
 */
const SIT_CWM_COVERAGE_LINE_MINIMUMS = array(
	'includes/Workflow/' => 90,
	'includes/Activity/' => 80,
	'includes/Content/'  => 80,
);

/**
 * `WorkflowManager` methods a status change runs through; every branch of
 * each must be covered.
 */
const SIT_CWM_COVERAGE_TRANSITION_METHODS = array(
	'can_transition',
	'check_transition',
	'transition',
	'validate_transition',
	'check_post',
	'resolve_user_id',
);

/**
 * Loads a report written by `phpunit --coverage-php`, or stops.
 *
 * @since 1.0.0
 *
 * @param string $name File name inside `coverage/`.
 * @return \SebastianBergmann\CodeCoverage\CodeCoverage
 */
function sit_cwm_coverage_load( string $name ) {
	$path = dirname( __DIR__ ) . '/coverage/' . $name;

	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "Missing coverage/{$name}. Run composer test:coverage." . PHP_EOL );
		exit( 1 );
	}

	return require $path;
}

/**
 * Formats a covered/total pair as a percentage.
 *
 * @since 1.0.0
 *
 * @param int $covered Covered items.
 * @param int $total   All items.
 * @return float
 */
function sit_cwm_coverage_percent( int $covered, int $total ): float {
	return 0 === $total ? 100.0 : round( 100 * $covered / $total, 1 );
}

$sit_cwm_lines = sit_cwm_coverage_load( 'unit.cov' );
$sit_cwm_lines->merge( sit_cwm_coverage_load( 'integration.cov' ) );

$sit_cwm_root   = str_replace( '\\', '/', dirname( __DIR__ ) ) . '/';
$sit_cwm_totals = array_fill_keys( array_keys( SIT_CWM_COVERAGE_LINE_MINIMUMS ), array( 0, 0 ) );
$sit_cwm_failed = false;

foreach ( $sit_cwm_lines->getData()->lineCoverage() as $sit_cwm_file => $sit_cwm_file_lines ) {
	$sit_cwm_relative = str_replace( $sit_cwm_root, '', str_replace( '\\', '/', $sit_cwm_file ) );

	foreach ( array_keys( $sit_cwm_totals ) as $sit_cwm_area ) {
		if ( 0 !== strpos( $sit_cwm_relative, $sit_cwm_area ) ) {
			continue;
		}

		foreach ( $sit_cwm_file_lines as $sit_cwm_tests ) {
			if ( is_array( $sit_cwm_tests ) ) {
				++$sit_cwm_totals[ $sit_cwm_area ][1];
				$sit_cwm_totals[ $sit_cwm_area ][0] += array() === $sit_cwm_tests ? 0 : 1;
			}
		}
	}
}

echo 'Line coverage (unit + integration)' . PHP_EOL;

foreach ( $sit_cwm_totals as $sit_cwm_area => $sit_cwm_count ) {
	$sit_cwm_percent = sit_cwm_coverage_percent( $sit_cwm_count[0], $sit_cwm_count[1] );
	$sit_cwm_ok      = $sit_cwm_percent >= SIT_CWM_COVERAGE_LINE_MINIMUMS[ $sit_cwm_area ];
	$sit_cwm_failed  = $sit_cwm_failed || ! $sit_cwm_ok;

	printf(
		'  %-20s %5.1f %% (%d/%d)  minimum %d %%  %s' . PHP_EOL,
		$sit_cwm_area,
		$sit_cwm_percent,
		$sit_cwm_count[0],
		$sit_cwm_count[1],
		SIT_CWM_COVERAGE_LINE_MINIMUMS[ $sit_cwm_area ],
		$sit_cwm_ok ? 'ok' : 'FAIL'
	);
}

echo PHP_EOL . 'Branch coverage of the status-change path (WorkflowManager)' . PHP_EOL;

$sit_cwm_functions = array();

foreach ( sit_cwm_coverage_load( 'branches.cov' )->getData()->functionCoverage() as $sit_cwm_file_functions ) {
	$sit_cwm_functions += $sit_cwm_file_functions;
}

foreach ( SIT_CWM_COVERAGE_TRANSITION_METHODS as $sit_cwm_method ) {
	$sit_cwm_key = 'Sit_Cwm\\Workflow\\WorkflowManager->' . $sit_cwm_method;

	if ( ! isset( $sit_cwm_functions[ $sit_cwm_key ]['branches'] ) ) {
		printf( '  %-20s no branch data (was --path-coverage passed?)  FAIL' . PHP_EOL, $sit_cwm_method );
		$sit_cwm_failed = true;
		continue;
	}

	$sit_cwm_branches = $sit_cwm_functions[ $sit_cwm_key ]['branches'];
	$sit_cwm_hit      = count(
		array_filter(
			$sit_cwm_branches,
			static function ( array $branch ): bool {
				return array() !== $branch['hit'];
			}
		)
	);
	$sit_cwm_ok       = count( $sit_cwm_branches ) === $sit_cwm_hit;
	$sit_cwm_failed   = $sit_cwm_failed || ! $sit_cwm_ok;

	printf(
		'  %-20s %d/%d branches  %s' . PHP_EOL,
		$sit_cwm_method,
		$sit_cwm_hit,
		count( $sit_cwm_branches ),
		$sit_cwm_ok ? 'ok' : 'FAIL'
	);
}

exit( $sit_cwm_failed ? 1 : 0 );
