/**
 * End-to-end: the publish gate in the block editor (roadmap §3.1 A1–A3).
 *
 * Needs the test-only mu-plugin `tests/e2e/fixtures/sit-cwm-e2e-helpers.php`
 * for `setSettings()`, `goLive()` and `rawPublish()`.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	apiAsBrowserUser,
	createWorkflowUser,
	getActivityActions,
	goLive,
	loginAs,
	moveTo,
	openWorkflowSidebar,
	postStatus,
	rawPublish,
	setSettings,
	setWorkflowStatus,
	walkWorkflow,
} from './utils';

const LOCKED = 'Publishing is locked until this content is approved.';
const LOG_ONLY_WARNING =
	'This content is not approved yet. Publishing it now will be recorded as a bypass of the review.';

/**
 * The editor's top bar.
 *
 * @param {Object} page Playwright page.
 * @return {Object} Locator.
 */
const topBar = ( page ) =>
	page.getByRole( 'region', { name: 'Editor top bar' } );

/**
 * A top-bar button by its exact name.
 *
 * @param {Object} page Playwright page.
 * @param {string} name Button name.
 * @return {Object} Locator.
 */
const topBarButton = ( page, name ) =>
	topBar( page ).getByRole( 'button', { name, exact: true } );

/**
 * Gate action slugs in an activity list.
 *
 * @param {string[]} actions Action slugs.
 * @return {string[]} Only the `publish_gate_*` ones.
 */
const gateActions = ( actions ) =>
	actions.filter( ( action ) => action.startsWith( 'publish_gate_' ) );

