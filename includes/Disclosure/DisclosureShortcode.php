<?php
/**
 * Review disclosure shortcode.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Disclosure;

use Sit_Cwm\Core\Interfaces\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `[sit_cwm_review_disclosure reviewer="1" ai="1"]` (F11).
 *
 * Always registered, so content using it never shows the raw tag; it prints
 * `''` while the setting is off. It renders the current post only: there is
 * deliberately no `post_id` attribute, so an author cannot surface another
 * post's approver.
 *
 * @since 1.2.0
 * @internal
 */
final class DisclosureShortcode implements Bootable {

	/**
	 * Shortcode tag.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	const TAG = 'sit_cwm_review_disclosure';

	/**
	 * Attribute values that switch a line off; anything else leaves it on.
	 *
	 * @since 1.2.0
	 * @var string[]
	 */
	const OFF_VALUES = array( '0', 'no', 'false' );

	/**
	 * Disclosure service.
	 *
	 * @since 1.2.0
	 * @var ReviewDisclosure
	 */
	private $disclosure;

	/**
	 * Constructor. Does not touch WordPress.
	 *
	 * @since 1.2.0
	 *
	 * @param ReviewDisclosure $disclosure Disclosure service.
	 */
	public function __construct( ReviewDisclosure $disclosure ) {
		$this->disclosure = $disclosure;
	}

	/**
	 * Registers the shortcode on `init`.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_shortcode' ) );
	}

	/**
	 * Adds the shortcode.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function add_shortcode(): void {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @since 1.2.0
	 *
	 * @param array|string $atts Shortcode attributes; `''` when none.
	 * @return string Escaped HTML, or `''`.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'reviewer' => '1',
				'ai'       => '1',
			),
			$atts,
			self::TAG
		);

		return $this->disclosure->render( (int) get_the_ID(), $this->is_on( $atts['reviewer'] ), $this->is_on( $atts['ai'] ) );
	}

	/**
	 * Whether an attribute value leaves its line on.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private function is_on( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return ! is_scalar( $value ) || ! in_array( strtolower( trim( (string) $value ) ), self::OFF_VALUES, true );
	}
}
