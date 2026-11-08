/**
 * End-to-end: the launch promotion of Pro inside Free (phase 49, roadmap §4.5).
 *
 * Runs on a site where Free is active and Pro is not, so `Promotions::stage()`
 * is `on_sale`. With Pro active, Pro's own suite asserts that none of this
 * shows (`sit_cwm_show_promotions`).
 *
 * The guideline-8 purchase check needs a staging site, a Freemius sandbox and
 * a person at the checkout, so it runs only with
 * `SIT_CWM_E2E_PURCHASE=manual` and pauses for the tester to buy Pro from the
 * Add-Ons page. Every other test runs on any local site.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	ADMIN,
	createWorkflowUser,
	freemiusOptin,
	loginAs,
	openWorkflowSidebar,
	setUserCap,
} from './utils';

const MANAGER = 'e2e_promo_manager';
const EDITOR = 'e2e_promo_editor';

/**
 * Hint keys, the screen that prints each one and, on Settings, the section
 * tab whose panel holds it (the other panels are hidden).
 *
 * @type {Array<[string, string, string, string?]>}
 */
const HINTS = [
	[ 'overdue', 'admin.php', 'page=sit-cwm-dashboard' ],
	[ 'export', 'admin.php', 'page=sit-cwm-report' ],
	[ 'workflows', 'admin.php', 'page=sit-cwm-settings', 'Workflow' ],
	[
		'notifications',
		'admin.php',
		'page=sit-cwm-settings',
		'Email notifications',
	],
];

/**
 * Anything that would mean Free installs a plugin by itself.
 *
 * @type {RegExp}
 */
