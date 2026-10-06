/**
 * End-to-end: every sit-cwm/v1 route probed by a visitor and a subscriber
 * (step 20.3).
 *
 * The PHPUnit negative suite checks the permission callbacks in-process. This
 * spec asks a real site over HTTP, with a real login cookie and `wp_rest`
 * nonce, so cookie authentication and the REST server's own ordering are part
 * of what is tested. Each response must refuse (401 / 403 / 404) and must not
 * mention the probed posts or their author.
 */

/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

/**
 * Internal dependencies
 */
import { createWorkflowUser, loginAs } from './utils';

/**
 * Marker in both post titles, so any echo of either shows up in a body.
 *
 * @type {string}
 */
const SECRET = 'CWM-PROBE-SECRET';

/**
 * Logins of the probe users.
 *
 * @type {{author: string, subscriber: string}}
 */
const LOGIN = {
	author: 'cwm_probe_author',
	subscriber: 'cwm_probe_subscriber',
};

/**
 * Absolute URL of a site path, whether or not the base URL ends in a slash.
 *
 * @param {string} baseURL Site URL.
 * @param {string} path    Path relative to the site root.
 * @return {string} URL.
 */
const siteUrl = ( baseURL, path ) => baseURL.replace( /\/?$/, '/' ) + path;

test.describe( 'Subscriber probe', () => {
	const fixture = {};

	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();

		fixture.author = await createWorkflowUser(
			requestUtils,
			LOGIN.author,
			'author'
		);
		fixture.subscriber = await createWorkflowUser(
			requestUtils,
			LOGIN.subscriber,
			'subscriber'
		);

		// A draft the subscriber cannot read, and a published post they can.
		fixture.draft = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: `${ SECRET } draft`,
				status: 'draft',
				author: fixture.author.id,
			},
		} );
		fixture.published = await requestUtils.rest( {
			method: 'POST',
			path: '/wp/v2/posts',
			data: {
				title: `${ SECRET } published`,
				status: 'publish',
				author: fixture.author.id,
			},
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllUsers();
	} );

	/**
	 * Every route and method, with a valid body so that argument validation
	 * (which the REST server runs before permission callbacks) passes and the
	 * permission check is what answers.
	 *
	 * `subscriber` is the expected status for a logged-in subscriber: 404 when
	 * they cannot read the post (a 403 would confirm it exists), 403 when they
	 * can read it but may not act on it, 403 for collection routes.
	 *
	 * @return {Array<Object>} Probe cases.
	 */
	const cases = () => {
		const draft = fixture.draft.id;
		const published = fixture.published.id;
		const move = { from: 'draft', status: 'writing' };
		const comment = { message: 'Probe' };
		const batch = {
			post_ids: [ draft, published ],
			action: 'change_status',
			payload: { status: 'writing' },
		};

		return [
			[ 'GET', `/posts/${ draft }/workflow`, null, 404 ],
			[ 'GET', `/posts/${ published }/workflow`, null, 403 ],
			[ 'POST', `/posts/${ draft }/workflow`, move, 404 ],
			[ 'POST', `/posts/${ published }/workflow`, move, 403 ],
			[ 'GET', `/posts/${ draft }/activity`, null, 404 ],
			[ 'GET', `/posts/${ published }/activity`, null, 403 ],
			[ 'POST', `/posts/${ draft }/comments`, comment, 404 ],
			[ 'POST', `/posts/${ published }/comments`, comment, 403 ],
			[ 'GET', '/posts', null, 403 ],
			[ 'POST', '/posts/batch', batch, 403 ],
			[ 'GET', '/statuses', null, 403 ],
			[ 'GET', '/users', null, 403 ],
		].map( ( [ method, route, data, subscriber ] ) => ( {
			method,
			route,
			data,
			subscriber,
		} ) );
	};

	/**
	 * Strings no refusal may contain.
	 *
	 * @return {string[]} Needles.
	 */
	const secrets = () => [ SECRET, LOGIN.author, fixture.author.email ];

	/**
	 * Sends one probe and checks its status and body.
	 *
	 * @param {Object} context  Playwright API request context.
	 * @param {string} baseURL  Site URL.
	 * @param {Object} probe    Case from `cases()`.
	 * @param {number} expected Expected HTTP status.
	 * @param {Object} headers  Extra headers.
	 * @return {Promise<Object>} `{ label, status, code }` for the report.
	 */
	const send = async ( context, baseURL, probe, expected, headers = {} ) => {
		const response = await context.fetch(
			siteUrl( baseURL, `?rest_route=/sit-cwm/v1${ probe.route }` ),
			{
				method: probe.method,
				headers: { ...headers, 'Content-Type': 'application/json' },
				data: probe.data ? JSON.stringify( probe.data ) : undefined,
				failOnStatusCode: false,
			}
		);
		const body = await response.text();
		const label = `${ probe.method } ${ probe.route }`;

		expect( response.status(), label ).toBe( expected );

		for ( const needle of secrets() ) {
			expect( body, `${ label } leaks "${ needle }"` ).not.toContain(
				needle
			);
		}

		let code;
		try {
			code = JSON.parse( body ).code || '';
		} catch {
			code = 'not JSON';
		}

		return { label, status: response.status(), code };
	};

	/**
	 * Prints the results as a Markdown table for ARCHITECTURE.md.
	 *
	 * @param {string}   who     Column heading.
	 * @param {Object[]} results Results of `send()`.
	 * @return {void}
	 */
	const report = ( who, results ) => {
		// eslint-disable-next-line no-console
		console.log(
			[
				`| Route | ${ who } |`,
				'|---|---|',
				...results.map(
					( { label, status, code } ) =>
						`| \`${ label }\` | ${ status } \`${ code }\` |`
				),
			].join( '\n' )
		);
	};

	test( 'a visitor gets 401 from every route', async ( {
		playwright,
		baseURL,
	} ) => {
		// A fresh context: no login cookie at all.
		const visitor = await playwright.request.newContext();
		const results = [];

		for ( const probe of cases() ) {
			results.push( await send( visitor, baseURL, probe, 401 ) );
		}

		await visitor.dispose();
		report( 'Logged out', results );
		expect( results ).toHaveLength( cases().length );
	} );

	test( 'a subscriber is refused on every route', async ( {
		page,
		baseURL,
	} ) => {
		await loginAs( page, LOGIN.subscriber );

		// The nonce the block editor would send, issued for this session.
		const nonceResponse = await page.request.get(
			siteUrl( baseURL, 'wp-admin/admin-ajax.php?action=rest-nonce' )
		);
		const nonce = ( await nonceResponse.text() ).trim();

		expect( nonce ).toMatch( /^[0-9a-f]{10}$/ );

		const results = [];

		for ( const probe of cases() ) {
			results.push(
				await send( page.request, baseURL, probe, probe.subscriber, {
					'X-WP-Nonce': nonce,
				} )
			);
		}

		report( 'Subscriber', results );
		expect( results ).toHaveLength( cases().length );
	} );

	test( 'a login cookie without a nonce counts as logged out', async ( {
		page,
		baseURL,
	} ) => {
		await loginAs( page, LOGIN.subscriber );

		// Cookie authentication requires the nonce; without it WordPress
		// treats the request as anonymous, which is what defeats CSRF.
		const probe = cases().find(
			( entry ) =>
				'POST' === entry.method && entry.route.endsWith( '/workflow' )
		);
		const result = await send( page.request, baseURL, probe, 401 );

		expect( result.code ).toBe( 'rest_forbidden' );
	} );
} );
