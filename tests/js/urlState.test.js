/**
 * Internal dependencies
 */
import {
	DEFAULT_VIEW,
	hasActiveQuery,
	viewFromSearchParams,
	viewToSearchParams,
} from '../../src/dashboard/urlState';

const BASE = 'page=sit-cwm-dashboard';

describe( 'dashboard URL state', () => {
	it( 'writes nothing for the default view and keeps unrelated params', () => {
		expect( viewToSearchParams( DEFAULT_VIEW, BASE ).toString() ).toBe(
			BASE
		);
		expect( viewFromSearchParams( BASE ) ).toEqual( DEFAULT_VIEW );
	} );

	it( 'writes a filtered view with readable keys', () => {
		const params = viewToSearchParams(
			{
				...DEFAULT_VIEW,
				page: 2,
				filters: [
					{ field: 'status', operator: 'isAny', value: [ 'review' ] },
				],
			},
			BASE
		);

		expect( params.toString() ).toBe( `${ BASE }&paged=2&status=review` );
	} );

	it( 'round-trips a full view', () => {
		const view = {
			...viewFromSearchParams( 'layout=grid' ),
			page: 3,
			perPage: 50,
			search: 'launch plan',
			sort: { field: 'due_date', direction: 'asc' },
			filters: [
				{
					field: 'status',
					operator: 'isAny',
					value: [ 'review', 'approved' ],
				},
				{ field: 'reviewer', operator: 'is', value: 0 },
				{ field: 'author', operator: 'is', value: 3 },
				{ field: 'post_type', operator: 'is', value: 'page' },
				{
					field: 'due_date',
					operator: 'between',
					value: [ '2026-09-01', '2026-09-30' ],
				},
				{ field: 'is_overdue', operator: 'is', value: true },
			],
		};

		const params = viewToSearchParams( view, BASE );

		expect( params.get( 'overdue' ) ).toBe( 'true' );
		expect( params.get( 'page' ) ).toBe( 'sit-cwm-dashboard' );
		expect( params.get( 'type' ) ).toBe( 'page' );
		expect( params.get( 'post_type' ) ).toBeNull();
		expect( params.get( 'due_op' ) ).toBe( 'between' );
		expect( params.get( 'status_op' ) ).toBeNull();
		expect( viewFromSearchParams( params ) ).toEqual( view );
	} );

	it( 'replaces its own stale params', () => {
		const params = viewToSearchParams(
			DEFAULT_VIEW,
			`${ BASE }&status=review&paged=4&due=2026-01-01&due_op=before`
		);

		expect( params.toString() ).toBe( BASE );
	} );

	it( 'drops unknown or invalid values from the URL', () => {
		expect(
			viewFromSearchParams(
				[
					BASE,
					'layout=kanban',
					'paged=-1',
					'per_page=37',
					'orderby=status',
					'status=review',
					'status_op=isNone',
					'reviewer=abc',
					'author=3',
					'author_op=__proto__',
					'due=2026-09-01',
					'due_op=between',
					'secret=1',
				].join( '&' )
			)
		).toEqual( DEFAULT_VIEW );
	} );

	it( 'defaults the direction to descending', () => {
		expect( viewFromSearchParams( 'orderby=title' ).sort ).toEqual( {
			field: 'title',
			direction: 'desc',
		} );
	} );

	it( 'detects an active search or filter', () => {
		expect( hasActiveQuery( DEFAULT_VIEW ) ).toBe( false );
		expect(
			hasActiveQuery( {
				...DEFAULT_VIEW,
				filters: [ { field: 'status', operator: 'isAny', value: [] } ],
			} )
		).toBe( false );
		expect( hasActiveQuery( { ...DEFAULT_VIEW, search: 'x' } ) ).toBe(
			true
		);
		expect(
			hasActiveQuery( {
				...DEFAULT_VIEW,
				filters: [ { field: 'reviewer', operator: 'is', value: 0 } ],
			} )
		).toBe( true );
	} );
} );
