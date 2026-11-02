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
 * @api
 */
final class StatusManager {

	/**
	 * Slug assigned to managed posts that have no workflow status yet.
	 *
	 * @since 1.0.0
	 * @internal
	 * @var string
	 */
	const DEFAULT_STATUS = 'draft';

	/**
	 * Colour used when a filtered status omits a valid one.
	 *
	 * @since 1.0.0
	 * @internal
	 * @var string
	 */
	const FALLBACK_COLOR = '#757575';

	/**
	 * Order used when a filtered status omits a valid one (sorts last).
	 *
	 * @since 1.0.0
	 * @internal
	 * @var int
	 */
	const FALLBACK_ORDER = 1000;

	/**
	 * Most post registries kept in the memo before it is emptied (F2).
	 *
	 * Bounds memory in long loops (export, batch) without an LRU.
	 *
	 * @since 1.2.0
	 * @internal
	 * @var int
	 */
	const MEMO_LIMIT = 256;

	/**
	 * Resolved registries keyed by post id. Keyed, never shared: see F2.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Keyed by post id.
	 * @var array<int, array<string, array>>
	 */
	private $statuses = array();

	/**
	 * All statuses, keyed by slug, in `order` sequence.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param int $post_id Post id; 0 when the question is not about one post.
	 * @return array<string, array{slug: string, label: string, description: string, color: string, order: int, is_final: bool}>
	 */
	public function all( int $post_id = 0 ): array {
		$post_id = max( 0, $post_id );

		if ( isset( $this->statuses[ $post_id ] ) ) {
			return $this->statuses[ $post_id ];
		}

		$statuses = $this->resolve( $post_id );

		// Translations are not loaded before `init`; don't cache untranslated labels.
		if ( did_action( 'init' ) ) {
			if ( count( $this->statuses ) >= self::MEMO_LIMIT ) {
				$this->statuses = array();
			}

			$this->statuses[ $post_id ] = $statuses;
		}

		return $statuses;
	}

	/**
	 * Status slugs in `order` sequence.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param int $post_id Post id; 0 when the question is not about one post.
	 * @return string[]
	 */
	public function slugs( int $post_id = 0 ): array {
		return array_keys( $this->all( $post_id ) );
	}

	/**
	 * A single status definition.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param string $slug    Status slug.
	 * @param int    $post_id Post id; 0 when the question is not about one post.
	 * @return array|null Definition, or null when the slug is not registered.
	 */
	public function get( string $slug, int $post_id = 0 ): ?array {
		$statuses = $this->all( $post_id );

		return isset( $statuses[ $slug ] ) ? $statuses[ $slug ] : null;
	}

	/**
	 * Whether a slug is a registered status. Exact match, no normalisation.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param string $slug    Status slug.
	 * @param int    $post_id Post id; 0 when the question is not about one post.
	 * @return bool
	 */
	public function exists( string $slug, int $post_id = 0 ): bool {
		return null !== $this->get( $slug, $post_id );
	}

	/**
	 * Human-readable label for a status. Not escaped; escape at output.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param string $slug    Status slug.
	 * @param int    $post_id Post id; 0 when the question is not about one post.
	 * @return string Label, or the slug itself when it is not registered.
	 */
	public function label( string $slug, int $post_id = 0 ): string {
		$status = $this->get( $slug, $post_id );

		return null === $status ? $slug : $status['label'];
	}

	/**
	 * Slug assigned to managed posts that have no workflow status yet.
	 *
	 * @since 1.0.0
	 * @api
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
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param string $slug    Status slug.
	 * @param int    $post_id Post id; 0 when the question is not about one post.
	 * @return bool False for unregistered slugs.
	 */
	public function is_final( string $slug, int $post_id = 0 ): bool {
		$status = $this->get( $slug, $post_id );

		return null !== $status && $status['is_final'];
	}

	/**
	 * Shared status sanitizer for REST arguments and stored values.
	 *
	 * Surrounding whitespace is trimmed; anything else that is not already a
	 * canonical registered slug (wrong case, invalid characters, unknown slug,
	 * non-string) yields the default status.
	 *
	 * `$post_id` is deliberately untyped: core passes extra arguments (the meta
	 * key, the REST request) to a `sanitize_callback`, and any non-int means
	 * the site-wide registry, so this stays usable as a callback (F2).
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 * @api
	 *
	 * @param mixed $value   Raw value.
	 * @param mixed $post_id Post id whose registry applies; 0 or any non-int for the site-wide one.
	 * @return string A registered status slug.
	 */
	public function sanitize( $value, $post_id = 0 ): string {
		$post_id = is_int( $post_id ) ? $post_id : 0;

		if ( ! is_string( $value ) ) {
			return $this->default_status();
		}

		$value = trim( $value );

		if ( sanitize_key( $value ) !== $value || ! $this->exists( $value, $post_id ) ) {
			return $this->default_status();
		}

		return $value;
	}

