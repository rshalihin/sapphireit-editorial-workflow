/**
 * Declared AI use labels, mirroring `Sit_Cwm\Governance\AiOrigin::label()`.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * The declarable values, in `AiOrigin::VALUES` order.
 *
 * @type {string[]}
 */
export const AI_ORIGIN_VALUES = [ 'human', 'ai_assisted', 'ai_generated' ];

/**
 * Translated labels of the declarable values.
 *
 * A function rather than a constant so the strings are translated after the
 * locale data has loaded.
 *
 * @return {Object<string, string>} Value → label.
 */
export function aiOriginLabels() {
	return {
		human: __( 'Human-written', 'sapphireit-editorial-workflow' ),
		ai_assisted: __( 'AI-assisted', 'sapphireit-editorial-workflow' ),
		ai_generated: __( 'AI-generated', 'sapphireit-editorial-workflow' ),
	};
}

/**
 * Label of a stored value; unset or unknown values read as "Not declared".
 *
 * @param {string} value Stored value.
 * @return {string} Label.
 */
export function aiOriginLabel( value ) {
	return (
		aiOriginLabels()[ value ] ||
		__( 'Not declared', 'sapphireit-editorial-workflow' )
	);
}
