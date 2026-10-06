/**
 * Dashboard view ↔ query string, so a filtered dashboard is linkable and
 * survives a refresh.
 *
 * Keys avoid `page` (the admin page slug) and `post_type` (read by
 * `wp-admin/admin.php`), and are only written when they differ from the
 * default view. Everything read back is validated: unknown layouts, sort
 * fields, filter fields and operators are dropped.
 */

/**
 * Internal dependencies
 */
import {
	DEFAULT_PER_PAGE,
	FILTER_OPERATORS,
	PER_PAGE_SIZES,
	SORT_COLUMNS,
} from '../hooks/usePosts';

/**
 * Columns shown by default.
 *
 * @type {string[]}
 */
const DEFAULT_FIELDS = [
	'status',
	'reviewer',
	'due_date',
	'author',
	'post_type',
	'last_activity',
];

/**
 * Layouts the dashboard offers, with the settings applied when switching.
 *
 * @type {Object}
 */
export const DEFAULT_LAYOUTS = {
	table: {
		fields: DEFAULT_FIELDS,
		titleField: 'title',
	},
	grid: {
		fields: DEFAULT_FIELDS,
		titleField: 'title',
		layout: { badgeFields: [ 'status' ] },
	},
};

/**
 * The initial view.
 *
 * @type {Object}
 */
export const DEFAULT_VIEW = {
	type: 'table',
	page: 1,
	perPage: DEFAULT_PER_PAGE,
	search: '',
	filters: [],
	...DEFAULT_LAYOUTS.table,
};

/**
 * Filter field id → query string key.
 *
 * @type {Object<string, string>}
 */
const FILTER_KEYS = {
	status: 'status',
	reviewer: 'reviewer',
	author: 'author',
	post_type: 'type',
	due_date: 'due',
	is_overdue: 'overdue',
};

/**
 * Operators whose value is a list.
 *
 * @type {string[]}
 */
const LIST_OPERATORS = [ 'isAny', 'between' ];

/**
 * Fields whose values are user ids.
 *
 * @type {string[]}
 */
const INTEGER_FIELDS = [ 'reviewer', 'author' ];

/**
 * Fields whose only value is `true`.
 *
 * @type {string[]}
 */
const BOOLEAN_FIELDS = [ 'is_overdue' ];

/**
 * Every query string key this module owns.
 *
 * @type {string[]}
 */
const OWN_KEYS = [
	'layout',
	'paged',
	'per_page',
	'search',
	'orderby',
	'order',
	...Object.values( FILTER_KEYS ).flatMap( ( key ) => [
		key,
		`${ key }_op`,
	] ),
];

/**
 * Own-property lookup that ignores inherited keys.
 *
 * @param {Object} object Object.
 * @param {*}      key    Key.
 * @return {boolean} True when `key` is an own property.
 */
function hasOwn( object, key ) {
	return Object.prototype.hasOwnProperty.call( object, key );
}

/**
 * A positive integer from a query string value.
 *
 * @param {?string} value Raw value.
 * @return {number} Integer, or `0`.
 */
function positiveInt( value ) {
	return value && /^\d+$/.test( value ) ? parseInt( value, 10 ) : 0;
}

/**
 * Writes a view into query parameters, preserving unrelated ones.
 *
 * @param {Object}                 view      DataViews view.
 * @param {URLSearchParams|string} [current] Existing parameters.
 * @return {URLSearchParams} New parameters.
 */
