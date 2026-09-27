<?php
/**
 * Guards the compiled bundles against script dependencies that the minimum
 * supported WordPress version does not register.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every handle in `assets/build/*.asset.php` must exist on WordPress 6.8.
 *
 * WordPress silently drops a script whose dependency is not registered, so a
 * bundle that needs a newer core script (DataViews 19 needing `wp-theme`, for
 * example) leaves the dashboard blank with no notice. This reads the real
 * build output, not the fixtures.
 *
 * @since 1.0.0
 *
 * @coversNothing
 */
final class AssetCompatTest extends WP_UnitTestCase {

	/**
	 * Script handles verified as registered by WordPress 6.8 core
	 * (`Requires at least`). Add a handle here only after checking it on a
	 * 6.8 site: `wp eval 'var_dump( wp_script_is( "<handle>", "registered" ) );'`.
	 *
	 * @var string[]
	 */
	const MINIMUM_WP_HANDLES = array(
		'react',
		'react-dom',
		'react-jsx-runtime',
		'wp-a11y',
		'wp-api-fetch',
		'wp-block-editor',
		'wp-blocks',
		'wp-commands',
		'wp-components',
		'wp-compose',
		'wp-core-data',
		'wp-data',
		'wp-date',
		'wp-deprecated',
		'wp-dom',
		'wp-dom-ready',
		'wp-edit-post',
		'wp-editor',
		'wp-element',
		'wp-escape-html',
		'wp-hooks',
		'wp-html-entities',
		'wp-i18n',
		'wp-is-shallow-equal',
		'wp-keyboard-shortcuts',
		'wp-keycodes',
		'wp-notices',
		'wp-plugins',
		'wp-preferences',
		'wp-primitives',
		'wp-private-apis',
		'wp-rich-text',
		'wp-url',
		'wp-viewport',
		'wp-warning',
	);

	/**
	 * Provides each compiled entry point.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function entry_provider() {
		return array(
			'sidebar'   => array( 'sidebar' ),
			'dashboard' => array( 'dashboard' ),
		);
	}

	/**
	 * Every dependency is one the minimum WordPress version registers.
	 *
	 * @dataProvider entry_provider
	 *
	 * @param string $entry Entry point name.
	 * @return void
	 */
	public function test_dependencies_exist_on_minimum_wordpress( $entry ) {
		$asset = $this->load_asset( $entry );

		$unknown = array_values( array_diff( $asset['dependencies'], self::MINIMUM_WP_HANDLES ) );

		$this->assertSame(
			array(),
			$unknown,
			sprintf(
				'assets/build/%s.asset.php depends on script handles not verified on WordPress 6.8: %s. See DEVELOPMENT.md, "Why DataViews is pinned".',
				$entry,
				implode( ', ', $unknown )
			)
		);
	}

	/**
	 * Every dependency is registered by the WordPress version under test.
	 *
	 * On the CI leg that runs the minimum version this is the exact check;
	 * on newer versions it catches a handle that core has since removed.
	 *
	 * @dataProvider entry_provider
	 *
	 * @param string $entry Entry point name.
	 * @return void
	 */
	public function test_dependencies_are_registered_by_running_core( $entry ) {
		$asset   = $this->load_asset( $entry );
		$scripts = wp_scripts();

		$missing = array_values(
			array_filter(
				$asset['dependencies'],
				static function ( $handle ) use ( $scripts ) {
					return ! $scripts->query( $handle, 'registered' );
				}
			)
		);

		$this->assertSame( array(), $missing, sprintf( 'Unregistered dependencies of %s: %s', $entry, implode( ', ', $missing ) ) );
	}

	/**
	 * Reads a compiled `*.asset.php` file.
	 *
	 * @param string $entry Entry point name.
	 * @return array{dependencies: string[], version: string}
	 */
	private function load_asset( $entry ) {
		$file = SIT_CWM_PLUGIN_DIR . 'assets/build/' . $entry . '.asset.php';

		$this->assertFileExists( $file, 'Run npm run build first.' );

		$asset = require $file;

		$this->assertIsArray( $asset );
		$this->assertArrayHasKey( 'dependencies', $asset );

		return $asset;
	}
}
