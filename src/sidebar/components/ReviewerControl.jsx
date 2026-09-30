/**
 * Reviewer picker. Rendered only when the user may assign reviewers.
 */

/**
 * WordPress dependencies
 */
import { ComboboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import useReviewerOptions from '../../hooks/useReviewerOptions';

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

	const currentId = reviewer ? reviewer.id : 0;

	const handleChange = ( value ) => {
		const id = value ? parseInt( value, 10 ) || 0 : 0;

		if ( ! isSaving && id !== currentId ) {
			onChange( id );
		}
	};

	return (
		<div className="sit-cwm-field sit-cwm-reviewer">
			{ reviewer && (
				<div className="sit-cwm-reviewer-current">
					{ reviewer.avatar && (
						<img
							className="sit-cwm-avatar"
							src={ reviewer.avatar }
							alt=""
							width={ 24 }
							height={ 24 }
						/>
					) }
					<span>{ reviewer.name }</span>
				</div>
			) }
			<ComboboxControl
				label={ __( 'Reviewer', 'sapphireit-editorial-workflow' ) }
				value={ String( currentId ) }
				options={ options }
				onChange={ handleChange }
				onFilterValueChange={ onFilterValueChange }
				isLoading={ isLoading }
				allowReset={ false }
			/>
			{ error && <p className="sit-cwm-error">{ error.message }</p> }
		</div>
	);
}
