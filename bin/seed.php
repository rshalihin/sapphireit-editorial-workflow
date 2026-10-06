<?php
/**
 * Development seed data for performance measurements (step 21.1).
 *
 * Creates reviewers, workflow-managed posts with random statuses, reviewers
 * and due dates, and activity history. Dev only: `/bin` is export-ignored and
 * never ships in the release zip.
 *
 * Usage (wp-env or any WP-CLI site with the plugin active):
 *
 *     wp eval-file bin/seed.php [posts=500] [reviewers=10] [activity=5000]
 *
 * Seeded posts carry the `_sit_cwm_seed` meta key, so they can be removed with:
 *
 *     wp post delete $(wp post list --post_type=any --post_status=any --meta_key=_sit_cwm_seed --format=ids) --force
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
 * Seeds the site.
 *
 * @since 1.0.0
 *
 * @param array $cli_args Positional `wp eval-file` arguments: posts, reviewers, activity rows.
 * @return void
 */
function sit_cwm_seed( array $cli_args ) {
	$post_count     = isset( $cli_args[0] ) ? max( 1, absint( $cli_args[0] ) ) : 500;
	$reviewer_count = isset( $cli_args[1] ) ? max( 1, absint( $cli_args[1] ) ) : 10;
	$activity_count = isset( $cli_args[2] ) ? absint( $cli_args[2] ) : 5000;

	$statuses = new \Sit_Cwm\Workflow\StatusManager();
	$settings = new \Sit_Cwm\Core\Settings();
	$posts    = new \Sit_Cwm\Content\PostRepository( $statuses, $settings );
	$activity = new \Sit_Cwm\Activity\ActivityLogger( new \Sit_Cwm\Core\Database() );
	$slugs    = $statuses->slugs();
	$actions  = $activity->get_actions();

	wp_defer_term_counting( true );

	// Reviewers: editors hold `sit_cwm_review_content` by default.
	$reviewer_ids = array();

	for ( $i = 1; $i <= $reviewer_count; $i++ ) {
		$login    = 'sit_cwm_reviewer_' . $i;
		$existing = get_user_by( 'login', $login );

		if ( $existing ) {
			$reviewer_ids[] = $existing->ID;
			continue;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 24 ),
				'user_email'   => $login . '@example.test',
				'display_name' => 'Seed Reviewer ' . $i,
				'role'         => 'editor',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			WP_CLI::warning( $user_id->get_error_message() );
			continue;
		}

		$reviewer_ids[] = $user_id;
	}

	if ( array() === $reviewer_ids ) {
		WP_CLI::error( 'No reviewers could be created.' );
	}

	// Posts.
	$post_types = $settings->enabled_post_types();
	$post_type  = in_array( 'post', $post_types, true ) ? 'post' : (string) reset( $post_types );
	$author_id  = get_current_user_id() ? get_current_user_id() : $reviewer_ids[0];
	$post_ids   = array();
	$progress   = \WP_CLI\Utils\make_progress_bar( 'Creating posts', $post_count );

	for ( $i = 1; $i <= $post_count; $i++ ) {
		$status  = $slugs[ wp_rand( 0, count( $slugs ) - 1 ) ];
		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_title'   => 'CWM seed post ' . $i,
				'post_content' => 'Seeded for performance measurements.',
				'post_status'  => 'published' === $status ? 'publish' : 'draft',
				'post_author'  => $author_id,
				'meta_input'   => array( '_sit_cwm_seed' => 1 ),
			),
			true
		);

		$progress->tick();

		if ( is_wp_error( $post_id ) ) {
			continue;
		}

		$posts->set_status( $post_id, $status );

		// About one post in five stays unassigned or without a due date.
		if ( wp_rand( 1, 5 ) > 1 ) {
			$posts->set_reviewer_id( $post_id, $reviewer_ids[ wp_rand( 0, count( $reviewer_ids ) - 1 ) ] );
		}

		if ( wp_rand( 1, 5 ) > 1 ) {
			$posts->set_due_date( $post_id, gmdate( 'Y-m-d', time() + wp_rand( -30, 60 ) * DAY_IN_SECONDS ) );
		}

		$post_ids[] = $post_id;
	}

	$progress->finish();

	// Activity, spread over the last 90 days.
	$logged = 0;

	if ( array() !== $post_ids && array() !== $actions && $activity_count > 0 ) {
		$progress = \WP_CLI\Utils\make_progress_bar( 'Logging activity', $activity_count );

		for ( $i = 0; $i < $activity_count; $i++ ) {
			$entry_id = $activity->log(
				$post_ids[ wp_rand( 0, count( $post_ids ) - 1 ) ],
				$actions[ wp_rand( 0, count( $actions ) - 1 ) ],
				array(
					'message'    => 'Seeded activity entry.',
					'user_id'    => $reviewer_ids[ wp_rand( 0, count( $reviewer_ids ) - 1 ) ],
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - wp_rand( 0, 90 * DAY_IN_SECONDS ) ),
				)
			);

			if ( $entry_id > 0 ) {
				++$logged;
			}

			$progress->tick();
		}

		$progress->finish();
	}

	wp_defer_term_counting( false );

	WP_CLI::success(
		sprintf(
			'Seeded %d posts, %d reviewers and %d activity rows.',
			count( $post_ids ),
			count( $reviewer_ids ),
			$logged
		)
	);
}

sit_cwm_seed( isset( $args ) && is_array( $args ) ? $args : array() );
