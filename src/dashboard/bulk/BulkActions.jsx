/**
 * Bulk actions for the dashboard: the DataViews action definitions, the
 * context their modals run in, and the summary notice of the last batch.
 *
 * Every `isEligible` predicate is a UX filter only. The batch route authorizes
 * and validates each post on its own and reports the ones it refused.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import AssignReviewerModal from './AssignReviewerModal';
import ChangeStatusModal from './ChangeStatusModal';
import SetDueDateModal from './SetDueDateModal';
import { BulkActionContext } from './shared';

/**
 * Builds the bulk-capable DataViews actions.
 *
 * The modal components are module-level, so rebuilding the definitions (e.g.
 * when `isRunning` changes) never remounts an open modal.
 *
 * @param {Object}  [options]           Options.
 * @param {boolean} [options.isRunning] Whether a batch is running; disables the actions.
 * @return {Object[]} DataViews actions.
 */
export function buildBulkActions( { isRunning = false } = {} ) {
	return [
		{
			id: 'change-status',
			label: __( 'Change status', 'sapphireit-editorial-workflow' ),
			modalHeader: __( 'Change workflow status', 'sapphireit-editorial-workflow' ),
			supportsBulk: true,
			disabled: isRunning,
			isEligible: ( item ) =>
				Array.isArray( item?.available_transitions ) &&
				item.available_transitions.length > 0,
			RenderModal: ChangeStatusModal,
		},
		{
			id: 'assign-reviewer',
			label: __( 'Assign reviewer', 'sapphireit-editorial-workflow' ),
			modalHeader: __( 'Assign reviewer', 'sapphireit-editorial-workflow' ),
			supportsBulk: true,
			disabled: isRunning,
			isEligible: ( item ) => !! item?.capabilities?.can_assign_reviewer,
			RenderModal: AssignReviewerModal,
		},
		{
			id: 'set-due-date',
			label: __( 'Set due date', 'sapphireit-editorial-workflow' ),
			modalHeader: __( 'Set due date', 'sapphireit-editorial-workflow' ),
			modalSize: 'small',
			supportsBulk: true,
			disabled: isRunning,
			isEligible: ( item ) => !! item?.capabilities?.can_set_due_date,
			RenderModal: SetDueDateModal,
		},
	];
}

/**
 * Summary of the last batch, with the failures and their reasons on demand.
 *
 * @param {Object}   props           Props.
 * @param {?Object}  props.result    Result from `useBulkAction()`.
 * @param {Function} props.onDismiss Dismisses the notice.
 * @return {?Element} Notice.
 */
export function BulkResultNotice( { result, onDismiss } ) {
	const [ isExpanded, setIsExpanded ] = useState( false );

	if ( ! result ) {
		return null;
	}

	const labels = result.labels || {};

	return (
		<Notice
			className="sit-cwm-bulk-result"
			status={ result.status }
			onRemove={ onDismiss }
		>
			<p>{ result.message }</p>
			{ result.failed.length > 0 && (
				<>
					<Button
						variant="link"
						aria-expanded={ isExpanded }
						onClick={ () => setIsExpanded( ( value ) => ! value ) }
					>
						{ isExpanded
							? __( 'Hide details', 'sapphireit-editorial-workflow' )
							: __( 'Show details', 'sapphireit-editorial-workflow' ) }
					</Button>
					{ isExpanded && (
						<ul className="sit-cwm-bulk-failures">
							{ result.failed.map( ( entry ) => (
								<li key={ entry.post_id }>
									{ sprintf(
										/* translators: 1: Post title, 2: Reason it was not updated. */
										_x(
											'%1$s: %2$s',
											'bulk action failure',
											'sapphireit-editorial-workflow'
										),
										labels[ entry.post_id ] ||
											sprintf(
												/* translators: %d: Post ID. */
												__( 'Item #%d', 'sapphireit-editorial-workflow' ),
												entry.post_id
											),
										entry.message
									) }
								</li>
							) ) }
						</ul>
					) }
				</>
			) }
		</Notice>
	);
}

/**
 * Provides the bulk runner to the action modals and shows the last result.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.bulk      State from `useBulkAction()`.
 * @param {Function} props.onChanged Refetches the current page (single-row paths).
 * @param {Element}  props.children  The DataViews table.
 * @return {Element} Provider.
 */
export default function BulkActions( { bulk, onChanged, children } ) {
	const { run, isRunning, result, clearResult } = bulk;
	const value = useMemo(
		() => ( { run, isRunning, onChanged } ),
		[ run, isRunning, onChanged ]
	);

	return (
		<BulkActionContext.Provider value={ value }>
			<BulkResultNotice
				key={ result?.id }
				result={ result }
				onDismiss={ clearResult }
			/>
			{ children }
		</BulkActionContext.Provider>
	);
}
