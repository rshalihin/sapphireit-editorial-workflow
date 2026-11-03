/**
 * End-to-end: declared AI use (1.2.0 phases 12–15, F9).
 *
 * AI use is declared, never detected: these tests check that people can
 * declare it in both editors, that a per-type site default is stamped on new
 * content, that every declaration lands in the activity timeline, and that a
 * user without the workflow capability only reads it.
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
	loginAs,
	openWorkflowSidebar,
	setSettings,
	setUserCap,
} from './utils';

const AUTHOR = 'e2e_ai_author';
const READER = 'e2e_ai_reader';
const HELP =
	'Declare how this content was made. This is recorded in the activity log; it is not detected automatically.';

/**
 * Timeline entries of the AI-use declaration inside a panel.
 *
 * @param {Object} panel Panel locator.
 * @return {Object} Locator.
 */
const declarations = ( panel ) =>
	panel.locator( '.sit-cwm-activity-item[data-action="ai_origin_changed"]' );

test.describe( 'Declared AI use', () => {
	const users = {};

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await setSettings( requestUtils, { ai_origin_defaults: {} } );

		users.author = await createWorkflowUser(
			requestUtils,
			AUTHOR,
			'author'
		);
		users.reader = await createWorkflowUser(
			requestUtils,
			READER,
			'author'
		);
		await setUserCap(
			requestUtils,
			users.reader.id,
			'sit_cwm_change_workflow',
			false
		);
	} );

	test.afterEach( async ( { requestUtils } ) => {
		await setSettings( requestUtils, { ai_origin_defaults: {} } );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	test( 'author declares AI use in the sidebar', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: 'E2E AI declared',
				status: 'draft',
				author: users.author.id,
			},
		} );

		await loginAs( page, AUTHOR );
		const panel = await openWorkflowSidebar( page, post.id );
		const select = panel.getByRole( 'combobox', { name: 'AI use' } );

		await expect( select ).toHaveValue( '' );
		await expect( select.locator( 'option:checked' ) ).toHaveText(
			'Not declared'
		);
		await expect( panel.getByText( HELP ) ).toBeVisible();

		await select.selectOption( 'ai_generated' );

		await expect( declarations( panel ) ).toHaveCount( 1 );
		await expect( declarations( panel ) ).toContainText(
			'AI use declared'
		);
		await expect( declarations( panel ) ).toContainText( 'AI-generated' );

		// Once declared, "Not declared" is no longer offered.
		await expect( select ).toHaveValue( 'ai_generated' );
		await expect(
			select.locator( 'option', { hasText: 'Not declared' } )
		).toHaveCount( 0 );

		const saved = await requestUtils.rest( {
			path: `/wp/v2/posts/${ post.id }`,
			params: { context: 'edit' },
		} );

		expect( saved.sit_cwm_ai_origin ).toBe( 'ai_generated' );
	} );

	test( 'site default is stamped on new posts', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=sit-cwm-settings' );
		await page.getByRole( 'tab', { name: 'AI use default' } ).click();

		const section = page.locator( '#sit-cwm-settings-sit-cwm-ai-origin' );

		await section
			.getByRole( 'combobox', { name: 'Posts' } )
			.selectOption( 'ai_assisted' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.getByText( 'Settings saved.' ) ).toBeVisible();

		await admin.visitAdminPage( 'post-new.php' );
		await page.waitForFunction(
			() =>
				!! window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
		);
		const id = await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).getCurrentPostId()
		);

		const panel = await openWorkflowSidebar( page, id );

		await expect(
			panel.getByRole( 'combobox', { name: 'AI use' } )
		).toHaveValue( 'ai_assisted' );
		await expect( declarations( panel ) ).toHaveCount( 1 );
		await expect( declarations( panel ) ).toContainText( 'AI-assisted' );
	} );

	test( 'dashboard shows the AI use column', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: 'E2E AI column',
				status: 'draft',
				sit_cwm_ai_origin: 'human',
			},
		} );

		await admin.visitAdminPage(
			'admin.php',
			`page=sit-cwm-dashboard&search=${ encodeURIComponent(
				'E2E AI column'
			) }`
		);

		await expect(
			page.locator( '.dataviews-view-table thead' )
		).toContainText( 'AI use' );
		await expect(
			page.locator( '.dataviews-view-table tbody tr' ).first()
		).toContainText( 'Human-written' );
	} );

	test( 'subscriber-level reader cannot change it', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: 'E2E AI read only',
				status: 'draft',
				author: users.reader.id,
				sit_cwm_ai_origin: 'ai_assisted',
			},
		} );

		await loginAs( page, READER );
		const panel = await openWorkflowSidebar( page, post.id );
		const field = panel.locator( '.sit-cwm-ai-origin' );

		await expect( field ).toContainText( 'AI use' );
		await expect( field.locator( '.sit-cwm-ai-origin__value' ) ).toHaveText(
			'AI-assisted'
		);
		await expect(
			panel.getByRole( 'combobox', { name: 'AI use' } )
		).toHaveCount( 0 );
	} );

	test.describe( 'classic editor', () => {
		test.beforeAll( async ( { requestUtils } ) => {
			await requestUtils.activatePlugin( 'classic-editor' );
		} );

		test.afterAll( async ( { requestUtils } ) => {
			await requestUtils.deactivatePlugin( 'classic-editor' );
		} );

		test( 'classic editor box offers the same control', async ( {
			page,
			requestUtils,
		} ) => {
			const post = await requestUtils.rest( {
				method: 'POST',
				path: '/wp/v2/posts',
				data: {
					title: 'E2E AI classic',
					status: 'draft',
					author: users.author.id,
				},
			} );

			await loginAs( page, AUTHOR );
			await page.goto(
				`/wp-admin/post.php?post=${ post.id }&action=edit`
			);

			const panel = page
				.locator( '#sit-cwm-workflow' )
				.locator( '.sit-cwm-sidebar.sit-cwm-workflow-panel--classic' );

			await expect( panel ).toBeVisible( { timeout: 20000 } );

			const select = panel.getByRole( 'combobox', { name: 'AI use' } );

			await expect( select ).toHaveValue( '' );
			await select.selectOption( 'human' );
			await expect( declarations( panel ) ).toHaveCount( 1 );
			await expect( declarations( panel ) ).toContainText(
				'Human-written'
			);
		} );
	} );
} );
