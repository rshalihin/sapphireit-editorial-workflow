/**
 * Assigns or clears the reviewer of one or more rows.
 *
 * A single row uses the row modal (reviewers limited to that post); several
 * rows go through the batch route.
 */

/**
 * WordPress dependencies
 */
import { ComboboxControl, Notice } from '@wordpress/components';
import { useContext, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ModalActions from '../modals/ModalActions';
import ReviewerModal from '../modals/ReviewerModal';
import useReviewerOptions, {
	UNASSIGNED,
} from '../../hooks/useReviewerOptions';
import { BulkActionContext, BulkConfirmation, toBulkRequest } from './shared';

/**
 * @param {Object}   props            Props.
 * @param {Object[]} props.items      Selected rows.
 * @param {Function} props.closeModal Closes the modal.
 * @return {Element} Form.
 */
function BulkReviewerForm( { items, closeModal } ) {
	const { run, isRunning } = useContext( BulkActionContext );
	const { options, isLoading, error, onFilterValueChange } =
		useReviewerOptions();
	const [ value, setValue ] = useState( '' );
	const [ isConfirming, setIsConfirming ] = useState( false );

	const count = items.length;
	const isClearing = value === UNASSIGNED;
	const reviewerName =
		options.find( ( option ) => option.value === value )?.label || '';

	const submit = async ( event ) => {
		event.preventDefault();

		if ( ! value || isRunning ) {
			return;
		}

		// Every bulk change is confirmed, naming the count and the reviewer.
		if ( ! isConfirming ) {
			setIsConfirming( true );
			return;
		}

		const { postIds, labels } = toBulkRequest( items );

		await run(
			'assign_reviewer',
			{ reviewer_id: parseInt( value, 10 ) || 0 },
			postIds,
			{ labels }
		);
		closeModal();
	};

	return (
		<form className="sit-cwm-modal-form" onSubmit={ submit }>
			<p>
				{ sprintf(
					/* translators: %d: Number of selected items. */
					_n(
						'Set the reviewer of %d selected item.',
						'Set the reviewer of %d selected items.',
						count,
						'sapphireit-editorial-workflow'
					),
					count
				) }
			</p>

			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Reviewer', 'sapphireit-editorial-workflow' ) }
				value={ value }
				options={ options }
				onChange={ ( next ) => {
					setValue( next || '' );
					setIsConfirming( false );
				} }
				onFilterValueChange={ onFilterValueChange }
				isLoading={ isLoading }
				allowReset={ false }
			/>
			{ error && <p className="sit-cwm-error">{ error.message }</p> }

			{ isConfirming && (
				<BulkConfirmation
					message={
						isClearing
							? sprintf(
									/* translators: %d: Number of items. */
									_n(
										'Remove the reviewer from %d item?',
										'Remove the reviewer from %d items?',
										count,
										'sapphireit-editorial-workflow'
									),
									count
							  )
							: sprintf(
									/* translators: 1: Reviewer display name, 2: Number of items. */
									_n(
										'Assign %1$s as reviewer of %2$d item?',
										'Assign %1$s as reviewer of %2$d items?',
										count,
										'sapphireit-editorial-workflow'
									),
									reviewerName,
									count
							  )
					}
				/>
			) }

			{ ! options.length && ! isLoading && (
				<Notice status="info" isDismissible={ false }>
					{ __( 'No reviewers are available.', 'sapphireit-editorial-workflow' ) }
				</Notice>
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
				canSubmit={ !! value }
				isDestructive={ isClearing }
			/>
		</form>
	);
}

/**
 * @param {Object}   props            Props.
 * @param {Object[]} props.items      Rows the action runs on.
 * @param {Function} props.closeModal Closes the modal.
 * @return {Element} Modal content.
 */
export default function AssignReviewerModal( { items, closeModal } ) {
	const { onChanged } = useContext( BulkActionContext );

	if ( items.length === 1 ) {
		return (
			<ReviewerModal
				items={ items }
				closeModal={ closeModal }
				onChanged={ onChanged }
			/>
		);
	}

	return <BulkReviewerForm items={ items } closeModal={ closeModal } />;
}
