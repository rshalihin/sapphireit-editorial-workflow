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

	describe( 'attestation', () => {
		const STATEMENT =
			'I confirm I have reviewed this content and approve it for publication.';
		const items = [
			{
				post_id: 1,
				title: 'One',
				available_transitions: [
					{ slug: 'needs_changes', is_rollback: true },
					{ slug: 'approved' },
				],
			},
		];

		/**
		 * Renders the modal with a spy for the batch run.
		 *
		 * @return {Function} The `run` spy.
		 */
		const renderModal = () => {
			const run = jest.fn().mockResolvedValue( {} );

			render(
				<BulkActionContext.Provider
					value={ { run, isRunning: false, onChanged: () => {} } }
				>
					<ChangeStatusModal
						items={ items }
						closeModal={ () => {} }
					/>
				</BulkActionContext.Provider>
			);

			return run;
		};

		const apply = () => screen.getByRole( 'button', { name: 'Apply' } );

		beforeEach( () => {
			window.sitCwm.requireAttestation = true;
			window.sitCwm.attestationStatement = STATEMENT;
		} );

		it( 'requires the statement before moving to Approved', async () => {
			const run = renderModal();

			fireEvent.change( screen.getByLabelText( 'New status' ), {
				target: { value: 'approved' },
			} );

			const checkbox = screen.getByRole( 'checkbox', {
				name: STATEMENT,
			} );

			expect( apply().getAttribute( 'aria-disabled' ) ).toBe( 'true' );

			fireEvent.click( apply() );
			expect(
				screen.queryByRole( 'button', { name: 'Yes, update 1 item' } )
			).toBeNull();

			fireEvent.click( checkbox );

			expect( apply().getAttribute( 'aria-disabled' ) ).not.toBe(
				'true'
			);

			fireEvent.click( apply() );
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Yes, update 1 item' } )
			);

			await waitFor( () =>
				expect( run ).toHaveBeenCalledWith(
					'change_status',
					{ status: 'approved', attested: true },
					[ 1 ],
					{ labels: { 1: 'One' } }
				)
			);
		} );

		it( 'shows no checkbox and sends no attested for other targets', async () => {
			const run = renderModal();

			expect( screen.getByLabelText( 'New status' ).value ).toBe(
				'needs_changes'
			);
			expect( screen.queryByRole( 'checkbox' ) ).toBeNull();

			fireEvent.click( apply() );
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Yes, update 1 item' } )
			);

			await waitFor( () =>
				expect( run ).toHaveBeenCalledWith(
					'change_status',
					{ status: 'needs_changes' },
					[ 1 ],
					{ labels: { 1: 'One' } }
				)
			);
		} );

		it( 'shows no checkbox when the site does not require it', () => {
			window.sitCwm.requireAttestation = false;
			renderModal();

			fireEvent.change( screen.getByLabelText( 'New status' ), {
				target: { value: 'approved' },
			} );

			expect( screen.queryByRole( 'checkbox' ) ).toBeNull();
			expect( apply().getAttribute( 'aria-disabled' ) ).not.toBe(
				'true'
			);
		} );
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
