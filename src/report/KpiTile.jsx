/**
 * One KPI tile of the governance report.
 */

/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';

/**
 * The label, then a large value and optional help text. The value is
 * described by the label, so screen readers announce what it counts.
 *
 * @param {Object} props        Props.
 * @param {string} props.label  Tile label.
 * @param {string} props.value  Formatted value.
 * @param {string} [props.help] Help text under the value.
 * @param {string} [props.tone] `danger` colours the value.
 * @return {Element} Tile.
 */
export default function KpiTile( { label, value, help, tone } ) {
	const id = useInstanceId( KpiTile, 'sit-cwm-report-tile' );

	return (
		<div
			className={
				'sit-cwm-report__tile' +
				( tone ? ` sit-cwm-report__tile--${ tone }` : '' )
			}
		>
			<div id={ id } className="sit-cwm-report__tile-label">
				{ label }
			</div>
			<div className="sit-cwm-report__tile-value" aria-describedby={ id }>
				{ value }
			</div>
			{ help && <div className="sit-cwm-report__tile-help">{ help }</div> }
		</div>
	);
}
