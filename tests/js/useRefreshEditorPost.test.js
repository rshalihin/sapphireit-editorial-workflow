/**
 * WordPress dependencies
 */
import { resolveSelect, useDispatch, useSelect } from '@wordpress/data';

/**
 * External dependencies
 */
import { renderHook } from '@testing-library/react';

/**
 * Internal dependencies
 */
import useRefreshEditorPost from '../../src/hooks/useRefreshEditorPost';

jest.mock( '@wordpress/data', () => ( {
	resolveSelect: jest.fn(),
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/core-data', () => ( { store: 'core' } ) );

jest.mock( '@wordpress/editor', () => ( { store: 'core/editor' } ) );

describe( 'useRefreshEditorPost', () => {
	let invalidateResolution;
	let getEntityRecord;

	beforeEach( () => {
		invalidateResolution = jest.fn();
		getEntityRecord = jest.fn().mockResolvedValue( { id: 42 } );

		useSelect.mockImplementation( ( mapSelect ) =>
			mapSelect( ( store ) =>
				store === 'core/editor'
					? {
							getCurrentPostType: () => 'post',
							getCurrentPostId: () => 42,
						}
					: {}
			)
		);
		useDispatch.mockImplementation( ( store ) =>
			store === 'core' ? { invalidateResolution } : {}
		);
		resolveSelect.mockImplementation( ( store ) =>
			store === 'core' ? { getEntityRecord } : {}
		);
	} );

	afterEach( () => {
		jest.clearAllMocks();
	} );

	it( 'invalidates, then re-fetches the current editor post', async () => {
		const { result } = renderHook( () => useRefreshEditorPost() );

		await expect( result.current() ).resolves.toBeUndefined();

		expect( invalidateResolution ).toHaveBeenCalledWith(
			'getEntityRecord',
			[ 'postType', 'post', 42 ]
		);
		expect( getEntityRecord ).toHaveBeenCalledWith(
			'postType',
			'post',
			42
		);
		expect(
			invalidateResolution.mock.invocationCallOrder[ 0 ]
		).toBeLessThan( getEntityRecord.mock.invocationCallOrder[ 0 ] );
	} );

	it( 'never rejects when the re-fetch fails', async () => {
		getEntityRecord.mockRejectedValue( new Error( 'offline' ) );

		const { result } = renderHook( () => useRefreshEditorPost() );

		await expect( result.current() ).resolves.toBeUndefined();
	} );
} );
