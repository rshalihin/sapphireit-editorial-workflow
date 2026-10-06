/**
 * Assignable reviewer lookup.
 */

/**
 * WordPress dependencies
 */
import { useEffect, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { getUsers, isAbortError } from '../api/client';

/**
 * Default number of reviewers fetched per search.
 *
 * @type {number}
 */
const PER_PAGE = 20;

/**
 * Users who may be assigned as reviewer, refetched when the search changes.
 *
 * Debouncing is the caller's job; every distinct `search` value triggers a
 * request, and an outdated request is aborted.
 *
 * @param {Object}  [options]         Options.
 * @param {string}  [options.search]  Name search.
 * @param {number}  [options.postId]  Limit to users who can review this post.
 * @param {number}  [options.perPage] Page size (max 100).
 * @param {boolean} [options.enabled] Set false to skip fetching.
 * @return {{users: Object[], isLoading: boolean, error: ?Object}} Result.
 */
export default function useUsers( {
	search = '',
	postId = 0,
	perPage = PER_PAGE,
	enabled = true,
} = {} ) {
	const [ users, setUsers ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		const controller = new window.AbortController();

		if ( enabled ) {
			setIsLoading( true );

			getUsers(
				{ search, per_page: perPage, post_id: postId || undefined },
				{ signal: controller.signal }
			)
				.then( ( data ) => {
					setUsers( Array.isArray( data ) ? data : [] );
					setError( null );
				} )
				.catch( ( err ) => {
					if ( ! isAbortError( err ) ) {
						setError( err );
					}
				} )
				.finally( () => {
					if ( ! controller.signal.aborted ) {
						setIsLoading( false );
					}
				} );
		}

		return () => controller.abort();
	}, [ search, postId, perPage, enabled ] );

	return { users, isLoading, error };
}
