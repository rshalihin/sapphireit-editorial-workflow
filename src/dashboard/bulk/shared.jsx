/**
 * Pieces shared by the bulk action modals.
 */

/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { createContext } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getBootstrap, getStatusDefinition } from '../../utils/format';

/**
 * Bulk runner for the modals, provided by `<BulkActions />`.
 *
 * Read from context rather than captured in the action definitions, so the
 * definitions (and the modal components) stay stable while a batch runs.
 *
 * @type {Object}
 */
export const BulkActionContext = createContext( {
	run: async () => null,
	isRunning: false,
	onChanged: () => {},
} );

/**
 * Whether the server offered a transition to a status for a row.
 *
 * @param {Object} item Row.
 * @param {string} slug Target status slug.
 * @return {boolean} True when offered.
 */
export function canMoveTo( item, slug ) {
	return (
		Array.isArray( item?.available_transitions ) &&
		item.available_transitions.some(
			( transition ) => transition && transition.slug === slug
		)
	);
}

/**
 * Statuses reachable from at least one of the rows, in registry order.
 *
 * A UX filter only: each post is validated again by the server, which reports
 * rows that cannot make the move as failures.
 *
 * @param {Object[]} items Rows.
 * @return {Array<{slug: string, label: string, isRollback: boolean}>} Statuses.
 */
export function reachableStatuses( items ) {
	const found = new Map();

	( Array.isArray( items ) ? items : [] ).forEach( ( item ) => {
		( Array.isArray( item?.available_transitions )
			? item.available_transitions
			: []
		).forEach( ( transition ) => {
			if ( ! transition || typeof transition.slug !== 'string' ) {
				return;
			}

			const previous = found.get( transition.slug );

			found.set( transition.slug, {
				slug: transition.slug,
				label:
					getStatusDefinition( transition.slug )?.label ||
					previous?.label ||
					transition.label ||
					transition.slug,
				isRollback: !! (
					previous?.isRollback || transition.is_rollback
				),
			} );
		} );
	} );

	const { statuses } = getBootstrap();
	const order = ( Array.isArray( statuses ) ? statuses : [] )
		.filter( Boolean )
		.map( ( status ) => status.slug );
	const position = ( slug ) =>
		order.includes( slug ) ? order.indexOf( slug ) : order.length;

	return [ ...found.values() ].sort(
		( a, b ) => position( a.slug ) - position( b.slug )
	);
}

/**
 * Post ids and titles of the rows an action runs on.
 *
 * @param {Object[]} items Rows.
 * @return {{postIds: number[], labels: Object<number, string>}} Request data.
 */
export function toBulkRequest( items ) {
	const postIds = [];
	const labels = {};

	( Array.isArray( items ) ? items : [] ).forEach( ( item ) => {
		if ( item && Number.isInteger( item.post_id ) && item.post_id > 0 ) {
			postIds.push( item.post_id );
			labels[ item.post_id ] =
				item.title || __( '(no title)', 'sapphireit-editorial-workflow' );
		}
	} );

	return { postIds, labels };
}

/**
 * The second step of a destructive bulk action.
 *
 * @param {Object} props         Props.
 * @param {string} props.message Confirmation text naming the item count.
 * @return {Element} Warning.
 */
export function BulkConfirmation( { message } ) {
	return (
		<Notice
			className="sit-cwm-bulk-confirm"
			status="warning"
			isDismissible={ false }
		>
			{ message }
		</Notice>
	);
}
