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
import useActivity from '../../src/hooks/useActivity';

jest.mock( '@wordpress/api-fetch' );

const entry = ( id ) => ( {
	id,
	action: 'comment_added',
	action_label: 'Comment added',
	old_value: null,
	old_label: null,
	new_value: null,
	new_label: null,
	message: `Comment ${ id }`,
	created_at: '2026-09-12T09:30:00+00:00',
	created_at_human: '2 days ago',
	user_id: 4,
	user: { id: 4, name: 'Jane', avatar: 'https://example.org/a.png' },
} );

/**
 * A `parse: false` response: JSON body plus pagination headers.
 *
 * @param {Object[]} items               Body.
 * @param {Object}   [totals]            Headers.
 * @param {number}   [totals.total]      `X-WP-Total`.
 * @param {number}   [totals.totalPages] `X-WP-TotalPages`.
 * @return {Object} Response-like object.
 */
const pageResponse = (
	items,
	{ total = items.length, totalPages = 1 } = {}
) => {
	const headers = {
		'X-WP-Total': String( total ),
		'X-WP-TotalPages': String( totalPages ),
	};

	return {
		ok: true,
		status: 200,
		json: () => Promise.resolve( items ),
		headers: { get: ( name ) => headers[ name ] ?? null },
	};
};

const errorResponse = ( code, message, status ) => ( {
	ok: false,
	status,
	json: () => Promise.resolve( { code, message, data: { status } } ),
	headers: { get: () => null },
} );

const ids = ( items ) => items.map( ( item ) => item.id );

describe( 'useActivity', () => {
	afterEach( () => {
		apiFetch.mockReset();
	} );

	it( 'loads the first page and reads the pagination headers', async () => {
		apiFetch.mockResolvedValueOnce(
			pageResponse( [ entry( 3 ), entry( 2 ) ], {
				total: 3,
				totalPages: 2,
			} )
		);

		const { result } = renderHook( () =>
			useActivity( 42, { perPage: 2 } )
		);

		expect( result.current.isLoading ).toBe( true );

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/sit-cwm/v1/posts/42/activity?page=1&per_page=2',
				parse: false,
			} )
		);
		expect( ids( result.current.items ) ).toEqual( [ 3, 2 ] );
		expect( result.current.hasMore ).toBe( true );
		expect( result.current.error ).toBeNull();
	} );

	it( 'appends the next page without duplicates', async () => {
		apiFetch
			.mockResolvedValueOnce(
				pageResponse( [ entry( 3 ), entry( 2 ) ], {
					total: 4,
					totalPages: 2,
				} )
			)
			// A new entry was logged meanwhile, so page 2 repeats entry 2.
			.mockResolvedValueOnce(
				pageResponse( [ entry( 2 ), entry( 1 ) ], {
					total: 4,
					totalPages: 2,
				} )
			);

		const { result } = renderHook( () =>
			useActivity( 42, { perPage: 2 } )
		);

		await waitFor( () => expect( result.current.hasMore ).toBe( true ) );

		act( () => {
			result.current.loadMore();
		} );

		await waitFor( () =>
			expect( ids( result.current.items ) ).toEqual( [ 3, 2, 1 ] )
		);

		expect( apiFetch.mock.calls[ 1 ][ 0 ].path ).toBe(
			'/sit-cwm/v1/posts/42/activity?page=2&per_page=2'
		);
		expect( result.current.hasMore ).toBe( false );
	} );

	it( 'reloads page 1 when the version changes', async () => {
		apiFetch
			.mockResolvedValueOnce(
				pageResponse( [ entry( 3 ), entry( 2 ) ], { totalPages: 2 } )
			)
			.mockResolvedValueOnce(
				pageResponse( [ entry( 1 ) ], { totalPages: 2 } )
			)
			.mockResolvedValueOnce(
				pageResponse( [ entry( 4 ), entry( 3 ) ], { totalPages: 3 } )
			);

		const { result, rerender } = renderHook(
			( { version } ) => useActivity( 42, { perPage: 2, version } ),
			{ initialProps: { version: 0 } }
		);

		await waitFor( () => expect( result.current.hasMore ).toBe( true ) );

		act( () => {
			result.current.loadMore();
		} );

		await waitFor( () => expect( result.current.items ).toHaveLength( 3 ) );

		rerender( { version: 1 } );

		await waitFor( () =>
			expect( ids( result.current.items ) ).toEqual( [ 4, 3 ] )
		);

		expect( apiFetch.mock.calls[ 2 ][ 0 ].path ).toBe(
			'/sit-cwm/v1/posts/42/activity?page=1&per_page=2'
		);
	} );

	it( 'exposes a normalized error and retries', async () => {
		apiFetch
			.mockRejectedValueOnce(
				errorResponse( 'sit_cwm_forbidden', 'Not allowed.', 403 )
			)
			.mockResolvedValueOnce( pageResponse( [ entry( 1 ) ] ) );

		const { result } = renderHook( () => useActivity( 42 ) );

		await waitFor( () => expect( result.current.error ).not.toBeNull() );

		expect( result.current.error ).toEqual( {
			code: 'sit_cwm_forbidden',
			message: 'Not allowed.',
			status: 403,
		} );
		expect( result.current.items ).toEqual( [] );

		act( () => {
			result.current.retry();
		} );

		await waitFor( () =>
			expect( ids( result.current.items ) ).toEqual( [ 1 ] )
		);

		expect( result.current.error ).toBeNull();
	} );

	it( 'never fetches when disabled', () => {
		const { result } = renderHook( () =>
			useActivity( 42, { enabled: false } )
		);

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( result.current.isLoading ).toBe( false );
		expect( result.current.hasMore ).toBe( false );
	} );

	it( 'aborts the request on unmount', () => {
		apiFetch.mockReturnValueOnce( new Promise( () => {} ) );

		const { unmount } = renderHook( () => useActivity( 42 ) );
		const { signal } = apiFetch.mock.calls[ 0 ][ 0 ];

		expect( signal.aborted ).toBe( false );
		unmount();
		expect( signal.aborted ).toBe( true );
	} );
} );
