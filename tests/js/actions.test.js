/**
 * Internal dependencies
 */
import buildActions from '../../src/dashboard/actions';

const row = ( overrides = {} ) => ( {
	post_id: 12,
	title: 'Launch plan',
	status: 'review',
	status_label: 'Review',
	edit_link: 'http://example.org/wp-admin/post.php?post=12&action=edit',
	available_transitions: [],
	capabilities: {
		can_change_status: false,
		can_assign_reviewer: false,
		can_set_due_date: false,
	},
	...overrides,
} );

const eligible = ( item ) =>
	buildActions( { onChanged: () => {} } )
		.filter( ( action ) => action.isEligible( item ) )
		.map( ( action ) => action.id );

const BULK = [ 'change-status', 'assign-reviewer', 'set-due-date' ];

describe( 'dashboard actions', () => {
	it( 'defines row quick actions and the bulk-capable actions', () => {
		const actions = buildActions( { onChanged: () => {} } );

		expect( actions.map( ( action ) => action.id ) ).toEqual( [
			'edit',
			'approve',
			'request-changes',
			...BULK,
		] );
		expect(
			actions
				.filter( ( action ) => action.supportsBulk )
				.map( ( action ) => action.id )
		).toEqual( BULK );
	} );

	it( 'disables bulk actions while a batch runs without swapping modals', () => {
		const idle = buildActions( { onChanged: () => {} } );
		const running = buildActions( {
			onChanged: () => {},
			isBulkRunning: true,
		} );

		running
			.filter( ( action ) => action.supportsBulk )
			.forEach( ( action, index ) => {
				expect( action.disabled ).toBe( true );
				expect( action.RenderModal ).toBe(
					idle.filter( ( entry ) => entry.supportsBulk )[ index ]
						.RenderModal
				);
			} );
	} );

	it( 'offers only Edit when the server offers nothing else', () => {
		expect( eligible( row() ) ).toEqual( [ 'edit' ] );
	} );

	it( 'offers status actions only for transitions the server lists', () => {
		expect(
			eligible(
				row( {
					available_transitions: [
						{ slug: 'approved', is_rollback: false },
						{ slug: 'needs_changes', is_rollback: true },
					],
				} )
			)
		).toEqual( [ 'edit', 'approve', 'request-changes', 'change-status' ] );

		expect(
			eligible(
				row( {
					available_transitions: [ { slug: 'needs_changes' } ],
				} )
			)
		).toEqual( [ 'edit', 'request-changes', 'change-status' ] );
	} );

	it( 'offers reviewer and due date actions from the row capabilities', () => {
		expect(
			eligible(
				row( {
					capabilities: {
						can_assign_reviewer: true,
						can_set_due_date: true,
					},
				} )
			)
		).toEqual( [ 'edit', 'assign-reviewer', 'set-due-date' ] );
	} );

	it( 'hides Edit without a safe edit link', () => {
		expect( eligible( row( { edit_link: '' } ) ) ).toEqual( [] );
		expect(
			eligible( row( { edit_link: 'javascript:alert(1)' } ) )
		).toEqual( [] );
	} );

	it( 'tolerates rows without hints', () => {
		expect(
			eligible( {
				post_id: 1,
				edit_link: '',
				available_transitions: null,
			} )
		).toEqual( [] );
	} );
} );
