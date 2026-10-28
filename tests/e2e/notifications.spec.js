/**
 * End-to-end: email notifications (roadmap §3.1 C).
 *
 * Needs the test-only mu-plugin: it captures every `wp_mail()` call instead
 * of sending it, and `getMails()` reads them back.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	allNotifications,
	clearMails,
	createWorkflowUser,
	getMails,
	loginAs,
	moveTo,
	openWorkflowSidebar,
	setSettings,
	setWorkflowStatus,
	walkWorkflow,
} from './utils';

/**
 * Email address `createWorkflowUser()` gives a username.
 *
 * @param {string} username Username.
 * @return {string} Email address.
 */
const emailOf = ( username ) => `${ username }@example.org`;

test.describe( 'Email notifications', () => {
	const users = {};
	let site;

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
		users.editor = await createWorkflowUser(
			requestUtils,
			'cwm_editor',
			'editor'
		);

		site = ( await requestUtils.rest( { path: '/wp/v2/settings' } ) ).title;
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			publish_gate: {},
			notifications: allNotifications( true ),
		} );
		await requestUtils.deleteAllPosts();
		await clearMails( requestUtils );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			notifications: allNotifications( false ),
		} );
		await clearMails( requestUtils );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	/**
	 * A draft by the author, walked along the workflow and optionally given a
	 * reviewer by the administrator. Mails the arrangement caused are cleared.
	 *
	 * @param {Object}   requestUtils       Request utils fixture.
	 * @param {string}   title              Post title.
	 * @param {Object}   [options]          Options.
	 * @param {string[]} [options.path]     Workflow statuses to walk through.
	 * @param {boolean}  [options.reviewer] Assign `cwm_reviewer`.
	 * @return {Promise<Object>} Created post.
	 */
	async function arrange(
		requestUtils,
		title,
		{ path = [], reviewer = false } = {}
	) {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title, status: 'draft', author: users.author.id },
		} );

		if ( reviewer ) {
			await requestUtils.rest( {
				method: 'POST',
				path: `/sit-cwm/v1/posts/${ post.id }/workflow`,
				data: { reviewer_id: users.reviewer.id },
			} );
		}

		await walkWorkflow( requestUtils, post.id, path );
		await clearMails( requestUtils );

		return post;
	}

	test( 'assigning a reviewer emails the reviewer once', async ( {
		page,
		requestUtils,
	} ) => {
		const title = 'E2E mail reviewer assigned';
		const post = await arrange( requestUtils, title, {
			path: [ 'writing' ],
		} );

		await loginAs( page, 'cwm_editor' );
		const panel = await openWorkflowSidebar( page, post.id );
		const reviewer = panel.getByRole( 'combobox', { name: 'Reviewer' } );

		await reviewer.fill( 'cwm_reviewer' );
		await page.getByRole( 'option', { name: 'cwm_reviewer' } ).click();
		await expect( reviewer ).toHaveValue( 'cwm_reviewer' );

		await expect.poll( () => getMails( requestUtils ) ).toHaveLength( 1 );

		const [ mail ] = await getMails( requestUtils );

		expect( mail.to ).toEqual( [ emailOf( 'cwm_reviewer' ) ] );
		expect( mail.subject ).toBe(
			`[${ site }] Review assigned: ${ title }`
		);
	} );

	test( 'submitting for review emails the assigned reviewer', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await arrange( requestUtils, 'E2E mail review requested', {
			path: [ 'writing' ],
			reviewer: true,
		} );

		await loginAs( page, 'cwm_author' );
		const panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Review' );

		await expect.poll( () => getMails( requestUtils ) ).toHaveLength( 1 );
		expect( ( await getMails( requestUtils ) )[ 0 ].to ).toEqual( [
			emailOf( 'cwm_reviewer' ),
		] );
	} );

	test( 'submitting with no reviewer emails nobody', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await arrange( requestUtils, 'E2E mail no reviewer', {
			path: [ 'writing' ],
		} );

		await loginAs( page, 'cwm_author' );
		const panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Review' );

		expect( await getMails( requestUtils ) ).toEqual( [] );
	} );

	test( 'approval emails the author', async ( { page, requestUtils } ) => {
		const post = await arrange( requestUtils, 'E2E mail approved', {
			path: [ 'writing', 'review' ],
		} );

		await loginAs( page, 'cwm_reviewer' );
		const panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Approved', { confirm: true } );

		await expect.poll( () => getMails( requestUtils ) ).toHaveLength( 1 );
		expect( ( await getMails( requestUtils ) )[ 0 ].to ).toEqual( [
			emailOf( 'cwm_author' ),
		] );
	} );

	test( 'comment by reviewer emails the author with the text', async ( {
		page,
		requestUtils,
	} ) => {
		const comment = 'Tighten the intro.';
		const post = await arrange( requestUtils, 'E2E mail comment', {
			path: [ 'writing', 'review' ],
		} );

		await loginAs( page, 'cwm_reviewer' );
		const panel = await openWorkflowSidebar( page, post.id );

		await panel
			.getByRole( 'textbox', { name: 'Add a workflow comment' } )
			.fill( comment );
		await panel.getByRole( 'button', { name: 'Add comment' } ).click();
		await expect( panel.getByText( comment ) ).toBeVisible();

		await expect.poll( () => getMails( requestUtils ) ).toHaveLength( 1 );

		const [ mail ] = await getMails( requestUtils );

		expect( mail.to ).toEqual( [ emailOf( 'cwm_author' ) ] );
		expect( mail.body ).toContain( comment );
	} );

	test( 'nobody is emailed about their own action', async ( {
		page,
		requestUtils,
	} ) => {
		const comment = 'Note to self.';
		const post = await arrange( requestUtils, 'E2E mail own comment', {
			path: [ 'writing' ],
		} );

		await loginAs( page, 'cwm_author' );
		const panel = await openWorkflowSidebar( page, post.id );

		await panel
			.getByRole( 'textbox', { name: 'Add a workflow comment' } )
			.fill( comment );
		await panel.getByRole( 'button', { name: 'Add comment' } ).click();
		await expect( panel.getByText( comment ) ).toBeVisible();

		expect( await getMails( requestUtils ) ).toEqual( [] );
	} );

	test( 'disabling an event stops its mail', async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			notifications: { approved: false },
		} );

		const post = await arrange( requestUtils, 'E2E mail disabled', {
			path: [ 'writing', 'review' ],
		} );

		await setWorkflowStatus( requestUtils, post.id, 'review', 'approved' );

		expect( await getMails( requestUtils ) ).toEqual( [] );
	} );

	test( 'double submit sends once', async ( { requestUtils } ) => {
		const post = await arrange( requestUtils, 'E2E mail double submit', {
			path: [ 'writing' ],
			reviewer: true,
		} );

		await setWorkflowStatus( requestUtils, post.id, 'writing', 'review' );

		// The second request carries the same, now stale, `from`.
		const conflict = await setWorkflowStatus(
			requestUtils,
			post.id,
			'writing',
			'review'
		).catch( ( error ) => error );

		expect( conflict.code ).toBe( 'sit_cwm_status_conflict' );
		expect( conflict.data.status ).toBe( 409 );
		expect( await getMails( requestUtils ) ).toHaveLength( 1 );
	} );
} );
