<?php
/**
 * Integration tests for the settings accessor.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Core\Settings;
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
}
