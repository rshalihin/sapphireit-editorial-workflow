<?php
/**
 * Workflow post meta persistence.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Content;

use DateTimeImmutable;
use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\StatusManager;
use WP_Post;
use WP_Query;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only code that reads or writes workflow post meta (D6).
 *
 * This is the persistence layer: writers validate data shape but perform no
 * authorization. WorkflowManager and the REST layer authorize before calling
 * them.
 *
 * Reads go through `get_metadata_raw()` rather than `get_post_meta()`: the
 * raw read skips registered defaults, which would otherwise look up each
 * post's subtype and cost one query per uncached post inside the batch
 * methods.
 *
 * @since 1.0.0
 */
final class PostRepository implements Bootable {

	/**
	 * Workflow status meta key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_STATUS = '_sit_cwm_status';

	/**
	 * Reviewer user id meta key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_REVIEWER = '_sit_cwm_reviewer_id';

	/**
	 * Due date (`Y-m-d`) meta key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_DUE_DATE = '_sit_cwm_due_date';

	/**
	 * Posts cleared per query when a deleted user was a reviewer.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const CLEAR_BATCH_SIZE = 100;

	/**
	 * Largest page size of `query_posts()`.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const QUERY_MAX_PER_PAGE = 100;

	/**
	 * Status registry.
	 *
	 * @since 1.0.0
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Plugin settings.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Activity logger for system-initiated changes; optional.
	 *
	 * @since 1.0.0
	 * @var ActivityLogger|null
	 */
	private $activity;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param StatusManager       $statuses Status registry.
	 * @param Settings            $settings Plugin settings.
	 * @param ActivityLogger|null $activity Activity logger for system-initiated
	 *                                      changes (user deletion); none logs nothing.
	 */
	public function __construct( StatusManager $statuses, Settings $settings, ?ActivityLogger $activity = null ) {
		$this->statuses = $statuses;
		$this->settings = $settings;
		$this->activity = $activity;
	}

	/**
	 * Attaches the user-deletion cleanup.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'deleted_user', array( $this, 'handle_deleted_user' ) );
	}

	/**
	 * Workflow status of a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return string A registered status slug; the default when unset or no
	 *                longer registered.
	 */
	public function get_status( int $post_id ): string {
		return $this->status_from_raw( $this->read( $post_id, self::META_STATUS ) );
	}

	/**
	 * Whether a post stores a workflow status that is not registered (e.g. a
	 * Pro filter removed it). `get_status()` then reports the default status.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return bool False when the status is unset or registered.
	 */
	public function has_unknown_status( int $post_id ): bool {
		$raw = $this->read( $post_id, self::META_STATUS );

		if ( null === $raw || '' === $raw ) {
			return false;
		}

		return ! is_string( $raw ) || ! $this->statuses->exists( trim( $raw ) );
	}

	/**
	 * Stores a post's workflow status.
	 *
	 * Unregistered slugs are rejected rather than coerced to the default, so a
	 * typo can never silently reset a post to `draft`.
	 *
	 * @since 1.0.0
	 * @internal Callers must authorize first.
	 *
	 * @param int    $post_id Post id.
	 * @param string $status  Registered status slug.
	 * @return bool True when the stored status now equals `$status`.
	 */
	public function set_status( int $post_id, string $status ): bool {
		if ( $post_id <= 0 || ! $this->statuses->exists( $status ) ) {
			return false;
		}

		update_post_meta( $post_id, self::META_STATUS, $status );

		return $this->get_status( $post_id ) === $status;
	}

	/**
	 * Reviewer user id of a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return int User id, `0` when none.
	 */
	public function get_reviewer_id( int $post_id ): int {
		return absint( $this->read( $post_id, self::META_REVIEWER ) );
	}

