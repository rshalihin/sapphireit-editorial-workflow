/**
 * Bulk workflow actions over `POST /sit-cwm/v1/posts/batch`.
 */

/**
 * WordPress dependencies
 */
import { useCallback, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { batchUpdate } from '../api/client';

/**
 * Actions the batch route accepts.
 *
 * @type {string[]}
 */
export const BULK_ACTIONS = [
	'change_status',
	'assign_reviewer',
	'set_due_date',
];

/**
 * Error code for an id the server response did not account for.
 *
 * @type {string}
 */
export const MISSING_RESULT = 'sit_cwm_missing_result';

/**
 * Unique positive integer ids, in order.
 *
 * @param {*} postIds Candidate ids (numbers or digit strings).
 * @return {number[]} Ids.
 */
export function normalizePostIds( postIds ) {
	const ids = new Set();

	( Array.isArray( postIds ) ? postIds : [] ).forEach( ( id ) => {
		const value =
			typeof id === 'string' && /^\d+$/.test( id )
				? parseInt( id, 10 )
				: id;

		if ( Number.isInteger( value ) && value > 0 ) {
			ids.add( value );
		}
	} );

	return [ ...ids ];
}

/**
 * Reconciles a batch response with the ids that were sent.
 *
 * Only ids that were sent are kept, and every sent id the response does not
 * account for counts as failed, so a partial or malformed answer can never be
 * reported as a success.
 *
 * @param {*}        data    Response body.
 * @param {number[]} postIds Ids that were sent.
 * @return {{succeeded: number[], failed: Object[], items: Object[]}} Result.
 */
export function reconcileResult( data, postIds ) {
	const sent = new Set( postIds );
	const succeeded = normalizePostIds( data?.succeeded ).filter( ( id ) =>
		sent.has( id )
	);
	const done = new Set( succeeded );
	const failed = [];

	( Array.isArray( data?.failed ) ? data.failed : [] ).forEach( ( entry ) => {
		const [ id ] = normalizePostIds( [ entry?.post_id ] );

		if ( ! id || ! sent.has( id ) || done.has( id ) ) {
			return;
		}

		done.add( id );
		failed.push( {
			post_id: id,
			code:
				typeof entry.code === 'string' && entry.code
					? entry.code
					: 'unknown_error',
			message:
				typeof entry.message === 'string' && entry.message
					? entry.message
					: __(
							'An unknown error occurred.',
							'sapphireit-editorial-workflow'
						),
		} );
	} );

	postIds.forEach( ( id ) => {
		if ( ! done.has( id ) ) {
			failed.push( {
				post_id: id,
				code: MISSING_RESULT,
				message: __(
					'The server did not report a result for this item.',
					'sapphireit-editorial-workflow'
				),
			} );
		}
	} );

	return {
		succeeded,
		failed,
		items: Array.isArray( data?.items ) ? data.items : [],
	};
}

/**
 * Summary notice for a batch result. A batch with any failure is never a
 * success: it is a warning when some items were updated, an error otherwise.
 *
 * @param {{succeeded: number[], failed: Object[]}} result Reconciled result.
 * @return {{status: string, message: string}} Notice status and text.
 */
export function summarizeResult( result ) {
	const updated = result.succeeded.length;
	const failed = result.failed.length;
	const parts = [
		updated
			? sprintf(
					/* translators: %d: Number of updated posts. */
					_n(
						'%d post updated.',
						'%d posts updated.',
						updated,
						'sapphireit-editorial-workflow'
					),
					updated
				)
			: __( 'No posts were updated.', 'sapphireit-editorial-workflow' ),
	];

	if ( failed ) {
		parts.push(
			sprintf(
				/* translators: %d: Number of posts that could not be updated. */
				_n(
					'%d could not be updated.',
					'%d could not be updated.',
					failed,
					'sapphireit-editorial-workflow'
				),
				failed
			)
		);
	}

	let status = 'success';

	if ( failed ) {
		status = updated ? 'warning' : 'error';
	}

	return { status, message: parts.join( ' ' ) };
}

/**
 * Runs bulk actions one batch at a time.
 *
 * `run()` resolves with the reconciled result, also exposed as `result` with
 * its summary (`status`, `message`), the `labels` passed in and a unique `id`.
 * A whole-request failure marks every id as failed with the request's error.
 * Calls made while a batch is running are ignored (resolve to null), so a
 * double submit never sends a second request.
 *
 * @param {Object}   [options]            Options.
 * @param {Function} [options.onComplete] Called with the result after every batch.
 * @return {{run: Function, isRunning: boolean, result: ?Object, clearResult: Function}} Bulk state.
 */
export default function useBulkAction( { onComplete } = {} ) {
	const [ isRunning, setIsRunning ] = useState( false );
	const [ result, setResult ] = useState( null );
	const runningRef = useRef( false );
	const runCountRef = useRef( 0 );

	/**
	 * Sends one batch.
	 *
	 * @param {string} action           Bulk action.
	 * @param {Object} payload          Action payload.
	 * @param {Array}  postIds          Post ids.
	 * @param {Object} [options]        Options.
	 * @param {Object} [options.labels] Post id → title, for the failure list.
	 * @return {Promise<?Object>} Result, or null when nothing was sent.
	 */
	const run = useCallback(
		async ( action, payload, postIds, { labels = {} } = {} ) => {
			const ids = normalizePostIds( postIds );

			if (
				runningRef.current ||
				! BULK_ACTIONS.includes( action ) ||
				! ids.length
			) {
				return null;
			}

			runningRef.current = true;
			setIsRunning( true );
			setResult( null );

			let outcome;

			try {
				outcome = reconcileResult(
					await batchUpdate( ids, action, payload ),
					ids
				);
			} catch ( err ) {
				outcome = {
					succeeded: [],
					failed: ids.map( ( id ) => ( {
						post_id: id,
						code: err.code,
						message: err.message,
					} ) ),
					items: [],
					error: err,
				};
			}

			runCountRef.current += 1;
			outcome = {
				...outcome,
				...summarizeResult( outcome ),
				id: runCountRef.current,
				action,
				labels,
			};

			runningRef.current = false;
			setIsRunning( false );
			setResult( outcome );

			if ( onComplete ) {
				onComplete( outcome );
			}

			return outcome;
		},
		[ onComplete ]
	);

	const clearResult = useCallback( () => setResult( null ), [] );

	return { run, isRunning, result, clearResult };
}
