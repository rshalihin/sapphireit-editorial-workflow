<?php
/**
 * Custom table schema management.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, upgrades and drops the activity table (D8).
 *
 * The table name is built only by `table_name()`; every consumer calls it.
 *
 * @since 1.0.0
 */
final class Database {

	/**
	 * Option holding the installed schema version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const VERSION_OPTION = 'sit_cwm_db_version';

	/**
	 * Target schema version. `null` means `SIT_CWM_DB_VERSION`.
	 *
	 * @since 1.0.0
	 * @var string|null
	 */
	private $version;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $version Target schema version. Defaults to
	 *                             `SIT_CWM_DB_VERSION`; override in tests only.
	 */
	public function __construct( ?string $version = null ) {
		$this->version = $version;
	}

	/**
	 * Full name of the activity table, including the site's table prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'sit_cwm_activity';
	}

	/**
	 * Target schema version this code expects.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function version(): string {
		return null !== $this->version ? $this->version : SIT_CWM_DB_VERSION;
	}

	/**
	 * Creates or updates the activity table via `dbDelta()` and records the
	 * schema version. Idempotent.
	 *
	 * The SQL below follows dbDelta's formatting rules: one field per line,
	 * lowercase types, `KEY` not `INDEX`, two spaces after `PRIMARY KEY`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $this->table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
post_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
action varchar(50) NOT NULL,
old_value varchar(191) NULL,
new_value varchar(191) NULL,
message text NULL,
context longtext NULL,
created_at datetime NOT NULL,
PRIMARY KEY  (id),
KEY post_created (post_id,created_at),
KEY user_id (user_id),
KEY action (action)
) {$charset_collate};";

		dbDelta( $sql );

		update_option( self::VERSION_OPTION, $this->version(), false );
	}

	/**
	 * Runs `install()` when the stored schema version differs from the code's.
	 *
	 * Hooked on `plugins_loaded`, so updating plugin files without
	 * reactivation still upgrades the schema.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		$installed = (string) get_option( self::VERSION_OPTION, '' );
		$target    = $this->version();

		if ( $installed === $target ) {
			return;
		}

		$this->install();

		/**
		 * Fires after the activity table schema was installed or upgraded.
		 *
		 * @since 1.0.0
		 *
		 * @param string $installed Previously stored version, `''` if none.
		 * @param string $target    Version now installed.
		 */
		do_action( 'sit_cwm_db_upgraded', $installed, $target );
	}

	/**
	 * Drops the activity table and forgets the schema version.
	 *
	 * For `uninstall.php` and tests only.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function drop(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table_name() ) );

		delete_option( self::VERSION_OPTION );
	}
}
