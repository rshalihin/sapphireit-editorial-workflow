<?php
/**
 * Integration tests for the Plugins-screen action links.
 *
 * @package Sit_Cwm\Tests
 * @since   1.1.0
 */

namespace Sit_Cwm\Tests\Integration\Admin;

use Sit_Cwm\Admin\PluginActionLinks;
use Sit_Cwm\Core\Container;
use Sit_Cwm\Core\Plugin;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Tests\TestCase;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's row on the Plugins screen leads with a Settings link, for users
 * who can open the settings screen.
 *
 * @since 1.1.0
 *
 * @covers \Sit_Cwm\Admin\PluginActionLinks
 */
final class PluginActionLinksTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var PluginActionLinks
	 */
	private $links;

	/**
	 * Builds the service.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->links = new PluginActionLinks( new PermissionManager( $this->posts, new Settings() ) );
	}

	/**
	 * The filter is hooked to this plugin's row only.
	 *
	 * @return void
	 */
	public function test_register_filters_this_plugins_row() {
		$this->links->register();

		$hook = 'plugin_action_links_' . plugin_basename( SIT_CWM_PLUGIN_FILE );

		$this->assertSame( 10, has_filter( $hook, array( $this->links, 'filter_action_links' ) ) );

		remove_filter( $hook, array( $this->links, 'filter_action_links' ) );
	}

	/**
	 * A user who can open the settings screen gets the Settings link ahead of Deactivate.
	 *
	 * @return void
	 */
	public function test_settings_link_comes_first() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$links = $this->links->filter_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertSame(
			'<a href="' . esc_url( admin_url( 'admin.php?page=sit-cwm-settings' ) ) . '">Settings</a>',
			$links['settings']
		);
	}

	/**
	 * A user who cannot open the settings screen gets the row unchanged, even
	 * when they can open the dashboard.
	 *
	 * @return void
	 */
	public function test_no_link_without_settings_access() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$given = array( 'deactivate' => '<a href="#">Deactivate</a>' );

		$this->assertSame( $given, $this->links->filter_action_links( $given ) );
	}

	/**
	 * The container builds the service for the admin phase.
	 *
	 * @return void
	 */
	public function test_container_wires_action_links() {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertInstanceOf( PluginActionLinks::class, $plugin->container()->get( 'admin.action_links' ) );
		$this->assertContains( 'admin.action_links', Plugin::BOOT_PHASES['admin'] );
	}
}
