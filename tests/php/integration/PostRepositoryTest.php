<?php
/**
 * Integration tests for the post repository.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Database;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\StatusManager;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflow meta persistence, sanitization, batch reads and user cleanup.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Content\PostRepository
 */
final class PostRepositoryTest extends WP_UnitTestCase {

	/**
	 * Status registry used by the repository.
	 *
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Service under test.
	 *
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Builds the service with default settings.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );

		$this->statuses = new StatusManager();
		$this->posts    = new PostRepository( $this->statuses, new Settings() );
	}

	/**
	 * Unset status reads as the default; valid statuses round-trip.
	 *
	 * @return void
	 */
	public function test_status_defaults_and_round_trips() {
		$post = self::factory()->post->create();

		$this->assertSame( 'draft', $this->posts->get_status( $post ) );
		$this->assertTrue( $this->posts->set_status( $post, 'review' ) );
		$this->assertSame( 'review', $this->posts->get_status( $post ) );
		$this->assertTrue( $this->posts->set_status( $post, 'review' ), 'Re-saving the same status still succeeds.' );
	}

	/**
	 * Unregistered statuses are rejected, not coerced to the default.
	 *
	 * @return void
	 */
	public function test_set_status_rejects_unknown_status() {
		$post = self::factory()->post->create();
		$this->posts->set_status( $post, 'review' );

		$this->assertFalse( $this->posts->set_status( $post, 'approvd' ) );
		$this->assertFalse( $this->posts->set_status( $post, 'Review' ) );
		$this->assertSame( 'review', $this->posts->get_status( $post ) );
	}

	/**
	 * A stored status that is no longer registered reads as the default.
	 *
	 * @return void
	 */
	public function test_status_removed_from_registry_reads_as_default() {
		$post = self::factory()->post->create();
		$this->posts->set_status( $post, 'writing' );

		add_filter(
			'sit_cwm_statuses',
			static function ( $statuses ) {
				unset( $statuses['writing'] );
				return $statuses;
			}
		);
		$this->statuses->flush();

		$this->assertSame( 'draft', $this->posts->get_status( $post ) );
		$this->assertTrue( $this->posts->has_unknown_status( $post ) );
	}

	/**
	 * Unset and registered statuses are not reported as unknown.
	 *
	 * @return void
	 */
	public function test_has_unknown_status_is_false_for_unset_and_registered() {
		$post = self::factory()->post->create();

		$this->assertFalse( $this->posts->has_unknown_status( $post ) );

		$this->posts->set_status( $post, 'review' );

		$this->assertFalse( $this->posts->has_unknown_status( $post ) );
	}

	/**
	 * Invalid calendar dates store `''`; valid ones round-trip; `''` clears.
	 *
	 * @return void
	 */
	public function test_due_date_validation() {
		$post = self::factory()->post->create();

		$this->assertTrue( $this->posts->set_due_date( $post, '2026-09-20' ) );
		$this->assertSame( '2026-09-20', $this->posts->get_due_date( $post ) );

		$this->assertTrue( $this->posts->set_due_date( $post, '2026-02-31' ) );
		$this->assertSame( '', $this->posts->get_due_date( $post ) );
		$this->assertFalse( metadata_exists( 'post', $post, PostRepository::META_DUE_DATE ) );

		$this->posts->set_due_date( $post, '2026-09-20' );
		$this->assertTrue( $this->posts->set_due_date( $post, '' ) );
		$this->assertSame( '', $this->posts->get_due_date( $post ) );

		foreach ( array( '20-09-2026', '2026-9-20', '2026-09-20 10:00', 'tomorrow', '2026-13-01' ) as $invalid ) {
			$this->assertSame( '', $this->posts->sanitize_due_date( $invalid ), $invalid );
		}

		$this->assertSame( '2024-02-29', $this->posts->sanitize_due_date( ' 2024-02-29 ' ) );
		$this->assertSame( '', $this->posts->sanitize_due_date( array( '2026-09-20' ) ) );
	}

