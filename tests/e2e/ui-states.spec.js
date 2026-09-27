/**
 * End-to-end: every loading, empty, error and offline state, and the small
 * screen layout (steps 22.1 and 22.6).
 *
 * Failures are forced by intercepting the plugin's REST requests in the
 * browser, so no server state has to be broken. Each state is attached to the
 * test report as a screenshot.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { openWorkflowSidebar } from './utils';

const TITLE = 'E2E states post';
const OFFLINE =
	'Couldn’t reach the server. Check your connection and try again.';

/**
 * Matches the dashboard collection request, whatever the permalink style.
 *
 * @param {URL} url Request URL.
 * @return {boolean} True for `GET /sit-cwm/v1/posts`.
 */
const isCollection = ( url ) =>
	/sit-cwm(\/|%2F)v1(\/|%2F)posts($|\?|&|%3F)/i.test( url.href );

/**
 * Matches the sidebar's workflow request.
 *
 * @param {URL} url Request URL.
 * @return {boolean} True for `/sit-cwm/v1/posts/<id>/workflow`.
 */
const isWorkflow = ( url ) =>
	/sit-cwm(\/|%2F)v1(\/|%2F)posts(\/|%2F)\d+(\/|%2F)workflow/i.test(
		url.href
	);

/**
 * A REST error response.
 *
 * @param {number} status  HTTP status.
 * @param {string} code    Error code.
 * @param {string} message Message.
 * @return {Object} `route.fulfill()` options.
 */
const restError = ( status, code, message ) => ( {
	status,
	contentType: 'application/json',
	body: JSON.stringify( { code, message, data: { status } } ),
} );

/**
 * Attaches a screenshot of the page to the report.
 *
 * @param {Object} page Playwright page.
 * @param {string} name Attachment name.
 * @return {Promise<void>}
 */
const snap = async ( page, name ) =>
	test.info().attach( name, {
		body: await page.screenshot(),
		contentType: 'image/png',
	} );

