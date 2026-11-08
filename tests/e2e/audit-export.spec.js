/**
 * End-to-end: CSV export of the activity log (1.2.0 phase 11, F8).
 */

/**
 * External dependencies
 */
import { readFile } from 'fs/promises';

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { createWorkflowUser, loginAs, walkWorkflow } from './utils';

const EDITOR = 'e2e_export_editor';
const BUTTON = 'Export activity log (CSV)';
const FORMULA = '=HYPERLINK("https://example.org","click")';

/**
 * The F8 header row, in `ActivityExporter::COLUMNS` order.
 *
 * @type {string}
 */
const HEADER = [
	'id',
	'created_at_utc',
	'post_id',
	'post_title',
	'post_type',
	'user_id',
	'user_login',
	'user_name',
	'action',
	'action_label',
	'old_value',
	'new_value',
	'message',
	'workflow_status',
	'gate_mode',
	'gate_layer',
	'gate_reason',
	'source',
	'ai_origin',
	'via',
	'attested',
	'attestation',
	'content_hash',
	'revision_id',
].join( ',' );

test.describe( 'Activity log CSV export', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await createWorkflowUser( requestUtils, EDITOR, 'editor' );

		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: 'E2E export post', status: 'draft' },
		} );

		await walkWorkflow( requestUtils, post.id, [ 'writing' ] );
		await requestUtils.rest( {
			method: 'POST',
			path: `/sit-cwm/v1/posts/${ post.id }/comments`,
			data: { message: FORMULA },
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'auditor downloads the CSV', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-dashboard' );

		const [ download ] = await Promise.all( [
			page.waitForEvent( 'download' ),
			page.getByRole( 'button', { name: BUTTON } ).click(),
		] );

		expect( download.suggestedFilename() ).toMatch(
			/^sit-cwm-activity-\d{8}-\d{6}\.csv$/
		);

		const csv = await readFile( await download.path(), 'utf8' );

		// Excel needs the BOM to read the file as UTF-8.
		expect( csv.charCodeAt( 0 ) ).toBe( 0xfeff );

		const lines = csv.slice( 1 ).split( /\r?\n/ );

		expect( lines[ 0 ] ).toBe( HEADER );

		// The comment opens as text: a leading apostrophe neutralises it.
		expect( csv ).toContain( `'=HYPERLINK(` );
		expect( csv ).not.toMatch( /(^|,)"?=HYPERLINK/m );
	} );

	test( 'non-auditors see no button and are refused', async ( { page } ) => {
		await loginAs( page, EDITOR );
		await page.goto( '/wp-admin/admin.php?page=sit-cwm-dashboard' );

		await expect( page.locator( '#sit-cwm-dashboard' ) ).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: BUTTON } )
		).toHaveCount( 0 );

		// A forged form post, with the editor's cookies but no valid nonce.
		const response = await page.request.post(
			'./wp-admin/admin-post.php?action=sit_cwm_export_activity',
			{
				form: {
					action: 'sit_cwm_export_activity',
					_sit_cwm_nonce: 'forged',
				},
				maxRedirects: 0,
			}
		);

		expect( response.status() ).toBe( 403 );
		expect( response.headers()[ 'content-type' ] || '' ).not.toContain(
			'text/csv'
		);
	} );
} );
