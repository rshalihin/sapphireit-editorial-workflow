/**
 * Confirms a status change for one row, optionally with a workflow comment.
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
import { addComment, updateWorkflow } from '../../api/client';
import { getTransitionConfirmation } from '../../utils/confirmations';

/**
 * Comment field.
 *
 * @type {Object[]}
 */
const COMMENT_FIELDS = [
	{
		id: 'comment',
		type: 'text',
		label: __( 'Comment (optional)', 'sapphireit-editorial-workflow' ),
		description: __( 'Added to the activity history.', 'sapphireit-editorial-workflow' ),
		Edit: { control: 'textarea', rows: 4 },
	},
];

/**
 * Comment form.
 *
 * @type {Object}
 */
const COMMENT_FORM = { layout: { type: 'regular' }, fields: [ 'comment' ] };

/**
 * @param {Object}   props               Props.
 * @param {Object[]} props.items         Rows the action runs on (one).
 * @param {Function} props.closeModal    Closes the modal.
 * @param {string}   props.to            Target status slug.
 * @param {string}   props.submitLabel   Submit button label.
 * @param {boolean}  [props.withComment] Whether to offer a comment.
 * @param {Function} props.onChanged     Refetches the current page.
 * @return {Element} Modal content.
 */
export default function TransitionModal( {
	items,
	closeModal,
	to,
	submitLabel,
	withComment = false,
	onChanged,
} ) {
	const [ item ] = items;
	const [ data, setData ] = useState( { comment: '' } );
	const { run, isBusy, error } = useRowMutation( { closeModal, onChanged } );

	// Offered by the server for this row; the server re-validates on submit.
	const transition = ( item?.available_transitions || [] ).find(
		( entry ) => entry && entry.slug === to
	);

	if ( ! item || ! transition ) {
		return (
			<>
				<p>
					{ __(
						'This action is no longer available for this content.',
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

	const title = item.title || __( '(no title)', 'sapphireit-editorial-workflow' );
	const { message: explanation } = getTransitionConfirmation( transition );

	const submit = ( event ) => {
		event.preventDefault();

		run( async () => {
			// `from` is the status this row showed; a mismatch answers 409.
			await updateWorkflow( item.post_id, {
				from: item.status,
				status: to,
			} );

			const message = withComment ? data.comment.trim() : '';
			const success = sprintf(
				/* translators: 1: Post title, 2: Workflow status label. */
				__( '“%1$s” moved to %2$s.', 'sapphireit-editorial-workflow' ),
				title,
				transition.label
			);

			if ( ! message ) {
				return { success };
			}

			try {
				await addComment( item.post_id, message );
			} catch ( err ) {
				return {
					warning: sprintf(
						/* translators: 1: Workflow status label, 2: Error message. */
						__(
							'Moved to %1$s, but the comment could not be saved: %2$s',
							'sapphireit-editorial-workflow'
						),
						transition.label,
						err.message
					),
				};
			}

			return { success };
		} );
	};

	return (
		<form className="sit-cwm-modal-form" onSubmit={ submit }>
			<p>
				{ sprintf(
					/* translators: 1: Post title, 2: Current workflow status label, 3: Target workflow status label. */
					__( 'Move “%1$s” from %2$s to %3$s?', 'sapphireit-editorial-workflow' ),
					title,
					item.status_label,
					transition.label
				) }
			</p>
			{ explanation && <p>{ explanation }</p> }

			{ withComment && (
				<DataForm
					data={ data }
					fields={ COMMENT_FIELDS }
					form={ COMMENT_FORM }
					onChange={ ( edits ) =>
						setData( ( previous ) => ( { ...previous, ...edits } ) )
					}
				/>
			) }

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error.message }
				</Notice>
			) }

			<ModalActions
				onCancel={ closeModal }
				submitLabel={ submitLabel }
				isBusy={ isBusy }
				isDestructive={ !! transition.is_rollback }
			/>
		</form>
	);
}