test.describe( 'Publish gate in the block editor', () => {
	const users = {};

	// Several logins and editor loads per test.
	test.slow();

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();

		users.author = await createWorkflowUser(
			requestUtils,
			'cwm_author',
			'author'
		);
		users.reviewer = await createWorkflowUser(
			requestUtils,
			'cwm_reviewer',
			'editor'
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
	} );

	/**
	 * A draft by the author, optionally walked along the workflow.
	 *
	 * @param {Object}   requestUtils Request utils fixture.
	 * @param {string}   title        Post title.
	 * @param {string[]} [path]       Workflow statuses to walk through.
	 * @return {Promise<Object>} Created post.
	 */
	async function authorPost( requestUtils, title, path = [] ) {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title, status: 'draft', author: users.author.id },
		} );

		await walkWorkflow( requestUtils, post.id, path );

		return post;
	}

	test( 'author sees Submit for Review, not Publish, below approved', async ( {
		editor,
		page,
		requestUtils,
	} ) => {
		const post = await authorPost( requestUtils, 'E2E gate locked' );

		await loginAs( page, 'cwm_author' );
		const panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Writing' );
		await moveTo( page, panel, 'Review' );

		await expect( topBarButton( page, 'Submit for Review' ) ).toBeVisible();
		await expect( topBarButton( page, 'Publish' ) ).toHaveCount( 0 );
		await expect( panel.locator( '.sit-cwm-gate-notice' ) ).toContainText(
			LOCKED
		);

		// An ordinary draft save is not a publish attempt.
		await editor.canvas
			.getByRole( 'textbox', { name: 'Add title' } )
			.fill( 'E2E gate locked, edited' );
		await editor.saveDraft();

		expect(
			gateActions( await getActivityActions( requestUtils, post.id ) )
		).toEqual( [] );
	} );

	test( 'approval in the sidebar enables Publish without reload', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await authorPost( requestUtils, 'E2E gate approval', [
			'writing',
			'review',
		] );

		await loginAs( page, 'cwm_reviewer' );
		const panel = await openWorkflowSidebar( page, post.id );

		await expect( panel.locator( '.sit-cwm-gate-notice' ) ).toContainText(
			LOCKED
		);

		await moveTo( page, panel, 'Approved', { confirm: true } );

		await expect( topBarButton( page, 'Publish' ) ).toBeVisible();
		await expect( panel.locator( '.sit-cwm-gate-notice' ) ).toHaveCount(
			0
		);
	} );

	test( 'author publishes an approved post', async ( {
		editor,
		page,
		requestUtils,
	} ) => {
		const post = await authorPost( requestUtils, 'E2E gate approved', [
			'writing',
			'review',
			'approved',
		] );

		await loginAs( page, 'cwm_author' );
		await openWorkflowSidebar( page, post.id );
		await editor.publishPost();

		expect( await postStatus( requestUtils, post.id ) ).toBe( 'publish' );

		const actions = await getActivityActions( requestUtils, post.id );

		expect( actions.at( -1 ) ).toBe( 'post_published' );
		expect( gateActions( actions ) ).toEqual( [] );

		// The timeline loads its entries once, so reopen the editor.
		const panel = await openWorkflowSidebar( page, post.id );

		await expect(
			panel.getByText(
				'cwm_author published this while it was at Approved'
			)
		).toBeVisible();
	} );

	test( 'admin can bypass and it is recorded', async ( {
		editor,
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: 'E2E gate bypass', status: 'draft' },
		} );

		await openWorkflowSidebar( page, post.id );
		await editor.publishPost();

		expect( await postStatus( requestUtils, post.id ) ).toBe( 'publish' );

		const actions = await getActivityActions( requestUtils, post.id );
		const bypassed = actions.indexOf( 'publish_gate_bypassed' );

		expect( bypassed ).toBeGreaterThanOrEqual( 0 );
		expect( actions.indexOf( 'post_published' ) ).toBeGreaterThan(
			bypassed
		);

		const panel = await openWorkflowSidebar( page, post.id );

		await expect(
			panel.getByText( /published this without approval/ )
		).toBeVisible();
	} );

	test( 'live post in re-review stays live', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await authorPost( requestUtils, 'E2E gate live', [
			'writing',
			'review',
			'approved',
		] );

		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ post.id }`,
			data: { status: 'publish' },
		} );
		await walkWorkflow(
			requestUtils,
			post.id,
			[ 'published', 'writing' ],
			'approved'
		);

		await loginAs( page, 'cwm_author' );
		await openWorkflowSidebar( page, post.id );
		await page.evaluate( () =>
			window.wp.data
				.dispatch( 'core/editor' )
				.editPost( { title: 'E2E gate live, edited' } )
		);

		// "Update" before WordPress 6.6, "Save" since.
		await topBar( page )
			.getByRole( 'button', { name: /^(Update|Save)$/ } )
			.click();
		await expect(
			page
				.getByTestId( 'snackbar' )
				.filter( { hasText: /updated|saved/i } )
				.first()
		).toBeVisible();

		expect( await postStatus( requestUtils, post.id ) ).toBe( 'publish' );
		expect(
			await getActivityActions( requestUtils, post.id )
		).not.toContain( 'publish_gate_refused' );
	} );

	test( 'scheduled post sent back is not published at go-live', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await authorPost( requestUtils, 'E2E gate scheduled', [
			'writing',
			'review',
			'approved',
		] );
		const tomorrow = new Date( Date.now() + 24 * 60 * 60 * 1000 )
			.toISOString()
			.slice( 0, 19 );

		// Scheduled by the author, who has no bypass, while it is approved.
		await loginAs( page, 'cwm_author' );

		const scheduled = await apiAsBrowserUser( page, {
			path: `/wp/v2/posts/${ post.id }`,
			method: 'POST',
			data: { status: 'future', date_gmt: tomorrow },
		} );

		expect( scheduled.status ).toBe( 200 );
		expect( scheduled.body.status ).toBe( 'future' );

		await setWorkflowStatus(
			requestUtils,
			post.id,
			'approved',
			'needs_changes'
		);
		await goLive( requestUtils, post.id );

		expect( await postStatus( requestUtils, post.id ) ).toBe( 'draft' );

		const actions = await getActivityActions( requestUtils, post.id );

		expect( actions ).toContain( 'publish_gate_refused' );
		expect( actions ).not.toContain( 'post_published' );
	} );

	test( 'direct wp_publish_post is reverted', async ( { requestUtils } ) => {
		const post = await authorPost( requestUtils, 'E2E gate backstop', [
			'writing',
			'review',
		] );

		await rawPublish( requestUtils, post.id );

		expect( await postStatus( requestUtils, post.id ) ).toBe( 'draft' );
		expect(
			( await getActivityActions( requestUtils, post.id ) ).slice( -3 )
		).toEqual( [
			'post_published',
			'publish_gate_refused',
			'post_unpublished',
		] );
	} );

	test( 'log_only allows and records', async ( {
		editor,
		page,
		requestUtils,
	} ) => {
		await setSettings( requestUtils, {
			publish_gate: { post: 'log_only' },
		} );

		const post = await authorPost( requestUtils, 'E2E gate log only', [
			'writing',
			'review',
		] );

		await loginAs( page, 'cwm_author' );
		const panel = await openWorkflowSidebar( page, post.id );

		await expect( topBarButton( page, 'Publish' ) ).toBeVisible();
		await expect( panel.locator( '.sit-cwm-gate-notice' ) ).toContainText(
			LOG_ONLY_WARNING
		);

		await editor.publishPost();

		expect( await postStatus( requestUtils, post.id ) ).toBe( 'publish' );
		expect( await getActivityActions( requestUtils, post.id ) ).toContain(
			'publish_gate_bypassed'
		);
	} );
} );
