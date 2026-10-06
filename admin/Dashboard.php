<?php
/**
 * Admin dashboard screen.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Admin;

use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Workflow\Capabilities;
use Sit_Cwm\Workflow\PermissionManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The "Editorial Workflow" top-level admin page.
 *
 * The page is an empty React root: every row, filter and action is loaded
 * from `GET /sit-cwm/v1/posts` and re-authorized server-side per request, so
 * nothing here builds markup from data. The bundle is enqueued on this screen
 * only.
 *
 * @since 1.0.0
 */
final class Dashboard implements Bootable {

	/**
	 * Build entry name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ENTRY = 'dashboard';

	/**
	 * Menu slug (`admin.php?page=sit-cwm-dashboard`).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const MENU_SLUG = 'sit-cwm-dashboard';

	/**
	 * Id of the element the React app mounts into.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ROOT_ID = 'sit-cwm-dashboard';

	/**
	 * Menu position: right below Comments.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MENU_POSITION = 25;

	/**
	 * Asset loader.
	 *
	 * @since 1.0.0
	 * @var Assets
	 */
	private $assets;

	/**
	 * Workflow authorization.
	 *
	 * @since 1.0.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Hook suffix of the registered page; empty until the menu is added.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Assets            $assets      Asset loader.
	 * @param PermissionManager $permissions Workflow authorization.
	 */
	public function __construct( Assets $assets, PermissionManager $permissions ) {
		$this->assets      = $assets;
		$this->permissions = $permissions;
	}

	/**
	 * Attaches the menu and enqueue hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Registers the top-level menu page.
	 *
	 * Gated on `sit_cwm_view_activity` rather than `manage_options`, so
	 * editors, authors and contributors get it. Users who cannot list content
	 * of any workflow-enabled post type get no menu, since the page would only
	 * show a permission error.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		if ( ! $this->permissions->can_access_dashboard() ) {
			return;
		}

		$title = __( 'Editorial Workflow', 'sapphireit-editorial-workflow' );

		$this->hook_suffix = (string) add_menu_page(
			$title,
			$title,
			Capabilities::VIEW_ACTIVITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-clipboard',
			self::MENU_POSITION
		);

		// Name the first submenu entry explicitly; otherwise core points the
		// top-level item at the first submenu added later (Settings).
		add_submenu_page(
			self::MENU_SLUG,
			$title,
			__( 'Dashboard', 'sapphireit-editorial-workflow' ),
			Capabilities::VIEW_ACTIVITY,
			self::MENU_SLUG
		);
	}

	/**
	 * Hook suffix of the dashboard screen.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty when the menu was not registered for this user.
	 */
	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Enqueues the dashboard bundle on the dashboard screen only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Hook suffix of the current admin screen.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $this->hook_suffix !== $hook_suffix ) {
			return;
		}

		$this->assets->enqueue( self::ENTRY );
	}

	/**
	 * Prints the page shell the React app mounts into.
	 *
	 * The heading sits outside the React root so it survives mounting, and
	 * core moves admin notices below the first `.wrap h1`.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->permissions->can_access_dashboard() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'sapphireit-editorial-workflow' ), 403 );
		}

		printf(
			'<div class="wrap sit-cwm-dashboard-wrap"><h1 class="screen-reader-text">%1$s</h1><div id="%2$s"></div></div>',
			esc_html__( 'Editorial Workflow', 'sapphireit-editorial-workflow' ),
			esc_attr( self::ROOT_ID )
		);
	}
}
