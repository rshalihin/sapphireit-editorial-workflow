<?php
/**
 * Measures the step 21.1 budgets on a seeded site (dev only).
 *
 * For each hot REST path it reports the queries the dispatch runs, wall time,
 * peak memory and any SQL run more than once, then `EXPLAIN`s the timeline and
 * last-activity queries. Run it after `bin/seed.php`:
 *
 *     wp --exec="define( 'SAVEQUERIES', true );" eval-file bin/measure.php [user=admin] [batch=50] [sql]
 *
 * Pass `sql` as the third argument to list every query of every scenario.
 *
 * Without SAVEQUERIES the duplicate check and the EXPLAIN output are skipped.
 *
 * Each scenario starts from an empty object cache with only what every
 * request's bootstrap loads anyway (autoloaded options and the current user),
 * so the counts match a real request on a site without a persistent object
 * cache and exclude WordPress's own bootstrap queries. That is also what
 * `tests/php/integration/PerformanceTest.php` counts.
 *
 * It writes: the POST scenarios change a seeded post's status and the due
 * date of `batch` seeded posts. Use a scratch site.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Runs one REST request from a cold object cache and measures it.
 *
 * @since 1.0.0
 *
 * @param string     $method HTTP method.
 * @param string     $route  Route.
 * @param array      $query  Query parameters.
 * @param array|null $body   JSON body.
 * @return array{status: int, queries: int, ms: float, memory: float, duplicates: string[], sql: string[], data: mixed}
 */
function sit_cwm_measure_request( string $method, string $route, array $query = array(), ?array $body = null ): array {
	global $wpdb;

	wp_cache_flush();
	wp_load_alloptions();
	wp_set_current_user( get_current_user_id() );
	get_userdata( get_current_user_id() );

	$request = new WP_REST_Request( $method, $route );
	$request->set_query_params( $query );

	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}

	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}

	$first_query = is_array( $wpdb->queries ) ? count( $wpdb->queries ) : 0;
	$queries     = $wpdb->num_queries;
	$memory      = memory_get_usage();
	$start       = microtime( true );

	$response = rest_do_request( $request );

	$elapsed = ( microtime( true ) - $start ) * 1000;
	$sql     = is_array( $wpdb->queries ) ? array_column( array_slice( $wpdb->queries, $first_query ), 0 ) : array();
	$counts  = array_count_values( $sql );

	return array(
		'status'     => $response->get_status(),
		'queries'    => $wpdb->num_queries - $queries,
		'ms'         => round( $elapsed, 1 ),
		'memory'     => round( ( memory_get_peak_usage() - $memory ) / 1048576, 2 ),
		'duplicates' => array_keys(
			array_filter(
				$counts,
				static function ( $count ) {
					return $count > 1;
				}
			)
		),
		'sql'        => $sql,
		'data'       => $response->get_data(),
	);
}

/**
 * Prints `EXPLAIN` for the first captured query that matches a pattern.
 *
 * @since 1.0.0
 *
 * @param string   $title   Heading.
 * @param string[] $sql     Captured queries.
 * @param string   $pattern Regular expression.
 * @return void
 */
