/**
 * Admin dashboard app.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Snackbars from '../components/Snackbars';
import WorkflowDataViews from './WorkflowDataViews';

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
