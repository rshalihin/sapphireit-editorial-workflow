<?php
/**
 * Integration tests for the activity table schema.
 *
 * @package Sit_Cwm\Tests
 * @since   1.0.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Core\Activator;
use Sit_Cwm\Core\Database;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table creation, idempotence, upgrades and activation.
 *
 * The WP test case rewrites CREATE/DROP TABLE into temporary-table statements
 * inside each test. `SHOW TABLES` cannot see temporary tables, so existence is
 * checked with `DESCRIBE`.
 *
 * @since 1.0.0
 *
 * @covers \Sit_Cwm\Core\Database
 * @covers \Sit_Cwm\Core\Activator
 */
final class DatabaseTest extends WP_UnitTestCase {

	/**
	 * Service under test.
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

		// Runs before the test case installs its temporary-table query filters.
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

		/*
		 * Runs after the test case removed its temporary-table query filters.
		 * The last test may still have left a temporary table of that name
		 * alive on this connection; dbDelta would find it and create nothing,
		 * and the next class's reconnect would then drop it, leaving every
		 * later test without an activity table.
		 */
		$wpdb->query( 'DROP TEMPORARY TABLE IF EXISTS ' . ( new Database() )->table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Test fixture; name from table_name().

		( new Database() )->install();
	}

	/**
	 * Starts every test without the table or version option.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->database = new Database();
		$this->database->drop();
	}

	/**
	 * Fresh activation creates exactly the D8 columns and indexes.
	 *
	 * @return void
	 */
	public function test_activation_creates_table_with_d8_schema() {
		$this->assertFalse( $this->table_exists() );

		Activator::activate();

		$this->assertTrue( $this->table_exists() );
		$this->assertSame( SIT_CWM_DB_VERSION, get_option( 'sit_cwm_db_version' ) );

		$create = $this->show_create_table();

		$expected_lines = array(
			'/^`id` bigint(\(20\))? unsigned NOT NULL AUTO_INCREMENT,$/',
			'/^`post_id` bigint(\(20\))? unsigned NOT NULL,$/',
			'/^`user_id` bigint(\(20\))? unsigned NOT NULL,$/',
			'/^`action` varchar\(50\)( COLLATE \w+)? NOT NULL,$/',
			'/^`old_value` varchar\(191\)( COLLATE \w+)? DEFAULT NULL,$/',
			'/^`new_value` varchar\(191\)( COLLATE \w+)? DEFAULT NULL,$/',
			'/^`message` text( COLLATE \w+)?,$/',
			'/^`context` longtext( COLLATE \w+)?,$/',
			'/^`created_at` datetime NOT NULL,$/',
			'/^PRIMARY KEY \(`id`\),$/',
			'/^KEY `post_created` \(`post_id`,`created_at`\),$/',
			'/^KEY `user_id` \(`user_id`\),$/',
			'/^KEY `action` \(`action`\)$/',
		);

		// Body lines between "CREATE ... (" and ") ENGINE=...".
		$lines = array_slice( array_map( 'trim', explode( "\n", $create ) ), 1, -1 );

		$this->assertCount( count( $expected_lines ), $lines, $create );

		foreach ( $expected_lines as $index => $pattern ) {
			$this->assertMatchesRegularExpression( $pattern, $lines[ $index ], $create );
		}
	}

	/**
	 * Running `install()` twice leaves the schema unchanged.
	 *
	 * @return void
	 */
	public function test_install_is_idempotent() {
		$this->database->install();
		$first = $this->show_create_table();

		$this->database->install();

		$this->assertSame( $first, $this->show_create_table() );
	}

	/**
	 * A version bump triggers exactly one upgrade run, then stops.
	 *
	 * @return void
	 */
	public function test_version_bump_upgrades_once() {
		$this->database->install();

		$runs = 0;
		add_action(
			'sit_cwm_db_upgraded',
			static function () use ( &$runs ) {
				++$runs;
			}
		);

		// Same version: nothing to do.
		$this->database->maybe_upgrade();
		$this->assertSame( 0, $runs );

		$bumped = new Database( '1.1.0' );
		$bumped->maybe_upgrade();
		$bumped->maybe_upgrade();

		$this->assertSame( 1, $runs );
		$this->assertSame( '1.1.0', get_option( 'sit_cwm_db_version' ) );
		$this->assertTrue( $this->table_exists() );
	}

	/**
	 * A missing version option (e.g. files copied in without activation)
	 * installs the table.
	 *
	 * @return void
	 */
	public function test_maybe_upgrade_installs_when_version_missing() {
		$this->database->maybe_upgrade();

		$this->assertTrue( $this->table_exists() );
		$this->assertSame( SIT_CWM_DB_VERSION, get_option( 'sit_cwm_db_version' ) );
	}

	/**
	 * `drop()` removes the table and the version option.
	 *
	 * @return void
	 */
	public function test_drop_removes_table_and_version() {
		$this->database->install();
		$this->database->drop();

		$this->assertFalse( $this->table_exists() );
		$this->assertFalse( get_option( 'sit_cwm_db_version' ) );
	}

	/**
	 * The table name uses the site prefix.
	 *
	 * @return void
	 */
	public function test_table_name_uses_prefix() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'sit_cwm_activity', $this->database->table_name() );
	}

	/**
	 * Whether the activity table (real or temporary) exists.
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

	/**
	 * `SHOW CREATE TABLE` output for the activity table.
	 *
	 * @return string
	 */
	private function show_create_table(): string {
		global $wpdb;

		$row = $wpdb->get_row( 'SHOW CREATE TABLE ' . $this->database->table_name(), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Test helper; name from table_name().

		$this->assertIsArray( $row );

		return (string) $row[1];
	}
}