	/**
	 * Unknown users store `0`; real users round-trip; `0` clears.
	 *
	 * @return void
	 */
	public function test_reviewer_validation() {
		$post     = self::factory()->post->create();
		$reviewer = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertTrue( $this->posts->set_reviewer_id( $post, 999999 ) );
		$this->assertSame( 0, $this->posts->get_reviewer_id( $post ) );
		$this->assertFalse( metadata_exists( 'post', $post, PostRepository::META_REVIEWER ) );

		$this->assertTrue( $this->posts->set_reviewer_id( $post, $reviewer ) );
		$this->assertSame( $reviewer, $this->posts->get_reviewer_id( $post ) );

		$this->assertTrue( $this->posts->set_reviewer_id( $post, 0 ) );
		$this->assertSame( 0, $this->posts->get_reviewer_id( $post ) );
	}

	/**
	 * The reviewer sanitizer never flips a negative id onto a real user and
	 * rejects non-integers.
	 *
	 * @return void
	 */
	public function test_sanitize_reviewer_id_rejects_non_positive_integers() {
		$user = self::factory()->user->create();

		$this->assertSame( $user, $this->posts->sanitize_reviewer_id( $user ) );
		$this->assertSame( $user, $this->posts->sanitize_reviewer_id( (string) $user ) );

		foreach ( array( -$user, "{$user}abc", (float) $user + 0.5, true, null, array( $user ), '' ) as $invalid ) {
			$this->assertSame( 0, $this->posts->sanitize_reviewer_id( $invalid ), var_export( $invalid, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Assertion message.
		}
	}

	/**
	 * Writers refuse non-positive post ids.
	 *
	 * @return void
	 */
	public function test_writers_reject_invalid_post_id() {
		$this->assertFalse( $this->posts->set_status( 0, 'review' ) );
		$this->assertFalse( $this->posts->set_reviewer_id( 0, 0 ) );
		$this->assertFalse( $this->posts->set_due_date( -1, '' ) );
		$this->assertSame( 'draft', $this->posts->get_status( 0 ) );
	}

	/**
	 * Managed means: post exists and its type is enabled.
	 *
	 * @return void
	 */
	public function test_is_managed() {
		$post = self::factory()->post->create();
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertTrue( $this->posts->is_managed( $post ) );
		$this->assertTrue( $this->posts->is_managed( $page ) );
		$this->assertFalse( $this->posts->is_managed( 999999 ) );
		$this->assertFalse( $this->posts->is_managed( 0 ) );

		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) );

		$this->assertFalse( $this->posts->is_managed( $page ) );
		$this->assertSame( array(), $this->posts->get_workflow( $page ) );
	}

	/**
	 * `get_workflow()` returns raw state plus post basics.
	 *
	 * @return void
	 */
	public function test_get_workflow_shape() {
		$author   = self::factory()->user->create( array( 'role' => 'author' ) );
		$reviewer = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post     = self::factory()->post->create(
			array(
				'post_title'  => 'Product Guide',
				'post_author' => $author,
				'post_status' => 'draft',
			)
		);

		$this->posts->set_status( $post, 'review' );
		$this->posts->set_reviewer_id( $post, $reviewer );
		$this->posts->set_due_date( $post, '2026-09-20' );

		$this->assertSame(
			array(
				'post_id'     => $post,
				'post_title'  => 'Product Guide',
				'post_type'   => 'post',
				'post_status' => 'draft',
				'post_author' => $author,
				'status'      => 'review',
				'reviewer_id' => $reviewer,
				'due_date'    => '2026-09-20',
			),
			$this->posts->get_workflow( $post )
		);
	}

	/**
	 * Batch status read over 50 cold posts adds at most 2 queries.
	 *
	 * @return void
	 */
	public function test_get_statuses_for_posts_is_batched() {
		global $wpdb;

		$ids = self::factory()->post->create_many( 50 );
		$this->posts->set_status( $ids[0], 'review' );
		$this->posts->set_status( $ids[49], 'approved' );
		$this->flush_meta_cache( $ids );

		$before   = $wpdb->num_queries;
		$statuses = $this->posts->get_statuses_for_posts( $ids );

		$this->assertLessThanOrEqual( 2, $wpdb->num_queries - $before );
		$this->assertCount( 50, $statuses );
		$this->assertSame( $ids, array_keys( $statuses ) );
		$this->assertSame( 'review', $statuses[ $ids[0] ] );
		$this->assertSame( 'approved', $statuses[ $ids[49] ] );
		$this->assertSame( 'draft', $statuses[ $ids[1] ] );
	}

