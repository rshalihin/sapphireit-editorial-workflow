<?php
/**
 * Workflow status registry.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Workflow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single authoritative registry of workflow statuses (D3).
 *
 * Nothing else in the codebase hard-codes a status slug: labels, order,
 * colours, the default status and the shared status sanitizer all come from
 * here. The registry is filterable through `sit_cwm_statuses`, and the filter
 * result is validated so a misbehaving extension cannot corrupt core.
 *
 * @since 1.0.0
 */
final class StatusManager {

	/**
	 * Slug assigned to managed posts that have no workflow status yet.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const DEFAULT_STATUS = 'draft';

	/**
	 * Colour used when a filtered status omits a valid one.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const FALLBACK_COLOR = '#757575';

	/**
	 * Order used when a filtered status omits a valid one (sorts last).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const FALLBACK_ORDER = 1000;

	/**
	 * Resolved registry, memoised per request.
	 *
	 * @since 1.0.0
	 * @var array<string, array>|null
	 */
	private $statuses = null;

	/**
	 * All statuses, keyed by slug, in `order` sequence.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{slug: string, label: string, description: string, color: string, order: int, is_final: bool}>
	 */
	public function all(): array {
		if ( null !== $this->statuses ) {
			return $this->statuses;
		}

		$statuses = $this->resolve();

		// Translations are not loaded before `init`; don't cache untranslated labels.
		if ( did_action( 'init' ) ) {
			$this->statuses = $statuses;
		}

		return $statuses;
	}

	/**
	 * Status slugs in `order` sequence.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function slugs(): array {
		return array_keys( $this->all() );
	}

	/**
	 * A single status definition.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Status slug.
	 * @return array|null Definition, or null when the slug is not registered.
	 */
	public function get( string $slug ): ?array {
		$statuses = $this->all();

		return isset( $statuses[ $slug ] ) ? $statuses[ $slug ] : null;
	}

	/**
	 * Whether a slug is a registered status. Exact match, no normalisation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Status slug.
	 * @return bool
	 */
	public function exists( string $slug ): bool {
		return null !== $this->get( $slug );
	}

	/**
	 * Human-readable label for a status. Not escaped; escape at output.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Status slug.
	 * @return string Label, or the slug itself when it is not registered.
	 */
	public function label( string $slug ): string {
		$status = $this->get( $slug );

		return null === $status ? $slug : $status['label'];
	}

	/**
	 * Slug assigned to managed posts that have no workflow status yet.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function default_status(): string {
		return self::DEFAULT_STATUS;
	}

	/**
	 * Whether a status ends the workflow cycle.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Status slug.
	 * @return bool False for unregistered slugs.
	 */
	public function is_final( string $slug ): bool {
		$status = $this->get( $slug );

		return null !== $status && $status['is_final'];
	}

	/**
	 * Shared status sanitizer for post meta and REST arguments.
	 *
	 * Surrounding whitespace is trimmed; anything else that is not already a
	 * canonical registered slug (wrong case, invalid characters, unknown slug,
	 * non-string) yields the default status.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string A registered status slug.
	 */
	public function sanitize( $value ): string {
		if ( ! is_string( $value ) ) {
			return $this->default_status();
		}

		$value = trim( $value );

		if ( sanitize_key( $value ) !== $value || ! $this->exists( $value ) ) {
			return $this->default_status();
		}

		return $value;
	}

	/**
	 * Discards the memoised registry so the next call re-resolves it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->statuses = null;
	}

	/**
	 * Builds, filters and validates the registry.
	 *
	 * Falls back to the core registry when the filter returns a non-array, or
	 * when validation leaves no usable registry (empty, or missing the default
	 * status that every managed post relies on).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array>
	 */
	private function resolve(): array {
		$core = $this->normalize( $this->core_statuses() );

		/**
		 * Filters the workflow status registry.
		 *
		 * Each entry needs at least a `slug` (lowercase, `sanitize_key()`-safe)
		 * and a non-empty `label`; `description`, `color` (hex), `order` (int)
		 * and `is_final` (bool) are optional. Invalid entries are dropped.
		 *
		 * @since 1.0.0
		 *
		 * @param array $statuses Status definitions keyed by slug.
		 */
		$filtered = apply_filters( 'sit_cwm_statuses', $core );

		if ( ! is_array( $filtered ) ) {
			return $core;
		}

		$statuses = $this->normalize( $filtered );

		if ( ! isset( $statuses[ self::DEFAULT_STATUS ] ) ) {
			return $core;
		}

		return $statuses;
	}

