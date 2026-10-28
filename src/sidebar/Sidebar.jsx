/**
 * The "Editorial Workflow" editor sidebar.
 */

/**
 * WordPress dependencies
 */
import { useSelect } from '@wordpress/data';
import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
	store as editorStore,
} from '@wordpress/editor';
import { __ } from '@wordpress/i18n';
import { seen } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import WorkflowPanel, { Unavailable } from '../components/WorkflowPanel';
import useRefreshEditorPost from '../hooks/useRefreshEditorPost';
import { isEnabledPostType } from '../utils/format';

/**
 * Plugin name passed to `registerPlugin()`.
 *
 * @type {string}
 */
export const PLUGIN_NAME = 'sit-cwm-sidebar';

/**
 * Sidebar name within the plugin.
 *
 * @type {string}
 */
const SIDEBAR_NAME = 'workflow';

/**
 * Sidebar and its "more menu" entry.
 *
 * @return {Element} Plugin output.
 */
export default function Sidebar() {
	const { postId, postType } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
		};
	}, [] );
	// The classic editor's Publish button is rendered by PHP, so only the
	// block editor refreshes the post after a status change.
	const refreshEditorPost = useRefreshEditorPost();

	const title = __( 'Editorial Workflow', 'sapphireit-editorial-workflow' );

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME } icon={ seen }>
				{ title }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name={ SIDEBAR_NAME } title={ title } icon={ seen }>
				{ postId && isEnabledPostType( postType ) ? (
					<WorkflowPanel
						key={ postId }
						postId={ postId }
						onStatusChanged={ refreshEditorPost }
					/>
				) : (
					<Unavailable />
				) }
			</PluginSidebar>
		</>
	);
}
