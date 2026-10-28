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
use Sit_Cwm\Editor\ClassicMetaBox;
use Sit_Cwm\Notifications\Deduplicator;
use Sit_Cwm\Notifications\MessageBuilder;
use Sit_Cwm\Notifications\Notifier;
use Sit_Cwm\Notifications\RecipientResolver;
use Sit_Cwm\Publishing\GateLedger;
use Sit_Cwm\Publishing\GateRecorder;
use Sit_Cwm\Publishing\Layers\RestLayer;
use Sit_Cwm\Publishing\Layers\SaveLayer;
use Sit_Cwm\Publishing\Layers\ScheduleLayer;
use Sit_Cwm\Publishing\PublicationLogger;
use Sit_Cwm\Publishing\PublishGate;
use Sit_Cwm\Publishing\RefusalNotices;
use Sit_Cwm\Workflow\Capabilities;
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
		$this->assertSame( 20, has_action( 'plugins_loaded', array( Capabilities::class, 'maybe_upgrade' ) ) );
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
	 * The classic editor meta box is wired and boots with the admin services,
	 * right after the block editor sidebar.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function test_classic_meta_box_is_wired_in_admin_phase() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( ClassicMetaBox::class, $plugin->container()->get( 'editor.classic' ) );

		$admin = Plugin::BOOT_PHASES['admin'];

		$this->assertContains( 'editor.classic', $admin );
		$this->assertSame( array_search( 'editor.sidebar', $admin, true ) + 1, array_search( 'editor.classic', $admin, true ) );
	}

	/**
	 * The publication logger is wired, boots with the core services right
	 * after the workflow manager, and hooks `transition_post_status` at 10.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_publication_logger_is_wired_in_core_phase() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$logger = $plugin->container()->get( 'publication_logger' );

		$this->assertInstanceOf( PublicationLogger::class, $logger );
		$this->assertSame( 10, has_action( 'transition_post_status', array( $logger, 'handle_transition' ) ) );

		$core = Plugin::BOOT_PHASES['core'];

		$this->assertContains( 'publication_logger', $core );
		$this->assertGreaterThan( array_search( 'workflow_manager', $core, true ), array_search( 'publication_logger', $core, true ) );
	}

	/**
	 * The publish gate is wired, and the publication logger gets it.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_publish_gate_is_wired() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( PublishGate::class, $plugin->container()->get( 'publish_gate' ) );
	}

	/**
	 * The ledger, recorder, notices and save layer are wired, boot with the
	 * core services in the 01 order, and hook in at the E6 priorities.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_save_layer_chain_is_wired_in_core_phase() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$container = $plugin->container();
		$this->assertInstanceOf( GateLedger::class, $container->get( 'publish_gate.ledger' ) );
		$this->assertInstanceOf( RefusalNotices::class, $container->get( 'publish_gate.notices' ) );
		$this->assertInstanceOf( GateRecorder::class, $container->get( 'publish_gate.recorder' ) );
		$this->assertInstanceOf( SaveLayer::class, $container->get( 'publish_gate.layer.save' ) );

		$layer = $container->get( 'publish_gate.layer.save' );
		$this->assertSame( 10, has_filter( 'wp_insert_post_data', array( $layer, 'filter_insert_post_data' ) ) );
		$this->assertSame( PHP_INT_MAX, has_action( 'wp_insert_post', array( $layer, 'end_insert' ) ) );
		$this->assertSame( 5, has_action( 'transition_post_status', array( $container->get( 'publish_gate.recorder' ), 'release_pending' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( $container->get( 'publish_gate.notices' ), 'render' ) ) );

		$core     = Plugin::BOOT_PHASES['core'];
		$expected = array( 'workflow_manager', 'publish_gate.recorder', 'publish_gate.notices', 'publication_logger', 'publish_gate.layer.save', 'publish_gate.layer.rest' );
		$this->assertSame( $expected, array_values( array_intersect( $core, $expected ) ) );
	}

	/**
	 * The REST layer is wired in the core phase, after the save layer, and
	 * hooks the meta capability and the per-type REST filters (E3).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_rest_layer_is_wired_in_core_phase() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$layer = $plugin->container()->get( 'publish_gate.layer.rest' );
		$this->assertInstanceOf( RestLayer::class, $layer );
		$this->assertSame( 10, has_filter( 'map_meta_cap', array( $layer, 'filter_meta_cap' ) ) );
		$this->assertSame( 20, has_action( 'rest_api_init', array( $layer, 'register_post_type_filters' ) ) );
	}

	/**
	 * The schedule layer is wired in the core phase, after the REST layer, and
	 * hooks the scheduled-publish cron and the backstop at the E6 priorities.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_schedule_layer_is_wired_in_core_phase() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$layer = $plugin->container()->get( 'publish_gate.layer.schedule' );
		$this->assertInstanceOf( ScheduleLayer::class, $layer );
		$this->assertSame( 5, has_action( 'publish_future_post', array( $layer, 'guard_scheduled' ) ) );
		$this->assertSame( 20, has_action( 'transition_post_status', array( $layer, 'backstop' ) ) );
		$this->assertSame( 30, has_action( 'transition_post_status', array( $layer, 'forget_schedule_bypass' ) ) );

		$core     = Plugin::BOOT_PHASES['core'];
		$expected = array( 'publish_gate.layer.rest', 'publish_gate.layer.schedule' );
		$this->assertSame( $expected, array_values( array_intersect( $core, $expected ) ) );
	}

	/**
	 * The recipient resolver is a factory only: wired, but never booted.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_recipient_resolver_is_wired_but_not_booted() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( RecipientResolver::class, $plugin->container()->get( 'notifications.recipients' ) );
		$this->assertNotContains( 'notifications.recipients', array_merge( ...array_values( Plugin::BOOT_PHASES ) ) );
	}

	/**
	 * The notifier and its helpers are wired; the notifier boots last in the
	 * core phase and hooks the three 1.0 workflow actions at 20.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_notifier_is_wired_last_in_core_phase() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$container = $plugin->container();
		$this->assertInstanceOf( MessageBuilder::class, $container->get( 'notifications.messages' ) );
		$this->assertInstanceOf( Deduplicator::class, $container->get( 'notifications.dedup' ) );

		$notifier = $container->get( 'notifications' );
		$this->assertInstanceOf( Notifier::class, $notifier );
		$this->assertSame( 20, has_action( 'sit_cwm_status_changed', array( $notifier, 'on_status_changed' ) ) );
		$this->assertSame( 20, has_action( 'sit_cwm_reviewer_assigned', array( $notifier, 'on_reviewer_assigned' ) ) );
		$this->assertSame( 20, has_action( 'sit_cwm_comment_added', array( $notifier, 'on_comment_added' ) ) );

		$core = Plugin::BOOT_PHASES['core'];
		$this->assertSame( 'notifications', end( $core ) );
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
