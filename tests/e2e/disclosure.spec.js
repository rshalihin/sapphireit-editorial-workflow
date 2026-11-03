/**
 * End-to-end: the opt-in front-end review disclosure, as a shortcode and a
 * block (1.2.0 phases 18–19, F11).
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { setSettings, walkWorkflow } from './utils';

const BLOCK = 'sit-cwm/review-disclosure';
const SHORTCODE = '[sit_cwm_review_disclosure]';
const AI_LINE =
	'This content was created with AI assistance and reviewed by a person.';

/**
 * Creates a post, optionally walks it to Approved, then publishes it.
 *
 * @param {Object}   requestUtils    Request utils fixture.
 * @param {Object}   options         Options.
 * @param {string}   options.title   Title.
 * @param {string}   options.content Content.
 * @param {string[]} options.path    Workflow statuses to walk before publishing.
 * @return {Promise<Object>} The published post (`id`, `link`).
 */
async function publishedPost( requestUtils, { title, content, path } ) {
	const post = await requestUtils.rest( {
		method: 'POST',
		path: '/wp/v2/posts',
		data: {
			title,
			content,
			status: 'draft',
			sit_cwm_ai_origin: 'ai_assisted',
		},
	} );

	await walkWorkflow( requestUtils, post.id, path );

	return requestUtils.rest( {
		method: 'POST',
		path: `/wp/v2/posts/${ post.id }`,
		data: { status: 'publish' },
	} );
}

/**
 * Whether the inserter offers the disclosure block in the open editor.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<boolean>} Offered.
 */
const inserterOffers = ( page ) =>
	page.evaluate(
		( name ) =>
			window.wp.data
				.select( 'core/block-editor' )
				.getInserterItems()
				.some( ( item ) => item.name === name ),
		BLOCK
	);

test.describe( 'Review disclosure', () => {
	let approver;

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await setSettings( requestUtils, {
			disclosure_enabled: false,
			publish_gate: {},
		} );
		approver = await requestUtils.rest( {
			path: '/wp/v2/users/me',
			params: { context: 'edit' },
		} );
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await setSettings( requestUtils, { disclosure_enabled: false } );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
	} );

	test( 'shortcode prints nothing while off', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await publishedPost( requestUtils, {
			title: 'E2E disclosure off',
			content: SHORTCODE,
			path: [ 'writing', 'review', 'approved' ],
		} );

		await page.goto( post.link );
		await expect(
			page.getByRole( 'heading', {
				name: 'E2E disclosure off',
				level: 1,
			} )
		).toBeVisible();
		await expect( page.locator( '.sit-cwm-disclosure' ) ).toHaveCount( 0 );
		await expect( page.locator( 'body' ) ).not.toContainText( SHORTCODE );
	} );

	test( 'shortcode and block render when on', async ( {
		page,
		requestUtils,
	} ) => {
		const shortcode = await publishedPost( requestUtils, {
			title: 'E2E disclosure shortcode',
			content: SHORTCODE,
			path: [ 'writing', 'review', 'approved' ],
		} );
		const block = await publishedPost( requestUtils, {
			title: 'E2E disclosure block',
			content: `<!-- wp:${ BLOCK } /-->`,
			path: [ 'writing', 'review', 'approved' ],
		} );

		await setSettings( requestUtils, { disclosure_enabled: true } );

		for ( const post of [ shortcode, block ] ) {
			await page.goto( post.link );

			const disclosure = page.locator( '.sit-cwm-disclosure' );

			await expect( disclosure, post.link ).toHaveCount( 1 );
			await expect(
				disclosure.locator( '.sit-cwm-disclosure__review' )
			).toHaveText(
				new RegExp( `^Reviewed by ${ approver.name } on .+\\.$` )
			);
			await expect(
				disclosure.locator( '.sit-cwm-disclosure__ai' )
			).toHaveText( AI_LINE );
		}
	} );

	test( 'block is insertable only when on', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await admin.createNewPost();
		expect( await inserterOffers( page ) ).toBe( false );

		await setSettings( requestUtils, { disclosure_enabled: true } );
		await admin.createNewPost();
		expect( await inserterOffers( page ) ).toBe( true );

		await editor.insertBlock( { name: BLOCK } );

		const placeholder = editor.canvas.locator( `[data-type="${ BLOCK }"]` );

		await expect( placeholder ).toContainText(
			'The review disclosure appears here once this content is approved and published.'
		);

		await editor.openDocumentSettingsSidebar();
		await page
			.getByRole( 'region', { name: 'Editor settings' } )
			.getByRole( 'tab', { name: 'Block' } )
			.click();

		for ( const label of [ 'Show reviewer and date', 'Show AI use' ] ) {
			await expect(
				page.getByRole( 'checkbox', { name: label } )
			).toBeChecked();
		}
	} );

	test( 'post published without approval shows no disclosure', async ( {
		page,
		requestUtils,
	} ) => {
		await setSettings( requestUtils, {
			disclosure_enabled: true,
			publish_gate: { post: 'off' },
		} );

		const post = await publishedPost( requestUtils, {
			title: 'E2E disclosure unapproved',
			content: SHORTCODE,
			path: [ 'writing', 'review' ],
		} );

		await page.goto( post.link );
		await expect(
			page.getByRole( 'heading', {
				name: 'E2E disclosure unapproved',
				level: 1,
			} )
		).toBeVisible();
		await expect( page.locator( '.sit-cwm-disclosure' ) ).toHaveCount( 0 );
	} );
} );
