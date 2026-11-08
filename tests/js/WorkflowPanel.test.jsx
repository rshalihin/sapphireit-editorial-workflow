/**
 * WordPress dependencies
 */
import {
	addFilter,
	doAction,
	hasAction,
	removeAllFilters,
} from '@wordpress/hooks';

/**
 * External dependencies
 */
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import ClassicPanel from '../../src/classic/ClassicPanel';
import WorkflowPanel from '../../src/components/WorkflowPanel';
import useWorkflow from '../../src/hooks/useWorkflow';

jest.mock( '../../src/hooks/useWorkflow' );

// Loads its own data; not under test.
jest.mock( '../../src/components/ActivityTimeline', () => () => null );

// The classic mount renders snackbars from the notices store; not under test.
jest.mock( '../../src/components/Snackbars', () => () => null );

const LOCKED = 'Publishing is locked until this content is approved.';
const SELF_APPROVAL =
	"You can't approve your own content. Another reviewer has to approve it.";

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

describe( 'WorkflowPanel self-approval note', () => {
	it( 'explains the missing Approve in the classic panel', () => {
		mockWorkflow( makeWorkflow( { self_approval_blocked: true } ) );

		render( <ClassicPanel postId={ 42 } postType="post" /> );

		expect( screen.getByText( SELF_APPROVAL ) ).toBeTruthy();
	} );

	it( 'says nothing when the rule is not the reason', () => {
		mockWorkflow( makeWorkflow( { self_approval_blocked: false } ) );

		render( <WorkflowPanel postId={ 42 } /> );

		expect( screen.queryByText( SELF_APPROVAL ) ).toBeNull();
	} );

	it( 'says nothing when an older server sends no flag', () => {
		mockWorkflow( makeWorkflow() );

		render( <WorkflowPanel postId={ 42 } /> );

		expect( screen.queryByText( SELF_APPROVAL ) ).toBeNull();
	} );
} );

