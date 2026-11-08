<?php
/**
 * Integration tests for `GET /sit-cwm/v1/posts/summary` (20b).
 *
 * @package Sit_Cwm\Tests
 */

namespace Sit_Cwm\Tests\Integration\REST;

use Sit_Cwm\Core\Database;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Tests\Traits\RestoresPluginInstance;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\StatusManager;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The dashboard tiles' counts: same permission and visibility as the
 * collection, one count per tile.
 *
 * @coversDefaultClass \Sit_Cwm\REST\PostsController
 */
class PostsSummaryTest extends WP_UnitTestCase {

	use RestoresPluginInstance;

	/**
	 * Post repository, for arranging workflow state.
	 *
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Ensures the real activity table exists.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		( new Database() )->install();

		parent::set_up_before_class();
	}

	/**
	 * Grants default capabilities and starts a fresh REST server.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->remember_plugin_instance();

		delete_option( Settings::OPTION );
		Capabilities::add_caps();

		$this->posts = new PostRepository( new StatusManager(), new Settings() );

		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.
		rest_get_server();
	}

	/**
	 * Discards the REST server.
	 *
	 * @return void
	 */
	public function tear_down() {
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's REST server global.

		remove_all_filters( 'sit_cwm_statuses' );
		sit_cwm_container()->get( 'status_manager' )->flush();

		$this->restore_plugin_instance();

		parent::tear_down();
	}

