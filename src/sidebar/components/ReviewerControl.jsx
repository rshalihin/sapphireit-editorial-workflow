/**
 * Reviewer picker. Rendered only when the user may assign reviewers.
 */

/**
 * WordPress dependencies
 */
import { ComboboxControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
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
 * A pick made while another workflow change is saving is shown at once and
 * sent once that save finishes, instead of being ignored.
 *
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
	// Reviewer id picked during a save; `null` when nothing is waiting.
	const [ queued, setQueued ] = useState( null );

	const currentId = reviewer ? reviewer.id : 0;

	useEffect( () => {
		if ( queued === null || isSaving ) {
			return;
		}

		setQueued( null );

		if ( queued !== currentId ) {
			onChange( queued );
		}
		// `onChange` may be rebuilt every render; the queue only advances on these.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ queued, isSaving ] );

	const handleChange = ( value ) => {
		const id = value ? parseInt( value, 10 ) || 0 : 0;

		if ( isSaving ) {
			// A later pick replaces an earlier one; picking the current
			// reviewer again cancels it.
			setQueued( id );
		} else if ( id !== currentId ) {
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
				value={ String( queued ?? currentId ) }
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
