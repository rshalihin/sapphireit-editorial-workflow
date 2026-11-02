/**
 * External dependencies
 */
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import TransitionActions from '../../src/sidebar/components/TransitionActions';

const toReview = {
	slug: 'review',
	label: 'Review',
	is_forward: true,
	is_rollback: false,
};
const approve = {
	slug: 'approved',
	label: 'Approved',
	is_forward: true,
	is_rollback: false,
};
const publish = {
	slug: 'published',
	label: 'Published',
	is_forward: true,
	is_rollback: false,
};
const STATEMENT =
	'I confirm I have reviewed this content and approve it for publication.';
const attestation = { required: true, statement: STATEMENT };
const sendBack = {
	slug: 'needs_changes',
	label: 'Needs Changes',
	is_forward: false,
	is_rollback: true,
};

/**
 * The button inside the open dialog with the given text.
 *
 * @param {string} text Button text.
 * @return {HTMLElement} Button.
 */
function dialogButton( text ) {
	return [
		...screen.getByRole( 'dialog' ).querySelectorAll( 'button' ),
	].find( ( button ) => button.textContent === text );
}

describe( 'TransitionActions', () => {
	beforeEach( () => {
		window.sitCwm.statuses = [
			{ slug: 'review', label: 'Review', is_final: false },
			{ slug: 'approved', label: 'Approved', is_final: false },
			{ slug: 'needs_changes', label: 'Needs Changes', is_final: false },
			{ slug: 'published', label: 'Published', is_final: true },
		];
	} );

	it( 'renders exactly the allowed transitions', () => {
		render(
			<TransitionActions
				transitions={ [ approve, sendBack ] }
				onTransition={ jest.fn() }
				isSaving={ false }
			/>
		);

		expect(
			screen
				.getAllByRole( 'button' )
				.map( ( button ) => button.textContent )
		).toEqual( [ 'Move to Approved', 'Move to Needs Changes' ] );
		expect(
			screen
				.getByRole( 'button', { name: 'Move to Needs Changes' } )
				.classList.contains( 'is-destructive' )
		).toBe( true );
	} );

	it( 'explains why nothing is offered', () => {
		render(
			<TransitionActions
				transitions={ [] }
				onTransition={ jest.fn() }
				isSaving={ false }
			/>
		);

		expect( screen.queryAllByRole( 'button' ) ).toEqual( [] );
		expect(
			screen.getByText( /No workflow actions are available/ )
		).toBeTruthy();
	} );

	it( 'runs a plain forward transition directly', async () => {
		const onTransition = jest.fn().mockResolvedValue( null );
		const user = userEvent.setup();

		render(
			<TransitionActions
				transitions={ [ toReview ] }
				onTransition={ onTransition }
				isSaving={ false }
			/>
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Move to Review' } )
		);

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( onTransition ).toHaveBeenCalledWith( 'review' );
	} );

	it( 'confirms approval with the approval copy', async () => {
		const onTransition = jest.fn().mockResolvedValue( null );
		const user = userEvent.setup();

		render(
			<TransitionActions
				transitions={ [ approve ] }
				onTransition={ onTransition }
				isSaving={ false }
			/>
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Move to Approved' } )
		);

		expect( onTransition ).not.toHaveBeenCalled();
		expect( screen.getByText( 'Approve content?' ) ).toBeTruthy();
		expect(
			screen.getByText( 'This will mark the content as approved.' )
		).toBeTruthy();

		await user.click( dialogButton( 'Approve' ) );

		expect( onTransition ).toHaveBeenCalledWith( 'approved' );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	} );

	it( 'confirms a move into a final status', async () => {
		const onTransition = jest.fn().mockResolvedValue( null );
		const user = userEvent.setup();

		render(
			<TransitionActions
				transitions={ [ publish ] }
				onTransition={ onTransition }
				isSaving={ false }
			/>
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Move to Published' } )
		);

		expect( screen.getByText( 'Publish content?' ) ).toBeTruthy();

		await user.click( dialogButton( 'Cancel' ) );

		expect( onTransition ).not.toHaveBeenCalled();
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	} );

	it( 'confirms a rollback before running it', async () => {
		const onTransition = jest.fn().mockResolvedValue( null );
		const user = userEvent.setup();

		render(
			<TransitionActions
				transitions={ [ sendBack ] }
				onTransition={ onTransition }
				isSaving={ false }
			/>
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Move to Needs Changes' } )
		);

		expect( onTransition ).not.toHaveBeenCalled();

		await user.click( dialogButton( 'Move to Needs Changes' ) );

		expect( onTransition ).toHaveBeenCalledWith( 'needs_changes' );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	} );

	it( 'queues a click made while another change saves, then runs it', async () => {
		const onTransition = jest.fn().mockResolvedValue( null );
		const user = userEvent.setup();
		const props = { transitions: [ toReview ], onTransition };

		const { rerender } = render(
			<TransitionActions { ...props } isSaving />
		);

		const button = screen.getByRole( 'button', { name: 'Move to Review' } );

		expect( button.disabled ).toBe( false );

		await user.click( button );

		expect( onTransition ).not.toHaveBeenCalled();
		expect( button.disabled ).toBe( true );
		expect( button.classList.contains( 'is-busy' ) ).toBe( true );

		await act( async () => {
			rerender( <TransitionActions { ...props } isSaving={ false } /> );
		} );

		expect( onTransition ).toHaveBeenCalledTimes( 1 );
		expect( onTransition ).toHaveBeenCalledWith( 'review' );
	} );

	it( 'queues a confirmed rollback made while another change saves', async () => {
		const onTransition = jest.fn().mockResolvedValue( null );
		const user = userEvent.setup();
		const props = { transitions: [ approve, sendBack ], onTransition };

		const { rerender } = render(
			<TransitionActions { ...props } isSaving />
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Move to Needs Changes' } )
		);
		await user.click( dialogButton( 'Move to Needs Changes' ) );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( onTransition ).not.toHaveBeenCalled();

		await act( async () => {
			rerender( <TransitionActions { ...props } isSaving={ false } /> );
		} );

		expect( onTransition ).toHaveBeenCalledWith( 'needs_changes' );
	} );

	it( 'drops a queued transition the server no longer offers', async () => {
		const onTransition = jest.fn().mockResolvedValue( null );
		const user = userEvent.setup();

		const { rerender } = render(
			<TransitionActions
				transitions={ [ toReview ] }
				onTransition={ onTransition }
				isSaving
			/>
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Move to Review' } )
		);

		await act( async () => {
			rerender(
				<TransitionActions
					transitions={ [ approve ] }
					onTransition={ onTransition }
					isSaving={ false }
				/>
			);
		} );

		expect( onTransition ).not.toHaveBeenCalled();
		expect(
			screen.getByRole( 'button', { name: 'Move to Approved' } ).disabled
		).toBe( false );
	} );

	it( 'blocks the other actions while a transition runs', async () => {
		const onTransition = jest.fn( () => new Promise( () => {} ) );
		const user = userEvent.setup();

		render(
			<TransitionActions
				transitions={ [ toReview, sendBack ] }
				onTransition={ onTransition }
				isSaving={ false }
			/>
		);

		await user.click(
			screen.getByRole( 'button', { name: 'Move to Review' } )
		);

		screen
			.getAllByRole( 'button' )
			.forEach( ( button ) => expect( button.disabled ).toBe( true ) );
	} );

	it( 'disables every action when disabled', () => {
		render(
			<TransitionActions
				transitions={ [ approve, sendBack ] }
				onTransition={ jest.fn() }
				isSaving={ false }
				isDisabled
			/>
		);

		screen
			.getAllByRole( 'button' )
			.forEach( ( button ) => expect( button.disabled ).toBe( true ) );
	} );

	describe( 'attestation', () => {
		/**
		 * Whether a button is disabled but still focusable.
		 *
		 * @param {string} name Button name.
		 * @return {boolean} Whether it is `aria-disabled`.
		 */
		const isAriaDisabled = ( name ) =>
			screen
				.getByRole( 'button', { name } )
				.getAttribute( 'aria-disabled' ) === 'true';

		it( 'disables Approve until the attestation is ticked, then sends attested', async () => {
			const onTransition = jest.fn().mockResolvedValue( null );
			const user = userEvent.setup();

			render(
				<TransitionActions
					transitions={ [ approve, sendBack ] }
					attestation={ attestation }
					onTransition={ onTransition }
					isSaving={ false }
				/>
			);

			expect( isAriaDisabled( 'Move to Approved' ) ).toBe( true );
			expect( isAriaDisabled( 'Move to Needs Changes' ) ).toBe( false );

			await user.click(
				screen.getByRole( 'button', { name: 'Move to Approved' } )
			);

			expect( screen.queryByRole( 'dialog' ) ).toBeNull();

			await user.click(
				screen.getByRole( 'checkbox', { name: STATEMENT } )
			);

			expect( isAriaDisabled( 'Move to Approved' ) ).toBe( false );

			await user.click(
				screen.getByRole( 'button', { name: 'Move to Approved' } )
			);
			// The approval confirmation still follows the attestation.
			await user.click( dialogButton( 'Approve' ) );

			expect( onTransition ).toHaveBeenCalledWith( 'approved', {
				attested: true,
			} );
		} );

		it( 'sends no options for other transitions', async () => {
			const onTransition = jest.fn().mockResolvedValue( null );
			const user = userEvent.setup();

			render(
				<TransitionActions
					transitions={ [ approve, toReview ] }
					attestation={ attestation }
					onTransition={ onTransition }
					isSaving={ false }
				/>
			);

			await user.click(
				screen.getByRole( 'checkbox', { name: STATEMENT } )
			);
			await user.click(
				screen.getByRole( 'button', { name: 'Move to Review' } )
			);

			expect( onTransition ).toHaveBeenCalledWith( 'review' );
		} );

		it( 'shows no checkbox when attestation is not required', () => {
			render(
				<TransitionActions
					transitions={ [ approve ] }
					onTransition={ jest.fn() }
					isSaving={ false }
				/>
			);

			expect( screen.queryByRole( 'checkbox' ) ).toBeNull();
			expect( isAriaDisabled( 'Move to Approved' ) ).toBe( false );
		} );

		it( 'shows no checkbox when Approve is not offered', () => {
			render(
				<TransitionActions
					transitions={ [ sendBack ] }
					attestation={ attestation }
					onTransition={ jest.fn() }
					isSaving={ false }
				/>
			);

			expect( screen.queryByRole( 'checkbox' ) ).toBeNull();
		} );

		it( 'unticks after a successful approval and when Approve is no longer offered', async () => {
			const onTransition = jest.fn().mockResolvedValue( null );
			const user = userEvent.setup();
			const props = { attestation, onTransition, isSaving: false };

			const { rerender } = render(
				<TransitionActions
					{ ...props }
					transitions={ [ approve, sendBack ] }
				/>
			);

			await user.click(
				screen.getByRole( 'checkbox', { name: STATEMENT } )
			);
			await user.click(
				screen.getByRole( 'button', { name: 'Move to Approved' } )
			);
			await user.click( dialogButton( 'Approve' ) );

			expect( onTransition ).toHaveBeenCalledTimes( 1 );
			expect(
				screen.getByRole( 'checkbox', { name: STATEMENT } ).checked
			).toBe( false );

			await user.click(
				screen.getByRole( 'checkbox', { name: STATEMENT } )
			);

			rerender(
				<TransitionActions { ...props } transitions={ [ sendBack ] } />
			);

			expect( screen.queryByRole( 'checkbox' ) ).toBeNull();

			rerender(
				<TransitionActions
					{ ...props }
					transitions={ [ approve, sendBack ] }
				/>
			);

			expect(
				screen.getByRole( 'checkbox', { name: STATEMENT } ).checked
			).toBe( false );
		} );
	} );
} );