test.describe( 'UI states', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'dashboard: empty, no matches, error with retry, offline', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// Nothing in the workflow: an explanation and the next action.
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );
		await expect(
			page.getByText( 'No content is in the workflow yet.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'Add new Post' } )
		).toBeVisible();
		await snap( page, 'dashboard-empty' );

		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: TITLE, status: 'draft' },
		} );

		// A search that matches nothing offers to clear it.
		await admin.visitAdminPage(
			'admin.php',
			'page=sit-cwm-dashboard&search=zz-no-such-post'
		);
		await expect(
			page.getByText(
				'No content matches the current search and filters.'
			)
		).toBeVisible();
		await page
			.locator( '.sit-cwm-dashboard-empty' )
			.getByRole( 'button', { name: 'Clear all filters' } )
			.click();
		await expect(
			page.locator( '.dataviews-view-table tbody tr' )
		).toContainText( [ TITLE ] );

		// A server error shows its message and a Retry that recovers.
		await page.route( isCollection, ( route ) =>
			route.fulfill(
				restError( 500, 'db_error', 'The database is unavailable.' )
			)
		);
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		const notice = page.locator( '.sit-cwm-dashboard-error' );

		await expect( notice ).toContainText( 'The database is unavailable.' );
		await expect(
			page.getByText( 'Content could not be loaded.' )
		).toBeVisible();
		await snap( page, 'dashboard-error' );

		await page.unroute( isCollection );
		await notice.getByRole( 'button', { name: 'Retry' } ).click();
		await expect( notice ).toBeHidden();
		await expect(
			page.locator( '.dataviews-view-table tbody tr' )
		).toContainText( [ TITLE ] );

		// No response at all is reported differently from a 4xx/5xx.
		await page.route( isCollection, ( route ) => route.abort( 'failed' ) );
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );
		await expect( notice ).toContainText( OFFLINE );
		await expect(
			notice.getByRole( 'button', { name: 'Retry' } )
		).toBeVisible();
		await snap( page, 'dashboard-offline' );
		await page.unroute( isCollection );
	} );

	test( 'sidebar: loading, error with retry, offline, forbidden', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: TITLE, status: 'draft' },
		} );
		const panel = page.locator( '.sit-cwm-sidebar' );
		// `Notice` also announces its text in a live region; match the notice.
		const notices = page.locator( '.components-notice' );

		// A slow first load shows a spinner and a label, never a blank panel.
		let release;
		const held = new Promise( ( resolve ) => ( release = resolve ) );

		await page.route( isWorkflow, async ( route ) => {
			await held;
			await route.continue();
		} );

		await openWorkflowSidebar( page, post.id, { waitForPanel: false } );

		// A spinner with an accessible label, never a blank panel.
		await expect(
			page.locator( '.sit-cwm-sidebar-loading' )
		).toBeVisible();
		await expect(
			page.locator( '.sit-cwm-sidebar-loading' )
		).toContainText( 'Loading workflow…' );
		await snap( page, 'sidebar-loading' );
		release();
		await expect( panel ).toBeVisible( { timeout: 20000 } );
		await page.unroute( isWorkflow );

		// A server error: the message and Retry, which recovers.
		await page.route( isWorkflow, ( route ) =>
			route.fulfill(
				restError( 500, 'db_error', 'The database is unavailable.' )
			)
		);
		await openWorkflowSidebar( page, post.id, { waitForPanel: false } );
		await expect(
			notices.getByText( 'The database is unavailable.' )
		).toBeVisible( { timeout: 20000 } );
		await snap( page, 'sidebar-error' );

		await page.unroute( isWorkflow );
		await page.getByRole( 'button', { name: 'Retry' } ).click();
		await expect( panel.locator( '.sit-cwm-status-text' ) ).toHaveText(
			'Draft'
		);

		// Offline is reported differently from a server error.
		await page.route( isWorkflow, ( route ) => route.abort( 'failed' ) );
		await openWorkflowSidebar( page, post.id, { waitForPanel: false } );
		await expect( notices.getByText( OFFLINE ) ).toBeVisible( {
			timeout: 20000,
		} );
		await expect(
			page.getByRole( 'button', { name: 'Retry' } )
		).toBeVisible();
		await snap( page, 'sidebar-offline' );
		await page.unroute( isWorkflow );

		// Forbidden: the whole panel is gated, so it explains why and offers
		// no controls.
		await page.route( isWorkflow, ( route ) =>
			route.fulfill(
				restError(
					403,
					'sit_cwm_forbidden',
					'Sorry, you are not allowed.'
				)
			)
		);
		await openWorkflowSidebar( page, post.id, { waitForPanel: false } );
		await expect(
			notices.getByText(
				'You don’t have permission to view the workflow of this content.'
			)
		).toBeVisible( { timeout: 20000 } );
		await expect(
			page.getByRole( 'button', { name: /^Move to / } )
		).toHaveCount( 0 );
		await snap( page, 'sidebar-forbidden' );
		await page.unroute( isWorkflow );
	} );

	test( 'dashboard at 782 px: grid layout, no horizontal scroll', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: TITLE, status: 'draft' },
		} );

		await page.setViewportSize( { width: 782, height: 900 } );
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		await expect( page.locator( '.dataviews-view-grid' ) ).toBeVisible();
		await expect( page.getByText( TITLE ) ).toBeVisible();

		const overflow = await page.evaluate(
			() =>
				document.documentElement.scrollWidth -
				document.documentElement.clientWidth
		);

		expect( overflow ).toBeLessThanOrEqual( 0 );
		await snap( page, 'dashboard-782px' );
	} );

	test( 'sidebar controls fit the editor sidebar', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: TITLE, status: 'draft' },
		} );

		await page.setViewportSize( { width: 782, height: 900 } );

		const panel = await openWorkflowSidebar( page, post.id );
		const overflow = await panel.evaluate(
			( el ) => el.scrollWidth - el.clientWidth
		);

		expect( overflow ).toBeLessThanOrEqual( 0 );
		await snap( page, 'sidebar-782px' );
	} );
} );
