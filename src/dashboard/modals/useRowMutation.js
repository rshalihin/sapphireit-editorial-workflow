/**
 * Runs one row action request from a DataViews modal.
 */

/**
 * WordPress dependencies
 */
import { useDispatch } from '@wordpress/data';
import { useCallback, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Statuses meaning the row the user acted on is stale: it changed elsewhere
 * (409), permissions changed (403) or it is gone (404). The modal closes and
 * the page is refetched instead of showing an inline error.
 *
 * @type {number[]}
 */
const STALE_STATUSES = [ 403, 404, 409 ];

/**
 * Shared submit logic for row action modals.
 *
 * The server is the source of truth: nothing is applied optimistically. On
 * success the modal closes, a snackbar is shown and `onChanged` refetches the
 * current page. Validation errors stay in the modal as `error`.
 *
 * @param {Object}   options            Options.
 * @param {Function} options.closeModal Closes the modal.
 * @param {Function} options.onChanged  Refetches the current page.
 * @return {{run: Function, isBusy: boolean, error: ?Object}} Result.
 */
export default function useRowMutation( { closeModal, onChanged } ) {
	const [ isBusy, setIsBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const busyRef = useRef( false );
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );

	/**
	 * Sends a request once; ignored while another one is running.
	 *
	 * @param {Function} send Performs the request; may resolve to
	 *                        `{ success, warning }` snackbar messages.
	 * @return {Promise<boolean>} Whether the request succeeded.
	 */
	const run = useCallback(
		async ( send ) => {
			if ( busyRef.current ) {
				return false;
			}

			busyRef.current = true;
			setIsBusy( true );
			setError( null );

			let result;

			try {
				result = ( await send() ) || {};
			} catch ( err ) {
				busyRef.current = false;

				if ( STALE_STATUSES.includes( err.status ) ) {
					createErrorNotice(
						err.status === 409
							? __(
									'This post changed elsewhere — refreshed.',
									'sapphireit-editorial-workflow'
								)
							: err.message,
						{ type: 'snackbar' }
					);
					closeModal();
					onChanged();

					return false;
				}

				setIsBusy( false );
				setError( err );

				return false;
			}

			busyRef.current = false;

			if ( result.warning ) {
				createErrorNotice( result.warning, { type: 'snackbar' } );
			} else if ( result.success ) {
				createSuccessNotice( result.success, { type: 'snackbar' } );
			}

			closeModal();
			onChanged();

			return true;
		},
		[ closeModal, onChanged, createSuccessNotice, createErrorNotice ]
	);

	return { run, isBusy, error };
}
