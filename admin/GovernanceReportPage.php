<?php
/**
 * Governance report screen.
 *
 * @package Sit_Cwm
 * @since   1.2.0
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
 * "Workflow → Governance report" (F12): the period selector, KPI tiles and
 * AI-use breakdowns for auditors, plus the activity export form.
 *
 * The page is an empty React root under the shared header. Every figure is
 * loaded from `GET /sit-cwm/v1/reports/governance`, which re-checks
 * `can_audit()` on each request; nothing here builds markup from data.
 *
 * @since 1.2.0
 * @internal
 */
final class GovernanceReportPage implements Bootable {

	/**
	 * Build entry name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const ENTRY = 'report';

	/**
	 * Menu slug (`admin.php?page=sit-cwm-report`).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const MENU_SLUG = 'sit-cwm-report';

	/**
	 * Id of the element the React app mounts into.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const ROOT_ID = 'sit-cwm-report';

	/**
	 * Id of the title-row slot the app portals its Period control into.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const PERIOD_ID = 'sit-cwm-report-period';

	/**
	 * `admin_menu` priority: with the dashboard (10), before settings (11).
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MENU_PRIORITY = 10;

	/**
	 * Submenu position: right after the Dashboard item.
	 *
	 * @since 1.2.0
	 * @var int
	 */
	const MENU_POSITION = 1;

	/**
	 * Asset loader.
	 *
	 * @since 1.2.0
	 * @var Assets
	 */
	private $assets;

	/**
	 * Workflow authorization.
	 *
	 * @since 1.2.0
	 * @var PermissionManager
	 */
	private $permissions;

	/**
	 * Activity log export form.
	 *
	 * @since 1.2.0
	 * @var ActivityExport
	 */
	private $export;

	/**
	 * Shared admin header.
	 *
	 * @since 1.2.0
	 * @var AdminShell
	 */
	private $shell;

	/**
	 * Hook suffix of the registered page; empty until the menu is added.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 *
	 * @param Assets            $assets      Asset loader.
	 * @param PermissionManager $permissions Workflow authorization.
	 * @param ActivityExport    $export      Activity log export form.
	 * @param AdminShell        $shell       Shared admin header.
	 */
	public function __construct( Assets $assets, PermissionManager $permissions, ActivityExport $export, AdminShell $shell ) {
		$this->assets      = $assets;
		$this->permissions = $permissions;
		$this->export      = $export;
		$this->shell       = $shell;
	}

	/**
	 * Attaches the menu and enqueue hooks.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), self::MENU_PRIORITY );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Registers the submenu page for auditors, right after Dashboard.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		if ( ! $this->permissions->can_audit() ) {
			return;
		}

		$title = __( 'Governance report', 'sapphireit-editorial-workflow' );

		$this->hook_suffix = (string) add_submenu_page(
			Dashboard::MENU_SLUG,
			$title,
			$title,
			Capabilities::MANAGE_WORKFLOWS,
			self::MENU_SLUG,
			array( $this, 'render' ),
			self::MENU_POSITION
		);
	}

	/**
	 * Hook suffix of the report screen.
	 *
	 * @since 1.2.0
	 *
	 * @return string Empty when the menu was not registered for this user.
	 */
	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	/**
	 * Enqueues the report bundle on the report screen only.
	 *
	 * @since 1.2.0
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
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->permissions->can_audit() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'sapphireit-editorial-workflow' ), 403 );
		}

		echo '<div class="wrap sit-cwm-report-wrap">';
		$this->shell->render_header();

		// The React app portals its Period control into the actions slot.
		$actions = sprintf( '<div id="%s" class="sit-cwm-report-period"></div>', esc_attr( self::PERIOD_ID ) ) . $this->export->render_form();

		$head = $this->shell->page_head(
			__( 'Governance report', 'sapphireit-editorial-workflow' ),
			__( 'How content reached publication, from the activity log.', 'sapphireit-editorial-workflow' ),
			$actions
		);

		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- page_head() escapes the text; the actions are built from escaped parts.

		printf( '<div id="%s"></div></div>', esc_attr( self::ROOT_ID ) );
	}
}
