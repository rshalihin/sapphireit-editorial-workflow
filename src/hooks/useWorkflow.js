/**
 * The single data hook for a post's workflow.
 */

/**
 * WordPress dependencies
 */
import { speak } from '@wordpress/a11y';
import { useDispatch } from '@wordpress/data';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import * as client from '../api/client';
import { decodeText, formatDate } from '../utils/format';

/**
 * Error returned when a mutation is attempted while another is running or
 * before the workflow has loaded.
 *
 * @type {import('../api/client').ApiError}
 */
const BUSY = { code: 'sit_cwm_busy', message: '', status: 0 };

/**
 * Error returned when a mutation is attempted after the post disappeared.
 *
 * @type {import('../api/client').ApiError}
 */
const GONE = { code: 'sit_cwm_gone', message: '', status: 404 };

/**
 * Loads and mutates a post's workflow through the REST API.
 *
 * The server is the source of truth: mutations are not applied optimistically.
 * Each one sets `isSaving`, then replaces `workflow` with the server response.
 *
 * Mutations resolve to `null` on success or the normalized error on failure,
 * so callers can react locally. Workflow mutations (status, reviewer, due date)
 * also expose their failure as `error`; `addComment` leaves that to the caller.
 *
 * A status change sends `{ from, status }` with the status the user saw. On a
 * 409 the hook refetches and reports that the post changed elsewhere rather
 * than retrying. A 403 also refetches, so revoked permissions stop being offered.
 * A 404 after the workflow loaded (post deleted, or its type disabled) sets
 * `isGone`, and every later mutation is refused without a request.
 *
 * Reviewer, due date and comment changes are announced to screen readers;
 * status changes raise a snackbar, which announces itself.
 *
 * @param {number} postId Post id.
 * @return {Object} `{ workflow, isLoading, isSaving, isGone, error,
 *                  activityVersion, updateStatus, assignReviewer, setDueDate,
 *                  addComment, refresh, clearError }`.
 */
