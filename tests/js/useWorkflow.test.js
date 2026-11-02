/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * External dependencies
 */
import { act, renderHook, waitFor } from '@testing-library/react';

/**
 * Internal dependencies
 */
import useWorkflow from '../../src/hooks/useWorkflow';

jest.mock( '@wordpress/api-fetch' );

const workflow = ( overrides = {} ) => ( {
	post_id: 42,
	post_type: 'post',
	status: 'review',
	status_label: 'Review',
	reviewer: null,
	due_date: '',
	available_transitions: [
		{
			slug: 'approved',
			label: 'Approved',
			is_forward: true,
			is_rollback: false,
		},
	],
	capabilities: { can_change_status: true },
	...overrides,
} );

const restError = ( code, message, status ) => ( {
	code,
	message,
	data: { status },
} );

describe( 'useWorkflow', () => {
	afterEach( () => {
		apiFetch.mockReset();
		dispatch( noticesStore ).removeNotice( 'sit-cwm-status-changed' );
	} );

	it( 'loads the workflow on mount', async () => {
		apiFetch.mockResolvedValueOnce( workflow() );

		const { result } = renderHook( () => useWorkflow( 42 ) );

		expect( result.current.isLoading ).toBe( true );
		expect( result.current.workflow ).toBeNull();

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

		expect( result.current.workflow ).toEqual( workflow() );
		expect( result.current.error ).toBeNull();
		expect( apiFetch ).toHaveBeenCalledWith(
			expect.objectContaining( { path: '/sit-cwm/v1/posts/42/workflow' } )
		);
	} );

	it( 'exposes a normalized error when loading fails', async () => {
		apiFetch.mockRejectedValueOnce(
			restError( 'sit_cwm_not_managed', 'No workflow.', 404 )
		);

		const { result } = renderHook( () => useWorkflow( 42 ) );

		await waitFor( () => expect( result.current.isLoading ).toBe( false ) );

		expect( result.current.workflow ).toBeNull();
		expect( result.current.error ).toEqual( {
			code: 'sit_cwm_not_managed',
			message: 'No workflow.',
			status: 404,
		} );
	} );

	it( 'aborts the request on unmount', () => {
		apiFetch.mockReturnValueOnce( new Promise( () => {} ) );

		const { unmount } = renderHook( () => useWorkflow( 42 ) );
		const { signal } = apiFetch.mock.calls[ 0 ][ 0 ];

		expect( signal.aborted ).toBe( false );
		unmount();
		expect( signal.aborted ).toBe( true );
	} );

	it( 'marks the post as gone on a 404 mutation and refuses later ones', async () => {
		apiFetch
			.mockResolvedValueOnce( workflow() )
			.mockRejectedValueOnce(
				restError( 'sit_cwm_not_managed', 'No workflow.', 404 )
			);

		const { result } = renderHook( () => useWorkflow( 42 ) );

		await waitFor( () => expect( result.current.workflow ).not.toBeNull() );

		expect( result.current.isGone ).toBe( false );

		await act( async () => {
			await result.current.setDueDate( '2026-09-30' );
		} );

		expect( result.current.isGone ).toBe( true );
		// The last known state stays visible behind the notice.
		expect( result.current.workflow ).toEqual( workflow() );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );

		let error;

		await act( async () => {
			error = await result.current.updateStatus( 'approved' );
		} );

		expect( error ).toEqual( expect.objectContaining( { status: 404 } ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'sends from/status and applies the server response', async () => {
		apiFetch.mockResolvedValueOnce( workflow() ).mockResolvedValueOnce(
			workflow( {
				status: 'approved',
				status_label: 'Approved',
				available_transitions: [],
			} )
		);

		const { result } = renderHook( () => useWorkflow( 42 ) );

		await waitFor( () => expect( result.current.workflow ).not.toBeNull() );

		let error;

		await act( async () => {
			error = await result.current.updateStatus( 'approved' );
		} );

		expect( error ).toBeNull();
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/sit-cwm/v1/posts/42/workflow',
			method: 'POST',
			data: { from: 'review', status: 'approved' },
		} );
		expect( result.current.workflow.status ).toBe( 'approved' );
		expect( result.current.workflow.available_transitions ).toEqual( [] );
		expect( result.current.isSaving ).toBe( false );
		expect( result.current.activityVersion ).toBe( 1 );
		expect( select( noticesStore ).getNotices() ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					id: 'sit-cwm-status-changed',
					content: 'Workflow status changed to Approved.',
				} ),
			] )
		);
	} );

	it( 'declares AI use and applies the server response', async () => {
		apiFetch.mockResolvedValueOnce( workflow() ).mockResolvedValueOnce(
			workflow( {
				ai_origin: {
					value: 'human',
					label: 'Human-written',
					can_declare: true,
				},
			} )
		);

		const { result } = renderHook( () => useWorkflow( 42 ) );

		await waitFor( () => expect( result.current.workflow ).not.toBeNull() );

		let error;

		await act( async () => {
			error = await result.current.setAiOrigin( 'human' );
		} );

		expect( error ).toBeNull();
		expect( apiFetch ).toHaveBeenLastCalledWith( {
			path: '/sit-cwm/v1/posts/42/workflow',
			method: 'POST',
			data: { ai_origin: 'human' },
		} );
		expect( result.current.workflow.ai_origin.value ).toBe( 'human' );
		expect( result.current.activityVersion ).toBe( 1 );
		expect( select( noticesStore ).getNotices() ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					id: 'sit-cwm-ai-origin-changed',
					content: 'AI use declared as Human-written.',
				} ),
			] )
		);

		dispatch( noticesStore ).removeNotice( 'sit-cwm-ai-origin-changed' );
	} );

	it( 'refetches and reports a conflict on 409 without retrying', async () => {
		apiFetch
			.mockResolvedValueOnce( workflow() )
			.mockRejectedValueOnce(
				restError( 'sit_cwm_status_conflict', 'Changed.', 409 )
			)
			.mockResolvedValueOnce(
				workflow( { status: 'approved', status_label: 'Approved' } )
			);

		const { result } = renderHook( () => useWorkflow( 42 ) );

		await waitFor( () => expect( result.current.workflow ).not.toBeNull() );

		let error;

		await act( async () => {
			error = await result.current.updateStatus( 'approved' );
		} );

		expect( apiFetch ).toHaveBeenCalledTimes( 3 );
		expect( apiFetch.mock.calls[ 2 ][ 0 ] ).toEqual(
			expect.objectContaining( { path: '/sit-cwm/v1/posts/42/workflow' } )
		);
		expect( apiFetch.mock.calls[ 2 ][ 0 ].method ).toBeUndefined();
		expect( error.status ).toBe( 409 );
		expect( result.current.error.message ).toBe(
			'This post changed elsewhere — refreshed.'
		);
		expect( result.current.workflow.status ).toBe( 'approved' );
		expect( result.current.activityVersion ).toBe( 0 );
	} );

	it( 'reports an extension veto verbatim and refetches', async () => {
		const blocked = {
			slug: 'approved',
			label: 'Approved',
			code: 'checklist_incomplete',
			message: 'The checklist is not complete.',
		};

		apiFetch
			.mockResolvedValueOnce( workflow() )
			.mockRejectedValueOnce(
				restError(
					'checklist_incomplete',
					'The checklist is not complete.',
					422
				)
			)
			.mockResolvedValueOnce(
				workflow( {
					available_transitions: [],
					blocked_transitions: [ blocked ],
				} )
			);

		const { result } = renderHook( () => useWorkflow( 42 ) );

		await waitFor( () => expect( result.current.workflow ).not.toBeNull() );

		let error;

		await act( async () => {
			error = await result.current.updateStatus( 'approved' );
		} );

		expect( apiFetch ).toHaveBeenCalledTimes( 3 );
		expect( apiFetch.mock.calls[ 2 ][ 0 ] ).toEqual(
			expect.objectContaining( { path: '/sit-cwm/v1/posts/42/workflow' } )
		);
		expect( apiFetch.mock.calls[ 2 ][ 0 ].method ).toBeUndefined();
		expect( error ).toEqual( {
			code: 'checklist_incomplete',
			message: 'The checklist is not complete.',
			status: 422,
		} );
		expect( result.current.error ).toEqual( error );
		expect( result.current.workflow.blocked_transitions ).toEqual( [
			blocked,
		] );
		expect( result.current.workflow.status ).toBe( 'review' );
	} );

	it( 'returns comment errors without setting the hook error', async () => {
		apiFetch
			.mockResolvedValueOnce( workflow() )
			.mockRejectedValueOnce(
				restError( 'sit_cwm_empty_comment', 'Empty.', 400 )
			);

		const { result } = renderHook( () => useWorkflow( 42 ) );

		await waitFor( () => expect( result.current.workflow ).not.toBeNull() );

		let error;

		await act( async () => {
			error = await result.current.addComment( ' ' );
		} );

		expect( error ).toEqual( {
			code: 'sit_cwm_empty_comment',
			message: 'Empty.',
			status: 400,
		} );
		expect( result.current.error ).toBeNull();
	} );
} );
