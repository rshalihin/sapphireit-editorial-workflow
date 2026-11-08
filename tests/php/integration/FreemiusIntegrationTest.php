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
	 * Free is WordPress.org compliant and sells only add-ons: no paid plans of
	 * its own (phase 49; phase 48, finding 1).
	 *
	 * @return void
	 */
	public function test_free_is_org_compliant_and_sells_only_addons() {
		$this->assertTrue( sit_cwm_fs()->is_org_repo_compliant() );
		$this->assertFalse( sit_cwm_fs()->is_premium() );
		$this->assertFalse( sit_cwm_fs()->has_paid_plan() );
		$this->assertTrue( sit_cwm_fs()->has_addons() );
	}

	/**
	 * The Add-Ons page is on; the pricing page and its "Upgrade" item are not,
	 * so Free adds no `upgrade-mode` menu item and no "Activate License" link.
	 *
	 * @return void
	 */
	public function test_addons_menu_on_and_no_pricing_or_upgrade_item() {
		$this->assertTrue( sit_cwm_fs()->is_submenu_item_visible( 'addons' ) );
		$this->assertFalse( sit_cwm_fs()->is_submenu_item_visible( 'pricing' ) );
		$this->assertFalse( sit_cwm_fs()->is_pricing_page_visible() );
	}

	/**
	 * Guideline 8: Free never installs Pro. The SDK's Add-Ons page offers
	 * "Install Now" only to a premium or non-org-compliant parent, or for an
	 * org-compliant add-on; Free is neither and Pro is not. The real purchase
	 * check is `tests/e2e/promotions.spec.js` on staging.
	 *
	 * @return void
	 */
	public function test_free_is_not_allowed_to_install_addons() {
		$this->assertFalse( sit_cwm_fs()->is_allowed_to_install() );
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
	 * The SDK's opt-in replaces the page its `menu.slug` names, for anyone
	 * who can open it. It names Settings, which only managers can open, so
	 * the Dashboard every workflow user works in is never replaced (F18).
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_opt_in_lives_on_the_manager_only_settings_page() {
		$this->assertSame( \Sit_Cwm\Admin\Settings::MENU_SLUG, sit_cwm_fs()->get_menu_slug() );
		$this->assertNotSame( \Sit_Cwm\Admin\Dashboard::MENU_SLUG, sit_cwm_fs()->get_menu_slug() );
	}

	/**
	 * Nothing is sold in 1.2.0 (R10): the SDK's hidden pricing page is
	 * replaced by a link to the Dashboard, so its URL redirects there
	 * instead of loading the pricing app.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_pricing_url_points_to_the_dashboard() {
		$this->assertSame(
			admin_url( 'admin.php?page=' . \Sit_Cwm\Admin\Dashboard::MENU_SLUG ),
			sit_cwm_fs()->apply_filters( 'pricing_url', null )
		);
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
