<?php
/**
 * Integration tests for the compiled asset loader.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Content\PostRepository;
use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueueing from `*.asset.php` files and the `window.sitCwm` bootstrap.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Core\Assets
 */
final class AssetsTest extends WP_UnitTestCase {

	/**
	 * Resets script/style registries and grants the default capabilities.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->reset_dependencies();
		delete_option( Settings::OPTION );
		Capabilities::add_caps();
	}

	/**
	 * Resets script/style registries.
	 *
	 * @return void
	 */
	public function tear_down() {
		$this->reset_dependencies();

		parent::tear_down();
	}

	/**
	 * The script is enqueued in the footer with the dependencies and version
	 * from its asset file, plus translations.
	 *
	 * @return void
	 */
	public function test_enqueue_uses_asset_file() {
		$assets = $this->make_assets( 'build' );

		$this->assertTrue( $assets->enqueue( 'sidebar' ) );

		$handle = 'sit-cwm-sidebar-js';
		$script = wp_scripts()->registered[ $handle ];

		$this->assertSame( $handle, $assets->script_handle( 'sidebar' ) );
		$this->assertTrue( wp_script_is( $handle, 'enqueued' ) );
		$this->assertSame( self::build_url() . 'sidebar.js', $script->src );
		$this->assertSame( array( 'wp-element', 'wp-i18n' ), $script->deps );
		$this->assertSame( 'fixture-sidebar', $script->ver );
		$this->assertSame( 1, wp_scripts()->get_data( $handle, 'group' ) );
		$this->assertSame( 'sapphireit-editorial-workflow', $script->textdomain );
		$this->assertSame( '/languages', $script->translations_path );
	}

	/**
	 * A stylesheet is enqueued only when the build emitted one.
	 *
	 * @return void
	 */
	public function test_style_enqueued_only_when_built() {
		$assets = $this->make_assets( 'build' );

		$this->assertTrue( $assets->enqueue( 'dashboard' ) );
		$this->assertFalse( wp_style_is( 'sit-cwm-dashboard-css', 'registered' ) );

		$this->assertTrue( $assets->enqueue( 'sidebar' ) );
		$this->assertTrue( wp_style_is( 'sit-cwm-sidebar-css', 'enqueued' ) );

		$style = wp_styles()->registered['sit-cwm-sidebar-css'];

		$this->assertSame( self::build_url() . 'sidebar.css', $style->src );
		$this->assertSame( array( 'wp-components' ), $style->deps );
		$this->assertSame( 'fixture-sidebar', $style->ver );
	}

	/**
	 * Unknown, unbuilt and malformed entries enqueue nothing.
	 *
	 * @return void
	 */
	public function test_unknown_unbuilt_or_malformed_entries_are_rejected() {
		$built = $this->make_assets( 'build' );

		$this->assertFalse( $built->enqueue( 'settings' ) );
		$this->assertFalse( $built->enqueue( '../build/sidebar' ) );
		$this->assertFalse( $built->enqueue( 'Sidebar' ) );

		$this->assertFalse( $this->make_assets( 'missing' )->enqueue( 'sidebar' ) );

		$malformed = $this->make_assets( 'build-malformed' );

		$this->assertFalse( $malformed->enqueue( 'sidebar' ) );
		$this->assertFalse( $malformed->enqueue( 'dashboard' ) );

		$this->assertSame( array(), $this->plugin_handles( wp_scripts()->registered ) );
		$this->assertSame( array(), $this->plugin_handles( wp_styles()->registered ) );
	}

	/**
	 * The bootstrap object is printed once, before the script, as JSON with
	 * exactly the documented keys and no nonce.
	 *
	 * @return void
	 */
	public function test_bootstrap_printed_once_before_script() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$assets = $this->make_assets( 'build' );
		$assets->enqueue( 'sidebar' );
		$assets->enqueue( 'sidebar' );

		// Core seeds the inline list with `false`; keep only printed code.
		$before = array_values( array_filter( (array) wp_scripts()->get_data( 'sit-cwm-sidebar-js', 'before' ), 'is_string' ) );

		$this->assertCount( 1, $before );
		$this->assertStringStartsWith( 'window.sitCwm = ', $before[0] );
		$this->assertStringEndsWith( ';', $before[0] );
		$this->assertStringNotContainsStringIgnoringCase( 'nonce', $before[0] );

		$data = json_decode( substr( $before[0], strlen( 'window.sitCwm = ' ), -1 ), true );

		$this->assertSame( $assets->bootstrap_data(), $data );
		$this->assertSame( array( 'restNamespace', 'statuses', 'capabilities', 'postTypes', 'adminUrl' ), array_keys( $data ) );
	}

	/**
	 * Bootstrap values come from the status registry, the permission layer
	 * and the enabled post types the user may edit.
	 *
	 * @return void
	 */
	public function test_bootstrap_data_reflects_current_user() {
		$assets = $this->make_assets( 'build' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$data = $assets->bootstrap_data();

		$this->assertSame( 'sit-cwm/v1', $data['restNamespace'] );
		$this->assertSame( admin_url(), $data['adminUrl'] );
		$this->assertSame( ( new StatusManager() )->slugs(), array_column( $data['statuses'], 'slug' ) );
		$this->assertTrue( $data['capabilities'][ Capabilities::APPROVE_CONTENT ] );
		$this->assertFalse( $data['capabilities'][ Capabilities::MANAGE_WORKFLOWS ] );
		$this->assertSame(
			array(
				'slug'          => 'post',
				'label'         => 'Posts',
				'singularLabel' => 'Post',
			),
			$data['postTypes'][0]
		);
		$this->assertSame( array( 'post', 'page' ), array_column( $data['postTypes'], 'slug' ) );

		wp_set_current_user( 0 );
		$data = $assets->bootstrap_data();

		$this->assertSame( array_fill_keys( Capabilities::all(), false ), $data['capabilities'] );
		$this->assertSame( array(), $data['postTypes'] );
	}

	/**
	 * Builds a loader over a fixture build directory.
	 *
	 * @param string $fixture Directory name under `tests/php/fixtures/`.
	 * @return Assets
	 */
	private function make_assets( string $fixture ): Assets {
		$statuses = new StatusManager();
		$settings = new Settings();

		return new Assets(
			$statuses,
			new PermissionManager( new PostRepository( $statuses, $settings ), $settings ),
			dirname( __DIR__ ) . '/fixtures/' . $fixture,
			self::build_url(),
			'/languages'
		);
	}

	/**
	 * Build URL passed to the loader, derived from the plugin URL so a renamed
	 * plugin folder cannot break the assertions.
	 *
	 * @return string
	 */
	private static function build_url(): string {
		return SIT_CWM_PLUGIN_URL . 'assets/build/';
	}

	/**
	 * Plugin handles in a dependency registry.
	 *
	 * @param array $registered Registered dependencies keyed by handle.
	 * @return string[]
	 */
	private function plugin_handles( array $registered ): array {
		return array_values( preg_grep( '/^sit-cwm-/', array_keys( $registered ) ) );
	}

	/**
	 * Removes every plugin script and style (and their inline data).
	 *
	 * @return void
	 */
	private function reset_dependencies() {
		foreach ( Assets::ENTRIES as $entry ) {
			wp_dequeue_script( 'sit-cwm-' . $entry . '-js' );
			wp_deregister_script( 'sit-cwm-' . $entry . '-js' );
			wp_dequeue_style( 'sit-cwm-' . $entry . '-css' );
			wp_deregister_style( 'sit-cwm-' . $entry . '-css' );
		}
	}
}