function sit_cwm_measure_explain( string $title, array $sql, string $pattern ): void {
	global $wpdb;

	$matches = preg_grep( $pattern, $sql );

	if ( empty( $matches ) ) {
		WP_CLI::warning( $title . ': query not captured (define SAVEQUERIES).' );
		return;
	}

	$query = reset( $matches );

	WP_CLI::line( PHP_EOL . '### ' . $title . PHP_EOL );
	WP_CLI::line( '```sql' . PHP_EOL . substr( $query, 0, 400 ) . ( strlen( $query ) > 400 ? ' …' : '' ) . PHP_EOL . '```' . PHP_EOL );

	// The query was captured from $wpdb->queries after $wpdb->prepare() ran.
	$rows = $wpdb->get_results( 'EXPLAIN ' . $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Already prepared; diagnostic only.

	// A UNION has one plan row per branch; collapse identical plans.
	$plans = array();

	foreach ( $rows as $row ) {
		$key = implode( '|', array( $row['select_type'], $row['type'], $row['key'], $row['ref'], $row['Extra'] ) );

		if ( ! isset( $plans[ $key ] ) ) {
			$plans[ $key ] = $row + array(
				'count'    => 0,
				'max_rows' => 0,
			);
		}

		++$plans[ $key ]['count'];
		$plans[ $key ]['max_rows'] = max( $plans[ $key ]['max_rows'], (int) $row['rows'] );
	}

	WP_CLI\Utils\format_items( 'table', array_values( $plans ), array( 'count', 'select_type', 'table', 'type', 'key', 'ref', 'max_rows', 'Extra' ) );
}

/**
 * Runs every scenario and prints the budget table.
 *
 * @since 1.0.0
 *
 * @param array $cli_args Positional arguments: user login, batch size, `sql`.
 * @return void
 */
function sit_cwm_measure( array $cli_args ) {
	global $wpdb;

	$login      = isset( $cli_args[0] ) ? sanitize_user( $cli_args[0] ) : 'admin';
	$batch_size = isset( $cli_args[1] ) ? max( 1, min( 100, absint( $cli_args[1] ) ) ) : 50;
	$verbose    = isset( $cli_args[2] ) && 'sql' === $cli_args[2];
	$user       = get_user_by( 'login', $login );

	if ( ! $user ) {
		WP_CLI::error( 'No user "' . $login . '".' );
	}

	wp_set_current_user( $user->ID );

	// Build the REST server first: registering core's routes reads a few
	// options (image sizes for the attachments schema) on every REST request,
	// whichever plugin answers it. That cost is not the plugin's.
	rest_get_server();

	$saving = defined( 'SAVEQUERIES' ) && SAVEQUERIES;

	if ( ! $saving ) {
		WP_CLI::warning( 'SAVEQUERIES is off: duplicate queries and EXPLAIN are skipped.' );
	}

	$seeded = get_posts(
		array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'meta_key'       => '_sit_cwm_seed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Dev script.
			'fields'         => 'ids',
			'posts_per_page' => 100,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);

	if ( count( $seeded ) < $batch_size + 1 ) {
		WP_CLI::error( 'Seed the site first: wp eval-file bin/seed.php 500 10 5000' );
	}

	// The seeded post with the longest history, for the per-post routes.
	$table   = ( new \Sit_Cwm\Core\Database() )->table_name();
	$post_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i GROUP BY post_id ORDER BY COUNT(*) DESC LIMIT 1', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dev script.

	$totals = array(
		'posts'    => (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'post' )->draft + (int) wp_count_posts( 'post' )->pending,
		'activity' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dev script.
	);

	WP_CLI::line( sprintf( 'Site: %d posts, %d activity rows; acting as %s; probe post %d.', $totals['posts'], $totals['activity'], $login, $post_id ) );

	$workflow_route = '/sit-cwm/v1/posts/' . $post_id . '/workflow';
	$results        = array();

	$results['GET /posts?per_page=100']              = array( 8, 300, sit_cwm_measure_request( 'GET', '/sit-cwm/v1/posts', array( 'per_page' => 100 ) ) );
	$results['GET /posts/<id>/workflow']             = array( 5, null, sit_cwm_measure_request( 'GET', $workflow_route ) );
	$results['GET /posts/<id>/activity?per_page=20'] = array( 4, null, sit_cwm_measure_request( 'GET', '/sit-cwm/v1/posts/' . $post_id . '/activity', array( 'per_page' => 20 ) ) );

	// A real status change: the first move the server offers on this post.
	$state = $results['GET /posts/<id>/workflow'][2]['data'];
	$to    = isset( $state['available_transitions'][0]['slug'] ) ? $state['available_transitions'][0]['slug'] : '';

	if ( '' === $to ) {
		WP_CLI::warning( 'The probe post offers no transition; POST /workflow is skipped.' );
	} else {
		$results['POST /posts/<id>/workflow'] = array(
			10,
			null,
			sit_cwm_measure_request(
				'POST',
				$workflow_route,
				array(),
				array(
					'from'   => $state['status'],
					'status' => $to,
				)
			),
		);
	}

	// Batch: the same action on 10 and on N posts shows the per-post cost. A
	// date no post has yet, so every post is written, not skipped as a no-op.
	foreach ( array( 10, $batch_size ) as $size ) {
		$due = gmdate( 'Y-m-d', time() + wp_rand( 400, 4000 ) * DAY_IN_SECONDS );

		$results[ 'POST /posts/batch (' . $size . ' posts)' ] = array(
			null,
			null,
			sit_cwm_measure_request(
				'POST',
				'/sit-cwm/v1/posts/batch',
				array(),
				array(
					'post_ids' => array_slice( $seeded, 1, $size ),
					'action'   => 'set_due_date',
					'payload'  => array( 'due_date' => $due ),
				)
			),
		);
	}

	WP_CLI::line( PHP_EOL . '| Path | Budget | Status | Queries | Time | Peak memory | Duplicate SQL |' );
	WP_CLI::line( '|---|---|---|---|---|---|---|' );

	$failed = false;

	foreach ( $results as $label => list( $max_queries, $max_ms, $result ) ) {
		$budget = array();

		// Reads must not repeat a query. A write re-reads the meta it just
		// changed (to verify it and build the response), which is expected.
		$ok = $result['status'] < 300 && ( 0 !== strpos( $label, 'GET ' ) || array() === $result['duplicates'] );

		if ( null !== $max_queries ) {
			$budget[] = '≤ ' . $max_queries . ' queries';
			$ok       = $ok && $result['queries'] <= $max_queries;
		}

		if ( null !== $max_ms ) {
			$budget[] = '< ' . $max_ms . ' ms';
			$ok       = $ok && $result['ms'] < $max_ms;
		}

		$failed = $failed || ! $ok;

		WP_CLI::line(
			sprintf(
				'| `%s` | %s | %d | %d | %s ms | %s MB | %s |',
				$label,
				$budget ? implode( ', ', $budget ) : 'linear',
				$result['status'],
				$result['queries'],
				$result['ms'],
				$result['memory'],
				$saving ? ( $result['duplicates'] ? count( $result['duplicates'] ) . ' ⚠' : 'none' ) : 'n/a'
			)
		);

		foreach ( $result['duplicates'] as $duplicate ) {
			WP_CLI::line( '    duplicate: ' . substr( preg_replace( '/\s+/', ' ', $duplicate ), 0, 200 ) );
		}

		if ( $verbose ) {
			foreach ( $result['sql'] as $index => $statement ) {
				WP_CLI::line( sprintf( '    %2d. %s', $index + 1, substr( preg_replace( '/\s+/', ' ', $statement ), 0, 220 ) ) );
			}
		}
	}

	$small = $results['POST /posts/batch (10 posts)'][2]['queries'];
	$large = $results[ 'POST /posts/batch (' . $batch_size . ' posts)' ][2]['queries'];

	if ( $batch_size > 10 ) {
		WP_CLI::line( sprintf( PHP_EOL . 'Batch: %.1f queries per extra post (%d → %d).', ( $large - $small ) / ( $batch_size - 10 ), $small, $large ) );
	}

	if ( $saving ) {
		sit_cwm_measure_explain( 'Timeline query', $results['GET /posts/<id>/activity?per_page=20'][2]['sql'], '/FROM `?' . preg_quote( $table, '/' ) . '`?\s+WHERE .*ORDER BY created_at/i' );
		sit_cwm_measure_explain( 'Last activity per row (dashboard)', $results['GET /posts?per_page=100'][2]['sql'], '/\) UNION ALL \( SELECT \* FROM `?' . preg_quote( $table, '/' ) . '/i' );
	}

	if ( $failed ) {
		WP_CLI::warning( 'At least one path missed its budget, returned an error or ran duplicate SQL.' );
	} else {
		WP_CLI::success( 'Every path is within budget.' );
	}
}

sit_cwm_measure( isset( $args ) && is_array( $args ) ? $args : array() );
