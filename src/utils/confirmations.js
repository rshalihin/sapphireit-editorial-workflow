/**
 * Which workflow moves ask for confirmation, and the dialog copy for each.
 *
 * Presentation only: whether a move is allowed is decided by the server.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getStatusDefinition } from './format';

/**
 * Target status that approves content (D3).
 *
 * @type {string}
 */
const APPROVED = 'approved';

/**
 * Whether a transition asks for confirmation: approving, moving into a final
 * status (Published) and every rollback.
 *
 * @param {Object} transition `{ slug, label, is_rollback }`.
 * @return {boolean} True when a confirm dialog is shown first.
 */
export function requiresConfirmation( transition ) {
	return (
		!! transition &&
		( !! transition.is_rollback ||
			transition.slug === APPROVED ||
			!! getStatusDefinition( transition.slug )?.is_final )
	);
}

/**
 * Dialog copy for a transition.
 *
 * @param {Object} transition `{ slug, label, is_rollback }`.
 * @return {{title: string, message: string, confirmLabel: string, isDestructive: boolean}} Copy.
 */
export function getTransitionConfirmation( transition ) {
	const label =
		transition.label ||
		getStatusDefinition( transition.slug )?.label ||
		transition.slug;

	if ( transition.is_rollback ) {
		return {
			title: sprintf(
				/* translators: %s: Target workflow status label. */
				__( 'Move to %s?', 'sapphireit-editorial-workflow' ),
				label
			),
			message: __(
				'This sends the content back to an earlier workflow stage.',
				'sapphireit-editorial-workflow'
			),
			confirmLabel: sprintf(
				/* translators: %s: Target workflow status label. */
				__( 'Move to %s', 'sapphireit-editorial-workflow' ),
				label
			),
			isDestructive: true,
		};
	}

	if ( transition.slug === APPROVED ) {
		return {
			title: __( 'Approve content?', 'sapphireit-editorial-workflow' ),
			message: __(
				'This will mark the content as approved.',
				'sapphireit-editorial-workflow'
			),
			confirmLabel: __( 'Approve', 'sapphireit-editorial-workflow' ),
			isDestructive: false,
		};
	}

	if ( getStatusDefinition( transition.slug )?.is_final ) {
		return {
			title: __( 'Publish content?', 'sapphireit-editorial-workflow' ),
			message: __(
				'This will mark the workflow as published and complete the cycle. It does not change the post’s WordPress publish status.',
				'sapphireit-editorial-workflow'
			),
			confirmLabel: __( 'Publish', 'sapphireit-editorial-workflow' ),
			isDestructive: false,
		};
	}

	return {
		title: sprintf(
			/* translators: %s: Target workflow status label. */
			__( 'Move to %s?', 'sapphireit-editorial-workflow' ),
			label
		),
		message: '',
		confirmLabel: sprintf(
			/* translators: %s: Target workflow status label. */
			__( 'Move to %s', 'sapphireit-editorial-workflow' ),
			label
		),
		isDestructive: false,
	};
}
