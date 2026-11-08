/**
 * The dashboard's summary tiles (design A, 20b) over
 * `GET /sit-cwm/v1/posts/summary`.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getPostsSummary, isAbortError } from '../api/client';
import { FALLBACK_STATUS_COLOR, getStatusDefinition } from '../utils/format';

/**
 * A status filter, as DataViews stores it.
 *
 * @param {string} slug Status slug.
 * @return {Object} DataViews filter.
 */
const statusFilter = ( slug ) => statusesFilter( [ slug ] );

/**
 * A filter on several statuses, as DataViews stores it.
 *
 * @param {string[]} slugs Status slugs.
 * @return {Object} DataViews filter.
 */
const statusesFilter = ( slugs ) => ( {
	field: 'status',
	operator: 'isAny',
	value: slugs,
} );

/**
 * The tiles, in display order. Every count is the server's.
 *
 * @param {Object} summary Route response.
 * @return {Array<Object>} Tile definitions.
 */
function tilesFor( summary ) {
	const count = ( key ) => Number( summary[ key ] ) || 0;
	const mine = count( 'in_review_mine' );
	// An older server sends no list.
	const reviewStatuses =
		Array.isArray( summary.review_statuses ) &&
		summary.review_statuses.length
			? summary.review_statuses
			: [ 'review' ];

	return [
		{
			key: 'in_review',
			label: __( 'In review', 'sapphireit-editorial-workflow' ),
			value: count( 'in_review' ),
			help: sprintf(
				/* translators: %s: number of items in Review assigned to the current user. */
				_n(
					'%s assigned to you',
					'%s assigned to you',
					mine,
					'sapphireit-editorial-workflow'
				),
				new Intl.NumberFormat().format( mine )
			),
			status: 'review',
			filter: statusesFilter( reviewStatuses ),
		},
		{
			key: 'needs_changes',
			label: __( 'Needs changes', 'sapphireit-editorial-workflow' ),
			value: count( 'needs_changes' ),
			help: __( 'Waiting on the author', 'sapphireit-editorial-workflow' ),
			status: 'needs_changes',
			filter: statusFilter( 'needs_changes' ),
		},
		{
			key: 'overdue',
			label: __( 'Overdue', 'sapphireit-editorial-workflow' ),
			value: count( 'overdue' ),
			help: __( 'Past their due date', 'sapphireit-editorial-workflow' ),
			tone: 'danger',
			filter: { field: 'is_overdue', operator: 'is', value: true },
		},
		{
			key: 'approved',
			label: __(
				'Approved, not published',
				'sapphireit-editorial-workflow'
			),
			value: count( 'approved' ),
			help: __( 'Ready to go live', 'sapphireit-editorial-workflow' ),
			status: 'approved',
			filter: statusFilter( 'approved' ),
		},
	];
}

/**
 * Clock icon of the Overdue tile.
 *
 * @return {Element} Icon.
 */
function ClockIcon() {
	return (
		<svg
			width="14"
			height="14"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth="2"
			strokeLinecap="round"
			aria-hidden="true"
			focusable="false"
		>
			<circle cx="12" cy="12" r="9" />
			<path d="M12 7v5l3 2" />
		</svg>
	);
}

/**
 * Four count tiles; each is a button that filters the table to what it
 * counts. Hidden until the counts load, and when they cannot be loaded: the
 * table below stays the source of truth.
 *
 * @param {Object}   props            Props.
 * @param {number}   props.refreshKey Changes whenever the counts may be stale.
 * @param {Function} props.onSelect   Receives the tile's DataViews filter.
 * @return {Element|null} Tiles.
 */
export default function SummaryTiles( { refreshKey, onSelect } ) {
	const [ summary, setSummary ] = useState( null );

	useEffect( () => {
		const controller = new window.AbortController();

		getPostsSummary( { signal: controller.signal } )
			.then( ( result ) => setSummary( result ) )
			.catch( ( error ) => {
				if ( ! isAbortError( error ) ) {
					setSummary( null );
				}
			} );

		return () => controller.abort();
	}, [ refreshKey ] );

	if ( ! summary ) {
		return null;
	}

	const format = new Intl.NumberFormat();

	return (
		<ul
			className="sit-cwm-summary"
			aria-label={ __( 'Workflow summary', 'sapphireit-editorial-workflow' ) }
		>
			{ tilesFor( summary ).map( ( tile ) => (
				<li key={ tile.key }>
					<button
						type="button"
						className={
							'sit-cwm-summary__tile' +
							( tile.tone ? ` is-${ tile.tone }` : '' )
						}
						onClick={ () => onSelect( tile.filter ) }
					>
						<span className="sit-cwm-summary__label">
							{ tile.tone === 'danger' ? (
								<ClockIcon />
							) : (
								<span
									className="sit-cwm-summary__dot"
									aria-hidden="true"
									style={ {
										backgroundColor:
											getStatusDefinition( tile.status )
												?.color || FALLBACK_STATUS_COLOR,
									} }
								/>
							) }
							{ tile.label }
						</span>
						<span className="sit-cwm-summary__value">
							{ format.format( tile.value ) }
						</span>
						<span className="sit-cwm-summary__help">
							{ tile.help }
						</span>
					</button>
				</li>
			) ) }
		</ul>
	);
}
