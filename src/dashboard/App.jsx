/**
 * Admin dashboard app.
 */

/**
 * WordPress dependencies
 */
import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import WorkflowDataViews from './WorkflowDataViews';

/**
 * Snackbar notices raised by row actions.
 *
 * @return {Element} Snackbars.
 */
function Snackbars() {
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
			className="sit-cwm-snackbars"
			notices={ notices }
			onRemove={ removeNotice }
		/>
	);
}

/**
 * @return {Element} App.
 */
export default function App() {
	return (
		<div className="sit-cwm-dashboard">
			{ /* The page's <h1> is printed by PHP for screen readers. */ }
			<div className="sit-cwm-dashboard-header" aria-hidden="true">
				{ __( 'Editorial Workflow', 'sapphireit-editorial-workflow' ) }
			</div>
			<div className="sit-cwm-dashboard-body">
				<WorkflowDataViews />
			</div>
			<Snackbars />
		</div>
	);
}
