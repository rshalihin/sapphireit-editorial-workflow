<?php
/**
 * Publish gate Layer B: the save guard.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Publishing\Layers;

use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Publishing\GateDecision;
use Sit_Cwm\Publishing\GateLedger;
use Sit_Cwm\Publishing\GateRecorder;
use Sit_Cwm\Publishing\PublishGate;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layer B (E3): judges every `wp_insert_post()` / `wp_update_post()` that
 * would move managed content into `publish`/`future`, from anywhere — the
 * classic editor, Quick Edit, Bulk Edit, programmatic saves and WP-CLI
 * `wp post create|update`.
 *
 * A refused write is downgraded to its stored `draft`/`pending` (else
 * `draft`) instead of failing, so the rest of the save still lands (E5).
 *
 * A post being inserted has no id while `wp_insert_post_data` runs, so its
 * decision is held in the ledger and recorded by `GateRecorder::release_pending()`
 * on the post's `new → …` transition. Holds are a LIFO stack keyed by post
 * type only: two holds coexist only when one insert is nested inside another
 * insert's `wp_insert_post_data` filter, and then the inner insert's
 * transition fires first, which is exactly what popping the latest hold
 * matches.
 *
 * @since 1.1.0
 * @internal
 */
final class SaveLayer implements Bootable {

	/**
	 * Stored statuses a refused write keeps; anything else becomes `draft`.
	 *
	 * @since 1.1.0
	 * @var string[]
	 */
	const KEPT_STATUSES = array( 'draft', 'pending' );

	/**
	 * Publish gate.
	 *
	 * @since 1.1.0
	 * @var PublishGate
	 */
	private $gate;

	/**
	 * Per-request ledger.
	 *
	 * @since 1.1.0
	 * @var GateLedger
	 */
	private $ledger;

	/**
	 * Gate recorder.
	 *
	 * @since 1.1.0
	 * @var GateRecorder
	 */
	private $recorder;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.1.0
	 *
	 * @param PublishGate  $gate     Publish gate.
	 * @param GateLedger   $ledger   Per-request ledger.
	 * @param GateRecorder $recorder Gate recorder.
	 */
	public function __construct( PublishGate $gate, GateLedger $ledger, GateRecorder $recorder ) {
		$this->gate     = $gate;
		$this->ledger   = $ledger;
		$this->recorder = $recorder;
	}

	/**
	 * Hooks the save path (E3 table).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_insert_post_data', array( $this, 'filter_insert_post_data' ), 10, 2 );
		add_action( 'wp_insert_post', array( $this, 'end_insert' ), PHP_INT_MAX );
	}

	/**
	 * Unhooks; mirrors `register()`.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function unregister(): void {
		remove_filter( 'wp_insert_post_data', array( $this, 'filter_insert_post_data' ), 10 );
		remove_action( 'wp_insert_post', array( $this, 'end_insert' ), PHP_INT_MAX );
	}

	/**
	 * Judges a write into `publish`/`future`. Hooked on `wp_insert_post_data`.
	 *
	 * Fails open (E5): on any exception the data is returned unchanged and
	 * the failure is recorded.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $data    Slashed, sanitized post data about to be written.
	 * @param mixed $postarr Slashed, sanitized and processed post data as passed in.
	 * @return mixed The data, with `post_status` downgraded when refused.
	 */
	public function filter_insert_post_data( $data, $postarr ) {
		try {
			if ( ! is_array( $data ) || ! is_array( $postarr ) ) {
				return $data;
			}

			return $this->guard( $data, $postarr );
		} catch ( Throwable $e ) {
			$this->recorder->failed_open( 'save', is_array( $postarr ) ? absint( $postarr['ID'] ?? 0 ) : 0, $e );

			return $data;
		}
	}

	/**
	 * Forgets this post's decision once its write is finished, so a later
	 * write to it in the same request is judged afresh. Hooked on
	 * `wp_insert_post` at `PHP_INT_MAX`.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $post_id Post id.
	 * @return void
	 */
	public function end_insert( $post_id ): void {
		$this->ledger->forget( (int) $post_id );
	}

	/**
	 * Whether a write moves a post into a published status (E4 rule 2).
	 *
	 * - Into `publish` from anything but `publish`.
	 * - Into `future` from anything but `publish` or `future`.
	 *
	 * So a live post is never demoted and an already scheduled post is not
	 * re-judged on unrelated edits.
	 *
	 * @since 1.1.0
	 *
	 * @param string $target Status being written.
	 * @param string $stored Status stored before the write; `''` for a new post.
	 * @return bool
	 */
	public static function is_entering_published( string $target, string $stored ): bool {
		return ( 'publish' === $target && 'publish' !== $stored )
			|| ( 'future' === $target && ! in_array( $stored, array( 'publish', 'future' ), true ) );
	}

	/**
	 * The judgement itself; see `filter_insert_post_data()`.
	 *
	 * @since 1.1.0
	 *
	 * @param array $data    Post data about to be written.
	 * @param array $postarr Post data as passed in.
	 * @return array
	 */
	private function guard( array $data, array $postarr ): array {
		// Bulk Edit's "No change" leaves the status absent or unchanged.
		if ( ! isset( $data['post_status'] ) || ! is_string( $data['post_status'] ) || ! in_array( $data['post_status'], PublishGate::PUBLISH_STATUSES, true ) ) {
			return $data;
		}

		$attempted = $data['post_status'];
		$post_id   = absint( $postarr['ID'] ?? 0 );
		$stored    = $post_id > 0 ? (string) get_post_status( $post_id ) : '';

		if ( ! self::is_entering_published( $attempted, $stored ) ) {
			return $data;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ( $post_id > 0 && wp_is_post_revision( $post_id ) ) ) {
			return $data;
		}

		if ( $this->ledger->is_reverting() ) {
			return $data;
		}

		$post_type = isset( $data['post_type'] ) ? (string) $data['post_type'] : 'post';
		$user_id   = get_current_user_id();
		$decision  = $post_id > 0 ? $this->ledger->judged( $post_id ) : null;

		// Reusing a decision another layer took for this write: it recorded it.
		$record = null === $decision;

		if ( null === $decision ) {
			$decision = $this->gate->evaluate( $post_id, $post_type, $user_id );

			if ( ! $decision->applies() ) {
				return $data;
			}

			if ( $post_id > 0 ) {
				$this->ledger->judge( $post_id, $decision );
			}
		}

		if ( $decision->is_refused() ) {
			$data['post_status'] = in_array( $stored, self::KEPT_STATUSES, true ) ? $stored : 'draft';
		}

		if ( ! $record || ( ! $decision->is_refused() && ! $decision->is_bypassed() ) ) {
			return $data;
		}

		if ( 0 === $post_id ) {
			$this->ledger->hold( $post_type, $attempted, $decision );
		} elseif ( $decision->is_refused() ) {
			$this->recorder->refused( $post_id, $decision, $stored, $attempted, 'save', $user_id, $user_id );
		} elseif ( 'publish' === $attempted ) {
			$this->recorder->bypassed( $post_id, $decision, $stored, 'save', $user_id );
		} elseif ( GateDecision::REASON_CAPABILITY === $decision->reason() ) {
			$this->recorder->remember_schedule_bypass( $post_id, $user_id );
		}

		return $data;
	}
}
