/**
 * Thin `apiFetch` wrappers over the `sit-cwm/v1` REST routes.
 *
 * Every wrapper rejects with a normalized `{ code, message, status }` error, so
 * hooks and components never inspect raw fetch or `WP_Error` responses. The
 * `wp_rest` nonce is added by `@wordpress/api-fetch` itself.
 */

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Error code used for requests cancelled through an `AbortSignal`.
 *
 * @type {string}
 */
export const ABORTED = 'sit_cwm_request_aborted';

/**
 * Error code `apiFetch` uses when the server could not be reached.
 *
 * @type {string}
 */
const FETCH_ERROR = 'fetch_error';

/**
 * @typedef {Object} ApiError
 * @property {string} code    Error code (`WP_Error` code, or a client code).
 * @property {string} message Human-readable, already translated message.
 * @property {number} status  HTTP status; `0` when no response was received.
 */

/**
 * REST namespace from the bootstrap object, with the D9 default.
 *
 * @return {string} Namespace without slashes.
 */
function namespace() {
	return window.sitCwm?.restNamespace || 'sit-cwm/v1';
}

/**
 * Path of a per-post route.
 *
 * @param {number} postId Post id.
 * @param {string} route  Route below the post, e.g. `workflow`.
 * @return {string} API path.
 */
function postPath( postId, route ) {
	return `/${ namespace() }/posts/${ parseInt( postId, 10 ) }/${ route }`;
}

/**
 * Appends query arguments to a path, skipping empty values.
 *
 * Arrays are sent comma-separated, which the REST API parses for `array`
 * arguments.
 *
 * @param {string} path Path.
 * @param {Object} args Query arguments.
 * @return {string} Path with query string.
 */
function withQuery( path, args = {} ) {
	const params = new URLSearchParams();

	Object.entries( args ).forEach( ( [ key, value ] ) => {
		if ( Array.isArray( value ) ) {
			if ( value.length ) {
				params.append( key, value.join( ',' ) );
			}
		} else if ( value !== undefined && value !== null && value !== '' ) {
			params.append( key, String( value ) );
		}
	} );

	const query = params.toString();

	return query ? `${ path }?${ query }` : path;
}

/**
 * Whether an error (raw or normalized) is a cancelled request.
 *
 * @param {*} error Error.
 * @return {boolean} True for aborted requests.
 */
export function isAbortError( error ) {
	return (
		!! error && ( error.name === 'AbortError' || error.code === ABORTED )
	);
}

/**
 * Whether an error means the server could not be reached at all, as opposed
 * to a response the server sent (4xx/5xx).
 *
 * @param {*} error Normalized error.
 * @return {boolean} True for network failures.
 */
export function isNetworkError( error ) {
	return !! error && error.code === FETCH_ERROR;
}

/**
 * Turns anything `apiFetch` can reject with into an `ApiError`.
 *
 * Handles REST `WP_Error` bodies (`{ code, message, data: { status } }`),
 * network failures, aborted requests, plain `Error`s and already normalized
 * errors (returned unchanged).
 *
 * @param {*} error Rejection value.
 * @return {ApiError} Normalized error.
 */
export function normalizeError( error ) {
	if ( isAbortError( error ) ) {
		return { code: ABORTED, message: '', status: 0 };
	}

	if ( ! error || typeof error !== 'object' ) {
		return {
			code: 'unknown_error',
			message: __(
				'An unknown error occurred.',
				'sapphireit-editorial-workflow'
			),
			status: 0,
		};
	}

	const code =
		typeof error.code === 'string' && error.code
			? error.code
			: 'unknown_error';

	if ( code === FETCH_ERROR ) {
		return {
			code,
			message: __(
				'Couldn’t reach the server. Check your connection and try again.',
				'sapphireit-editorial-workflow'
			),
			status: 0,
		};
	}

	let status = 0;

	if ( typeof error.status === 'number' ) {
		status = error.status;
	} else if ( error.data && Number.isInteger( error.data.status ) ) {
		status = error.data.status;
	}

	return {
		code,
		message:
			typeof error.message === 'string' && error.message
				? error.message
				: __(
						'An unknown error occurred.',
						'sapphireit-editorial-workflow'
					),
		status,
	};
}

/**
 * Runs a request and rejects with a normalized error.
 *
 * @param {Object} options `apiFetch` options.
 * @return {Promise<*>} Parsed response body.
 */
function request( options ) {
	return apiFetch( options ).catch( ( error ) => {
		throw normalizeError( error );
	} );
}

/**
 * Current workflow state of a post.
 *
 * @param {number}      postId         Post id.
 * @param {Object}      [options]      Options.
 * @param {AbortSignal} options.signal Cancels the request.
 * @return {Promise<Object>} Workflow state.
 */
export function getWorkflow( postId, { signal } = {} ) {
	return request( { path: postPath( postId, 'workflow' ), signal } );
}

/**
 * Changes a post's status, reviewer and/or due date.
 *
 * A status change must carry `from` (the status the user saw); the server
 * answers 409 when it no longer matches.
 *
 * @param {number} postId  Post id.
 * @param {Object} payload `{ from, status }`, `{ reviewer_id }` and/or `{ due_date }`.
 * @return {Promise<Object>} Fresh workflow state.
 */
