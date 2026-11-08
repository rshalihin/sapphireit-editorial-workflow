/**
 * WordPress dependencies
 */
import { addFilter, removeAllFilters } from '@wordpress/hooks';

/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import buildFields, {
	addonFields,
	addonFilterFields,
	mergeUsers,
} from '../../src/dashboard/fields';
import {
	DEFAULT_LAYOUTS,
	defaultLayouts,
	defaultView,
	viewFromSearchParams,
} from '../../src/dashboard/urlState';
import { FILTER_OPERATORS } from '../../src/hooks/usePosts';

const row = ( overrides = {} ) => ( {
	post_id: 12,
	title: 'Launch plan',
	post_type: 'page',
	author: { id: 3, name: 'Ada Author' },
	status: 'review',
	status_label: 'Review',
	reviewer: null,
	due_date: '',
	is_overdue: false,
	last_activity: null,
	edit_link: 'http://example.org/wp-admin/post.php?post=12&action=edit',
	available_transitions: [],
	capabilities: {},
	...overrides,
} );

const field = ( fields, id ) => fields.find( ( entry ) => entry.id === id );

const renderCell = ( id, item, options ) => {
	const fields = buildFields( options );
	const { render: Cell } = field( fields, id );

	return render( <Cell item={ item } field={ field( fields, id ) } /> );
};

beforeEach( () => {
	window.sitCwm.statuses = [
		{ slug: 'review', label: 'Review', color: '#dba617' },
		{ slug: 'approved', label: 'Approved', color: '#008a20' },
	];
	window.sitCwm.postTypes = [
		{ slug: 'post', label: 'Posts', singularLabel: 'Post' },
		{ slug: 'page', label: 'Pages', singularLabel: 'Page' },
	];
} );

describe( 'buildFields', () => {
	it( 'defines the dashboard columns in order', () => {
		expect( buildFields().map( ( entry ) => entry.id ) ).toEqual( [
			'title',
			'status',
			'ai_origin',
			'reviewer',
			'due_date',
			'author',
			'post_type',
			'last_activity',
			'is_overdue',
		] );
	} );

	it( 'offers "Overdue only" as a filter that is never a column', () => {
		const overdue = field( buildFields(), 'is_overdue' );

		expect( overdue.elements ).toEqual( [
			{ value: true, label: 'Overdue only' },
		] );
		// Not primary: primary filters pin the filter bar open, which makes
		// the DataViews filter toggle collapse and immediately re-expand it.
		expect( overdue.filterBy ).toEqual( {
			operators: FILTER_OPERATORS.is_overdue,
		} );
		expect( overdue.enableHiding ).toBe( false );
	} );

	it( 'reads status and post type options from the bootstrap', () => {
		const fields = buildFields();

		expect( field( fields, 'status' ).elements ).toEqual( [
			{ value: 'review', label: 'Review' },
			{ value: 'approved', label: 'Approved' },
		] );
		expect( field( fields, 'post_type' ).elements ).toEqual( [
			{ value: 'post', label: 'Post' },
			{ value: 'page', label: 'Page' },
		] );

		window.sitCwm.statuses = [ { slug: 'custom', label: 'Custom' } ];

		expect( field( buildFields(), 'status' ).elements ).toEqual( [
			{ value: 'custom', label: 'Custom' },
		] );
	} );

	it( 'builds reviewer and author options from the given users', () => {
		const fields = buildFields( {
			reviewers: [
				{ id: 9, name: 'Zoe' },
				{ id: 5, name: 'Ben' },
				{ id: 9, name: 'Zoe again' },
			],
			authors: [ { id: 3, name: 'Ada Author' } ],
		} );

		expect( field( fields, 'reviewer' ).elements ).toEqual( [
			{ value: 0, label: 'Unassigned' },
			{ value: 5, label: 'Ben' },
			{ value: 9, label: 'Zoe' },
		] );
		expect( field( fields, 'author' ).elements ).toEqual( [
			{ value: 3, label: 'Ada Author' },
		] );
		expect( field( fields, 'author' ).filterBy ).toEqual( {
			operators: FILTER_OPERATORS.author,
		} );
	} );

	it( 'disables the author filter when no authors are known', () => {
		expect( field( buildFields(), 'author' ).filterBy ).toBe( false );
	} );

	it( 'only sorts by title and due date', () => {
		expect(
			buildFields()
				.filter( ( entry ) => entry.enableSorting )
				.map( ( entry ) => entry.id )
		).toEqual( [ 'title', 'due_date' ] );
	} );

	it( 'only offers operators viewToQuery understands', () => {
		const fields = buildFields( { authors: [ { id: 3, name: 'A' } ] } );
		const offered = Object.fromEntries(
			fields
				.filter( ( entry ) => entry.filterBy !== false )
				.map( ( entry ) => [ entry.id, entry.filterBy.operators ] )
		);

		expect( offered ).toEqual( FILTER_OPERATORS );
	} );

	it( 'reads filter values from the row', () => {
		const fields = buildFields();
		const item = row( {
			reviewer: { id: 7, name: 'Rae' },
			due_date: '2026-09-20',
		} );

		expect( field( fields, 'status' ).getValue( { item } ) ).toBe(
			'review'
		);
		expect( field( fields, 'reviewer' ).getValue( { item } ) ).toBe( 7 );
		expect( field( fields, 'reviewer' ).getValue( { item: row() } ) ).toBe(
			0
		);
		expect( field( fields, 'author' ).getValue( { item } ) ).toBe( 3 );
		expect( field( fields, 'due_date' ).getValue( { item } ) ).toBe(
			'2026-09-20'
		);
	} );
} );

