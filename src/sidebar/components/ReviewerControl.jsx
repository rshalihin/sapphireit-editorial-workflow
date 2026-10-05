/**
 * Reviewer picker. Rendered only when the user may assign reviewers.
 */

/**
 * WordPress dependencies
 */
import { ComboboxControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import useReviewerOptions from '../../hooks/useReviewerOptions';

/**
 * Case- and accent-insensitive form of a label, as `ComboboxControl` matches.
 *
 * @param {string} text Text.
 * @return {string} Normalized text.
 */
const normalize = ( text ) =>
	text
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLocaleLowerCase();

/**
 * @param {Object}   props          Props.
 * @param {number}   props.postId   Post id.
 * @param {?Object}  props.reviewer Current reviewer `{ id, name, avatar }`.
 * @param {Function} props.onChange Receives the new reviewer id (`0` clears).
 * @param {boolean}  props.isSaving Whether a workflow change is in flight.
 * @return {Element} Control.
 */
export default function ReviewerControl( {
	postId,
	reviewer,
	onChange,
	isSaving,
} ) {
	const { options, isLoading, error, onFilterValueChange } =
		useReviewerOptions( { postId, reviewer } );
	const [ filter, setFilter ] = useState( '' );

	const currentId = reviewer ? reviewer.id : 0;

	const handleChange = ( value ) => {
		const id = value ? parseInt( value, 10 ) || 0 : 0;

		if ( ! isSaving && id !== currentId ) {
			onChange( id );
		}
	};

	const handleFilterValueChange = ( value ) => {
		setFilter( value );
		onFilterValueChange( value );
	};

	// With no matching option, `ComboboxControl` still picks its last
	// highlighted suggestion on Enter (often "Unassigned"), which would
	// silently clear the reviewer. It skips events whose default is prevented.
	const handleKeyDownCapture = ( event ) => {
		const match = normalize( filter.trim() );

		if (
			event.key === 'Enter' &&
			match !== '' &&
			! options.some( ( option ) =>
				normalize( option.label ).includes( match )
			)
		) {
			event.preventDefault();
		}
	};

	return (
		<div
			className="sit-cwm-field sit-cwm-reviewer"
			onKeyDownCapture={ handleKeyDownCapture }
		>
			{ /* The combobox shows the current reviewer; no separate summary,
			   which would render above the "Reviewer" label. */ }
			<ComboboxControl
				label={ __( 'Reviewer', 'sapphireit-editorial-workflow' ) }
				value={ String( currentId ) }
				options={ options }
				onChange={ handleChange }
				onFilterValueChange={ handleFilterValueChange }
				isLoading={ isLoading }
				allowReset={ false }
			/>
			{ error && <p className="sit-cwm-error">{ error.message }</p> }
		</div>
	);
}
