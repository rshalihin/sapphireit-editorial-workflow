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
 * The map is filterable through `sit_cwm_transition_map`, and the filter
 * result is validated against the StatusManager registry so an extension can
 * never introduce an unknown status or a self-transition.
 *
 * @since 1.0.0
 */
final class TransitionManager {

	/**
	 * Core transition map (D4): source slug => ordered list of target slugs.
	 *
	 * @since 1.0.0
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
	 * @var array<string, string[]>
	 */
	const ROLLBACKS = array(
		'review'    => array( 'needs_changes' ),
		'approved'  => array( 'needs_changes' ),
		'published' => array( 'writing' ),
	);

	/**
	 * Status registry.
	 *
	 * @since 1.0.0
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Resolved transition map, memoised per request.
	 *
	 * @since 1.0.0
	 * @var array<string, string[]>|null
	 */
	private $map = null;

	/**
	 * Resolved rollback map, memoised per request.
	 *
	 * @since 1.0.0
	 * @var array<string, string[]>|null
	 */
	private $rollbacks = null;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
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
	 *
	 * @return array<string, string[]> Source slug => ordered target slugs.
	 */
	public function map(): array {
		if ( null !== $this->map ) {
			return $this->map;
		}

		$core = $this->normalize( self::MAP );

		/**
		 * Filters the workflow transition map.
		 *
		 * Return an array of source status slug => list of target status slugs.
		 * Unknown statuses, self-transitions and malformed entries are dropped.
		 * Returning a non-array keeps the core map.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string[]> $map Transition map.
		 */
		$filtered = apply_filters( 'sit_cwm_transition_map', $core );
		$map      = is_array( $filtered ) ? $this->normalize( $filtered ) : $core;

		// Filters (and the status registry) are settled once `init` has run.
		if ( did_action( 'init' ) ) {
			$this->map = $map;
		}

		return $map;
	}

	/**
	 * Legal next statuses from a status, in map order.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Current status slug.
	 * @return string[] Target slugs; empty for unknown or terminal statuses.
	 */
	public function targets_for( string $from ): array {
		$map = $this->map();

		return isset( $map[ $from ] ) ? $map[ $from ] : array();
	}

	/**
	 * Whether `$from → $to` is a structurally legal transition.
	 *
	 * Says nothing about whether any user may perform it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Current status slug.
	 * @param string $to   Target status slug.
	 * @return bool
	 */
	public function is_valid( string $from, string $to ): bool {
		return in_array( $to, $this->targets_for( $from ), true );
	}

	/**
	 * Describes a transition so the UI can style it without knowing the graph.
	 *
	 * Both flags are false for an invalid transition. The label is the target
	 * status label, unescaped; escape at output.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Current status slug.
	 * @param string $to   Target status slug.
	 * @return array{slug: string, label: string, is_forward: bool, is_rollback: bool}
	 */
	public function describe( string $from, string $to ): array {
		$is_valid    = $this->is_valid( $from, $to );
		$rollbacks   = $this->rollbacks();
		$is_rollback = $is_valid && isset( $rollbacks[ $from ] ) && in_array( $to, $rollbacks[ $from ], true );

		return array(
			'slug'        => $to,
			'label'       => $this->statuses->label( $to ),
			'is_forward'  => $is_valid && ! $is_rollback,
			'is_rollback' => $is_rollback,
		);
	}

	/**
	 * Discards the memoised maps so the next call re-resolves them.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->map       = null;
		$this->rollbacks = null;
	}

	/**
	 * The validated rollback map.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string[]> Source slug => rollback target slugs.
	 */
	private function rollbacks(): array {
		if ( null !== $this->rollbacks ) {
			return $this->rollbacks;
		}

		$core = $this->normalize( self::ROLLBACKS );

		/**
		 * Filters which transitions count as rollbacks (sending content back).
		 *
		 * Same shape as `sit_cwm_transition_map`. Entries only take effect for
		 * transitions that are also in the transition map.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string[]> $rollbacks Rollback map.
		 */
		$filtered  = apply_filters( 'sit_cwm_rollback_transitions', $core );
		$rollbacks = is_array( $filtered ) ? $this->normalize( $filtered ) : $core;

		if ( did_action( 'init' ) ) {
			$this->rollbacks = $rollbacks;
		}

		return $rollbacks;
	}

	/**
	 * Keeps only registered, non-self, de-duplicated transitions.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw Raw map.
	 * @return array<string, string[]>
	 */
	private function normalize( array $raw ): array {
		$map = array();

		foreach ( $raw as $from => $targets ) {
			if ( ! is_string( $from ) || ! $this->statuses->exists( $from ) || ! is_array( $targets ) ) {
				continue;
			}

			$valid = array();

			foreach ( $targets as $to ) {
				if ( ! is_string( $to ) || $from === $to || ! $this->statuses->exists( $to ) || in_array( $to, $valid, true ) ) {
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
