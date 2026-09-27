/**
 * End-to-end: automated accessibility checks of the plugin's own UI (step 22).
 *
 * axe runs against WCAG 2.0 / 2.1 A and AA rules, scoped to markup the plugin
 * renders, so issues in core admin chrome do not fail this spec. Automated
 * checks find roughly a third of real problems; the keyboard spec and a
 * screen-reader pass (DEVELOPMENT.md) cover the rest.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * External dependencies
 */
import AxeBuilder from '@axe-core/playwright';

/**
 * Internal dependencies
 */
import { openWorkflowSidebar } from './utils';

const TITLE = 'E2E a11y post';

/**
 * Violations of WCAG A/AA rules inside one element.
 *
 * @param {Object} page     Playwright page.
 * @param {string} selector CSS selector to scan.
 * @return {Promise<Object[]>} `{ id, impact, help, targets }` per violation.
 */
async function violations( page, selector ) {
	const { violations: found, passes } = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
		.analyze();

	// Proves the selector matched: an empty scope would pass vacuously.
	expect(
		passes.length,
		`axe checked nothing in ${ selector }`
	).toBeGreaterThan( 5 );

	return found.map( ( { id, impact, help, nodes } ) => ( {
		id,
		impact,
		help,
		targets: nodes.map( ( node ) => node.target.join( ' ' ) ),
	} ) );
}

test.describe( 'Accessibility', () => {
	let post;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();

		post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: TITLE, status: 'draft' },
		} );

		const path = `/sit-cwm/v1/posts/${ post.id }/workflow`;

		await requestUtils.rest( {
			method: 'POST',
			path,
			data: { from: 'draft', status: 'writing', due_date: '2020-01-15' },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path,
			data: { from: 'writing', status: 'review' },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'dashboard table and a row action modal', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		const row = page
			.locator( '.dataviews-view-table tbody tr' )
			.filter( { hasText: TITLE } );

		await expect( row ).toBeVisible();
		expect( await violations( page, '#sit-cwm-dashboard' ) ).toEqual( [] );

		await row.getByRole( 'button', { name: 'Actions' } ).click();
		await page.getByRole( 'menuitem', { name: 'Approve' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Approve content?' } );

		await expect( dialog ).toBeVisible();
		expect( await violations( page, '.components-modal__frame' ) ).toEqual(
			[]
		);
	} );

	test( 'editor sidebar and its confirmation dialog', async ( { page } ) => {
		const panel = await openWorkflowSidebar( page, post.id );

		expect( await violations( page, '.sit-cwm-sidebar' ) ).toEqual( [] );

		await panel.getByRole( 'button', { name: 'Move to Approved' } ).click();

		const dialog = page.getByRole( 'dialog', { name: 'Approve content?' } );

		await expect( dialog ).toBeVisible();
		expect( await violations( page, '.components-modal__frame' ) ).toEqual(
			[]
		);
	} );
} );
