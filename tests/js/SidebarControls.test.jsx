/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

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
import CommentForm from '../../src/sidebar/components/CommentForm';
import DueDateControl from '../../src/sidebar/components/DueDateControl';
import ReviewerControl from '../../src/sidebar/components/ReviewerControl';

jest.mock( '@wordpress/api-fetch' );

// Opening the real popover calendar takes most of a minute in jsdom. The
// stubs render the dropdown content inline, and hand the control what
// `DatePicker` does: a local date-time string for the picked day.
jest.mock( '@wordpress/components', () => ( {
	...jest.requireActual( '@wordpress/components' ),
	Dropdown: ( { renderToggle, renderContent } ) => (
		<>
			{ renderToggle( { isOpen: true, onToggle: () => {} } ) }
			{ renderContent( { onClose: () => {} } ) }
		</>
	),
	DatePicker: ( { currentDate, onChange } ) => (
		<div data-current={ currentDate || '' }>
			<button
				type="button"
				onClick={ () => onChange( '2026-10-15T00:00:00' ) }
			>
				Pick October 15
			</button>
			<button
				type="button"
				onClick={ () => onChange( '2026-10-01T00:00:00' ) }
			>
				Pick October 1
			</button>
		</div>
	),
} ) );

const jane = { id: 4, name: 'Jane', avatar: 'https://example.org/j.png' };
const omar = { id: 7, name: 'Omar', avatar: '' };

beforeAll( () => {
	// jsdom has no layout; `ComboboxControl` scrolls the highlighted option.
	window.HTMLElement.prototype.scrollIntoView = () => {};
} );

afterEach( () => {
	apiFetch.mockReset();
} );

describe( 'CommentForm', () => {
	const field = () =>
		screen.getByRole( 'textbox', { name: 'Add a workflow comment' } );
	const submit = () => screen.getByRole( 'button', { name: 'Add comment' } );

	it( 'cannot submit an empty or blank comment', () => {
		const onSubmit = jest.fn();

		render( <CommentForm onSubmit={ onSubmit } isSaving={ false } /> );

		expect( submit().disabled ).toBe( true );

		fireEvent.change( field(), { target: { value: '   ' } } );
		fireEvent.submit( field().closest( 'form' ) );

		expect( onSubmit ).not.toHaveBeenCalled();
	} );

	it( 'sends the comment and clears the field on success', async () => {
		const onSubmit = jest.fn().mockResolvedValue( null );

		render( <CommentForm onSubmit={ onSubmit } isSaving={ false } /> );

		fireEvent.change( field(), { target: { value: 'Looks good.' } } );
		await act( async () => {
			fireEvent.click( submit() );
		} );

		expect( onSubmit ).toHaveBeenCalledWith( 'Looks good.' );
		expect( field().value ).toBe( '' );
	} );

	it( 'keeps the text and shows the error when saving fails', async () => {
		const onSubmit = jest
			.fn()
			.mockResolvedValue( { message: 'Comment too long.' } );

		render( <CommentForm onSubmit={ onSubmit } isSaving={ false } /> );

		fireEvent.change( field(), { target: { value: 'Draft note' } } );
		await act( async () => {
			fireEvent.click( submit() );
		} );

		// `Notice` also announces the message in a live region.
		expect(
			document.querySelector( '.components-notice__content' ).textContent
		).toContain( 'Comment too long.' );
		expect( field().value ).toBe( 'Draft note' );
	} );

	it( 'is read-only while disabled', () => {
		render(
			<CommentForm onSubmit={ jest.fn() } isSaving={ false } isDisabled />
		);

		expect( field().disabled ).toBe( true );
		expect( submit().disabled ).toBe( true );
	} );
} );

