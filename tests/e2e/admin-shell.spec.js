/**
 * End-to-end: the design A admin shell (header and tabs) and the Getting
 * Started page (1.2.0 phases 20a and 20b, F15).
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { createWorkflowUser, isProActive, loginAs } from './utils';

const EDITOR = 'e2e_shell_editor';

const ADMIN_TABS = [
	[ 'Dashboard', 'sit-cwm-dashboard' ],
	[ 'Governance report', 'sit-cwm-report' ],
	[ 'Settings', 'sit-cwm-settings' ],
	[ 'Getting Started', 'sit-cwm-getting-started' ],
];

/**
 * Hosts core itself may load on any admin screen (the admin bar avatar and
 * the emoji fallback). Anything else counts as a third-party request.
 *
 * @type {RegExp}
 */
const CORE_HOSTS = /(^|\.)gravatar\.com$|^s\.w\.org$/;

/**
 * The shell's tab navigation.
 *
 * @param {Object} page Playwright page.
 * @return {Object} Locator.
 */
const tabs = ( page ) =>
	page.getByRole( 'navigation', { name: 'Editorial Workflow' } );

test.describe( 'Admin shell and Getting Started', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await createWorkflowUser( requestUtils, EDITOR, 'editor' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllUsers();
	} );

	test( 'admin sees Dashboard, Governance report, Settings and Getting Started tabs in that order', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// eslint-disable-next-line playwright/no-skipped-test -- Deliberate: Pro adds tabs and drops Add-Ons, see the next test.
		test.skip(
			await isProActive( requestUtils ),
			'Describes a site without Pro.'
		);

		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		// Pro is on sale and not active here, so the SDK's Add-Ons page is
		// the last tab (phase 49); promotions.spec.js covers it.
		await expect( tabs( page ).getByRole( 'link' ) ).toHaveText( [
			...ADMIN_TABS.map( ( [ label ] ) => label ),
			'Add-Ons',
		] );
	} );

	test( 'with Pro active, Free keeps its tabs in order and Add-Ons goes', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		// eslint-disable-next-line playwright/no-skipped-test -- Deliberate: only a site with Pro has this state.
		test.skip(
			! ( await isProActive( requestUtils ) ),
			'Needs Pro active.'
		);

		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		const free = ADMIN_TABS.map( ( [ label ] ) => label );
		const labels = await tabs( page ).getByRole( 'link' ).allTextContents();

		expect( labels.filter( ( label ) => free.includes( label ) ) ).toEqual(
			free
		);
		expect( labels ).not.toContain( 'Add-Ons' );
	} );

	test( 'editor sees only Dashboard and Getting Started, and no setup steps', async ( {
		page,
	} ) => {
		await loginAs( page, EDITOR );
		await page.goto( '/wp-admin/admin.php?page=sit-cwm-getting-started' );

		await expect( tabs( page ).getByRole( 'link' ) ).toHaveText( [
			'Dashboard',
			'Getting Started',
		] );
		await expect(
			page.getByRole( 'heading', { name: 'Getting Started', level: 1 } )
		).toBeVisible();
		await expect(
			page.getByText( 'Set up your editorial workflow' )
		).toHaveCount( 0 );
	} );

	test( 'the current tab is marked and every tab opens its page', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		for ( const [ label, slug ] of ADMIN_TABS ) {
			await tabs( page )
				.getByRole( 'link', { name: label, exact: true } )
				.click();
			await expect( page ).toHaveURL( new RegExp( `page=${ slug }` ) );

			const current = tabs( page ).locator( '[aria-current="page"]' );

			await expect( current ).toHaveCount( 1 );
			await expect( current ).toHaveText( label );
		}
	} );

	test( 'Getting Started shows the setup steps to an admin', async ( {
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
		await expect( page.getByText( 'Choose content types' ) ).toBeVisible();
	} );

	test( 'Getting Started links open in a new tab and the page makes no third-party request', async ( {
		admin,
		page,
	} ) => {
		const site = new URL(
			process.env.WP_BASE_URL || 'http://localhost:8889'
		);
		const foreign = [];

		page.on( 'request', ( request ) => {
			const { protocol, hostname } = new URL( request.url() );

			if (
				[ 'http:', 'https:' ].includes( protocol ) &&
				hostname !== site.hostname &&
				! CORE_HOSTS.test( hostname )
			) {
				foreign.push( request.url() );
			}
		} );

		await admin.visitAdminPage(
			'admin.php',
			'page=sit-cwm-getting-started'
		);

		// Links that leave the site: the support forum, website, reviews.
		const external = page
			.locator( '.sit-cwm-gs' )
			.locator(
				`a[href^="http"]:not([href^="${ site.protocol }//${ site.host }"])`
			);

		expect( await external.count() ).toBeGreaterThan( 0 );

		for ( const link of await external.all() ) {
			const href = await link.getAttribute( 'href' );

			await expect( link, href ).toHaveAttribute( 'target', '_blank' );
			await expect( link, href ).toHaveAttribute( 'rel', /noopener/ );
		}

		// Everything the page loads by itself has loaded by now.
		await page.waitForLoadState( 'load' );
		await expect( page.locator( '.sit-cwm-shell' ) ).toBeVisible();
		expect( foreign ).toEqual( [] );
	} );

	test( 'the empty dashboard links to Getting Started', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.deleteAllPosts();
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		const callout = page.locator( '.sit-cwm-gs-callout' );

		await expect(
			callout.getByText( 'New to Editorial Workflow?' )
		).toBeVisible();

		await callout
			.getByRole( 'link', { name: 'Open Getting Started' } )
			.click();
		await expect( page ).toHaveURL( /page=sit-cwm-getting-started/ );
	} );

	test( 'admin notices appear below the header', async ( {
		admin,
		page,
	} ) => {
		for ( const [ , slug ] of ADMIN_TABS ) {
			await admin.visitAdminPage(
				'admin.php',
				`page=${ slug }&sit_cwm_e2e_notice=1`
			);

			const notice = page.locator( '.sit-cwm-e2e-notice' );

			await expect( notice, slug ).toBeVisible();

			// Core moves notices to just after `.wp-header-end`, which the
			// shell prints after the title row.
			await expect(
				page.locator( 'hr.wp-header-end ~ .sit-cwm-e2e-notice' ),
				slug
			).toHaveCount( 1 );

			const header = await page.locator( '.sit-cwm-shell' ).boundingBox();
			const box = await notice.boundingBox();

			expect( box.y, slug ).toBeGreaterThanOrEqual(
				header.y + header.height
			);
		}
	} );
} );
