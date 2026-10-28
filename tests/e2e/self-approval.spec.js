/**
 * End-to-end: "Prevent people from approving their own content" (E16).
 *
 * Needs the test-only mu-plugin for `setSettings()`. The fixture post is
 * authored by `cwm_reviewer`, an editor, who holds `sit_cwm_approve_content`.
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
	loginAs,
	moveTo,
	openWorkflowSidebar,
	setSettings,
	statusBadge,
	walkWorkflow,
} from './utils';

const NOTE =
	"You can't approve your own content. Another reviewer has to approve it.";

/**
 * A top-bar button by its exact name.
 *
 * @param {Object} page Playwright page.
 * @param {string} name Button name.
 * @return {Object} Locator.
 */
const topBarButton = ( page, name ) =>
	page
		.getByRole( 'region', { name: 'Editor top bar' } )
		.getByRole( 'button', { name, exact: true } );

test.describe( 'Prevent self-approval', () => {
	const users = {};

	test.slow();

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();

		users.reviewer = await createWorkflowUser(
			requestUtils,
			'cwm_reviewer',
			'editor'
		);
		users.second = await createWorkflowUser(
			requestUtils,
			'cwm_second_editor',
			'editor'
		);
	} );

	test.beforeEach( async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			prevent_self_approval: true,
			publish_gate: { post: 'enforce' },
		} );
		await requestUtils.deleteAllPosts();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			prevent_self_approval: false,
			publish_gate: {},
		} );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	/**
	 * A draft authored by `cwm_reviewer`, optionally walked along the workflow.
	 *
	 * @param {Object}   requestUtils Request utils fixture.
	 * @param {string}   title        Post title.
	 * @param {string[]} [path]       Workflow statuses to walk through.
	 * @return {Promise<Object>} Created post.
	 */
	async function ownPost( requestUtils, title, path = [] ) {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title, status: 'draft', author: users.reviewer.id },
		} );

		await walkWorkflow( requestUtils, post.id, path );

		return post;
	}

	test( 'author with approve rights cannot approve own post', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await ownPost( requestUtils, 'E2E own post' );

		await loginAs( page, 'cwm_reviewer' );
		const panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Writing' );
		await moveTo( page, panel, 'Review' );

		await expect(
			panel.getByRole( 'button', { name: 'Move to Approved' } )
		).toHaveCount( 0 );
		await expect( panel.getByText( NOTE ) ).toBeVisible();
		await expect( topBarButton( page, 'Publish' ) ).toHaveCount( 0 );
	} );

	test( 'another editor can approve it', async ( { page, requestUtils } ) => {
		const post = await ownPost( requestUtils, 'E2E approved by another', [
			'writing',
			'review',
		] );

		await loginAs( page, 'cwm_second_editor' );
		let panel = await openWorkflowSidebar( page, post.id );

		await expect( panel.getByText( NOTE ) ).toHaveCount( 0 );
		await moveTo( page, panel, 'Approved', { confirm: true } );

		await loginAs( page, 'cwm_reviewer' );
		panel = await openWorkflowSidebar( page, post.id );

		await expect( statusBadge( panel ) ).toHaveText( 'Approved' );
		await expect( topBarButton( page, 'Publish' ) ).toBeVisible();
	} );

	test( 'author is not offered as their own reviewer', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await ownPost( requestUtils, 'E2E own reviewer' );

		await loginAs( page, 'cwm_reviewer' );
		const panel = await openWorkflowSidebar( page, post.id );

		await panel
			.getByRole( 'combobox', { name: 'Reviewer' } )
			.fill( 'cwm_' );

		// The list has loaded once another candidate shows.
		await expect(
			page.getByRole( 'option', { name: 'cwm_second_editor' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'option', { name: 'cwm_reviewer', exact: true } )
		).toHaveCount( 0 );
	} );

	test( 'REST self-approval is refused', async ( { page, requestUtils } ) => {
		const post = await ownPost( requestUtils, 'E2E REST self-approval', [
			'writing',
			'review',
		] );

		await loginAs( page, 'cwm_reviewer' );

		const response = await apiAsBrowserUser( page, {
			path: `/sit-cwm/v1/posts/${ post.id }/workflow`,
			method: 'POST',
			data: { from: 'review', status: 'approved' },
		} );

		expect( response.status ).toBe( 403 );
		expect( response.body.code ).toBe( 'sit_cwm_self_approval' );

		const workflow = await requestUtils.rest( {
			path: `/sit-cwm/v1/posts/${ post.id }/workflow`,
		} );

		expect( workflow.status ).toBe( 'review' );
	} );

	test( 'setting off allows it', async ( { page, requestUtils } ) => {
		await setSettings( requestUtils, { prevent_self_approval: false } );

		const post = await ownPost( requestUtils, 'E2E self-approval allowed', [
			'writing',
			'review',
		] );

		await loginAs( page, 'cwm_reviewer' );
		const panel = await openWorkflowSidebar( page, post.id );

		await expect( panel.getByText( NOTE ) ).toHaveCount( 0 );
		await moveTo( page, panel, 'Approved', { confirm: true } );
	} );
} );