describe( 'DueDateControl', () => {
	it( 'shows an overdue date as text to users who cannot edit it', () => {
		const { container } = render(
			<DueDateControl
				value="2020-01-15"
				onChange={ jest.fn() }
				canEdit={ false }
				isSaving={ false }
				isComplete={ false }
			/>
		);

		expect( screen.queryByRole( 'button' ) ).toBeNull();
		expect( container.querySelector( '.is-overdue' ).textContent ).toMatch(
			/\(overdue\)$/
		);
	} );

	it( 'does not flag completed content as overdue', () => {
		const { container } = render(
			<DueDateControl
				value="2020-01-15"
				canEdit={ false }
				onChange={ jest.fn() }
				isSaving={ false }
				isComplete
			/>
		);

		expect( container.querySelector( '.is-overdue' ) ).toBeNull();
	} );

	it( 'clears the due date', () => {
		const onChange = jest.fn();

		render(
			<DueDateControl
				value="2026-10-01"
				onChange={ onChange }
				canEdit
				isSaving={ false }
				isComplete={ false }
			/>
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Clear' } ) );

		expect( onChange ).toHaveBeenCalledWith( '' );
	} );

	it( 'picks a date from the calendar', async () => {
		const onChange = jest.fn();

		render(
			<DueDateControl
				value="2026-10-01"
				onChange={ onChange }
				canEdit
				isSaving={ false }
				isComplete={ false }
			/>
		);

		expect(
			screen.getByText( 'Pick October 15' ).parentElement.dataset.current
		).toBe( '2026-10-01T00:00:00' );

		// Picking the current date again changes nothing.
		fireEvent.click( screen.getByText( 'Pick October 1' ) );
		expect( onChange ).not.toHaveBeenCalled();

		fireEvent.click( screen.getByText( 'Pick October 15' ) );
		expect( onChange ).toHaveBeenCalledWith( '2026-10-15' );
	} );

	it( 'offers no Clear without a date', () => {
		render(
			<DueDateControl
				value=""
				onChange={ jest.fn() }
				canEdit
				isSaving={ false }
				isComplete={ false }
			/>
		);

		expect(
			screen.getByRole( 'button', {
				name: 'Change due date: No due date',
			} )
		).toBeTruthy();
		expect( screen.queryByRole( 'button', { name: 'Clear' } ) ).toBeNull();
	} );
} );

describe( 'ReviewerControl', () => {
	it( 'shows the current reviewer and assigns another one', async () => {
		apiFetch.mockResolvedValue( [ jane, omar ] );
		const onChange = jest.fn();

		render(
			<ReviewerControl
				postId={ 12 }
				reviewer={ jane }
				onChange={ onChange }
				isSaving={ false }
			/>
		);

		expect( screen.getByText( 'Jane' ) ).toBeTruthy();

		const input = screen.getByRole( 'combobox', { name: 'Reviewer' } );
		fireEvent.focus( input );
		fireEvent.change( input, { target: { value: 'Om' } } );
		const option = await screen.findByRole( 'option', { name: 'Omar' } );
		await act( async () => {
			fireEvent.click( option );
		} );

		expect( onChange ).toHaveBeenCalledWith( 7 );
		await waitFor( () =>
			expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toContain(
				'post_id=12'
			)
		);
	} );

	it( 'ignores picks while saving', async () => {
		apiFetch.mockResolvedValue( [ omar ] );
		const onChange = jest.fn();

		render(
			<ReviewerControl
				postId={ 12 }
				reviewer={ null }
				onChange={ onChange }
				isSaving
			/>
		);

		const input = screen.getByRole( 'combobox', { name: 'Reviewer' } );
		fireEvent.focus( input );
		fireEvent.change( input, { target: { value: 'Om' } } );
		fireEvent.click(
			await screen.findByRole( 'option', { name: 'Omar' } )
		);

		expect( onChange ).not.toHaveBeenCalled();
	} );

	it( 'shows why reviewers could not be loaded', async () => {
		apiFetch.mockRejectedValue( {
			code: 'rest_forbidden',
			message: 'Not allowed to list users.',
			data: { status: 403 },
		} );

		render(
			<ReviewerControl
				postId={ 12 }
				reviewer={ null }
				onChange={ jest.fn() }
				isSaving={ false }
			/>
		);

		expect(
			await screen.findByText( 'Not allowed to list users.' )
		).toBeTruthy();
	} );
} );
