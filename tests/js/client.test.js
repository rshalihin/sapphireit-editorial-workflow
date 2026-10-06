/**
 * Internal dependencies
 */
import { ABORTED, normalizeError } from '../../src/api/client';

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
