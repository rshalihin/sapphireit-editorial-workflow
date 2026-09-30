/**
 * Coloured workflow status badge for dashboard rows.
 *
 * The label is always shown; the colour swatch only repeats it.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';
import { caution, Icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { FALLBACK_STATUS_COLOR, getStatusDefinition } from '../utils/format';

/**
 * @param {Object}  props             Props.
 * @param {string}  props.status      Status slug.
 * @param {string}  [props.label]     Label from the REST row.
 * @param {boolean} [props.isUnknown] Whether the stored status is no longer
 *                                    registered (the default is shown).
 * @return {Element} Badge.
 */
export default function StatusBadge( { status, label, isUnknown = false } ) {
	const definition = getStatusDefinition( status );
	const color = definition?.color || FALLBACK_STATUS_COLOR;
	const unknownText = __(
		'The previous workflow status is no longer available.',
		'sapphireit-editorial-workflow'
	);

	return (
		<span
			className="sit-cwm-status-badge"
			data-status={ status }
			title={ isUnknown ? unknownText : undefined }
		>
			<span
				className="sit-cwm-status-swatch"
				style={ { backgroundColor: color } }
				aria-hidden="true"
			/>
			<span className="sit-cwm-status-text">
				{ label || definition?.label || status }
			</span>
			{ isUnknown && (
				<span className="sit-cwm-status-unknown">
					<Icon icon={ caution } size={ 16 } />
					<span className="screen-reader-text">{ unknownText }</span>
				</span>
			) }
		</span>
	);
}
