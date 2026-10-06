/**
 * External dependencies
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

/**
 * Internal dependencies
 */
import { BulkResultNotice } from '../../src/dashboard/bulk/BulkActions';
import ChangeStatusModal from '../../src/dashboard/bulk/ChangeStatusModal';
import {
	BulkActionContext,
	reachableStatuses,
	toBulkRequest,
} from '../../src/dashboard/bulk/shared';

beforeEach( () => {
	window.sitCwm.statuses = [
		{ slug: 'review', label: 'Review' },
		{ slug: 'needs_changes', label: 'Needs Changes' },
		{ slug: 'approved', label: 'Approved' },
		{ slug: 'published', label: 'Published' },
	];
} );

describe( 'reachableStatuses', () => {
	it( 'unions the offered transitions in registry order', () => {
		expect(
			reachableStatuses( [
				{
					available_transitions: [
						{ slug: 'published', label: 'Published' },
						{ slug: 'needs_changes', is_rollback: true },
					],
				},
				{ available_transitions: [ { slug: 'approved' } ] },
				{ available_transitions: null },
			] )
		).toEqual( [
			{ slug: 'needs_changes', label: 'Needs Changes', isRollback: true },
			{ slug: 'approved', label: 'Approved', isRollback: false },
			{ slug: 'published', label: 'Published', isRollback: false },
		] );
	} );
} );

describe( 'BulkResultNotice', () => {
	it( 'shows the summary and lists failures on demand', () => {
		render(
			<BulkResultNotice
				result={ {
					status: 'warning',
					message: '1 post updated. 1 could not be updated.',
					succeeded: [ 4 ],
					failed: [
						{
							post_id: 5,
							code: 'sit_cwm_forbidden',
							message: 'Nope.',
						},
					],
					labels: { 5: 'Launch plan' },
				} }
				onDismiss={ () => {} }
			/>
		);

		expect(
			screen.getByText( '1 post updated. 1 could not be updated.' )
		).toBeTruthy();
		expect( screen.queryByText( 'Launch plan: Nope.' ) ).toBeNull();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show details' } )
		);

		expect( screen.getByText( 'Launch plan: Nope.' ) ).toBeTruthy();
	} );

	it( 'renders nothing without a result', () => {
		const { container } = render(
			<BulkResultNotice result={ null } onDismiss={ () => {} } />
		);

		expect( container.innerHTML ).toBe( '' );
	} );
} );

describe( 'ChangeStatusModal', () => {
	it( 'asks for confirmation naming the count before publishing', async () => {
		const run = jest.fn().mockResolvedValue( {} );
		const closeModal = jest.fn();
		const items = [
			{
				post_id: 1,
				title: 'One',
				available_transitions: [ { slug: 'published' } ],
			},
			{
				post_id: 2,
				title: 'Two',
				available_transitions: [ { slug: 'published' } ],
			},
		];

		const { container } = render(
			<BulkActionContext.Provider
				value={ { run, isRunning: false, onChanged: () => {} } }
			>
				<ChangeStatusModal items={ items } closeModal={ closeModal } />
			</BulkActionContext.Provider>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Apply' } ) );

		expect( run ).not.toHaveBeenCalled();
		/*
		 * Scoped to the render container: Notice announces its text through
		 * @wordpress/a11y, which repeats it in a live region that sits outside
		 * the container but still inside `screen`.
		 */
		expect(
			within( container ).getByText( 'Move 2 items to Published?' )
		).toBeTruthy();

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Yes, update 2 items' } )
		);

		expect( run ).toHaveBeenCalledWith(
			'change_status',
			{ status: 'published' },
			[ 1, 2 ],
			{ labels: { 1: 'One', 2: 'Two' } }
		);
		await waitFor( () => expect( closeModal ).toHaveBeenCalled() );
	} );
} );

describe( 'toBulkRequest', () => {
	it( 'labels items with decoded titles', () => {
		expect(
			toBulkRequest( [
				{ post_id: 1, title: 'Q&amp;A' },
				{ post_id: 2, title: '' },
				{ post_id: 0, title: 'Skipped' },
			] )
		).toEqual( {
			postIds: [ 1, 2 ],
			labels: { 1: 'Q&A', 2: '(no title)' },
		} );
	} );
} );
