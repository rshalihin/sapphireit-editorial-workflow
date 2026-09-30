<?php
/**
 * Integration tests for plugin bootstrap and lifecycle.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Core\Activator;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Deactivator;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Plugin;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin boot, activation and deactivation.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Core\Plugin
 * @covers \Sit_Cwm\Core\Activator
 * @covers \Sit_Cwm\Core\Deactivator
 */
final class PluginTest extends WP_UnitTestCase {

	/**
	 * The main plugin file boots the plugin on `plugins_loaded`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_main_file_hooks_boot() {
		$this->assertSame( 10, has_action( 'plugins_loaded', 'sit_cwm_boot' ) );
	}

	/**
	 * The text domain, the main file name and the release zip's top-level
	 * directory all equal the WordPress.org slug. Plugin Check fails a text
	 * domain that differs from the slug, so these must not drift apart.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_text_domain_matches_release_slug() {
		$root = dirname( SIT_CWM_PLUGIN_FILE );

		// Read, not included: including the script would build a zip.
		$build_zip = (string) file_get_contents( $root . '/bin/build-zip.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$this->assertSame( 1, preg_match( "/define\(\s*'SIT_CWM_SLUG',\s*'([^']+)'/", $build_zip, $matches ) );
		$slug = $matches[1];

		$headers = get_file_data( SIT_CWM_PLUGIN_FILE, array( 'text_domain' => 'Text Domain' ) );

		$this->assertSame( $slug, $headers['text_domain'] );
		$this->assertSame( $slug . '.php', basename( SIT_CWM_PLUGIN_FILE ) );
	}

	/**
	 * `boot()` attaches the plugin-level hooks at their priorities.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_boot_registers_expected_hooks() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertFalse( method_exists( $plugin, 'load_textdomain' ) );
		$this->assertSame( 20, has_action( 'plugins_loaded', array( $plugin, 'maybe_upgrade_database' ) ) );
		$this->assertSame( 5, has_action( 'rest_api_init', array( $plugin, 'register_rest_services' ) ) );
	}

	/**
	 * Core services register at boot, exactly once; REST services wait for
	 * `rest_api_init`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_bootable_services_register_in_their_phase() {
		$rest = $this->make_bootable();

		// Every id now has a real factory that boot() registers, so the core
		// phase is observed through a real bootable service's hook, and the
		// REST stub replaces a real factory after the first boot().
		$container = new Container();
		$plugin    = new Plugin( $container );
		$plugin->boot();

		$container->set(
			'rest.users',
			static function () use ( $rest ) {
				return $rest;
			}
		);

		$plugin->boot();

		$this->assertSame( 10, has_action( 'before_delete_post', array( $container->get( 'activity_logger' ), 'handle_before_delete_post' ) ) );
		$this->assertSame( 0, $rest->calls );

		$plugin->register_rest_services();

		$this->assertSame( 1, $rest->calls );
		$this->assertSame( $container, $plugin->container() );
	}

	/**
	 * Activation seeds defaults once and never overwrites saved settings,
	 * across activate → deactivate → reactivate.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_activation_lifecycle_is_idempotent() {
		delete_option( 'sit_cwm_settings' );

		Activator::activate();
		$this->assertSame( Activator::default_settings(), get_option( 'sit_cwm_settings' ) );

		$custom = array(
			'post_types'               => array( 'post' ),
			'delete_data_on_uninstall' => true,
		);
		update_option( 'sit_cwm_settings', $custom );

		Deactivator::deactivate();
		$this->assertSame( $custom, get_option( 'sit_cwm_settings' ) );

		Activator::activate();
		$this->assertSame( $custom, get_option( 'sit_cwm_settings' ) );
	}

	/**
	 * Builds a bootable stub that counts `register()` calls.
	 *
	 * @since 1.0.0
	 *
	 * @return Bootable&object{calls: int}
	 */
	private function make_bootable() {
		return new class() implements Bootable {

			/**
			 * Number of `register()` calls.
			 *
			 * @var int
			 */
			public $calls = 0;

			/**
			 * Counts the call.
			 *
			 * @return void
			 */
			public function register(): void {
				++$this->calls;
			}
		};
	}
}
