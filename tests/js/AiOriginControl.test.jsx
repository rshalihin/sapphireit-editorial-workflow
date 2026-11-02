/**
 * External dependencies
 */
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import AiOriginControl from '../../src/sidebar/components/AiOriginControl';

describe( 'AiOriginControl', () => {
	it( 'offers the three declarations, with Not declared shown only while unset', () => {
		const { rerender } = render(
			<AiOriginControl value="" canDeclare onChange={ jest.fn() } />
		);
		const select = screen.getByRole( 'combobox', { name: 'AI use' } );

		expect(
			within( select )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [
			'Not declared',
			'Human-written',
			'AI-assisted',
			'AI-generated',
		] );
		expect(
			within( select ).getByRole( 'option', { name: 'Not declared' } )
				.disabled
		).toBe( true );

		rerender(
			<AiOriginControl value="human" canDeclare onChange={ jest.fn() } />
		);

		expect(
			within(
				screen.getByRole( 'combobox', { name: 'AI use' } )
			).queryByRole( 'option', { name: 'Not declared' } )
		).toBeNull();
	} );

	it( 'calls onChange with the slug', async () => {
		const onChange = jest.fn();

		render( <AiOriginControl value="" canDeclare onChange={ onChange } /> );

		await userEvent.selectOptions(
			screen.getByRole( 'combobox', { name: 'AI use' } ),
			'ai_generated'
		);

		expect( onChange ).toHaveBeenCalledWith( 'ai_generated' );
	} );

	it( 'is disabled while saving', () => {
		render(
			<AiOriginControl
				value="human"
				canDeclare
				isSaving
				onChange={ jest.fn() }
			/>
		);

		expect(
			screen.getByRole( 'combobox', { name: 'AI use' } ).disabled
		).toBe( true );
	} );

	it( 'is read-only text for users who cannot declare', () => {
		render(
			<AiOriginControl
				value="ai_assisted"
				canDeclare={ false }
				onChange={ jest.fn() }
			/>
		);

		expect( screen.queryByRole( 'combobox' ) ).toBeNull();
		expect( screen.getByText( 'AI-assisted' ) ).toBeTruthy();
	} );

	it( 'shows the help text', () => {
		render(
			<AiOriginControl value="" canDeclare onChange={ jest.fn() } />
		);

		expect(
			screen.getByText( /it is not detected automatically/ )
		).toBeTruthy();
	} );
} );
