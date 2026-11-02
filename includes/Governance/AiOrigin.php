<?php
/**
 * Declared AI-origin values.
 *
 * @package Sit_Cwm
 * @since   1.2.0
 */

namespace Sit_Cwm\Governance;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The closed set of AI-origin values a post can declare, with their labels
 * (F9, F16).
 *
 * The value is declared by a person or a tool, never detected: nothing in the
 * plugin inspects content. An unset or unknown value reads as "Not declared".
 *
 * @since 1.2.0
 * @api
 */
final class AiOrigin {

	/**
	 * Written by a person.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	const HUMAN = 'human';

	/**
	 * Created with AI assistance.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	const AI_ASSISTED = 'ai_assisted';

	/**
	 * Generated with AI.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	const AI_GENERATED = 'ai_generated';

	/**
	 * Nothing declared. Never stored: it is what an unset value reads as.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string
	 */
	const NOT_DECLARED = '';

	/**
	 * Every value that may be stored, in display order.
	 *
	 * @since 1.2.0
	 * @api
	 * @var string[]
	 */
	const VALUES = array( self::HUMAN, self::AI_ASSISTED, self::AI_GENERATED );

	/**
	 * Human-readable label of a value. Not escaped; escape at output.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @param string $value AI-origin value.
	 * @return string "Not declared" for `''` and any unknown value.
	 */
	public static function label( string $value ): string {
		$options = self::options();

		return $options[ $value ] ?? __( 'Not declared', 'sapphireit-editorial-workflow' );
	}

	/**
	 * Strict sanitizer: an exact value from `VALUES`, else `''`.
	 *
	 * No trimming or case folding, so a malformed value is never coerced into
	 * a declaration nobody made. Untyped so it can serve as a meta
	 * `sanitize_callback`, which core calls with extra arguments.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @param mixed $value Raw value.
	 * @return string A value from `VALUES`, or `''`.
	 */
	public static function sanitize( $value ): string {
		return is_string( $value ) && in_array( $value, self::VALUES, true ) ? $value : self::NOT_DECLARED;
	}

	/**
	 * Every declarable value with its label, for selects.
	 *
	 * @since 1.2.0
	 * @api
	 *
	 * @return array<string, string> Value => label, in `VALUES` order.
	 */
	public static function options(): array {
		return array(
			self::HUMAN        => __( 'Human-written', 'sapphireit-editorial-workflow' ),
			self::AI_ASSISTED  => __( 'AI-assisted', 'sapphireit-editorial-workflow' ),
			self::AI_GENERATED => __( 'AI-generated', 'sapphireit-editorial-workflow' ),
		);
	}
}
