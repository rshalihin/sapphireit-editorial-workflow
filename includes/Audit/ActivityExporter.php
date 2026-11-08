<?php
/**
 * Activity log CSV export.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Sit_Cwm\Activity\ActivityEntry;
use Sit_Cwm\Activity\ActivityLogger;
use Sit_Cwm\REST\ActivityFormatter;
use Sit_Cwm\REST\UserSummaries;
use WP_Post;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams the whole activity log as CSV (F8).
 *
 * Reads keyset batches through `ActivityLogger::get_batch()` and writes each
 * one before reading the next, so memory follows the batch size, never the
 * log's length. Posts and users are loaded once per batch. Performs no
 * authorization: the caller checks `PermissionManager::can_audit()` first.
 *
 * Add-ons reach it through `export_with()`, for filtered output with extra
 * columns and the same formula neutralising.
 *
 * @since 1.2.0
 * @api
 */
final class ActivityExporter {

	/**
	 * CSV columns, in order (F8).
	 *
	 * @since 1.2.0
	 * @internal Use `columns()`.
	 * @var string[]
	 */
	const COLUMNS = array(
		'id',
		'created_at_utc',
		'post_id',
		'post_title',
		'post_type',
		'user_id',
		'user_login',
		'user_name',
		'action',
		'action_label',
		'old_value',
		'new_value',
		'message',
		'workflow_status',
		'gate_mode',
		'gate_layer',
		'gate_reason',
		'source',
		'ai_origin',
		'via',
		'attested',
		'attestation',
		'content_hash',
		'revision_id',
	);

	/**
	 * Context columns and the context key each one reads.
	 *
	 * @since 1.2.0
	 * @internal
	 * @var array<string, string>
	 */
	const CONTEXT_COLUMNS = array(
		'workflow_status' => 'workflow_status',
		'gate_mode'       => 'gate_mode',
		'gate_layer'      => 'layer',
		'gate_reason'     => 'reason',
		'source'          => 'source',
		'ai_origin'       => 'ai_origin',
		'via'             => 'via',
		'attested'        => 'attested',
		'attestation'     => 'attestation',
		'content_hash'    => 'content_hash',
		'revision_id'     => 'revision_id',
	);

	/**
	 * UTF-8 byte order mark, so spreadsheets read the file as UTF-8.
	 *
	 * @since 1.2.0
	 * @internal
	 * @var string
	 */
	const BOM = "\xEF\xBB\xBF";

	/**
	 * Activity reads.
	 *
	 * @since 1.2.0
	 * @var ActivityLogger
	 */
	private $activity;

	/**
	 * Action labels.
	 *
	 * @since 1.2.0
	 * @var ActivityFormatter
	 */
	private $formatter;

	/**
	 * Batched user names.
	 *
	 * @since 1.2.0
	 * @var UserSummaries
	 */
	private $users;

	/**
	 * Rows read per batch.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	private $batch_size;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.2.0
	 * @internal
	 *
	 * @param ActivityLogger    $activity   Activity reads.
	 * @param ActivityFormatter $formatter  Action labels.
	 * @param UserSummaries     $users      Batched user names.
	 * @param int               $batch_size Rows read per batch; clamped to `get_batch()`'s range.
	 */
	public function __construct( ActivityLogger $activity, ActivityFormatter $formatter, UserSummaries $users, int $batch_size = 500 ) {
		$this->activity   = $activity;
		$this->formatter  = $formatter;
		$this->users      = $users;
		$this->batch_size = max( 1, min( ActivityLogger::MAX_BATCH_SIZE, $batch_size ) );
	}

	/**
	 * Free's CSV columns, in order.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @return string[]
	 */
	public function columns(): array {
		return self::COLUMNS;
	}

	/**
	 * Writes the BOM, the header and every activity row, oldest first.
	 *
	 * @since 1.2.0
	 * @internal
	 *
	 * @param resource      $handle      Writable stream.
	 * @param callable|null $after_batch Called with no arguments after each
	 *                                   batch is written (the handler passes
	 *                                   `flush`).
	 * @return int Number of data rows written.
	 */
	public function export( $handle, ?callable $after_batch = null ): int {
		return $this->export_with( $handle, array( 'after_batch' => $after_batch ) )['written'];
	}

