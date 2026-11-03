/**
 * End-to-end: the probe add-on (`tests/fixtures/sit-cwm-probe`) in the
 * browser (1.2.0 roadmap §3.2 acceptance).
 *
 * The probe injects a `legal_review` status between Review and Approved and
 * vetoes Approved, with a reason, until its checklist flag is set. These
 * tests prove both reach the sidebar, the dashboard and the classic meta box
 * through the 1.2 seams alone.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	activateProbe,
	createWorkflowUser,
	deactivateProbe,
	loginAs,
	moveTo,
	openWorkflowSidebar,
	setProbeChecklist,
	statusBadge,
	walkWorkflow,
} from './utils';

const EDITOR = 'e2e_probe_editor';
const VETO = 'The legal checklist is not complete.';
const TITLE = 'E2E probe post';

/**
 * Creates a draft and walks it to a workflow status as the admin.
 *
 * @param {Object}   requestUtils Request utils fixture.
 * @param {string[]} path         Statuses to walk through from Draft.
 * @return {Promise<number>} Post id.
 */
async function postAt( requestUtils, path ) {
	const post = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/posts',
		data: { title: TITLE, status: 'draft' },
	} );

	await walkWorkflow( requestUtils, post.id, path );

	return post.id;
}

const TO_LEGAL = [ 'writing', 'review', 'legal_review' ];

test.describe( 'Probe add-on in the browser', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await activateProbe( requestUtils );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await createWorkflowUser( requestUtils, EDITOR, 'editor' );
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await deactivateProbe( requestUtils );
	} );

	test( 'injected status renders in the sidebar with its label and colour', async ( {
		page,
		requestUtils,
	} ) => {
		const id = await postAt( requestUtils, TO_LEGAL );

		await loginAs( page, EDITOR );
		const panel = await openWorkflowSidebar( page, id );

		await expect( statusBadge( panel ) ).toHaveText( 'Legal Review' );
		await expect(
			panel.locator( '.sit-cwm-status .sit-cwm-status-swatch' )
		).toHaveCSS( 'background-color', 'rgb(138, 43, 226)' );
	} );

	test( 'injected status renders on the dashboard and filters', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await postAt( requestUtils, TO_LEGAL );
		await postAt( requestUtils, [ 'writing' ] );

		await admin.visitAdminPage(
			'admin.php',
			`page=sit-cwm-dashboard&search=${ encodeURIComponent( TITLE ) }`
		);

		const rows = page.locator( '.dataviews-view-table tbody tr' );

		await expect( rows ).toHaveCount( 2 );
		await expect(
			rows.locator( '.sit-cwm-status-text', { hasText: 'Legal Review' } )
		).toHaveCount( 1 );

		// The status filter accepts the injected status and narrows the
		// table to it.
		await admin.visitAdminPage(
			'admin.php',
			`page=sit-cwm-dashboard&search=${ encodeURIComponent(
				TITLE
			) }&status=legal_review`
		);

		await expect( rows ).toHaveCount( 1 );
		await expect( rows.first() ).toContainText( 'Legal Review' );
	} );

	test( 'veto message reaches the sidebar verbatim', async ( {
		page,
		requestUtils,
	} ) => {
		const id = await postAt( requestUtils, TO_LEGAL );

		await loginAs( page, EDITOR );
		let panel = await openWorkflowSidebar( page, id );

		const blocked = panel.locator( '.sit-cwm-blocked__item' );

		await expect(
			blocked.getByRole( 'button', { name: 'Move to Approved' } )
		).toBeDisabled();
		await expect( blocked ).toContainText( VETO );

		await setProbeChecklist( requestUtils, id, true );
		await page.reload();
		panel = await openWorkflowSidebar( page, id );

		await expect( panel.locator( '.sit-cwm-blocked__item' ) ).toHaveCount(
			0
		);
		await expect(
			panel.getByRole( 'button', { name: 'Move to Approved' } )
		).toBeEnabled();
		await moveTo( page, panel, 'Approved', { confirm: true } );
	} );

	test( 'veto returned on click is shown verbatim', async ( {
		page,
		requestUtils,
	} ) => {
		const id = await postAt( requestUtils, TO_LEGAL );

		await setProbeChecklist( requestUtils, id, true );
		await loginAs( page, EDITOR );
		const panel = await openWorkflowSidebar( page, id );

		await expect(
			panel.getByRole( 'button', { name: 'Move to Approved' } )
		).toBeEnabled();

		// The checklist is reopened after the sidebar loaded, so only the
		// server's answer to the click can carry the veto.
		await setProbeChecklist( requestUtils, id, false );

		await panel.getByRole( 'button', { name: 'Move to Approved' } ).click();
		await page
			.getByRole( 'dialog', { name: 'Approve content?' } )
			.getByRole( 'button', { name: 'Approve', exact: true } )
			.click();

		await expect(
			page
				.locator( '.components-snackbar, .components-notice' )
				.filter( { hasText: VETO } )
				.first()
		).toBeVisible();
		await expect( statusBadge( panel ) ).toHaveText( 'Legal Review' );
	} );

	test( 'review → approved is no longer offered', async ( {
		page,
		requestUtils,
	} ) => {
		const id = await postAt( requestUtils, [ 'writing', 'review' ] );

		await loginAs( page, EDITOR );
		const panel = await openWorkflowSidebar( page, id );
		const offered = await panel
			.locator( '.sit-cwm-actions' )
			.getByRole( 'button' )
			.allInnerTexts();

		expect( offered.map( ( text ) => text.trim() ).sort() ).toEqual( [
			'Move to Legal Review',
			'Move to Needs Changes',
		] );
	} );

	test.describe( 'classic editor', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.activatePlugin( 'classic-editor' );
		} );

		test.afterAll( async ( { requestUtils } ) => {
			await requestUtils.deactivatePlugin( 'classic-editor' );
		} );

		test( 'classic editor shows the same veto', async ( {
			page,
			requestUtils,
		} ) => {
			const id = await postAt( requestUtils, TO_LEGAL );

			await loginAs( page, EDITOR );
			await page.goto( `/wp-admin/post.php?post=${ id }&action=edit` );

			const panel = page
				.locator( '#sit-cwm-workflow' )
				.locator( '.sit-cwm-sidebar.sit-cwm-workflow-panel--classic' );

			await expect( panel ).toBeVisible( { timeout: 20000 } );
			await expect( statusBadge( panel ) ).toHaveText( 'Legal Review' );

			const blocked = panel.locator( '.sit-cwm-blocked__item' );

			await expect(
				blocked.getByRole( 'button', { name: 'Move to Approved' } )
			).toBeDisabled();
			await expect( blocked ).toContainText( VETO );
		} );
	} );
} );
