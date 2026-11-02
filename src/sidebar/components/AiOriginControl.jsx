/**
 * Declared AI use of the post.
 *
 * The value is declared by people or tools, never detected. Once declared it
 * can be changed but not withdrawn, so "Not declared" is offered only while
 * nothing is set, and then as a disabled placeholder.
 */

/**
 * WordPress dependencies
 */
import { SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	AI_ORIGIN_VALUES,
	aiOriginLabel,
	aiOriginLabels,
} from '../../utils/aiOrigin';

/**
 * @param {Object}   props            Props.
 * @param {string}   props.value      Declared value, or `''`.
 * @param {boolean}  props.canDeclare Whether the user may change it.
 * @param {boolean}  [props.isSaving] Whether a workflow change is in flight.
 * @param {Function} props.onChange   Receives the chosen value.
 * @return {Element} Control.
 */
export default function AiOriginControl( {
	value,
	canDeclare,
	isSaving = false,
	onChange,
} ) {
	const help = __(
		'Declare how this content was made. This is recorded in the activity log; it is not detected automatically.',
		'sapphireit-editorial-workflow'
	);

	if ( ! canDeclare ) {
		return (
			<div className="sit-cwm-field sit-cwm-ai-origin">
				<span className="sit-cwm-field-label">
					{ __( 'AI use', 'sapphireit-editorial-workflow' ) }
				</span>
				<p className="sit-cwm-ai-origin__value">
					{ aiOriginLabel( value ) }
				</p>
			</div>
		);
	}

	const labels = aiOriginLabels();
	const options = AI_ORIGIN_VALUES.map( ( slug ) => ( {
		value: slug,
		label: labels[ slug ],
	} ) );

	if ( value === '' ) {
		options.unshift( {
			value: '',
			label: __( 'Not declared', 'sapphireit-editorial-workflow' ),
			disabled: true,
		} );
	}

	return (
		<div className="sit-cwm-field sit-cwm-ai-origin">
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				className="sit-cwm-ai-origin__select"
				label={ __( 'AI use', 'sapphireit-editorial-workflow' ) }
				help={ help }
				value={ value }
				options={ options }
				onChange={ ( next ) => onChange( next ) }
				disabled={ isSaving }
			/>
		</div>
	);
}
