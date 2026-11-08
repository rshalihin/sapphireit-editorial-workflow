/**
 * WordPress dependencies
 */
import { addFilter, removeAllFilters } from '@wordpress/hooks';

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

describe( 'add-on filters in the URL', () => {
	const elements = [
		{ value: 'my_groups', label: 'My groups' },
		{ value: 'all', label: 'All' },
	];
	const filterable = ( id ) => ( {
		id,
		label: id,
		elements,
		filterBy: { operators: [ 'is' ] },
	} );

	afterEach( () => removeAllFilters( 'sitCwm.dashboard.fields' ) );

	it( 'round-trips an add-on filter', () => {
		addFilter( 'sitCwm.dashboard.fields', 't', () => [
			filterable( 'pro_view' ),
		] );

		const filter = {
			field: 'pro_view',
			operator: 'is',
			value: 'my_groups',
		};
		const params = viewToSearchParams(
			{ ...DEFAULT_VIEW, filters: [ filter ] },
			BASE
		);

		expect( params.toString() ).toBe( `${ BASE }&pro_view=my_groups` );
		expect( viewFromSearchParams( params ).filters ).toEqual( [ filter ] );
	} );

	it( 'removes the key once the filter is cleared', () => {
		addFilter( 'sitCwm.dashboard.fields', 't', () => [
			filterable( 'pro_view' ),
		] );

		expect(
			viewToSearchParams(
				DEFAULT_VIEW,
				`${ BASE }&pro_view=my_groups`
			).toString()
		).toBe( BASE );
	} );

	it( 'drops a value the field does not offer', () => {
		addFilter( 'sitCwm.dashboard.fields', 't', () => [
			filterable( 'pro_view' ),
		] );

		expect( viewFromSearchParams( 'pro_view=nope' ).filters ).toEqual( [] );
	} );

	it( 'never lets an add-on field take a key this module owns', () => {
		addFilter( 'sitCwm.dashboard.fields', 't', () => [
			filterable( 'search' ),
			filterable( 'paged' ),
			filterable( 'page' ),
		] );

		const view = {
			...DEFAULT_VIEW,
			filters: [ { field: 'page', operator: 'is', value: 'all' } ],
		};

		expect( viewToSearchParams( view, BASE ).toString() ).toBe( BASE );
		expect( viewFromSearchParams( 'search=all&paged=2' ).filters ).toEqual(
			[]
		);
		expect( viewFromSearchParams( 'page=all' ).filters ).toEqual( [] );
	} );
} );

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