const INSTALL_REQUEST =
	/update\.php\?[^#]*action=install-plugin|plugin-install\.php\?[^#]*tab=plugin-information[^#]*sapphireit-editorial-workflow-pro/;

/**
 * Locator of one hint.
 *
 * @param {Object} page Playwright page.
 * @param {string} key  Hint key.
 * @return {Object} Locator.
 */
const hint = ( page, key ) =>
	page.locator( `.sit-cwm-hint[data-sit-cwm-hint="${ key }"]` );

/**
 * Opens a wp-admin URL.
 *
 * @param {Object} page  Playwright page.
 * @param {string} path  Path under wp-admin.
 * @param {string} query Query string.
 * @return {Promise<void>}
 */
const visit = ( page, path, query ) =>
	page.goto( `/wp-admin/${ path }?${ query }` );

/**
 * Opens a Settings section tab, so its panel (and hint) is visible. Does
 * nothing off Settings.
 *
 * @param {Object}           page    Playwright page.
 * @param {string|undefined} section Tab name, or undefined.
 * @return {Promise<void>}
 */
async function openSection( page, section ) {
	if ( section ) {
		await page.getByRole( 'tab', { name: section, exact: true } ).click();
	}
}

test.describe( 'Promotion of Pro (phase 49)', () => {
	let editorId;
	let postId;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await freemiusOptin( requestUtils, 'skip' );

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

		editorId = (
			await createWorkflowUser( requestUtils, EDITOR, 'editor' )
		).id;

		postId = (
			await requestUtils.createPost( {
				title: 'E2E promotion post',
				status: 'draft',
				author: editorId,
			} )
		).id;
	} );

	test.afterAll( async ( { requestUtils } ) => {
		// Deleting the users drops their dismissed hints with them.
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'a manager sees each one-line hint on its own screen', async ( {
		page,
	} ) => {
		await loginAs( page, MANAGER );

		for ( const [ key, path, query, section ] of HINTS ) {
			await visit( page, path, query );

			await openSection( page, section );
			await expect( hint( page, key ), key ).toHaveCount( 1 );
			await expect(
				hint( page, key ).getByRole( 'link', {
					name: /Learn about Pro/,
				} )
			).toHaveAttribute( 'target', '_blank' );
		}

		// The overdue hint sits above the dashboard's tiles.
		await visit( page, 'admin.php', 'page=sit-cwm-dashboard' );
		const hintBox = await hint( page, 'overdue' ).boundingBox();
		const rootBox = await page
			.locator( '#sit-cwm-dashboard' )
			.boundingBox();
		expect( hintBox.y ).toBeLessThan( rootBox.y );
	} );

	test( 'a dismissed hint never comes back, and the others stay', async ( {
		page,
	} ) => {
		await loginAs( page, MANAGER );
		await visit( page, 'admin.php', 'page=sit-cwm-settings' );

		await hint( page, 'workflows' )
			.getByRole( 'link', { name: 'Dismiss' } )
			.click();

		await expect( page ).toHaveURL( /page=sit-cwm-settings/ );
		await expect( hint( page, 'workflows' ) ).toHaveCount( 0 );
		await expect( hint( page, 'notifications' ) ).toHaveCount( 1 );

		await page.reload();
		await expect( hint( page, 'workflows' ) ).toHaveCount( 0 );

		// The other user still sees it: dismissal is per user.
		await loginAs( page, ADMIN.username, ADMIN.password );
		await visit( page, 'admin.php', 'page=sit-cwm-settings' );
		await expect( hint( page, 'workflows' ) ).toHaveCount( 1 );
	} );

	test( 'a dismiss link with a bad nonce is refused', async ( { page } ) => {
		await loginAs( page, MANAGER );

		const response = await page.goto(
			'/wp-admin/admin-post.php?action=sit_cwm_dismiss_hint&hint=export&_wpnonce=bad'
		);

		expect( response.status() ).toBe( 403 );
		await visit( page, 'admin.php', 'page=sit-cwm-report' );
		await expect( hint( page, 'export' ) ).toHaveCount( 1 );
	} );

	test( 'the header has a plain Pro link and Add-Ons as the last tab', async ( {
		page,
	} ) => {
		await loginAs( page, ADMIN.username, ADMIN.password );
		await visit( page, 'admin.php', 'page=sit-cwm-dashboard' );

		const pro = page.locator(
			'.sit-cwm-shell__aside a.sit-cwm-shell__pro'
		);
		await expect( pro ).toHaveCount( 1 );
		await expect( pro ).toHaveText( /^Pro/ );
		await expect(
			page.locator( '.sit-cwm-shell__aside .button' )
		).toHaveCount( 0 );

		const tabs = page
			.getByRole( 'navigation', { name: 'Editorial Workflow' } )
			.getByRole( 'link' );
		await expect( tabs.last() ).toHaveText( 'Add-Ons' );
		await expect( tabs.last() ).toHaveAttribute(
			'href',
			/page=sit-cwm-settings-addons/
		);
	} );

	test( 'non-managers see no promotion at all', async ( { page } ) => {
		await loginAs( page, EDITOR );
		await visit( page, 'admin.php', 'page=sit-cwm-dashboard' );

		await expect( page.locator( '.sit-cwm-hint' ) ).toHaveCount( 0 );
		await expect( page.locator( '.sit-cwm-shell__pro' ) ).toHaveCount( 0 );
		await expect(
			page
				.getByRole( 'navigation', { name: 'Editorial Workflow' } )
				.getByRole( 'link', { name: 'Add-Ons' } )
		).toHaveCount( 0 );
	} );

	test( 'nothing in the block editor, no site-wide notice', async ( {
		page,
	} ) => {
		await loginAs( page, ADMIN.username, ADMIN.password );

		const panel = await openWorkflowSidebar( page, postId );
		await expect( panel ).toBeVisible();

		const editorHtml = await page.content();
		expect( editorHtml ).not.toContain( 'sit-cwm-hint' );
		expect( editorHtml ).not.toContain( 'Learn about Pro' );

		// Core screens get nothing: no notice, no dashboard widget.
		for ( const path of [ 'index.php', 'edit.php', 'plugins.php' ] ) {
			await page.goto( `/wp-admin/${ path }` );
			await expect( page.locator( '.sit-cwm-hint' ), path ).toHaveCount(
				0
			);
			await expect(
				page.locator( '.sit-cwm-shell__pro' ),
				path
			).toHaveCount( 0 );
		}
	} );

	test( 'the Plugins screen row has a plain Go Pro link', async ( {
		page,
	} ) => {
		await loginAs( page, ADMIN.username, ADMIN.password );
		await page.goto( '/wp-admin/plugins.php' );

		const row = page.locator(
			'tr[data-plugin="sapphireit-editorial-workflow/sapphireit-editorial-workflow.php"]'
		);
		await expect( row.locator( '.sit_cwm_go_pro a' ) ).toHaveText(
			/^Go Pro/
		);
	} );

	test( 'the Add-Ons page offers no install of Pro', async ( { page } ) => {
		await loginAs( page, ADMIN.username, ADMIN.password );

		const requests = [];
		page.on( 'request', ( request ) => requests.push( request.url() ) );

		await visit( page, 'admin.php', 'page=sit-cwm-settings-addons' );

		await expect(
			page.locator( 'a[href*="action=install-plugin"]' )
		).toHaveCount( 0 );
		expect(
			requests.filter( ( url ) => INSTALL_REQUEST.test( url ) )
		).toEqual( [] );
	} );

	test( 'guideline 8: buying Pro from Free never makes Free install it', async ( {
		page,
	} ) => {
		// eslint-disable-next-line playwright/no-skipped-test -- Deliberate: needs staging and a person at the checkout, see the file header.
		test.skip(
			'manual' !== process.env.SIT_CWM_E2E_PURCHASE,
			'Needs staging, a Freemius sandbox and a tester at the checkout: set SIT_CWM_E2E_PURCHASE=manual.'
		);
		test.setTimeout( 30 * 60 * 1000 );

		await loginAs( page, ADMIN.username, ADMIN.password );

		const requests = [];
		page.context().on( 'request', ( request ) =>
			requests.push( request.url() )
		);

		await visit( page, 'admin.php', 'page=sit-cwm-settings-addons' );

		// The tester buys Pro in the SDK's checkout, then resumes the run.
		// If the page offers a one-click install, stop the release and report
		// it (roadmap §4.5). Never patch freemius/.
		// eslint-disable-next-line playwright/no-page-pause -- Deliberate: a person completes the real checkout here.
		await page.pause();

		expect(
			requests.filter( ( url ) => INSTALL_REQUEST.test( url ) )
		).toEqual( [] );

		await page.goto( '/wp-admin/plugins.php' );
		await expect(
			page.locator(
				'tr[data-plugin^="sapphireit-editorial-workflow-pro/"]'
			)
		).toHaveCount( 0 );
	} );
} );
