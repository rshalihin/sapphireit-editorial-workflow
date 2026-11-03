/**
 * Admin dashboard app.
 */

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
			{ /* The page's header and <h1> are printed by PHP (AdminShell). */ }
			<div className="sit-cwm-dashboard-body">
				<WorkflowDataViews />
			</div>
			<Snackbars />
		</div>
	);
}
