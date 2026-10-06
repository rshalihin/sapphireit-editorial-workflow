/**
 * Paginated activity history of a post.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

/**
 * Internal dependencies
 */
import * as client from '../api/client';

/**
 * Entries fetched per page (mirrors the REST default).
 *
 * @type {number}
 */
export const DEFAULT_PER_PAGE = 20;

/**
 * Appends entries that are not already listed.
 *
 * A page can repeat an entry from the previous one when new activity was
 * logged in between, shifting every offset by one.
 *
 * @param {Object[]} current  Entries shown so far.
 * @param {Object[]} incoming Entries of the next page.
 * @return {Object[]} Merged entries, newest first.
 */
function mergeById( current, incoming ) {
	const seen = new Set( current.map( ( item ) => item.id ) );

	return [
		...current,
		...incoming.filter( ( item ) => ! seen.has( item.id ) ),
	];
}

/**
 * Loads a post's activity page by page, newest first.
 *
 * Pages are appended by `loadMore()`. Changing `version` (the counter
 * `useWorkflow` bumps after a mutation) or calling `refresh()` reloads page 1
 * and replaces the list, so a new entry appears at the top. Entries already
 * shown stay visible while that reload runs.
 *
 * @param {number}  postId            Post id.
 * @param {Object}  [options]         Options.
 * @param {number}  [options.perPage] Entries per page.
 * @param {number}  [options.version] Reloads page 1 when it changes.
 * @param {boolean} [options.enabled] Set false to skip fetching entirely.
 *                                    `isRefreshing` is true while page 1 reloads behind entries already shown.
 *
 * @return {Object} `{ items, isLoading, isRefreshing, error, hasMore, loadMore, refresh, retry }`.
 */
export default function useActivity(
	postId,
	{ perPage = DEFAULT_PER_PAGE, version = 0, enabled = true } = {}
) {
	const isActive = Boolean( enabled && postId );
	const [ items, setItems ] = useState( [] );
	const [ page, setPage ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( isActive );
	const [ loadingPage, setLoadingPage ] = useState( 0 );
	const [ error, setError ] = useState( null );
	const [ resetCount, setResetCount ] = useState( 0 );

	// The request in flight; only its response is applied.
	const controllerRef = useRef( null );
	const failedPageRef = useRef( 1 );
	const postIdRef = useRef( postId );

	const load = useCallback(
		async ( nextPage ) => {
			if ( controllerRef.current ) {
				controllerRef.current.abort();
			}

			const controller = new window.AbortController();

			controllerRef.current = controller;
			setIsLoading( true );
			setLoadingPage( nextPage );
			setError( null );

			try {
				const result = await client.getActivityPage(
					postId,
					{ page: nextPage, per_page: perPage },
					{ signal: controller.signal }
				);

				if ( controllerRef.current !== controller ) {
					return;
				}

				setItems( ( current ) =>
					nextPage === 1
						? result.items
						: mergeById( current, result.items )
				);
				setPage( nextPage );
				setTotalPages( result.totalPages );
			} catch ( err ) {
				if (
					controllerRef.current === controller &&
					! client.isAbortError( err )
				) {
					failedPageRef.current = nextPage;
					setError( err );
				}
			} finally {
				if ( controllerRef.current === controller ) {
					controllerRef.current = null;
					setIsLoading( false );
				}
			}
		},
		[ postId, perPage ]
	);

	useEffect( () => {
		if ( postIdRef.current !== postId ) {
			postIdRef.current = postId;
			setItems( [] );
			setPage( 0 );
			setTotalPages( 0 );
		}

		if ( ! isActive ) {
			setIsLoading( false );

			return undefined;
		}

		load( 1 );

		return () => {
			if ( controllerRef.current ) {
				controllerRef.current.abort();
				controllerRef.current = null;
			}
		};
		// `version` and `resetCount` only trigger a reload of page 1.
	}, [ isActive, postId, load, version, resetCount ] );

	const loadMore = useCallback( () => {
		// Never race a reload of page 1: its offsets would no longer match.
		if ( ! isActive || controllerRef.current || page >= totalPages ) {
			return;
		}

		load( page + 1 );
	}, [ isActive, page, totalPages, load ] );

	const refresh = useCallback( () => {
		if ( isActive ) {
			setResetCount( ( count ) => count + 1 );
		}
	}, [ isActive ] );

	const retry = useCallback( () => {
		if ( failedPageRef.current > 1 ) {
			loadMore();
		} else {
			refresh();
		}
	}, [ loadMore, refresh ] );

	return {
		items,
		isLoading,
		isRefreshing: isLoading && loadingPage === 1 && items.length > 0,
		error,
		hasMore: page > 0 && page < totalPages,
		loadMore,
		refresh,
		retry,
	};
}