	/**
	 * Writes the BOM, the header and the matching rows, oldest first.
	 *
	 * @since 1.2.0
	 * @api Callers must authorize first.
	 *
	 * @param resource $handle  Writable stream.
	 * @param array    $options {
	 *     Optional.
	 *
	 *     @type array         $filters       `since`, `until`, `actions`, `user_id`
	 *                                        and `post_id`, as for
	 *                                        `ActivityLogger::get_batch()`.
	 *     @type string[]      $extra_columns Columns added after Free's.
	 *     @type callable|null $row_filter    Receives the row keyed by column,
	 *                                        and the `ActivityEntry`. Returns
	 *                                        the row, or null to skip it. A
	 *                                        skipped row counts as read.
	 *     @type int           $max_rows      Most rows to read; `0` for no limit.
	 *     @type callable|null $after_batch   Called with no arguments after each
	 *                                        batch.
	 * }
	 * @return array{read: int, written: int, last_id: int} `last_id` is the last
	 *                                                      row read, `0` for none.
	 */
	public function export_with( $handle, array $options = array() ): array {
		$filters     = array_intersect_key( (array) ( $options['filters'] ?? array() ), array_flip( array( 'since', 'until', 'actions', 'user_id', 'post_id' ) ) );
		$header      = array_merge( self::COLUMNS, array_values( array_filter( (array) ( $options['extra_columns'] ?? array() ), 'is_string' ) ) );
		$row_filter  = $options['row_filter'] ?? null;
		$after_batch = $options['after_batch'] ?? null;
		$max_rows    = max( 0, (int) ( $options['max_rows'] ?? 0 ) );
		$result      = array(
			'read'    => 0,
			'written' => 0,
			'last_id' => 0,
		);

		fwrite( $handle, self::BOM ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes to a caller-owned stream.
		CsvWriter::write_row( $handle, $header );

		while ( 0 === $max_rows || $result['read'] < $max_rows ) {
			$entries = $this->activity->get_batch(
				array_merge(
					$filters,
					array(
						'after_id' => $result['last_id'],
						'limit'    => 0 === $max_rows ? $this->batch_size : min( $this->batch_size, $max_rows - $result['read'] ),
					)
				)
			);

			if ( array() === $entries ) {
				break;
			}

			$names = $this->prime( $entries );

			foreach ( $entries as $entry ) {
				++$result['read'];
				$result['last_id'] = $entry->get_id();

				$row = $this->row( $entry, $names );

				if ( is_callable( $row_filter ) ) {
					$row = call_user_func( $row_filter, $row, $entry );
				}

				if ( is_array( $row ) ) {
					CsvWriter::write_row( $handle, $this->cells( $row, $header ) );
					++$result['written'];
				}
			}

			unset( $entries, $names );

			if ( is_callable( $after_batch ) ) {
				call_user_func( $after_batch );
			}
		}

		return $result;
	}

	/**
	 * A row's cells in header order. A missing key is an empty cell, so the
	 * columns stay aligned.
	 *
	 * @since 1.2.0
	 *
	 * @param array    $row    Row keyed by column.
	 * @param string[] $header Columns.
	 * @return array
	 */
	private function cells( array $row, array $header ): array {
		$cells = array();

		foreach ( $header as $column ) {
			$value   = $row[ $column ] ?? '';
			$cells[] = is_scalar( $value ) ? $value : $this->context_cell( $value );
		}

		return $cells;
	}

	/**
	 * Loads a batch's posts and users in bulk.
	 *
	 * `UserSummaries::load()` reads names with a field-list query, which
	 * does not fill the user cache, so `cache_users()` primes it for the
	 * logins read through `get_userdata()`.
	 *
	 * @since 1.2.0
	 *
	 * @param ActivityEntry[] $entries Batch.
	 * @return array<int, array{id: int, name: string, avatar: string}> User id => summary.
	 */
	private function prime( array $entries ): array {
		$post_ids = array();
		$user_ids = array();

		foreach ( $entries as $entry ) {
			$post_ids[ $entry->get_post_id() ] = $entry->get_post_id();

			if ( $entry->get_user_id() > 0 ) {
				$user_ids[ $entry->get_user_id() ] = $entry->get_user_id();
			}
		}

		_prime_post_caches( array_values( $post_ids ), false, false );

		if ( array() === $user_ids ) {
			return array();
		}

		cache_users( array_values( $user_ids ) );

		return $this->users->load( array_values( $user_ids ) );
	}

	/**
	 * One CSV row keyed by column.
	 *
	 * @since 1.2.0
	 *
	 * @param ActivityEntry $entry Entry.
	 * @param array         $names User id => summary.
	 * @return array
	 */
	private function row( ActivityEntry $entry, array $names ): array {
		// `get_post( 0 )` would return the global post; a prune row has none.
		$post    = $entry->get_post_id() > 0 ? get_post( $entry->get_post_id() ) : null;
		$user_id = $entry->get_user_id();
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		$context = $entry->get_context();
		$action  = $entry->get_action();

		$row = array(
			'id'             => $entry->get_id(),
			'created_at_utc' => $this->iso_utc( $entry->get_created_at() ),
			'post_id'        => $entry->get_post_id(),
			'post_title'     => $post instanceof WP_Post ? (string) $post->post_title : '',
			'post_type'      => $post instanceof WP_Post ? (string) $post->post_type : '',
			'user_id'        => $user_id,
			'user_login'     => $user instanceof WP_User ? (string) $user->user_login : '',
			'user_name'      => isset( $names[ $user_id ] ) ? $names[ $user_id ]['name'] : '',
			'action'         => $action,
			'action_label'   => $this->formatter->action_label( $action ),
			'old_value'      => (string) $entry->get_old_value(),
			'new_value'      => (string) $entry->get_new_value(),
			'message'        => (string) $entry->get_message(),
		);

		foreach ( self::CONTEXT_COLUMNS as $column => $key ) {
			$row[ $column ] = $this->context_cell( $context[ $key ] ?? null );
		}

		return $row;
	}

	/**
	 * A context value as a cell: scalars as text (booleans as `1`/`0`),
	 * anything else as JSON, absent as `''`.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Context value.
	 * @return string
	 */
	private function context_cell( $value ): string {
		if ( null === $value ) {
			return '';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		$json = wp_json_encode( $value );

		return false === $json ? '' : $json;
	}

	/**
	 * Stored UTC `Y-m-d H:i:s` as `Y-m-d\TH:i:s\Z`.
	 *
	 * @since 1.2.0
	 *
	 * @param string $created_at Stored timestamp.
	 * @return string `''` when the stored value does not parse.
	 */
	private function iso_utc( string $created_at ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $created_at, new DateTimeZone( 'UTC' ) );

		return false === $date ? '' : $date->format( 'Y-m-d\TH:i:s\Z' );
	}
}
