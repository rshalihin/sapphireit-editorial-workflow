/**
 * One KPI tile of the governance report.
 */

/**
 * WordPress dependencies
 */
import { Card, CardBody } from '@wordpress/components';
import { useInstanceId } from '@wordpress/compose';

/**
 * A large value with its label underneath and optional help text. The value
 * is described by the label, so screen readers announce what it counts.
 *
 * @param {Object} props       Props.
 * @param {string} props.label Tile label.
 * @param {string} props.value Formatted value.
 * @param {string} [props.help] Help text under the label.
 * @return {Element} Tile.
 */
export default function KpiTile( { label, value, help } ) {
	const id = useInstanceId( KpiTile, 'sit-cwm-report-tile' );

	return (
		<Card className="sit-cwm-report__tile-card">
			<CardBody className="sit-cwm-report__tile">
				<div
					className="sit-cwm-report__tile-value"
					aria-describedby={ id }
				>
					{ value }
				</div>
				<div id={ id } className="sit-cwm-report__tile-label">
					{ label }
				</div>
				{ help && (
					<div className="sit-cwm-report__tile-help">{ help }</div>
				) }
			</CardBody>
		</Card>
	);
}
