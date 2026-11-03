<?php
/**
 * Review disclosure block.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Disclosure;

use Sit_Cwm\Core\Assets;
use Sit_Cwm\Core\Interfaces\Bootable;
use Sit_Cwm\Core\Settings;
use WP_Block;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The dynamic `sit-cwm/review-disclosure` block (F11).
 *
 * Always registered, so saved content never turns into an "unsupported
 * block"; it is hidden from the inserter while the setting is off, and then
 * renders `''`. It renders the post in block context only, through the same
 * service as the shortcode.
 *
 * @since 1.2.0
 * @internal
 */
final class DisclosureBlock implements Bootable {

	/**
	 * Block name.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const NAME = 'sit-cwm/review-disclosure';

	/**
	 * Build entry of the editor script (see `webpack.config.js`).
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const ENTRY = 'review-disclosure';

	/**
	 * Disclosure service.
	 *
	 * @since 1.2.0
	 * @var ReviewDisclosure
	 */
	private $disclosure;

	/**
	 * Compiled asset loader.
	 *
	 * @since 1.2.0
	 * @var Assets
	 */
	private $assets;

	/**
	 * Plugin settings.
	 *
	 * @since 1.2.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.2.0
	 *
	 * @param ReviewDisclosure $disclosure Disclosure service.
	 * @param Assets           $assets     Compiled asset loader.
	 * @param Settings         $settings   Plugin settings.
	 */
	public function __construct( ReviewDisclosure $disclosure, Assets $assets, Settings $settings ) {
		$this->disclosure = $disclosure;
		$this->assets     = $assets;
		$this->settings   = $settings;
	}

	/**
	 * Registers the block on `init` (priority 9, ahead of most themes'
	 * block work) and hides it from the inserter while the setting is off.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ), 9 );
		add_filter( 'register_block_type_args', array( $this, 'filter_block_args' ), 10, 2 );
	}

	/**
	 * Registers the editor script, then the block from its `block.json`.
	 *
	 * An unbuilt editor script only costs the editor UI; the block is still
	 * registered and renders on the front end.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register_block(): void {
		if ( $this->assets->register_script( self::ENTRY ) ) {
			wp_add_inline_script(
				$this->assets->script_handle( self::ENTRY ),
				sprintf( 'window.sitCwmDisclosure = %s;', wp_json_encode( array( 'enabled' => $this->settings->is_disclosure_enabled() ) ) ),
				'before'
			);
		}

		register_block_type( SIT_CWM_PLUGIN_DIR . 'blocks/review-disclosure', array( 'render_callback' => array( $this, 'render_block' ) ) );
	}

	/**
	 * Sets `supports.inserter = false` on this block while the setting is off.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $args       Block type arguments.
	 * @param string $block_type Block name.
	 * @return array
	 */
	public function filter_block_args( $args, $block_type ) {
		if ( self::NAME !== $block_type || ! is_array( $args ) || $this->settings->is_disclosure_enabled() ) {
			return $args;
		}

		$supports             = isset( $args['supports'] ) && is_array( $args['supports'] ) ? $args['supports'] : array();
		$supports['inserter'] = false;
		$args['supports']     = $supports;

		return $args;
	}

	/**
	 * Block render callback.
	 *
	 * @since 1.2.0
	 *
	 * @param array    $attributes Block attributes.
	 * @param string   $content    Inner content; unused (dynamic block).
	 * @param WP_Block $block      Block instance.
	 * @return string Escaped HTML in the block wrapper, or `''`.
	 */
	public function render_block( array $attributes, string $content, WP_Block $block ): string {
		unset( $content );

		$post_id       = (int) ( $block->context['postId'] ?? get_the_ID() );
		$show_reviewer = $attributes['showReviewer'] ?? true;
		$show_ai       = $attributes['showAiOrigin'] ?? true;
		$html          = $this->disclosure->render( $post_id, false !== $show_reviewer, false !== $show_ai );

		if ( '' === $html ) {
			return '';
		}

		// $html is escaped by ReviewDisclosure::render(); the wrapper attributes by core.
		return sprintf( '<div %1$s>%2$s</div>', get_block_wrapper_attributes(), $html );
	}
}