export default function useWorkflow( postId ) {
	const [ workflow, setWorkflow ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( Boolean( postId ) );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isGone, setIsGone ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ activityVersion, setActivityVersion ] = useState( 0 );
	const { createSuccessNotice } = useDispatch( noticesStore );

	// Bumped when the post changes or the hook unmounts; responses from an
	// older generation are ignored.
	const generationRef = useRef( 0 );
	const postIdRef = useRef( postId );
	const controllersRef = useRef( new Set() );
	const savingRef = useRef( false );
	const loadedRef = useRef( false );
	const goneRef = useRef( false );

	const markGone = useCallback( () => {
		goneRef.current = true;
		setIsGone( true );
	}, [] );

	const refresh = useCallback( async () => {
		const generation = generationRef.current;
		const controller = new window.AbortController();

		controllersRef.current.add( controller );
		setIsLoading( true );

		try {
			const data = await client.getWorkflow( postIdRef.current, {
				signal: controller.signal,
			} );

			if ( generation === generationRef.current ) {
				loadedRef.current = true;
				setWorkflow( data );
				setError( null );
			}

			return data;
		} catch ( err ) {
			if (
				generation === generationRef.current &&
				! client.isAbortError( err )
			) {
				if ( err.status === 404 && loadedRef.current ) {
					markGone();
				}

				setError( err );
			}

			return null;
		} finally {
			controllersRef.current.delete( controller );

			if ( generation === generationRef.current ) {
				setIsLoading( false );
			}
		}
	}, [ markGone ] );

	useEffect( () => {
		const controllers = controllersRef.current;

		postIdRef.current = postId;
		savingRef.current = false;
		loadedRef.current = false;
		goneRef.current = false;
		setWorkflow( null );
		setError( null );
		setIsSaving( false );
		setIsGone( false );
		setIsLoading( Boolean( postId ) );

		if ( postId ) {
			refresh();
		}

		return () => {
			generationRef.current += 1;
			controllers.forEach( ( controller ) => controller.abort() );
			controllers.clear();
		};
	}, [ postId, refresh ] );

	/**
	 * Runs one mutation with the shared saving guard.
	 *
	 * @param {Function} send                Performs the request.
	 * @param {Object}   options             Options.
	 * @param {boolean}  options.exposeError Whether a failure sets `error`.
	 * @return {Promise<{data: *, error: ?Object}>} Result.
	 */
	const run = useCallback(
		async ( send, { exposeError } ) => {
			if ( goneRef.current ) {
				return { data: null, error: GONE };
			}

			if ( savingRef.current || ! postIdRef.current ) {
				return { data: null, error: BUSY };
			}

			const generation = generationRef.current;

			savingRef.current = true;
			setIsSaving( true );

			try {
				const data = await send( postIdRef.current );

				if ( generation === generationRef.current ) {
					setActivityVersion( ( version ) => version + 1 );
				}

				return { data, error: null };
			} catch ( err ) {
				if ( generation !== generationRef.current ) {
					return { data: null, error: err };
				}

				// Deleted, or no longer workflow-enabled, while the editor was open.
				if ( err.status === 404 ) {
					markGone();
				}

				if ( ! exposeError ) {
					return { data: null, error: err };
				}

				let reported = err;

				if ( err.status === 409 ) {
					await refresh();
					reported = {
						...err,
						message: __(
							'This post changed elsewhere — refreshed.',
							'sapphireit-editorial-workflow'
						),
					};
				} else if ( err.status === 403 ) {
					await refresh();
				}

				if ( generation === generationRef.current ) {
					setError( reported );
				}

				return { data: null, error: reported };
			} finally {
				if ( generation === generationRef.current ) {
					savingRef.current = false;
					setIsSaving( false );
				}
			}
		},
		[ refresh, markGone ]
	);

	/**
	 * Sends a workflow update and applies the server response.
	 *
	 * @param {Object} payload REST payload.
	 * @return {Promise<{data: *, error: ?Object}>} Result.
	 */
	const update = useCallback(
		async ( payload ) => {
			const generation = generationRef.current;
			const result = await run(
				( id ) => client.updateWorkflow( id, payload ),
				{ exposeError: true }
			);

			if ( result.data && generation === generationRef.current ) {
				setWorkflow( result.data );
				setError( null );
			}

			return result;
		},
		[ run ]
	);

	const updateStatus = useCallback(
		async ( to ) => {
			if ( ! workflow ) {
				return BUSY;
			}

			// `from` is the status this render showed the user.
			const { data, error: err } = await update( {
				from: workflow.status,
				status: to,
			} );

			if ( data ) {
				createSuccessNotice(
					sprintf(
						/* translators: %s: Workflow status label. */
						__(
							'Workflow status changed to %s.',
							'sapphireit-editorial-workflow'
						),
						data.status_label
					),
					{ id: 'sit-cwm-status-changed', type: 'snackbar' }
				);
			}

			return err;
		},
		[ workflow, update, createSuccessNotice ]
	);

	const assignReviewer = useCallback(
		async ( reviewerId ) => {
			const { data, error: err } = await update( {
				reviewer_id: parseInt( reviewerId, 10 ) || 0,
			} );

			if ( data ) {
				speak(
					data.reviewer?.name
						? sprintf(
								/* translators: %s: Reviewer display name. */
								__(
									'Reviewer set to %s.',
									'sapphireit-editorial-workflow'
								),
								decodeText( data.reviewer.name )
							)
						: __(
								'Reviewer removed.',
								'sapphireit-editorial-workflow'
							)
				);
			}

			return err;
		},
		[ update ]
	);

	const setDueDate = useCallback(
		async ( date ) => {
			const { data, error: err } = await update( {
				due_date: date || '',
			} );

			if ( data ) {
				speak(
					data.due_date
						? sprintf(
								/* translators: %s: Due date. */
								__(
									'Due date set to %s.',
									'sapphireit-editorial-workflow'
								),
								formatDate( data.due_date )
							)
						: __(
								'Due date removed.',
								'sapphireit-editorial-workflow'
							)
				);
			}

			return err;
		},
		[ update ]
	);

	const addComment = useCallback(
		async ( message ) => {
			const { error: err } = await run(
				( id ) => client.addComment( id, message ),
				{ exposeError: false }
			);

			if ( ! err ) {
				speak(
					__( 'Comment added.', 'sapphireit-editorial-workflow' )
				);
			}

			return err;
		},
		[ run ]
	);

	const clearError = useCallback( () => setError( null ), [] );

	return {
		workflow,
		isLoading,
		isSaving,
		isGone,
		error,
		activityVersion,
		updateStatus,
		assignReviewer,
		setDueDate,
		addComment,
		refresh,
		clearError,
	};
}
