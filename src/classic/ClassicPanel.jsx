/**
 * Workflow controls in the classic editor's "Editorial Workflow" meta box.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Snackbars from '../components/Snackbars';
import WorkflowPanel, { Unavailable } from '../components/WorkflowPanel';
import { isEnabledPostType } from '../utils/format';

/**
 * Stops Enter in a text input from submitting the surrounding `<form id="post">`.
 *
 * Only the default action is prevented, never propagation. This handler sits on
 * an ancestor, so controls such as `ComboboxControl` have already handled the
 * key by the time it runs. Textareas (newlines) and buttons (activation) are
 * left alone.
 *
 * @param {KeyboardEvent} event Key event bubbling up from the panel.
 */
export function stopEnterSubmit( event ) {
	if (
		event.key === 'Enter' &&
		event.target instanceof window.HTMLInputElement
	) {
		event.preventDefault();
	}
}

/**
 * @param {Object} props          Props.
 * @param {number} props.postId   Post id.
 * @param {string} props.postType Post type slug.
 * @return {Element} Meta box content.
 */
export default function ClassicPanel( { postId, postType } ) {
	// PHP only adds the meta box for enabled types; this is a second check.
	if ( ! isEnabledPostType( postType ) ) {
		return <Unavailable />;
	}

	return (
		// The wrapper only guards Enter key presses that bubble up from the
		// controls inside it; it is not interactive itself.
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<div className="sit-cwm-classic" onKeyDown={ stopEnterSubmit }>
			<p className="sit-cwm-classic__hint">
				{ __(
					'Workflow changes are saved immediately. You don’t need to click Update.',
					'sapphireit-editorial-workflow'
				) }
			</p>
			<WorkflowPanel
				postId={ postId }
				isClassic
				className="sit-cwm-workflow-panel--classic"
			/>
			<Snackbars className="sit-cwm-snackbars sit-cwm-snackbars--classic" />
		</div>
	);
}
