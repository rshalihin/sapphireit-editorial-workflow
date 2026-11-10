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
	 * No SDK menu item sells anything: the Add-Ons page, the pricing page and
	 * its "Upgrade" item are all hidden, so Free adds no `upgrade-mode` menu
	 * item and no "Activate License" link.
	 *
	 * @return void
	 */
	public function test_no_addons_pricing_or_upgrade_item() {
		$this->assertFalse( sit_cwm_fs()->is_submenu_item_visible( 'addons' ) );
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
	 * Guideline 8: the SDK's in-dashboard checkout, where it offers to
	 * install the add-on after payment, never runs. A checkout request to
	 * the pricing page gets the Dashboard link too, so the SDK registers no
	 * page to run it on.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_a_checkout_request_gets_no_pricing_page() {
		$_GET['page']         = sit_cwm_fs()->get_menu_slug() . '-pricing';
		$_REQUEST['checkout'] = 'true';

		$this->assertSame(
			admin_url( 'admin.php?page=' . \Sit_Cwm\Admin\Dashboard::MENU_SLUG ),
			sit_cwm_fs()->apply_filters( 'pricing_url', null )
		);
	}

	/**
	 * The SDK's Add-Ons page, whose cards lead to that checkout, is sent to
	 * the Dashboard before it loads. Other pages are left alone.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_the_addons_page_is_sent_to_the_dashboard() {
		$this->assertSame( 10, has_action( 'admin_init', 'sit_cwm_fs_skip_addons_page' ) );

		$_GET['page'] = sit_cwm_fs()->get_menu_slug() . '-addons';
		$this->assertSame( admin_url( 'admin.php?page=' . \Sit_Cwm\Admin\Dashboard::MENU_SLUG ), sit_cwm_fs_addons_page_redirect() );

		foreach ( array( \Sit_Cwm\Admin\Dashboard::MENU_SLUG, sit_cwm_fs()->get_menu_slug(), sit_cwm_fs()->get_menu_slug() . '-account' ) as $page ) {
			$_GET['page'] = $page;
			$this->assertNull( sit_cwm_fs_addons_page_redirect(), $page );
		}
	}

	/**
	 * The purchase email's "Auto Install" button reaches Free's SDK pages
	 * with `auto_install=true`. The flag is dropped there, before the SDK
	 * reads it, while the rest of the activation request is kept.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_auto_install_is_dropped_from_free_sdk_requests() {
		set_current_screen( 'dashboard' );
		$this->assertSame( 0, has_action( 'init', 'sit_cwm_fs_drop_auto_install' ) );

		$requests = array(
			array( 'page' => sit_cwm_fs()->get_menu_slug() . '-account' ),
			array( 'page' => sit_cwm_fs()->get_menu_slug() ),
			array( 'fs_action' => sit_cwm_fs()->get_unique_affix() . '_activate_new' ),
		);

		foreach ( $requests as $request ) {
			$this->request( $request + array( 'auto_install' => 'true' ) );
			sit_cwm_fs_drop_auto_install();

			$this->assertFalse( isset( $_GET['auto_install'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reads back the request this test built.
			$this->assertFalse( fs_request_has( 'auto_install' ) );
			$this->assertFalse( fs_request_get_bool( 'auto_install' ) );
			$this->assertSame( reset( $request ), fs_request_get( key( $request ) ) );
		}
	}

	/**
	 * Other plugins' requests keep the flag: they may install their own
	 * premium code.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_auto_install_is_kept_for_other_requests() {
		set_current_screen( 'dashboard' );

		foreach ( array( array( 'page' => 'another-plugin-account' ), array( 'fs_action' => 'another-plugin_activate_new' ), array() ) as $request ) {
			$this->request( $request + array( 'auto_install' => 'true' ) );
			sit_cwm_fs_drop_auto_install();

			$this->assertTrue( fs_request_get_bool( 'auto_install' ) );
		}
	}

	/**
	 * Every SDK route that installs an add-on ends in its "install premium
	 * version" AJAX call. Free answers it first, with a refusal.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_the_premium_install_ajax_call_is_refused_first() {
		$hook = 'wp_ajax_' . sit_cwm_fs()->get_ajax_action( 'install_premium_version' );

		$this->assertSame( 1, has_action( $hook, 'sit_cwm_fs_refuse_premium_install' ) );

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
		ob_start();

		try {
			sit_cwm_fs_refuse_premium_install();
			$this->fail( 'The refusal did not end the request.' );
		} catch ( \WPDieException $e ) {
			$response = json_decode( (string) ob_get_contents(), true );
			$this->assertFalse( $response['success'] );
			$this->assertSame( 'sit_cwm_install_not_offered', $response['data']['code'] );
		} finally {
			ob_end_clean();
			remove_filter( 'wp_doing_ajax', '__return_true' );
			remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
		}
	}

	/**
	 * The Account page's "Download Latest" for an add-on opens the Freemius
	 * customer portal, so Free never fetches Pro's zip through the site.
	 * Free's own link is left alone.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_addon_download_links_go_to_the_customer_portal() {
		$this->assertSame( 'https://users.freemius.com/', sit_cwm_fs()->_get_latest_download_local_url( 43920 ) );

		$own = sit_cwm_fs()->_get_latest_download_local_url();
		$this->assertStringContainsString( 'download_latest', $own );
		$this->assertStringNotContainsString( 'users.freemius.com', $own );
	}

	/**
	 * The SDK's "Add-on was successfully purchased. Download the latest
	 * version" notice is hidden; other notices are not.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_addon_purchase_notice_is_hidden() {
		$tag = sit_cwm_fs()->get_action_tag( 'show_admin_notice' );

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The SDK's own hook for this plugin.
		$this->assertFalse( apply_filters( $tag, true, array( 'id' => 'addon_plan_upgraded_sapphireit-editorial-workflow-pro' ) ) );
		$this->assertTrue( apply_filters( $tag, true, array( 'id' => 'activation_complete' ) ) );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
	}

	/**
	 * The SDK's Account page shows no Account / Add-Ons tabs.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function test_account_page_has_no_addons_tab() {
		$this->assertTrue( sit_cwm_fs()->apply_filters( 'hide_account_tabs', false ) );
	}

	/**
	 * Turns `wp_die()` in an AJAX response into an exception.
	 *
	 * @return callable
	 */
	public function ajax_die_handler() {
		return static function () {
			throw new \WPDieException( 'ajax' );
		};
	}

	/**
	 * Replaces the request with the given parameters.
	 *
	 * @param array<string, string> $params Request parameters.
	 * @return void
	 */
	private function request( array $params ) {
		unset( $_GET['page'], $_GET['fs_action'], $_GET['auto_install'], $_REQUEST['page'], $_REQUEST['fs_action'], $_REQUEST['auto_install'] );

		foreach ( $params as $key => $value ) {
			$_GET[ $key ]     = $value;
			$_REQUEST[ $key ] = $value;
		}
	}

	/**
	 * Clears what a test put in the request.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_GET['page'], $_GET['fs_action'], $_GET['auto_install'], $_REQUEST['page'], $_REQUEST['fs_action'], $_REQUEST['auto_install'], $_REQUEST['checkout'] );
		set_current_screen( 'front' );

		parent::tear_down();
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
