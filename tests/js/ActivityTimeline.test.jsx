/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { getSettings, setSettings } from '@wordpress/date';

/**
 * External dependencies
 */
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import ActivityItem from '../../src/components/ActivityItem';
import ActivityTimeline from '../../src/components/ActivityTimeline';

jest.mock( '@wordpress/api-fetch' );

const jane = { id: 4, name: 'Jane', avatar: 'https://example.org/jane.png' };

const entry = ( id, overrides = {} ) => ( {
	id,
	action: 'comment_added',
	action_label: 'Comment added',
	old_value: null,
	old_label: null,
	new_value: null,
	new_label: null,
	message: '',
	created_at: '2026-09-14T09:00:00+00:00',
	created_at_human: '1 hour ago',
	user_id: 4,
	user: jane,
	...overrides,
} );

const pageResponse = (
	items,
	{ total = items.length, totalPages = 1 } = {}
) => {
	const headers = {
		'X-WP-Total': String( total ),
		'X-WP-TotalPages': String( totalPages ),
	};

	return {
		ok: true,
		status: 200,
		json: () => Promise.resolve( items ),
		headers: { get: ( name ) => headers[ name ] ?? null },
	};
};

const itemTexts = ( container ) =>
	[ ...container.querySelectorAll( '.sit-cwm-activity-sentence' ) ].map(
		( node ) => node.textContent
	);

/**
 * Pins `Date` only. Faking timers too would make Testing Library's `waitFor`
 * fast-forward fake time and give up before mocked requests settle.
 *
 * @param {string} iso Current time.
 */
const pinDate = ( iso ) =>
	jest.useFakeTimers( {
		now: new Date( iso ),
		doNotFake: [
			'hrtime',
			'nextTick',
			'performance',
			'queueMicrotask',
			'requestAnimationFrame',
			'cancelAnimationFrame',
			'requestIdleCallback',
			'cancelIdleCallback',
			'setImmediate',
			'clearImmediate',
			'setInterval',
			'clearInterval',
			'setTimeout',
			'clearTimeout',
		],
	} );

