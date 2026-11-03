/**
 * An AI-use breakdown of the governance report.
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
 * Rows that mean "unknown" and get the neutral bar colour.
 *
 * @type {string[]}
 */
const NEUTRAL = [ 'not_declared', 'not_recorded' ];

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
 * Horizontal bars of AI use → count, titled by a heading. The bars are
 * decoration; the same figures are in a table for screen readers, labelled
 * by the heading.
 *
 * @param {Object}         props                   Props.
 * @param {string}         props.title             Section title.
 * @param {Object}         props.counts            Key → count, from the route.
 * @param {Function}       props.formatNumber      Formats a count.
 * @param {Function}       [props.totalLabel]      Total → text beside the title.
 * @param {boolean}        [props.withNotRecorded] Adds the "Not recorded" row.
 * @param {string}         [props.footnote]        Text under the bars.
 * @param {Element|string} [props.empty]           Shown instead of the bars.
 * @return {Element} Breakdown.
 */
export default function Breakdown( {
	title,
	counts,
	formatNumber,
	totalLabel,
	withNotRecorded = false,
	footnote,
	empty,
} ) {
	const id = useInstanceId( Breakdown, 'sit-cwm-report-breakdown' );
	const values = counts || {};
	const rows = rowsFor( withNotRecorded ).map( ( [ key, label ] ) => [
		key,
		label,
		Number( values[ key ] ) || 0,
	] );
	const total = rows.reduce( ( all, row ) => all + row[ 2 ], 0 );
	const max = Math.max( 1, ...rows.map( ( row ) => row[ 2 ] ) );
	const percent = new Intl.NumberFormat( undefined, {
		style: 'percent',
		maximumFractionDigits: 0,
	} );

	return (
		<div className="sit-cwm-report__breakdown">
			<div className="sit-cwm-report__breakdown-head">
				<h2 id={ id } className="sit-cwm-report__section-title">
					{ title }
				</h2>
				{ ! empty && totalLabel && (
					<span className="sit-cwm-report__breakdown-total">
						{ totalLabel( total ) }
					</span>
				) }
			</div>
			{ empty ? (
				<p className="sit-cwm-report__empty">{ empty }</p>
			) : (
				<>
					<div className="sit-cwm-report__bars" aria-hidden="true">
						{ rows.map( ( [ key, label, count ] ) => (
							<div key={ key } className="sit-cwm-report__bar-row">
								<span className="sit-cwm-report__bar-label">
									{ label }
								</span>
								<span className="sit-cwm-report__bar-track">
									<span
										className={
											'sit-cwm-report__bar-fill' +
											( NEUTRAL.includes( key )
												? ' is-neutral'
												: '' )
										}
										style={ {
											width: `${ ( count / max ) * 100 }%`,
										} }
									/>
								</span>
								<span className="sit-cwm-report__bar-value">
									{ formatNumber( count ) }
									{ total > 0 &&
										` · ${ percent.format(
											count / total
										) }` }
								</span>
							</div>
						) ) }
					</div>
					<table
						className="screen-reader-text"
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
								<th scope="col">
									{ __(
										'Count',
										'sapphireit-editorial-workflow'
									) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( [ key, label, count ] ) => (
								<tr key={ key }>
									<th scope="row">{ label }</th>
									<td>{ formatNumber( count ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</>
			) }
			{ footnote && (
				<p className="sit-cwm-report__footnote">{ footnote }</p>
			) }
		</div>
	);
}
