/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import SummaryTiles from '../../src/dashboard/SummaryTiles';
import { getPostsSummary } from '../../src/api/client';

jest.mock( '../../src/api/client', () => ( {
	...jest.requireActual( '../../src/api/client' ),
	getPostsSummary: jest.fn(),
} ) );

const SUMMARY = {
	in_review: 12,
	in_review_mine: 3,
	needs_changes: 4,
	overdue: 1500,
	approved: 5,
};

/**
 * The tile button whose label is the given text.
 *
 * @param {string} label Tile label.
 * @return {HTMLElement} Button.
 */
function tile( label ) {
	return screen.getByText( label ).closest( 'button' );
}

describe( 'Dashboard summary tiles', () => {
	beforeEach( () => {
		getPostsSummary.mockReset();
		getPostsSummary.mockResolvedValue( SUMMARY );
	} );

	it( 'renders the four counts with their help text', async () => {
		render( <SummaryTiles refreshKey={ 0 } onSelect={ () => {} } /> );

		expect(
			await screen.findByRole( 'list', { name: 'Workflow summary' } )
		).toBeTruthy();
		expect( tile( 'In review' ).textContent ).toBe(
			'In review123 assigned to you'
		);
		expect( tile( 'Needs changes' ).textContent ).toContain( '4' );
		expect( tile( 'Overdue' ).textContent ).toContain( '1,500' );
		expect( tile( 'Overdue' ).className ).toContain( 'is-danger' );
		expect( tile( 'Approved, not published' ).textContent ).toContain(
			'Ready to go live'
		);
	} );

	it( 'passes the matching DataViews filter when a tile is clicked', async () => {
		const onSelect = jest.fn();

		render( <SummaryTiles refreshKey={ 0 } onSelect={ onSelect } /> );
		await screen.findByText( 'In review' );

		await userEvent.click( tile( 'Needs changes' ) );
		expect( onSelect ).toHaveBeenLastCalledWith( {
			field: 'status',
			operator: 'isAny',
			value: [ 'needs_changes' ],
		} );

		await userEvent.click( tile( 'Overdue' ) );
		expect( onSelect ).toHaveBeenLastCalledWith( {
			field: 'is_overdue',
			operator: 'is',
			value: true,
		} );
	} );

	it( 'filters "In review" on every status the server counts', async () => {
		const onSelect = jest.fn();

		getPostsSummary.mockResolvedValue( {
			...SUMMARY,
			review_statuses: [ 'review', 'legal_review' ],
		} );
		render( <SummaryTiles refreshKey={ 0 } onSelect={ onSelect } /> );
		await screen.findByText( 'In review' );

		await userEvent.click( tile( 'In review' ) );

		expect( onSelect ).toHaveBeenLastCalledWith( {
			field: 'status',
			operator: 'isAny',
			value: [ 'review', 'legal_review' ],
		} );
	} );

	it( 'falls back to Review for an older server', async () => {
		const onSelect = jest.fn();

		render( <SummaryTiles refreshKey={ 0 } onSelect={ onSelect } /> );
		await screen.findByText( 'In review' );

		await userEvent.click( tile( 'In review' ) );

		expect( onSelect ).toHaveBeenLastCalledWith( {
			field: 'status',
			operator: 'isAny',
			value: [ 'review' ],
		} );
	} );

	it( 'refetches when the refresh key changes', async () => {
		const { rerender } = render(
			<SummaryTiles refreshKey={ 0 } onSelect={ () => {} } />
		);
		await screen.findByText( 'In review' );

		getPostsSummary.mockResolvedValue( { ...SUMMARY, in_review: 13 } );
		rerender( <SummaryTiles refreshKey={ 1 } onSelect={ () => {} } /> );

		await waitFor( () =>
			expect( tile( 'In review' ).textContent ).toContain( '13' )
		);
		expect( getPostsSummary ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'renders nothing when the counts cannot be loaded', async () => {
		getPostsSummary.mockRejectedValue( {
			code: 'sit_cwm_forbidden',
			message: 'Sorry, you are not allowed to do that.',
			status: 403,
		} );

		const { container } = render(
			<SummaryTiles refreshKey={ 0 } onSelect={ () => {} } />
		);

		await waitFor( () => expect( getPostsSummary ).toHaveBeenCalled() );
		expect( container.innerHTML ).toBe( '' );
	} );
} );
