/**
 * External dependencies
 */
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import App from '../../src/report/App';
import { getGovernanceReport } from '../../src/api/client';

jest.mock( '../../src/api/client', () => ( {
	...jest.requireActual( '../../src/api/client' ),
	getGovernanceReport: jest.fn(),
} ) );

const REPORT = {
	period_days: 90,
	since: '2026-07-31T00:00:00Z',
	generated_at: '2026-10-29T10:00:00Z',
	publication: {
		total: 8,
		approved: 7,
		approved_share: 0.875,
		by_gate_mode: { off: 2, log_only: 1, enforce: 5, unknown: 0 },
	},
	gate: { refused: 3, bypassed: { log_only: 1, capability: 1, error: 0 } },
	ai_origin: {
		current: { human: 4, ai_assisted: 2, ai_generated: 1, not_declared: 9 },
		published: {
			human: 3,
			ai_assisted: 1,
			ai_generated: 1,
			not_declared: 2,
			not_recorded: 1,
		},
	},
	overdue: { count: 4 },
};

const FOOTNOTE =
	'“Not recorded” counts publications logged before version 1.2, which did not record AI use.';

/**
 * The tile whose label is the given text.
 *
 * @param {string} label Tile label.
 * @return {HTMLElement} Tile element.
 */
function tile( label ) {
	return screen.getByText( label ).closest( '.sit-cwm-report__tile' );
}

/**
 * Count cells of a breakdown table, by row label.
 *
 * @param {string} title Section title.
 * @return {Object<string, string>} Row label → count text.
 */
function breakdown( title ) {
	const table = screen.getByRole( 'table', { name: title } );
	const rows = within( table ).getAllByRole( 'row' ).slice( 1 );

	return Object.fromEntries(
		rows.map( ( row ) => {
			const cells = within( row ).getAllByRole( 'cell' );
			const header = within( row ).getByRole( 'rowheader' );

			return [ header.textContent, cells[ 0 ].textContent ];
		} )
	);
}

