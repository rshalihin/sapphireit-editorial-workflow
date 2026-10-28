/**
 * Captures the documentation and WordPress.org screenshots in docs/screenshots/
 * as `screenshot-1.png` … `screenshot-5.png`, one per caption in `readme.txt`'s
 * Screenshots section, in that order.
 *
 * Skipped unless CWM_CAPTURE is set, so it never runs in the CI end-to-end job:
 * it is a generator, not a test, and it asserts only enough to be sure it is
 * photographing the right thing.
 *
 * Run it with `npm run screenshots` (see bin/capture-screenshots.js), which
 * points it at a local WordPress and sets the flag.
 *
 * Like the other specs it is destructive — it deletes every post before and
 * after — so point it at a scratch site. It needs the test-only mu-plugin
 * (`setSettings()`, `rawPublish()`) and the Classic Editor plugin installed.
 */

/**
 * External dependencies
 */
const path = require( 'path' );

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	allNotifications,
	loginAs,
	openWorkflowSidebar,
	rawPublish,
	setSettings,
} from './utils';

const OUT = path.join( __dirname, '../../docs/screenshots' );

/**
 * The seeded reviewer, who is also the user the sidebar shot logs in as.
 *
 * @type {{username: string, password: string}}
 */
const REVIEWER = { username: 'dana', password: 'cwm-Screenshot-pass-1!' };

/**
 * Path of a numbered shot, named the way the WordPress.org listing wants it:
 * `screenshot-N.png` matches caption N in `readme.txt`'s Screenshots section.
 *
 * @param {number} n Caption number.
 * @return {string} File path.
 */
const shot = ( n ) => path.join( OUT, `screenshot-${ n }.png` );

/**
 * Waits until everything the workflow panel fetches has landed, so a shot
 * never shows loading skeletons or a spinning reviewer field.
 *
 * @param {Object} container Panel or meta box locator.
 * @return {Promise<void>}
 */
async function settled( container ) {
	const timeline = container.locator( '.sit-cwm-activity' );

	await expect( timeline ).toBeVisible();
	await expect( timeline ).toContainText( 'changed status from' );
	await expect( timeline ).toHaveAttribute( 'aria-busy', 'false' );
	await expect(
		container.locator( '.sit-cwm-reviewer .components-spinner' )
	).toHaveCount( 0 );
}

/**
 * Posts to seed, with the workflow state each ends up in.
 *
 * Titles read like a real editorial queue: a screenshot full of "Test post 1"
 * tells a reader nothing about what the plugin is for.
 *
 * @type {Array<{title: string, status: string, due: string, reviewer: boolean}>}
 */
const POSTS = [
	{
		title: 'Q3 launch announcement',
		status: 'review',
		due: '2030-03-04',
		reviewer: true,
	},
	{
		title: 'Migrating to block themes',
		status: 'review',
		due: '2030-03-06',
		reviewer: true,
	},
	{
		title: 'Accessibility audit findings',
		status: 'needs_changes',
		due: '2030-02-20',
		reviewer: true,
	},
	{
		title: 'Customer story: Northwind',
		status: 'approved',
		due: '2030-03-11',
		reviewer: true,
	},
	{
		title: 'Release notes 4.2',
		status: 'writing',
		due: '2030-03-18',
		reviewer: false,
	},
	{
		title: 'Editorial calendar for spring',
		status: 'draft',
		due: '',
		reviewer: false,
	},
];

/**
 * The path from `draft` to each seeded status.
 *
 * @type {Object<string, string[]>}
 */
const ROUTES = {
	draft: [],
	writing: [ 'writing' ],
	review: [ 'writing', 'review' ],
	needs_changes: [ 'writing', 'review', 'needs_changes' ],
	approved: [ 'writing', 'review', 'approved' ],
};

