/**
 * End-to-end: the governance report screen (1.2.0 phases 20–21, F12).
 *
 * The figures come from seeded activity rows, so the expected numbers are
 * fixed: every other spec deletes its posts, and deleting a post deletes its
 * activity.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { createWorkflowUser, daysAgo, loginAs, seedActivity } from './utils';

const EDITOR = 'e2e_report_editor';
const REPORT = 'page=sit-cwm-report';

/**
 * A KPI tile's value, found by its label.
 *
 * @param {Object} page  Playwright page.
 * @param {string} label Tile label.
 * @return {Object} Locator.
 */
const tile = ( page, label ) =>
	page
		.locator( '.sit-cwm-report__tile' )
		.filter( {
			has: page.locator( '.sit-cwm-report__tile-label', {
				hasText: new RegExp( `^${ label }$` ),
			} ),
		} )
		.locator( '.sit-cwm-report__tile-value' );

/**
 * The headline tile.
 *
 * @param {Object} page Playwright page.
 * @return {Object} Locator.
 */
const headline = ( page ) => page.locator( '.sit-cwm-report__headline' );

test.describe( 'Governance report', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await createWorkflowUser( requestUtils, EDITOR, 'editor' );

		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: 'E2E report post', status: 'draft' },
		} );
		const at = daysAgo( 5 );
		const published = ( workflowStatus, gateMode, createdAt = at ) => ( {
			post_id: post.id,
			action: 'post_published',
			old_value: 'draft',
			new_value: 'publish',
			created_at: createdAt,
			context: {
				workflow_status: workflowStatus,
				gate_mode: gateMode,
				ai_origin: 'none',
			},
		} );

		await seedActivity( requestUtils, [
			// Last 30 days: 4 publications, 3 of them approved first.
			published( 'approved', 'enforce' ),
			published( 'published', 'enforce' ),
			published( 'approved', 'off' ),
			published( 'review', 'log_only' ),
			{
				post_id: post.id,
				action: 'publish_gate_refused',
				created_at: at,
				context: { gate_mode: 'enforce', reason: 'not_approved' },
			},
			{
				post_id: post.id,
				action: 'publish_gate_bypassed',
				created_at: at,
				context: { gate_mode: 'log_only', reason: 'log_only' },
			},
			{
				post_id: post.id,
				action: 'publish_gate_bypassed',
				created_at: at,
				context: { gate_mode: 'enforce', reason: 'capability' },
			},
			// Only inside the default 90 days.
			published( 'approved', 'enforce', daysAgo( 45 ) ),
		] );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'auditor sees tiles from seeded data', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', REPORT );

		// Default period, 90 days: the older publication counts too.
		await expect(
			headline( page ).locator( '.sit-cwm-report__headline-value' )
		).toHaveText( '80%' );

		await page
			.getByRole( 'combobox', { name: 'Period' } )
			.selectOption( { label: 'Last 30 days' } );

		await expect(
			headline( page ).locator( '.sit-cwm-report__headline-value' )
		).toHaveText( '75%' );
		await expect( headline( page ) ).toContainText( '3 of 4 publications' );
		await expect( tile( page, 'Published without approval' ) ).toHaveText(
			'2'
		);
		await expect(
			tile( page, 'Published while the gate was off or logging only' )
		).toHaveText( '2' );
		await expect( tile( page, 'Publishing blocked' ) ).toHaveText( '1' );
		await expect( tile( page, 'Overdue reviews' ) ).toHaveText( '0' );
	} );

	test( 'period persists across reload', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', REPORT );
		await page
			.getByRole( 'combobox', { name: 'Period' } )
			.selectOption( { label: 'Last 30 days' } );
		await expect( page ).toHaveURL( /[?&]period=30\b/ );

		await page.reload();

		await expect(
			page.getByRole( 'combobox', { name: 'Period' } )
		).toHaveValue( '30' );
		await expect(
			headline( page ).locator( '.sit-cwm-report__headline-value' )
		).toHaveText( '75%' );
	} );

	test( 'editor cannot open the screen', async ( { page } ) => {
		await loginAs( page, EDITOR );
		await page.goto( '/wp-admin/admin.php?page=sit-cwm-dashboard' );

		await expect(
			page.locator( '#toplevel_page_sit-cwm-dashboard' )
		).toBeVisible();
		await expect(
			page.locator(
				'#toplevel_page_sit-cwm-dashboard a[href*="page=sit-cwm-report"]'
			)
		).toHaveCount( 0 );

		const response = await page.goto( `/wp-admin/admin.php?${ REPORT }` );

		expect( response.status() ).toBe( 403 );
		await expect( page.locator( '#sit-cwm-report' ) ).toHaveCount( 0 );
	} );

	test( 'export button present on the report', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', REPORT );

		await expect(
			page
				.locator( '.sit-cwm-page-actions' )
				.getByRole( 'button', { name: 'Export activity log (CSV)' } )
		).toBeVisible();
	} );
} );