export function viewToSearchParams( view, current = '' ) {
	const params = new URLSearchParams( current );

	OWN_KEYS.forEach( ( key ) => params.delete( key ) );

	if (
		view.type !== DEFAULT_VIEW.type &&
		hasOwn( DEFAULT_LAYOUTS, view.type )
	) {
		params.set( 'layout', view.type );
	}

	if ( view.page > 1 ) {
		params.set( 'paged', String( view.page ) );
	}

	if (
		view.perPage !== DEFAULT_PER_PAGE &&
		PER_PAGE_SIZES.includes( view.perPage )
	) {
		params.set( 'per_page', String( view.perPage ) );
	}

	if ( view.search ) {
		params.set( 'search', view.search );
	}

	if ( view.sort && hasOwn( SORT_COLUMNS, view.sort.field ) ) {
		params.set( 'orderby', view.sort.field );
		params.set( 'order', view.sort.direction === 'asc' ? 'asc' : 'desc' );
	}

	( view.filters || [] ).forEach( ( filter ) => {
		if (
			! filter ||
			! hasOwn( FILTER_KEYS, filter.field ) ||
			! FILTER_OPERATORS[ filter.field ].includes( filter.operator )
		) {
			return;
		}

		const value = Array.isArray( filter.value )
			? filter.value.join( ',' )
			: filter.value;

		if ( value === undefined || value === null || value === '' ) {
			return;
		}

		const key = FILTER_KEYS[ filter.field ];

		params.set( key, String( value ) );

		if ( filter.operator !== FILTER_OPERATORS[ filter.field ][ 0 ] ) {
			params.set( `${ key }_op`, filter.operator );
		}
	} );

	return params;
}

/**
 * Parses a filter value from the query string.
 *
 * @param {string} field    Field id.
 * @param {string} operator Operator.
 * @param {string} raw      Raw value.
 * @return {*} Filter value, or null when unusable.
 */
function parseFilterValue( field, operator, raw ) {
	if ( LIST_OPERATORS.includes( operator ) ) {
		const list = raw.split( ',' ).filter( Boolean );

		if ( operator === 'between' ) {
			return list.length === 2 ? list : null;
		}

		return list.length ? list : null;
	}

	if ( INTEGER_FIELDS.includes( field ) ) {
		return /^\d+$/.test( raw ) ? parseInt( raw, 10 ) : null;
	}

	if ( BOOLEAN_FIELDS.includes( field ) ) {
		return raw === 'true' ? true : null;
	}

	return raw;
}

/**
 * Reads a view from query parameters, falling back to the default view.
 *
 * @param {URLSearchParams|string} search Query parameters.
 * @return {Object} DataViews view.
 */
export function viewFromSearchParams( search ) {
	const params = new URLSearchParams( search );
	const layout = params.get( 'layout' );
	const type =
		layout && hasOwn( DEFAULT_LAYOUTS, layout )
			? layout
			: DEFAULT_VIEW.type;
	const view = {
		...DEFAULT_VIEW,
		...DEFAULT_LAYOUTS[ type ],
		type,
		filters: [],
	};

	const page = positiveInt( params.get( 'paged' ) );

	if ( page > 0 ) {
		view.page = page;
	}

	const perPage = positiveInt( params.get( 'per_page' ) );

	if ( PER_PAGE_SIZES.includes( perPage ) ) {
		view.perPage = perPage;
	}

	if ( params.get( 'search' ) ) {
		view.search = params.get( 'search' );
	}

	const orderby = params.get( 'orderby' );

	if ( orderby && hasOwn( SORT_COLUMNS, orderby ) ) {
		view.sort = {
			field: orderby,
			direction: params.get( 'order' ) === 'asc' ? 'asc' : 'desc',
		};
	}

	Object.entries( FILTER_KEYS ).forEach( ( [ field, key ] ) => {
		const raw = params.get( key );

		if ( ! raw ) {
			return;
		}

		const operators = FILTER_OPERATORS[ field ];
		const operator = params.get( `${ key }_op` ) || operators[ 0 ];

		if ( ! operators.includes( operator ) ) {
			return;
		}

		const value = parseFilterValue( field, operator, raw );

		if ( value !== null ) {
			view.filters.push( { field, operator, value } );
		}
	} );

	return view;
}

/**
 * Whether a view has a search or a filter with a value.
 *
 * @param {Object} view DataViews view.
 * @return {boolean} True when the result set is narrowed.
 */
export function hasActiveQuery( view ) {
	return (
		!! view.search ||
		( view.filters || [] ).some(
			( filter ) =>
				filter &&
				filter.value !== undefined &&
				filter.value !== null &&
				filter.value !== '' &&
				! ( Array.isArray( filter.value ) && ! filter.value.length )
		)
	);
}
