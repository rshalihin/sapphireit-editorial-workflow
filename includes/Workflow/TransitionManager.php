<?php
/**
 * Workflow transition map (state machine).
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Workflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "is `$from → $to` a structurally legal move?" (D4).
 *
 * Pure structure: no permissions, no database, no post state. Authorization
 * lives in PermissionManager; WorkflowManager is the only place the two meet.
 * The map is filterable through `sit_cwm_transition_map`, per post, see F2,
 * and the filter result is validated against that post's StatusManager
 * registry so an extension can never introduce an unknown status or a
 * self-transition.
 *
 * @since 1.0.0
 * @api
 */
final class TransitionManager {

	/**
	 * Core transition map (D4): source slug => ordered list of target slugs.
	 *
	 * @since 1.0.0
	 * @internal
	 * @var array<string, string[]>
	 */
	const MAP = array(
		'draft'         => array( 'writing' ),
		'writing'       => array( 'review' ),
		'review'        => array( 'approved', 'needs_changes' ),
		'needs_changes' => array( 'writing' ),
		'approved'      => array( 'published', 'needs_changes' ),
		'published'     => array( 'writing' ),
	);

	/**
	 * Core transitions that send content backwards in the workflow.
	 *
	 * @since 1.0.0
	 * @internal
	 * @var array<string, string[]>
	 */
	const ROLLBACKS = array(
		'review'    => array( 'needs_changes' ),
		'approved'  => array( 'needs_changes' ),
		'published' => array( 'writing' ),
	);

	/**
	 * Most post maps kept in each memo before it is emptied (F2).
	 *
	 * Bounds memory in long loops (export, batch) without an LRU.
	 *
	 * @since 1.2.0
	 * @internal
	 * @var int
	 */
	const MEMO_LIMIT = 256;

	/**
	 * Status registry.
	 *
	 * @since 1.0.0
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Resolved transition maps keyed by post id. Keyed, never shared: see F2.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Keyed by post id.
	 * @var array<int, array<string, string[]>>
	 */
	private $map = array();

	/**
	 * Resolved rollback maps keyed by post id. Keyed, never shared: see F2.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Keyed by post id.
	 * @var array<int, array<string, string[]>>
	 */
	private $rollbacks = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @internal
	 *
	 * @param StatusManager $statuses Status registry.
	 */
	public function __construct( StatusManager $statuses ) {
		$this->statuses = $statuses;
	}

	/**
	 * The validated transition map.
	 *
	 * Only registered statuses appear, as keys or targets; self-transitions
	 * and duplicates are removed; sources without targets are omitted.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param int $post_id Post id; 0 when the question is not about one post.
	 * @return array<string, string[]> Source slug => ordered target slugs.
	 */
	public function map( int $post_id = 0 ): array {
		$post_id = max( 0, $post_id );

		if ( isset( $this->map[ $post_id ] ) ) {
			return $this->map[ $post_id ];
		}

		$core = $this->normalize( self::MAP, $post_id );

		/**
		 * Filters the workflow transition map.
		 *
		 * Return an array of source status slug => list of target status slugs.
		 * Unknown statuses (for this post's registry), self-transitions and
		 * malformed entries are dropped. Returning a non-array keeps the core map.
		 *
		 * @since 1.0.0
		 * @since 1.2.0 Added $post_id.
		 *
		 * @param array<string, string[]> $map     Transition map.
		 * @param int                     $post_id Post id; 0 when the question is not about one post.
		 */
		$filtered = apply_filters( 'sit_cwm_transition_map', $core, $post_id );
		$map      = is_array( $filtered ) ? $this->normalize( $filtered, $post_id ) : $core;

		// Filters (and the status registry) are settled once `init` has run.
		if ( did_action( 'init' ) ) {
			if ( count( $this->map ) >= self::MEMO_LIMIT ) {
				$this->map = array();
			}

			$this->map[ $post_id ] = $map;
		}

		return $map;
	}

