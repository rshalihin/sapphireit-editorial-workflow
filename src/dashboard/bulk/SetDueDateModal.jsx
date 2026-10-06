/**
 * Sets or clears the due date of one or more rows.
 *
 * A single row uses the row modal; several rows go through the batch route.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import { useContext, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import DueDateModal from '../modals/DueDateModal';
import ModalActions from '../modals/ModalActions';
import { isCalendarDate } from '../../hooks/usePosts';
import { formatDate } from '../../utils/format';
import { BulkActionContext, BulkConfirmation, toBulkRequest } from './shared';

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
 * @param {Object[]} props.items      Selected rows.
 * @param {Function} props.closeModal Closes the modal.
 * @return {Element} Form.
 */
function BulkDueDateForm( { items, closeModal } ) {
	const { run, isRunning } = useContext( BulkActionContext );
	const [ data, setData ] = useState( { due_date: '' } );
	const [ isClearing, setIsClearing ] = useState( false );
	const [ isConfirming, setIsConfirming ] = useState( false );

	const count = items.length;

	const send = async ( date ) => {
		const { postIds, labels } = toBulkRequest( items );

		await run( 'set_due_date', { due_date: date }, postIds, { labels } );
		closeModal();
	};

	const submit = ( event ) => {
		event.preventDefault();

		if ( isRunning ) {
			return;
		}

		if ( isClearing ) {
			send( '' );
		} else if ( ! isCalendarDate( data.due_date ) ) {
			return;
		} else if ( isConfirming ) {
			send( data.due_date );
		} else {
			// Every bulk change is confirmed, naming the count and the date.
			setIsConfirming( true );
		}
	};

	const isAsking = isClearing || isConfirming;

	return (
		<form className="sit-cwm-modal-form" onSubmit={ submit }>
			<p>
				{ sprintf(
					/* translators: %d: Number of selected items. */
					_n(
						'Set the due date of %d selected item.',
						'Set the due date of %d selected items.',
						count,
						'sapphireit-editorial-workflow'
					),
					count
				) }
			</p>

			<DataForm
				data={ data }
				fields={ FIELDS }
				form={ FORM }
				onChange={ ( edits ) => {
					setIsClearing( false );
					setIsConfirming( false );
					setData( {
						due_date:
							typeof edits.due_date === 'string'
								? edits.due_date.slice( 0, 10 )
								: '',
					} );
				} }
			/>

			{ isClearing && (
				<BulkConfirmation
					message={ sprintf(
						/* translators: %d: Number of items. */
						_n(
							'Remove the due date from %d item?',
							'Remove the due date from %d items?',
							count,
							'sapphireit-editorial-workflow'
						),
						count
					) }
				/>
			) }

			{ isConfirming && ! isClearing && (
				<BulkConfirmation
					message={ sprintf(
						/* translators: 1: Number of items, 2: Due date. */
						_n(
							'Set the due date of %1$d item to %2$s?',
							'Set the due date of %1$d items to %2$s?',
							count,
							'sapphireit-editorial-workflow'
						),
						count,
						formatDate( data.due_date )
					) }
				/>
			) }

			<ModalActions
				onCancel={ closeModal }
				submitLabel={ ( () => {
					if ( isClearing ) {
						return sprintf(
							/* translators: %d: Number of items. */
							_n(
								'Yes, remove from %d item',
								'Yes, remove from %d items',
								count,
								'sapphireit-editorial-workflow'
							),
							count
						);
					}

					if ( isConfirming ) {
						return sprintf(
							/* translators: %d: Number of items. */
							_n(
								'Yes, update %d item',
								'Yes, update %d items',
								count,
								'sapphireit-editorial-workflow'
							),
							count
						);
					}

					return __( 'Apply', 'sapphireit-editorial-workflow' );
				} )() }
				isBusy={ isRunning }
				canSubmit={ isClearing || isCalendarDate( data.due_date ) }
				isDestructive={ isClearing }
			>
				{ ! isAsking && (
					<Button
						__next40pxDefaultSize
						variant="secondary"
						isDestructive
						onClick={ () => {
							setIsConfirming( false );
							setIsClearing( true );
						} }
						disabled={ isRunning }
					>
						{ __( 'Remove due dates', 'sapphireit-editorial-workflow' ) }
					</Button>
				) }
			</ModalActions>
		</form>
	);
}

/**
 * @param {Object}   props            Props.
 * @param {Object[]} props.items      Rows the action runs on.
 * @param {Function} props.closeModal Closes the modal.
 * @return {Element} Modal content.
 */
export default function SetDueDateModal( { items, closeModal } ) {
	const { onChanged } = useContext( BulkActionContext );

	if ( items.length === 1 ) {
		return (
			<DueDateModal
				items={ items }
				closeModal={ closeModal }
				onChanged={ onChanged }
			/>
		);
	}

	return <BulkDueDateForm items={ items } closeModal={ closeModal } />;
}
