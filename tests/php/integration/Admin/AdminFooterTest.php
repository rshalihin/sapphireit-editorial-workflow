<?php
/**
 * Integration tests for the plugin screens' admin footer.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Admin;

use Sit_Cwm\Admin\AdminFooter;
use Sit_Cwm\Admin\AdminShell;
use Sit_Cwm\Admin\Dashboard;
use Sit_Cwm\Admin\GettingStartedPage;
use Sit_Cwm\Admin\Settings as SettingsPage;
use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Tests\Traits\RestoresPluginInstance;
use Sit_Cwm\Workflow\PermissionManager;
use Sit_Cwm\Workflow\StatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * The plugin screens' footer asks for a review and shows the plugin's
 * version; every other admin screen keeps core's footer.
 *
 * @since 1.1.0
 * @since 1.2.0 Plugin screens are the shell's tabs, Getting Started included.
 *
 * @covers \Sit_Cwm\Admin\AdminFooter
 */
final class AdminFooterTest extends TestCase {

	use RestoresPluginInstance;

	/**
	 * Service under test.
	 *
	 * @var AdminFooter
	 */
	private $footer;

	/**
	 * Builds the service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->remember_plugin_instance();

		$settings    = new Settings();
		$permissions = new PermissionManager( $this->posts, $settings );

		$this->footer = new AdminFooter( new AdminShell( new Assets( new StatusManager(), $permissions, '', '', '' ) ) );
	}

	/**
	 * Restores `$plugin_page`.
	 *
	 * @return void
	 */
	public function tear_down() {
		$GLOBALS['plugin_page'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolates the requested page between tests.
		$this->restore_plugin_instance();

		parent::tear_down();
	}

	/**
	 * Both filters are hooked, the version one after core's `core_update_footer`.
	 *
	 * @return void
	 */
	public function test_register_adds_both_filters() {
		$this->footer->register();

		$this->assertSame( 10, has_filter( 'admin_footer_text', array( $this->footer, 'filter_footer_text' ) ) );
		$this->assertSame( AdminFooter::VERSION_PRIORITY, has_filter( 'update_footer', array( $this->footer, 'filter_version' ) ) );
		$this->assertGreaterThan( has_filter( 'update_footer', 'core_update_footer' ), AdminFooter::VERSION_PRIORITY );

		remove_filter( 'admin_footer_text', array( $this->footer, 'filter_footer_text' ) );
		remove_filter( 'update_footer', array( $this->footer, 'filter_version' ), AdminFooter::VERSION_PRIORITY );
	}

	/**
	 * On every plugin screen the footer names the plugin, links to its review
	 * form and shows the plugin's version.
	 *
	 * @return void
	 */
	public function test_plugin_screens_ask_for_a_review_and_show_the_version() {
		foreach ( array( Dashboard::MENU_SLUG, SettingsPage::MENU_SLUG, GettingStartedPage::MENU_SLUG ) as $page ) {
			$GLOBALS['plugin_page'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the requested page.

			$text = $this->footer->filter_footer_text( 'core text' );

			$this->assertStringContainsString( '<strong>SapphireIT Editorial Workflow</strong>', $text, $page );
			$this->assertStringContainsString( 'href="' . esc_url( AdminFooter::REVIEW_URL ) . '"', $text, $page );
			$this->assertStringContainsString( 'class="sit-cwm-rating-link"', $text, $page );
			$this->assertStringContainsString( 'rel="noopener noreferrer"', $text, $page );
			$this->assertStringContainsString( '>review<span class="screen-reader-text"> (opens in a new tab)</span></a>', $text, $page );
			$this->assertStringNotContainsString( 'core text', $text, $page );

			$this->assertSame( 'Version ' . SIT_CWM_VERSION, $this->footer->filter_version( 'Version 6.8' ), $page );
		}
	}

	/**
	 * The review link is neutral: no rating is preselected or asked for.
	 *
	 * @return void
	 */
	public function test_the_review_request_does_not_ask_for_a_rating() {
		$GLOBALS['plugin_page'] = Dashboard::MENU_SLUG; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the requested page.

		$text = $this->footer->filter_footer_text( 'core text' );

		$this->assertStringNotContainsString( 'rate=', AdminFooter::REVIEW_URL );
		$this->assertStringNotContainsString( '&#9733;', $text );
		$this->assertStringNotContainsString( 'five star', strtolower( $text ) );
	}

	/**
	 * Core screens, and pages others add under our menu (Freemius's Account
	 * page), keep whatever they were given.
	 *
	 * @return void
	 */
	public function test_other_screens_keep_the_core_footer() {
		foreach ( array( null, 'some-other-page', 'sit-cwm-dashboard-account' ) as $page ) {
			$GLOBALS['plugin_page'] = $page; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the requested page.

			$this->assertSame( 'core text', $this->footer->filter_footer_text( 'core text' ), (string) $page );
			$this->assertSame( 'Version 6.8', $this->footer->filter_version( 'Version 6.8' ), (string) $page );
		}
	}

	/**
	 * The container builds the service for the admin phase.
	 *
	 * @return void
	 */
	public function test_container_wires_footer() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( AdminFooter::class, $plugin->container()->get( 'admin.footer' ) );
		$this->assertContains( 'admin.footer', Plugin::BOOT_PHASES['admin'] );
	}
}
