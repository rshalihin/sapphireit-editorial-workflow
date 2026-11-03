/**
 * An AI-use breakdown table of the governance report.
 */

/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { AI_ORIGIN_VALUES, aiOriginLabel } from '../utils/aiOrigin';

/**
 * Row keys, in display order, and their labels.
 *
 * @param {boolean} withNotRecorded Whether to add the "Not recorded" row.
 * @return {Array<Array<string>>} `[ key, label ]` pairs.
 */
function rowsFor( withNotRecorded ) {
	const rows = [ ...AI_ORIGIN_VALUES, 'not_declared' ].map( ( key ) => [
		key,
		aiOriginLabel( key ),
	] );

	if ( withNotRecorded ) {
		rows.push( [
			'not_recorded',
			__( 'Not recorded', 'sapphireit-editorial-workflow' ),
		] );
	}

	return rows;
}

/**
 * A plain table of AI use → count, titled by a heading it is labelled with.
 *
 * @param {Object}           props                   Props.
 * @param {string}           props.title             Section title.
 * @param {Object}           props.counts            Key → count, from the route.
 * @param {Function}         props.formatNumber      Formats a count.
 * @param {boolean}          [props.withNotRecorded] Adds the "Not recorded" row.
 * @param {string}           [props.footnote]        Text under the table.
 * @param {Element|string}   [props.empty]           Shown instead of the table.
 * @return {Element} Breakdown.
 */
export default function Breakdown( {
	title,
	counts,
	formatNumber,
	withNotRecorded = false,
	footnote,
	empty,
} ) {
	const id = useInstanceId( Breakdown, 'sit-cwm-report-breakdown' );
	const values = counts || {};

	return (
		<div className="sit-cwm-report__breakdown">
			<h2 id={ id } className="sit-cwm-report__section-title">
				{ title }
			</h2>
			{ empty ? (
				<p className="sit-cwm-report__empty">{ empty }</p>
			) : (
				<table
					className="widefat striped sit-cwm-report__table"
					aria-labelledby={ id }
				>
					<thead>
						<tr>
							<th scope="col">
								{ __(
									'AI use',
									'sapphireit-editorial-workflow'
								) }
							</th>
							<th scope="col" className="sit-cwm-report__count">
								{ __(
									'Count',
									'sapphireit-editorial-workflow'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rowsFor( withNotRecorded ).map(
							( [ key, label ] ) => (
								<tr key={ key }>
									<th scope="row">{ label }</th>
									<td className="sit-cwm-report__count">
										{ formatNumber(
											Number( values[ key ] ) || 0
										) }
									</td>
								</tr>
							)
						) }
					</tbody>
				</table>
			) }
			{ footnote && (
				<p className="sit-cwm-report__footnote">{ footnote }</p>
			) }
		</div>
	);
}
