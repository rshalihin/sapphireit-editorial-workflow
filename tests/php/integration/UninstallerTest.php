<?php
/**
 * Integration tests for the uninstall cleanup.
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Admin\Promotions;
use Sit_Cwm\Core\Database;
use Sit_Cwm\Core\Uninstaller;
use Sit_Cwm\Workflow\Capabilities;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The cleanup that `uninstall.php` ran in 1.1, now hooked to Freemius's
 * `after_uninstall` action (F18). Behaviour is unchanged.
 *
 * The WP test case rewrites CREATE/DROP TABLE into temporary-table statements,
 * so the real table is dropped before the class and restored after it, the
 * same way `DatabaseTest` does.
 *
 * @since 1.2.0
 *
 * @covers \Sit_Cwm\Core\Uninstaller
 */
final class UninstallerTest extends WP_UnitTestCase {

	/**
	 * Activity table service.
	 *
	 * @var Database
	 */
	private $database;

	/**
	 * Drops the real table the plugin created while the suite bootstrapped, so
	 * it cannot shadow the per-test temporary tables.
	 *
	 * @return void
	 */
	public static function set_up_before_class() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . ( new Database() )->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Test fixture; name from table_name().

		parent::set_up_before_class();
	}

	/**
	 * Restores the real table, so later test classes can write activity rows.
	 *
	 * @return void
	 */
	public static function tear_down_after_class() {
		global $wpdb;

		parent::tear_down_after_class();

		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . ( new Database() )->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Test fixture; name from table_name().

		( new Database() )->install();
	}

	/**
	 * Starts every test with the table in place.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->database = new Database();
		$this->database->install();
	}

	/**
	 * Restores the capabilities a cleanup removed from the in-memory roles.
	 *
	 * @return void
	 */
	public function tear_down() {
		Capabilities::add_caps();

		parent::tear_down();
	}

	/**
	 * Without the opt-in, nothing is removed.
	 *
	 * @return void
	 */
	public function test_keeps_everything_when_deletion_not_opted_in() {
		update_option( 'sit_cwm_settings', array( 'delete_data_on_uninstall' => false ) );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_sit_cwm_status', 'review' );

		Uninstaller::run();

		$this->assertSame( 'review', get_post_meta( $post_id, '_sit_cwm_status', true ) );
		$this->assertTrue( $this->table_exists() );
		$this->assertNotFalse( get_option( 'sit_cwm_settings' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_WORKFLOWS ) );
	}

	/**
	 * With the opt-in, the table, meta, capabilities and options all go.
	 *
	 * @return void
	 */
	public function test_removes_table_meta_caps_and_options_when_opted_in() {
		update_option( 'sit_cwm_settings', array( 'delete_data_on_uninstall' => true ) );
		update_option( Capabilities::REVISION_OPTION, 1 );
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_sit_cwm_status', 'review' );
		update_post_meta( $post_id, '_sit_cwm_reviewer_id', 1 );
		update_post_meta( $post_id, '_other_plugin_key', 'kept' );

		Uninstaller::run();

		$this->assertSame( '', get_post_meta( $post_id, '_sit_cwm_status', true ) );
		$this->assertSame( '', get_post_meta( $post_id, '_sit_cwm_reviewer_id', true ) );
		$this->assertSame( 'kept', get_post_meta( $post_id, '_other_plugin_key', true ) );
		$this->assertFalse( $this->table_exists() );
		$this->assertFalse( get_option( Database::VERSION_OPTION ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_WORKFLOWS ) );
		$this->assertFalse( get_option( 'sit_cwm_settings' ) );
		$this->assertFalse( get_option( Capabilities::REVISION_OPTION ) );
	}

	/**
	 * With the opt-in, every user's dismissed promotion hints go too; other
	 * user meta stays. Without it, they stay.
	 *
	 * @return void
	 */
	public function test_dismissed_hints_follow_the_opt_in() {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, Promotions::DISMISSED_META, array( 'export' ) );
		update_user_meta( $user_id, 'other_plugin_key', 'kept' );

		update_option( 'sit_cwm_settings', array( 'delete_data_on_uninstall' => false ) );
		Uninstaller::run();
		$this->assertSame( array( 'export' ), get_user_meta( $user_id, Promotions::DISMISSED_META, true ) );

		update_option( 'sit_cwm_settings', array( 'delete_data_on_uninstall' => true ) );
		Uninstaller::run();
		$this->assertSame( '', get_user_meta( $user_id, Promotions::DISMISSED_META, true ) );
		$this->assertSame( 'kept', get_user_meta( $user_id, 'other_plugin_key', true ) );
	}

	/**
	 * A non-array settings value is treated as "not opted in".
	 *
	 * @return void
	 */
	public function test_corrupt_settings_keep_everything() {
		update_option( 'sit_cwm_settings', 'delete_data_on_uninstall' );

		Uninstaller::run();

		$this->assertTrue( $this->table_exists() );
	}

	/**
	 * On a network, each site that opted in is cleaned.
	 *
	 * @group multisite
	 *
	 * @return void
	 */
	public function test_runs_on_every_site_of_a_network() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}

		$site_ids = array( get_current_blog_id(), self::factory()->blog->create() );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			update_option( 'sit_cwm_settings', array( 'delete_data_on_uninstall' => true ) );
			restore_current_blog();
		}

		Uninstaller::run();

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			$this->assertFalse( get_option( 'sit_cwm_settings' ), "Site {$site_id}" );
			restore_current_blog();
		}
	}

	/**
	 * Freemius: an uninstall.php stops the SDK's uninstall event from firing.
	 *
	 * @return void
	 */
	public function test_uninstall_php_is_gone() {
		$this->assertFileDoesNotExist( SIT_CWM_PLUGIN_DIR . 'uninstall.php' );
	}

	/**
	 * Whether the activity table exists. `DESCRIBE` also sees the temporary
	 * table the test case creates in place of the real one.
	 *
	 * @return bool
	 */
	private function table_exists(): bool {
		global $wpdb;

		$suppress = $wpdb->suppress_errors( true );
		$columns  = $wpdb->get_results( 'DESCRIBE ' . $this->database->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Test helper; name from table_name().
		$wpdb->suppress_errors( $suppress );

		return ! empty( $columns );
	}
}
