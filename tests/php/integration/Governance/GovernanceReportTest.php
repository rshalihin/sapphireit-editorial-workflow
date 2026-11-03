<?php
/**
 * Integration tests for the governance report service.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Governance;

use DateTimeImmutable;
use DateTimeZone;
use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Database;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Governance\GovernanceReport;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every F12 definition, computed from arranged activity rows and posts.
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Governance\GovernanceReport
 */
final class GovernanceReportTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Service under test.
	 *
	 * @var GovernanceReport
	 */
	private $report;

	/**
	 * Fixed "now" (UTC).
	 *
	 * @var DateTimeImmutable
	 */
	private $now;

	/**
	 * Starts from an empty site: no posts, no activity rows.
	 *
	 * Installer content and rows logged by other fixtures would otherwise
	 * leak into the site-wide counts. Both deletes run inside the test's
	 * transaction, so they are rolled back.
	 *
	 * @return void
	 */
	public function set_up() {
		global $wpdb;

		parent::set_up();

		_delete_all_posts();

		$table = ( new Database() )->table_name();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture; fixed table name.

		$this->report = new GovernanceReport( $this->activity, $this->posts, new Settings() );
		$this->now    = new DateTimeImmutable( '2026-10-29 10:00:00', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * An empty site reports zeroes, a null share and the period bounds.
	 *
	 * @return void
	 */
	public function test_empty_site_shape() {
		$r = $this->report->build( 90, $this->now );

		$this->assertSame( 90, $r['period_days'] );
		$this->assertSame( '2026-07-31T00:00:00Z', $r['since'] );
		$this->assertSame( '2026-10-29T10:00:00Z', $r['generated_at'] );
		$this->assertSame(
			array(
				'total'          => 0,
				'approved'       => 0,
				'approved_share' => null,
				'by_gate_mode'   => array(
					'off'      => 0,
					'log_only' => 0,
					'enforce'  => 0,
					'unknown'  => 0,
				),
			),
			$r['publication']
		);
		$this->assertSame(
			array(
				'refused'  => 0,
				'bypassed' => array(
					'log_only'   => 0,
					'capability' => 0,
					'error'      => 0,
				),
			),
			$r['gate']
		);
		$this->assertSame(
			array(
				'current'   => array(
					'human'        => 0,
					'ai_assisted'  => 0,
					'ai_generated' => 0,
					'not_declared' => 0,
				),
				'published' => array(
					'human'        => 0,
					'ai_assisted'  => 0,
					'ai_generated' => 0,
					'not_declared' => 0,
					'not_recorded' => 0,
				),
			),
			$r['ai_origin']
		);
		$this->assertSame( array( 'count' => 0 ), $r['overdue'] );
		$this->assertSame( array( 'period_days', 'since', 'generated_at', 'publication', 'gate', 'ai_origin', 'overdue' ), array_keys( $r ) );
	}

	/**
	 * The approved share, gate modes and published AI origin; pre-1.1 and
	 * pre-1.2 rows count as `unknown` / `not_recorded`, never as approved or
	 * declared; rows before `since` are left out.
	 *
	 * @return void
	 */
	public function test_publication_share_and_gate_modes() {
		$p = $this->create_managed_post( array( 'post_status' => 'draft' ) );

		$this->pub(
			$p,
			array(
				'workflow_status' => 'approved',
				'gate_mode'       => 'enforce',
				'ai_origin'       => 'human',
			),
			'2026-10-01 00:00:00'
		);
		$this->pub(
			$p,
			array(
				'workflow_status' => 'published',
				'gate_mode'       => 'off',
				'ai_origin'       => 'ai_generated',
			),
			'2026-10-02 00:00:00'
		);
		$this->pub(
			$p,
			array(
				'workflow_status' => 'review',
				'gate_mode'       => 'log_only',
				'ai_origin'       => 'none',
			),
			'2026-10-03 00:00:00'
		);
		$this->pub( $p, array( 'workflow_status' => 'review' ), '2026-10-04 00:00:00' ); // Pre-1.1 shape: no gate_mode, no ai_origin.
		$this->pub(
			$p,
			array(
				'workflow_status' => 'approved',
				'gate_mode'       => 'off',
			),
			'2026-01-01 00:00:00'
		); // Outside the 90-day period.

		$r = $this->report->build( 90, $this->now );

		$this->assertSame( 4, $r['publication']['total'] );
		$this->assertSame( 2, $r['publication']['approved'] );
		$this->assertSame( 0.5, $r['publication']['approved_share'] );
		$this->assertSame(
			array(
				'off'      => 1,
				'log_only' => 1,
				'enforce'  => 1,
				'unknown'  => 1,
			),
			$r['publication']['by_gate_mode']
		);
		$this->assertSame(
			array(
				'human'        => 1,
				'ai_assisted'  => 0,
				'ai_generated' => 1,
				'not_declared' => 1,
				'not_recorded' => 1,
			),
			$r['ai_origin']['published']
		);
		$this->assertSame( 5, $this->report->build( 365, $this->now )['publication']['total'] );
	}

	/**
	 * The share is rounded to four decimals.
	 *
	 * @return void
	 */
	public function test_approved_share_is_rounded() {
		$p = $this->create_managed_post( array( 'post_status' => 'draft' ) );

		$this->pub( $p, array( 'workflow_status' => 'approved' ), '2026-10-01 00:00:00' );
		$this->pub( $p, array( 'workflow_status' => 'review' ), '2026-10-01 00:00:00' );
		$this->pub( $p, array( 'workflow_status' => 'review' ), '2026-10-01 00:00:00' );

		$this->assertSame( 0.3333, $this->report->build( 30, $this->now )['publication']['approved_share'] );
	}

	/**
	 * Refusals are counted; bypasses are counted by reason, unknown reasons
	 * ignored.
	 *
	 * @return void
	 */
	public function test_gate_refusals_and_bypasses_by_reason() {
		$p = $this->create_managed_post( array( 'post_status' => 'draft' ) );

		$this->row( $p, 'publish_gate_refused', array(), '2026-10-10 00:00:00' );
		$this->row( $p, 'publish_gate_refused', array(), '2026-01-10 00:00:00' ); // Outside the period.

		foreach ( array( 'log_only', 'log_only', 'capability', 'error', 'weird' ) as $reason ) {
			$this->row( $p, 'publish_gate_bypassed', array( 'reason' => $reason ), '2026-10-10 00:00:00' );
		}

		$this->assertSame(
			array(
				'refused'  => 1,
				'bypassed' => array(
					'log_only'   => 2,
					'capability' => 1,
					'error'      => 1,
				),
			),
			$this->report->build( 30, $this->now )['gate']
		);
	}

	/**
	 * The current AI origin counts live managed content only: no trash, no
	 * attachments.
	 *
	 * @return void
	 */
	public function test_current_ai_origin_counts_managed_content_only() {
		$a = $this->create_managed_post();
		$b = $this->create_managed_post();
		$c = $this->create_managed_post();
		$d = $this->create_managed_post( array( 'post_status' => 'draft' ) );

		$this->posts->set_ai_origin( $a, 'ai_generated' );
		$this->posts->set_ai_origin( $b, 'ai_generated' );
		$this->posts->set_ai_origin( $c, 'human' );
		wp_trash_post( $c );
		self::factory()->post->create( array( 'post_type' => 'attachment' ) );

		$this->assertSame(
			array(
				'human'        => 0,
				'ai_assisted'  => 0,
				'ai_generated' => 2,
				'not_declared' => 1,
			),
			$this->report->build( 30, $this->now )['ai_origin']['current'],
			"Draft {$d} counts as not declared."
		);
	}

	/**
	 * With no enabled post type, nothing counts and no AI query runs.
	 *
	 * @return void
	 */
	public function test_current_ai_origin_without_enabled_types() {
		$this->posts->set_ai_origin( $this->create_managed_post(), 'human' );

		add_filter( 'sit_cwm_enabled_post_types', '__return_empty_array' );

		$this->assertSame(
			array(
				'human'        => 0,
				'ai_assisted'  => 0,
				'ai_generated' => 0,
				'not_declared' => 0,
			),
			$this->report->build( 30, $this->now )['ai_origin']['current']
		);
	}

	/**
	 * Overdue counts every overdue post, not only the viewer's own.
	 *
	 * @return void
	 */
	public function test_overdue_counts_all_posts_regardless_of_viewer() {
		$p = $this->create_managed_post( array( 'post_author' => self::fixture_user( 'editor' ) ) );

		update_post_meta( $p, PostRepository::META_DUE_DATE, '2020-01-01' );
		update_post_meta( $p, PostRepository::META_STATUS, 'review' );
		wp_set_current_user( self::fixture_user( 'admin' ) );

		$this->assertSame( array( 'count' => 1 ), $this->report->build( 30, $this->now )['overdue'] );
	}

	/**
	 * A period outside `PERIODS` falls back to 90 days.
	 *
	 * @return void
	 */
	public function test_invalid_period_falls_back_to_90() {
		$this->assertSame( 90, $this->report->build( 7, $this->now )['period_days'] );
	}

	/**
	 * `$now` in another timezone is converted to UTC first.
	 *
	 * @return void
	 */
	public function test_now_is_converted_to_utc() {
		$r = $this->report->build( 30, new DateTimeImmutable( '2026-10-29 01:00:00', new DateTimeZone( 'Asia/Dhaka' ) ) );

		$this->assertSame( '2026-10-28T19:00:00Z', $r['generated_at'] );
		$this->assertSame( '2026-09-28T00:00:00Z', $r['since'] );
	}

	/**
	 * Rows are read in batches until none are left.
	 *
	 * @return void
	 */
	public function test_batches_cover_more_than_one_page() {
		global $wpdb;

		$p      = $this->create_managed_post( array( 'post_status' => 'draft' ) );
		$values = array();

		for ( $i = 0; $i < GovernanceReport::BATCH + 5; $i++ ) {
			$values[] = $wpdb->prepare( '(%d, %d, %s, %s, %s)', $p, 0, 'post_published', '{"workflow_status":"approved","gate_mode":"enforce"}', '2026-10-15 00:00:00' );
		}

		$table = ( new Database() )->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture; every value tuple is prepared above.
		$wpdb->query( "INSERT INTO {$table} (post_id, user_id, action, context, created_at) VALUES " . implode( ', ', $values ) );

		$r = $this->report->build( 30, $this->now );

		$this->assertSame( GovernanceReport::BATCH + 5, $r['publication']['total'] );
		$this->assertSame( GovernanceReport::BATCH + 5, $r['publication']['approved'] );
		$this->assertSame( 1.0, $r['publication']['approved_share'] );
	}

	/**
	 * Logs a `post_published` row at a given time.
	 *
	 * `log_publication_event()` cannot take `created_at`, so this writes the
	 * same context shape through `log()`.
	 *
	 * @param int    $post_id    Post id.
	 * @param array  $context    Row context.
	 * @param string $created_at UTC `Y-m-d H:i:s`.
	 * @return void
	 */
	private function pub( int $post_id, array $context, string $created_at ): void {
		$this->row( $post_id, 'post_published', $context, $created_at );
	}

	/**
	 * Logs an activity row at a given time.
	 *
	 * @param int    $post_id    Post id.
	 * @param string $action     Action.
	 * @param array  $context    Row context.
	 * @param string $created_at UTC `Y-m-d H:i:s`.
	 * @return void
	 */
	private function row( int $post_id, string $action, array $context, string $created_at ): void {
		$id = $this->activity->log(
			$post_id,
			$action,
			array(
				'old_value'  => 'draft',
				'new_value'  => 'publish',
				'context'    => $context,
				'created_at' => $created_at,
			)
		);

		$this->assertGreaterThan( 0, $id );
	}
}