describe( 'ActivityTimeline', () => {
	let original;

	beforeAll( () => {
		original = getSettings();
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

	afterEach( () => {
		jest.useRealTimers();
		apiFetch.mockReset();
	} );

	it( 'shows skeleton rows while the first page loads', () => {
		apiFetch.mockReturnValueOnce( new Promise( () => {} ) );

		const { container } = render( <ActivityTimeline postId={ 42 } /> );

		expect(
			container.querySelectorAll( '.sit-cwm-activity-skeleton' )
		).toHaveLength( 3 );
		expect( screen.getByText( 'Loading activity…' ) ).toBeTruthy();
		expect( screen.queryByText( 'No activity yet.' ) ).toBeNull();
	} );

	it( 'shows the empty state', async () => {
		apiFetch.mockResolvedValueOnce( pageResponse( [] ) );

		const { container } = render( <ActivityTimeline postId={ 42 } /> );

		expect( await screen.findByText( 'No activity yet.' ) ).toBeTruthy();
		expect(
			container.querySelector( '.sit-cwm-activity-skeleton' )
		).toBeNull();
		expect( screen.queryByRole( 'button' ) ).toBeNull();
	} );

	it( 'shows an error with a working retry button', async () => {
		const user = userEvent.setup();

		apiFetch
			.mockRejectedValueOnce( {
				ok: false,
				status: 500,
				json: () =>
					Promise.resolve( {
						code: 'sit_cwm_db_error',
						message: 'Something broke.',
						data: { status: 500 },
					} ),
			} )
			.mockResolvedValueOnce( pageResponse( [ entry( 1 ) ] ) );

		const { container } = render( <ActivityTimeline postId={ 42 } /> );

		// The notice is also spoken through the a11y region; check the notice.
		const notice = () =>
			container.querySelector(
				'.sit-cwm-activity-error .components-notice'
			);

		await waitFor( () =>
			expect( notice()?.textContent ).toContain( 'Something broke.' )
		);

		await user.click( screen.getByRole( 'button', { name: 'Retry' } ) );

		await waitFor( () =>
			expect( itemTexts( container ) ).toEqual( [ 'Jane commented' ] )
		);
		expect( notice() ).toBeNull();
	} );

	it( 'groups entries by site-timezone day with translatable sentences', async () => {
		pinDate( '2026-09-14T10:00:00Z' ); // 19:00 on the 14th in UTC+9.

		apiFetch.mockResolvedValueOnce(
			pageResponse( [
				entry( 4, {
					action: 'status_changed',
					old_value: 'writing',
					old_label: 'Writing',
					new_value: 'review',
					new_label: 'Review',
					// 01:00 on the 14th in UTC+9, still the 13th in UTC.
					created_at: '2026-09-13T16:00:00+00:00',
				} ),
				entry( 3, {
					action: 'reviewer_assigned',
					new_value: '7',
					new_label: 'Sam',
					created_at: '2026-09-13T14:00:00+00:00',
					user_id: 0,
					user: null,
				} ),
				entry( 2, {
					action: 'due_date_cleared',
					created_at: '2026-09-01T08:00:00+00:00',
					user_id: 9,
					user: null,
				} ),
			] )
		);

		const { container } = render( <ActivityTimeline postId={ 42 } /> );

		await waitFor( () =>
			expect( itemTexts( container ) ).toHaveLength( 3 )
		);

		expect(
			screen.getAllByRole( 'heading' ).map( ( node ) => node.textContent )
		).toEqual( [ 'Today', 'Yesterday', 'September 1, 2026' ] );
		expect( itemTexts( container ) ).toEqual( [
			'Jane changed status from Writing to Review',
			'System assigned Sam as reviewer',
			'Someone removed the due date',
		] );

		const [ today ] = screen.getAllByRole( 'list' );
		const time = within( today ).getByText( '1 hour ago' );

		expect( time.tagName ).toBe( 'TIME' );
		expect( time.getAttribute( 'datetime' ) ).toBe(
			'2026-09-13T16:00:00+00:00'
		);
		expect( time.getAttribute( 'title' ) ).toBe(
			'September 14, 2026 1:00 am'
		);
	} );

	it( 'renders comments as plain text', async () => {
		apiFetch.mockResolvedValueOnce(
			pageResponse( [
				entry( 1, {
					message:
						'<p>Looks <strong>good</strong></p><p>Ship it</p><script>alert(1)</script><img src="x" onerror="alert(2)">',
				} ),
			] )
		);

		const { container } = render( <ActivityTimeline postId={ 42 } /> );

		const message = await waitFor( () => {
			const node = container.querySelector( '.sit-cwm-activity-message' );

			expect( node ).not.toBeNull();

			return node;
		} );

		expect( message.textContent ).toBe( 'Looks good\nShip it' );
		expect( message.children ).toHaveLength( 0 );
		expect(
			container.querySelector( 'script, strong + img, [onerror]' )
		).toBeNull();
	} );

	it( 'loads more and appends the next page', async () => {
		const user = userEvent.setup();

		apiFetch
			.mockResolvedValueOnce(
				pageResponse( [ entry( 3 ), entry( 2 ) ], {
					total: 3,
					totalPages: 2,
				} )
			)
			.mockResolvedValueOnce(
				pageResponse( [ entry( 2 ), entry( 1 ) ], {
					total: 3,
					totalPages: 2,
				} )
			);

		const { container } = render(
			<ActivityTimeline postId={ 42 } perPage={ 2 } />
		);

		await user.click(
			await screen.findByRole( 'button', { name: 'Load more' } )
		);

		await waitFor( () =>
			expect( itemTexts( container ) ).toHaveLength( 3 )
		);

		expect( apiFetch.mock.calls[ 1 ][ 0 ].path ).toBe(
			'/sit-cwm/v1/posts/42/activity?page=2&per_page=2'
		);
		expect(
			screen.queryByRole( 'button', { name: 'Load more' } )
		).toBeNull();
	} );

	it( 'prepends a new entry and announces it when the version changes', async () => {
		apiFetch
			.mockResolvedValueOnce( pageResponse( [ entry( 1 ) ] ) )
			.mockResolvedValueOnce(
				pageResponse( [
					entry( 2, {
						action: 'status_changed',
						old_label: 'Review',
						new_label: 'Approved',
					} ),
					entry( 1 ),
				] )
			);

		const { container, rerender } = render(
			<ActivityTimeline postId={ 42 } version={ 0 } />
		);

		await waitFor( () =>
			expect( itemTexts( container ) ).toHaveLength( 1 )
		);

		rerender( <ActivityTimeline postId={ 42 } version={ 1 } /> );

		await waitFor( () =>
			expect( itemTexts( container ) ).toEqual( [
				'Jane changed status from Review to Approved',
				'Jane commented',
			] )
		);
		expect(
			container.querySelector( '[aria-live="polite"]' ).textContent
		).toBe( '1 new activity entry.' );
	} );
} );

describe( 'ActivityItem publication sentences', () => {
	const dana = { id: 5, name: 'Dana', avatar: '' };

	const sentenceOf = ( overrides ) => {
		const { container } = render(
			<ul>
				<ActivityItem
					entry={ entry( 1, {
						user_id: 5,
						user: dana,
						old_value: 'draft',
						new_value: 'publish',
						workflow_status_label: null,
						gate_reason: null,
						...overrides,
					} ) }
				/>
			</ul>
		);

		return itemTexts( container )[ 0 ];
	};

	it.each( [
		[
			'post_published',
			{ workflow_status_label: 'Review' },
			'Dana published this while it was at Review',
		],
		[
			'post_published',
			{
				user: null,
				user_id: 0,
				old_value: 'future',
				workflow_status_label: 'Approved',
			},
			'System published this on schedule while it was at Approved',
		],
		[
			'post_unpublished',
			{ new_label: 'Draft' },
			'Dana unpublished this (now Draft)',
		],
		[
			'publish_gate_refused',
			{ new_label: 'Published', workflow_status_label: 'Writing' },
			'Publishing by Dana was blocked: it was at Writing, not approved',
		],
		[
			'publish_gate_bypassed',
			{ gate_reason: 'capability', workflow_status_label: 'Draft' },
			'Dana published this without approval (it was at Draft)',
		],
		[
			'publish_gate_bypassed',
			{ gate_reason: 'error' },
			'Dana published this without the approval check (internal error)',
		],
	] )( '%s renders %p', ( action, extras, expected ) => {
		expect( sentenceOf( { action, ...extras } ) ).toBe( expected );
	} );

	it( 'decodes entities in the workflow status label', () => {
		expect(
			sentenceOf( {
				action: 'post_published',
				workflow_status_label: 'Legal &amp; review',
			} )
		).toBe( 'Dana published this while it was at Legal & review' );
	} );

	it.each( [
		[ 'post_published', 'Published' ],
		[ 'publish_gate_refused', 'Publishing blocked' ],
		[ 'publish_gate_bypassed', 'Published without approval' ],
	] )(
		'%s without a workflow status label falls back to the action label',
		( action, label ) => {
			expect( sentenceOf( { action, action_label: label } ) ).toBe(
				`Dana: ${ label }`
			);
		}
	);
} );
