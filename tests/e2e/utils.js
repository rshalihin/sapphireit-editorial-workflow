/**
 * Helpers shared by the end-to-end specs.
 */

/**
 * WordPress dependencies
 */
import { expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * Password of every user the specs create.
 *
 * @type {string}
 */
export const PASSWORD = 'e2e-Workflow-pass-1!';

/**
 * wp-env's default administrator.
 *
 * @type {{username: string, password: string}}
 */
export const ADMIN = { username: 'admin', password: 'password' };

/**
 * Creates a user with one role.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {string} username     Username (also the display name).
 * @param {string} role         Role slug.
 * @return {Promise<Object>} Created user `{ id, name, email }`.
 */
export function createWorkflowUser( requestUtils, username, role ) {
	return requestUtils.createUser( {
		username,
		email: `${ username }@example.org`,
		password: PASSWORD,
		roles: [ role ],
	} );
}

/**
 * Hands back the editor's lock on the post currently open, if any.
 *
 * WordPress locks a post for the user who opened it and holds that lock for
 * 150 seconds. The specs walk one post through several users in far less time
 * than that, so without this the next user opens the editor into the "This
 * post is already being edited" dialog instead of the sidebar. The browser
 * normally releases the lock on `beforeunload`, but this session is about to
 * lose its cookies, so release it first, with the same request core sends.
 *
 * @param {Object} page Playwright page.
 * @return {Promise<void>}
 */
export async function releasePostLock( page ) {
	const released = await page.evaluate( async () => {
		const editor = window.wp?.data?.select( 'core/editor' );
		const lock = editor?.getActivePostLock?.();

		if ( ! lock ) {
			return;
		}

		const { postLockUtils } = editor.getEditorSettings();
		const data = new window.FormData();

		data.append( 'action', 'wp-remove-post-lock' );
		data.append( '_wpnonce', postLockUtils.unlockNonce );
		data.append( 'post_ID', editor.getCurrentPostId() );
		data.append( 'active_post_lock', lock );

		const response = await window.fetch( postLockUtils.ajaxUrl, {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
		} );
		return response.text();
	} );

	// Core answers `1` when the lock was handed back. Failing here beats
	// leaving the next user stuck behind the lock dialog.
	if ( undefined !== released ) {
		expect( released ).toBe( '1' );
	}
}

/**
 * Logs the browser in as another user through the login form.
 *
 * @param {Object} page       Playwright page.
 * @param {string} username   Username.
 * @param {string} [password] Password; defaults to `PASSWORD`.
 * @return {Promise<void>}
 */
export async function loginAs( page, username, password = PASSWORD ) {
	await releasePostLock( page );
	await page.context().clearCookies();
	await page.goto( '/wp-login.php' );

	// 200 ms after load, core's wp_attempt_focus() focuses and selects
	// #user_login. If that lands while the password is being typed, the
	// password replaces the username and the empty required password field
	// blocks the submit. Wait for it before typing anything.
	const userLogin = page.locator( '#user_login' );
	await expect( userLogin ).toBeFocused();
	await userLogin.fill( username );
	await page.locator( '#user_pass' ).fill( password );
	await expect( userLogin ).toHaveValue( username );
	await page.locator( '#wp-submit' ).click();
	await page.waitForURL( '**/wp-admin/**' );
}

/**
 * Opens a post in the block editor with the Content Workflow sidebar open.
 *
 * @param {Object}  page                   Playwright page.
 * @param {number}  postId                 Post id.
 * @param {Object}  [options]              Options.
 * @param {boolean} [options.waitForPanel] Wait for the loaded panel; pass
 *                                         false when the load is expected to
 *                                         fail or stall.
 * @return {Promise<Object>} Locator of the sidebar panel.
 */
export async function openWorkflowSidebar(
	page,
	postId,
	{ waitForPanel = true } = {}
) {
	const editorReady = () =>
		page.waitForFunction(
			() =>
				!! window.wp?.data?.select( 'core/editor' )?.getCurrentPostId()
		);

	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await editorReady();

	// `releasePostLock()` does not delete the previous user's lock: core
	// backdates it so it still holds for about five seconds. A user who
	// arrives inside that window gets the "already being edited" dialog, so
	// take the post over the way a person would.
	if (
		await page.evaluate( () =>
			window.wp.data.select( 'core/editor' ).isPostLocked()
		)
	) {
		// Core takes the lock and redirects back to the plain edit URL, so
		// follow the link (relative to wp-admin) with goto() rather than waiting
		// for its URL.
		const takeOver = await page
			.locator( '.editor-post-locked-modal' )
			.getByText( 'Take over', { exact: true } )
			.getAttribute( 'href' );
		await page.goto( new URL( takeOver, page.url() ).href );
		await editorReady();
	}

	// Users created by the specs would otherwise see the welcome guide. Older
	// cores keep a guide that is already open, so close it as well.
	await page.evaluate( () =>
		window.wp.data
			.dispatch( 'core/preferences' )
			.set( 'core/edit-post', 'welcomeGuide', false )
	);
	const guide = page.locator( '.components-guide' );
	if ( await guide.isVisible() ) {
		await page.keyboard.press( 'Escape' );
	}
	await expect( guide ).toHaveCount( 0 );

	const toggle = page
		.getByRole( 'region', { name: 'Editor top bar' } )
		.getByRole( 'button', { name: 'Content Workflow', exact: true } );

	if ( ( await toggle.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
		await toggle.click();
	}

	const panel = page.locator( '.sit-cwm-sidebar' );

	// The panel replaces its spinner only once `GET /workflow` answers, which
	// on a cold local server can take longer than the default 5 s.
	if ( waitForPanel ) {
		await expect( panel ).toBeVisible( { timeout: 20000 } );
	}

	return panel;
}

/**
 * The status badge text of the sidebar.
 *
 * @param {Object} panel Sidebar panel locator.
 * @return {Object} Locator.
 */
export function statusBadge( panel ) {
	return panel.locator( '.sit-cwm-status-text' );
}

/**
 * Dialogs that approving and publishing raise, keyed by target status label.
 *
 * Those two moves get copy of their own rather than the generic
 * "Move to X?" of a rollback; see `src/utils/confirmations.js`.
 *
 * @type {Object<string, {title: string, button: string}>}
 */
const CONFIRMATIONS = {
	Approved: { title: 'Approve content?', button: 'Approve' },
	Published: { title: 'Publish content?', button: 'Publish' },
};

/**
 * Clicks a sidebar transition button and waits for the new status.
 *
 * @param {Object}  page              Playwright page.
 * @param {Object}  panel             Sidebar panel locator.
 * @param {string}  label             Target status label, e.g. "Review".
 * @param {Object}  [options]         Options.
 * @param {boolean} [options.confirm] Whether the move opens a confirm dialog.
 * @return {Promise<void>}
 */
export async function moveTo( page, panel, label, { confirm = false } = {} ) {
	await panel.getByRole( 'button', { name: `Move to ${ label }` } ).click();

	if ( confirm ) {
		const { title, button } = CONFIRMATIONS[ label ] || {
			title: `Move to ${ label }?`,
			button: `Move to ${ label }`,
		};

		await page
			.getByRole( 'dialog', { name: title } )
			.getByRole( 'button', { name: button, exact: true } )
			.click();
	}

	await expect( statusBadge( panel ) ).toHaveText( label );
}
