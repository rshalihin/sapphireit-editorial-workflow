/**
 * Captures the documentation screenshots in docs/screenshots/ (step 23).
 *
 * Skipped unless CWM_CAPTURE is set, so it never runs in the CI end-to-end job:
 * it is a generator, not a test, and it asserts only enough to be sure it is
 * photographing the right thing.
 *
 * Run it with `npm run screenshots` (see bin/capture-screenshots.js), which
 * points it at a local WordPress and sets the flag.
 *
 * Like the other specs it is destructive — it deletes every post before and
 * after — so point it at a scratch site.
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
import { openWorkflowSidebar } from './utils';

const OUT = path.join( __dirname, '../../docs/screenshots' );

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
					username: 'dana',
					name: 'Dana Okafor',
					email: 'dana@example.org',
					password: 'cwm-Screenshot-pass-1!',
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
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'sidebar and timeline', async ( { page } ) => {
		const panel = await openWorkflowSidebar(
			page,
			ids[ 'Q3 launch announcement' ]
		);

		await expect( panel ).toContainText( 'Review' );

		const timeline = panel.locator( '.sit-cwm-activity' );

		// Everything the sidebar fetches must have landed before either shot,
		// or the images show loading skeletons and a spinning reviewer field.
		await expect( timeline ).toBeVisible();
		await expect( timeline ).toContainText( 'changed status from' );
		await expect( timeline ).toHaveAttribute( 'aria-busy', 'false' );
		await expect(
			panel.locator( '.sit-cwm-reviewer .components-spinner' )
		).toHaveCount( 0 );

		// The sidebar alone, not the whole editor: the panel is the subject.
		// Clipping the page rather than screenshotting the element keeps the
		// shot to what is actually painted — the panel's box runs past the
		// viewport, and an element screenshot pads the overflow with blank.
		const box = await panel.boundingBox();
		const viewport = page.viewportSize();

		await page.screenshot( {
			path: path.join( OUT, 'sidebar.png' ),
			clip: {
				x: box.x,
				y: box.y,
				width: box.width,
				height: Math.min( box.height, viewport.height - box.y ),
			},
		} );

		await timeline.scrollIntoViewIfNeeded();
		await timeline.screenshot( {
			path: path.join( OUT, 'timeline.png' ),
		} );
	} );

	test( 'classic editor box', async ( { admin, page, requestUtils } ) => {
		// Needs the Classic Editor plugin installed on the target site. It is
		// active only for this shot, so the block editor shots stay as they are.
		await requestUtils.activatePlugin( 'classic-editor' );

		try {
			await admin.visitAdminPage(
				'post.php',
				`post=${ ids[ 'Q3 launch announcement' ] }&action=edit`
			);

			const box = page.locator( '#sit-cwm-workflow' );
			const timeline = box.locator( '.sit-cwm-activity' );

			// The same settled state as the sidebar shot: no skeletons, no spinner.
			await expect( box ).toContainText( 'Review' );
			await expect( timeline ).toContainText( 'changed status from' );
			await expect( timeline ).toHaveAttribute( 'aria-busy', 'false' );
			await expect(
				box.locator( '.sit-cwm-reviewer .components-spinner' )
			).toHaveCount( 0 );

			await box.screenshot( {
				path: path.join( OUT, 'classic-editor.png' ),
			} );
		} finally {
			await requestUtils.deactivatePlugin( 'classic-editor' );
		}
	} );

	test( 'dashboard and bulk actions', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		const rows = page.locator( '.dataviews-view-table tbody tr' );

		// The site may hold pages of its own; every seeded post must be there.
		await expect( rows.first() ).toBeVisible();

		for ( const { title } of POSTS ) {
			await expect(
				page.getByText( title, { exact: true } )
			).toBeVisible();
		}

		await page.screenshot( {
			path: path.join( OUT, 'dashboard.png' ),
		} );

		// Selecting rows reveals the bulk action bar.
		await page
			.getByRole( 'checkbox', { name: 'Q3 launch announcement' } )
			.check();
		await page
			.getByRole( 'checkbox', { name: 'Migrating to block themes' } )
			.check();

		const changeStatus = page.getByRole( 'button', {
			name: 'Change status',
		} );

		await expect( changeStatus ).toBeVisible();
		await changeStatus.click();

		const dialog = page.getByRole( 'dialog', {
			name: 'Change workflow status',
		} );

		await expect( dialog ).toBeVisible();
		await dialog
			.getByRole( 'combobox', { name: 'New status' } )
			.selectOption( 'approved' );

		await page.screenshot( {
			path: path.join( OUT, 'bulk-actions.png' ),
		} );

		await page.keyboard.press( 'Escape' );
	} );

	test( 'settings', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-settings' );

		await expect(
			page.getByRole( 'heading', { name: 'Editorial Workflow Settings' } )
		).toBeVisible();

		await page.screenshot( {
			path: path.join( OUT, 'settings.png' ),
		} );

		await page.keyboard.press( 'Escape' );
	} );
} );
