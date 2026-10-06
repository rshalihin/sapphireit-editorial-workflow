/**
 * Classic editor workflow meta box entry point.
 *
 * Enqueued only on classic-editor screens of workflow-enabled post types the
 * current user may edit (`Sit_Cwm\Editor\ClassicMetaBox`), with `window.sitCwm`
 * printed before it. Mounts into `#sit-cwm-classic-root`; if a plugin removed
 * or moved the meta box, it does nothing.
 */

/**
 * WordPress dependencies
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';

/**
 * Internal dependencies
 */
import ClassicPanel from './ClassicPanel';
// The panel's shared control styles live with the sidebar entry.
import '../sidebar/sidebar.scss';
import './classic.scss';

/**
 * Root element printed by `ClassicMetaBox::render()`.
 *
 * @type {string}
 */
export const ROOT_ID = 'sit-cwm-classic-root';

/**
 * Mounts the panel, if the root element is on the page.
 *
 * @return {boolean} Whether the panel was mounted.
 */
export function mount() {
	const root = document.getElementById( ROOT_ID );

	if ( ! root ) {
		return false;
	}

	const postId = parseInt( root.dataset.postId, 10 );
	const postType = root.dataset.postType || '';

	if ( ! postId ) {
		return false;
	}

	createRoot( root ).render(
		<ClassicPanel postId={ postId } postType={ postType } />
	);

	return true;
}

domReady( mount );
