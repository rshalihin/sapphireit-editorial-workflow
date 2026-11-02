<?php
/**
 * Integration tests for the activity CSV exporter.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration\Audit;

use Sit_Cwm\Audit\ActivityExporter;
use Sit_Cwm\REST\ActivityFormatter;
use Sit_Cwm\REST\UserSummaries;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\CreatesWorkflowPosts;
use Sit_Cwm\Workflow\StatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * BOM, header, rows across batches, context columns and neutralised cells (F8).
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Audit\ActivityExporter
 * @covers \Sit_Cwm\Audit\CsvWriter
 */
final class ActivityExporterTest extends TestCase {

	use CreatesWorkflowPosts;

	/**
	 * Every row is written once, across batch boundaries, behind a BOM and
	 * the header, with user-typed cells neutralised.
	 *
	 * @return void
	 */
	public function test_export_writes_header_and_all_rows_across_batches() {
		$p = $this->create_managed_post(
			array(
				'post_title'  => '=cmd|calc',
				'post_status' => 'draft',
			)
		);

		for ( $i = 0; $i < 7; $i++ ) {
			$this->activity->log_comment( $p, "-note {$i}", self::fixture_user( 'editor' ) );
		}

		$h = $this->stream();

		$this->assertSame( 7, $this->exporter( 3 )->export( $h ) );

		rewind( $h );
		$this->assertSame( "\xEF\xBB\xBF", fread( $h, 3 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- In-memory stream.

		$rows = $this->read_rows( $h );

		$this->assertSame( ActivityExporter::COLUMNS, $rows[0] );
		$this->assertCount( 8, $rows );

		$first = array_combine( ActivityExporter::COLUMNS, $rows[1] );

		$this->assertSame( "'=cmd|calc", $first['post_title'] );
		$this->assertSame( "'-note 0", $first['message'] );
		$this->assertSame( 'comment_added', $first['action'] );
		$this->assertSame( 'Comment added', $first['action_label'] );
		$this->assertSame( (string) $p, $first['post_id'] );
		$this->assertSame( 'post', $first['post_type'] );
		$this->assertSame( (string) self::fixture_user( 'editor' ), $first['user_id'] );
		$this->assertSame( get_userdata( self::fixture_user( 'editor' ) )->user_login, $first['user_login'] );
		$this->assertSame( 'Editor Fixture', $first['user_name'] );
		$this->assertSame( '', $first['gate_mode'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $first['created_at_utc'] );

		$messages = array_map(
			static function ( $row ) {
				return array_combine( ActivityExporter::COLUMNS, $row )['message'];
			},
			array_slice( $rows, 1 )
		);

		$this->assertSame( array( "'-note 0", "'-note 1", "'-note 2", "'-note 3", "'-note 4", "'-note 5", "'-note 6" ), $messages );
	}

	/**
	 * The batch callback runs once per non-empty batch.
	 *
	 * @return void
	 */
	public function test_after_batch_runs_per_batch() {
		$p = $this->create_managed_post( array( 'post_status' => 'draft' ) );

		for ( $i = 0; $i < 7; $i++ ) {
			$this->activity->log_comment( $p, "note {$i}", self::fixture_user( 'editor' ) );
		}

		$calls = 0;

		$this->exporter( 3 )->export(
			$this->stream(),
			static function () use ( &$calls ) {
				++$calls;
			}
		);

		$this->assertSame( 3, $calls );
	}

	/**
	 * An empty log exports the header only.
	 *
	 * @return void
	 */
	public function test_empty_log_exports_header_only() {
		$h = $this->stream();

		$this->assertSame( 0, $this->exporter()->export( $h ) );

		rewind( $h );
		fread( $h, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- In-memory stream.

		$this->assertSame( array( ActivityExporter::COLUMNS ), $this->read_rows( $h ) );
	}

	/**
	 * Gate context lands in its own columns.
	 *
	 * @return void
	 */
	public function test_context_columns_come_from_publication_rows() {
		$p = $this->create_managed_post( array( 'post_status' => 'draft' ) );

		$this->activity->log_publication_event(
			$p,
			'publish_gate_bypassed',
			'draft',
			'publish',
			array(
				'workflow_status' => 'review',
				'gate_mode'       => 'log_only',
				'layer'           => 'save',
				'source'          => 'admin',
				'reason'          => 'log_only',
			),
			self::fixture_user( 'admin' )
		);

		$row = $this->export_rows()[0];

		$this->assertSame(
			array( 'review', 'log_only', 'save', 'log_only', 'admin' ),
			array( $row['workflow_status'], $row['gate_mode'], $row['gate_layer'], $row['gate_reason'], $row['source'] )
		);
		$this->assertSame( 'draft', $row['old_value'] );
		$this->assertSame( 'publish', $row['new_value'] );
	}

	/**
	 * A deleted user keeps their id but exports no login or name.
	 *
	 * @return void
	 */
	public function test_deleted_user_exports_empty_login_and_name() {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$p    = $this->create_managed_post( array( 'post_status' => 'draft' ) );
		$user = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->activity->log_comment( $p, 'Gone soon.', $user );
		wp_delete_user( $user );

		$row = $this->export_rows()[0];

		$this->assertSame( (string) $user, $row['user_id'] );
		$this->assertSame( '', $row['user_login'] );
		$this->assertSame( '', $row['user_name'] );
	}

	/**
	 * System entries (user 0) and deleted posts export empty cells.
	 *
	 * @return void
	 */
	public function test_system_user_and_missing_post_export_empty_cells() {
		$this->activity->log(
			999999,
			'comment_added',
			array(
				'message' => 'Orphan.',
				'user_id' => 0,
			)
		);

		$row = $this->export_rows()[0];

		$this->assertSame( '999999', $row['post_id'] );
		$this->assertSame( '', $row['post_title'] );
		$this->assertSame( '', $row['post_type'] );
		$this->assertSame( '0', $row['user_id'] );
		$this->assertSame( '', $row['user_login'] );
	}

	/**
	 * Exporter with real dependencies.
	 *
	 * @param int $batch_size Rows per batch.
	 * @return ActivityExporter
	 */
	private function exporter( int $batch_size = 500 ): ActivityExporter {
		return new ActivityExporter( $this->activity, new ActivityFormatter( new StatusManager() ), new UserSummaries(), $batch_size );
	}

	/**
	 * A fresh in-memory stream.
	 *
	 * @return resource
	 */
	private function stream() {
		return fopen( 'php://memory', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream.
	}

	/**
	 * Every remaining CSV row of a stream.
	 *
	 * @param resource $h Stream positioned after the BOM.
	 * @return array[]
	 */
	private function read_rows( $h ): array {
		$rows = array();

		while ( false !== ( $row = fgetcsv( $h ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- Idiomatic read loop.
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Data rows of a full export, keyed by column.
	 *
	 * @return array[]
	 */
	private function export_rows(): array {
		$h = $this->stream();

		$this->exporter()->export( $h );

		rewind( $h );
		fread( $h, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- In-memory stream.

		return array_map(
			static function ( $row ) {
				return array_combine( ActivityExporter::COLUMNS, $row );
			},
			array_slice( $this->read_rows( $h ), 1 )
		);
	}
}
