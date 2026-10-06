<?php
/**
 * Integration tests for the activity logger.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Activity\ActivityEntry;
use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Core\Database;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logging, sanitization, reads, batching and post-deletion cleanup.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Activity\ActivityLogger
 * @covers \Sit_Cwm\Activity\ActivityEntry
 */
final class ActivityLoggerTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Schema owner.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Post the tests log against.
	 *
	 * @var int
	 */
	private $post;

	/**
	 * Ensures the real activity table exists. MySQL cannot reference one
	 * temporary table twice in a query, which `get_for_posts()` does.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		( new Database() )->install();

		parent::set_up_before_class();
	}

	/**
	 * Builds the service and a post.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->database = new Database();
		$this->activity = new ActivityLogger( $this->database );
		$this->post     = self::factory()->post->create();
	}

	/**
	 * Each valid action inserts exactly one row with the given columns.
	 *
	 * @return void
	 */
	public function test_log_inserts_one_row_per_valid_action() {
		$user = self::factory()->user->create();

		foreach ( ActivityLogger::ACTIONS as $action ) {
			$before = $this->row_count();

			$id = $this->activity->log(
				$this->post,
				$action,
				array(
					'old_value'  => 'old',
					'new_value'  => 'new',
					'message'    => 'Hello',
					'context'    => array( 'source' => 'test' ),
					'user_id'    => $user,
					'created_at' => '2026-09-14 10:00:00',
				)
			);

			$this->assertGreaterThan( 0, $id, $action );
			$this->assertSame( $before + 1, $this->row_count(), $action );

			$row = $this->get_row( $id );

			$this->assertSame( (string) $this->post, $row->post_id );
			$this->assertSame( (string) $user, $row->user_id );
			$this->assertSame( $action, $row->action );
			$this->assertSame( 'old', $row->old_value );
			$this->assertSame( 'new', $row->new_value );
			$this->assertSame( 'Hello', $row->message );
			$this->assertSame( '{"source":"test"}', $row->context );
			$this->assertSame( '2026-09-14 10:00:00', $row->created_at );
		}
	}

	/**
	 * Unknown actions and invalid post ids insert nothing and return `0`.
	 *
	 * @return void
	 */
	public function test_invalid_action_inserts_nothing() {
		$before = $this->row_count();

		foreach ( array( 'status_change', 'STATUS_CHANGED', '', 'status_changed; DROP TABLE' ) as $action ) {
			$this->assertSame( 0, $this->activity->log( $this->post, $action ), $action );
		}

		$this->assertSame( 0, $this->activity->log( 0, 'status_changed' ) );
		$this->assertSame( 0, $this->activity->log( -5, 'status_changed' ) );
		$this->assertSame( $before, $this->row_count() );
	}

	/**
	 * The action filter can add slugs; malformed slugs are dropped.
	 *
	 * @return void
	 */
	public function test_actions_filter() {
		add_filter(
			'sit_cwm_activity_actions',
			static function ( $actions ) {
				$actions[] = 'checklist_completed';
				$actions[] = 'Bad Slug';
				$actions[] = str_repeat( 'a', 51 );
				return $actions;
			}
		);

		$this->assertGreaterThan( 0, $this->activity->log( $this->post, 'checklist_completed' ) );
		$this->assertSame( 0, $this->activity->log( $this->post, 'Bad Slug' ) );
		$this->assertNotContains( str_repeat( 'a', 51 ), $this->activity->get_actions() );
	}

	/**
	 * Messages keep post-safe HTML only; values are plain text, truncated.
	 *
	 * @return void
	 */
	public function test_sanitization() {
		$id = $this->activity->log(
			$this->post,
			'comment_added',
			array(
				'message'   => '<strong>Looks good</strong><script>alert(1)</script>',
				'old_value' => '<b>x</b>',
				'new_value' => str_repeat( 'é', 300 ),
			)
		);

		$row = $this->get_row( $id );

		$this->assertStringContainsString( '<strong>Looks good</strong>', $row->message );
		$this->assertStringNotContainsString( '<script', $row->message );
		$this->assertSame( 'x', $row->old_value );
		$this->assertSame( 191, mb_strlen( $row->new_value ) );
	}

	/**
	 * Defaults: current user, UTC now, NULL optional columns; the logged action
	 * fires with the entry.
	 *
	 * @return void
	 */
	public function test_defaults_and_logged_action() {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		$fired = array();
		add_action(
			'sit_cwm_activity_logged',
			static function ( ...$args ) use ( &$fired ) {
				$fired[] = $args;
			},
			10,
			2
		);

		$id  = $this->activity->log( $this->post, 'status_changed', array( 'created_at' => 'not a date' ) );
		$row = $this->get_row( $id );

		$this->assertSame( (string) $user, $row->user_id );
		$this->assertNull( $row->old_value );
		$this->assertNull( $row->message );
		$this->assertNull( $row->context );
		$this->assertLessThanOrEqual( 5, abs( strtotime( $row->created_at . ' UTC' ) - time() ) );

		$this->assertCount( 1, $fired );
		$this->assertSame( $id, $fired[0][0] );
		$this->assertInstanceOf( ActivityEntry::class, $fired[0][1] );
		$this->assertSame( $id, $fired[0][1]->get_id() );
		$this->assertSame( $this->post, $fired[0][1]->get_post_id() );

		$system = $this->activity->log( $this->post, 'status_changed', array( 'user_id' => 0 ) );
		$this->assertSame( '0', $this->get_row( $system )->user_id );
	}

	/**
	 * Convenience loggers pick the right action and values.
	 *
	 * @return void
	 */
	public function test_convenience_loggers() {
		$entries = array(
			$this->activity->log_status_change( $this->post, 'draft', 'writing', 7 ),
			$this->activity->log_reviewer_assigned( $this->post, 27, 0, 7 ),
			$this->activity->log_reviewer_assigned( $this->post, 0, 27, 7 ),
			$this->activity->log_due_date_changed( $this->post, '2026-09-20', '', 7 ),
			$this->activity->log_due_date_changed( $this->post, '', '2026-09-20', 7 ),
			$this->activity->log_comment( $this->post, 'Please revise.', 7 ),
		);

		$expected = array(
			array( 'status_changed', 'draft', 'writing', null ),
			array( 'reviewer_assigned', null, '27', null ),
			array( 'reviewer_cleared', '27', null, null ),
			array( 'due_date_set', null, '2026-09-20', null ),
			array( 'due_date_cleared', '2026-09-20', null, null ),
			array( 'comment_added', null, null, 'Please revise.' ),
		);

		foreach ( $entries as $index => $id ) {
			$row = $this->get_row( $id );
			$this->assertSame( $expected[ $index ], array( $row->action, $row->old_value, $row->new_value, $row->message ), (string) $index );
			$this->assertSame( '7', $row->user_id );
		}

		$this->assertSame( 0, $this->activity->log_comment( $this->post, '  <p> </p><script></script> ' ) );
	}

	/**
	 * Reads paginate, default to newest first, clamp page size and filter by
	 * action; counts agree.
	 *
	 * @return void
	 */
	public function test_get_for_post_pagination_and_order() {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->activity->log(
				$this->post,
				0 === $i % 5 ? 'comment_added' : 'status_changed',
				array(
					'new_value'  => (string) $i,
					'created_at' => gmdate( 'Y-m-d H:i:s', 1789000000 + $i * 60 ),
				)
			);
		}

		$first = $this->activity->get_for_post( $this->post );
		$this->assertCount( 20, $first );
		$this->assertSame( '24', $first[0]->get_new_value() );
		$this->assertSame( '5', $first[19]->get_new_value() );

		$second = $this->activity->get_for_post( $this->post, array( 'page' => 2 ) );
		$this->assertSame( array( '4', '3', '2', '1', '0' ), $this->new_values( $second ) );

		$asc = $this->activity->get_for_post(
			$this->post,
			array(
				'order'    => 'asc',
				'per_page' => 3,
			)
		);
		$this->assertSame( array( '0', '1', '2' ), $this->new_values( $asc ) );

		$this->assertCount( 25, $this->activity->get_for_post( $this->post, array( 'per_page' => 500 ) ) );
		$this->assertCount( 20, $this->activity->get_for_post( $this->post, array( 'per_page' => -1 ) ) );

		$comments = $this->activity->get_for_post( $this->post, array( 'action' => 'comment_added' ) );
		$this->assertSame( array( '20', '15', '10', '5', '0' ), $this->new_values( $comments ) );

		$this->assertSame( 25, $this->activity->count_for_post( $this->post ) );
		$this->assertSame( 5, $this->activity->count_for_post( $this->post, array( 'action' => 'comment_added' ) ) );
		$this->assertSame( 0, $this->activity->count_for_post( $this->post, array( 'action' => 'nope' ) ) );
		$this->assertSame( array(), $this->activity->get_for_post( 0 ) );
	}

	/**
	 * Injection attempts through read arguments are neutralized.
	 *
	 * @return void
	 */
	public function test_read_arguments_cannot_inject_sql() {
		$this->activity->log( $this->post, 'status_changed', array( 'created_at' => '2026-09-01 00:00:00' ) );
		$this->activity->log( $this->post, 'status_changed', array( 'created_at' => '2026-09-02 00:00:00' ) );

		$entries = $this->activity->get_for_post( $this->post, array( 'order' => '1; DROP TABLE ' . $this->database->table_name() ) );

		$this->assertCount( 2, $entries );
		$this->assertSame( '2026-09-02 00:00:00', $entries[0]->get_created_at(), 'Invalid order falls back to DESC.' );

		$this->assertSame( array(), $this->activity->get_for_post( $this->post, array( 'action' => "status_changed' OR '1'='1" ) ) );
		$this->assertCount( 2, $this->activity->get_for_post( $this->post, array( 'page' => '1 OR 1=1' ) ) );
		$this->assertSame( 2, $this->activity->count_for_post( $this->post ) );
	}

	/**
	 * Batch read runs one query for 50 posts and returns the latest N each.
	 *
	 * @return void
	 */
	public function test_get_for_posts_is_one_query() {
		global $wpdb;

		$posts = self::factory()->post->create_many( 2 );

		foreach ( array( '01', '02', '03' ) as $day ) {
			$this->activity->log( $posts[0], 'status_changed', array( 'new_value' => $day, 'created_at' => "2026-09-{$day} 00:00:00" ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		}

		// Identical timestamps: the higher id is newer.
		$this->activity->log( $posts[1], 'status_changed', array( 'new_value' => 'a', 'created_at' => '2026-09-05 00:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.
		$this->activity->log( $posts[1], 'status_changed', array( 'new_value' => 'b', 'created_at' => '2026-09-05 00:00:00' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact fixture.

		$ids = array_values( array_unique( array_merge( range( 1, 50 ), $posts ) ) );

		$before = $wpdb->num_queries;
		$latest = $this->activity->get_for_posts( range( 1, 50 ) );
		$this->assertSame( 1, $wpdb->num_queries - $before );
		$this->assertCount( 50, $latest );

		$result = $this->activity->get_for_posts( $ids );
		$this->assertSame( $ids, array_keys( $result ) );
		$this->assertSame( array( '03' ), $this->new_values( $result[ $posts[0] ] ) );
		$this->assertSame( array( 'b' ), $this->new_values( $result[ $posts[1] ] ) );

		$two = $this->activity->get_for_posts( array( $posts[0], $posts[1], -1, 0, (string) $posts[0], true ), 2 );
		$this->assertSame( array( $posts[0], $posts[1] ), array_keys( $two ) );
		$this->assertSame( array( '03', '02' ), $this->new_values( $two[ $posts[0] ] ) );
		$this->assertSame( array( 'b', 'a' ), $this->new_values( $two[ $posts[1] ] ) );

		$before = $wpdb->num_queries;
		$this->assertSame( array(), $this->activity->get_for_posts( array( 0, 'x' ) ) );
		$this->assertSame( 0, $wpdb->num_queries - $before );
	}

	/**
	 * Permanently deleting a post removes its rows; trashing keeps them.
	 *
	 * Uses the hook registered by the booted plugin, not a manual call.
	 *
	 * @return void
	 */
	public function test_permanent_delete_removes_rows_trash_does_not() {
		$other = self::factory()->post->create();

		$this->activity->log( $this->post, 'status_changed' );
		$this->activity->log( $this->post, 'comment_added', array( 'message' => 'Hi' ) );
		$this->activity->log( $other, 'status_changed' );

		wp_trash_post( $this->post );
		$this->assertSame( 2, $this->activity->count_for_post( $this->post ) );

		wp_delete_post( $this->post, true );
		$this->assertSame( 0, $this->activity->count_for_post( $this->post ) );
		$this->assertSame( 1, $this->activity->count_for_post( $other ) );
	}

	/**
	 * `delete_for_post()` reports the rows it removed.
	 *
	 * @return void
	 */
	public function test_delete_for_post_returns_count() {
		$this->activity->log( $this->post, 'status_changed' );
		$this->activity->log( $this->post, 'status_changed' );

		$this->assertSame( 2, $this->activity->delete_for_post( $this->post ) );
		$this->assertSame( 0, $this->activity->delete_for_post( $this->post ) );
		$this->assertSame( 0, $this->activity->delete_for_post( 0 ) );
	}

	/**
	 * Context is JSON only: serialized PHP in the column is never unserialized.
	 *
	 * @return void
	 */
	public function test_entry_context_is_json_only() {
		$entry = ActivityEntry::from_row(
			(object) array(
				'id'         => '3',
				'post_id'    => '4',
				'user_id'    => '0',
				'action'     => 'status_changed',
				'old_value'  => null,
				'new_value'  => 'review',
				'message'    => null,
				'context'    => serialize( array( 'evil' => true ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Fixture proving it is not unserialized.
				'created_at' => '2026-09-14 10:00:00',
			)
		);

		$this->assertSame( array(), $entry->get_context() );
		$this->assertSame(
			array(
				'id'         => 3,
				'post_id'    => 4,
				'user_id'    => 0,
				'action'     => 'status_changed',
				'old_value'  => null,
				'new_value'  => 'review',
				'message'    => null,
				'context'    => array(),
				'created_at' => '2026-09-14 10:00:00',
			),
			$entry->to_array()
		);

		$id = $this->activity->log( $this->post, 'status_changed', array( 'context' => array( 'nested' => array( 'a' => 1 ) ) ) );
		$this->assertSame( array( 'nested' => array( 'a' => 1 ) ), $this->activity->get_for_post( $this->post )[0]->get_context() );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Total rows in the activity table.
	 *
	 * @return int
	 */
	private function row_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->database->table_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test helper.
	}

	/**
	 * Raw activity row by id.
	 *
	 * @param int $id Activity id.
	 * @return object
	 */
	private function get_row( int $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->database->table_name(), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test helper.

		$this->assertIsObject( $row, "Activity row {$id}" );

		return $row;
	}

	/**
	 * `new_value` of each entry.
	 *
	 * @param ActivityEntry[] $entries Entries.
	 * @return array
	 */
	private function new_values( array $entries ): array {
		return array_map(
			static function ( ActivityEntry $entry ) {
				return $entry->get_new_value();
			},
			$entries
		);
	}
}
