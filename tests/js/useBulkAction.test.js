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
import useBulkAction, {
	MISSING_RESULT,
	normalizePostIds,
	reconcileResult,
	summarizeResult,
} from '../../src/hooks/useBulkAction';

jest.mock( '@wordpress/api-fetch' );

afterEach( () => {
	apiFetch.mockReset();
} );

describe( 'normalizePostIds', () => {
	it( 'keeps unique positive integers in order', () => {
		expect(
			normalizePostIds( [ 3, '7', 3, 0, -2, 'x', 1.5, null, '7' ] )
		).toEqual( [ 3, 7 ] );
		expect( normalizePostIds( 'nope' ) ).toEqual( [] );
	} );
} );

describe( 'reconcileResult', () => {
	it( 'counts every id the server did not account for as failed', () => {
		const result = reconcileResult(
			{
				succeeded: [ 1, 99 ],
				failed: [
					{ post_id: 2, code: 'sit_cwm_forbidden', message: 'No.' },
				],
			},
			[ 1, 2, 3 ]
		);

		expect( result.succeeded ).toEqual( [ 1 ] );
		expect( result.failed ).toEqual( [
			{ post_id: 2, code: 'sit_cwm_forbidden', message: 'No.' },
			{ post_id: 3, code: MISSING_RESULT, message: expect.any( String ) },
		] );
	} );

	it( 'treats a malformed body as a total failure', () => {
		const result = reconcileResult( null, [ 4, 5 ] );

		expect( result.succeeded ).toEqual( [] );
		expect( result.failed.map( ( entry ) => entry.post_id ) ).toEqual( [
			4, 5,
		] );
	} );
} );

describe( 'summarizeResult', () => {
	const ids = ( count ) =>
		Array.from( { length: count }, ( _, index ) => ( {
			post_id: index + 1,
		} ) );

	it( 'is a success only when nothing failed', () => {
		expect(
			summarizeResult( { succeeded: [ 1, 2, 3 ], failed: [] } )
		).toEqual( { status: 'success', message: '3 posts updated.' } );
		expect( summarizeResult( { succeeded: [ 1 ], failed: [] } ) ).toEqual( {
			status: 'success',
			message: '1 post updated.',
		} );
	} );

	it( 'never reports a partial batch as a success', () => {
		expect(
			summarizeResult( { succeeded: [ 1, 2, 3 ], failed: ids( 1 ) } )
		).toEqual( {
			status: 'warning',
			message: '3 posts updated. 1 could not be updated.',
		} );
		expect(
			summarizeResult( { succeeded: [], failed: ids( 2 ) } )
		).toEqual( {
			status: 'error',
			message: 'No posts were updated. 2 could not be updated.',
		} );
	} );
} );

describe( 'useBulkAction', () => {
	it( 'sends one batch and reports the result', async () => {
		let resolve;

		apiFetch.mockReturnValueOnce(
			new Promise( ( done ) => {
				resolve = done;
			} )
		);

		const onComplete = jest.fn();
		const { result } = renderHook( () => useBulkAction( { onComplete } ) );

		expect( result.current.isRunning ).toBe( false );

		let pending;

		act( () => {
			pending = result.current.run(
				'change_status',
				{ status: 'approved' },
				[ '12', 34, 12 ],
				{ labels: { 12: 'Launch plan' } }
			);
		} );

		expect( result.current.isRunning ).toBe( true );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/sit-cwm/v1/posts/batch',
			method: 'POST',
			data: {
				post_ids: [ 12, 34 ],
				action: 'change_status',
				payload: { status: 'approved' },
			},
		} );

		await act( async () => {
			resolve( {
				succeeded: [ 12 ],
				failed: [
					{ post_id: 34, code: 'sit_cwm_forbidden', message: 'No.' },
				],
				items: [],
			} );
			await pending;
		} );

		expect( result.current.isRunning ).toBe( false );
		expect( result.current.result ).toMatchObject( {
			status: 'warning',
			message: '1 post updated. 1 could not be updated.',
			succeeded: [ 12 ],
			labels: { 12: 'Launch plan' },
		} );
		expect( onComplete ).toHaveBeenCalledWith( result.current.result );
	} );

	it( 'ignores a second run while one is in flight', async () => {
		apiFetch.mockReturnValueOnce( new Promise( () => {} ) );

		const { result } = renderHook( () => useBulkAction() );

		act( () => {
			result.current.run( 'set_due_date', { due_date: '' }, [ 1 ] );
		} );

		let second;

		await act( async () => {
			second = await result.current.run(
				'set_due_date',
				{ due_date: '' },
				[ 1 ]
			);
		} );

		expect( second ).toBeNull();
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'marks every id failed when the request itself fails', async () => {
		apiFetch.mockRejectedValueOnce( {
			code: 'rest_invalid_param',
			message: 'Invalid parameter(s): post_ids',
			data: { status: 400 },
		} );

		const { result } = renderHook( () => useBulkAction() );

		await act( async () => {
			await result.current.run(
				'assign_reviewer',
				{ reviewer_id: 5 },
				[ 1, 2 ]
			);
		} );

		await waitFor( () => expect( result.current.result ).not.toBeNull() );

		expect( result.current.result.status ).toBe( 'error' );
		expect( result.current.result.failed ).toEqual( [
			{
				post_id: 1,
				code: 'rest_invalid_param',
				message: 'Invalid parameter(s): post_ids',
			},
			{
				post_id: 2,
				code: 'rest_invalid_param',
				message: 'Invalid parameter(s): post_ids',
			},
		] );
	} );

	it( 'sends nothing for an unknown action or an empty selection', async () => {
		const { result } = renderHook( () => useBulkAction() );

		await act( async () => {
			expect(
				await result.current.run( 'delete_everything', {}, [ 1 ] )
			).toBeNull();
			expect(
				await result.current.run( 'change_status', {}, [] )
			).toBeNull();
		} );

		expect( apiFetch ).not.toHaveBeenCalled();
	} );
} );
