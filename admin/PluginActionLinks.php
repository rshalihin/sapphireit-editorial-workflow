<?php
/**
 * The plugin's row on the Plugins screen.
 *
 * @package Sit_Cwm
 * @since   1.1.0
 */

namespace Sit_Cwm\Admin;

use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a "Settings" link beside core's "Deactivate" on the Plugins screen.
 *
 * The link points at the dashboard page slug rather than a menu path, so it
 * keeps working wherever the page sits in the admin menu.
 *
 * @since 1.1.0
 */
final class PluginActionLinks implements Bootable {

	/**
	 * Workflow authorization.
	 *
	 * @since 1.1.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param PermissionManager $permissions Workflow authorization.
	 */
	public function __construct( PermissionManager $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * Attaches the action links filter for this plugin's row only.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'plugin_action_links_' . plugin_basename( SIT_CWM_PLUGIN_FILE ), array( $this, 'filter_action_links' ) );
	}

	/**
	 * Puts the Settings link first in the plugin's row.
	 *
	 * Skipped for a user who could not open the dashboard anyway.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $links Action links from core or an earlier filter.
	 * @return string[]
	 */
	public function filter_action_links( $links ): array {
		if ( ! $this->permissions->can_access_dashboard() ) {
			return (array) $links;
		}

		$settings = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=' . Dashboard::MENU_SLUG ) ),
			esc_html__( 'Settings', 'sapphireit-editorial-workflow' )
		);

		return array_merge( array( 'settings' => $settings ), (array) $links );
	}
}
