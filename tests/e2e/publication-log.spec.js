/**
 * End-to-end: publication logging with the gate off (roadmap §3.1 B).
 *
 * Needs the test-only mu-plugin for `setSettings()`.
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
	getActivity,
	getActivityActions,
	loginAs,
	moveTo,
	openWorkflowSidebar,
	setSettings,
	walkWorkflow,
} from './utils';

test.describe( 'Publication log', () => {
	const users = {};

	test.slow();

	test.beforeAll( async ( { requestUtils } ) => {
		await setSettings( requestUtils, { publish_gate: {} } );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();

		users.author = await createWorkflowUser(
			requestUtils,
			'cwm_author',
			'author'
		);
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'publication at Review is logged with actor, time and workflow status with the gate off', async ( {
		editor,
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: 'E2E published at review',
				status: 'draft',
				author: users.author.id,
			},
		} );

		await loginAs( page, 'cwm_author' );
		let panel = await openWorkflowSidebar( page, post.id );
		await moveTo( page, panel, 'Writing' );
		await moveTo( page, panel, 'Review' );

		// The gate is off, so Publish is offered below Approved.
		await editor.publishPost();

		panel = await openWorkflowSidebar( page, post.id );

		const entry = panel.locator(
			'.sit-cwm-activity-item[data-action="post_published"]'
		);

		await expect( entry ).toContainText(
			'cwm_author published this while it was at Review'
		);
		await expect( entry.locator( 'time' ) ).toHaveCount( 1 );

		const published = ( await getActivity( requestUtils, post.id ) ).find(
			( item ) => item.action === 'post_published'
		);

		expect( published.workflow_status ).toBe( 'review' );
		expect( published.user.name ).toBe( 'cwm_author' );
		expect( published.created_at ).not.toBe( '' );
	} );

	test( 'unpublishing is logged', async ( { requestUtils } ) => {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: 'E2E unpublished', status: 'draft' },
		} );

		await walkWorkflow( requestUtils, post.id, [ 'writing', 'review' ] );
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ post.id }`,
			data: { status: 'publish' },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/posts/${ post.id }`,
			data: { status: 'draft' },
		} );

		expect(
			( await getActivityActions( requestUtils, post.id ) ).slice( -2 )
		).toEqual( [ 'post_published', 'post_unpublished' ] );
	} );
} );
