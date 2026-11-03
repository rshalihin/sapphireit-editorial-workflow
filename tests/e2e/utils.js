/**
 * Helpers shared by the end-to-end specs.
 */

/**
 * WordPress dependencies
 */
import { expect } from '@wordpress/e2e-test-utils-playwright';

/**
 * External dependencies
 */
import AxeBuilder from '@axe-core/playwright';
import { request as playwrightRequest } from '@playwright/test';

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
 * Opens a post in the block editor with the Editorial Workflow sidebar open.
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
		.getByRole( 'button', { name: 'Editorial Workflow', exact: true } );

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

	// The move is queued behind the editor's own start-up requests, which on
	// a slow local server (opcache off, ~0.5 s per boot) can take several
	// seconds; the same allowance as the panel's first load.
	await expect( statusBadge( panel ) ).toHaveText( label, {
		timeout: 20000,
	} );
}

/**
 * Violations of WCAG A/AA rules inside one element.
 *
 * @param {Object} page     Playwright page.
 * @param {string} selector CSS selector to scan.
 * @return {Promise<Object[]>} `{ id, impact, help, targets }` per violation.
 */
export async function axeViolations( page, selector ) {
	const { violations: found, passes } = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ] )
		.analyze();

	// Proves the selector matched: an empty scope would pass vacuously.
	expect(
		passes.length,
		`axe checked nothing in ${ selector }`
	).toBeGreaterThan( 5 );

	return found.map( ( { id, impact, help, nodes } ) => ( {
		id,
		impact,
		help,
		targets: nodes.map( ( node ) => node.target.join( ' ' ) ),
	} ) );
}

/**
 * Namespace of the test-only routes in `tests/e2e/fixtures/sit-cwm-e2e-helpers.php`.
 *
 * @type {string}
 */
const E2E_NAMESPACE = '/sit-cwm-e2e/v1';

/**
 * Merges a partial settings array through the plugin's own settings model.
 *
 * `publish_gate` replaces the stored map (omitted types are off);
 * `notifications` is merged over the stored toggles.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {Object} partial      Partial settings, e.g. `{ publish_gate: { post: 'enforce' } }`.
 * @return {Promise<Object>} The settings after the update.
 */
export function setSettings( requestUtils, partial ) {
	return requestUtils.rest( {
		method: 'POST',
		path: `${ E2E_NAMESPACE }/settings`,
		data: partial,
	} );
}

/**
 * Every notification event, for `setSettings( { notifications } )`.
 *
 * @param {boolean} enabled Whether to turn them on.
 * @return {Object<string, boolean>} Event => enabled.
 */
export function allNotifications( enabled ) {
	return Object.fromEntries(
		[
			'reviewer_assigned',
			'review_requested',
			'changes_requested',
			'approved',
			'comment_added',
		].map( ( event ) => [ event, enabled ] )
	);
}

/**
 * Mails captured since the last `clearMails()`, oldest first.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<Array<{to: string[], subject: string, body: string}>>} Mails.
 */
export function getMails( requestUtils ) {
	return requestUtils.rest( { path: `${ E2E_NAMESPACE }/mails` } );
}

/**
 * Forgets every captured mail and the notification de-duplication state.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<void>}
 */
export async function clearMails( requestUtils ) {
	await requestUtils.rest( {
		method: 'DELETE',
		path: `${ E2E_NAMESPACE }/mails`,
	} );
}

/**
 * Makes a scheduled post due and runs what cron runs at go-live.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} id           Post id.
 * @return {Promise<{status: string}>} The post status afterwards.
 */
export function goLive( requestUtils, id ) {
	return requestUtils.rest( {
		method: 'POST',
		path: `${ E2E_NAMESPACE }/go-live/${ id }`,
	} );
}

/**
 * Publishes a post with `wp_publish_post()`, the path only the backstop sees.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} id           Post id.
 * @return {Promise<{status: string}>} The post status afterwards.
 */
export function rawPublish( requestUtils, id ) {
	return requestUtils.rest( {
		method: 'POST',
		path: `${ E2E_NAMESPACE }/raw-publish/${ id }`,
	} );
}

/**
 * A post's activity items from the public route, oldest first.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} id           Post id.
 * @return {Promise<Object[]>} Activity items.
 */
export async function getActivity( requestUtils, id ) {
	const items = await requestUtils.rest( {
		path: `/sit-cwm/v1/posts/${ id }/activity`,
		params: { per_page: 100 },
	} );

	return items.reverse();
}

/**
 * A post's activity `action` slugs, oldest first.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} id           Post id.
 * @return {Promise<string[]>} Action slugs.
 */
export async function getActivityActions( requestUtils, id ) {
	return ( await getActivity( requestUtils, id ) ).map(
		( entry ) => entry.action
	);
}

