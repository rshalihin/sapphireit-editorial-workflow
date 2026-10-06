<?php
/**
 * Block editor sidebar assets.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Editor;

use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Workflow\PermissionManager;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the workflow sidebar bundle in the block editor.
 *
 * Only for a post whose type is workflow-enabled and that the current user may
 * edit; everywhere else (site editor, widgets, other post types) the bundle is
 * never enqueued.
 *
 * @since 1.0.0
 */
final class SidebarAssets implements Bootable {

	/**
	 * Build entry name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ENTRY = 'sidebar';

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
	 * Attaches the enqueue hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueues the sidebar for the post being edited, when permitted.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$post = get_post();

		if ( ! $post instanceof WP_Post || ! $this->permissions->can_edit_post( $post->ID ) ) {
			return;
		}

		$this->assets->enqueue( self::ENTRY );
	}
}
