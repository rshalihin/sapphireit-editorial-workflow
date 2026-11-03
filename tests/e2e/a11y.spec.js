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
 * Internal dependencies
 */
import {
	axeViolations as violations,
	createWorkflowUser,
	loginAs,
	openWorkflowSidebar,
	setSettings,
} from './utils';

const TITLE = 'E2E a11y post';
const EDITOR = 'e2e_a11y_editor';

/**
 * The settings tabs, in order (phase 20b).
 *
 * @type {string[]}
 */
const SETTINGS_SECTIONS = [
	'Workflow',
	'Publish gate',
	'Email notifications',
	'AI use default',
	'Review attestation',
	'Front-end disclosure',
	'Uninstall',
];

test.describe( 'Accessibility', () => {
	let post;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await createWorkflowUser( requestUtils, EDITOR, 'editor' );

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
		await setSettings( requestUtils, { publish_gate: {} } );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
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

	// 1.2.0 (phase 20b): the sections are vertical tabs, one panel at a time,
	// so each tab is opened and its panel scanned.
	test( 'settings screen, every section tab', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-settings' );

		const tabs = page.getByRole( 'tab' );

		await expect( tabs ).toHaveText( SETTINGS_SECTIONS );

		for ( const name of SETTINGS_SECTIONS ) {
			await page.getByRole( 'tab', { name } ).click();
			await expect(
				page.getByRole( 'tabpanel' ).getByRole( 'heading', { name } )
			).toBeVisible();
			expect( await violations( page, '.wrap form' ), name ).toEqual(
				[]
			);
		}
	} );

	test( 'Getting Started, as an admin and as an editor', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=sit-cwm-getting-started'
		);
		await expect(
			page.getByText( 'Set up your editorial workflow' )
		).toBeVisible();
		expect( await violations( page, '.sit-cwm-getting-started' ) ).toEqual(
			[]
		);

		await loginAs( page, EDITOR );
		await page.goto( '/wp-admin/admin.php?page=sit-cwm-getting-started' );
		await expect(
			page.getByRole( 'heading', { name: 'Getting Started', level: 1 } )
		).toBeVisible();
		expect( await violations( page, '.sit-cwm-getting-started' ) ).toEqual(
			[]
		);
	} );

	test( 'shell header at 360px wide', async ( { admin, page } ) => {
		await page.setViewportSize( { width: 360, height: 800 } );
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		await expect( page.locator( '.sit-cwm-shell' ) ).toBeVisible();
		expect( await violations( page, '.sit-cwm-shell' ) ).toEqual( [] );
	} );

	test( 'governance report screen', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-report' );

		await expect(
			page.locator( '.sit-cwm-report__headline' )
		).toBeVisible();
		expect( await violations( page, '.sit-cwm-report-wrap' ) ).toEqual(
			[]
		);
	} );

	test( 'editor sidebar with AI use and the attestation checkbox', async ( {
		page,
		requestUtils,
	} ) => {
		await setSettings( requestUtils, { require_attestation: true } );

		try {
			const panel = await openWorkflowSidebar( page, post.id );

			await expect(
				panel.getByRole( 'combobox', { name: 'AI use' } )
			).toBeVisible();
			await expect(
				panel.getByRole( 'checkbox', {
					name: 'I confirm I have reviewed this content and approve it for publication.',
				} )
			).toBeVisible();
			expect( await violations( page, '.sit-cwm-sidebar' ) ).toEqual(
				[]
			);
		} finally {
			await setSettings( requestUtils, { require_attestation: false } );
		}
	} );

	test( 'editor sidebar with the gate notice', async ( {
		page,
		requestUtils,
	} ) => {
		await setSettings( requestUtils, {
			publish_gate: { post: 'enforce' },
		} );

		// The administrator holds the bypass, so the notice is the bypass
		// warning; it is the same component as the locked notice.
		const panel = await openWorkflowSidebar( page, post.id );

		await expect( panel.locator( '.sit-cwm-gate-notice' ) ).toBeVisible();
		expect( await violations( page, '.sit-cwm-sidebar' ) ).toEqual( [] );
	} );
} );
