/**
 * End-to-end: the Freemius SDK in Free (1.2.0 phase 21a, F18).
 *
 * Every other spec runs with the opt-in skipped (`bin/setup-e2e-site.php`
 * and the helper mu-plugin do that). These tests bring the opt-in back
 * through the SDK's reconnect URL, then skip it again when they finish.
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
	freemiusOptin,
	loginAs,
	setUserCap,
} from './utils';

const EDITOR = 'e2e_fs_editor';
const MANAGER = 'e2e_fs_manager';

const PLUGIN_FILE =
	'sapphireit-editorial-workflow/sapphireit-editorial-workflow.php';

/**
 * Freemius admin pages that must not exist in 1.2.0 (R10). The SDK names
 * them after its menu slug (Settings since F18's opt-in move), plus a suffix;
 * the Dashboard prefix is checked too.
 *
 * `contact` and `wp-support-forum` are not listed: for a free build the SDK
 * always registers hidden links to its hosted contact form and to this
 * plugin's WordPress.org support forum, with no setting to drop them. They
 * have no menu item (checked below) and open only for an admin who types the
 * URL (F18, known SDK behaviour).
 *
 * @type {string[]}
 */
const ABSENT_PAGES = [ 'sit-cwm-settings', 'sit-cwm-dashboard' ].flatMap(
	( prefix ) =>
		[ 'addons', 'affiliation' ].map( ( page ) => `${ prefix }-${ page }` )
);

/**
 * The SDK's pricing page, replaced by a link to the Dashboard (R10).
 *
 * @type {string}
 */
const PRICING_PAGE = 'sit-cwm-settings-pricing';

/**
 * Brings the opt-in back: keeps the helper mu-plugin from skipping it, then
 * opens the SDK's reconnect URL, which clears the earlier Skip.
 *
 * @param {Object} page         Playwright page (logged in as the admin).
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<void>}
 */
async function showOptin( page, requestUtils ) {
	const state = await freemiusOptin( requestUtils, 'show' );

	expect( state.registered, 'the test site must not be opted in' ).toBe(
		false
	);

	if ( state.anonymous ) {
		await page.goto( state.reconnect_url );
	}

	expect( ( await freemiusOptin( requestUtils ) ).anonymous ).toBe( false );
}

/**
 * Makes sure the plugin is active again and that no activation redirect is
 * left behind for the specs that follow.
 *
 * Activating over REST as an admin leaves the SDK's 60-second "just
 * activated" hint, which redirects the next admin page load. Loading one
 * admin page here uses it up.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<void>}
 */
async function restorePlugin( requestUtils ) {
	const plugins = await requestUtils.rest( { path: '/wp/v2/plugins' } );
	const ours = plugins.find(
		( plugin ) => `${ plugin.plugin }.php` === PLUGIN_FILE
	);

	if ( ours && ours.status !== 'active' ) {
		await requestUtils.rest( {
			method: 'PUT',
			path: `/wp/v2/plugins/${ ours.plugin }`,
			data: { status: 'active' },
		} );
	}

	await requestUtils.request.get( 'wp-admin/index.php', {
		maxRedirects: 0,
	} );
}

