/**
 * End-to-end: the workflow meta box in the classic editor (step 26).
 *
 * Needs the Classic Editor plugin installed but inactive on the target site
 * (`.wp-env.json` maps it into the tests environment). This spec activates it
 * for its own tests only, so every other spec keeps the block editor.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import {
	ADMIN,
	axeViolations,
	createWorkflowUser,
	loginAs,
	moveTo,
	statusBadge,
} from './utils';

const TITLE = 'E2E classic post';
const HINT =
	'Workflow changes are saved immediately. You don’t need to click Update.';

/**
 * The meta box.
 *
 * @param {Object} page Playwright page.
 * @return {Object} Locator.
 */
const metaBox = ( page ) => page.locator( '#sit-cwm-workflow' );

/**
 * Waits for the panel inside the meta box to load, and returns it.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<Object>} Locator of the loaded panel.
 */
async function loadedPanel( page ) {
	const panel = metaBox( page ).locator(
		'.sit-cwm-sidebar.sit-cwm-workflow-panel--classic'
	);

	// The panel replaces its spinner once `GET /workflow` answers, which on a
	// cold local server can take longer than the default 5 s.
	await expect( panel ).toBeVisible( { timeout: 20000 } );

	return panel;
}

/**
 * Timeline entries of one activity action inside the panel.
 *
 * @param {Object} panel  Panel locator.
 * @param {string} action Activity action, e.g. `comment_added`.
 * @return {Object} Locator.
 */
const entries = ( panel, action ) =>
	panel.locator( `.sit-cwm-activity-item[data-action="${ action }"]` );

/**
 * Marks the current document, so a later check can tell whether the browser
 * loaded a new one (which is what submitting `<form id="post">` does).
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
const markDocument = ( page ) =>
	page.evaluate( () => {
		window.sitCwmE2eMarker = true;
	} );

/**
 * Asserts the post form was not submitted since `markDocument()`: same
 * document, same URL, and no "Post updated" / "Draft saved" notice.
 *
 * @param {Object} page Playwright page.
 * @param {string} url  URL before the action.
 * @return {Promise<void>}
 */
async function expectNotSubmitted( page, url ) {
	expect(
		await page.evaluate( () => window.sitCwmE2eMarker === true ),
		'The post form was submitted and the page reloaded.'
	).toBe( true );
	expect( page.url() ).toBe( url );
	await expect( page.locator( '#message.updated' ) ).toHaveCount( 0 );
}

