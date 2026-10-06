<?php
/**
 * Classic editor workflow meta box.
 *
 * @package Sit_Cwm
 * @since   1.0.0
 */

namespace Sit_Cwm\Editor;

use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings;
use Sit_Cwm\Workflow\PermissionManager;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the "Editorial Workflow" meta box to classic editor screens.
 *
 * The box is only a mount point for the `classic` React bundle, which reuses
 * the block editor sidebar's `WorkflowPanel`. It has no form fields, no nonce
 * and no `save_post` handler on purpose: nothing is submitted with the post
 * form. Every workflow write goes through the REST API, authenticated with the
 * `wp_rest` nonce by `@wordpress/api-fetch`, and is authorized server-side by
 * `WorkflowManager` / `PermissionManager`.
 *
 * The box and its bundle load only for a workflow-enabled post type, a post
 * the current user may edit, and a post that is not edited in the block editor
 * (where the sidebar is used instead).
 *
 * @since 1.0.0
 */
final class ClassicMetaBox implements Bootable {

	/**
	 * Build entry name.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ENTRY = 'classic';

	/**
	 * Meta box id.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_BOX_ID = 'sit-cwm-workflow';

	/**
	 * Id of the element the React panel mounts into.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ROOT_ID = 'sit-cwm-classic-root';

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
	 * Plugin settings (enabled post types).
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Assets            $assets      Asset loader.
	 * @param PermissionManager $permissions Workflow authorization.
	 * @param Settings          $settings    Plugin settings.
	 */
	public function __construct( Assets $assets, PermissionManager $permissions, Settings $settings ) {
		$this->assets      = $assets;
		$this->permissions = $permissions;
		$this->settings    = $settings;
	}

	/**
	 * Attaches the meta box and enqueue hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Registers the meta box for the post being edited, when it applies.
	 *
	 * @since 1.0.0
	 *
	 * @param string        $post_type Post type of the edit screen.
	 * @param WP_Post|mixed $post      Post being edited (other screens pass other objects).
	 * @return void
	 */
	public function add_meta_box( $post_type, $post ): void {
		if ( ! $this->applies( $post ) ) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Editorial Workflow', 'sapphireit-editorial-workflow' ),
			array( $this, 'render' ),
			$post_type,
			'side',
			'high',
			// Never shown in the block editor's meta box area; the sidebar covers it.
			array( '__back_compat_meta_box' => true )
		);
	}

	/**
	 * Prints the React mount point, with a no-JS fallback message.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Post being edited.
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		printf(
			'<div id="%1$s" class="sit-cwm-classic-root" data-post-id="%2$s" data-post-type="%3$s"><p class="hide-if-js">%4$s</p></div>',
			esc_attr( self::ROOT_ID ),
			esc_attr( (string) $post->ID ),
			esc_attr( $post->post_type ),
			esc_html__( 'The editorial workflow needs JavaScript.', 'sapphireit-editorial-workflow' )
		);
	}

	/**
	 * Enqueues the classic bundle on post edit screens where the box applies.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Hook suffix of the current admin screen.
	 * @return void
	 */
	public function enqueue( $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		if ( ! $this->applies( get_post() ) ) {
			return;
		}

		$this->assets->enqueue( self::ENTRY );
	}

	/**
	 * Whether the meta box and bundle apply to a post.
	 *
	 * Single source of truth for both the meta box and the enqueue: an enabled
	 * post type, the classic editor, and a user who may edit the post.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post|mixed $post Post being edited.
	 * @return bool
	 */
	private function applies( $post ): bool {
		return $post instanceof WP_Post
			&& $this->settings->is_post_type_enabled( $post->post_type )
			&& function_exists( 'use_block_editor_for_post' )
			&& ! use_block_editor_for_post( $post )
			&& $this->permissions->can_edit_post( (int) $post->ID );
	}
}
