/**
 * Assigns or clears the reviewer of one row.
 */

/**
 * WordPress dependencies
 */
import { ComboboxControl, Notice } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ModalActions from './ModalActions';
import useRowMutation from './useRowMutation';
import { updateWorkflow } from '../../api/client';
import useReviewerOptions from '../../hooks/useReviewerOptions';
import { displayTitle } from '../../utils/format';

/**
 * Reviewer form.
 *
 * @type {Object}
 */
const FORM = { layout: { type: 'regular' }, fields: [ 'reviewer_id' ] };

/**
 * Searchable picker limited to users who can review the post.
 *
 * @param {Object}   props          DataForm control props.
 * @param {Object}   props.data     Form data.
 * @param {Object}   props.field    Normalized field.
 * @param {Function} props.onChange Receives `{ reviewer_id }`.
 * @param {number}   props.postId   Post id.
 * @param {?Object}  props.reviewer Current reviewer.
 * @return {Element} Control.
 */
function ReviewerPicker( { data, field, onChange, postId, reviewer } ) {
	const { options, isLoading, error, onFilterValueChange } =
		useReviewerOptions( { postId, reviewer } );

	return (
		<>
			<ComboboxControl
				__next40pxDefaultSize
				label={ field.label }
				value={ String( data.reviewer_id ) }
				options={ options }
				onChange={ ( value ) =>
					onChange( {
						reviewer_id: value ? parseInt( value, 10 ) || 0 : 0,
					} )
				}
				onFilterValueChange={ onFilterValueChange }
				isLoading={ isLoading }
				allowReset={ false }
			/>
			{ error && <p className="sit-cwm-error">{ error.message }</p> }
		</>
	);
}

/**
 * @param {Object}   props            Props.
 * @param {Object[]} props.items      Rows the action runs on (one).
 * @param {Function} props.closeModal Closes the modal.
 * @param {Function} props.onChanged  Refetches the current page.
 * @return {Element} Modal content.
 */
export default function ReviewerModal( { items, closeModal, onChanged } ) {
	const [ item ] = items;
	const currentId = item.reviewer ? item.reviewer.id : 0;
	const [ data, setData ] = useState( { reviewer_id: currentId } );
	const { run, isBusy, error } = useRowMutation( { closeModal, onChanged } );

	const fields = useMemo(
		() => [
			{
				id: 'reviewer_id',
				type: 'integer',
				label: __( 'Reviewer', 'sapphireit-editorial-workflow' ),
				Edit: ( props ) => (
					<ReviewerPicker
						{ ...props }
						postId={ item.post_id }
						reviewer={ item.reviewer }
					/>
				),
			},
		],
		[ item.post_id, item.reviewer ]
	);

	const title = displayTitle( item.title );

	const submit = ( event ) => {
		event.preventDefault();

		run( async () => {
			await updateWorkflow( item.post_id, {
				reviewer_id: data.reviewer_id,
			} );

			return {
				success: data.reviewer_id
					? sprintf(
							/* translators: %s: Post title. */
							__(
								'Reviewer assigned to “%s”.',
								'sapphireit-editorial-workflow'
							),
							title
						)
					: sprintf(
							/* translators: %s: Post title. */
							__(
								'Reviewer removed from “%s”.',
								'sapphireit-editorial-workflow'
							),
							title
						),
			};
		} );
	};

	return (
		<form className="sit-cwm-modal-form" onSubmit={ submit }>
			<DataForm
				data={ data }
				fields={ fields }
				form={ FORM }
				onChange={ ( edits ) =>
					setData( ( previous ) => ( { ...previous, ...edits } ) )
				}
			/>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error.message }
				</Notice>
			) }

			<ModalActions
				onCancel={ closeModal }
				submitLabel={ __( 'Save', 'sapphireit-editorial-workflow' ) }
				isBusy={ isBusy }
				canSubmit={ data.reviewer_id !== currentId }
			/>
		</form>
	);
}