	/**
	 * Stores a post's reviewer. `0`, or an id that is not an existing user,
	 * clears it.
	 *
	 * @since 1.0.0
	 * @internal Callers must authorize first.
	 *
	 * @param int $post_id Post id.
	 * @param int $user_id Reviewer user id; `0` clears.
	 * @return bool True when the stored reviewer now equals the sanitized id.
	 */
	public function set_reviewer_id( int $post_id, int $user_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$user_id = $this->sanitize_reviewer_id( $user_id );

		if ( 0 === $user_id ) {
			delete_post_meta( $post_id, self::META_REVIEWER );
		} else {
			update_post_meta( $post_id, self::META_REVIEWER, $user_id );
		}

		return $this->get_reviewer_id( $post_id ) === $user_id;
	}

	/**
	 * Due date of a post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return string `Y-m-d`, or `''` when none.
	 */
	public function get_due_date( int $post_id ): string {
		return $this->sanitize_due_date( $this->read( $post_id, self::META_DUE_DATE ) );
	}

	/**
	 * Stores a post's due date. `''`, or anything that is not a real `Y-m-d`
	 * calendar date, clears it.
	 *
	 * @since 1.0.0
	 * @internal Callers must authorize first.
	 *
	 * @param int    $post_id Post id.
	 * @param string $date    `Y-m-d`; `''` clears.
	 * @return bool True when the stored date now equals the sanitized date.
	 */
	public function set_due_date( int $post_id, string $date ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$date = $this->sanitize_due_date( $date );

		if ( '' === $date ) {
			delete_post_meta( $post_id, self::META_DUE_DATE );
		} else {
			update_post_meta( $post_id, self::META_DUE_DATE, $date );
		}

		return $this->get_due_date( $post_id ) === $date;
	}

	/**
	 * Raw workflow state of a managed post plus its basic post fields.
	 *
	 * Presentation (labels, reviewer name/avatar, edit link, capabilities) is
	 * added by WorkflowManager.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return array{post_id: int, post_title: string, post_type: string, post_status: string, post_author: int, status: string, reviewer_id: int, due_date: string}|array{}
	 *         Empty when the post is not managed.
	 */
	public function get_workflow( int $post_id ): array {
		$post = $this->managed_post( $post_id );

		if ( null === $post ) {
			return array();
		}

		return array(
			'post_id'     => $post->ID,
			'post_title'  => $post->post_title,
			'post_type'   => $post->post_type,
			'post_status' => $post->post_status,
			'post_author' => (int) $post->post_author,
			'status'      => $this->get_status( $post->ID ),
			'reviewer_id' => $this->get_reviewer_id( $post->ID ),
			'due_date'    => $this->get_due_date( $post->ID ),
		);
	}

	/**
	 * Whether a post exists and its type is workflow-enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function is_managed( int $post_id ): bool {
		return null !== $this->managed_post( $post_id );
	}

	/**
	 * Workflow statuses of many posts, loading their meta in one query.
	 *
	 * Does not check whether each post is managed (that would load every post).
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $post_ids Post ids.
	 * @return array<int, string> Post id => status slug, in input order, unique
	 *                            positive ids only.
	 */
	public function get_statuses_for_posts( array $post_ids ): array {
		$result = array();

		foreach ( $this->prime( $post_ids ) as $post_id ) {
			$result[ $post_id ] = $this->get_status( $post_id );
		}

		return $result;
	}

	/**
	 * Reviewer ids of many posts, loading their meta in one query.
	 *
	 * Returns ids only. Callers that render names should prime users with
	 * `cache_users( array_filter( $result ) )`.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $post_ids Post ids.
	 * @return array<int, int> Post id => reviewer id (`0` when none), in input
	 *                         order, unique positive ids only.
	 */
	public function get_reviewers_for_posts( array $post_ids ): array {
		$result = array();

		foreach ( $this->prime( $post_ids ) as $post_id ) {
			$result[ $post_id ] = $this->get_reviewer_id( $post_id );
		}

		return $result;
	}

