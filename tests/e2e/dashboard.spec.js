/**
 * End-to-end: dashboard filtering, sorting and bulk actions (step 19.3).
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const DUE_DATES = {
	'E2E dash Alpha': '2030-03-01',
	'E2E dash Bravo': '2030-01-01',
	'E2E dash Charlie': '2030-02-01',
};

const DASHBOARD = 'admin.php';

/**
 * Dashboard query string for the probe posts.
 *
 * @param {string} extra Extra query arguments.
 * @return {string} Query string.
 */
const query = ( extra ) =>
	`page=sit-cwm-dashboard&search=${ encodeURIComponent(
		'E2E dash'
	) }&${ extra }`;

test.describe( 'Workflow dashboard', () => {
	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();

		for ( const [ title, dueDate ] of Object.entries( DUE_DATES ) ) {
			const post = await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/posts',
				data: { title, status: 'draft' },
			} );
			const path = `/sit-cwm/v1/posts/${ post.id }/workflow`;

			await requestUtils.rest( {
				method: 'POST',
				path,
				data: { from: 'draft', status: 'writing', due_date: dueDate },
			} );
			await requestUtils.rest( {
				method: 'POST',
				path,
				data: { from: 'writing', status: 'review' },
			} );
		}

		// Stays in Draft, so the status filter has something to exclude.
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: 'E2E dash Delta', status: 'draft' },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'filters by status and sorts by due date', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			DASHBOARD,
			query( 'status=review&orderby=due_date&order=asc' )
		);

		const rows = page.locator( '.dataviews-view-table tbody tr' );

		await expect( rows ).toHaveCount( 3 );
		await expect( rows.nth( 0 ) ).toContainText( 'E2E dash Bravo' );
		await expect( rows.nth( 1 ) ).toContainText( 'E2E dash Charlie' );
		await expect( rows.nth( 2 ) ).toContainText( 'E2E dash Alpha' );
		await expect( page.getByText( 'E2E dash Delta' ) ).toHaveCount( 0 );
	} );

	test( 'bulk-approves two posts and reports the result', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( DASHBOARD, query( 'status=review' ) );

		const rows = page.locator( '.dataviews-view-table tbody tr' );

		await expect( rows ).toHaveCount( 3 );

		await page.getByRole( 'checkbox', { name: 'E2E dash Alpha' } ).check();
		await page.getByRole( 'checkbox', { name: 'E2E dash Bravo' } ).check();
		await page.getByRole( 'button', { name: 'Change status' } ).click();

		const dialog = page.getByRole( 'dialog', {
			name: 'Change workflow status',
		} );

		await dialog
			.getByRole( 'combobox', { name: 'New status' } )
			.selectOption( 'approved' );
		await dialog.getByRole( 'button', { name: 'Apply' } ).click();

		// Every bulk status change is confirmed a second time, naming the count.
		await expect(
			dialog.getByText( 'Move 2 items to Approved?' )
		).toBeVisible();
		await dialog
			.getByRole( 'button', { name: 'Yes, update 2 items' } )
			.click();

		await expect( page.locator( '.sit-cwm-bulk-result' ) ).toContainText(
			'2 posts updated.'
		);

		// The approved posts leave the Review filter once the page refetches.
		await expect( rows ).toHaveCount( 1 );
		await expect( rows.nth( 0 ) ).toContainText( 'E2E dash Charlie' );

		await admin.visitAdminPage( DASHBOARD, query( 'status=approved' ) );
		await expect( rows ).toHaveCount( 2 );
	} );
} );
