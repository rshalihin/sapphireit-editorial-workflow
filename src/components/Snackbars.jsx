/**
 * Snackbar notices, shared by the admin dashboard and the classic editor
 * meta box.
 */

/**
 * WordPress dependencies
 */
import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Snackbar notices from the core notices store.
 *
 * @param {Object} props
 * @param {string} [props.className] Class name for the list.
 * @return {Element} Snackbars.
 */
export default function Snackbars( { className = 'sit-cwm-snackbars' } ) {
	const notices = useSelect(
		( select ) =>
			select( noticesStore )
				.getNotices()
				.filter( ( notice ) => notice.type === 'snackbar' ),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );

	return (
		<SnackbarList
			className={ className }
			notices={ notices }
			onRemove={ removeNotice }
		/>
	);
}
