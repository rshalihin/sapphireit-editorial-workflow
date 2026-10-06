/**
 * Dashboard collection: the DataViews view → REST query mapping, and the data
 * hook over `GET /sit-cwm/v1/posts`.
 *
 * Pagination, sorting and filtering all happen on the server; nothing here
 * slices or filters rows.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { getPosts, isAbortError } from '../api/client';
import { getStatusDefinition, isEnabledPostType, isYmd } from '../utils/format';

/**
 * Page sizes offered by the dashboard.
 *
 * @type {number[]}
 */
export const PER_PAGE_SIZES = [ 20, 50, 100 ];

/**
 * Default page size.
 *
 * @type {number}
 */
export const DEFAULT_PER_PAGE = 20;

/**
 * DataViews sort field → REST `orderby`.
 *
 * @type {Object<string, string>}
 */
export const SORT_COLUMNS = { title: 'title', due_date: 'due_date' };

/**
 * Maximum search length accepted by the route.
 *
 * @type {number}
 */
const MAX_SEARCH_LENGTH = 200;

/**
 * Own-property lookup, so keys such as `__proto__` or `toString` coming from a
 * tampered URL never resolve to inherited members.
 *
 * @param {Object} object Object.
 * @param {*}      key    Key.
 * @return {boolean} True when `key` is an own property.
 */
function hasOwn( object, key ) {
	return Object.prototype.hasOwnProperty.call( object, key );
}

/**
 * A non-negative integer from a number or a digit string.
 *
 * @param {*} value Value.
 * @return {?number} Integer, or null.
 */
function toInteger( value ) {
	if ( Number.isInteger( value ) && value >= 0 ) {
		return value;
	}

	return typeof value === 'string' && /^\d+$/.test( value )
		? parseInt( value, 10 )
		: null;
}

/**
 * Whether a value is a real calendar date as `Y-m-d` (rejects `2026-02-30`).
 *
 * @param {*} value Value.
 * @return {boolean} True for valid dates.
 */
export function isCalendarDate( value ) {
	if ( ! isYmd( value ) ) {
		return false;
	}

	const date = new Date( `${ value }T00:00:00Z` );

	return (
		! Number.isNaN( date.getTime() ) &&
		date.toISOString().slice( 0, 10 ) === value
	);
}

/**
 * Moves a `Y-m-d` date by whole days, in UTC so it never shifts with offsets.
 *
 * @param {string} ymd  Valid `Y-m-d` date.
 * @param {number} days Days to add (negative to subtract).
 * @return {string} `Y-m-d` date.
 */
export function shiftYmd( ymd, days ) {
	const date = new Date( `${ ymd }T00:00:00Z` );

	date.setUTCDate( date.getUTCDate() + days );

	return date.toISOString().slice( 0, 10 );
}

/**
 * Builds a date operator handler that ignores invalid dates.
 *
 * @param {Function} build Receives a valid `Y-m-d` date, returns REST args.
 * @return {Function} Handler.
 */
function dateHandler( build ) {
	return ( value ) => ( isCalendarDate( value ) ? build( value ) : null );
}

/**
 * Field id → DataViews operator → handler turning the filter value into REST
 * args, or null when the value is unusable. The route's `due_before` and
 * `due_after` are inclusive, so exclusive operators move the date by a day.
 *
 * Anything not listed here is dropped by `viewToQuery()`.
 *
 * @type {Object<string, Object<string, Function>>}
 */
const FILTERS = {
	status: {
		isAny: ( value ) => {
			const slugs = ( Array.isArray( value ) ? value : [ value ] ).filter(
				( slug ) =>
					typeof slug === 'string' && !! getStatusDefinition( slug )
			);

			return slugs.length ? { status: [ ...new Set( slugs ) ] } : null;
		},
	},
	reviewer: {
		is: ( value ) => {
			const id = toInteger( value );

			return id === null ? null : { reviewer_id: id };
		},
	},
	author: {
		is: ( value ) => {
			const id = toInteger( value );

			return id ? { author: id } : null;
		},
	},
	post_type: {
		is: ( value ) =>
			typeof value === 'string' && isEnabledPostType( value )
				? { post_type: value }
				: null,
	},
	due_date: {
		on: dateHandler( ( date ) => ( {
			due_after: date,
			due_before: date,
		} ) ),
		before: dateHandler( ( date ) => ( {
			due_before: shiftYmd( date, -1 ),
		} ) ),
		beforeInc: dateHandler( ( date ) => ( { due_before: date } ) ),
		after: dateHandler( ( date ) => ( {
			due_after: shiftYmd( date, 1 ),
		} ) ),
		afterInc: dateHandler( ( date ) => ( { due_after: date } ) ),
		between: ( value ) => {
			if (
				! Array.isArray( value ) ||
				value.length !== 2 ||
				! value.every( isCalendarDate )
			) {
				return null;
			}

			const [ from, to ] = [ ...value ].sort();

			return { due_after: from, due_before: to };
		},
	},
	// "Overdue only": computed by the server against the site timezone.
	is_overdue: {
		is: ( value ) =>
			value === true || value === 'true' ? { overdue: true } : null,
	},
};