	/**
	 * Batch reviewer read over 50 cold posts adds at most 2 queries.
	 *
	 * @return void
	 */
	public function test_get_reviewers_for_posts_is_batched() {
		global $wpdb;

		$reviewer = self::factory()->user->create( array( 'role' => 'editor' ) );
		$ids      = self::factory()->post->create_many( 50 );
		$this->posts->set_reviewer_id( $ids[10], $reviewer );
		$this->flush_meta_cache( $ids );

		$before    = $wpdb->num_queries;
		$reviewers = $this->posts->get_reviewers_for_posts( $ids );

		$this->assertLessThanOrEqual( 2, $wpdb->num_queries - $before );
		$this->assertCount( 50, $reviewers );
		$this->assertSame( $reviewer, $reviewers[ $ids[10] ] );
		$this->assertSame( 0, $reviewers[ $ids[11] ] );
	}

	/**
	 * Batch methods drop invalid and duplicate ids and query nothing for none.
	 *
	 * @return void
	 */
	public function test_batch_methods_normalize_ids() {
		global $wpdb;

		$post = self::factory()->post->create();

		$this->assertSame( array( $post => 'draft' ), $this->posts->get_statuses_for_posts( array( $post, 0, -3, (string) $post ) ) );

		$before = $wpdb->num_queries;
		$this->assertSame( array(), $this->posts->get_reviewers_for_posts( array() ) );
		$this->assertSame( 0, $wpdb->num_queries - $before );
	}

	/**
	 * Deleting a user clears their reviewer assignment on every post (any
	 * type or status, beyond one query batch) and leaves other reviewers alone.
	 *
	 * Uses the hook registered by the booted plugin, not a manual call.
	 *
	 * @return void
	 */
	public function test_deleting_user_clears_reviewer_assignments() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$doomed = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other  = self::factory()->user->create( array( 'role' => 'editor' ) );

		$ids   = self::factory()->post->create_many( PostRepository::CLEAR_BATCH_SIZE + 2 );
		$ids[] = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$ids[] = self::factory()->post->create( array( 'post_status' => 'trash' ) );

		foreach ( $ids as $id ) {
			$this->posts->set_reviewer_id( $id, $doomed );
		}

		$kept = self::factory()->post->create();
		$this->posts->set_reviewer_id( $kept, $other );

		$fired = array();
		add_action(
			'sit_cwm_reviewer_assigned',
			static function ( ...$args ) use ( &$fired ) {
				$fired[] = $args;
			},
			10,
			4
		);

		wp_delete_user( $doomed );

		foreach ( $ids as $id ) {
			$this->assertSame( 0, $this->posts->get_reviewer_id( $id ), "Post {$id}" );
		}

		$this->assertSame( $other, $this->posts->get_reviewer_id( $kept ) );
		$this->assertCount( count( $ids ), $fired );
		$this->assertSame( array( $ids[0], 0, $doomed, 0 ), $fired[0] );

		$cleared = ( new ActivityLogger( new Database() ) )->get_for_post( $ids[0], array( 'action' => 'reviewer_cleared' ) );
		$this->assertCount( 1, $cleared );
		$this->assertSame( 0, $cleared[0]->get_user_id() );
		$this->assertSame( (string) $doomed, $cleared[0]->get_old_value() );
		$this->assertNull( $cleared[0]->get_new_value() );
	}

	/**
	 * Evicts posts' meta from the object cache so the next read is cold.
	 *
	 * @param int[] $ids Post ids.
	 * @return void
	 */
	private function flush_meta_cache( array $ids ) {
		foreach ( $ids as $id ) {
			wp_cache_delete( $id, 'post_meta' );
		}
	}
}