test.describe( 'Classic editor meta box', () => {
	const users = {};

	// Logins and full classic-editor page loads; the editor test walks the
	// whole box.
	test.slow();

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'classic-editor' );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();

		users.editor = await createWorkflowUser(
			requestUtils,
			'cwm_classic_editor',
			'editor'
		);
		users.reviewer = await createWorkflowUser(
			requestUtils,
			'cwm_classic_reviewer',
			'editor'
		);
		users.author = await createWorkflowUser(
			requestUtils,
			'cwm_classic_author',
			'author'
		);
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
		await requestUtils.deactivatePlugin( 'classic-editor' );
	} );

	test( 'an editor runs the workflow from post-new.php without submitting the post', async ( {
		page,
		requestUtils,
	} ) => {
		await loginAs( page, 'cwm_classic_editor' );
		await page.goto( '/wp-admin/post-new.php' );

		// The box is there, with the hint, on the auto-draft.
		await expect( metaBox( page ) ).toBeVisible();
		await expect( metaBox( page ) ).toContainText( HINT );
		await expect(
			page.locator( '.block-editor, .editor-header' )
		).toHaveCount( 0 );

		const panel = await loadedPanel( page );
		const postId = Number( await page.locator( '#post_ID' ).inputValue() );
		const workflowPath = `/sit-cwm/v1/posts/${ postId }/workflow`;
		const url = page.url();

		expect( postId ).toBeGreaterThan( 0 );
		expect( url ).toContain( 'post-new.php' );
		await expect( statusBadge( panel ) ).toHaveText( 'Draft' );

		await markDocument( page );

		// 1. Reviewer, picked from the combobox.
		const reviewer = panel.getByRole( 'combobox', { name: 'Reviewer' } );
		await reviewer.fill( 'cwm_classic_reviewer' );
		await page
			.getByRole( 'option', { name: 'cwm_classic_reviewer' } )
			.click();
		await expect( reviewer ).toHaveValue( 'cwm_classic_reviewer' );
		await expect( entries( panel, 'reviewer_assigned' ) ).toHaveCount( 1 );

		// Enter in the combobox with no match must not submit the post.
		await reviewer.fill( 'no-such-reviewer' );
		await reviewer.press( 'Enter' );
		await reviewer.press( 'Escape' );
		await expectNotSubmitted( page, url );

		// 2. Due date: the 15th of next month, picked with Enter in the
		// calendar (keyboard path), which must not submit the post either.
		await panel.getByRole( 'button', { name: /^Change due date/ } ).click();
		const picker = page.locator( '.sit-cwm-due-date-picker' );
		await picker.getByRole( 'button', { name: 'View next month' } ).click();
		await picker.locator( 'button', { hasText: /^15$/ } ).press( 'Enter' );

		await expect( entries( panel, 'due_date_set' ) ).toHaveCount( 1 );
		await expectNotSubmitted( page, url );

		const dueDate = await page.evaluate( () => {
			const next = new Date();
			next.setDate( 1 );
			next.setMonth( next.getMonth() + 1 );
			return `${ next.getFullYear() }-${ String(
				next.getMonth() + 1
			).padStart( 2, '0' ) }-15`;
		} );

		// 3. Draft → Writing → Review, each with a snackbar.
		const snackbars = page.locator( '.sit-cwm-snackbars--classic' );

		await moveTo( page, panel, 'Writing' );
		await expect( snackbars ).toContainText(
			'Workflow status changed to Writing.'
		);
		await moveTo( page, panel, 'Review' );
		await expect( snackbars ).toContainText(
			'Workflow status changed to Review.'
		);
		await expect( entries( panel, 'status_changed' ) ).toHaveCount( 2 );

		// 4. Comments, with the button and with Ctrl+Enter.
		const comment = panel.getByRole( 'textbox', {
			name: 'Add a workflow comment',
		} );

		// Wait on the timeline, not `getByText`: React mirrors a controlled
		// textarea's value into its text, so that would match the field.
		const comments = entries( panel, 'comment_added' );

		await comment.fill( 'Added with the button.' );
		await panel.getByRole( 'button', { name: 'Add comment' } ).click();
		await expect( comments ).toHaveCount( 1 );
		await expect( comments.first() ).toContainText(
			'Added with the button.'
		);
		await expect( comment ).toHaveValue( '' );

		await comment.fill( 'Added with Ctrl+Enter.' );
		await comment.press( 'Control+Enter' );
		await expect( comments ).toHaveCount( 2 );
		await expect( comments.first() ).toContainText(
			'Added with Ctrl+Enter.'
		);
		await expect( comment ).toHaveValue( '' );

		// Regression guard: none of the above submitted `<form id="post">`.
		await expectNotSubmitted( page, url );

		// The server holds every change, and the post itself is untouched.
		let workflow = await requestUtils.rest( { path: workflowPath } );
		expect( workflow.status ).toBe( 'review' );
		expect( workflow.reviewer.id ).toBe( users.reviewer.id );
		expect( workflow.due_date ).toBe( dueDate );
		expect( workflow.post_status ).toBe( 'auto-draft' );

		// 5. Save Draft: the post is saved, the workflow state is unchanged.
		await page.locator( '#title' ).fill( TITLE );
		await page.locator( '#save-post' ).click();
		await page.waitForURL( /post\.php\?post=\d+&action=edit/ );
		await expect( page.locator( '#message.updated' ) ).toBeVisible();
		expect( Number( await page.locator( '#post_ID' ).inputValue() ) ).toBe(
			postId
		);

		const reloaded = await loadedPanel( page );
		await expect( statusBadge( reloaded ) ).toHaveText( 'Review' );

		workflow = await requestUtils.rest( { path: workflowPath } );
		expect( workflow.status ).toBe( 'review' );
		expect( workflow.reviewer.id ).toBe( users.reviewer.id );
		expect( workflow.due_date ).toBe( dueDate );
		expect( workflow.post_status ).toBe( 'draft' );

		// 6. "Update" (Save Draft again) after another workflow change leaves
		// the workflow status alone.
		await moveTo( page, reloaded, 'Needs Changes', { confirm: true } );
		await page.locator( '#save-post' ).click();
		await page.waitForURL( /post\.php\?post=\d+&action=edit/ );
		await expect( page.locator( '#message.updated' ) ).toBeVisible();
		await expect( statusBadge( await loadedPanel( page ) ) ).toHaveText(
			'Needs Changes'
		);

		workflow = await requestUtils.rest( { path: workflowPath } );
		expect( workflow.status ).toBe( 'needs_changes' );
		expect( workflow.post_status ).toBe( 'draft' );
	} );

	test( 'an author gets no box on another user’s post', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: `${ TITLE } (editor’s)`,
				status: 'draft',
				author: users.editor.id,
			},
		} );

		await loginAs( page, 'cwm_classic_author' );
		await page.goto( `/wp-admin/post.php?post=${ post.id }&action=edit` );

		await expect( page.locator( 'body' ) ).toContainText(
			'Sorry, you are not allowed to edit this item.'
		);
		await expect( metaBox( page ) ).toHaveCount( 0 );
	} );

	test( 'the box has no WCAG A/AA violations', async ( {
		page,
		requestUtils,
	} ) => {
		const post = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: { title: `${ TITLE } (a11y)`, status: 'draft' },
		} );

		const path = `/sit-cwm/v1/posts/${ post.id }/workflow`;

		await requestUtils.rest( {
			method: 'POST',
			path,
			data: { from: 'draft', status: 'writing', due_date: '2020-01-15' },
		} );
		await requestUtils.rest( {
			method: 'POST',
			path,
			data: { from: 'writing', status: 'review' },
		} );

		await loginAs( page, ADMIN.username, ADMIN.password );
		await page.goto( `/wp-admin/post.php?post=${ post.id }&action=edit` );

		const panel = await loadedPanel( page );

		// Settled: timeline loaded, so axe sees real entries, not skeletons.
		await expect( panel.locator( '.sit-cwm-activity' ) ).toHaveAttribute(
			'aria-busy',
			'false'
		);

		expect( await axeViolations( page, '#sit-cwm-workflow' ) ).toEqual(
			[]
		);
	} );
} );
