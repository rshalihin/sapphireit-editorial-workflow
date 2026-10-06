/**
 * DataViews actions: single-row quick actions plus the bulk-capable ones.
 *
 * Every `isEligible` predicate is a UX filter only: it hides actions the row's
 * server-computed `available_transitions` and `capabilities` rule out. Each
 * request is authorized again by the REST API and WorkflowManager, so a stale
 * or tampered row can never do more than the server allows.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { pencil } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { buildBulkActions } from './bulk/BulkActions';
import { canMoveTo } from './bulk/shared';
import TransitionModal from './modals/TransitionModal';
import { isSafeUrl } from '../utils/format';

export { canMoveTo };

/**
 * Builds the dashboard actions.
 *
 * @param {Object}   options                 Options.
 * @param {Function} options.onChanged       Refetches the current page after a change.
 * @param {boolean}  [options.isBulkRunning] Whether a batch is running.
 * @return {Object[]} DataViews actions.
 */
export default function buildActions( { onChanged, isBulkRunning = false } ) {
	function ApproveModal( props ) {
		return (
			<TransitionModal
				{ ...props }
				to="approved"
				submitLabel={ __( 'Approve', 'sapphireit-editorial-workflow' ) }
				onChanged={ onChanged }
			/>
		);
	}

	function RequestChangesModal( props ) {
		return (
			<TransitionModal
				{ ...props }
				to="needs_changes"
				submitLabel={ __( 'Request changes', 'sapphireit-editorial-workflow' ) }
				withComment
				onChanged={ onChanged }
			/>
		);
	}

	return [
		{
			id: 'edit',
			label: __( 'Edit', 'sapphireit-editorial-workflow' ),
			icon: pencil,
			isPrimary: true,
			isEligible: ( item ) => isSafeUrl( item.edit_link ),
			callback: ( [ item ] ) => {
				if ( item && isSafeUrl( item.edit_link ) ) {
					window.location.assign( item.edit_link );
				}
			},
		},
		{
			id: 'approve',
			label: __( 'Approve', 'sapphireit-editorial-workflow' ),
			modalHeader: __( 'Approve content?', 'sapphireit-editorial-workflow' ),
			isEligible: ( item ) => canMoveTo( item, 'approved' ),
			RenderModal: ApproveModal,
		},
		{
			id: 'request-changes',
			label: __( 'Request changes', 'sapphireit-editorial-workflow' ),
			modalHeader: __( 'Request changes?', 'sapphireit-editorial-workflow' ),
			isEligible: ( item ) => canMoveTo( item, 'needs_changes' ),
			RenderModal: RequestChangesModal,
		},
		...buildBulkActions( { isRunning: isBulkRunning } ),
	];
}
