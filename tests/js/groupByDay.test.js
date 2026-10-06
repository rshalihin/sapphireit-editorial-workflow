/**
 * WordPress dependencies
 */
import { getSettings, setSettings } from '@wordpress/date';

/**
 * Internal dependencies
 */
import groupByDay from '../../src/utils/groupByDay';

const at = ( id, createdAt ) => ( { id, created_at: createdAt } );

describe( 'groupByDay', () => {
	let original;

	beforeAll( () => {
		original = getSettings();
		// UTC+9: a day boundary that differs from UTC and from most test hosts.
		setSettings( {
			...original,
			timezone: {
				offset: 9,
				offsetFormatted: '+9',
				string: '',
				abbr: '',
			},
		} );
	} );

	afterAll( () => {
		setSettings( original );
	} );

	const now = new Date( '2026-09-14T10:00:00Z' ); // 19:00 on the 14th, site time.

	it( 'buckets by calendar day in the site timezone', () => {
		const groups = groupByDay(
			[
				at( 5, '2026-09-14T09:00:00+00:00' ), // 14th 18:00
				at( 4, '2026-09-13T16:00:00+00:00' ), // 14th 01:00 — UTC says the 13th
				at( 3, '2026-09-13T14:00:00+00:00' ), // 13th 23:00
				at( 2, '2026-09-12T15:30:00+00:00' ), // 13th 00:30
				at( 1, '2026-09-01T08:00:00+00:00' ), // 1st 17:00
			],
			now
		);

		expect(
			groups.map( ( { day, label, items } ) => [
				day,
				label,
				items.map( ( item ) => item.id ),
			] )
		).toEqual( [
			[ '2026-09-14', 'Today', [ 5, 4 ] ],
			[ '2026-09-13', 'Yesterday', [ 3, 2 ] ],
			[ '2026-09-01', 'September 1, 2026', [ 1 ] ],
		] );
	} );

	it( 'computes yesterday across a month boundary', () => {
		const groups = groupByDay(
			[ at( 1, '2026-09-30T20:00:00+00:00' ) ], // Oct 1st 05:00
			new Date( '2026-10-02T01:00:00Z' ) // Oct 2nd 10:00
		);

		expect( groups[ 0 ].label ).toBe( 'Yesterday' );
	} );

	it( 'puts unparsable timestamps under an unknown date', () => {
		expect( groupByDay( [ at( 1, '' ) ], now ) ).toEqual( [
			{ day: '', label: 'Unknown date', items: [ at( 1, '' ) ] },
		] );
		expect( groupByDay( null, now ) ).toEqual( [] );
	} );
} );