	/**
	 * Validates raw definitions, re-keys them by slug and sorts by `order`.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw Raw definitions.
	 * @return array<string, array>
	 */
	private function normalize( array $raw ): array {
		$statuses = array();
		$position = array();

		foreach ( $raw as $entry ) {
			$status = $this->normalize_entry( $entry );

			if ( null === $status || isset( $statuses[ $status['slug'] ] ) ) {
				continue;
			}

			$statuses[ $status['slug'] ] = $status;
			$position[ $status['slug'] ] = count( $position );
		}

		// Stable sort: equal `order` keeps registration sequence.
		uksort(
			$statuses,
			static function ( $a, $b ) use ( $statuses, $position ) {
				return array( $statuses[ $a ]['order'], $position[ $a ] ) <=> array( $statuses[ $b ]['order'], $position[ $b ] );
			}
		);

		return $statuses;
	}

	/**
	 * Validates a single definition and fills optional fields.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $entry Raw definition.
	 * @return array|null Normalised definition, or null when invalid.
	 */
	private function normalize_entry( $entry ): ?array {
		if ( ! is_array( $entry ) || ! isset( $entry['slug'], $entry['label'] ) ) {
			return null;
		}

		$slug  = $entry['slug'];
		$label = $entry['label'];

		if ( ! is_string( $slug ) || '' === $slug || sanitize_key( $slug ) !== $slug ) {
			return null;
		}

		if ( ! is_string( $label ) || '' === trim( $label ) ) {
			return null;
		}

		$description = isset( $entry['description'] ) && is_string( $entry['description'] ) ? $entry['description'] : '';
		$color       = isset( $entry['color'] ) && is_string( $entry['color'] ) && preg_match( '/^#(?:[0-9a-fA-F]{3}){1,2}$/', $entry['color'] ) ? $entry['color'] : self::FALLBACK_COLOR;
		$order       = isset( $entry['order'] ) && is_int( $entry['order'] ) ? $entry['order'] : self::FALLBACK_ORDER;

		return array(
			'slug'        => $slug,
			'label'       => $label,
			'description' => $description,
			'color'       => $color,
			'order'       => $order,
			'is_final'    => isset( $entry['is_final'] ) && true === $entry['is_final'],
		);
	}

	/**
	 * Core status definitions (D3). Built on demand so labels are translated.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array>
	 */
	private function core_statuses(): array {
		return array(
			'draft'         => array(
				'slug'        => 'draft',
				'label'       => _x( 'Draft', 'workflow status', 'sit-cwm' ),
				'description' => __( 'Not yet started in the workflow.', 'sit-cwm' ),
				'color'       => '#757575',
				'order'       => 10,
				'is_final'    => false,
			),
			'writing'       => array(
				'slug'        => 'writing',
				'label'       => _x( 'Writing', 'workflow status', 'sit-cwm' ),
				'description' => __( 'The author is working on the content.', 'sit-cwm' ),
				'color'       => '#3858e9',
				'order'       => 20,
				'is_final'    => false,
			),
			'review'        => array(
				'slug'        => 'review',
				'label'       => _x( 'Review', 'workflow status', 'sit-cwm' ),
				'description' => __( 'Awaiting reviewer feedback.', 'sit-cwm' ),
				'color'       => '#996800',
				'order'       => 30,
				'is_final'    => false,
			),
			'needs_changes' => array(
				'slug'        => 'needs_changes',
				'label'       => _x( 'Needs Changes', 'workflow status', 'sit-cwm' ),
				'description' => __( 'Sent back to the author for changes.', 'sit-cwm' ),
				'color'       => '#d63638',
				'order'       => 40,
				'is_final'    => false,
			),
			'approved'      => array(
				'slug'        => 'approved',
				'label'       => _x( 'Approved', 'workflow status', 'sit-cwm' ),
				'description' => __( 'Cleared for publication.', 'sit-cwm' ),
				'color'       => '#008a20',
				'order'       => 50,
				'is_final'    => false,
			),
			'published'     => array(
				'slug'        => 'published',
				'label'       => _x( 'Published', 'workflow status', 'sit-cwm' ),
				'description' => __( 'Published; the workflow cycle is complete.', 'sit-cwm' ),
				'color'       => '#2271b1',
				'order'       => 60,
				'is_final'    => true,
			),
		);
	}
}
