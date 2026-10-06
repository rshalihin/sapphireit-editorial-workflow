<?php
/**
 * Activity history persistence.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Activity;

use DateTimeImmutable;
use Sit_Cwm\Core\Database;
use Sit_Cwm\Core\Interfaces\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append-only audit trail in the activity table (D8).
 *
 * The only code that reads or writes activity rows. Like PostRepository, it
 * performs no authorization: WorkflowManager and the REST layer authorize
 * before logging or exposing entries.
 *
 * Every timestamp is stored in UTC; localize only at render time.
 *
 * No caching in v1.0. An object-cache layer is a Pro/performance follow-up and
 * must sit behind this read API so its signatures stay stable.
 *
 * @since 1.0.0
 */
final class ActivityLogger implements Bootable {

	/**
	 * Action slugs accepted in v1.0 (D8), before `sit_cwm_activity_actions`.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const ACTIONS = array(
		'status_changed',
		'reviewer_assigned',
		'reviewer_cleared',
		'due_date_set',
		'due_date_cleared',
		'comment_added',
	);

	/**
	 * Default page size for reads.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const DEFAULT_PER_PAGE = 20;

	/**
	 * Largest page size for reads.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_PER_PAGE = 100;

	/**
	 * Width of the `action` column.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const ACTION_MAX_LENGTH = 50;

	/**
	 * Width of the `old_value` / `new_value` columns.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const VALUE_MAX_LENGTH = 191;

	/**
	 * Schema owner, source of the table name.
	 *
	 * @since 1.0.0
	 * @var Database
	 */
	private $database;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param Database $database Schema owner.
	 */
	public function __construct( Database $database ) {
		$this->database = $database;
	}

	/**
	 * Attaches the post-deletion cleanup.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'before_delete_post', array( $this, 'handle_before_delete_post' ) );
	}

	/**
	 * Action slugs `log()` accepts.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_actions(): array {
		/**
		 * Filters the activity action slugs that may be logged.
		 *
		 * Slugs must be lowercase keys (`sanitize_key()`-clean) of at most 50
		 * characters; anything else is dropped.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $actions Action slugs.
		 */
		$actions = apply_filters( 'sit_cwm_activity_actions', self::ACTIONS );

		if ( ! is_array( $actions ) ) {
			return self::ACTIONS;
		}

		$valid = array();

		foreach ( $actions as $action ) {
			if ( is_string( $action ) && '' !== $action && sanitize_key( $action ) === $action && strlen( $action ) <= self::ACTION_MAX_LENGTH ) {
				$valid[ $action ] = $action;
			}
		}

