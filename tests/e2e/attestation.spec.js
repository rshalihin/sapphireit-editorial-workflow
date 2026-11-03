/**
 * End-to-end: review attestation (1.2.0 phases 16–17, F10).
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	createWorkflowUser,
	loginAs,
	moveTo,
	openWorkflowSidebar,
	setSettings,
	walkWorkflow,
} from './utils';

const EDITOR = 'e2e_attest_editor';
const STATEMENT =
	'I confirm I have reviewed this content and approve it for publication.';
const PREFIX = 'E2E attest';

/**
 * Creates a draft and walks it to Review as the admin.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {string} title        Post title.
 * @return {Promise<number>} Post id.
 */
async function postInReview( requestUtils, title ) {
	const post = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/posts',
		data: { title, status: 'draft' },
	} );

	await walkWorkflow( requestUtils, post.id, [ 'writing', 'review' ] );

	return post.id;
}

test.describe( 'Review attestation', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await createWorkflowUser( requestUtils, EDITOR, 'editor' );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await setSettings( requestUtils, { require_attestation: true } );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await setSettings( requestUtils, { require_attestation: false } );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'Approve is disabled until the statement is ticked', async ( {
		page,
		requestUtils,
	} ) => {
		const id = await postInReview( requestUtils, `${ PREFIX } sidebar` );

		await loginAs( page, EDITOR );
		const panel = await openWorkflowSidebar( page, id );
		const approve = panel.getByRole( 'button', {
			name: 'Move to Approved',
		} );

		await expect( approve ).toBeDisabled();

		await panel.getByRole( 'checkbox', { name: STATEMENT } ).check();
		await expect( approve ).toBeEnabled();

		await moveTo( page, panel, 'Approved', { confirm: true } );

		const approval = panel
			.locator( '.sit-cwm-activity-item[data-action="status_changed"]' )
			.filter( { hasText: 'Approved' } )
			.first();

		await expect( approval ).toContainText( 'Review confirmed' );
	} );

	test( 'bulk approve requires the checkbox', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await postInReview( requestUtils, `${ PREFIX } bulk A` );
		await postInReview( requestUtils, `${ PREFIX } bulk B` );

		await admin.visitAdminPage(
			'admin.php',
			`page=sit-cwm-dashboard&search=${ encodeURIComponent(
				`${ PREFIX } bulk`
			) }`
		);

		const rows = page.locator( '.dataviews-view-table tbody tr' );

		await expect( rows ).toHaveCount( 2 );
		await page
			.getByRole( 'checkbox', { name: `${ PREFIX } bulk A` } )
			.check();
		await page
			.getByRole( 'checkbox', { name: `${ PREFIX } bulk B` } )
			.check();
		await page.getByRole( 'button', { name: 'Change status' } ).click();

		const dialog = page.getByRole( 'dialog', {
			name: 'Change workflow status',
		} );

		await dialog
			.getByRole( 'combobox', { name: 'New status' } )
			.selectOption( 'approved' );

		const apply = dialog.getByRole( 'button', { name: 'Apply' } );

		await expect( apply ).toBeDisabled();
		await dialog.getByRole( 'checkbox', { name: STATEMENT } ).check();
		await expect( apply ).toBeEnabled();
		await apply.click();

		await dialog
			.getByRole( 'button', { name: 'Yes, update 2 items' } )
			.click();
		await expect( page.locator( '.sit-cwm-bulk-result' ) ).toContainText(
			'2 posts updated.'
		);

		await expect(
			rows.locator( '.sit-cwm-status-text', { hasText: 'Approved' } )
		).toHaveCount( 2 );
	} );

	test( 'with the setting off there is no checkbox', async ( {
		page,
		requestUtils,
	} ) => {
		await setSettings( requestUtils, { require_attestation: false } );
		const id = await postInReview( requestUtils, `${ PREFIX } off` );

		await loginAs( page, EDITOR );
		const panel = await openWorkflowSidebar( page, id );

		await expect(
			panel.getByRole( 'button', { name: 'Move to Approved' } )
		).toBeEnabled();
		await expect(
			panel.getByRole( 'checkbox', { name: STATEMENT } )
		).toHaveCount( 0 );
	} );

	test( 'settings screen toggles the requirement', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await setSettings( requestUtils, { require_attestation: false } );
		const id = await postInReview( requestUtils, `${ PREFIX } settings` );

		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-settings' );
		await page.getByRole( 'tab', { name: 'Review attestation' } ).click();
		await page
			.getByRole( 'checkbox', {
				name: 'Require approvers to confirm they reviewed the content',
			} )
			.check();
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();

		const panel = await openWorkflowSidebar( page, id );

		await expect(
			panel.getByRole( 'checkbox', { name: STATEMENT } )
		).toBeVisible();
	} );
} );