	/**
	 * One page of workflow-managed post ids for the dashboard, with the total.
	 *
	 * Runs a single ids-only `WP_Query`. Workflow meta filters become
	 * `meta_query` clauses; a post with no stored status counts as the default
	 * status. Visibility scoping and the due date / status sort are SQL clauses
	 * bound to this one query, so they are enforced by the query itself, never
	 * by filtering its result.
	 *
	 * Performs no authorization: the caller decides `post_types` and
	 * `visible_to` through PermissionManager.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type string[] $post_types  Post types; types that are not workflow-enabled are dropped.
	 *     @type string   $search      Search terms.
	 *     @type string[] $statuses    Workflow statuses; empty for any.
	 *     @type int|null $reviewer_id Reviewer id; `0` for posts without a reviewer, null for any.
	 *     @type int      $author      Author id; `0` for any.
	 *     @type string   $due_after   Earliest due date, `Y-m-d`, inclusive.
	 *     @type string   $due_before  Latest due date, `Y-m-d`, inclusive.
	 *     @type bool     $overdue     Only posts due before today (site timezone) and not in a final status.
	 *     @type string   $orderby     `title`, `date` (default), `due_date` or `status`.
	 *     @type string   $order       `ASC` or `DESC` (default).
	 *     @type int      $page        1-based page; default 1.
	 *     @type int      $per_page    Posts per page; default 20, max 100.
	 *     @type int      $visible_to  When positive, only posts this user authors or reviews.
	 * }
	 * @return array{ids: int[], total: int} Empty when no post type remains or
	 *                                       a filter value is invalid.
	 */
	public function query_posts( array $args ): array {
		$empty = array(
			'ids'   => array(),
			'total' => 0,
		);

		$post_types = array();

		foreach ( (array) ( $args['post_types'] ?? array() ) as $post_type ) {
			if ( is_string( $post_type ) && $this->settings->is_post_type_enabled( $post_type ) ) {
				$post_types[ $post_type ] = $post_type;
			}
		}

		$meta_query = $this->dashboard_meta_query( $args );

		if ( array() === $post_types || null === $meta_query ) {
			return $empty;
		}

		$orderby    = isset( $args['orderby'] ) && in_array( $args['orderby'], array( 'title', 'date', 'due_date', 'status' ), true ) ? $args['orderby'] : 'date';
		$order      = isset( $args['order'] ) && is_string( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$visible_to = isset( $args['visible_to'] ) ? max( 0, (int) $args['visible_to'] ) : 0;
		$search     = isset( $args['search'] ) && is_string( $args['search'] ) ? trim( $args['search'] ) : '';
		$author     = isset( $args['author'] ) ? max( 0, (int) $args['author'] ) : 0;
		$sort       = 'none';

		if ( 'title' === $orderby || 'date' === $orderby ) {
			$sort = array(
				$orderby => $order,
				'ID'     => $order,
			);
		}

		$query_args = array(
			'post_type'              => array_values( $post_types ),
			'post_status'            => 'any',
			'perm'                   => 'readable',
			'posts_per_page'         => isset( $args['per_page'] ) ? max( 1, min( self::QUERY_MAX_PER_PAGE, (int) $args['per_page'] ) ) : 20,
			'paged'                  => isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1,
			'orderby'                => $sort,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'suppress_filters'       => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		if ( $author > 0 ) {
			$query_args['author'] = $author;
		}

		if ( array() !== $meta_query ) {
			$query_args['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Workflow filters live in post meta; an index table is a documented Pro-scale follow-up.
		}

		$query   = new WP_Query();
		$applied = false;
		$filter  = function ( $clauses, $current ) use ( $query, &$applied, $orderby, $order, $visible_to ) {
			global $wpdb;

			if ( $current !== $query ) {
				return $clauses;
			}

			$applied = true;

			if ( $visible_to > 0 ) {
				$clauses['where'] .= $wpdb->prepare(
					" AND ( {$wpdb->posts}.post_author = %d OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS sit_cwm_visible WHERE sit_cwm_visible.post_id = {$wpdb->posts}.ID AND sit_cwm_visible.meta_key = %s AND sit_cwm_visible.meta_value = %s ) )",
					$visible_to,
					self::META_REVIEWER,
					(string) $visible_to
				);
			}

			if ( 'due_date' === $orderby || 'status' === $orderby ) {
				$clauses['join'] .= $wpdb->prepare(
					" LEFT JOIN {$wpdb->postmeta} AS sit_cwm_order ON ( sit_cwm_order.post_id = {$wpdb->posts}.ID AND sit_cwm_order.meta_key = %s )",
					'due_date' === $orderby ? self::META_DUE_DATE : self::META_STATUS
				);

				// $order is whitelisted above; posts without a due date always sort last.
				$clauses['orderby'] = 'due_date' === $orderby
					? "sit_cwm_order.meta_value IS NULL ASC, sit_cwm_order.meta_value {$order}, {$wpdb->posts}.ID {$order}"
					: $this->status_order_sql( $order );
			}

			return $clauses;
		};

		add_filter( 'posts_clauses', $filter, PHP_INT_MAX, 2 );

		try {
			$query->query( $query_args );
		} finally {
			remove_filter( 'posts_clauses', $filter, PHP_INT_MAX );
		}

		// The clauses carry visibility scoping: never return a result built without them.
		if ( ! $applied ) {
			return $empty;
		}

		return array(
			'ids'   => array_map( 'intval', $query->posts ),
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Shared reviewer sanitizer for post meta and REST arguments.
	 *
	 * Accepts only a positive integer (or integer string) naming an existing
	 * user. Negative numbers yield `0` rather than being flipped positive by
	 * `absint()`, which could otherwise point at a real user.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return int Existing user id, or `0`.
	 */
	public function sanitize_reviewer_id( $value ): int {
		if ( is_bool( $value ) || ! is_scalar( $value ) ) {
			return 0;
		}

		$user_id = filter_var( $value, FILTER_VALIDATE_INT );

		if ( false === $user_id || $user_id <= 0 ) {
			return 0;
		}

		return get_userdata( $user_id ) instanceof WP_User ? $user_id : 0;
	}

	/**
	 * Shared due date sanitizer for post meta and REST arguments.
	 *
	 * Accepts only a real calendar date in `Y-m-d` form: the parsed date must
	 * format back to the identical string, which rejects overflow such as
	 * `2026-02-31` (PHP would roll it to March 3rd).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string `Y-m-d`, or `''` when empty or invalid.
	 */
	public function sanitize_due_date( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return '';
		}

		return $value;
	}

	/**
	 * Clears the reviewer on every post that referenced a deleted user.
	 *
	 * Hooked on `deleted_user`. Runs as the system (acting user `0`): logs a
	 * `reviewer_cleared` activity entry and fires `sit_cwm_reviewer_assigned`
	 * with reviewer `0` for each cleared post.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id Deleted user id.
	 * @return int[] Ids of the posts whose reviewer was cleared.
	 */
	public function handle_deleted_user( $user_id ): array {
		$user_id = absint( $user_id );
		$cleared = array();

		if ( 0 === $user_id ) {
			return $cleared;
		}

		// Each batch removes its matches, so page 1 is re-queried until empty.
		// Stop if a batch yields nothing new, so a failed delete cannot loop.
		do {
			$post_ids   = $this->posts_with_reviewer( $user_id );
			$batch_full = self::CLEAR_BATCH_SIZE === count( $post_ids );
			$new_ids    = array_diff( $post_ids, $cleared );

			foreach ( $new_ids as $post_id ) {
				delete_post_meta( $post_id, self::META_REVIEWER, (string) $user_id );
				$cleared[] = $post_id;

				if ( null !== $this->activity ) {
					$this->activity->log_reviewer_assigned( $post_id, 0, $user_id, 0 );
				}

				/**
				 * Fires after a post's reviewer changed.
				 *
				 * @since 1.0.0
				 *
				 * @param int $post_id     Post id.
				 * @param int $reviewer_id New reviewer id; `0` when cleared.
				 * @param int $previous_id Previous reviewer id.
				 * @param int $user_id     Acting user id; `0` for the system.
				 */
				do_action( 'sit_cwm_reviewer_assigned', $post_id, 0, $user_id, 0 );
			}
		} while ( $batch_full && array() !== $new_ids );

		return $cleared;
	}

	/**
	 * One page of post ids whose reviewer is a given user, across every post
	 * type and status (including trash and unregistered types), so no stale
	 * reference survives.
	 *
	 * Reads postmeta directly rather than through `WP_Query`, so query
	 * filters from other plugins cannot hide a post from the cleanup.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id Reviewer user id.
	 * @return int[]
	 */
	private function posts_with_reviewer( int $user_id ): array {
		global $wpdb;

		// Not cached: each cleanup batch must see the previous batch's deletes.
		$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Rare, bounded cleanup on user deletion; must bypass query filters.
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC LIMIT %d",
				self::META_REVIEWER,
				(string) $user_id,
				self::CLEAR_BATCH_SIZE
			)
		);

		return array_map( 'intval', $post_ids );
	}

	/**
	 * `meta_query` clauses for the dashboard filters, most selective first.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Arguments of `query_posts()`.
	 * @return array|null Clauses (possibly empty), or null when a filter value
	 *                    is invalid so that nothing may match.
	 */
	private function dashboard_meta_query( array $args ): ?array {
		$clauses = array();

		if ( isset( $args['reviewer_id'] ) ) {
			$reviewer_id = is_numeric( $args['reviewer_id'] ) ? (int) $args['reviewer_id'] : -1;

			if ( $reviewer_id < 0 ) {
				return null;
			}

			$clauses[] = 0 === $reviewer_id
				? array(
					'key'     => self::META_REVIEWER,
					'compare' => 'NOT EXISTS',
				)
				: array(
					'key'     => self::META_REVIEWER,
					'value'   => (string) $reviewer_id,
					'compare' => '=',
				);
		}

		$requested = isset( $args['statuses'] ) ? array_filter( (array) $args['statuses'], 'is_string' ) : array();

		if ( array() !== $requested ) {
			$statuses = array_values( array_intersect( $this->statuses->slugs(), $requested ) );

			if ( array() === $statuses ) {
				return null;
			}

			$clause = array(
				'key'     => self::META_STATUS,
				'value'   => $statuses,
				'compare' => 'IN',
			);

			// Posts that never entered the workflow have no status meta and count as the default.
			if ( in_array( $this->statuses->default_status(), $statuses, true ) ) {
				$clause = array(
					'relation' => 'OR',
					$clause,
					array(
						'key'     => self::META_STATUS,
						'compare' => 'NOT EXISTS',
					),
				);
			}

			$clauses[] = $clause;
		}

		$ranges = array(
			'due_after'  => '>=',
			'due_before' => '<=',
		);

		foreach ( $ranges as $arg => $compare ) {
			if ( ! isset( $args[ $arg ] ) || '' === $args[ $arg ] ) {
				continue;
			}

			$date = $this->sanitize_due_date( $args[ $arg ] );

			if ( '' === $date ) {
				return null;
			}

			$clauses[] = array(
				'key'     => self::META_DUE_DATE,
				'value'   => $date,
				'compare' => $compare,
				'type'    => 'DATE',
			);
		}

		// Same rule as the dashboard's `is_overdue` flag: due before today in the
		// site timezone, and the workflow not yet in a final status.
		if ( ! empty( $args['overdue'] ) ) {
			$clauses[] = array(
				'key'     => self::META_DUE_DATE,
				'value'   => current_datetime()->format( 'Y-m-d' ),
				'compare' => '<',
				'type'    => 'DATE',
			);

			$unfinished = $this->unfinished_status_clause();

			if ( null !== $unfinished ) {
				$clauses[] = $unfinished;
			}
		}

		return $clauses;
	}

	/**
	 * `meta_query` clause matching posts whose workflow status is not final.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Null when no registered status is final.
	 */
	private function unfinished_status_clause(): ?array {
		$final = array();

		foreach ( $this->statuses->slugs() as $slug ) {
			if ( $this->statuses->is_final( $slug ) ) {
				$final[] = $slug;
			}
		}

		if ( array() === $final ) {
			return null;
		}

		$clause = array(
			'key'     => self::META_STATUS,
			'value'   => $final,
			'compare' => 'NOT IN',
		);

		// Posts without status meta have the default status.
		if ( in_array( $this->statuses->default_status(), $final, true ) ) {
			return $clause;
		}

		return array(
			'relation' => 'OR',
			$clause,
			array(
				'key'     => self::META_STATUS,
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * ORDER BY fragment sorting by workflow status registry order.
	 *
	 * Expects the `sit_cwm_order` join on the status meta key.
	 *
	 * @since 1.0.0
	 *
	 * @param string $order `ASC` or `DESC`.
	 * @return string
	 */
	private function status_order_sql( string $order ): string {
		global $wpdb;

		$order        = 'ASC' === $order ? 'ASC' : 'DESC';
		$slugs        = $this->statuses->slugs();
		$placeholders = implode( ', ', array_fill( 0, count( $slugs ), '%s' ) );

		return $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Values are passed as one array.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %s placeholders, $order is whitelisted.
			"FIELD( COALESCE( NULLIF( sit_cwm_order.meta_value, '' ), %s ), {$placeholders} ) {$order}, {$wpdb->posts}.ID {$order}",
			array_merge( array( $this->statuses->default_status() ), $slugs )
		);
	}

	/**
	 * Normalizes ids and loads their meta into the object cache in one query.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post_ids Raw post ids.
	 * @return int[] Unique positive ids, in input order.
	 */
	private function prime( array $post_ids ): array {
		$ids = array();

		/*
		 * Invalid ids are dropped, never coerced: `absint( -3 )` is `3`, a real
		 * post the caller never asked about, which would leak its workflow
		 * state into the response. Same reasoning as `sanitize_reviewer_id()`.
		 */
		foreach ( $post_ids as $post_id ) {
			$id = ( is_bool( $post_id ) || ! is_scalar( $post_id ) )
				? false
				: filter_var( $post_id, FILTER_VALIDATE_INT );

			if ( false !== $id && $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$ids = array_values( $ids );

		if ( array() !== $ids ) {
			update_meta_cache( 'post', $ids );
		}

		return $ids;
	}

	/**
	 * Single raw meta value, without registered defaults.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 * @return mixed Stored value, or null when absent.
	 */
	private function read( int $post_id, string $meta_key ) {
		if ( $post_id <= 0 ) {
			return null;
		}

		return get_metadata_raw( 'post', $post_id, $meta_key, true );
	}

	/**
	 * Resolves a raw stored status to a registered slug.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Stored value.
	 * @return string
	 */
	private function status_from_raw( $raw ): string {
		if ( null === $raw || '' === $raw ) {
			return $this->statuses->default_status();
		}

		return $this->statuses->sanitize( $raw );
	}

	/**
	 * Resolves a post id to a post whose type is workflow-enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post id.
	 * @return WP_Post|null
	 */
	private function managed_post( int $post_id ): ?WP_Post {
		if ( $post_id <= 0 ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! $this->settings->is_post_type_enabled( $post->post_type ) ) {
			return null;
		}

		return $post;
	}
}
