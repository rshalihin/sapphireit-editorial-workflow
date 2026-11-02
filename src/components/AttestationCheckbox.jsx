/**
 * The approver's "I reviewed this" confirmation.
 *
 * Gates Approve in the client only for convenience; the server requires
 * `attested` on its own (F10).
 */

/**
 * WordPress dependencies
 */
import { CheckboxControl } from '@wordpress/components';

/**
 * Internal dependencies
 */
import './attestation.scss';

/**
 * @param {Object}   props            Props.
 * @param {string}   props.statement  Statement the approver confirms.
 * @param {boolean}  props.checked    Whether it is ticked.
 * @param {Function} props.onChange   Receives the new checked state.
 * @param {boolean}  [props.disabled] Whether it can be changed.
 * @return {Element} Checkbox.
 */
export default function AttestationCheckbox( {
	statement,
	checked,
	onChange,
	disabled = false,
} ) {
	return (
		<div className="sit-cwm-attest">
			<CheckboxControl
				__nextHasNoMarginBottom
				label={ statement }
				checked={ !! checked }
				onChange={ onChange }
				disabled={ disabled }
			/>
		</div>
	);
}