	/**
	 * Shape-only sanitizer for the stored status meta (F2).
	 *
	 * `sanitize_meta()` receives no post id, so it cannot know which post's
	 * registry applies. This only checks that the value is a canonical slug;
	 * PostRepository::set_status() validates it against the post's registry
	 * before writing.
	 *
	 * @since 1.2.0
	 * @internal
	 *
	 * @param mixed $value Raw value.
	 * @return string A canonical slug, or the default status.
	 */
	public function sanitize_stored( $value ): string {
		if ( ! is_string( $value ) ) {
			return $this->default_status();
		}

		$value = trim( $value );

		if ( '' === $value || sanitize_key( $value ) !== $value ) {
			return $this->default_status();
		}

		return $value;
	}

	/**
	 * Discards every memoised registry so the next call re-resolves it.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Empties every post's entry.
	 * @api
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->statuses = array();
	}

	/**
	 * Builds, filters and validates the registry.
	 *
	 * Falls back to the core registry when the filter returns a non-array, or
	 * when validation leaves no usable registry (empty, or missing the default
	 * status that every managed post relies on).
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added $post_id.
	 *
	 * @param int $post_id Post id; 0 when the question is not about one post.
	 * @return array<string, array>
	 */
	private function resolve( int $post_id ): array {
		$core = $this->normalize( $this->core_statuses() );

		/**
		 * Filters the workflow status registry.
		 *
		 * Each entry needs at least a `slug` (lowercase, `sanitize_key()`-safe)
		 * and a non-empty `label`; `description`, `color` (hex), `order` (int)
		 * and `is_final` (bool) are optional. Invalid entries are dropped.
		 *
		 * For `$post_id` 0, return every status any post can have: site-wide
		 * readers (dashboard filters, the statuses route, bootstrap data) ask
		 * without a post.
		 *
		 * @since 1.0.0
		 * @since 1.2.0 Added $post_id.
		 *
		 * @param array $statuses Status definitions keyed by slug.
		 * @param int   $post_id  Post id; 0 when the question is not about one post.
		 */
		$filtered = apply_filters( 'sit_cwm_statuses', $core, $post_id );

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
				'label'       => _x( 'Draft', 'workflow status', 'sapphireit-editorial-workflow' ),
				'description' => __( 'Not yet started in the workflow.', 'sapphireit-editorial-workflow' ),
				'color'       => '#757575',
				'order'       => 10,
				'is_final'    => false,
			),
			'writing'       => array(
				'slug'        => 'writing',
				'label'       => _x( 'Writing', 'workflow status', 'sapphireit-editorial-workflow' ),
				'description' => __( 'The author is working on the content.', 'sapphireit-editorial-workflow' ),
				'color'       => '#3858e9',
				'order'       => 20,
				'is_final'    => false,
			),
			'review'        => array(
				'slug'        => 'review',
				'label'       => _x( 'Review', 'workflow status', 'sapphireit-editorial-workflow' ),
				'description' => __( 'Awaiting reviewer feedback.', 'sapphireit-editorial-workflow' ),
				'color'       => '#996800',
				'order'       => 30,
				'is_final'    => false,
			),
			'needs_changes' => array(
				'slug'        => 'needs_changes',
				'label'       => _x( 'Needs Changes', 'workflow status', 'sapphireit-editorial-workflow' ),
				'description' => __( 'Sent back to the author for changes.', 'sapphireit-editorial-workflow' ),
				'color'       => '#d63638',
				'order'       => 40,
				'is_final'    => false,
			),
			'approved'      => array(
				'slug'        => 'approved',
				'label'       => _x( 'Approved', 'workflow status', 'sapphireit-editorial-workflow' ),
				'description' => __( 'Cleared for publication.', 'sapphireit-editorial-workflow' ),
				'color'       => '#008a20',
				'order'       => 50,
				'is_final'    => false,
			),
			'published'     => array(
				'slug'        => 'published',
				'label'       => _x( 'Published', 'workflow status', 'sapphireit-editorial-workflow' ),
				'description' => __( 'Published; the workflow cycle is complete.', 'sapphireit-editorial-workflow' ),
				'color'       => '#2271b1',
				'order'       => 60,
				'is_final'    => true,
			),
		);
	}
}
