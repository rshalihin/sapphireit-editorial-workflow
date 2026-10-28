/**
 * End-to-end: the publish gate in the classic editor, Quick Edit and Bulk
 * Edit (roadmap §3.1 A1).
 *
 * Needs the Classic Editor plugin installed but inactive (`.wp-env.json` maps
 * it in) and the test-only mu-plugin. This spec activates Classic Editor for
 * its own tests only. Quick Edit and Bulk Edit run on `edit.php`, which the
 * Classic Editor plugin does not change.
 *
 * Every write here is made by an author: administrators hold the bypass
 * capability, so their publishes would go through.
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
	getActivityActions,
	getRawActivity,
	loginAs,
	postStatus,
	setSettings,
	walkWorkflow,
} from './utils';

const LOCKED = 'Publishing is locked until this content is approved.';
const REFUSED =
	'was not published: it has to be approved in the editorial workflow first. It was saved as Draft.';

/**
 * The workflow meta box.
 *
 * @param {Object} page Playwright page.
 * @return {Object} Locator.
 */
const metaBox = ( page ) => page.locator( '#sit-cwm-workflow' );

/**
 * A post's row on `edit.php`.
 *
 * @param {Object} page Playwright page.
 * @param {number} id   Post id.
 * @return {Object} Locator.
 */
const row = ( page, id ) => page.locator( `#post-${ id }` );

test.describe( 'Publish gate in the classic editor', () => {
	const users = {};

	test.slow();

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'classic-editor' );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();

		users.author = await createWorkflowUser(
			requestUtils,
			'cwm_classic_gate_author',
			'author'
		);
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			publish_gate: { post: 'enforce' },
		} );
		await requestUtils.deleteAllPosts();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setSettings( requestUtils, { publish_gate: {} } );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await requestUtils.deactivatePlugin( 'classic-editor' );
	} );

	/**
	 * A draft by the author at Review.
	 *
	 * @param {Object} requestUtils Request utils fixture.
	 * @param {string} title        Post title.
	 * @return {Promise<Object>} Created post.
	 */
	async function postAtReview( requestUtils, title ) {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title, status: 'draft', author: users.author.id },
		} );

		await walkWorkflow( requestUtils, post.id, [ 'writing', 'review' ] );

		return post;
	}

	test( 'classic Publish below approved saves as draft with a notice', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await postAtReview( requestUtils, 'E2E classic gate' );

		await loginAs( page, 'cwm_classic_gate_author' );
		await page.goto( `/wp-admin/post.php?post=${ post.id }&action=edit` );
		await page.locator( '#publish' ).click();
		await page.waitForLoadState();

		await expect(
			page.locator( '.notice.sit-cwm-gate-notice' )
		).toContainText( REFUSED );
		expect( await postStatus( requestUtils, post.id ) ).toBe( 'draft' );
	} );

	test( 'Quick Edit to Published below approved is refused', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await postAtReview( requestUtils, 'E2E quick edit gate' );

		await loginAs( page, 'cwm_classic_gate_author' );
		await page.goto( '/wp-admin/edit.php' );

		await row( page, post.id ).hover();
		await row( page, post.id )
			.getByRole( 'button', { name: /Quick edit/i } )
			.click();

		// The open Quick Edit row; edit.php also holds hidden templates.
		const form = page.locator( `#edit-${ post.id }` );

		await form
			.locator( 'select[name="_status"]' )
			.selectOption( 'publish' );
		await form.getByRole( 'button', { name: 'Update' } ).click();
		await expect( form ).toBeHidden();

		await expect( row( page, post.id ) ).toContainText( 'Draft' );
		expect( await postStatus( requestUtils, post.id ) ).toBe( 'draft' );

		const refused = (
			await getRawActivity( requestUtils, post.id )
		).filter( ( entry ) => entry.action === 'publish_gate_refused' );

		expect( refused ).toHaveLength( 1 );
		expect( refused[ 0 ].context.layer ).toBe( 'save' );
	} );

	test( 'Bulk Edit to Published below approved is refused for every post', async ( {
		page,
		requestUtils,
	} ) => {
		const first = await postAtReview( requestUtils, 'E2E bulk gate one' );
		const second = await postAtReview( requestUtils, 'E2E bulk gate two' );

		await loginAs( page, 'cwm_classic_gate_author' );
		await page.goto( '/wp-admin/edit.php' );

		await page.locator( `#cb-select-${ first.id }` ).check();
		await page.locator( `#cb-select-${ second.id }` ).check();
		await page
			.locator( '#bulk-action-selector-top' )
			.selectOption( 'edit' );
		await page.locator( '#doaction' ).click();

		const form = page.locator( '#bulk-edit' );

		await form
			.locator( 'select[name="_status"]' )
			.selectOption( 'publish' );
		await form.locator( '#bulk_edit' ).click();
		await page.waitForLoadState();

		for ( const post of [ first, second ] ) {
			expect( await postStatus( requestUtils, post.id ) ).toBe( 'draft' );
			expect(
				await getActivityActions( requestUtils, post.id )
			).toContain( 'publish_gate_refused' );
		}
	} );

	test( 'classic meta box shows the gate notice', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await postAtReview( requestUtils, 'E2E classic notice' );

		await loginAs( page, 'cwm_classic_gate_author' );
		await page.goto( `/wp-admin/post.php?post=${ post.id }&action=edit` );

		await expect(
			metaBox( page ).locator( '.sit-cwm-sidebar' )
		).toBeVisible( { timeout: 20000 } );
		await expect( metaBox( page ) ).toContainText( LOCKED );
	} );
} );