	/**
	 * Legal next statuses from a status, in map order.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param string $from    Current status slug.
	 * @param int    $post_id Post id; 0 when the question is not about one post.
	 * @return string[] Target slugs; empty for unknown or terminal statuses.
	 */
	public function targets_for( string $from, int $post_id = 0 ): array {
		$map = $this->map( $post_id );

		return isset( $map[ $from ] ) ? $map[ $from ] : array();
	}

	/**
	 * Whether `$from → $to` is a structurally legal transition.
	 *
	 * Says nothing about whether any user may perform it.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param string $from    Current status slug.
	 * @param string $to      Target status slug.
	 * @param int    $post_id Post id; 0 when the question is not about one post.
	 * @return bool
	 */
	public function is_valid( string $from, string $to, int $post_id = 0 ): bool {
		return in_array( $to, $this->targets_for( $from, $post_id ), true );
	}

	/**
	 * Describes a transition so the UI can style it without knowing the graph.
	 *
	 * Both flags are false for an invalid transition. The label is the target
	 * status label, unescaped; escape at output.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param string $from    Current status slug.
	 * @param string $to      Target status slug.
	 * @param int    $post_id Post id; 0 when the question is not about one post.
	 * @return array{slug: string, label: string, is_forward: bool, is_rollback: bool}
	 */
	public function describe( string $from, string $to, int $post_id = 0 ): array {
		$is_valid    = $this->is_valid( $from, $to, $post_id );
		$rollbacks   = $this->rollbacks( $post_id );
		$is_rollback = $is_valid && isset( $rollbacks[ $from ] ) && in_array( $to, $rollbacks[ $from ], true );

		return array(
			'slug'        => $to,
			'label'       => $this->statuses->label( $to, $post_id ),
			'is_forward'  => $is_valid && ! $is_rollback,
			'is_rollback' => $is_rollback,
		);
	}

	/**
	 * Discards every memoised map so the next call re-resolves it.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Empties every post's entry.
	 * @api
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->map       = array();
		$this->rollbacks = array();
	}

	/**
	 * The validated rollback map.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 *
	 * @param int $post_id Post id; 0 when the question is not about one post.
	 * @return array<string, string[]> Source slug => rollback target slugs.
	 */
	private function rollbacks( int $post_id ): array {
		$post_id = max( 0, $post_id );

		if ( isset( $this->rollbacks[ $post_id ] ) ) {
			return $this->rollbacks[ $post_id ];
		}

		$core = $this->normalize( self::ROLLBACKS, $post_id );

		/**
		 * Filters which transitions count as rollbacks (sending content back).
		 *
		 * Same shape as `sit_cwm_transition_map`. Entries only take effect for
		 * transitions that are also in the transition map.
		 *
		 * @since 1.0.0
		 * @since 1.2.0 Added $post_id.
		 *
		 * @param array<string, string[]> $rollbacks Rollback map.
		 * @param int                     $post_id   Post id; 0 when the question is not about one post.
		 */
		$filtered  = apply_filters( 'sit_cwm_rollback_transitions', $core, $post_id );
		$rollbacks = is_array( $filtered ) ? $this->normalize( $filtered, $post_id ) : $core;

		if ( did_action( 'init' ) ) {
			if ( count( $this->rollbacks ) >= self::MEMO_LIMIT ) {
				$this->rollbacks = array();
			}

			$this->rollbacks[ $post_id ] = $rollbacks;
		}

		return $rollbacks;
	}

	/**
	 * Keeps only registered, non-self, de-duplicated transitions.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 *
	 * @param array $raw     Raw map.
	 * @param int   $post_id Post id whose status registry applies.
	 * @return array<string, string[]>
	 */
	private function normalize( array $raw, int $post_id ): array {
		$map = array();

		foreach ( $raw as $from => $targets ) {
			if ( ! is_string( $from ) || ! $this->statuses->exists( $from, $post_id ) || ! is_array( $targets ) ) {
				continue;
			}

			$valid = array();

			foreach ( $targets as $to ) {
				if ( ! is_string( $to ) || $from === $to || ! $this->statuses->exists( $to, $post_id ) || in_array( $to, $valid, true ) ) {
					continue;
				}

				$valid[] = $to;
			}

			if ( array() !== $valid ) {
				$map[ $from ] = $valid;
			}
		}

		return $map;
	}
}
