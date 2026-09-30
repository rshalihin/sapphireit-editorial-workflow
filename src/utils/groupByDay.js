/**
 * Buckets activity entries by calendar day in the site's timezone.
 */

/**
 * WordPress dependencies
 */
import { dateI18n } from '@wordpress/date';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { formatDate } from './format';

/**
 * @typedef {Object} DayGroup
 * @property {string}   day   `Y-m-d` in the site's timezone; `''` when unknown.
 * @property {string}   label `Today`, `Yesterday` or a localized date.
 * @property {Object[]} items Entries of that day, in input order.
 */

/**
 * Moves a `Y-m-d` date by whole days.
 *
 * Pure calendar arithmetic: the date is already in the site's timezone, and
 * computing on UTC midnight keeps the browser's own offset and DST out of it.
 *
 * @param {string} ymd  `Y-m-d` date.
 * @param {number} days Days to add; negative to subtract.
 * @return {string} `Y-m-d` date.
 */
function shiftYmd( ymd, days ) {
	const [ year, month, day ] = ymd.split( '-' ).map( Number );

	return new Date( Date.UTC( year, month - 1, day + days ) )
		.toISOString()
		.slice( 0, 10 );
}

/**
 * The site-timezone calendar day of an ISO 8601 timestamp.
 *
 * @param {*} isoDate Timestamp with offset, e.g. `2026-09-12T09:30:00+00:00`.
 * @return {string} `Y-m-d`, or `''` when unparsable.
 */
export function siteDayOf( isoDate ) {
	if (
		typeof isoDate !== 'string' ||
		Number.isNaN( Date.parse( isoDate ) )
	) {
		return '';
	}

	return dateI18n( 'Y-m-d', isoDate );
}

/**
 * Groups entries under `Today`, `Yesterday` or a localized date heading.
 *
 * Groups keep the order in which their first entry appears, so newest-first
 * input yields newest-first groups.
 *
 * @param {Object[]}           items Entries with an ISO 8601 `created_at`.
 * @param {Date|string|number} [now] The current time; defaults to now.
 * @return {DayGroup[]} Groups.
 */
export default function groupByDay( items, now = new Date() ) {
	const today = dateI18n( 'Y-m-d', now );
	const yesterday = shiftYmd( today, -1 );
	const groups = new Map();

	( Array.isArray( items ) ? items : [] ).forEach( ( item ) => {
		const day = siteDayOf( item?.created_at );

		if ( ! groups.has( day ) ) {
			let label;

			if ( day === '' ) {
				label = __( 'Unknown date', 'sapphireit-editorial-workflow' );
			} else if ( day === today ) {
				label = __( 'Today', 'sapphireit-editorial-workflow' );
			} else if ( day === yesterday ) {
				label = __( 'Yesterday', 'sapphireit-editorial-workflow' );
			} else {
				label = formatDate( day );
			}

			groups.set( day, { day, label, items: [] } );
		}

		groups.get( day ).items.push( item );
	} );

	return [ ...groups.values() ];
}
