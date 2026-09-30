<?php
/**
 * Compiled asset loader.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Core;

use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the `@wordpress/scripts` build entries and their bootstrap data.
 *
 * Dependencies and versions always come from the `*.asset.php` file the
 * build emits next to each bundle; nothing here hand-maintains a dependency
 * array. Only the entries in `ENTRIES` can be loaded, so no path is ever built
 * from anything but a hard-coded name.
 *
 * Not `Bootable`: the screen-specific services (editor sidebar, dashboard)
 * decide when to call it.
 *
 * @since 1.0.0
 */
final class Assets {

	/**
	 * Build entry names (see `webpack.config.js`).
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const ENTRIES = array( 'sidebar', 'dashboard' );

	/**
	 * REST namespace exposed to scripts (D9).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const REST_NAMESPACE = 'sit-cwm/v1';

	/**
	 * Name of the global bootstrap object (`window.sitCwm`).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const GLOBAL_NAME = 'sitCwm';

	/**
	 * Status registry.
	 *
	 * @since 1.0.0
	 * @var StatusManager
	 */
	private $statuses;

	/**
	 * Workflow authorization.
	 *
	 * @since 1.0.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Absolute path of the build directory, with trailing slash.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $build_dir;

	/**
	 * URL of the build directory, with trailing slash.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $build_url;

	/**
	 * Absolute path of the translations directory.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $languages_dir;

	/**
	 * Script handles that already received the bootstrap object.
	 *
	 * @since 1.0.0
	 * @var array<string, true>
	 */
	private $bootstrapped = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param StatusManager     $statuses      Status registry.
	 * @param PermissionManager $permissions   Workflow authorization.
	 * @param string            $build_dir     Absolute build directory path.
	 * @param string            $build_url     Build directory URL.
	 * @param string            $languages_dir Absolute translations directory path.
	 */
	public function __construct( StatusManager $statuses, PermissionManager $permissions, string $build_dir, string $build_url, string $languages_dir ) {
		$this->statuses      = $statuses;
		$this->permissions   = $permissions;
		$this->build_dir     = trailingslashit( $build_dir );
		$this->build_url     = trailingslashit( $build_url );
		$this->languages_dir = $languages_dir;
	}

	/**
	 * Script handle of a build entry (D1), e.g. `sit-cwm-sidebar-js`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $entry Entry name.
	 * @return string
	 */
	public function script_handle( string $entry ): string {
		return 'sit-cwm-' . $entry . '-js';
	}

	/**
	 * Style handle of a build entry (D1), e.g. `sit-cwm-sidebar-css`.
	 *
	 * @since 1.0.0
	 *
	 * @param string $entry Entry name.
	 * @return string
	 */
	public function style_handle( string $entry ): string {
		return 'sit-cwm-' . $entry . '-css';
	}

	/**
	 * Enqueues a build entry's script, translations, stylesheet (when the
	 * build emitted one) and the `window.sitCwm` bootstrap object.
	 *
	 * @since 1.0.0
	 *
	 * @param string $entry Entry name from `ENTRIES`.
	 * @return bool False when the entry is unknown or has not been built.
	 */
	public function enqueue( string $entry ): bool {
		$asset = $this->asset( $entry );

		if ( null === $asset ) {
			return false;
		}

		$handle = $this->script_handle( $entry );

		wp_enqueue_script( $handle, $this->build_url . $entry . '.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( $handle, 'sapphireit-editorial-workflow', $this->languages_dir );

		if ( is_readable( $this->build_dir . $entry . '.css' ) ) {
			wp_enqueue_style( $this->style_handle( $entry ), $this->build_url . $entry . '.css', array( 'wp-components' ), $asset['version'] );

			// The build emits `{entry}-rtl.css`; core swaps it in for RTL locales.
			if ( is_readable( $this->build_dir . $entry . '-rtl.css' ) ) {
				wp_style_add_data( $this->style_handle( $entry ), 'rtl', 'replace' );
			}
		}

		$this->add_bootstrap( $handle );

		return true;
	}

	/**
	 * Data exposed to scripts as `window.sitCwm`.
	 *
	 * UI hints only: the server re-checks every action, so nothing here is
	 * trusted on the way back. Contains no nonces or other secrets
	 * (`@wordpress/api-fetch` already carries the `wp_rest` nonce).
	 *
	 * @since 1.0.0
	 *
	 * @return array{restNamespace: string, statuses: array[], capabilities: array<string, bool>, postTypes: array[], adminUrl: string}
	 */
	public function bootstrap_data(): array {
		$post_types = array();

		foreach ( $this->permissions->dashboard_post_types() as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( null === $object ) {
				continue;
			}

			$post_types[] = array(
				'slug'          => $post_type,
				'label'         => $object->labels->name,
				'singularLabel' => $object->labels->singular_name,
			);
		}

		return array(
			'restNamespace' => self::REST_NAMESPACE,
			'statuses'      => array_values( $this->statuses->all() ),
			'capabilities'  => $this->permissions->capability_flags(),
			'postTypes'     => $post_types,
			'adminUrl'      => admin_url(),
		);
	}

	/**
	 * Reads and validates an entry's `*.asset.php` file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $entry Entry name.
	 * @return array{dependencies: string[], version: string}|null Null when the
	 *                                                           entry is unknown,
	 *                                                           unbuilt or malformed.
	 */
	private function asset( string $entry ): ?array {
		if ( ! in_array( $entry, self::ENTRIES, true ) ) {
			return null;
		}

		$file = $this->build_dir . $entry . '.asset.php';

		if ( ! is_readable( $file ) || ! is_readable( $this->build_dir . $entry . '.js' ) ) {
			return null;
		}

		$asset = require $file;

		if ( ! is_array( $asset ) || ! isset( $asset['dependencies'], $asset['version'] ) || ! is_array( $asset['dependencies'] ) || ! is_string( $asset['version'] ) ) {
			return null;
		}

		return array(
			'dependencies' => array_values( array_filter( $asset['dependencies'], 'is_string' ) ),
			'version'      => $asset['version'],
		);
	}

	/**
	 * Prints the bootstrap object before a script, once per handle.
	 *
	 * @since 1.0.0
	 *
	 * @param string $handle Script handle.
	 * @return void
	 */
	private function add_bootstrap( string $handle ): void {
		if ( isset( $this->bootstrapped[ $handle ] ) ) {
			return;
		}

		$this->bootstrapped[ $handle ] = true;

		wp_add_inline_script(
			$handle,
			sprintf( 'window.%s = %s;', self::GLOBAL_NAME, wp_json_encode( $this->bootstrap_data() ) ),
			'before'
		);
	}
}
