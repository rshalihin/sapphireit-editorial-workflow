/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * External dependencies
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';

/**
 * Internal dependencies
 */
import TransitionModal from '../../src/dashboard/modals/TransitionModal';
import ReviewerModal from '../../src/dashboard/modals/ReviewerModal';
import DueDateModal from '../../src/dashboard/modals/DueDateModal';
import AssignReviewerModal from '../../src/dashboard/bulk/AssignReviewerModal';
import SetDueDateModal from '../../src/dashboard/bulk/SetDueDateModal';
import { BulkActionContext } from '../../src/dashboard/bulk/shared';

jest.mock( '@wordpress/api-fetch' );

const jane = { id: 4, name: 'Jane', avatar: 'https://example.org/j.png' };

const row = ( overrides = {} ) => ( {
	post_id: 12,
	title: 'Launch plan',
	status: 'review',
	status_label: 'Review',
	reviewer: null,
	due_date: '',
	available_transitions: [
		{ slug: 'approved', label: 'Approved' },
		{ slug: 'needs_changes', label: 'Needs Changes', is_rollback: true },
	],
	...overrides,
} );

const restError = ( code, status, message = 'Nope.' ) => ( {
	code,
	message,
	data: { status },
} );

/**
 * Snackbar texts currently in the notices store.
 *
 * @return {string[]} Contents.
 */
const snackbars = () =>
	select( noticesStore )
		.getNotices()
		.map( ( notice ) => notice.content );

/**
 * Renders a row modal with spies for its callbacks.
 *
 * @param {Function} Component Modal component.
 * @param {Object}   props     Extra props.
 * @return {Object} `{ closeModal, onChanged }`.
 */
const renderModal = ( Component, props ) => {
	const closeModal = jest.fn();
	const onChanged = jest.fn();

	render(
		<Component
			closeModal={ closeModal }
			onChanged={ onChanged }
			{ ...props }
		/>
	);

	return { closeModal, onChanged };
};

/**
 * Body of the `n`th apiFetch call.
 *
 * @param {number} n Call index.
 * @return {Object} `{ path, method, data }`.
 */
const call = ( n ) => apiFetch.mock.calls[ n ][ 0 ];

/**
 * Text of the bulk confirmation notice. `Notice` also announces it in a live
 * region, so a plain text query would find it twice.
 *
 * @return {?string} Message, or null when not asking.
 */
const confirmation = () =>
	document.querySelector(
		'.sit-cwm-bulk-confirm .components-notice__content'
	)?.firstChild?.textContent ?? null;

beforeAll( () => {
	// jsdom has no layout; `ComboboxControl` scrolls the highlighted option.
	window.HTMLElement.prototype.scrollIntoView = () => {};
} );

beforeEach( () => {
	apiFetch.mockReset();
	select( noticesStore )
		.getNotices()
		.forEach( ( notice ) =>
			dispatch( noticesStore ).removeNotice( notice.id )
		);
} );

