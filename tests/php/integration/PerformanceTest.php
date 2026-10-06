<?php
/**
 * Query budgets for the hot REST paths (step 21.3).
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Core\Settings;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Counts `$wpdb->num_queries` around REST dispatches.
 *
 * Every measured request runs after a warm-up of the same request, so option,
 * capability and current-user caches are warm (as they are after the first
 * request of a real page load), and the posts under test are then evicted from
 * the object cache so their loading is counted. The asserted budgets are the
 * ones in DEVELOPMENT.md → Performance; the scaling assertions are what catch
 * an N+1.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\REST\PostsController
 * @covers \Sit_Cwm\REST\WorkflowController
 * @covers \Sit_Cwm\REST\ActivityController
 * @covers \Sit_Cwm\Workflow\BulkProcessor
 * @covers \Sit_Cwm\Core\Settings::seed
 */
final class PerformanceTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Title word isolating this class's posts from installer content.
	 *
	 * @var string
	 */
	const PROBE = 'perfprobe';

	/**
	 * `GET /posts?per_page=100` stays within 8 queries and does not grow with
	 * the number of rows, reviewers or activity entries.
	 *
	 * @return void
	 */
	public function test_posts_collection_query_count_is_flat() {
		$reviewers = $this->make_reviewers( 3 );
		$first     = $this->make_posts( 30, $reviewers );

		wp_set_current_user( self::fixture_user( 'admin' ) );

		$small = $this->measure_collection( $first );

		$this->assertLessThanOrEqual( 8, $small, 'GET /posts exceeded its query budget.' );

		$second = $this->make_posts( 30, $reviewers );
		$large  = $this->measure_collection( array_merge( $first, $second ) );

		$this->assertSame( $small, $large, 'GET /posts query count grew with the number of rows.' );
	}

	/**
	 * `GET /posts/<id>/workflow` stays within 5 queries with a cold post.
	 *
	 * @return void
	 */
	public function test_get_workflow_query_count() {
		$post  = $this->make_posts( 1, $this->make_reviewers( 1 ) )[0];
		$route = '/sit-cwm/v1/posts/' . $post . '/workflow';

		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->assertSame( 200, $this->rest_request( 'GET', $route )->get_status() );

		clean_post_cache( $post );

		$count = $this->count_queries(
			function () use ( $route ) {
				$this->assertSame( 200, $this->rest_request( 'GET', $route )->get_status() );
			}
		);

		$this->assertLessThanOrEqual( 5, $count, 'GET /posts/<id>/workflow exceeded its query budget.' );
	}

	/**
	 * `GET /posts/<id>/activity?per_page=20` stays within 4 queries, however
	 * many entries and distinct users the timeline has.
	 *
	 * The post itself is warm: the timeline is loaded by an editor that
	 * already loaded it.
	 *
	 * @return void
	 */
	public function test_activity_timeline_query_count() {
		$reviewers = $this->make_reviewers( 5 );
		$post      = $this->make_posts( 1, $reviewers )[0];
		$route     = '/sit-cwm/v1/posts/' . $post . '/activity';
		$query     = array( 'per_page' => 20 );

		$this->log_activity( $post, 40, $reviewers );

		wp_set_current_user( self::fixture_user( 'admin' ) );
		$this->assertSame( 200, $this->rest_request( 'GET', $route, null, $query )->get_status() );

		$count = $this->count_queries(
			function () use ( $route, $query ) {
				$response = $this->rest_request( 'GET', $route, null, $query );

				$this->assertSame( 200, $response->get_status() );
				$this->assertCount( 20, $response->get_data() );
			}
		);

		$this->assertLessThanOrEqual( 4, $count, 'GET /posts/<id>/activity exceeded its query budget.' );
	}

	/**
	 * `POST /posts/<id>/workflow` (a status change) stays within 10 queries.
	 *
	 * @return void
	 */
	public function test_post_workflow_query_count() {
		$posts = $this->make_posts( 2, $this->make_reviewers( 1 ) );

		wp_set_current_user( self::fixture_user( 'admin' ) );

		// Warm-up on a sibling post, so the measured post is still cold.
		$this->transition_to_writing( $posts[0] );

		clean_post_cache( $posts[1] );

		$count = $this->count_queries(
			function () use ( $posts ) {
				$this->transition_to_writing( $posts[1] );
			}
		);

		$this->assert_status( $posts[1], 'writing' );
		$this->assertLessThanOrEqual( 10, $count, 'POST /posts/<id>/workflow exceeded its query budget.' );
	}

	/**
	 * `POST /posts/batch` runs no user query per post: doubling the batch, with
	 * a distinct reviewer on every post, leaves the user queries unchanged and
	 * grows the total no more than linearly.
	 *
	 * @return void
	 */
	public function test_batch_has_no_per_post_user_query() {
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$small_posts = $this->make_batch_posts( 10 );
		$large_posts = $this->make_batch_posts( 20 );

		// Warm-up: loads the acting user, options and routes.
		$warm_up = $this->make_batch_posts( 1 );
		$this->batch_to_writing( $warm_up );

		$small = $this->measure_batch( $small_posts );
		$large = $this->measure_batch( $large_posts );

		$this->assertSame( $small['users'], $large['users'], 'POST /posts/batch ran user queries per post.' );
		$this->assertLessThanOrEqual( 2 * $small['total'] + 10, $large['total'], 'POST /posts/batch query count grew faster than linearly.' );
	}

	/**
	 * `sit_cwm_settings` is autoloaded when seeded and after an update.
	 *
	 * @return void
	 */
	public function test_settings_option_is_autoloaded() {
		$settings = new Settings();

		$settings->seed();
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayHasKey( Settings::OPTION, wp_load_alloptions(), 'Seeded settings are not autoloaded.' );

		$this->assertTrue( $settings->update( array( 'post_types' => array( 'post' ) ) ) );
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertArrayHasKey( Settings::OPTION, wp_load_alloptions(), 'Updated settings are not autoloaded.' );
	}

	/**
	 * Queries run by a GET /posts request with the given posts evicted from cache.
	 *
	 * @param int[] $post_ids Posts to evict before measuring.
	 * @return int Query count.
	 */
	private function measure_collection( array $post_ids ): int {
		$query = array(
			'search'   => self::PROBE,
			'per_page' => 100,
		);

		// Warm-up.
		$this->assertSame( 200, $this->rest_request( 'GET', '/sit-cwm/v1/posts', null, $query )->get_status() );

		array_map( 'clean_post_cache', $post_ids );

		return $this->count_queries(
			function () use ( $query, $post_ids ) {
				$response = $this->rest_request( 'GET', '/sit-cwm/v1/posts', null, $query );

				$this->assertSame( 200, $response->get_status() );
				$this->assertCount( count( $post_ids ), $response->get_data() );
			}
		);
	}

	/**
	 * Total and user-table queries of a cold batch moving posts to `writing`.
	 *
	 * @param int[] $post_ids Posts, each with its own reviewer.
	 * @return array{total: int, users: int}
	 */
	private function measure_batch( array $post_ids ): array {
		global $wpdb;

		foreach ( $post_ids as $post_id ) {
			clean_user_cache( $this->posts->get_reviewer_id( $post_id ) );
			clean_post_cache( $post_id );
		}

		$users   = 0;
		$pattern = '/\b(' . preg_quote( $wpdb->users, '/' ) . '|' . preg_quote( $wpdb->usermeta, '/' ) . ')\b/';
		$counter = static function ( $sql ) use ( &$users, $pattern ) {
			if ( preg_match( $pattern, $sql ) ) {
				++$users;
			}

			return $sql;
		};

		add_filter( 'query', $counter );

		$total = $this->count_queries(
			function () use ( $post_ids ) {
				$this->batch_to_writing( $post_ids );
			}
		);

		remove_filter( 'query', $counter );

		return array(
			'total' => $total,
			'users' => $users,
		);
	}

	/**
	 * Moves posts to `writing` through the batch route and asserts success.
	 *
	 * @param int[] $post_ids Post ids.
	 * @return void
	 */
	private function batch_to_writing( array $post_ids ): void {
		$response = $this->rest_request(
			'POST',
			'/sit-cwm/v1/posts/batch',
			array(
				'post_ids' => $post_ids,
				'action'   => 'change_status',
				'payload'  => array( 'status' => 'writing' ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( count( $post_ids ), $this->batch_succeeded( $response ) );
	}

	/**
	 * Succeeded ids from a batch response.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return int[]
	 */
	private function batch_succeeded( WP_REST_Response $response ): array {
		$data = $response->get_data();

		return isset( $data['succeeded'] ) ? (array) $data['succeeded'] : array();
	}

	/**
	 * Moves a draft post to `writing` through the single-post route.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	private function transition_to_writing( int $post_id ): void {
		$response = $this->rest_request(
			'POST',
			'/sit-cwm/v1/posts/' . $post_id . '/workflow',
			array(
				'status' => 'writing',
				'from'   => 'draft',
			)
		);

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Runs a callback and returns how many queries it ran.
	 *
	 * @param callable $callback Callback.
	 * @return int Query count.
	 */
	private function count_queries( callable $callback ): int {
		global $wpdb;

		$before = $wpdb->num_queries;
		$callback();

		return $wpdb->num_queries - $before;
	}

	/**
	 * Creates reviewers (editors).
	 *
	 * @param int $count Number of reviewers.
	 * @return int[] User ids.
	 */
	private function make_reviewers( int $count ): array {
		return self::factory()->user->create_many( $count, array( 'role' => 'editor' ) );
	}

	/**
	 * Creates draft posts in the workflow, round-robin over reviewers, each with
	 * one activity entry.
	 *
	 * @param int   $count     Number of posts.
	 * @param int[] $reviewers Reviewer ids.
	 * @return int[] Post ids.
	 */
	private function make_posts( int $count, array $reviewers ): array {
		$authors = array( self::fixture_user( 'author' ), self::fixture_user( 'editor' ) );
		$ids     = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$post_id = $this->create_managed_post(
				array(
					'post_title'      => self::PROBE . ' ' . $i,
					'post_author'     => $authors[ $i % count( $authors ) ],
					'post_status'     => 'draft',
					'workflow_status' => 'draft',
					'reviewer_id'     => $reviewers[ $i % count( $reviewers ) ],
					'due_date'        => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
				)
			);

			$this->log_activity( $post_id, 1, $reviewers );

			$ids[] = $post_id;
		}

		return $ids;
	}

	/**
	 * Creates draft posts, each with its own new reviewer.
	 *
	 * @param int $count Number of posts.
	 * @return int[] Post ids.
	 */
	private function make_batch_posts( int $count ): array {
		$ids = array();

		foreach ( $this->make_reviewers( $count ) as $reviewer ) {
			$ids = array_merge( $ids, $this->make_posts( 1, array( $reviewer ) ) );
		}

		return $ids;
	}

	/**
	 * Logs activity entries by the given users.
	 *
	 * @param int   $post_id Post id.
	 * @param int   $count   Number of entries.
	 * @param int[] $users   Acting user ids, round-robin.
	 * @return void
	 */
	private function log_activity( int $post_id, int $count, array $users ): void {
		$actions = $this->activity->get_actions();

		for ( $i = 0; $i < $count; $i++ ) {
			$this->activity->log(
				$post_id,
				$actions[ $i % count( $actions ) ],
				array(
					'message' => 'Performance fixture.',
					'user_id' => $users[ $i % count( $users ) ],
				)
			);
		}
	}
}
