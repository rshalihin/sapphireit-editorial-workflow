<?php
/**
 * Integration tests for the Freemius SDK configuration (F18).
 *
 * @package Sit_Cwm\Tests
 * @since   1.2.0
 */

namespace Sit_Cwm\Tests\Integration;

use Freemius;
use Sit_Cwm\Core\Uninstaller;
use WP_UnitTestCase;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pins the F18 configuration through the SDK's own getters.
 *
 * @since 1.2.0
 *
 * @coversNothing
 */
final class FreemiusIntegrationTest extends WP_UnitTestCase {

	/**
	 * The SDK is initialised once, for this plugin's slug.
	 *
	 * @return void
	 */
	public function test_sdk_is_initialised_once_with_the_parent_slug() {
		$this->assertInstanceOf( Freemius::class, sit_cwm_fs() );
		$this->assertSame( sit_cwm_fs(), sit_cwm_fs() );
		$this->assertSame( 'sapphireit-editorial-workflow', sit_cwm_fs()->get_slug() );
		$this->assertSame( 1, did_action( 'sit_cwm_fs_loaded' ) );
	}

	/**
	 * Free is WordPress.org compliant and sells nothing in 1.2.0 (R10).
	 *
	 * @return void
	 */
	public function test_free_is_org_compliant_and_sells_nothing_yet() {
		$this->assertTrue( sit_cwm_fs()->is_org_repo_compliant() );
		$this->assertFalse( sit_cwm_fs()->is_premium() );
		$this->assertFalse( sit_cwm_fs()->has_paid_plan() );
		$this->assertFalse( sit_cwm_fs()->has_addons() );
	}

	/**
	 * Our cleanup runs on the SDK's uninstall event.
	 *
	 * @return void
	 */
	public function test_after_uninstall_runs_our_cleanup() {
		$this->assertNotFalse( has_action( sit_cwm_fs()->get_action_tag( 'after_uninstall' ), array( Uninstaller::class, 'run' ) ) );
	}

	/**
	 * Nothing older than the 2.13.5 security release ships.
	 *
	 * @return void
	 */
	public function test_sdk_version_is_at_least_the_security_release() {
		$this->assertTrue( version_compare( WP_FS__SDK_VERSION, '2.13.5', '>=' ) );
	}

	/**
	 * Free is updated by WordPress.org only: the SDK's updater is not
	 * started for a non-premium build, so nothing of Freemius hooks the
	 * plugin update transient.
	 *
	 * @return void
	 */
	public function test_free_updates_come_from_wordpress_org_only() {
		global $wp_filter;

		$this->assertFalse( sit_cwm_fs()->is_premium() );

		foreach ( array( 'pre_set_site_transient_update_plugins', 'site_transient_update_plugins' ) as $hook ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				continue;
			}

			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];

					$this->assertFalse(
						is_array( $function ) && is_object( $function[0] ) && $function[0] instanceof \FS_Plugin_Updater,
						"FS_Plugin_Updater is hooked to $hook"
					);
				}
			}
		}
	}
}