export function updateWorkflow( postId, payload ) {
	return request( {
		path: postPath( postId, 'workflow' ),
		method: 'POST',
		data: payload,
	} );
}

/**
 * One page of a post's activity, newest first.
 *
 * @param {number}      postId         Post id.
 * @param {Object}      [args]         `{ page, per_page, action }`.
 * @param {Object}      [options]      Options.
 * @param {AbortSignal} options.signal Cancels the request.
 * @return {Promise<Object[]>} Activity entries.
 */
export function getActivity( postId, args = {}, { signal } = {} ) {
	return request( {
		path: withQuery( postPath( postId, 'activity' ), args ),
		signal,
	} );
}

/**
 * @typedef {Object} CollectionPage
 * @property {Object[]} items      Entries of the page.
 * @property {number}   total      `X-WP-Total`: entries across all pages.
 * @property {number}   totalPages `X-WP-TotalPages`.
 */

/**
 * Reads the error body of a `parse: false` request.
 *
 * With `parse: false`, `apiFetch` rejects with the raw `Response` of a failed
 * request; its JSON body is the `WP_Error`.
 *
 * @param {*} error Rejection value.
 * @return {Promise<*>} Something `normalizeError()` understands.
 */
async function readErrorResponse( error ) {
	if ( ! error || typeof error.json !== 'function' ) {
		return error;
	}

	try {
		const body = await error.json();

		return body && typeof body === 'object'
			? { ...body, status: error.status }
			: { status: error.status };
	} catch {
		return { status: error.status };
	}
}

/**
 * Integer value of a response header.
 *
 * @param {Object} response Response.
 * @param {string} name     Header name.
 * @return {number} Value; `0` when missing or invalid.
 */
function intHeader( response, name ) {
	const value = parseInt( response.headers?.get( name ), 10 );

	return Number.isNaN( value ) ? 0 : value;
}

/**
 * One page of a collection route with the pagination totals.
 *
 * @param {string}      path   API path with query string.
 * @param {AbortSignal} signal Cancels the request.
 * @return {Promise<CollectionPage>} Entries and totals.
 */
function getCollectionPage( path, signal ) {
	return apiFetch( {
		path,
		parse: false,
		signal,
	} ).then(
		async ( response ) => {
			let items;

			try {
				items = await response.json();
			} catch {
				throw {
					code: 'invalid_json',
					message: __(
						'The response is not a valid JSON response.',
						'sapphireit-editorial-workflow'
					),
					status: response.status || 0,
				};
			}

			return {
				items: Array.isArray( items ) ? items : [],
				total: intHeader( response, 'X-WP-Total' ),
				totalPages: intHeader( response, 'X-WP-TotalPages' ),
			};
		},
		async ( error ) => {
			throw normalizeError( await readErrorResponse( error ) );
		}
	);
}

/**
 * One page of a post's activity with the pagination totals.
 *
 * @param {number}      postId         Post id.
 * @param {Object}      [args]         `{ page, per_page, action }`.
 * @param {Object}      [options]      Options.
 * @param {AbortSignal} options.signal Cancels the request.
 * @return {Promise<CollectionPage>} Entries, newest first, and totals.
 */
export function getActivityPage( postId, args = {}, { signal } = {} ) {
	return getCollectionPage(
		withQuery( postPath( postId, 'activity' ), args ),
		signal
	);
}

/**
 * One page of workflow content for the dashboard, with the totals.
 *
 * @param {Object}      [args]         REST query, see `viewToQuery()`.
 * @param {Object}      [options]      Options.
 * @param {AbortSignal} options.signal Cancels the request.
 * @return {Promise<CollectionPage>} Rows and totals.
 */
export function getPosts( args = {}, { signal } = {} ) {
	return getCollectionPage(
		withQuery( `/${ namespace() }/posts`, args ),
		signal
	);
}

/**
 * Applies one workflow action to many posts.
 *
 * The server authorizes every post on its own and answers with the outcome of
 * each: `{ succeeded, failed, items }`. A rejected promise means the request as
 * a whole failed (e.g. malformed or unauthenticated).
 *
 * @param {number[]} postIds Post ids (max 100).
 * @param {string}   action  `change_status`, `assign_reviewer` or `set_due_date`.
 * @param {Object}   payload `{ status }`, `{ reviewer_id }` or `{ due_date }`.
 * @return {Promise<Object>} Batch result.
 */
export function batchUpdate( postIds, action, payload ) {
	return request( {
		path: `/${ namespace() }/posts/batch`,
		method: 'POST',
		data: { post_ids: postIds, action, payload },
	} );
}

/**
 * Adds a workflow comment.
 *
 * @param {number} postId  Post id.
 * @param {string} message Comment text.
 * @return {Promise<Object>} The stored activity entry.
 */
export function addComment( postId, message ) {
	return request( {
		path: postPath( postId, 'comments' ),
		method: 'POST',
		data: { message },
	} );
}

/**
 * Users who may be assigned as reviewer.
 *
 * @param {Object}      [args]         `{ search, per_page, post_id }`.
 * @param {Object}      [options]      Options.
 * @param {AbortSignal} options.signal Cancels the request.
 * @return {Promise<Object[]>} `{ id, name, avatar }` summaries.
 */
export function getUsers( args = {}, { signal } = {} ) {
	return request( {
		path: withQuery( `/${ namespace() }/users`, args ),
		signal,
	} );
}
