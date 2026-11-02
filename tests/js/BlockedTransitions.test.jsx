/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import BlockedTransitions from '../../src/components/BlockedTransitions';

const approved = {
	slug: 'approved',
	label: 'Approved',
	code: 'checklist_incomplete',
	message: 'The checklist is not complete.',
};

describe( 'BlockedTransitions', () => {
	it( 'renders each blocked transition as a disabled button with its message', () => {
		render( <BlockedTransitions blocked={ [ approved ] } /> );

		const button = screen.getByRole( 'button', {
			name: 'Move to Approved',
		} );
		const reason = screen.getByText( 'The checklist is not complete.' );

		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( reason.id ).toBe( 'sit-cwm-blocked-approved' );
		expect( button.getAttribute( 'aria-describedby' ) ).toContain(
			'sit-cwm-blocked-approved'
		);
	} );

	it( 'renders the message as text, never HTML', () => {
		const message = '<img src=x onerror=alert(1)>';
		const { container } = render(
			<BlockedTransitions
				blocked={ [ { slug: 'x', label: 'X', code: 'c', message } ] }
			/>
		);

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( screen.getByText( message ) ).toBeTruthy();
	} );

	it( 'renders nothing for empty or malformed input', () => {
		const { container, rerender } = render(
			<BlockedTransitions blocked={ [] } />
		);

		expect( container.innerHTML ).toBe( '' );

		rerender( <BlockedTransitions blocked={ undefined } /> );
		expect( container.innerHTML ).toBe( '' );

		rerender( <BlockedTransitions blocked={ { slug: 'approved' } } /> );
		expect( container.innerHTML ).toBe( '' );
	} );
} );
