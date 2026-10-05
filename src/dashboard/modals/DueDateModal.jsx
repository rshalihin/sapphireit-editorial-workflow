/**
 * Sets or clears the due date of one row.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ModalActions from './ModalActions';
import useRowMutation from './useRowMutation';
import { updateWorkflow } from '../../api/client';
import { isCalendarDate } from '../../hooks/usePosts';
import { displayTitle } from '../../utils/format';

/**
 * Due date field.
 *
 * @type {Object[]}
 */
const FIELDS = [
	{
		id: 'due_date',
		type: 'date',
		label: __( 'Due date', 'sapphireit-editorial-workflow' ),
	},
];

/**
 * Due date form.
 *
 * @type {Object}
 */
const FORM = { layout: { type: 'regular' }, fields: [ 'due_date' ] };

/**
 * @param {Object}   props            Props.
 * @param {Object[]} props.items      Rows the action runs on (one).
 * @param {Function} props.closeModal Closes the modal.
 * @param {Function} props.onChanged  Refetches the current page.
 * @return {Element} Modal content.
 */
export default function DueDateModal( { items, closeModal, onChanged } ) {
	const [ item ] = items;
	const current = item.due_date || '';
	const [ data, setData ] = useState( { due_date: current } );
	const { run, isBusy, error } = useRowMutation( { closeModal, onChanged } );

	const title = displayTitle( item.title );

	const save = ( date ) =>
		run( async () => {
			await updateWorkflow( item.post_id, { due_date: date } );

			return {
				success: date
					? sprintf(
							/* translators: %s: Post title. */
							__(
								'Due date updated for “%s”.',
								'sapphireit-editorial-workflow'
							),
							title
						)
					: sprintf(
							/* translators: %s: Post title. */
							__(
								'Due date removed from “%s”.',
								'sapphireit-editorial-workflow'
							),
							title
						),
			};
		} );

	const submit = ( event ) => {
		event.preventDefault();
		save( data.due_date );
	};

	return (
		<form className="sit-cwm-modal-form" onSubmit={ submit }>
			<DataForm
				data={ data }
				fields={ FIELDS }
				form={ FORM }
				onChange={ ( edits ) =>
					setData( {
						due_date:
							typeof edits.due_date === 'string'
								? edits.due_date.slice( 0, 10 )
								: '',
					} )
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
				canSubmit={
					isCalendarDate( data.due_date ) && data.due_date !== current
				}
			>
				{ current && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isDestructive
						onClick={ () => save( '' ) }
						disabled={ isBusy }
					>
						{ __(
							'Remove due date',
							'sapphireit-editorial-workflow'
						) }
					</Button>
				) }
			</ModalActions>
		</form>
	);
}
