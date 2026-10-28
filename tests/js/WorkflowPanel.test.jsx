/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import ClassicPanel from '../../src/classic/ClassicPanel';
import WorkflowPanel from '../../src/components/WorkflowPanel';
import useWorkflow from '../../src/hooks/useWorkflow';

jest.mock( '../../src/hooks/useWorkflow' );

// The classic mount renders snackbars from the notices store; not under test.
jest.mock( '../../src/components/Snackbars', () => () => null );

const LOCKED = 'Publishing is locked until this content is approved.';

/**
 * A writing-stage workflow the current user may submit for review.
 *
 * @param {Object} overrides Fields to replace.
 * @return {Object} Workflow payload.
 */
function makeWorkflow( overrides = {} ) {
	return {
		post_id: 42,
		post_status: 'draft',
		status: 'writing',
		status_label: 'Writing',
		status_is_unknown: false,
		reviewer: null,
		due_date: '',
		available_transitions: [
			{
				slug: 'review',
				label: 'Review',
				is_forward: true,
				is_rollback: false,
			},
		],
		capabilities: { can_change_status: true },
		publish_gate: { mode: 'enforce', outcome: 'refused' },
		...overrides,
	};
}

/**
 * Stubs `useWorkflow()` with a loaded workflow.
 *
 * @param {Object}   workflow     Workflow payload.
 * @param {Function} updateStatus Status mutation.
 */
function mockWorkflow( workflow, updateStatus = jest.fn() ) {
	useWorkflow.mockReturnValue( {
		workflow,
		isLoading: false,
		isSaving: false,
		isGone: false,
		error: null,
		activityVersion: 0,
		updateStatus,
		assignReviewer: jest.fn(),
		setDueDate: jest.fn(),
		addComment: jest.fn(),
		refresh: jest.fn(),
		clearError: jest.fn(),
	} );
}

beforeEach( () => {
	window.sitCwm.postTypes = [ { slug: 'post', label: 'Posts' } ];
	window.sitCwm.statuses = [
		{ slug: 'writing', label: 'Writing', is_final: false },
		{ slug: 'review', label: 'Review', is_final: false },
	];
} );

afterEach( () => {
	jest.resetAllMocks();
} );

describe( 'WorkflowPanel gate notice', () => {
	it( 'shows the locked notice in the classic panel', () => {
		mockWorkflow( makeWorkflow() );

		const { container } = render(
			<ClassicPanel postId={ 42 } postType="post" />
		);

		const notices = container.querySelectorAll( '.sit-cwm-gate-notice' );

		expect( notices ).toHaveLength( 1 );
		expect( notices[ 0 ].textContent ).toContain( LOCKED );
	} );

	it( 'says nothing when the server sends no gate state', () => {
		const workflow = makeWorkflow();
		delete workflow.publish_gate;
		mockWorkflow( workflow );

		const { container } = render( <WorkflowPanel postId={ 42 } /> );

		expect( container.querySelector( '.sit-cwm-gate-notice' ) ).toBeNull();
	} );
} );

describe( 'WorkflowPanel onStatusChanged', () => {
	it( 'is called once after a successful transition', async () => {
		const onStatusChanged = jest.fn();
		const updateStatus = jest.fn().mockResolvedValue( null );
		mockWorkflow( makeWorkflow(), updateStatus );

		render(
			<WorkflowPanel postId={ 42 } onStatusChanged={ onStatusChanged } />
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Move to Review' } )
		);

		expect( updateStatus ).toHaveBeenCalledWith( 'review' );
		expect( onStatusChanged ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'is not called after a failed transition', async () => {
		const onStatusChanged = jest.fn();
		const updateStatus = jest.fn().mockResolvedValue( {
			status: 409,
			message: 'Changed elsewhere.',
		} );
		mockWorkflow( makeWorkflow(), updateStatus );

		render(
			<WorkflowPanel postId={ 42 } onStatusChanged={ onStatusChanged } />
		);

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Move to Review' } )
		);

		expect( updateStatus ).toHaveBeenCalledWith( 'review' );
		expect( onStatusChanged ).not.toHaveBeenCalled();
	} );

	it( 'is optional', async () => {
		const updateStatus = jest.fn().mockResolvedValue( null );
		mockWorkflow( makeWorkflow(), updateStatus );

		render( <WorkflowPanel postId={ 42 } /> );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Move to Review' } )
		);

		expect( updateStatus ).toHaveBeenCalledWith( 'review' );
	} );
} );
