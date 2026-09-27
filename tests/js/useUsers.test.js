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
import useUsers from '../../src/hooks/useUsers';
import useReviewerOptions, {
	UNASSIGNED,
} from '../../src/hooks/useReviewerOptions';

jest.mock( '@wordpress/api-fetch' );

const jane = { id: 4, name: 'Jane', avatar: 'https://example.org/j.png' };
const omar = { id: 7, name: 'Omar', avatar: 'https://example.org/o.png' };

const forbidden = {
	code: 'rest_forbidden',
	message: 'Sorry, you are not allowed to do that.',
	data: { status: 403 },
};

describe( 'useUsers', () => {
	afterEach( () => {
		apiFetch.mockReset();
	} );

	it( 'is loading until the users arrive', async () => {
		apiFetch.mockResolvedValueOnce( [ jane, omar ] );

		const { result } = renderHook( () =>
			useUsers( { search: 'ja', postId: 12, perPage: 5 } )
		);

		expect( result.current.isLoading ).toBe( true );

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

		expect( result.current.users ).toEqual( [ jane, omar ] );
		expect( result.current.error ).toBeNull();

		const { path } = apiFetch.mock.calls[ 0 ][ 0 ];
		expect( path ).toContain( '/sit-cwm/v1/users' );
		expect( path ).toContain( 'search=ja' );
		expect( path ).toContain( 'per_page=5' );
		expect( path ).toContain( 'post_id=12' );
	} );

	it( 'treats a non-array body as no users', async () => {
		apiFetch.mockResolvedValueOnce( { unexpected: true } );

		const { result } = renderHook( () => useUsers() );

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

		expect( result.current.users ).toEqual( [] );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).not.toContain( 'post_id' );
	} );

	it( 'exposes a normalized error', async () => {
		apiFetch.mockRejectedValueOnce( forbidden );

		const { result } = renderHook( () => useUsers() );

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

		expect( result.current.users ).toEqual( [] );
		expect( result.current.error ).toMatchObject( {
			code: 'rest_forbidden',
			status: 403,
		} );
	} );

	it( 'does not fetch while disabled', () => {
		const { result } = renderHook( () => useUsers( { enabled: false } ) );

		expect( apiFetch ).not.toHaveBeenCalled();
		expect( result.current.isLoading ).toBe( false );
	} );

	it( 'aborts the outdated request when the search changes', async () => {
		const signals = [];

		apiFetch.mockImplementation( ( { signal } ) => {
			signals.push( signal );

			return 1 === signals.length
				? new Promise( ( resolve, reject ) =>
						signal.addEventListener( 'abort', () => {
							const error = new Error( 'Aborted' );
							error.name = 'AbortError';
							reject( error );
						} )
					)
				: Promise.resolve( [ omar ] );
		} );

		const { result, rerender } = renderHook(
			( { search } ) => useUsers( { search } ),
			{ initialProps: { search: 'j' } }
		);

		rerender( { search: 'om' } );

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

		expect( signals[ 0 ].aborted ).toBe( true );
		expect( result.current.users ).toEqual( [ omar ] );
		// The aborted request is not reported as an error.
		expect( result.current.error ).toBeNull();
	} );
} );

describe( 'useReviewerOptions', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
		apiFetch.mockReset();
	} );

	it( 'lists Unassigned, the current reviewer, then the matches without duplicates', async () => {
		apiFetch.mockResolvedValue( [ jane, omar ] );

		const { result } = renderHook( () =>
			useReviewerOptions( { postId: 3, reviewer: jane } )
		);

		expect( result.current.options ).toEqual( [
			{ value: UNASSIGNED, label: '— Unassigned —' },
			{ value: '4', label: 'Jane' },
		] );

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

		expect( result.current.options ).toEqual( [
			{ value: UNASSIGNED, label: '— Unassigned —' },
			{ value: '4', label: 'Jane' },
			{ value: '7', label: 'Omar' },
		] );
	} );

	it( 'searches after the debounce delay', async () => {
		apiFetch.mockResolvedValue( [] );

		const { result } = renderHook( () => useReviewerOptions() );

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		act( () => result.current.onFilterValueChange( 'om' ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );

		apiFetch.mockResolvedValueOnce( [ omar ] );
		act( () => jest.advanceTimersByTime( 300 ) );

		await waitFor( () =>
			expect( result.current.options.map( ( o ) => o.label ) ).toContain(
				'Omar'
			)
		);
		expect( apiFetch.mock.calls[ 1 ][ 0 ].path ).toContain( 'search=om' );
	} );

	it( 'passes the error through', async () => {
		apiFetch.mockRejectedValueOnce( forbidden );

		const { result } = renderHook( () => useReviewerOptions() );

		await waitFor( () => expect( result.current.error ).not.toBeNull() );

		expect( result.current.options ).toHaveLength( 1 );
	} );
} );