describe( 'Governance report', () => {
	beforeEach( () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=sit-cwm-report'
		);
		getGovernanceReport.mockReset();
		getGovernanceReport.mockResolvedValue( REPORT );
	} );

	it( 'renders the five tiles with formatted values', async () => {
		render( <App /> );

		expect( await screen.findByText( '87.5%' ) ).toBeTruthy();
		expect( screen.getByText( '7 of 8 publications' ) ).toBeTruthy();
		expect(
			within(
				tile( 'Published while the gate was off or logging only' )
			).getByText( '3' )
		).toBeTruthy();
		expect(
			within( tile( 'Publishing blocked' ) ).getByText( '3' )
		).toBeTruthy();
		expect(
			within( tile( 'Published without approval' ) ).getByText( '2' )
		).toBeTruthy();
		expect(
			within( tile( 'Overdue reviews' ) ).getByText( '4' )
		).toBeTruthy();
		expect( getGovernanceReport ).toHaveBeenCalledWith(
			90,
			expect.objectContaining( { signal: expect.anything() } )
		);
	} );

	it( 'describes each value by its label', async () => {
		render( <App /> );

		const value = await screen.findByText( '87.5%' );
		const label = document.getElementById(
			value.getAttribute( 'aria-describedby' )
		);

		expect( label.textContent ).toBe( 'Published after approval' );
	} );

	it( 'shows a dash and the empty state when nothing was published', async () => {
		getGovernanceReport.mockResolvedValue( {
			...REPORT,
			publication: {
				...REPORT.publication,
				total: 0,
				approved: 0,
				approved_share: null,
			},
		} );

		render( <App /> );

		expect( await screen.findByText( '—' ) ).toBeTruthy();
		expect(
			screen.getByText( 'Nothing was published in this period.' )
		).toBeTruthy();
	} );

	it( 'refetches when the period changes and remembers it in the URL', async () => {
		render( <App /> );
		await screen.findByText( '87.5%' );

		await userEvent.selectOptions(
			screen.getByRole( 'combobox', { name: 'Period' } ),
			'30'
		);

		await waitFor( () =>
			expect( getGovernanceReport ).toHaveBeenLastCalledWith(
				30,
				expect.anything()
			)
		);
		expect( window.location.search ).toContain( 'period=30' );
		expect( window.location.search ).toContain( 'page=sit-cwm-report' );
	} );

	it( 'reads the period from the URL and ignores unknown values', async () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=sit-cwm-report&period=365'
		);
		const { unmount } = render( <App /> );

		await screen.findByText( '87.5%' );
		expect( getGovernanceReport ).toHaveBeenLastCalledWith(
			365,
			expect.anything()
		);
		expect( screen.getByRole( 'combobox', { name: 'Period' } ).value ).toBe(
			'365'
		);
		unmount();

		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=sit-cwm-report&period=7'
		);
		render( <App /> );

		await screen.findByText( '87.5%' );
		expect( getGovernanceReport ).toHaveBeenLastCalledWith(
			90,
			expect.anything()
		);
	} );

	it( 'renders both AI breakdowns including Not recorded and the footnote', async () => {
		render( <App /> );
		await screen.findByText( '87.5%' );

		expect( breakdown( 'AI use: all workflow content' ) ).toEqual( {
			'Human-written': '4',
			'AI-assisted': '2',
			'AI-generated': '1',
			'Not declared': '9',
		} );
		expect( breakdown( 'AI use: published in this period' ) ).toEqual( {
			'Human-written': '3',
			'AI-assisted': '1',
			'AI-generated': '1',
			'Not declared': '2',
			'Not recorded': '1',
		} );

		const published = screen.getByRole( 'table', {
			name: 'AI use: published in this period',
		} );

		expect(
			within( published )
				.getAllByRole( 'columnheader' )
				.map( ( th ) => th.textContent )
		).toEqual( [ 'AI use', 'Count' ] );
		expect( screen.getByText( FOOTNOTE ) ).toBeTruthy();
	} );

	it( 'shows an error notice with Retry when the request fails', async () => {
		getGovernanceReport.mockRejectedValueOnce( {
			code: 'sit_cwm_forbidden',
			message: 'Sorry, you are not allowed to do that.',
			status: 403,
		} );

		render( <App /> );

		expect(
			await screen.findByText( 'Sorry, you are not allowed to do that.' )
		).toBeTruthy();
		expect( screen.queryByText( 'Publishing blocked' ) ).toBeNull();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Retry' } )
		);

		expect( await screen.findByText( '87.5%' ) ).toBeTruthy();
		expect( document.querySelector( '.sit-cwm-report__error' ) ).toBeNull();
		expect( getGovernanceReport ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'draws the headline split bar with a sentence and a legend', async () => {
		render( <App /> );
		await screen.findByText( '87.5%' );

		expect(
			screen.getByRole( 'img', {
				name: '7 of 8 publications were approved first',
			} )
		).toBeTruthy();
		expect( screen.getByText( 'Approved first · 7' ) ).toBeTruthy();
		expect( screen.getByText( 'Without approval · 1' ) ).toBeTruthy();
		expect(
			document.querySelector( '.sit-cwm-report__split-fill' ).style.width
		).toBe( '87.5%' );
	} );

	it( 'shows each breakdown total and bar values with shares', async () => {
		render( <App /> );
		await screen.findByText( '87.5%' );

		expect( screen.getByText( '16 items' ) ).toBeTruthy();
		expect( screen.getByText( '8 publications' ) ).toBeTruthy();

		const values = Array.from(
			document.querySelectorAll( '.sit-cwm-report__bar-value' )
		).map( ( node ) => node.textContent );

		expect( values.slice( 0, 4 ) ).toEqual( [
			'4 · 25%',
			'2 · 13%',
			'1 · 6%',
			'9 · 56%',
		] );
		expect(
			document.querySelectorAll( '.sit-cwm-report__bar-fill.is-neutral' )
		).toHaveLength( 3 );
	} );

	it( 'portals the Period control into the title-row slot when present', async () => {
		const slot = document.createElement( 'div' );
		slot.id = 'sit-cwm-report-period';
		document.body.appendChild( slot );

		const { container } = render( <App /> );
		await screen.findByText( '87.5%' );

		expect(
			within( slot ).getByRole( 'combobox', { name: 'Period' } )
		).toBeTruthy();
		expect(
			within( container ).queryByRole( 'combobox', { name: 'Period' } )
		).toBeNull();

		slot.remove();
	} );

	it( 'shows a spinner while loading', () => {
		getGovernanceReport.mockReturnValue( new Promise( () => {} ) );

		const { container } = render( <App /> );

		expect(
			container.querySelector( '.components-spinner' )
		).not.toBeNull();
	} );
} );