test.describe( 'Documentation screenshots', () => {
	// eslint-disable-next-line playwright/no-skipped-test -- Deliberate: this file generates documentation images, so CI skips it.
	test.skip(
		! process.env.CWM_CAPTURE,
		'Set CWM_CAPTURE=1 (npm run screenshots) to regenerate the docs images.'
	);

	// One wide, stable viewport, so every image crops the same way.
	test.use( { viewport: { width: 1440, height: 900 } } );

	let reviewerId;
	let ids = {};

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();

		const reviewer = await requestUtils
			.rest( {
				method: 'POST',
				path: '/wp/v2/users',
				data: {
					username: REVIEWER.username,
					name: 'Dana Okafor',
					email: 'dana@example.org',
					password: REVIEWER.password,
					roles: [ 'editor' ],
				},
			} )
			.catch( async () => {
				const [ existing ] = await requestUtils.rest( {
					path: '/wp/v2/users',
					params: { search: 'dana', context: 'edit' },
				} );
				return existing;
			} );

		reviewerId = reviewer.id;
		ids = {};

		for ( const post of POSTS ) {
			const created = await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/posts',
				data: { title: post.title, status: 'draft' },
			} );
			const route = `/sit-cwm/v1/posts/${ created.id }/workflow`;

			ids[ post.title ] = created.id;

			if ( post.due || post.reviewer ) {
				await requestUtils.rest( {
					method: 'POST',
					path: route,
					data: {
						...( post.due ? { due_date: post.due } : {} ),
						...( post.reviewer ? { reviewer_id: reviewerId } : {} ),
					},
				} );
			}

			let from = 'draft';

			for ( const to of ROUTES[ post.status ] ) {
				await requestUtils.rest( {
					method: 'POST',
					path: route,
					data: { from, status: to },
				} );
				from = to;
			}

			if ( 'review' === post.status ) {
				await requestUtils.rest( {
					method: 'POST',
					path: `/sit-cwm/v1/posts/${ created.id }/comments`,
					data: {
						message:
							'Numbers in the second section need a source before this goes out.',
					},
				} );
			}
		}

		// The timeline shot's story, on the post at Review: published while
		// the gate was off, taken back to draft, then a publish the gate
		// blocked once it was set to Enforce.
		const story = ids[ 'Q3 launch announcement' ];

		await setSettings( requestUtils, { publish_gate: {} } );
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ story }`,
			data: { status: 'publish' },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ story }`,
			data: { status: 'draft' },
		} );
		await setSettings( requestUtils, {
			publish_gate: { post: 'enforce' },
			notifications: {
				review_requested: true,
				changes_requested: true,
				approved: true,
			},
		} );
		await rawPublish( requestUtils, story );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			publish_gate: {},
			notifications: allNotifications( false ),
		} );
		await requestUtils.deleteAllPosts();
	} );

	test( '1: sidebar with the locked notice', async ( { page } ) => {
		// Dana, an editor, holds no bypass, so she sees the locked notice an
		// administrator would not.
		await loginAs( page, REVIEWER.username, REVIEWER.password );

		const panel = await openWorkflowSidebar(
			page,
			ids[ 'Q3 launch announcement' ]
		);

		await expect( panel ).toContainText( 'Review' );
		await expect( panel.locator( '.sit-cwm-gate-notice' ) ).toContainText(
			'Publishing is locked until this content is approved.'
		);
		await settled( panel );

		// The sidebar alone, not the whole editor: the panel is the subject.
		// Clipping the page rather than screenshotting the element keeps the
		// shot to what is actually painted — the panel's box runs past the
		// viewport, and an element screenshot pads the overflow with blank.
		const box = await panel.boundingBox();
		const viewport = page.viewportSize();

		await page.screenshot( {
			path: shot( 1 ),
			clip: {
				x: box.x,
				y: box.y,
				width: box.width,
				height: Math.min( box.height, viewport.height - box.y ),
			},
		} );
	} );

	test( '2: timeline with a publication at Review and a blocked publish', async ( {
		page,
	} ) => {
		const panel = await openWorkflowSidebar(
			page,
			ids[ 'Q3 launch announcement' ]
		);
		const timeline = panel.locator( '.sit-cwm-activity' );

		await settled( panel );
		await expect( timeline ).toContainText(
			'published this while it was at Review'
		);
		await expect( timeline ).toContainText( 'was blocked' );

		await timeline.scrollIntoViewIfNeeded();
		await timeline.screenshot( { path: shot( 2 ) } );
	} );

	test( '3: settings with the publish gate and notifications', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-settings' );

		await expect(
			page.getByRole( 'heading', { name: 'Publish gate' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Email notifications' } )
		).toBeVisible();

		// Both new sections sit below the fold at 900 px.
		await page.screenshot( { path: shot( 3 ), fullPage: true } );
	} );

	test( '4: dashboard', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		const rows = page.locator( '.dataviews-view-table tbody tr' );

		// The site may hold pages of its own; every seeded post must be there.
		await expect( rows.first() ).toBeVisible();

		for ( const { title } of POSTS ) {
			await expect(
				page.getByText( title, { exact: true } )
			).toBeVisible();
		}

		await page.screenshot( { path: shot( 4 ) } );
	} );

	test( '5: classic editor box', async ( { admin, page, requestUtils } ) => {
		// Needs the Classic Editor plugin installed on the target site. It is
		// active only for this shot, so the block editor shots stay as they are.
		await requestUtils.activatePlugin( 'classic-editor' );

		try {
			await admin.visitAdminPage(
				'post.php',
				`post=${ ids[ 'Customer story: Northwind' ] }&action=edit`
			);

			const box = page.locator( '#sit-cwm-workflow' );

			// The same settled state as the sidebar shot: no skeletons, no spinner.
			await expect( box ).toContainText( 'Approved' );
			await settled( box );

			await box.screenshot( { path: shot( 5 ) } );
		} finally {
			await requestUtils.deactivatePlugin( 'classic-editor' );
		}
	} );
} );
