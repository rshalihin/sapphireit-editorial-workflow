/**
 * Moves one or more rows to a workflow status through the batch route.
 */

/**
 * WordPress dependencies
 */
import { Button, SelectControl } from '@wordpress/components';
import { useContext, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ModalActions from '../modals/ModalActions';
import {
	BulkActionContext,
	BulkConfirmation,
	canMoveTo,
	reachableStatuses,
	toBulkRequest,
} from './shared';

/**
 * @param {Object}   props            Props.
 * @param {Object[]} props.items      Selected rows.
 * @param {Function} props.closeModal Closes the modal.
 * @return {Element} Modal content.
 */
export default function ChangeStatusModal( { items, closeModal } ) {
	const { run, isRunning } = useContext( BulkActionContext );
	const options = useMemo( () => reachableStatuses( items ), [ items ] );
	const [ to, setTo ] = useState( () => options[ 0 ]?.slug || '' );
	const [ isConfirming, setIsConfirming ] = useState( false );

	const count = items.length;
	const selected = options.find( ( option ) => option.slug === to );

	if ( ! selected ) {
		return (
			<>
				<p>
					{ __(
						'None of the selected content can change status right now.',
						'sapphireit-editorial-workflow'
					) }
				</p>
				<div className="sit-cwm-modal-actions">
					<Button
						__next40pxDefaultSize
						variant="primary"
						onClick={ closeModal }
					>
						{ __( 'Close', 'sapphireit-editorial-workflow' ) }
					</Button>
				</div>
			</>
		);
	}

	const blocked = items.filter( ( item ) => ! canMoveTo( item, to ) ).length;

	const submit = async ( event ) => {
		event.preventDefault();

		if ( isRunning ) {
			return;
		}

		// Every bulk change is confirmed, naming the count and the target.
		if ( ! isConfirming ) {
			setIsConfirming( true );
			return;
		}

		const { postIds, labels } = toBulkRequest( items );

		// No `from`: the server starts from each post's own current status.
		await run( 'change_status', { status: to }, postIds, { labels } );
		closeModal();
	};

	return (
		<form className="sit-cwm-modal-form" onSubmit={ submit }>
			<p>
				{ sprintf(
					/* translators: %d: Number of selected items. */
					_n(
						'Change the workflow status of %d selected item.',
						'Change the workflow status of %d selected items.',
						count,
						'sapphireit-editorial-workflow'
					),
					count
				) }
			</p>

			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'New status', 'sapphireit-editorial-workflow' ) }
				value={ to }
				options={ options.map( ( option ) => ( {
					value: option.slug,
					label: option.label,
				} ) ) }
				onChange={ ( value ) => {
					setTo( value );
					setIsConfirming( false );
				} }
				disabled={ isRunning }
			/>

			{ blocked > 0 && (
				<p className="sit-cwm-bulk-hint">
					{ sprintf(
						/* translators: 1: Number of items, 2: Workflow status label. */
						_n(
							'%1$d item cannot move to %2$s right now and will be reported as not updated.',
							'%1$d items cannot move to %2$s right now and will be reported as not updated.',
							blocked,
							'sapphireit-editorial-workflow'
						),
						blocked,
						selected.label
					) }
				</p>
			) }

			{ isConfirming && (
				<BulkConfirmation
					message={ sprintf(
						/* translators: 1: Number of items, 2: Workflow status label. */
						_n(
							'Move %1$d item to %2$s?',
							'Move %1$d items to %2$s?',
							count,
							'sapphireit-editorial-workflow'
						),
						count,
						selected.label
					) }
				/>
			) }

			<ModalActions
				onCancel={ closeModal }
				submitLabel={
					isConfirming
						? sprintf(
								/* translators: %d: Number of items. */
								_n(
									'Yes, update %d item',
									'Yes, update %d items',
									count,
									'sapphireit-editorial-workflow'
								),
								count
						  )
						: __( 'Apply', 'sapphireit-editorial-workflow' )
				}
				isBusy={ isRunning }
				isDestructive={ selected.isRollback }
			/>
		</form>
	);
}
