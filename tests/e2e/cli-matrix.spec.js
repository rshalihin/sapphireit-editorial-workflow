/**
 * End-to-end: the publish gate under WP-CLI (roadmap §3.1 A1 "WP-CLI").
 *
 * Skipped unless SIT_CWM_E2E_CLI holds the command prefix for `wp` that
 * reaches the same site as the browser specs:
 *
 * - wp-env: `npx wp-env run tests-cli wp`
 * - a local site: `wp --path=/path/to/site`
 *
 * See DEVELOPMENT.md → "Publish gate E2E". Needs the test-only mu-plugin for
 * `setSettings()`.
 */

/**
 * External dependencies
 */
const { execSync } = require( 'child_process' );

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { getActivityActions, setSettings, walkWorkflow } from './utils';

const CLI = process.env.SIT_CWM_E2E_CLI;

/**
 * Runs a `wp` command and returns the last line it printed, which is the
 * value: wp-env prints its own progress lines before it.
 *
 * @param {string} args Arguments after `wp`.
 * @return {string} Last non-empty output line.
 */
function wp( args ) {
	const output = execSync( `${ CLI } ${ args }`, {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
	const lines = output
		.split( /\r?\n/ )
		.map( ( line ) => line.trim() )
		.filter( Boolean );

	return lines[ lines.length - 1 ] || '';
}

test.describe( 'Publish gate under WP-CLI', () => {
	// eslint-disable-next-line playwright/no-skipped-test -- Deliberate: needs a shell that reaches the site, see the file header.
	test.skip( ! CLI, 'Set SIT_CWM_E2E_CLI to the `wp` command prefix.' );

	test.beforeEach( async ( { requestUtils } ) => {
		await setSettings( requestUtils, {
			publish_gate: { post: 'enforce' },
		} );
		await requestUtils.deleteAllPosts();
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await setSettings( requestUtils, { publish_gate: {} } );
		await requestUtils.deleteAllPosts();
	} );

	/**
	 * A draft at Review.
	 *
	 * @param {Object} requestUtils Request utils fixture.
	 * @param {string} title        Post title.
	 * @return {Promise<Object>} Created post.
	 */
	async function postAtReview( requestUtils, title ) {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title, status: 'draft' },
		} );

		await walkWorkflow( requestUtils, post.id, [ 'writing', 'review' ] );

		return post;
	}

	test( 'CLI without a user is refused in enforce', async ( {
		requestUtils,
	} ) => {
		const post = await postAtReview( requestUtils, 'E2E CLI no user' );

		wp( `post update ${ post.id } --post_status=publish` );

		expect( wp( `post get ${ post.id } --field=post_status` ) ).toBe(
			'draft'
		);
	} );

	test( 'CLI as an administrator bypasses and is recorded', async ( {
		requestUtils,
	} ) => {
		const post = await postAtReview( requestUtils, 'E2E CLI admin' );

		wp( `post update ${ post.id } --post_status=publish --user=admin` );

		expect( wp( `post get ${ post.id } --field=post_status` ) ).toBe(
			'publish'
		);
		expect( await getActivityActions( requestUtils, post.id ) ).toContain(
			'publish_gate_bypassed'
		);
	} );

	test( 'CLI create as publish is refused', async ( { requestUtils } ) => {
		const id = Number(
			wp(
				'post create --post_title=CLI --post_status=publish --porcelain'
			)
		);

		expect( id ).toBeGreaterThan( 0 );
		expect( wp( `post get ${ id } --field=post_status` ) ).toBe( 'draft' );
		expect( await getActivityActions( requestUtils, id ) ).toContain(
			'publish_gate_refused'
		);
	} );
} );
