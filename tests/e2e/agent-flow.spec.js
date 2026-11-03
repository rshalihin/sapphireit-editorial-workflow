/**
 * End-to-end: the agent recipe in `docs/AGENTS.md`, run as written
 * (1.2.0 roadmap §3.3 "Agent-facing documentation").
 *
 * The agent is an Author-role user that authenticates with an Application
 * Password over HTTP Basic auth, with no cookies and no nonce, exactly as the
 * recipe's `curl -u` calls do.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	agentRequest,
	createAppPassword,
	createWorkflowUser,
	getRawActivity,
	openWorkflowSidebar,
	setSettings,
	statusBadge,
} from './utils';

const AGENT = 'e2e_drafting_agent';

/**
 * Steps 1–3 of the recipe: create the draft declaring AI use, move it to
 * Writing, submit it for review. Asserts each documented response.
 *
 * @param {Object} credentials Agent credentials.
 * @param {string} title       Post title.
 * @return {Promise<number>} Post id.
 */
async function submitForReview( credentials, title ) {
	// Step 1: create the draft and declare AI use.
	const created = await agentRequest( credentials, 'POST', '/wp/v2/posts', {
		title,
		content: '<p>Draft text…</p>',
		status: 'draft',
		sit_cwm_ai_origin: 'ai_generated',
	} );

	expect( created.status, JSON.stringify( created.body ) ).toBe( 201 );
	expect( created.body ).toMatchObject( {
		status: 'draft',
		sit_cwm_ai_origin: 'ai_generated',
	} );

	const { id } = created.body;
	const route = `/sit-cwm/v1/posts/${ id }/workflow`;

	// Step 2: move it to Writing.
	const writing = await agentRequest( credentials, 'POST', route, {
		from: 'draft',
		status: 'writing',
	} );

	expect( writing.status, JSON.stringify( writing.body ) ).toBe( 200 );
	expect( writing.body.status ).toBe( 'writing' );

	// Step 3: submit it for review.
	const review = await agentRequest( credentials, 'POST', route, {
		from: 'writing',
		status: 'review',
	} );

	expect( review.status, JSON.stringify( review.body ) ).toBe( 200 );
	expect( review.body.status ).toBe( 'review' );
	expect( review.body.available_transitions ).toEqual( [] );

	return id;
}

test.describe( 'Agent recipe (docs/AGENTS.md)', () => {
	let agent;
	let credentials;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await setSettings( requestUtils, { publish_gate: {} } );

		agent = await createWorkflowUser( requestUtils, AGENT, 'author' );
		credentials = await createAppPassword( requestUtils, {
			id: agent.id,
			username: AGENT,
		} );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await setSettings( requestUtils, { publish_gate: {} } );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'agent submits a draft for human review', async ( {
		page,
		requestUtils,
	} ) => {
		const id = await submitForReview( credentials, 'E2E agent draft' );

		// Step 4: the agent polls and sees it waiting.
		const polled = await agentRequest(
			credentials,
			'GET',
			`/sit-cwm/v1/posts/${ id }/workflow`
		);

		expect( polled.body.status ).toBe( 'review' );

		// What gets recorded (recipe §6): the declaration via the post API,
		// then both status changes, all by the agent's own user.
		const rows = await getRawActivity( requestUtils, id );
		const declaration = rows.find(
			( row ) => row.action === 'ai_origin_changed'
		);

		expect( declaration ).toMatchObject( {
			user_id: agent.id,
			context: { via: 'post_api' },
		} );
		expect(
			rows
				.filter( ( row ) => row.action === 'status_changed' )
				.map( ( row ) => row.user_id )
		).toEqual( [ agent.id, agent.id ] );

		// A person opens it in the editor.
		const panel = await openWorkflowSidebar( page, id );

		await expect( statusBadge( panel ) ).toHaveText( 'Review' );
		await expect(
			panel.getByRole( 'combobox', { name: 'AI use' } )
		).toHaveValue( 'ai_generated' );

		const declared = panel.locator(
			'.sit-cwm-activity-item[data-action="ai_origin_changed"]'
		);

		await expect( declared ).toHaveCount( 1 );
		await expect( declared ).toContainText( 'AI-generated' );
		await expect( declared ).toContainText( AGENT );
		await expect( declared ).not.toContainText( 'post_api' );

		const moves = panel.locator(
			'.sit-cwm-activity-item[data-action="status_changed"]'
		);

		await expect( moves ).toHaveCount( 2 );

		for ( const move of await moves.all() ) {
			await expect( move ).toContainText( AGENT );
		}
	} );

	test( 'agent cannot approve its own submission', async () => {
		const id = await submitForReview( credentials, 'E2E agent approve' );
		const approve = await agentRequest(
			credentials,
			'POST',
			`/sit-cwm/v1/posts/${ id }/workflow`,
			{ from: 'review', status: 'approved' }
		);

		expect( approve.status ).toBe( 403 );
		expect( approve.body.code ).toBe( 'sit_cwm_forbidden' );
	} );

	test( 'agent cannot publish when the gate enforces', async ( {
		requestUtils,
	} ) => {
		await setSettings( requestUtils, {
			publish_gate: { post: 'enforce' },
		} );

		const id = await submitForReview( credentials, 'E2E agent publish' );
		const publish = await agentRequest(
			credentials,
			'POST',
			`/wp/v2/posts/${ id }`,
			{ status: 'publish' }
		);

		expect( publish.status ).toBe( 403 );
		expect( publish.body.code ).toBe( 'sit_cwm_publish_gated' );

		const post = await requestUtils.rest( {
			path: `/wp/v2/posts/${ id }`,
			params: { context: 'edit' },
		} );

		expect( post.status ).toBe( 'draft' );
	} );
} );