	/**
	 * Logged out is 401, a subscriber is 403, like the collection.
	 *
	 * @covers ::get_items_permissions_check
	 *
	 * @return void
	 */
	public function test_logged_out_and_subscriber_are_rejected() {
		wp_set_current_user( 0 );
		$response = $this->summary();
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = $this->summary();
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'sit_cwm_forbidden', $response->as_error()->get_error_code() );
	}

	/**
	 * An editor gets one count per tile over all workflow content.
	 *
	 * @covers ::get_summary
	 *
	 * @return void
	 */
	public function test_counts_each_tile() {
		$editor   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author   = self::factory()->user->create( array( 'role' => 'author' ) );
		$past     = gmdate( 'Y-m-d', strtotime( '-3 days' ) );
		$future   = gmdate( 'Y-m-d', strtotime( '+3 days' ) );
		$reviewer = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->make_post( $author, 'review', $editor );
		$this->make_post( $author, 'review', $reviewer, $past );
		$this->make_post( $author, 'review' );
		$this->make_post( $author, 'needs_changes', 0, $past );
		$this->make_post( $author, 'approved', 0, $future );
		$this->make_post( $author, 'approved' );
		$this->make_post( $author, 'published', 0, $past );
		$this->make_post( $author, 'writing' );

		wp_set_current_user( $editor );

		$response = $this->summary();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'in_review'       => 3,
				'in_review_mine'  => 1,
				'needs_changes'   => 1,
				'overdue'         => 2,
				'approved'        => 2,
				'review_statuses' => array( 'review' ),
			),
			$response->get_data()
		);
	}

	/**
	 * An author only counts what they could list: their own content and
	 * content they review.
	 *
	 * @covers ::get_summary
	 *
	 * @return void
	 */
	public function test_author_counts_only_visible_content() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->make_post( $author, 'review' );
		$this->make_post( $other, 'review', $author );
		$this->make_post( $other, 'review' );
		$this->make_post( $other, 'approved' );

		wp_set_current_user( $author );

		$data = $this->summary()->get_data();

		$this->assertSame( 2, $data['in_review'] );
		$this->assertSame( 1, $data['in_review_mine'] );
		$this->assertSame( 0, $data['approved'] );
	}

	/**
	 * Content of a post type that is not workflow-enabled is never counted.
	 *
	 * @covers ::get_summary
	 *
	 * @return void
	 */
	public function test_disabled_post_types_are_not_counted() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post   = $this->make_post( $editor, 'review' );

		wp_set_current_user( $editor );
		$this->assertSame( 1, $this->summary()->get_data()['in_review'] );

		( new Settings() )->update( array( 'post_types' => array( 'page' ) ) );
		$this->assertSame( 0, $this->summary()->get_data()['in_review'] );
		$this->assertGreaterThan( 0, $post );
	}

	/**
	 * A status added through the filter counts in `in_review`, and its slug is
	 * returned so the tile can filter on it.
	 *
	 * @since 1.2.0
	 *
	 * @covers ::get_summary
	 *
	 * @return void
	 */
	public function test_summary_counts_filtered_review_statuses() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->add_legal_review_status();

		$this->make_post( $editor, 'review' );
		$this->make_post( $editor, 'legal_review', $editor );

		add_filter( 'sit_cwm_review_statuses', array( $this, 'add_legal_review' ) );
		wp_set_current_user( $editor );

		$data = $this->summary()->get_data();

		$this->assertSame( 2, $data['in_review'] );
		$this->assertSame( 1, $data['in_review_mine'] );
		$this->assertSame( array( 'review', 'legal_review' ), $data['review_statuses'] );
	}

	/**
	 * `review` stays whatever the filter does, and unknown slugs are dropped.
	 *
	 * @since 1.2.0
	 *
	 * @covers ::get_summary
	 *
	 * @return void
	 */
	public function test_review_cannot_be_removed_and_unknown_slugs_are_dropped() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		add_filter(
			'sit_cwm_review_statuses',
			static function () {
				return array( 'ghost', 7 );
			}
		);

		$this->assertSame( array( 'review' ), $this->summary()->get_data()['review_statuses'] );
	}

	/**
	 * A filter that returns something other than an array changes nothing.
	 *
	 * @since 1.2.0
	 *
	 * @covers ::get_summary
	 *
	 * @return void
	 */
	public function test_non_array_review_statuses_result_is_ignored() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		add_filter( 'sit_cwm_review_statuses', '__return_null' );

		$this->assertSame( array( 'review' ), $this->summary()->get_data()['review_statuses'] );
	}

	/**
	 * Filter callback: the extra review status.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $statuses Slugs.
	 * @return string[]
	 */
	public function add_legal_review( $statuses ) {
		$statuses[] = 'legal_review';

		return $statuses;
	}

	/**
	 * Registers `legal_review` on the status registry the route reads.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	private function add_legal_review_status() {
		add_filter(
			'sit_cwm_statuses',
			static function ( $statuses ) {
				$statuses[] = array(
					'slug'  => 'legal_review',
					'label' => 'Legal review',
					'order' => 35,
				);

				return $statuses;
			}
		);
		sit_cwm_container()->get( 'status_manager' )->flush();
		$this->posts = new PostRepository( new StatusManager(), new Settings() );
	}

	/**
	 * The response matches the route's schema.
	 *
	 * @covers ::get_summary_schema
	 *
	 * @return void
	 */
	public function test_response_matches_schema() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$data = $this->summary()->get_data();

		$options = rest_do_request( new WP_REST_Request( 'OPTIONS', '/sit-cwm/v1/posts/summary' ) )->get_data();
		$props   = $options['schema']['properties'];

		$this->assertSame( array_keys( $props ), array_keys( $data ) );

		foreach ( $data as $key => $value ) {
			$this->assertTrue( rest_validate_value_from_schema( $value, $props[ $key ], $key ), $key );
		}
	}

	/**
	 * Dispatches the summary request.
	 *
	 * @return WP_REST_Response
	 */
	private function summary(): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', '/sit-cwm/v1/posts/summary' ) );
	}

	/**
	 * Creates a draft post with workflow state.
	 *
	 * @param int    $author_id   Author user id.
	 * @param string $status      Workflow status.
	 * @param int    $reviewer_id Reviewer id; 0 for none.
	 * @param string $due_date    Due date; empty for none.
	 * @return int Post id.
	 */
	private function make_post( int $author_id, string $status, int $reviewer_id = 0, string $due_date = '' ): int {
		$post = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);

		$this->posts->set_status( $post, $status );

		if ( $reviewer_id > 0 ) {
			$this->posts->set_reviewer_id( $post, $reviewer_id );
		}

		if ( '' !== $due_date ) {
			$this->posts->set_due_date( $post, $due_date );
		}

		return $post;
	}
}