describe( 'field rendering', () => {
	it( 'links the title to the edit screen', () => {
		renderCell( 'title', row() );

		expect(
			screen
				.getByRole( 'link', { name: 'Launch plan' } )
				.getAttribute( 'href' )
		).toBe( 'http://example.org/wp-admin/post.php?post=12&action=edit' );
	} );

	it( 'renders the title as text without a safe edit link', () => {
		renderCell(
			'title',
			row( { title: '<b>x</b>', edit_link: 'javascript:alert(1)' } )
		);

		expect( screen.queryByRole( 'link' ) ).toBeNull();
		expect( screen.getByText( '<b>x</b>' ) ).toBeTruthy();
	} );

	it( 'decodes entities that kses stored in the title', () => {
		renderCell( 'title', row( { title: 'A &amp; B' } ) );

		expect( screen.getByRole( 'link', { name: 'A & B' } ) ).toBeTruthy();
	} );

	it( 'keeps a decoded title inert', () => {
		const { container } = renderCell(
			'title',
			row( { title: '&lt;img src=x onerror=alert(1)&gt;' } )
		);

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect(
			screen.getByText( '<img src=x onerror=alert(1)>' )
		).toBeTruthy();
	} );

	it( 'decodes the title value used for sorting and search', () => {
		const { getValue } = field( buildFields(), 'title' );

		expect( getValue( { item: row( { title: 'Q&amp;A' } ) } ) ).toBe(
			'Q&A'
		);
	} );

	it( 'falls back for an untitled post', () => {
		renderCell( 'title', row( { title: '' } ) );

		expect( screen.getByText( '(no title)' ) ).toBeTruthy();
	} );

	it( 'decodes reviewer and author names', () => {
		renderCell(
			'reviewer',
			row( { reviewer: { id: 7, name: 'R &amp; D', avatar: '' } } )
		);
		renderCell(
			'author',
			row( { author: { id: 3, name: 'Tom &#039;T&#039;' } } )
		);

		expect( screen.getByText( 'R & D' ) ).toBeTruthy();
		expect( screen.getByText( "Tom 'T'" ) ).toBeTruthy();
	} );

	it( 'renders the status badge with its label', () => {
		const { container } = renderCell( 'status', row() );

		expect( screen.getByText( 'Review' ) ).toBeTruthy();
		expect(
			container.querySelector( '.sit-cwm-status-swatch' ).style
				.backgroundColor
		).toBe( 'rgb(219, 166, 23)' );
	} );

	it( 'renders the declared AI use, read-only', () => {
		const aiOrigin = field( buildFields(), 'ai_origin' );

		expect( aiOrigin.label ).toBe( 'AI use' );
		expect( aiOrigin.enableSorting ).toBe( false );
		expect( aiOrigin.filterBy ).toBe( false );

		renderCell( 'ai_origin', row( { ai_origin: 'ai_generated' } ) );
		renderCell( 'ai_origin', row( { ai_origin: '' } ) );

		expect( screen.getByText( 'AI-generated' ) ).toBeTruthy();
		expect( screen.getByText( 'Not declared' ) ).toBeTruthy();
	} );

	it( 'renders a dash for an unassigned reviewer', () => {
		renderCell( 'reviewer', row() );

		expect( screen.getByText( '—' ) ).toBeTruthy();
		expect( screen.getByText( 'Unassigned' ) ).toBeTruthy();
	} );

	it( 'renders the reviewer avatar and name', () => {
		const { container } = renderCell(
			'reviewer',
			row( {
				reviewer: {
					id: 7,
					name: 'Rae Reviewer',
					avatar: 'https://secure.gravatar.com/avatar/x',
				},
			} )
		);

		expect( screen.getByText( 'Rae Reviewer' ) ).toBeTruthy();
		expect( container.querySelector( 'img' ).getAttribute( 'src' ) ).toBe(
			'https://secure.gravatar.com/avatar/x'
		);
	} );

	it( 'marks overdue dates using the server flag', () => {
		const { container } = renderCell(
			'due_date',
			row( { due_date: '2020-01-01', is_overdue: true } )
		);

		expect( container.querySelector( '.is-overdue' ) ).not.toBeNull();
		expect( container.textContent ).toMatch( /\(overdue\)$/ );
	} );

	it( 'does not mark a past date the server says is not overdue', () => {
		const { container } = renderCell(
			'due_date',
			row( { due_date: '2020-01-01', is_overdue: false } )
		);

		expect( container.querySelector( '.is-overdue' ) ).toBeNull();
		expect( container.textContent ).not.toMatch( /overdue/ );
	} );

	it( 'renders the singular post type label', () => {
		renderCell( 'post_type', row() );

		expect( screen.getByText( 'Page' ) ).toBeTruthy();
	} );

	it( 'renders the last activity action and time', () => {
		const { container } = renderCell(
			'last_activity',
			row( {
				last_activity: {
					action_label: 'Comment added',
					created_at: '2026-09-14T10:00:00+00:00',
				},
			} )
		);

		expect( screen.getByText( 'Comment added' ) ).toBeTruthy();
		expect(
			container.querySelector( 'time' ).getAttribute( 'datetime' )
		).toBe( '2026-09-14T10:00:00+00:00' );
	} );

	it( 'renders a dash without activity', () => {
		renderCell( 'last_activity', row() );

		expect( screen.getByText( 'No activity' ) ).toBeTruthy();
	} );
} );