/**
 * A post's raw activity rows with their stored context (e.g. `layer`),
 * oldest first. Test-only route; the public one does not expose context.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} id           Post id.
 * @return {Promise<Array<{action: string, user_id: number, context: Object}>>} Rows.
 */
export function getRawActivity( requestUtils, id ) {
	return requestUtils.rest( { path: `${ E2E_NAMESPACE }/activity/${ id }` } );
}

/**
 * Moves a post's workflow status as the administrator, through the plugin's
 * own route (never by writing meta).
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} id           Post id.
 * @param {string} from         Status the caller expects is current.
 * @param {string} to           Target status.
 * @return {Promise<Object>} The workflow afterwards.
 */
export function setWorkflowStatus( requestUtils, id, from, to ) {
	return requestUtils.rest( {
		method: 'POST',
		path: `/sit-cwm/v1/posts/${ id }/workflow`,
		data: { status: to, from },
	} );
}

/**
 * Walks a post along the workflow as the administrator, e.g.
 * `[ 'writing', 'review', 'approved' ]` from Draft.
 *
 * @param {Object}   requestUtils Request utils fixture.
 * @param {number}   id           Post id.
 * @param {string[]} path         Statuses to move through, in order.
 * @param {string}   [from]       Starting status; default `draft`.
 * @return {Promise<void>}
 */
export async function walkWorkflow( requestUtils, id, path, from = 'draft' ) {
	let current = from;

	for ( const to of path ) {
		await setWorkflowStatus( requestUtils, id, current, to );
		current = to;
	}
}

/**
 * The native post status, read fresh over REST.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {number} id           Post id.
 * @param {string} [type]       REST base; default `posts`.
 * @return {Promise<string>} Post status.
 */
export async function postStatus( requestUtils, id, type = 'posts' ) {
	const post = await requestUtils.rest( {
		path: `/wp/v2/${ type }/${ id }`,
		params: { context: 'edit' },
	} );

	return post.status;
}

/**
 * Calls the REST API as the user logged in to the browser and returns the
 * status code with the body instead of throwing.
 *
 * Uses the browser context's cookies with a nonce from core's `rest-nonce`
 * AJAX action, so it works from any page once `loginAs()` has run.
 *
 * @param {Object} page             Playwright page.
 * @param {Object} options          Request.
 * @param {string} options.path     REST path, e.g. `/sit-cwm/v1/posts/1/workflow`.
 * @param {string} [options.method] HTTP method; default `GET`.
 * @param {Object} [options.data]   JSON body.
 * @return {Promise<{status: number, body: Object}>} Response.
 */
export async function apiAsBrowserUser( page, { path, method = 'GET', data } ) {
	const nonce = await page.request.get(
		'./wp-admin/admin-ajax.php?action=rest-nonce'
	);
	const response = await page.request.fetch(
		`./index.php?rest_route=${ encodeURIComponent( path ) }`,
		{
			method,
			data,
			headers: { 'X-WP-Nonce': await nonce.text() },
		}
	);

	return { status: response.status(), body: await response.json() };
}

/**
 * Sets the probe add-on (`tests/fixtures/sit-cwm-probe`) active or inactive.
 *
 * Found by its folder: `requestUtils.activatePlugin()` keys plugins by a
 * slug of their name, which for the probe is not `sit-cwm-probe`.
 *
 * @param {Object}  requestUtils Request utils fixture.
 * @param {boolean} active       Whether it should be active.
 * @return {Promise<void>}
 */
async function setProbeActive( requestUtils, active ) {
	const plugins = await requestUtils.rest( { path: '/wp/v2/plugins' } );
	const probe = plugins.find( ( plugin ) =>
		plugin.plugin.startsWith( 'sit-cwm-probe/' )
	);

	if ( ! probe ) {
		throw new Error(
			'The probe add-on is not installed. Run `php bin/setup-e2e-site.php` (or map it in .wp-env.json).'
		);
	}

	const status = active ? 'active' : 'inactive';

	if ( probe.status !== status ) {
		await requestUtils.rest( {
			method: 'PUT',
			path: `/wp/v2/plugins/${ probe.plugin }`,
			data: { status },
		} );
	}
}

/**
 * Activates the probe add-on.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<void>}
 */
export function activateProbe( requestUtils ) {
	return setProbeActive( requestUtils, true );
}

/**
 * Deactivates the probe add-on.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<void>}
 */
export function deactivateProbe( requestUtils ) {
	return setProbeActive( requestUtils, false );
}

/**
 * Sets or clears the probe add-on's legal checklist flag on a post.
 *
 * @param {Object}  requestUtils Request utils fixture.
 * @param {number}  postId       Post id.
 * @param {boolean} done         Whether the checklist is complete.
 * @return {Promise<{done: boolean}>} The flag afterwards.
 */
export function setProbeChecklist( requestUtils, postId, done ) {
	return requestUtils.rest( {
		method: 'POST',
		path: `${ E2E_NAMESPACE }/probe-checklist`,
		data: { post_id: postId, done },
	} );
}

