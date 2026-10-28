<?php
/**
 * Per-request publish gate ledger.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Publishing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remembers, for the current request only, which writes the gate layers have
 * already judged, so no write is judged or recorded twice (E3, E5).
 *
 * - Judged decisions are keyed by post id: Layer A judges a REST write before
 *   Layer B sees the same write inside `wp_insert_post()`.
 * - Holds carry a decision for a post that had no id yet when it was judged,
 *   until its `new → …` status transition names the id it ended up with.
 * - The reverting flag marks a layer's own revert write, which no layer may
 *   judge again.
 *
 * Plain arrays and a bool; no WordPress calls.
 *
 * @since 1.1.0
 * @internal
 */
final class GateLedger {

	/**
	 * Decisions already taken in this request, by post id.
	 *
	 * @since 1.1.0
	 * @var array<int, GateDecision>
	 */
	private $judged = array();

	/**
	 * Held decisions for posts not yet inserted, a LIFO stack per post type.
	 *
	 * @since 1.1.0
	 * @var array<string, array<int, array{attempted: string, decision: GateDecision}>>
	 */
	private $holds = array();

	/**
	 * Whether a layer is writing its own revert.
	 *
	 * @since 1.1.0
	 * @var bool
	 */
	private $reverting = false;

	/**
	 * Records the decision taken for a write to an existing post.
	 *
	 * @since 1.1.0
	 *
	 * @param int          $post_id  Post id.
	 * @param GateDecision $decision Decision.
	 * @return void
	 */
	public function judge( int $post_id, GateDecision $decision ): void {
		$this->judged[ $post_id ] = $decision;
	}

	/**
	 * The decision already taken for a post in this request, if any.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post id.
	 * @return GateDecision|null
	 */
	public function judged( int $post_id ): ?GateDecision {
		return $this->judged[ $post_id ] ?? null;
	}

	/**
	 * Forgets the decision for a post once its write is finished.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public function forget( int $post_id ): void {
		unset( $this->judged[ $post_id ] );
	}

	/**
	 * Holds a decision for a post being inserted, until its id is known.
	 *
	 * @since 1.1.0
	 *
	 * @param string       $post_type Post type of the post being inserted.
	 * @param string       $attempted Status attempted: `publish` or `future`.
	 * @param GateDecision $decision  Decision.
	 * @return void
	 */
	public function hold( string $post_type, string $attempted, GateDecision $decision ): void {
		$this->holds[ $post_type ][] = array(
			'attempted' => $attempted,
			'decision'  => $decision,
		);
	}

	/**
	 * Pops the most recent hold for a post type.
	 *
	 * @since 1.1.0
	 *
	 * @param string $post_type Post type.
	 * @return array{attempted: string, decision: GateDecision}|null Null when nothing is held.
	 */
	public function release( string $post_type ): ?array {
		if ( empty( $this->holds[ $post_type ] ) ) {
			return null;
		}

		return array_pop( $this->holds[ $post_type ] );
	}

	/**
	 * Whether a layer is writing its own revert.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	public function is_reverting(): bool {
		return $this->reverting;
	}

	/**
	 * Runs a revert write with the reverting flag set, and always restores it.
	 *
	 * The previous value is restored rather than cleared, so a revert nested
	 * inside another keeps the outer one flagged.
	 *
	 * @since 1.1.0
	 *
	 * @param callable $callback Revert write.
	 * @return void
	 */
	public function while_reverting( callable $callback ): void {
		$previous        = $this->reverting;
		$this->reverting = true;

		try {
			$callback();
		} finally {
			$this->reverting = $previous;
		}
	}
}
