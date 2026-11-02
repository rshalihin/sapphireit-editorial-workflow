/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import AttestationCheckbox from '../../src/components/AttestationCheckbox';

const STATEMENT =
	'I confirm I have reviewed this content and approve it for publication.';

describe( 'AttestationCheckbox', () => {
	it( 'labels the checkbox with the statement and reports changes', async () => {
		const onChange = jest.fn();
		const user = userEvent.setup();

		const { container } = render(
			<AttestationCheckbox
				statement={ STATEMENT }
				checked={ false }
				onChange={ onChange }
			/>
		);

		const checkbox = screen.getByRole( 'checkbox', { name: STATEMENT } );

		expect( checkbox.checked ).toBe( false );
		expect( container.querySelector( '.sit-cwm-attest' ) ).not.toBeNull();

		await user.click( checkbox );

		expect( onChange ).toHaveBeenCalledWith( true );
	} );

	it( 'renders the statement as text, not markup', () => {
		render(
			<AttestationCheckbox
				statement="<b>Reviewed</b>"
				checked
				onChange={ jest.fn() }
			/>
		);

		expect(
			screen.getByRole( 'checkbox', { name: '<b>Reviewed</b>' } ).checked
		).toBe( true );
	} );

	it( 'can be disabled', () => {
		render(
			<AttestationCheckbox
				statement={ STATEMENT }
				checked={ false }
				onChange={ jest.fn() }
				disabled
			/>
		);

		expect(
			screen.getByRole( 'checkbox', { name: STATEMENT } ).disabled
		).toBe( true );
	} );
} );