/**
 * Inserts activity rows through the plugin's own logger, each with an
 * explicit `created_at` (UTC `Y-m-d H:i:s`) and `context`.
 *
 * @param {Object}   requestUtils Request utils fixture.
 * @param {Object[]} rows         `{ post_id, action, context?, created_at?, user_id?, old_value?, new_value? }`.
 * @return {Promise<number[]>} Inserted ids, in order.
 */
export async function seedActivity( requestUtils, rows ) {
	const ids = [];

	for ( const row of rows ) {
		const { id } = await requestUtils.rest( {
			method: 'POST',
			path: `${ E2E_NAMESPACE }/activity`,
			data: row,
		} );
		ids.push( id );
	}

	return ids;
}

/**
 * Deletes every activity row, for specs whose figures count the whole table.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @return {Promise<Object>} Response.
 */
export async function clearActivity( requestUtils ) {
	return requestUtils.rest( {
		method: 'DELETE',
		path: `${ E2E_NAMESPACE }/activity`,
	} );
}

/**
 * A UTC `Y-m-d H:i:s` timestamp some days ago, for `seedActivity()`.
 *
 * @param {number} days Days before now.
 * @return {string} Timestamp.
 */
export function daysAgo( days ) {
	return new Date( Date.now() - days * 86400000 )
		.toISOString()
		.slice( 0, 19 )
		.replace( 'T', ' ' );
}

/**
 * The Freemius opt-in state, or sets it: `'show'` keeps the opt-in showing
 * (open the returned `reconnect_url` to reset the skip), `'skip'` skips it.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {string} [optin]      `'show'` or `'skip'`; omit to only read.
 * @return {Promise<{registered: boolean, anonymous: boolean, keep_optin: boolean, reconnect_url: string}>} State.
 */
export function freemiusOptin( requestUtils, optin ) {
	return requestUtils.rest(
		optin
			? {
					method: 'POST',
					path: `${ E2E_NAMESPACE }/freemius`,
					data: { optin },
				}
			: { path: `${ E2E_NAMESPACE }/freemius` }
	);
}

/**
 * Creates an Application Password for a user, as the administrator.
 *
 * @param {Object} requestUtils Request utils fixture.
 * @param {Object} user         User `{ id, username }` (or `name`).
 * @return {Promise<{user: string, password: string}>} Basic auth credentials.
 */
export async function createAppPassword( requestUtils, user ) {
	const { password } = await requestUtils.rest( {
		method: 'POST',
		path: `/wp/v2/users/${ user.id }/application-passwords`,
		data: { name: 'E2E agent' },
	} );

	return { user: user.username || user.name, password };
}

/**
 * Calls the REST API the way `docs/AGENTS.md` tells an agent to: HTTP Basic
 * auth with an Application Password, no cookies, no nonce.
 *
 * Uses a request context of its own with no cookies: with the
 * administrator's cookies (which the `request` fixture and, by default, any
 * new context carry) WordPress would take the cookie user, skip the
 * Application Password, then drop to logged out for want of a nonce.
 *
 * @param {Object} credentials `{ user, password }` from `createAppPassword()`.
 * @param {string} method      HTTP method.
 * @param {string} path        Path under `/wp-json`, e.g. `/wp/v2/posts`.
 * @param {Object} [data]      JSON body.
 * @return {Promise<{status: number, body: Object}>} Response.
 */
export async function agentRequest( credentials, method, path, data ) {
	const auth = Buffer.from(
		`${ credentials.user }:${ credentials.password }`
	).toString( 'base64' );
	const context = await playwrightRequest.newContext( {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8889',
		// Inside the test runner a new context inherits the project's
		// `storageState` (the admin's cookies) unless given an empty one.
		storageState: { cookies: [], origins: [] },
		extraHTTPHeaders: { Authorization: `Basic ${ auth }` },
	} );

	try {
		const response = await context.fetch(
			`./index.php?rest_route=${ encodeURIComponent( path ) }`,
			{ method, data }
		);

		return { status: response.status(), body: await response.json() };
	} finally {
		await context.dispose();
	}
}

/**
 * Grants or explicitly denies one `sit_cwm_*` capability to a single user.
 *
 * @param {Object}  requestUtils Request utils fixture.
 * @param {number}  userId       User id.
 * @param {string}  cap          Capability, e.g. `sit_cwm_change_workflow`.
 * @param {boolean} grant        `false` denies it even if the role has it.
 * @return {Promise<{has_cap: boolean}>} Whether the user has it afterwards.
 */
export function setUserCap( requestUtils, userId, cap, grant ) {
	return requestUtils.rest( {
		method: 'POST',
		path: `${ E2E_NAMESPACE }/user-cap`,
		data: { user_id: userId, cap, grant },
	} );
}