		return array_values( $valid );
	}

	/**
	 * Whether an action slug may be logged.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action Action slug.
	 * @return bool
	 */
	public function is_valid_action( string $action ): bool {
		return in_array( $action, $this->get_actions(), true );
	}

	/**
	 * Appends one activity entry.
	 *
	 * @since 1.0.0
	 * @internal Callers must authorize first.
	 *
	 * @param int    $post_id Post id.
	 * @param string $action  Registered action slug.
	 * @param array  $args    {
	 *     Optional. Entry fields.
	 *
	 *     @type string|null $old_value  Previous value; plain text, max 191 chars.
	 *     @type string|null $new_value  New value; plain text, max 191 chars.
	 *     @type string|null $message    Message; passed through `wp_kses_post()`.
	 *     @type array       $context    Extra data, stored as JSON.
	 *     @type int         $user_id    Acting user; defaults to the current
	 *                                   user. `0` means the system.
	 *     @type string      $created_at UTC `Y-m-d H:i:s`; defaults to now.
	 * }
	 * @return int Inserted id, `0` on failure or an unknown action.
	 */
	public function log( int $post_id, string $action, array $args = array() ): int {
		global $wpdb;

		if ( $post_id <= 0 || ! $this->is_valid_action( $action ) ) {
			return 0;
		}

		$row = array(
			'post_id'    => $post_id,
			'user_id'    => array_key_exists( 'user_id', $args ) ? $this->sanitize_user_id( $args['user_id'] ) : get_current_user_id(),
			'action'     => $action,
			'old_value'  => $this->sanitize_value( $args['old_value'] ?? null ),
			'new_value'  => $this->sanitize_value( $args['new_value'] ?? null ),
			'message'    => $this->sanitize_message( $args['message'] ?? null ),
			'context'    => $this->encode_context( $args['context'] ?? null ),
			'created_at' => $this->sanitize_created_at( $args['created_at'] ?? null ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin's own table; no core API exists.
		$inserted = $wpdb->insert(
			$this->database->table_name(),
			$row,
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$id = (int) $wpdb->insert_id;

		if ( false === $inserted || $id <= 0 ) {
			return 0;
		}

		$entry = ActivityEntry::from_row( (object) array_merge( array( 'id' => $id ), $row ) );

		/**
		 * Fires after an activity entry was stored.
		 *
		 * @since 1.0.0
		 *
		 * @param int           $id    Activity id.
		 * @param ActivityEntry $entry The stored entry.
		 */
		do_action( 'sit_cwm_activity_logged', $id, $entry );

		return $id;
	}

	/**
	 * Logs a workflow status change.
	 *
	 * @since 1.0.0
	 * @internal Callers must authorize first.
	 *
	 * @param int      $post_id Post id.
	 * @param string   $from    Previous status slug.
	 * @param string   $to      New status slug.
	 * @param int|null $user_id Acting user; `null` for the current user.
	 * @return int Inserted id, `0` on failure.
	 */
	public function log_status_change( int $post_id, string $from, string $to, ?int $user_id = null ): int {
		return $this->log(
			$post_id,
			'status_changed',
			$this->with_user(
				array(
					'old_value' => $from,
					'new_value' => $to,
				),
				$user_id
			)
		);
	}

	/**
	 * Logs a reviewer change: `reviewer_assigned`, or `reviewer_cleared` when
	 * the new reviewer is `0`.
	 *
	 * @since 1.0.0
	 * @internal Callers must authorize first.
	 *
	 * @param int      $post_id Post id.
	 * @param int      $new_id  New reviewer id; `0` when cleared.
	 * @param int      $old_id  Previous reviewer id; `0` when none.
	 * @param int|null $user_id Acting user; `null` for the current user.
	 * @return int Inserted id, `0` on failure.
	 */
	public function log_reviewer_assigned( int $post_id, int $new_id, int $old_id, ?int $user_id = null ): int {
		return $this->log(
			$post_id,
			$new_id > 0 ? 'reviewer_assigned' : 'reviewer_cleared',
			$this->with_user(
				array(
					'old_value' => $old_id > 0 ? (string) $old_id : null,
					'new_value' => $new_id > 0 ? (string) $new_id : null,
				),
				$user_id
			)
		);
	}

	/**
	 * Logs a due date change: `due_date_set`, or `due_date_cleared` when the
	 * new date is empty.
	 *
	 * @since 1.0.0
	 * @internal Callers must authorize first.
	 *
	 * @param int      $post_id  Post id.
	 * @param string   $new_date New `Y-m-d` date; `''` when cleared.
	 * @param string   $old_date Previous `Y-m-d` date; `''` when none.
	 * @param int|null $user_id  Acting user; `null` for the current user.
	 * @return int Inserted id, `0` on failure.
	 */
	public function log_due_date_changed( int $post_id, string $new_date, string $old_date, ?int $user_id = null ): int {
		return $this->log(
			$post_id,
			'' !== $new_date ? 'due_date_set' : 'due_date_cleared',
			$this->with_user(
				array(
					'old_value' => '' !== $old_date ? $old_date : null,
					'new_value' => '' !== $new_date ? $new_date : null,
				),
				$user_id
			)
		);
	}

	/**
	 * Logs a workflow comment. A message that is empty after sanitizing is not
	 * logged.
	 *
	 * @since 1.0.0
	 * @internal Callers must authorize first.
	 *
	 * @param int      $post_id Post id.
	 * @param string   $message Comment body; limited HTML allowed.
	 * @param int|null $user_id Acting user; `null` for the current user.
	 * @return int Inserted id, `0` on failure or an empty message.
	 */
	public function log_comment( int $post_id, string $message, ?int $user_id = null ): int {
		if ( '' === trim( wp_strip_all_tags( wp_kses_post( $message ) ) ) ) {
			return 0;
		}

		return $this->log( $post_id, 'comment_added', $this->with_user( array( 'message' => $message ), $user_id ) );
	}

	/**
	 * A single activity entry.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Activity id.
	 * @return ActivityEntry|null Null when no such entry exists.
	 */
	public function get( int $id ): ?ActivityEntry {
		global $wpdb;

		if ( $id <= 0 ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; caching is a documented follow-up.
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $this->database->table_name(), $id )
		);

		return is_object( $row ) ? ActivityEntry::from_row( $row ) : null;
	}

	/**
	 * One page of a post's activity.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id Post id.
	 * @param array $args    {
	 *     Optional. Query arguments.
	 *
	 *     @type int    $per_page Entries per page; default 20, max 100.
	 *     @type int    $page     1-based page; default 1.
	 *     @type string $action   Only this action. An unknown action matches nothing.
	 *     @type string $order    `ASC` or `DESC` (default); anything else is `DESC`.
	 * }
	 * @return ActivityEntry[]
	 */
	public function get_for_post( int $post_id, array $args = array() ): array {
		global $wpdb;

		$query = $this->parse_query_args( $args );

		if ( $post_id <= 0 || null === $query ) {
			return array();
		}

		$table  = $this->database->table_name();
		$action = $query['action'];
		$limit  = $query['per_page'];
		$offset = ( $query['page'] - 1 ) * $query['per_page'];

		// Literal SQL per sort direction: nothing is interpolated. An empty
		// action matches all; MySQL folds `'' = ''` so `post_created` is used.
		if ( 'ASC' === $query['order'] ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; caching is a documented follow-up.
				$wpdb->prepare(
					"SELECT * FROM %i WHERE post_id = %d AND ( %s = '' OR action = %s ) ORDER BY created_at ASC, id ASC LIMIT %d OFFSET %d",
					$table,
					$post_id,
					$action,
					$action,
					$limit,
					$offset
				)
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; caching is a documented follow-up.
				$wpdb->prepare(
					"SELECT * FROM %i WHERE post_id = %d AND ( %s = '' OR action = %s ) ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
					$table,
					$post_id,
					$action,
					$action,
					$limit,
					$offset
				)
			);
		}

		return $this->to_entries( $rows );
	}

	/**
	 * Number of activity entries for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $post_id Post id.
	 * @param array $args    Optional. Honours `action`, as in `get_for_post()`.
	 * @return int
	 */
	public function count_for_post( int $post_id, array $args = array() ): int {
		global $wpdb;

		$query = $this->parse_query_args( $args );

		if ( $post_id <= 0 || null === $query ) {
			return 0;
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; caching is a documented follow-up.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE post_id = %d AND ( %s = '' OR action = %s )",
				$this->database->table_name(),
				$post_id,
				$query['action'],
				$query['action']
			)
		);
	}

	/**
	 * Latest entries for many posts in a single query, for the dashboard's
	 * "last activity" column.
	 *
	 * One `UNION ALL` branch per post, each an index-ordered read of
	 * `post_created` limited to `$per_post` rows, so the cost follows the page
	 * size and never a post's history length. (A correlated "count newer
	 * rows" subquery is quadratic in history: one post with 3 000 entries
	 * made a dashboard page take 8 s.) Needs no window functions, so it runs
	 * on every MySQL/MariaDB version WordPress supports.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $post_ids Post ids.
	 * @param int   $per_post Entries per post, newest first; 1 to 100.
	 * @return array<int, ActivityEntry[]> Post id => entries, in input order;
	 *                                     unique positive ids only, each with
	 *                                     an array (empty when no activity).
	 */
	public function get_for_posts( array $post_ids, int $per_post = 1 ): array {
		global $wpdb;

		$ids      = $this->normalize_post_ids( $post_ids );
		$per_post = max( 1, min( self::MAX_PER_PAGE, $per_post ) );
		$result   = array_fill_keys( $ids, array() );

		if ( array() === $ids ) {
			return $result;
		}

		$table    = $this->database->table_name();
		$branches = implode( ' UNION ALL ', array_fill( 0, count( $ids ), '( SELECT * FROM %i WHERE post_id = %d ORDER BY created_at DESC, id DESC LIMIT %d )' ) );
		$values   = array();

		foreach ( $ids as $post_id ) {
			array_push( $values, $table, $post_id, $per_post );
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table; caching is a documented follow-up.
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Values are passed as one array.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $branches is a list of fixed branches with %i/%d placeholders.
				"{$branches} ORDER BY post_id ASC, created_at DESC, id DESC",
				$values
			)
		);

		foreach ( $this->to_entries( $rows ) as $entry ) {
			$result[ $entry->get_post_id() ][] = $entry;
		}

		return $result;
	}

	/**
	 * Deletes every activity entry of a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return int Rows deleted.
	 */
	public function delete_for_post( int $post_id ): int {
		global $wpdb;

		if ( $post_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin's own table.
		$deleted = $wpdb->delete( $this->database->table_name(), array( 'post_id' => $post_id ), array( '%d' ) );

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Removes a post's activity when the post is permanently deleted.
	 *
	 * Hooked on `before_delete_post`, which does not fire when a post is only
	 * trashed, so trashed posts keep their history.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function handle_before_delete_post( $post_id ): void {
		$this->delete_for_post( absint( $post_id ) );
	}

	/**
	 * Normalizes read arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Raw arguments.
	 * @return array{per_page: int, page: int, action: string, order: string}|null
	 *         Null when an `action` filter names an unknown action.
	 */
	private function parse_query_args( array $args ): ?array {
		$per_page = isset( $args['per_page'] ) && is_numeric( $args['per_page'] ) ? (int) $args['per_page'] : 0;
		$page     = isset( $args['page'] ) && is_numeric( $args['page'] ) ? (int) $args['page'] : 1;
		$action   = isset( $args['action'] ) ? $args['action'] : '';
		$order    = isset( $args['order'] ) && is_string( $args['order'] ) ? strtoupper( trim( $args['order'] ) ) : '';

		if ( ! is_string( $action ) || ( '' !== $action && ! $this->is_valid_action( $action ) ) ) {
			return null;
		}

		return array(
			'per_page' => $per_page > 0 ? min( self::MAX_PER_PAGE, $per_page ) : self::DEFAULT_PER_PAGE,
			'page'     => max( 1, $page ),
			'action'   => $action,
			'order'    => 'ASC' === $order ? 'ASC' : 'DESC',
		);
	}

	/**
	 * Unique positive integer post ids, in input order.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_ids Raw ids.
	 * @return int[]
	 */
	private function normalize_post_ids( array $post_ids ): array {
		$ids = array();

		foreach ( $post_ids as $post_id ) {
			$id = filter_var( $post_id, FILTER_VALIDATE_INT );

			if ( ! is_bool( $post_id ) && false !== $id && $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
	}

	/**
	 * Builds entries from database rows.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $rows Result of `$wpdb->get_results()`.
	 * @return ActivityEntry[]
	 */
	private function to_entries( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( ActivityEntry::class, 'from_row' ), $rows );
	}

	/**
	 * Adds an explicit acting user to log arguments.
	 *
	 * @since 1.0.0
	 *
	 * @param array    $args    Log arguments.
	 * @param int|null $user_id Acting user; `null` leaves the current-user default.
	 * @return array
	 */
	private function with_user( array $args, ?int $user_id ): array {
		if ( null !== $user_id ) {
			$args['user_id'] = $user_id;
		}

		return $args;
	}

	/**
	 * Acting user id; anything but a positive integer is the system (`0`).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private function sanitize_user_id( $value ): int {
		$id = is_bool( $value ) ? false : filter_var( $value, FILTER_VALIDATE_INT );

		return false !== $id && $id > 0 ? $id : 0;
	}

	/**
	 * Plain-text old/new value, truncated to the column width.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string|null Null when absent or not scalar.
	 */
	private function sanitize_value( $value ): ?string {
		if ( null === $value || is_bool( $value ) || ! is_scalar( $value ) ) {
			return null;
		}

		return mb_substr( sanitize_text_field( (string) $value ), 0, self::VALUE_MAX_LENGTH );
	}

	/**
	 * Message with post-safe HTML only.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string|null Null when absent or not scalar.
	 */
	private function sanitize_message( $value ): ?string {
		if ( null === $value || is_bool( $value ) || ! is_scalar( $value ) ) {
			return null;
		}

		return wp_kses_post( (string) $value );
	}

	/**
	 * JSON-encodes context.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string|null Null when absent, empty, not an array or not encodable.
	 */
	private function encode_context( $value ): ?string {
		if ( ! is_array( $value ) || array() === $value ) {
			return null;
		}

		$json = wp_json_encode( $value );

		return false === $json ? null : $json;
	}

	/**
	 * UTC creation time; anything but a real `Y-m-d H:i:s` datetime is now.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function sanitize_created_at( $value ): string {
		if ( is_string( $value ) ) {
			$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value );

			if ( false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value ) {
				return $value;
			}
		}

		return current_time( 'mysql', true );
	}
}
