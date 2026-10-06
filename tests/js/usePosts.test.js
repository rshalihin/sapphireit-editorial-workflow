/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * External dependencies
 */
import { act, renderHook, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import usePosts, {
	FILTER_OPERATORS,
	viewToQuery,
} from '../../src/hooks/usePosts';

jest.mock( '@wordpress/api-fetch' );

const DEFAULTS = { page: 1, per_page: 20 };

const response = ( items, total = items.length, totalPages = 1 ) => ( {
	status: 200,
	json: () => Promise.resolve( items ),
	headers: {
		get: ( name ) =>
			( {
				'X-WP-Total': String( total ),
				'X-WP-TotalPages': String( totalPages ),
			} )[ name ] ?? null,
	},
} );

const filtered = ( field, operator, value ) =>
	viewToQuery( { type: 'table', filters: [ { field, operator, value } ] } );

beforeEach( () => {
	window.sitCwm.statuses = [
		{ slug: 'review', label: 'Review' },
		{ slug: 'approved', label: 'Approved' },
	];
	window.sitCwm.postTypes = [
		{ slug: 'post', label: 'Posts', singularLabel: 'Post' },
		{ slug: 'page', label: 'Pages', singularLabel: 'Page' },
	];
} );

describe( 'viewToQuery', () => {
	it( 'maps an empty view to the first default page', () => {
		expect( viewToQuery( { type: 'table' } ) ).toEqual( DEFAULTS );
		expect( viewToQuery() ).toEqual( DEFAULTS );
	} );

	it( 'maps pagination, search and sorting', () => {
		expect(
			viewToQuery( {
				page: 3,
				perPage: 50,
				search: '  launch plan ',
				sort: { field: 'title', direction: 'asc' },
			} )
		).toEqual( {
			page: 3,
			per_page: 50,
			search: 'launch plan',
			orderby: 'title',
			order: 'asc',
		} );

		expect(
			viewToQuery( { sort: { field: 'due_date', direction: 'desc' } } )
		).toEqual( { ...DEFAULTS, orderby: 'due_date', order: 'desc' } );
	} );

	it( 'drops unsupported sorting and page sizes', () => {
		expect(
			viewToQuery( {
				page: 0,
				perPage: 37,
				sort: { field: 'status', direction: 'asc' },
			} )
		).toEqual( DEFAULTS );
		expect(
			viewToQuery( { sort: { field: 'constructor', direction: 'asc' } } )
		).toEqual( DEFAULTS );
	} );

	// Every operator the fields offer, with the REST args it must produce.
	const cases = [
		[
			'status',
			'isAny',
			[ 'review', 'approved', 'review' ],
			{ status: [ 'review', 'approved' ] },
		],
		[ 'reviewer', 'is', 7, { reviewer_id: 7 } ],
		[ 'author', 'is', 3, { author: 3 } ],
		[ 'post_type', 'is', 'page', { post_type: 'page' } ],
		[
			'due_date',
			'on',
			'2026-09-14',
			{ due_after: '2026-09-14', due_before: '2026-09-14' },
		],
		[ 'due_date', 'before', '2026-03-01', { due_before: '2026-02-28' } ],
		[ 'due_date', 'beforeInc', '2026-09-14', { due_before: '2026-09-14' } ],
		[ 'due_date', 'after', '2026-12-31', { due_after: '2027-01-01' } ],
		[ 'due_date', 'afterInc', '2026-09-14', { due_after: '2026-09-14' } ],
		[
			'due_date',
			'between',
			[ '2026-10-01', '2026-09-01' ],
			{ due_after: '2026-09-01', due_before: '2026-10-01' },
		],
		[ 'is_overdue', 'is', true, { overdue: true } ],
	];

	it( 'has a mapping case for every declared operator', () => {
		const declared = Object.entries( FILTER_OPERATORS ).flatMap(
			( [ field, operators ] ) =>
				operators.map( ( operator ) => `${ field }:${ operator }` )
		);

		expect(
			cases.map( ( [ field, operator ] ) => `${ field }:${ operator }` )
		).toEqual( declared );
	} );

	it.each( cases )(
		'maps %s %s to the REST args',
		( field, operator, value, args ) => {
			expect( filtered( field, operator, value ) ).toEqual( {
				...DEFAULTS,
				...args,
			} );
		}
	);

	it( 'maps the unassigned reviewer filter to reviewer_id 0', () => {
		expect( filtered( 'reviewer', 'is', 0 ) ).toEqual( {
			...DEFAULTS,
			reviewer_id: 0,
		} );
		expect( filtered( 'reviewer', 'is', '12' ) ).toEqual( {
			...DEFAULTS,
			reviewer_id: 12,
		} );
	} );

	it.each( [
		[ 'status', 'isNone', [ 'review' ] ],
		[ 'status', 'is', 'review' ],
		[ 'reviewer', 'isNot', 7 ],
		[ 'author', 'isAny', [ 3 ] ],
		[ 'due_date', 'inThePast', { value: 7, unit: 'days' } ],
		[ 'due_date', 'notOn', '2026-09-14' ],
		[ 'title', 'contains', 'launch' ],
		[ 'secret_meta', 'is', 'x' ],
		[ '__proto__', 'toString', 'x' ],
		[ 'status', 'constructor', 'x' ],
	] )( 'drops unknown operator %s %s', ( field, operator, value ) => {
		expect( filtered( field, operator, value ) ).toEqual( DEFAULTS );
	} );

	it.each( [
		[ 'status', 'isAny', [ 'bogus' ] ],
		[ 'status', 'isAny', [] ],
		[ 'reviewer', 'is', -1 ],
		[ 'reviewer', 'is', 'abc' ],
		[ 'author', 'is', 0 ],
		[ 'post_type', 'is', 'attachment' ],
		[ 'due_date', 'on', '2026-02-30' ],
		[ 'due_date', 'before', 'tomorrow' ],
		[ 'due_date', 'between', [ '2026-09-01' ] ],
		[ 'due_date', 'between', '2026-09-01' ],
		[ 'is_overdue', 'is', false ],
		[ 'is_overdue', 'is', 'yes' ],
	] )( 'drops invalid value for %s %s', ( field, operator, value ) => {
		expect( filtered( field, operator, value ) ).toEqual( DEFAULTS );
	} );

	it( 'combines several filters', () => {
		expect(
			viewToQuery( {
				page: 2,
				filters: [
					{ field: 'status', operator: 'isAny', value: [ 'review' ] },
					{ field: 'reviewer', operator: 'is', value: 5 },
					{ field: 'bogus', operator: 'is', value: 1 },
				],
			} )
		).toEqual( {
			page: 2,
			per_page: 20,
			status: [ 'review' ],
			reviewer_id: 5,
		} );
	} );
} );

describe( 'usePosts', () => {
	afterEach( () => {
		apiFetch.mockReset();
	} );

	it( 'loads a page from the server with its totals', async () => {
		apiFetch.mockResolvedValueOnce(
			response( [ { post_id: 1 }, { post_id: 2 } ], 45, 3 )
		);

		const { result } = renderHook( () =>
			usePosts( {
				page: 1,
				per_page: 20,
				status: [ 'review', 'approved' ],
			} )
		);

		expect( result.current.isLoading ).toBe( true );

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/sit-cwm/v1/posts?page=1&per_page=20&status=review%2Capproved',
				parse: false,
			} )
		);
		expect( result.current.records ).toEqual( [
			{ post_id: 1 },
			{ post_id: 2 },
		] );
		expect( result.current.totalItems ).toBe( 45 );
		expect( result.current.totalPages ).toBe( 3 );
		expect( result.current.error ).toBeNull();
	} );

	it( 'refetches the same query in place on refresh', async () => {
		apiFetch
			.mockResolvedValueOnce(
				response( [ { post_id: 1, status: 'review' } ] )
			)
			.mockResolvedValueOnce(
				response( [ { post_id: 1, status: 'approved' } ] )
			);

		const { result } = renderHook( () => usePosts( { page: 1 } ) );

		await waitFor( () =>
			expect( result.current.records ).toHaveLength( 1 )
		);

		act( () => result.current.refresh() );

		// Background refresh keeps the current rows and no loading state.
		expect( result.current.isLoading ).toBe( false );
		expect( result.current.records[ 0 ].status ).toBe( 'review' );

		await waitFor( () =>
			expect( result.current.records[ 0 ].status ).toBe( 'approved' )
		);

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].path ).toBe(
			apiFetch.mock.calls[ 0 ][ 0 ].path
		);
	} );

	it( 'does not refetch for an equal query and aborts outdated ones', async () => {
		apiFetch
			.mockReturnValueOnce( new Promise( () => {} ) )
			.mockResolvedValueOnce( response( [] ) );

		const { rerender, result } = renderHook(
			( { query } ) => usePosts( query ),
			{ initialProps: { query: { page: 1 } } }
		);

		rerender( { query: { page: 1 } } );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		const { signal } = apiFetch.mock.calls[ 0 ][ 0 ];

		rerender( { query: { page: 2 } } );

		expect( signal.aborted ).toBe( true );
		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'exposes a normalized error and clears the rows', async () => {
		apiFetch.mockRejectedValueOnce( {
			status: 403,
			json: () =>
				Promise.resolve( {
					code: 'sit_cwm_forbidden',
					message: 'Nope.',
				} ),
		} );

		const { result } = renderHook( () => usePosts( { page: 1 } ) );

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

		expect( result.current.error ).toEqual( {
			code: 'sit_cwm_forbidden',
			message: 'Nope.',
			status: 403,
		} );
		expect( result.current.records ).toEqual( [] );
		expect( result.current.totalItems ).toBe( 0 );
	} );

	it( 'keeps the rows when a background refresh fails', async () => {
		apiFetch
			.mockResolvedValueOnce( response( [ { post_id: 1 } ], 1 ) )
			.mockRejectedValueOnce( {
				code: 'fetch_error',
				message: 'Offline',
			} );

		const { result } = renderHook( () => usePosts( { page: 1 } ) );

		await waitFor( () =>
			expect( result.current.records ).toHaveLength( 1 )
		);

		act( () => result.current.refresh() );

		expect( result.current.isRefreshing ).toBe( true );

		await waitFor( () =>
			expect( result.current.isRefreshing ).toBe( false )
		);

		expect( result.current.error ).toEqual(
			expect.objectContaining( { code: 'fetch_error', status: 0 } )
		);
		expect( result.current.records ).toEqual( [ { post_id: 1 } ] );
		expect( result.current.totalItems ).toBe( 1 );
	} );
} );