/**
 * Operators each filterable field supports, the first being its default.
 * Field definitions and URL state read this so they can only offer operators
 * `viewToQuery()` understands.
 *
 * @type {Object<string, string[]>}
 */
export const FILTER_OPERATORS = Object.fromEntries(
	Object.entries( FILTERS ).map( ( [ field, operators ] ) => [
		field,
		Object.keys( operators ),
	] )
);

/**
 * Maps a DataViews `view` to `GET /sit-cwm/v1/posts` arguments.
 *
 * Unknown sort fields, filter fields and operators, and invalid filter values
 * are dropped rather than passed through. The server validates everything
 * again regardless.
 *
 * @param {Object} [view] DataViews view.
 * @return {Object} REST query arguments.
 */
export function viewToQuery( view = {} ) {
	const page = toInteger( view.page );
	const query = {
		page: page > 0 ? page : 1,
		per_page: PER_PAGE_SIZES.includes( view.perPage )
			? view.perPage
			: DEFAULT_PER_PAGE,
	};

	const search =
		typeof view.search === 'string'
			? view.search.trim().slice( 0, MAX_SEARCH_LENGTH )
			: '';

	if ( search ) {
		query.search = search;
	}

	if ( view.sort && hasOwn( SORT_COLUMNS, view.sort.field ) ) {
		query.orderby = SORT_COLUMNS[ view.sort.field ];
		query.order = view.sort.direction === 'asc' ? 'asc' : 'desc';
	}

	( Array.isArray( view.filters ) ? view.filters : [] ).forEach(
		( filter ) => {
			if (
				! filter ||
				! hasOwn( FILTERS, filter.field ) ||
				! hasOwn( FILTERS[ filter.field ], filter.operator )
			) {
				return;
			}

			const args = FILTERS[ filter.field ][ filter.operator ](
				filter.value
			);

			if ( args ) {
				Object.assign( query, args );
			}
		}
	);

	return query;
}

/**
 * One page of dashboard rows for a REST query.
 *
 * `isLoading` is true while a new query loads. `refresh()` refetches the same
 * query in the background and swaps the rows in place, so the table does not
 * blank out after a row action; `isRefreshing` is true meanwhile. A failed
 * background refresh keeps the rows already shown. Outdated requests are
 * aborted.
 *
 * @param {Object} query REST query from `viewToQuery()`.
 * @return {{records: Object[], totalItems: number, totalPages: number, isLoading: boolean, isRefreshing: boolean, error: ?Object, refresh: Function}} Result.
 */
export default function usePosts( query ) {
	const [ page, setPage ] = useState( {
		records: [],
		totalItems: 0,
		totalPages: 0,
	} );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isRefreshing, setIsRefreshing ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ version, setVersion ] = useState( 0 );
	const lastKeyRef = useRef( null );

	// A stable dependency: equal queries never refetch.
	const key = JSON.stringify( query || {} );

	useEffect( () => {
		const controller = new window.AbortController();
		const isNewQuery = lastKeyRef.current !== key;

		if ( isNewQuery ) {
			lastKeyRef.current = key;
			setIsLoading( true );
		}

		setIsRefreshing( ! isNewQuery );

		getPosts( JSON.parse( key ), { signal: controller.signal } )
			.then( ( result ) => {
				setPage( {
					records: result.items,
					totalItems: result.total,
					totalPages: result.totalPages,
				} );
				setError( null );
			} )
			.catch( ( err ) => {
				if ( ! isAbortError( err ) ) {
					// Rows of another query would be misleading; the current
					// query's rows stay visible above the error.
					if ( isNewQuery ) {
						setPage( {
							records: [],
							totalItems: 0,
							totalPages: 0,
						} );
					}

					setError( err );
				}
			} )
			.finally( () => {
				if ( ! controller.signal.aborted ) {
					setIsLoading( false );
					setIsRefreshing( false );
				}
			} );

		return () => controller.abort();
	}, [ key, version ] );

	const refresh = useCallback( () => setVersion( ( v ) => v + 1 ), [] );

	return { ...page, isLoading, isRefreshing, error, refresh };
}
