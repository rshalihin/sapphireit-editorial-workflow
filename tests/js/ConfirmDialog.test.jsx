/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * WordPress dependencies
 */
import { useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ConfirmDialog from '../../src/components/ConfirmDialog';

/**
 * A trigger that opens the dialog, like the sidebar actions do.
 *
 * @param {Object}   props           Props.
 * @param {Function} props.onConfirm Confirm handler.
 * @return {Element} Harness.
 */
function Harness( { onConfirm } ) {
	const [ isOpen, setIsOpen ] = useState( false );

	return (
		<>
			<button type="button" onClick={ () => setIsOpen( true ) }>
				Approve
			</button>
			{ isOpen && (
				<ConfirmDialog
					title="Approve content?"
					confirmLabel="Approve now"
					onCancel={ () => setIsOpen( false ) }
					onConfirm={ () => {
						setIsOpen( false );
						onConfirm();
					} }
				>
					<p>This will mark the content as approved.</p>
				</ConfirmDialog>
			) }
		</>
	);
}

describe( 'ConfirmDialog', () => {
	it( 'shows the question, the explanation and both choices', async () => {
		const user = userEvent.setup();

		render( <Harness onConfirm={ jest.fn() } /> );
		await user.click( screen.getByRole( 'button', { name: 'Approve' } ) );

		expect( screen.getByRole( 'dialog' ) ).toBeTruthy();
		expect( screen.getByText( 'Approve content?' ) ).toBeTruthy();
		expect(
			screen.getByText( 'This will mark the content as approved.' )
		).toBeTruthy();
		expect( screen.getByRole( 'button', { name: 'Cancel' } ) ).toBeTruthy();
	} );

	it( 'confirms only through the confirm button', async () => {
		const onConfirm = jest.fn();
		const user = userEvent.setup();

		render( <Harness onConfirm={ onConfirm } /> );
		await user.click( screen.getByRole( 'button', { name: 'Approve' } ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Approve now' } )
		);

		expect( onConfirm ).toHaveBeenCalledTimes( 1 );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	} );

	it( 'closes on Escape and returns focus to the trigger', async () => {
		const onConfirm = jest.fn();
		const user = userEvent.setup();

		render( <Harness onConfirm={ onConfirm } /> );

		const trigger = screen.getByRole( 'button', { name: 'Approve' } );

		await user.click( trigger );

		/*
		 * Modal focuses its first tabbable child on mount, but jsdom reports
		 * every element as having no layout, so `focus.tabbable.find()` finds
		 * nothing and focus stays on the trigger. Escape is handled on the
		 * overlay, so put focus inside the dialog the way a browser would
		 * before pressing it.
		 */
		screen.getByRole( 'dialog' ).focus();
		await user.keyboard( '{Escape}' );

		/*
		 * Modal plays an exit animation before it calls `onRequestClose`, so
		 * closing is asynchronous. jsdom fires no `animationend`, leaving the
		 * component's own timeout to end it a few hundred milliseconds later.
		 */
		await waitFor( () =>
			expect( screen.queryByRole( 'dialog' ) ).toBeNull()
		);

		expect( onConfirm ).not.toHaveBeenCalled();
		expect( trigger.ownerDocument.activeElement ).toBe( trigger );
	} );

	it( 'blocks both buttons while busy', () => {
		render(
			<ConfirmDialog
				title="Approve content?"
				confirmLabel="Approve now"
				onCancel={ jest.fn() }
				onConfirm={ jest.fn() }
				isBusy
			/>
		);

		expect(
			screen.getByRole( 'button', { name: 'Cancel' } ).disabled
		).toBe( true );
		expect(
			screen
				.getByRole( 'button', { name: 'Approve now' } )
				.getAttribute( 'aria-disabled' )
		).toBe( 'true' );
	} );
} );