test.describe( 'Freemius opt-in', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
		await createWorkflowUser( requestUtils, EDITOR, 'editor' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await restorePlugin( requestUtils );
		await freemiusOptin( requestUtils, 'skip' );
		await requestUtils.deleteAllUsers();
	} );

	test( 'admin sees the opt-in on Settings and Skip lands on Getting Started', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await showOptin( page, requestUtils );

		// The Dashboard is never replaced, not even for an admin.
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );
		await expect( page.locator( '#sit-cwm-dashboard' ) ).toBeVisible();
		await expect( page.locator( '#fs_connect' ) ).toHaveCount( 0 );

		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-settings' );

		const optin = page.locator( '#fs_connect' );

		await expect( optin ).toBeVisible();
		await optin.locator( '#skip_activation' ).click();

		await expect( page ).toHaveURL( /page=sit-cwm-getting-started/ );
		await expect(
			page.getByText( 'Set up your editorial workflow' )
		).toBeVisible();

		await page
			.getByRole( 'navigation', { name: 'Editorial Workflow' } )
			.getByRole( 'link', { name: 'Dashboard', exact: true } )
			.click();
		await expect( page.locator( '#fs_connect' ) ).toHaveCount( 0 );
		await expect( page.locator( '#sit-cwm-dashboard' ) ).toBeVisible();
	} );

	test( 'editor never sees the opt-in', async ( { page, requestUtils } ) => {
		await showOptin( page, requestUtils );
		await loginAs( page, EDITOR );
		await page.goto( '/wp-admin/admin.php?page=sit-cwm-dashboard' );

		await expect( page.locator( '#sit-cwm-dashboard' ) ).toBeVisible();
		await expect( page.locator( '#fs_connect' ) ).toHaveCount( 0 );
		expect( new URL( page.url() ).searchParams.get( 'page' ) ).toBe(
			'sit-cwm-dashboard'
		);
	} );

	test( 'a manager without admin rights gets Settings, not the opt-in', async ( {
		page,
		requestUtils,
	} ) => {
		const manager = await createWorkflowUser(
			requestUtils,
			MANAGER,
			'editor'
		);
		await setUserCap(
			requestUtils,
			manager.id,
			'sit_cwm_manage_workflows',
			true
		);

		await showOptin( page, requestUtils );
		await loginAs( page, MANAGER );
		await page.goto( '/wp-admin/admin.php?page=sit-cwm-settings' );

		await expect( page.locator( '#fs_connect' ) ).toHaveCount( 0 );
		await expect(
			page.getByRole( 'button', { name: 'Save changes' } )
		).toBeVisible();
		expect( new URL( page.url() ).searchParams.get( 'page' ) ).toBe(
			'sit-cwm-settings'
		);
	} );

	test( 'no Contact, Support, Add-Ons or Pricing pages exist', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await freemiusOptin( requestUtils, 'skip' );
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		const submenu = page.locator(
			'#toplevel_page_sit-cwm-dashboard .wp-submenu a'
		);
		const labels = ( await submenu.allInnerTexts() ).map( ( text ) =>
			text.trim()
		);

		expect( labels.length ).toBeGreaterThan( 0 );

		for ( const absent of [
			/contact/i,
			/support/i,
			/add-?ons/i,
			/pricing/i,
			/upgrade/i,
			/affiliat/i,
		] ) {
			expect(
				labels.filter( ( label ) => absent.test( label ) )
			).toEqual( [] );
		}

		// The pricing page's URL leads to the Dashboard, not a pricing app.
		await page.goto( `/wp-admin/admin.php?page=${ PRICING_PAGE }` );
		await expect( page ).toHaveURL( /page=sit-cwm-dashboard(&|$)/ );
		await expect( page.locator( '#sit-cwm-dashboard' ) ).toBeVisible();

		for ( const slug of ABSENT_PAGES ) {
			await page.goto( `/wp-admin/admin.php?page=${ slug }` );
			await expect(
				page.getByText(
					'Sorry, you are not allowed to access this page.'
				),
				slug
			).toBeVisible();
		}
	} );

	test( 'bulk activation does not redirect', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await freemiusOptin( requestUtils, 'skip' );
		await requestUtils.deactivatePlugin( 'hello-dolly' );

		try {
			await requestUtils.deactivatePlugin(
				'sapphireit-editorial-workflow'
			);
			await admin.visitAdminPage( 'plugins.php' );

			// The probe fixture declares `Requires Plugins` on this plugin, so
			// core greys out its bulk checkbox on the test site. Without the
			// probe a site owner can tick it; the form still posts the same
			// `activate-selected` request.
			const ours = page.locator(
				`input[type="checkbox"][value="${ PLUGIN_FILE }"]`
			);

			await ours.evaluate( ( input ) => {
				input.disabled = false;
			} );
			await ours.check();
			await page
				.locator( 'input[type="checkbox"][value="hello.php"]' )
				.check();
			await page
				.locator( '#bulk-action-selector-top' )
				.selectOption( 'activate-selected' );
			await page.locator( '#doaction' ).click();

			await page.waitForLoadState();
			await expect( page ).toHaveURL( /plugins\.php/ );
			await expect(
				page.locator( `tr[data-plugin="${ PLUGIN_FILE }"]` )
			).toHaveClass( /\bactive\b/ );

			await admin.visitAdminPage( 'index.php' );
			await expect( page ).toHaveURL( /wp-admin\/(index\.php)?$/ );
		} finally {
			await restorePlugin( requestUtils );
			await requestUtils.deactivatePlugin( 'hello-dolly' );
			await freemiusOptin( requestUtils, 'skip' );
		}
	} );

	test( 'deactivation feedback can be skipped and deactivation completes', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await freemiusOptin( requestUtils, 'skip' );

		try {
			await admin.visitAdminPage( 'plugins.php' );

			const row = page.locator( `tr[data-plugin="${ PLUGIN_FILE }"]` );

			await row.getByRole( 'link', { name: /^Deactivate/ } ).click();

			const modal = page.locator( '.fs-modal-deactivation-feedback' );

			await expect( modal ).toBeVisible();
			// The SDK renders Skip & Deactivate as a link styled as a button.
			await modal.locator( '.button-deactivate' ).click();

			await expect( row ).toHaveClass( /\binactive\b/ );
		} finally {
			await restorePlugin( requestUtils );
			await freemiusOptin( requestUtils, 'skip' );
		}
	} );
} );
