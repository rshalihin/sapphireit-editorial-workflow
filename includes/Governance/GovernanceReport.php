<?php
/**
 * Governance report metrics.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Governance;

use DateTimeImmutable;
use DateTimeZone;
use Sit_Cwm\Activity\ActivityEntry;
use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Publishing\GateDecision;
use Sit_Cwm\Publishing\PublishGate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes the governance figures for a period (F12): the share of
 * publications that passed approval, publications by gate mode, gate
 * refusals and bypasses, AI-use counts and overdue reviews.
 *
 * Read-only; site-wide. Callers must check `PermissionManager::can_audit()`.
 *
 * Activity rows are read in keyset batches of `BATCH` through
 * `ActivityLogger::get_batch()` and their context is decoded in PHP (no JSON
 * SQL: the MySQL floor is below 5.7). Nothing is cached.
 *
 * @since 1.2.0
 * @api
 */
final class GovernanceReport {

	/**
	 * Periods the report covers, in days.
	 *
	 * @since 1.2.0
	 * @api
	 * @var int[]
	 */
	const PERIODS = array( 30, 90, 365 );

	/**
	 * Period used when the requested one is not in `PERIODS`.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const DEFAULT_PERIOD = 90;

	/**
	 * Activity rows read per query.
	 *
	 * @since 1.2.0
	 * @api
	 * @var int
	 */
	const BATCH = 1000;

	/**
	 * Gate modes counted separately; anything else is `unknown`.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const GATE_MODES = array( PublishGate::MODE_OFF, PublishGate::MODE_LOG_ONLY, PublishGate::MODE_ENFORCE );

	/**
	 * Bypass reasons counted; others are ignored.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const BYPASS_REASONS = array( GateDecision::REASON_LOG_ONLY, GateDecision::REASON_CAPABILITY, GateDecision::REASON_ERROR );

	/**
	 * Activity history.
	 *
	 * @since 1.2.0
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Workflow meta persistence.
	 *
	 * @since 1.2.0
	 * @var PostRepository
	 */
	private $posts;

	/**
	 * Plugin settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.2.0
	 * @internal
	 *
	 * @param ActivityLogger $activity Activity history.
	 * @param PostRepository $posts    Workflow meta persistence.
	 * @param Settings       $settings Plugin settings.
	 */
	public function __construct( ActivityLogger $activity, PostRepository $posts, Settings $settings ) {
		$this->activity = $activity;
		$this->posts    = $posts;
		$this->settings = $settings;
	}

	/**
	 * Builds the report for a period ending now.
	 *
	 * Read-only; site-wide. Callers must check `PermissionManager::can_audit()`.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @param int                    $period_days One of `PERIODS`; anything else is 90.
	 * @param DateTimeImmutable|null $now         "Now", for tests; null for the current time.
	 * @return array The F12 shape: `period_days`, `since`, `generated_at`,
	 *               `publication`, `gate`, `ai_origin` and `overdue`.
	 */
	public function build( int $period_days, ?DateTimeImmutable $now = null ): array {
		if ( ! in_array( $period_days, self::PERIODS, true ) ) {
			$period_days = self::DEFAULT_PERIOD;
		}

		$utc   = new DateTimeZone( 'UTC' );
		$now   = ( null === $now ? current_datetime() : $now )->setTimezone( $utc );
		$since = $now->setTime( 0, 0 )->modify( "-{$period_days} days" );

		$activity = $this->activity_counts( $since->format( 'Y-m-d H:i:s' ) );

		return array(
			'period_days'  => $period_days,
			'since'        => $since->format( 'Y-m-d\TH:i:s\Z' ),
			'generated_at' => $now->format( 'Y-m-d\TH:i:s\Z' ),
			'publication'  => $activity['publication'],
			'gate'         => $activity['gate'],
			'ai_origin'    => array(
				'current'   => $this->current_ai_origin(),
				'published' => $activity['ai_origin'],
			),
			'overdue'      => array( 'count' => $this->overdue_count() ),
		);
	}

