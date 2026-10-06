/**
 * Gutenberg workflow sidebar entry point.
 *
 * Enqueued only for workflow-enabled post types the current user may edit
 * (`Sit_Cwm\Editor\SidebarAssets`), with `window.sitCwm` printed before it.
 */

/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import Sidebar, { PLUGIN_NAME } from './Sidebar';
import './sidebar.scss';

registerPlugin( PLUGIN_NAME, { render: Sidebar } );