describe( 'TransitionModal', () => {
	it( 'explains when the server no longer offers the move', () => {
		const { closeModal } = renderModal( TransitionModal, {
			items: [ row( { available_transitions: [] } ) ],
			to: 'approved',
			submitLabel: 'Approve',
		} );

		expect(
			screen.getByText(
				'This action is no longer available for this content.'
			)
		).toBeTruthy();

		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		expect( closeModal ).toHaveBeenCalled();
	} );

	it( 'moves the row with its shown status as `from`, then comments', async () => {
		apiFetch.mockResolvedValue( {} );

		const { closeModal, onChanged } = renderModal( TransitionModal, {
			items: [ row() ],
			to: 'needs_changes',
			submitLabel: 'Request changes',
			withComment: true,
		} );

		expect(
			screen.getByText(
				'Move “Launch plan” from Review to Needs Changes?'
			)
		).toBeTruthy();

		fireEvent.change( screen.getByLabelText( 'Comment (optional)' ), {
			target: { value: '  Tighten the intro.  ' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Request changes' } )
		);

		await waitFor( () => expect( onChanged ).toHaveBeenCalled() );

		expect( call( 0 ) ).toMatchObject( {
			path: '/sit-cwm/v1/posts/12/workflow',
			method: 'POST',
			data: { from: 'review', status: 'needs_changes' },
		} );
		expect( call( 1 ) ).toMatchObject( {
			path: '/sit-cwm/v1/posts/12/comments',
			data: { message: 'Tighten the intro.' },
		} );
		expect( closeModal ).toHaveBeenCalled();
		expect( snackbars() ).toEqual( [
			'“Launch plan” moved to Needs Changes.',
		] );
	} );

	it( 'warns when the move worked but the comment did not', async () => {
		apiFetch
			.mockResolvedValueOnce( {} )
			.mockRejectedValueOnce(
				restError( 'rest_invalid_param', 400, 'Too long.' )
			);

		const { onChanged } = renderModal( TransitionModal, {
			items: [ row() ],
			to: 'approved',
			submitLabel: 'Approve',
			withComment: true,
		} );

		fireEvent.change( screen.getByLabelText( 'Comment (optional)' ), {
			target: { value: 'Looks good' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Approve' } ) );

		await waitFor( () => expect( onChanged ).toHaveBeenCalled() );

		expect( snackbars() ).toEqual( [
			'Moved to Approved, but the comment could not be saved: Too long.',
		] );
	} );

	it( 'keeps a validation error inside the modal', async () => {
		apiFetch.mockRejectedValueOnce(
			restError( 'sit_cwm_invalid_transition', 400, 'Not allowed.' )
		);

		const { closeModal, onChanged } = renderModal( TransitionModal, {
			items: [ row() ],
			to: 'approved',
			submitLabel: 'Approve',
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Approve' } ) );

		// Scoped to the notice: Notice also speak()s the message into an
		// aria-live region, which would otherwise be a second match.
		expect(
			await screen.findByText( 'Not allowed.', {
				selector: '.components-notice__content',
			} )
		).toBeTruthy();
		expect( closeModal ).not.toHaveBeenCalled();
		expect( onChanged ).not.toHaveBeenCalled();
	} );

	it( 'closes and refreshes when the row is stale', async () => {
		apiFetch.mockRejectedValueOnce(
			restError( 'sit_cwm_status_conflict', 409 )
		);

		const { closeModal, onChanged } = renderModal( TransitionModal, {
			items: [ row() ],
			to: 'approved',
			submitLabel: 'Approve',
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Approve' } ) );

		await waitFor( () => expect( onChanged ).toHaveBeenCalled() );

		expect( closeModal ).toHaveBeenCalled();
		expect( snackbars() ).toEqual( [
			'This post changed elsewhere — refreshed.',
		] );
	} );

	it( 'reports a lost permission with the server message', async () => {
		apiFetch.mockRejectedValueOnce(
			restError( 'sit_cwm_forbidden', 403, 'You may not do that.' )
		);

		const { onChanged } = renderModal( TransitionModal, {
			items: [ row() ],
			to: 'approved',
			submitLabel: 'Approve',
		} );

		fireEvent.click( screen.getByRole( 'button', { name: 'Approve' } ) );

		await waitFor( () => expect( onChanged ).toHaveBeenCalled() );
		expect( snackbars() ).toEqual( [ 'You may not do that.' ] );
	} );
} );

describe( 'ReviewerModal', () => {
	it( 'removes the current reviewer', async () => {
		apiFetch.mockImplementation( ( { path } ) =>
			Promise.resolve( path.includes( '/users' ) ? [ jane ] : {} )
		);

		const { onChanged } = renderModal( ReviewerModal, {
			items: [ row( { reviewer: jane } ) ],
		} );

		const save = screen.getByRole( 'button', { name: 'Save' } );
		expect( save.getAttribute( 'aria-disabled' ) ).toBe( 'true' );

		const input = screen.getByRole( 'combobox', { name: 'Reviewer' } );
		fireEvent.focus( input );
		fireEvent.change( input, { target: { value: 'Unassigned' } } );
		fireEvent.click(
			await screen.findByRole( 'option', { name: '— Unassigned —' } )
		);

		fireEvent.click( save );

		await waitFor( () => expect( onChanged ).toHaveBeenCalled() );

		const update = apiFetch.mock.calls
			.map( ( [ options ] ) => options )
			.find( ( options ) => options.method === 'POST' );
		expect( update ).toMatchObject( {
			path: '/sit-cwm/v1/posts/12/workflow',
			data: { reviewer_id: 0 },
		} );
		expect( snackbars() ).toEqual( [
			'Reviewer removed from “Launch plan”.',
		] );
	} );
} );

describe( 'DueDateModal', () => {
	it( 'removes the due date', async () => {
		apiFetch.mockResolvedValue( {} );

		const { onChanged } = renderModal( DueDateModal, {
			items: [ row( { due_date: '2026-10-01' } ) ],
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove due date' } )
		);

		await waitFor( () => expect( onChanged ).toHaveBeenCalled() );

		expect( call( 0 ).data ).toEqual( { due_date: '' } );
		expect( snackbars() ).toEqual( [
			'Due date removed from “Launch plan”.',
		] );
	} );

	it( 'names the post with its entities decoded', async () => {
		apiFetch.mockResolvedValue( {} );

		const { onChanged } = renderModal( DueDateModal, {
			items: [ row( { title: 'Q&amp;A', due_date: '2026-10-01' } ) ],
		} );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove due date' } )
		);

		await waitFor( () => expect( onChanged ).toHaveBeenCalled() );

		expect( snackbars() ).toEqual( [ 'Due date removed from “Q&A”.' ] );
	} );

	it( 'offers no removal when there is no due date', () => {
		renderModal( DueDateModal, { items: [ row() ] } );

		expect(
			screen.queryByRole( 'button', { name: 'Remove due date' } )
		).toBeNull();
		expect(
			screen
				.getByRole( 'button', { name: 'Save' } )
				.getAttribute( 'aria-disabled' )
		).toBe( 'true' );
	} );
} );

/**
 * Renders a bulk modal inside the bulk context.
 *
 * @param {Function} Component Modal component.
 * @param {Object[]} items     Selected rows.
 * @return {Object} `{ run, closeModal, onChanged }`.
 */
const renderBulk = ( Component, items ) => {
	const run = jest.fn().mockResolvedValue( {} );
	const closeModal = jest.fn();
	const onChanged = jest.fn();

	render(
		<BulkActionContext.Provider
			value={ { run, isRunning: false, onChanged } }
		>
			<Component items={ items } closeModal={ closeModal } />
		</BulkActionContext.Provider>
	);

	return { run, closeModal, onChanged };
};

const two = [
	row( { post_id: 1, title: 'One' } ),
	row( { post_id: 2, title: 'Two' } ),
];

describe( 'AssignReviewerModal', () => {
	it( 'uses the row modal for a single item', async () => {
		apiFetch.mockResolvedValue( [] );

		renderBulk( AssignReviewerModal, [ row() ] );

		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeTruthy();
		await waitFor( () => expect( apiFetch ).toHaveBeenCalled() );
	} );

	it( 'confirms before assigning a reviewer to several items', async () => {
		apiFetch.mockResolvedValue( [ jane ] );

		const { run, closeModal } = renderBulk( AssignReviewerModal, two );

		expect(
			screen.getByText( 'Set the reviewer of 2 selected items.' )
		).toBeTruthy();

		const input = screen.getByRole( 'combobox', { name: 'Reviewer' } );
		fireEvent.focus( input );
		fireEvent.change( input, { target: { value: 'Jan' } } );
		fireEvent.click(
			await screen.findByRole( 'option', { name: 'Jane' } )
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Apply' } ) );

		expect( confirmation() ).toBe( 'Assign Jane as reviewer of 2 items?' );
		expect( run ).not.toHaveBeenCalled();

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Yes, update 2 items' } )
			);
		} );

		expect( run ).toHaveBeenCalledWith(
			'assign_reviewer',
			{ reviewer_id: 4 },
			[ 1, 2 ],
			{ labels: { 1: 'One', 2: 'Two' } }
		);
		expect( closeModal ).toHaveBeenCalled();
	} );
} );

describe( 'SetDueDateModal', () => {
	it( 'uses the row modal for a single item', () => {
		renderBulk( SetDueDateModal, [ row( { due_date: '2026-10-01' } ) ] );

		expect(
			screen.getByRole( 'button', { name: 'Remove due date' } )
		).toBeTruthy();
	} );

	it( 'confirms before removing due dates from several items', async () => {
		const { run, closeModal } = renderBulk( SetDueDateModal, two );

		expect(
			screen
				.getByRole( 'button', { name: 'Apply' } )
				.getAttribute( 'aria-disabled' )
		).toBe( 'true' );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove due dates' } )
		);

		expect( confirmation() ).toBe( 'Remove the due date from 2 items?' );

		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', {
					name: 'Yes, remove from 2 items',
				} )
			);
		} );

		expect( run ).toHaveBeenCalledWith(
			'set_due_date',
			{ due_date: '' },
			[ 1, 2 ],
			{ labels: { 1: 'One', 2: 'Two' } }
		);
		expect( closeModal ).toHaveBeenCalled();
	} );
} );
