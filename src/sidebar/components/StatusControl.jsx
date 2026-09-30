/**
 * Read-only badge for the current workflow status.
 *
 * Status changes go through `TransitionActions`, never a free-form select: a
 * dropdown of every status invites requests the server would reject.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { FALLBACK_STATUS_COLOR, getStatusDefinition } from '../../utils/format';

/**
 * @param {Object} props         Props.
 * @param {string} props.status  Status slug.
 * @param {string} [props.label] Label from the workflow response.
 * @return {Element} Badge.
 */
export default function StatusControl( { status, label } ) {
	const definition = getStatusDefinition( status );
	const color = definition?.color || FALLBACK_STATUS_COLOR;
	const text = label || definition?.label || status;

	return (
		<div className="sit-cwm-field sit-cwm-status">
			<span className="sit-cwm-field-label">
				{ __( 'Status', 'sapphireit-editorial-workflow' ) }
			</span>
			<span className="sit-cwm-status-badge" data-status={ status }>
				<span
					className="sit-cwm-status-swatch"
					style={ { backgroundColor: color } }
					aria-hidden="true"
				/>
				<span className="sit-cwm-status-text">{ text }</span>
			</span>
			{ definition?.description && (
				<p className="sit-cwm-help">{ definition.description }</p>
			) }
		</div>
	);
}
