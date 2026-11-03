/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	ABORTED,
	getGovernanceReport,
	normalizeError,
} from '../../src/api/client';

jest.mock( '@wordpress/api-fetch' );

describe( 'normalizeError', () => {
	it( 'reads code, message and status from a REST error', () => {
		expect(
			normalizeError( {
				code: 'sit_cwm_forbidden',
				message: 'Nope.',
				data: { status: 403 },
			} )
		).toEqual( {
			code: 'sit_cwm_forbidden',
			message: 'Nope.',
			status: 403,
		} );
	} );

	it( 'maps network failures to status 0 with a readable message', () => {
		const error = normalizeError( {
			code: 'fetch_error',
			message: 'You are probably offline.',
		} );

		expect( error.code ).toBe( 'fetch_error' );
		expect( error.status ).toBe( 0 );
		expect( error.message ).toMatch( /reach the server/ );
	} );

	it( 'maps aborted requests', () => {
		const abort = new Error( 'aborted' );

		abort.name = 'AbortError';

		expect( normalizeError( abort ) ).toEqual( {
			code: ABORTED,
			message: '',
			status: 0,
		} );
	} );

	it( 'is idempotent', () => {
		const error = { code: 'x', message: 'y', status: 409 };

		expect( normalizeError( normalizeError( error ) ) ).toEqual( error );
	} );

	it( 'handles non-object rejections', () => {
		expect( normalizeError( undefined ) ).toEqual(
			expect.objectContaining( { code: 'unknown_error', status: 0 } )
		);
	} );
} );

describe( 'getGovernanceReport', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'requests the governance route with the period', async () => {
		apiFetch.mockResolvedValueOnce( { period_days: 30 } );

		await expect( getGovernanceReport( 30 ) ).resolves.toEqual( {
			period_days: 30,
		} );
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/sit-cwm/v1/reports/governance?period=30',
			} )
		);
	} );

	it( 'passes the abort signal through', async () => {
		const controller = new window.AbortController();

		apiFetch.mockResolvedValueOnce( {} );
		await getGovernanceReport( 90, { signal: controller.signal } );

		expect( apiFetch.mock.calls[ 0 ][ 0 ].signal ).toBe(
			controller.signal
		);
	} );

	it( 'normalises errors like the other wrappers', async () => {
		apiFetch.mockRejectedValueOnce( {
			code: 'sit_cwm_forbidden',
			message: 'Nope.',
			data: { status: 403 },
		} );

		await expect( getGovernanceReport( 90 ) ).rejects.toEqual( {
			code: 'sit_cwm_forbidden',
			message: 'Nope.',
			status: 403,
		} );
	} );
} );
