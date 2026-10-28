<?php
/**
 * Integration tests for the capability revision upgrade.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration;

use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Workflow\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bypass capability grant, and how sites updated from 1.0.0 without
 * reactivation receive it (E9).
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Workflow\Capabilities
 */
final class CapabilitiesUpgradeTest extends TestCase {

	/**
	 * Only administrators hold the bypass capability after activation.
	 *
	 * @return void
	 */
	public function test_administrator_holds_bypass_after_activation() {
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::BYPASS_PUBLISH_GATE ) );

		foreach ( array( 'editor', 'author', 'contributor', 'subscriber' ) as $role ) {
			$this->assertFalse( get_role( $role )->has_cap( Capabilities::BYPASS_PUBLISH_GATE ), $role );
		}
	}

	/**
	 * A 1.0.0 site (no revision option) gains the bypass capability, and
	 * nothing a site owner removed comes back.
	 *
	 * @return void
	 */
	public function test_upgrade_from_revision_1_grants_only_the_bypass_cap() {
		get_role( 'administrator' )->remove_cap( Capabilities::BYPASS_PUBLISH_GATE );
		get_role( 'editor' )->remove_cap( Capabilities::APPROVE_CONTENT ); // A site owner's deliberate change.
		delete_option( Capabilities::REVISION_OPTION );                    // 1.0.0 never wrote it.

		Capabilities::maybe_upgrade();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::BYPASS_PUBLISH_GATE ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::APPROVE_CONTENT ), 'Upgrade must not restore removed caps.' );
		$this->assertSame( 2, (int) get_option( Capabilities::REVISION_OPTION ) );
	}

	/**
	 * At the current revision the upgrade writes nothing, so a removed bypass
	 * capability stays removed.
	 *
	 * @return void
	 */
	public function test_upgrade_is_a_no_op_at_current_revision() {
		update_option( Capabilities::REVISION_OPTION, Capabilities::REVISION, false );
		get_role( 'administrator' )->remove_cap( Capabilities::BYPASS_PUBLISH_GATE );

		Capabilities::maybe_upgrade();

		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::BYPASS_PUBLISH_GATE ) );
	}

	/**
	 * A missing administrator role is skipped and the revision still recorded.
	 *
	 * @return void
	 */
	public function test_upgrade_skips_a_missing_administrator_role_without_error() {
		delete_option( Capabilities::REVISION_OPTION );
		$saved = get_role( 'administrator' )->capabilities;
		remove_role( 'administrator' );

		Capabilities::maybe_upgrade();

		$this->assertSame( 2, (int) get_option( Capabilities::REVISION_OPTION ) );
		add_role( 'administrator', 'Administrator', $saved );
	}

	/**
	 * The revision option is read on every request, so it must not autoload.
	 *
	 * @return void
	 */
	public function test_revision_option_is_not_autoloaded() {
		delete_option( Capabilities::REVISION_OPTION );

		Capabilities::maybe_upgrade();

		$this->assertSame( 'off', $this->autoload_flag( Capabilities::REVISION_OPTION ) );
	}

	/**
	 * Reads an option's stored autoload flag, normalising the pre-6.6 `no` to
	 * `off`.
	 *
	 * @param string $option Option name.
	 * @return string|null Normalised flag; null when the option is missing.
	 */
	private function autoload_flag( string $option ): ?string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test inspects the raw autoload column; no API exposes it.
		$flag = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );

		if ( null === $flag ) {
			return null;
		}

		return 'no' === $flag ? 'off' : (string) $flag;
	}
}