	/**
	 * Publication, gate and published-AI-origin counts from activity rows
	 * created at or after `$since`.
	 *
	 * @since 1.2.0
	 *
	 * @param string $since UTC `Y-m-d H:i:s`.
	 * @return array{publication: array, gate: array, ai_origin: array}
	 */
	private function activity_counts( string $since ): array {
		$total    = 0;
		$approved = 0;
		$modes    = array_fill_keys( array_merge( self::GATE_MODES, array( 'unknown' ) ), 0 );
		$refused  = 0;
		$bypassed = array_fill_keys( self::BYPASS_REASONS, 0 );
		$origins  = array_fill_keys( array_merge( AiOrigin::VALUES, array( 'not_declared', 'not_recorded' ) ), 0 );
		$after    = 0;

		do {
			$batch = $this->activity->get_batch(
				array(
					'after_id' => $after,
					'limit'    => self::BATCH,
					'actions'  => array( 'post_published', 'publish_gate_refused', 'publish_gate_bypassed' ),
					'since'    => $since,
				)
			);

			foreach ( $batch as $entry ) {
				/**
				 * Activity row.
				 *
				 * @var ActivityEntry $entry
				 */
				$after   = max( $after, $entry->get_id() );
				$context = $entry->get_context();

				switch ( $entry->get_action() ) {
					case 'post_published':
						++$total;

						if ( in_array( $context['workflow_status'] ?? null, PublishGate::PUBLISHABLE_STATUSES, true ) ) {
							++$approved;
						}

						$mode = $context['gate_mode'] ?? null;
						++$modes[ in_array( $mode, self::GATE_MODES, true ) ? $mode : 'unknown' ];
						++$origins[ $this->published_origin_key( $context ) ];
						break;

					case 'publish_gate_refused':
						++$refused;
						break;

					case 'publish_gate_bypassed':
						$reason = $context['reason'] ?? null;

						if ( in_array( $reason, self::BYPASS_REASONS, true ) ) {
							++$bypassed[ $reason ];
						}
						break;
				}
			}

			$fetched = count( $batch );
		} while ( $fetched >= self::BATCH );

		return array(
			'publication' => array(
				'total'          => $total,
				'approved'       => $approved,
				'approved_share' => $total > 0 ? round( $approved / $total, 4 ) : null,
				'by_gate_mode'   => $modes,
			),
			'gate'        => array(
				'refused'  => $refused,
				'bypassed' => $bypassed,
			),
			'ai_origin'   => $origins,
		);
	}

	/**
	 * Which `ai_origin.published` bucket a publication row counts in.
	 *
	 * `none` is "Not declared" at publication; a missing (pre-1.2) or
	 * unrecognised value is `not_recorded`, never counted as declared.
	 *
	 * @since 1.2.0
	 *
	 * @param array $context Row context.
	 * @return string
	 */
	private function published_origin_key( array $context ): string {
		$origin = $context['ai_origin'] ?? null;

		if ( 'none' === $origin ) {
			return 'not_declared';
		}

		return in_array( $origin, AiOrigin::VALUES, true ) ? $origin : 'not_recorded';
	}

	/**
	 * Declared AI origin of live managed content, in one grouped query.
	 *
	 * Counts posts of enabled types, excluding `trash`, `auto-draft` and
	 * `inherit`. Unset or unrecognised values count as `not_declared`.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, int> `human`, `ai_assisted`, `ai_generated`, `not_declared`.
	 */
	private function current_ai_origin(): array {
		global $wpdb;

		$counts = array_fill_keys( array_merge( AiOrigin::VALUES, array( 'not_declared' ) ), 0 );
		$types  = array_values( array_unique( $this->settings->enabled_post_types() ) );

		if ( array() === $types ) {
			return $counts;
		}

		$in = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One grouped count per report; the report is never cached (F12).
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Core table names; $in is a list of %s placeholders.
				"SELECT COALESCE( pm.meta_value, '' ) AS v, COUNT(*) AS n FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON ( pm.post_id = p.ID AND pm.meta_key = %s ) WHERE p.post_type IN ( {$in} ) AND p.post_status NOT IN ( 'trash', 'auto-draft', 'inherit' ) GROUP BY v",
				array_merge( array( PostRepository::META_AI_ORIGIN ), $types )
			)
		);

		foreach ( (array) $rows as $row ) {
			$value = AiOrigin::sanitize( $row->v );
			$key   = AiOrigin::NOT_DECLARED === $value ? 'not_declared' : $value;

			$counts[ $key ] += (int) $row->n;
		}

		return $counts;
	}

	/**
	 * Overdue managed posts, site-wide (not scoped to the viewer).
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	private function overdue_count(): int {
		$result = $this->posts->query_posts(
			array(
				'post_types' => $this->settings->enabled_post_types(),
				'overdue'    => true,
				'per_page'   => 1,
				'visible_to' => 0,
			)
		);

		return (int) $result['total'];
	}
}
