/**
 * Footer buttons shared by the row action modals.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * @param {Object}   props                 Props.
 * @param {Function} props.onCancel        Closes the modal.
 * @param {string}   props.submitLabel     Label of the submit button.
 * @param {boolean}  props.isBusy          Whether a request is running.
 * @param {boolean}  [props.canSubmit]     Whether submitting is possible.
 * @param {boolean}  [props.isDestructive] Whether the action is a rollback.
 * @param {Element}  [props.children]      Extra buttons, before Cancel.
 * @return {Element} Actions.
 */
export default function ModalActions( {
	onCancel,
	submitLabel,
	isBusy,
	canSubmit = true,
	isDestructive = false,
	children,
} ) {
	return (
		<div className="sit-cwm-modal-actions">
			{ children }
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
				type="submit"
				variant="primary"
				isDestructive={ isDestructive }
				isBusy={ isBusy }
				disabled={ isBusy || ! canSubmit }
				accessibleWhenDisabled
			>
				{ submitLabel }
			</Button>
		</div>
	);
}
