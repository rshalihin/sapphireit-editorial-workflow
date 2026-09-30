/**
 * Shared confirmation dialog: Approve, Publish, rollbacks and bulk actions.
 *
 * Built on `Modal`, which traps focus, closes on Escape and returns focus to
 * the element that opened it when it unmounts.
 */

/**
 * WordPress dependencies
 */
import { Button, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import './confirm-dialog.scss';

/**
 * @param {Object}   props                 Props.
 * @param {string}   props.title           Question, e.g. "Approve content?".
 * @param {string}   props.confirmLabel    Label of the confirming button.
 * @param {Function} props.onConfirm       Runs the action.
 * @param {Function} props.onCancel        Closes without acting (also Escape).
 * @param {boolean}  [props.isDestructive] Styles the confirm button as destructive.
 * @param {boolean}  [props.isBusy]        Blocks both buttons while running.
 * @param {Element}  [props.children]      Explanation of what will happen.
 * @return {Element} Dialog.
 */
export default function ConfirmDialog( {
	title,
	confirmLabel,
	onConfirm,
	onCancel,
	isDestructive = false,
	isBusy = false,
	children,
} ) {
	return (
		<Modal
			className="sit-cwm-confirm"
			title={ title }
			size="small"
			// Cancel gets focus first, so Enter never confirms by accident.
			focusOnMount="firstContentElement"
			onRequestClose={ () => {
				if ( ! isBusy ) {
					onCancel();
				}
			} }
		>
			{ children && (
				<div className="sit-cwm-confirm-message">{ children }</div>
			) }
			<div className="sit-cwm-confirm-actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
					disabled={ isBusy }
				>
					{ __( 'Cancel', 'sapphireit-editorial-workflow' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive={ isDestructive }
					isBusy={ isBusy }
					disabled={ isBusy }
					accessibleWhenDisabled
					onClick={ onConfirm }
				>
					{ confirmLabel }
				</Button>
			</div>
		</Modal>
	);
}
