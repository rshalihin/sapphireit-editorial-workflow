<?php
/**
 * Plugin settings accessor.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Typed accessor over the `sit_cwm_settings` option (D7).
 *
 * Reads always return every known key (stored values over defaults); writes
 * merge, validate and sanitize before saving.
 *
 * @since 1.0.0
 */
final class Settings {

	/**
	 * Option name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION = 'sit_cwm_settings';

	/**
	 * Post types that can never be workflow-enabled, on top of every `wp_*`
	 * post type core uses internally (blocks, templates, navigation, …).
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const EXCLUDED_POST_TYPES = array( 'attachment', 'revision', 'nav_menu_item' );

	/**
	 * Default settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array{post_types: string[], delete_data_on_uninstall: bool}
	 */
	public function defaults(): array {
		return array(
			'post_types'               => array( 'post', 'page' ),
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings: stored values merged over defaults, known keys only.
	 *
	 * @since 1.0.0
	 *
	 * @return array{post_types: string[], delete_data_on_uninstall: bool}
	 */
	public function all(): array {
		$defaults = $this->defaults();
		$stored   = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
	}

	/**
	 * A single setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Returned when the key is unknown.
	 * @return mixed
	 */
	public function get( string $key, $default_value = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Merges a partial settings array into the stored settings and saves it.
	 *
	 * The whole update is rejected (nothing saved) when the payload is not an
	 * array or `post_types` is not a list of post types that may be enabled
	 * (see `selectable_post_types()`). Unknown keys are ignored.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $partial Partial settings, e.g. `[ 'post_types' => [ 'post' ] ]`.
	 * @return bool True when the settings are valid and stored.
	 */
	public function update( $partial ): bool {
		if ( ! is_array( $partial ) ) {
			return false;
		}

		$settings = $this->all();

		if ( array_key_exists( 'post_types', $partial ) ) {
			$post_types = $this->sanitize_post_types( $partial['post_types'] );

			if ( null === $post_types ) {
				return false;
			}

			$settings['post_types'] = $post_types;
		}

		if ( array_key_exists( 'delete_data_on_uninstall', $partial ) ) {
			$settings['delete_data_on_uninstall'] = rest_sanitize_boolean( $partial['delete_data_on_uninstall'] );
		}

		if ( get_option( self::OPTION ) === $settings ) {
			return true;
		}

		return update_option( self::OPTION, $settings, true );
	}

	/**
	 * Seeds the option with defaults when it is absent. Never overwrites.
	 *
	 * Autoloaded: it is small and read on every request, so it rides along
	 * with `alloptions` instead of costing its own query.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function seed(): void {
		add_option( self::OPTION, $this->defaults(), '', true );
	}

	/**
	 * Post types the workflow applies to.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function enabled_post_types(): array {
		$types = (array) $this->get( 'post_types', array() );

		/**
		 * Filters the post types the editorial workflow applies to.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $types Post type slugs from settings.
		 */
		$types = apply_filters( 'sit_cwm_enabled_post_types', $types );

		return array_values( array_filter( (array) $types, 'is_string' ) );
	}

	/**
	 * Whether the workflow applies to a post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public function is_post_type_enabled( string $post_type ): bool {
		return in_array( $post_type, $this->enabled_post_types(), true );
	}

	/**
	 * Post types the workflow may be newly enabled for: editorial content with
	 * an admin UI, REST support and the `editor` feature, minus
	 * `EXCLUDED_POST_TYPES` and core's internal `wp_*` post types.
	 *
	 * Back-office types such as WooCommerce orders and coupons have no
	 * `editor` support, so they are not offered.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Slug => slug, in registration order.
	 */
	public function available_post_types(): array {
		$types = array();

		foreach ( get_post_types( array( 'show_ui' => true ) ) as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( null === $object || ! $object->show_in_rest || ! post_type_supports( $post_type, 'editor' ) ) {
				continue;
			}

			$types[ $post_type ] = $post_type;
		}

		/**
		 * Filters the post types the workflow may be enabled for.
		 *
		 * Use it to offer a post type the default rules leave out. Internal
		 * post types (attachments, revisions, menu items, `wp_*`) and
		 * unregistered ones are removed afterwards.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $types Slug => slug.
		 */
		$filtered = apply_filters( 'sit_cwm_available_post_types', $types );
		$types    = array();

		foreach ( (array) $filtered as $post_type ) {
			if ( ! is_string( $post_type ) || ! post_type_exists( $post_type ) || $this->is_internal_post_type( $post_type ) ) {
				continue;
			}

			$types[ $post_type ] = $post_type;
		}

		return $types;
	}

	/**
	 * Post types that may be saved as enabled: `available_post_types()` plus
	 * any still-registered type that is already enabled but no longer
	 * eligible, so saving the settings never silently drops it.
	 *
	 * Shared by `update()` and the settings screen, so both accept the same list.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Slug => slug; available types first.
	 */
	public function selectable_post_types(): array {
		$types = $this->available_post_types();

		foreach ( $this->unsupported_enabled_post_types() as $post_type ) {
			$types[ $post_type ] = $post_type;
		}

		return $types;
	}

	/**
	 * Enabled post types that `available_post_types()` no longer offers.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Registered, non-internal slugs.
	 */
	public function unsupported_enabled_post_types(): array {
		$available = $this->available_post_types();
		$types     = array();

		foreach ( (array) $this->get( 'post_types', array() ) as $post_type ) {
			if ( is_string( $post_type ) && ! isset( $available[ $post_type ] ) && post_type_exists( $post_type ) && ! $this->is_internal_post_type( $post_type ) ) {
				$types[] = $post_type;
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Whether a post type can never be workflow-enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private function is_internal_post_type( string $post_type ): bool {
		return in_array( $post_type, self::EXCLUDED_POST_TYPES, true ) || 0 === strpos( $post_type, 'wp_' );
	}

	/**
	 * Validates a post type list against `selectable_post_types()`.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return string[]|null Unique slugs, or null when invalid.
	 */
	private function sanitize_post_types( $value ): ?array {
		if ( ! is_array( $value ) ) {
			return null;
		}

		$available = $this->selectable_post_types();
		$types     = array();

		foreach ( $value as $post_type ) {
			if ( ! is_string( $post_type ) ) {
				return null;
			}

			$post_type = sanitize_key( $post_type );

			if ( ! isset( $available[ $post_type ] ) ) {
				return null;
			}

			$types[] = $post_type;
		}

		return array_values( array_unique( $types ) );
	}
}