describe( 'WorkflowPanel blocked transitions', () => {
	const CHECKLIST = 'The checklist is not complete.';

	const reviewStage = ( overrides = {} ) =>
		makeWorkflow( {
			status: 'review',
			status_label: 'Review',
			available_transitions: [
				{
					slug: 'needs_changes',
					label: 'Needs Changes',
					is_forward: false,
					is_rollback: true,
				},
			],
			...overrides,
		} );

	it.each( [
		[ 'block editor', () => <WorkflowPanel postId={ 42 } /> ],
		[ 'classic', () => <ClassicPanel postId={ 42 } postType="post" /> ],
	] )(
		'shows the vetoed move disabled, with its reason, in the %s panel',
		( name, panel ) => {
			mockWorkflow(
				reviewStage( {
					blocked_transitions: [
						{
							slug: 'approved',
							label: 'Approved',
							code: 'checklist_incomplete',
							message: CHECKLIST,
						},
					],
				} )
			);

			render( panel() );

			const offered = screen.getByRole( 'button', {
				name: 'Move to Needs Changes',
			} );
			const blocked = screen.getByRole( 'button', {
				name: 'Move to Approved',
			} );

			expect( offered.getAttribute( 'aria-disabled' ) ).toBeNull();
			expect( offered.disabled ).toBe( false );
			expect( blocked.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
			expect( screen.getByText( CHECKLIST ) ).toBeTruthy();
		}
	);

	it( 'shows no blocked section when an older server sends none', () => {
		mockWorkflow( reviewStage() );

		const { container } = render( <WorkflowPanel postId={ 42 } /> );

		expect(
			screen.getByRole( 'button', { name: 'Move to Needs Changes' } )
		).toBeTruthy();
		expect( container.querySelector( '.sit-cwm-blocked' ) ).toBeNull();
	} );
} );

describe( 'WorkflowPanel AI use', () => {
	it( 'shows the control and saves through setAiOrigin', async () => {
		const setAiOrigin = jest.fn().mockResolvedValue( null );
		mockWorkflow(
			makeWorkflow( {
				ai_origin: {
					value: '',
					label: 'Not declared',
					can_declare: true,
				},
			} )
		);
		useWorkflow.mockReturnValue( {
			...useWorkflow(),
			setAiOrigin,
		} );

		render( <ClassicPanel postId={ 42 } postType="post" /> );

		await userEvent.selectOptions(
			screen.getByRole( 'combobox', { name: 'AI use' } ),
			'ai_assisted'
		);

		expect( setAiOrigin ).toHaveBeenCalledWith( 'ai_assisted' );
	} );

	it( 'is absent when an older server sends no AI use', () => {
		mockWorkflow( makeWorkflow() );

		render( <WorkflowPanel postId={ 42 } /> );

		expect(
			screen.queryByRole( 'combobox', { name: 'AI use' } )
		).toBeNull();
		expect( screen.queryByText( 'AI use' ) ).toBeNull();
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

describe( 'WorkflowPanel attestation', () => {
	const STATEMENT =
		'I confirm I have reviewed this content and approve it for publication.';

	it( 'gates Approve on the payload attestation and forwards it', async () => {
		const updateStatus = jest.fn().mockResolvedValue( null );
		mockWorkflow(
			makeWorkflow( {
				status: 'review',
				status_label: 'Review',
				available_transitions: [
					{
						slug: 'approved',
						label: 'Approved',
						is_forward: true,
						is_rollback: false,
					},
				],
				attestation: { required: true, statement: STATEMENT },
			} ),
			updateStatus
		);

		render( <ClassicPanel postId={ 42 } postType="post" /> );

		const approve = screen.getByRole( 'button', {
			name: 'Move to Approved',
		} );

		expect( approve.getAttribute( 'aria-disabled' ) ).toBe( 'true' );

		await userEvent.click(
			screen.getByRole( 'checkbox', { name: STATEMENT } )
		);
		await userEvent.click( approve );
		await userEvent.click(
			[
				...screen.getByRole( 'dialog' ).querySelectorAll( 'button' ),
			].find( ( button ) => button.textContent === 'Approve' )
		);

		expect( updateStatus ).toHaveBeenCalledWith( 'approved', {
			attested: true,
		} );
	} );

	it( 'shows no checkbox when an older server sends no attestation', () => {
		mockWorkflow(
			makeWorkflow( {
				available_transitions: [
					{
						slug: 'approved',
						label: 'Approved',
						is_forward: true,
						is_rollback: false,
					},
				],
			} )
		);

		render( <WorkflowPanel postId={ 42 } /> );

		expect( screen.queryByRole( 'checkbox' ) ).toBeNull();
	} );
} );

describe( 'WorkflowPanel extension points', () => {
	afterEach( () => {
		removeAllFilters( 'sitCwm.workflowPanel.sections' );
	} );

	it( 'renders an extension section after the actions, before the activity', () => {
		addFilter( 'sitCwm.workflowPanel.sections', 'test', () => [
			{ name: 'x', order: 1, render: () => <p>Extra section</p> },
		] );
		mockWorkflow(
			makeWorkflow( {
				capabilities: {
					can_change_status: true,
					can_view_activity: true,
				},
			} )
		);

		const { container } = render( <WorkflowPanel postId={ 42 } /> );
		const text = container.textContent;

		expect( text.indexOf( 'Workflow actions' ) ).toBeLessThan(
			text.indexOf( 'Extra section' )
		);
		expect( text.indexOf( 'Extra section' ) ).toBeLessThan(
			text.indexOf( 'Activity' )
		);
	} );

	it( 'renders no extension section before the workflow has loaded', () => {
		const filter = jest.fn( () => [] );

		addFilter( 'sitCwm.workflowPanel.sections', 'test', filter );
		mockWorkflow( null );
		useWorkflow.mockReturnValue( {
			...useWorkflow(),
			isLoading: true,
		} );

		render( <WorkflowPanel postId={ 42 } /> );

		expect( filter ).not.toHaveBeenCalled();
	} );

	it( 'refetches on a refresh request for its own post only', () => {
		mockWorkflow( makeWorkflow() );

		render( <WorkflowPanel postId={ 5 } /> );

		const { refresh } = useWorkflow.mock.results[ 0 ].value;

		act( () => doAction( 'sitCwm.workflow.refresh', { postId: 6 } ) );
		expect( refresh ).not.toHaveBeenCalled();

		act( () => doAction( 'sitCwm.workflow.refresh', { postId: 5 } ) );
		expect( refresh ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops listening when it unmounts', () => {
		mockWorkflow( makeWorkflow() );

		const { unmount } = render( <WorkflowPanel postId={ 5 } /> );

		expect(
			hasAction( 'sitCwm.workflow.refresh', 'sit-cwm/workflow-panel-5' )
		).toBeTruthy();

		unmount();

		expect(
			hasAction( 'sitCwm.workflow.refresh', 'sit-cwm/workflow-panel-5' )
		).toBeFalsy();
	} );
} );
