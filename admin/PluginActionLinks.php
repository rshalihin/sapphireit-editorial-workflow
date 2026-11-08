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
 * The link points at the settings page slug rather than a menu path, so it
 * keeps working wherever the page sits in the admin menu.
 *
 * @since 1.1.0
 * @internal
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
	 * Promotion of Pro (the "Go Pro" link), if wired.
	 *
	 * @since 1.2.0
	 * @var Promotions|null
	 */
	private $promotions;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Added $promotions.
	 *
	 * @param PermissionManager $permissions Workflow authorization.
	 * @param Promotions|null   $promotions  Promotion of Pro; null adds no "Go Pro" link.
	 */
	public function __construct( PermissionManager $permissions, ?Promotions $promotions = null ) {
		$this->permissions = $permissions;
		$this->promotions  = $promotions;
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
	 * Puts the Settings link first in the plugin's row, and, while Pro is on
	 * sale, a plain "Go Pro" link last.
	 *
	 * Skipped for a user who could not open the settings screen anyway, by the
	 * same check that registers its menu.
	 *
	 * @since 1.1.0
	 * @since 1.2.0 Adds the "Go Pro" link.
	 *
	 * @param string[] $links Action links from core or an earlier filter.
	 * @return string[]
	 */
	public function filter_action_links( $links ): array {
		if ( ! $this->permissions->can_manage() ) {
			return (array) $links;
		}

		$settings = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=' . Settings::MENU_SLUG ) ),
			esc_html__( 'Settings', 'sapphireit-editorial-workflow' )
		);

		$links = array_merge( array( 'settings' => $settings ), (array) $links );
		$pro   = null !== $this->promotions ? $this->promotions->action_link() : '';

		if ( '' !== $pro ) {
			$links['sit_cwm_go_pro'] = $pro;
		}

		return $links;
	}
}
