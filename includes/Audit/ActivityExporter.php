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
 * @since 1.2.0
 * @internal
 */
final class ActivityExporter {

	/**
	 * CSV columns, in order (F8).
	 *
	 * @since 1.2.0
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
	);

	/**
	 * Context columns and the context key each one reads.
	 *
	 * @since 1.2.0
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
	);

	/**
	 * UTF-8 byte order mark, so spreadsheets read the file as UTF-8.
	 *
	 * @since 1.2.0
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
	 * Writes the BOM, the header and every activity row, oldest first.
	 *
	 * @since 1.2.0
	 *
	 * @param resource      $handle      Writable stream.
	 * @param callable|null $after_batch Called with no arguments after each
	 *                                   batch is written (the handler passes
	 *                                   `flush`).
	 * @return int Number of data rows written.
	 */
	public function export( $handle, ?callable $after_batch = null ): int {
		fwrite( $handle, self::BOM ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes to a caller-owned stream.
		CsvWriter::write_row( $handle, self::COLUMNS );

		$count    = 0;
		$after_id = 0;

		while ( true ) {
			$entries = $this->activity->get_batch(
				array(
					'after_id' => $after_id,
					'limit'    => $this->batch_size,
				)
			);

			if ( array() === $entries ) {
				break;
			}

			$names = $this->prime( $entries );

			foreach ( $entries as $entry ) {
				CsvWriter::write_row( $handle, $this->row( $entry, $names ) );
				++$count;
				$after_id = $entry->get_id();
			}

			unset( $entries, $names );

			if ( null !== $after_batch ) {
				call_user_func( $after_batch );
			}
		}

		return $count;
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
	 * One CSV row, in `COLUMNS` order.
	 *
	 * @since 1.2.0
	 *
	 * @param ActivityEntry $entry Entry.
	 * @param array         $names User id => summary.
	 * @return array
	 */
	private function row( ActivityEntry $entry, array $names ): array {
		$post    = get_post( $entry->get_post_id() );
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

		return array_values( $row );
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
