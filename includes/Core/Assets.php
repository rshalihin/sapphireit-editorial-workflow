<?php
/**
 * Compiled asset loader.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Core;

use Sit_Cwm\Admin\GettingStartedPage;
use Sit_Cwm\Governance\Attestation;
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
 * Not `Bootable`: the screen-specific services (editor sidebar, classic
 * meta box, dashboard) decide when to call it.
 *
 * @since 1.0.0
 * @internal
 */
final class Assets {

	/**
	 * Build entry names (see `webpack.config.js`).
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const ENTRIES = array( 'sidebar', 'dashboard', 'classic', 'admin-shell', 'report', 'review-disclosure' );

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
	 * Plugin settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Review attestation.
	 *
	 * @since 1.2.0
	 * @var Attestation
	 */
	private $attestation;

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
	 * @since 1.2.0 Added $settings and $attestation.
	 *
	 * @param StatusManager     $statuses      Status registry.
	 * @param PermissionManager $permissions   Workflow authorization.
	 * @param string            $build_dir     Absolute build directory path.
	 * @param string            $build_url     Build directory URL.
	 * @param string            $languages_dir Absolute translations directory path.
	 * @param Settings|null     $settings      Plugin settings; a fresh accessor when null.
	 * @param Attestation|null  $attestation   Review attestation; built from $settings when null.
	 */
	public function __construct( StatusManager $statuses, PermissionManager $permissions, string $build_dir, string $build_url, string $languages_dir, ?Settings $settings = null, ?Attestation $attestation = null ) {
		$this->statuses      = $statuses;
		$this->permissions   = $permissions;
		$this->build_dir     = trailingslashit( $build_dir );
		$this->build_url     = trailingslashit( $build_url );
		$this->languages_dir = $languages_dir;
		$this->settings      = $settings ?? new Settings();
		$this->attestation   = $attestation ?? new Attestation( $this->settings );
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
		$asset = $this->register( $entry );

		if ( null === $asset ) {
			return false;
		}

		$handle = $this->script_handle( $entry );

		wp_enqueue_script( $handle );

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
	 * Registers a build entry's script and translations without enqueueing
	 * it, for handles WordPress enqueues itself (a block's `editorScript`).
	 *
	 * No stylesheet and no bootstrap object: those come with `enqueue()`.
	 *
	 * @since 1.2.0
	 *
	 * @param string $entry Entry name from `ENTRIES`.
	 * @return bool False when the entry is unknown or has not been built.
	 */
	public function register_script( string $entry ): bool {
		return null !== $this->register( $entry );
	}

	/**
	 * Data exposed to scripts as `window.sitCwm`.
	 *
	 * UI hints only: the server re-checks every action, so nothing here is
	 * trusted on the way back. Contains no nonces or other secrets
	 * (`@wordpress/api-fetch` already carries the `wp_rest` nonce).
	 *
	 * `requireAttestation` is the site setting only; the per-post answer,
	 * after the `sit_cwm_require_attestation` filter, is in the workflow
	 * payload (F10).
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added `requireAttestation`, `attestationStatement` and `gettingStartedUrl`.
	 *
	 * @return array{restNamespace: string, statuses: array[], capabilities: array<string, bool>, postTypes: array[], adminUrl: string, requireAttestation: bool, attestationStatement: string, gettingStartedUrl: string}
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
			'restNamespace'        => self::REST_NAMESPACE,
			// Site-wide context (F2).
			'statuses'             => array_values( $this->statuses->all() ),
			'capabilities'         => $this->permissions->capability_flags(),
			'postTypes'            => $post_types,
			'adminUrl'             => admin_url(),
			'requireAttestation'   => $this->settings->requires_attestation(),
			'attestationStatement' => $this->attestation->statement(),
			'gettingStartedUrl'    => GettingStartedPage::url(),
		);
	}

	/**
	 * Registers an entry's script from its asset file and sets its
	 * translations.
	 *
	 * @since 1.2.0
	 *
	 * @param string $entry Entry name.
	 * @return array{dependencies: string[], version: string}|null The asset
	 *                                                           data; null when
	 *                                                           nothing was
	 *                                                           registered.
	 */
	private function register( string $entry ): ?array {
		$asset = $this->asset( $entry );

		if ( null === $asset ) {
			return null;
		}

		$handle = $this->script_handle( $entry );

		wp_register_script( $handle, $this->build_url . $entry . '.js', $this->dependencies( $entry, $asset['dependencies'] ), $asset['version'], true );
		wp_set_script_translations( $handle, 'sapphireit-editorial-workflow', $this->languages_dir );

		return $asset;
	}

	/**
	 * An entry's dependencies plus the handles add-ons need loaded before it.
	 *
	 * WordPress drops a script when one of its dependencies is missing, so a
	 * handle that is not registered yet is left out.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $entry        Entry name.
	 * @param string[] $dependencies The entry's own dependencies.
	 * @return string[]
	 */
	private function dependencies( string $entry, array $dependencies ): array {
		/**
		 * Filters the extra script handles to load before a build entry.
		 *
		 * Register the handle first. The entry's own dependencies cannot be
		 * removed.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $deps  Extra handles. Default empty.
		 * @param string   $entry Entry name, such as `dashboard` or `sidebar`.
		 */
		$extra = (array) apply_filters( 'sit_cwm_script_dependencies', array(), $entry );

		foreach ( $extra as $handle ) {
			if ( is_string( $handle ) && wp_script_is( $handle, 'registered' ) && ! in_array( $handle, $dependencies, true ) ) {
				$dependencies[] = $handle;
			}
		}

		return $dependencies;
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