describe( 'the sitCwm.dashboard.fields filter', () => {
	const HOOK = 'sitCwm.dashboard.fields';
	const elements = [ { value: 'my_groups', label: 'My groups' } ];

	afterEach( () => removeAllFilters( HOOK ) );

	it( 'changes nothing with no filter registered', () => {
		expect( addonFields() ).toEqual( [] );
		expect( buildFields().map( ( entry ) => entry.id ) ).toEqual( [
			'title',
			'status',
			'ai_origin',
			'reviewer',
			'due_date',
			'author',
			'post_type',
			'last_activity',
			'is_overdue',
		] );
	} );

	it( 'appends added fields and keeps every core field', () => {
		addFilter( HOOK, 't', () => [
			{ id: 'pro_workflow', label: 'Workflow' },
		] );

		const ids = buildFields().map( ( entry ) => entry.id );

		expect( ids.slice( 0, 2 ) ).toEqual( [ 'title', 'status' ] );
		expect( ids[ ids.length - 1 ] ).toBe( 'pro_workflow' );
		expect( ids ).toContain( 'is_overdue' );
	} );

	it( 'makes an added field hideable and unsortable', () => {
		addFilter( HOOK, 't', ( fields ) => [
			...fields,
			{
				id: 'pro_workflow',
				label: 'Workflow',
				enableHiding: false,
				enableSorting: true,
			},
		] );

		const added = field( buildFields(), 'pro_workflow' );

		expect( added.enableHiding ).toBe( true );
		expect( added.enableSorting ).toBe( false );
	} );

	it( 'cannot replace a core field or add a malformed one', () => {
		addFilter( HOOK, 't', ( fields ) => [
			...fields,
			{ id: 'status', label: 'Hacked' },
			null,
			{ label: 'No id' },
		] );

		const fields = buildFields();

		expect( field( fields, 'status' ).label ).toBe( 'Status' );
		expect( addonFields() ).toEqual( [] );
		expect(
			fields.filter( ( entry ) => entry.id === 'status' )
		).toHaveLength( 1 );
	} );

	it( 'a filter callback that throws leaves the core fields', () => {
		addFilter( HOOK, 't', () => {
			throw new Error( 'boom' );
		} );

		expect( field( buildFields(), 'title' ) ).toBeTruthy();
		expect( addonFields() ).toEqual( [] );
		expect( viewFromSearchParams( '' ).filters ).toEqual( [] );
		expect( console ).toHaveErrored();
	} );

	it( 'drops malformed elements and keeps the field working', () => {
		addFilter( HOOK, 't', () => [
			{
				id: 'pro_view',
				label: 'View',
				elements: [ null, 'text', ...elements ],
				filterBy: { operators: [ 'is' ] },
			},
			{
				id: 'pro_empty',
				label: 'Empty',
				elements: [ null ],
				filterBy: { operators: [ 'is' ] },
			},
		] );

		expect( field( addonFields(), 'pro_view' ).elements ).toEqual(
			elements
		);
		expect( field( addonFields(), 'pro_empty' ).filterBy ).toBe( false );
		expect( viewFromSearchParams( 'pro_view=my_groups' ).filters ).toEqual(
			[ { field: 'pro_view', operator: 'is', value: 'my_groups' } ]
		);
	} );

	it( 'ignores a non-array result', () => {
		addFilter( HOOK, 't', () => 'nope' );

		expect( addonFields() ).toEqual( [] );
		expect( field( buildFields(), 'title' ) ).toBeTruthy();
	} );

	it( 'lists only fields with elements and the "is" operator as filterable', () => {
		addFilter( HOOK, 't', () => [
			{
				id: 'pro_view',
				label: 'View',
				elements,
				filterBy: { operators: [ 'is' ] },
			},
			{ id: 'pro_plain', label: 'Plain' },
			{
				id: 'pro_any',
				label: 'Any',
				elements,
				filterBy: { operators: [ 'isAny' ] },
			},
		] );

		expect( addonFilterFields().map( ( entry ) => entry.id ) ).toEqual( [
			'pro_view',
		] );
		expect( field( buildFields(), 'pro_plain' ).filterBy ).toBe( false );
		expect( field( buildFields(), 'pro_any' ).filterBy ).toBe( false );
	} );

	it( 'shows a defaultVisible field from the start, and no other', () => {
		addFilter( HOOK, 't', () => [
			{ id: 'pro_stage', label: 'Stage', defaultVisible: true },
			{ id: 'pro_view', label: 'View' },
		] );

		const { fields } = viewFromSearchParams( '' );

		expect( fields ).toContain( 'pro_stage' );
		expect( fields ).not.toContain( 'pro_view' );
		expect( viewFromSearchParams( 'layout=grid' ).fields ).toContain(
			'pro_stage'
		);
		expect( defaultLayouts().table.fields ).toContain( 'pro_stage' );
		expect( defaultLayouts().grid.fields ).toContain( 'pro_stage' );
		expect( defaultView().fields ).toContain( 'pro_stage' );
		expect( DEFAULT_LAYOUTS.table.fields ).not.toContain( 'pro_stage' );
	} );
} );

describe( 'mergeUsers', () => {
	it( 'keeps the first user per id and skips invalid entries', () => {
		expect(
			mergeUsers(
				[ { id: 2, name: 'A' }, null ],
				[
					{ id: 2, name: 'B' },
					{ id: 0, name: 'Nobody' },
					{ id: 4, name: 'C' },
				]
			)
		).toEqual( [
			{ id: 2, name: 'A' },
			{ id: 4, name: 'C' },
		] );
	} );
} );
