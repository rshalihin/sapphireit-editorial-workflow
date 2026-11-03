<?php
/**
 * Integration tests for the settings accessor.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Core\Settings;
use Sit_Cwm\Notifications\NotificationEvents;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings defaults, validation and enabled post types.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Core\Settings
 */
final class SettingsTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Starts every test with no stored settings.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION );
		$this->settings = new Settings();
	}

	/**
	 * Defaults match D7 and are returned when nothing is stored.
	 *
	 * @return void
	 */
	public function test_defaults() {
		$expected = array(
			'post_types'               => array( 'post', 'page' ),
			'delete_data_on_uninstall' => false,
			'publish_gate'             => array(),
			'notifications'            => array_fill_keys( NotificationEvents::all(), false ),
			'prevent_self_approval'    => false,
			'ai_origin_defaults'       => array(),
			'require_attestation'      => false,
			'disclosure_enabled'       => false,
		);

		$this->assertSame( $expected, $this->settings->defaults() );
		$this->assertSame( $expected, $this->settings->all() );
		$this->assertSame( array( 'post', 'page' ), $this->settings->get( 'post_types' ) );
		$this->assertSame( 'fallback', $this->settings->get( 'unknown', 'fallback' ) );
	}

	/**
	 * `seed()` never overwrites stored settings.
	 *
	 * @return void
	 */
	public function test_seed_does_not_overwrite() {
		$this->settings->seed();
		$this->assertSame( $this->settings->defaults(), get_option( Settings::OPTION ) );

		$this->assertTrue( $this->settings->update( array( 'post_types' => array( 'post' ) ) ) );
		$this->settings->seed();

		$this->assertSame( array( 'post' ), $this->settings->get( 'post_types' ) );
	}

	/**
	 * `update()` merges a partial payload and sanitizes booleans.
	 *
	 * @return void
	 */
	public function test_update_merges_and_sanitizes() {
		$this->assertTrue( $this->settings->update( array( 'delete_data_on_uninstall' => 'true' ) ) );

		$this->assertSame(
			array(
				'post_types'               => array( 'post', 'page' ),
				'delete_data_on_uninstall' => true,
				'publish_gate'             => array(),
				'notifications'            => array_fill_keys( NotificationEvents::all(), false ),
				'prevent_self_approval'    => false,
				'ai_origin_defaults'       => array(),
				'require_attestation'      => false,
				'disclosure_enabled'       => false,
			),
			get_option( Settings::OPTION )
		);

		$this->assertTrue( $this->settings->update( array( 'post_types' => array( 'page', 'page' ) ) ) );
		$this->assertSame( array( 'page' ), $this->settings->get( 'post_types' ) );
		$this->assertTrue( $this->settings->get( 'delete_data_on_uninstall' ) );

		// Saving identical values is still a success.
		$this->assertTrue( $this->settings->update( array( 'post_types' => array( 'page' ) ) ) );
	}

	/**
	 * A non-existent post type rejects the whole update.
	 *
	 * @return void
	 */
	public function test_update_rejects_unknown_post_type() {
		$this->assertFalse(
			$this->settings->update(
				array(
					'post_types'               => array( 'post', 'not_a_type' ),
					'delete_data_on_uninstall' => true,
				)
			)
		);

		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	/**
	 * A registered but non-public post type is rejected.
	 *
	 * @return void
	 */
	public function test_update_rejects_non_public_post_type() {
		$this->assertFalse( $this->settings->update( array( 'post_types' => array( 'wp_block' ) ) ) );
	}

	/**
	 * Non-array payloads and non-array post type values are rejected.
	 *
	 * @return void
	 */
	public function test_update_rejects_non_array_payload() {
		$this->assertFalse( $this->settings->update( 'post' ) );
		$this->assertFalse( $this->settings->update( null ) );
		$this->assertFalse( $this->settings->update( array( 'post_types' => 'post' ) ) );

		$this->assertFalse( get_option( Settings::OPTION ) );
	}

	/**
	 * Enabled post types are filterable.
	 *
	 * @return void
	 */
	public function test_enabled_post_types_filter() {
		$this->assertTrue( $this->settings->is_post_type_enabled( 'post' ) );
		$this->assertFalse( $this->settings->is_post_type_enabled( 'attachment' ) );

		add_filter(
			'sit_cwm_enabled_post_types',
			static function ( $types ) {
				$types[] = 'book';
				return $types;
			}
		);

		$this->assertSame( array( 'post', 'page', 'book' ), $this->settings->enabled_post_types() );
		$this->assertTrue( $this->settings->is_post_type_enabled( 'book' ) );
	}

	/**
	 * Fresh defaults leave every gate off and every email event off (E1, E12).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_defaults_include_gate_and_notification_keys_all_off() {
		$defaults = ( new Settings() )->defaults();
		$this->assertSame( array(), $defaults['publish_gate'] );
		$this->assertSame( array_fill_keys( NotificationEvents::all(), false ), $defaults['notifications'] );
	}

	/**
	 * An option written by 1.0.0 reads as every gate off and every event off.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_upgrade_from_1_0_option_reads_every_gate_off_and_every_event_off() {
		update_option(
			Settings::OPTION,
			array(
				'post_types'               => array( 'post', 'page' ),
				'delete_data_on_uninstall' => false,
			)
		);
		$settings = new Settings();
		$this->assertSame( array(), $settings->gate_modes() );
		foreach ( NotificationEvents::all() as $event ) {
			$this->assertFalse( $settings->is_notification_enabled( $event ) );
		}
	}

	/**
	 * Valid modes for selectable post types are stored.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_update_stores_valid_gate_modes() {
		$settings = new Settings();
		$this->assertTrue(
			$settings->update(
				array(
					'publish_gate' => array(
						'post' => 'enforce',
						'page' => 'log_only',
					),
				)
			)
		);
		$this->assertSame(
			array(
				'post' => 'enforce',
				'page' => 'log_only',
			),
			$settings->gate_modes()
		);
	}

	/**
	 * An unknown mode, an unselectable type or a scalar map rejects the update.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_update_rejects_unknown_mode_or_unselectable_type_and_saves_nothing() {
		$settings = new Settings();
		$this->assertFalse( $settings->update( array( 'publish_gate' => array( 'post' => 'block' ) ) ) );
		$this->assertFalse( $settings->update( array( 'publish_gate' => array( 'nonexistent_cpt' => 'enforce' ) ) ) );
		$this->assertFalse( $settings->update( array( 'publish_gate' => 'enforce' ) ) );
		$this->assertSame( array(), $settings->gate_modes() );
	}

	/**
	 * The submitted gate map replaces the stored one; omitted types read as off.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_update_replaces_the_whole_gate_map() {
		$settings = new Settings();
		$settings->update(
			array(
				'publish_gate' => array(
					'post' => 'enforce',
					'page' => 'log_only',
				),
			)
		);
		$this->assertTrue( $settings->update( array( 'publish_gate' => array( 'page' => 'enforce' ) ) ) );
		$this->assertSame( array( 'page' => 'enforce' ), $settings->gate_modes() ); // 'post' is gone, so it reads as off.
		$this->assertTrue( $settings->update( array( 'publish_gate' => array() ) ) );
		$this->assertSame( array(), $settings->gate_modes() );
	}

	/**
	 * Stored entries with an unknown mode or a non-string key are ignored.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_gate_modes_drops_tampered_stored_entries() {
		update_option(
			Settings::OPTION,
			array(
				'publish_gate' => array(
					'post' => 'enforce',
					'page' => 'nonsense',
					7      => 'enforce',
				),
			)
		);
		$this->assertSame( array( 'post' => 'enforce' ), ( new Settings() )->gate_modes() );
	}

	/**
	 * Known events can be toggled; an unknown event rejects the update.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_update_stores_notification_toggles_and_rejects_unknown_events() {
		$settings = new Settings();
		$this->assertTrue( $settings->update( array( 'notifications' => array( 'approved' => true ) ) ) );
		$this->assertTrue( $settings->is_notification_enabled( 'approved' ) );
		$this->assertFalse( $settings->is_notification_enabled( 'comment_added' ) );
		$this->assertFalse( $settings->update( array( 'notifications' => array( 'slack' => true ) ) ) );
		$this->assertFalse( $settings->is_notification_enabled( 'slack' ) );
	}

	/**
	 * Every event has a label, in `all()` order.
	 *
	 * @since 1.1.0
	 *
	 * @covers \Sit_Cwm\Notifications\NotificationEvents::labels
	 *
	 * @return void
	 */
	public function test_notification_labels_cover_every_event() {
		$this->assertSame( NotificationEvents::all(), array_keys( NotificationEvents::labels() ) );
	}

	/**
	 * Self-approval prevention is off on fresh installs and after upgrading
	 * from a 1.0.0 option that has no such key (E16).
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_prevent_self_approval_defaults_off_and_upgrades_off() {
		$this->assertFalse( ( new Settings() )->defaults()['prevent_self_approval'] );

		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) ); // 1.0.0 option.

		$this->assertFalse( ( new Settings() )->prevents_self_approval() );
	}

	/**
	 * `update()` stores the self-approval toggle as a boolean.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function test_update_stores_prevent_self_approval_as_bool() {
		$settings = new Settings();

		$this->assertTrue( $settings->update( array( 'prevent_self_approval' => '1' ) ) );
		$this->assertTrue( $settings->prevents_self_approval() );
		$this->assertTrue( $settings->get( 'prevent_self_approval' ) );

		$this->assertTrue( $settings->update( array( 'prevent_self_approval' => 'false' ) ) );
		$this->assertFalse( $settings->prevents_self_approval() );
	}

	/**
	 * Review attestation is off by default and after upgrading from 1.1, and
	 * truthy values sanitize to true (F14).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_require_attestation_defaults_off_and_sanitizes_to_bool() {
		$this->assertFalse( ( new Settings() )->defaults()['require_attestation'] );

		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) ); // 1.1 option.

		$settings = new Settings();
		$this->assertFalse( $settings->requires_attestation() );

		foreach ( array( 'yes', 1, true ) as $value ) {
			$this->assertTrue( $settings->update( array( 'require_attestation' => false ) ) );
			$this->assertTrue( $settings->update( array( 'require_attestation' => $value ) ) );
			$this->assertTrue( $settings->get( 'require_attestation' ) );
			$this->assertTrue( $settings->requires_attestation() );
		}

		$this->assertTrue( $settings->update( array( 'require_attestation' => 'false' ) ) );
		$this->assertFalse( $settings->requires_attestation() );
	}

	/**
	 * Front-end disclosure is off by default and after upgrading from 1.1,
	 * and `is_disclosure_enabled()` reads the stored boolean (F14).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_disclosure_enabled_defaults_off_and_sanitizes_to_bool() {
		$this->assertFalse( ( new Settings() )->defaults()['disclosure_enabled'] );

		update_option( Settings::OPTION, array( 'post_types' => array( 'post' ) ) ); // 1.1 option.

		$settings = new Settings();
		$this->assertFalse( $settings->is_disclosure_enabled() );

		foreach ( array( 'yes', 1, true ) as $value ) {
			$this->assertTrue( $settings->update( array( 'disclosure_enabled' => false ) ) );
			$this->assertTrue( $settings->update( array( 'disclosure_enabled' => $value ) ) );
			$this->assertTrue( $settings->get( 'disclosure_enabled' ) );
			$this->assertTrue( $settings->is_disclosure_enabled() );
		}

		$this->assertTrue( $settings->update( array( 'disclosure_enabled' => 'false' ) ) );
		$this->assertFalse( $settings->is_disclosure_enabled() );
	}

	/**
	 * AI-origin defaults keep only selectable types with a declarable value,
	 * and read as none for a type the workflow no longer covers (F14).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_ai_origin_defaults_sanitized_and_read() {
		$settings = new Settings();

		$this->assertSame( array(), $settings->all()['ai_origin_defaults'] );
		$this->assertSame( '', $settings->ai_origin_default( 'post' ) );

		$this->assertTrue(
			$settings->update(
				array(
					'ai_origin_defaults' => array(
						'post'       => 'human',
						'page'       => 'robot',
						'nope'       => 'human',
						'attachment' => 'ai_assisted',
						3            => 'human',
					),
				)
			)
		);
		$this->assertSame( array( 'post' => 'human' ), $settings->all()['ai_origin_defaults'] );
		$this->assertSame( 'human', $settings->ai_origin_default( 'post' ) );
		$this->assertSame( '', $settings->ai_origin_default( 'page' ) );

		$this->assertTrue( $settings->update( array( 'post_types' => array( 'page' ) ) ) );
		$this->assertSame( '', $settings->ai_origin_default( 'post' ), 'Type no longer enabled.' );

		$this->assertFalse( $settings->update( array( 'ai_origin_defaults' => 'human' ) ), 'A non-array is rejected.' );
		$this->assertSame( array( 'post' => 'human' ), $settings->all()['ai_origin_defaults'] );
	}

	/**
	 * A tampered stored map never yields an invalid default.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_ai_origin_default_ignores_tampered_stored_values() {
		update_option( Settings::OPTION, array( 'ai_origin_defaults' => array( 'post' => 'robot' ) ) );
		$this->assertSame( '', ( new Settings() )->ai_origin_default( 'post' ) );

		update_option( Settings::OPTION, array( 'ai_origin_defaults' => 'human' ) );
		$this->assertSame( '', ( new Settings() )->ai_origin_default( 'post' ) );
	}
}
