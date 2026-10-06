<?php
/**
 * Minimal WordPress function stubs for the unit suite.
 *
 * Loaded only in unit mode (see bootstrap.php), never alongside WordPress.
 * Function names are dictated by WordPress core, so the prefix sniff is
 * disabled for this file.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Stubs must match WordPress core function names.
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, WordPress.WP.I18n -- Test-only shims.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['sit_cwm_test_filters'] = array();
$GLOBALS['sit_cwm_test_actions'] = array( 'init' => 1 );

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation stub: returns the text unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	/**
	 * Translation-with-context stub: returns the text unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text    Text.
	 * @param string $context Translation context.
	 * @param string $domain  Text domain.
	 * @return string
	 */
	function _x( $text, $context, $domain = 'default' ) {
		unset( $context, $domain );
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Port of core `sanitize_key()`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$sanitized = '';

		if ( is_scalar( $key ) ) {
			$sanitized = strtolower( (string) $key );
			$sanitized = preg_replace( '/[^a-z0-9_\-]/', '', $sanitized );
		}

		return $sanitized;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Registers a filter callback (priority ignored beyond ordering).
	 *
	 * @since 1.0.0
	 *
	 * @param string   $hook_name Hook.
	 * @param callable $callback  Callback.
	 * @param int      $priority  Priority.
	 * @return true
	 */
	function add_filter( $hook_name, $callback, $priority = 10 ) {
		$GLOBALS['sit_cwm_test_filters'][ $hook_name ][ $priority ][] = $callback;
		ksort( $GLOBALS['sit_cwm_test_filters'][ $hook_name ] );
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Runs registered filter callbacks.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_name Hook.
	 * @param mixed  $value     Value.
	 * @param mixed  ...$args   Extra arguments.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) {
		if ( empty( $GLOBALS['sit_cwm_test_filters'][ $hook_name ] ) ) {
			return $value;
		}

		foreach ( $GLOBALS['sit_cwm_test_filters'][ $hook_name ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = $callback( $value, ...$args );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	/**
	 * Removes every callback from a hook.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_name Hook.
	 * @return true
	 */
	function remove_all_filters( $hook_name ) {
		unset( $GLOBALS['sit_cwm_test_filters'][ $hook_name ] );
		return true;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	/**
	 * Number of times an action has fired. `init` counts as fired by default.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_name Hook.
	 * @return int
	 */
	function did_action( $hook_name ) {
		return isset( $GLOBALS['sit_cwm_test_actions'][ $hook_name ] ) ? (int) $GLOBALS['sit_cwm_test_actions'][ $hook_name ] : 0;
	}
}
