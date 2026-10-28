/**
 * One plain-language sentence about the publish gate, shown in the workflow
 * panel when the gate restricts or records publishing (E14).
 *
 * Display only: the server reports `publish_gate` and re-decides on save.
 */

/**
 * WordPress dependencies
 */
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Native post statuses whose updates the gate never judges (E4).
 *
 * @type {string[]}
 */
const LIVE_STATUSES = [ 'publish', 'future' ];

/**
 * Notice status and copy for a gate state, or null when nothing is said.
 *
 * @param {Object} gate `{ mode, outcome }`.
 * @return {?{status: string, message: string}} Notice, if any.
 */
function getNotice( gate ) {
	if ( gate.outcome === 'refused' && gate.mode === 'enforce' ) {
		return {
			status: 'info',
			message: __(
				'Publishing is locked until this content is approved.',
				'sapphireit-editorial-workflow'
			),
		};
	}

	if ( gate.outcome === 'bypassed' && gate.mode === 'log_only' ) {
		return {
			status: 'warning',
			message: __(
				'This content is not approved yet. Publishing it now will be recorded as a bypass of the review.',
				'sapphireit-editorial-workflow'
			),
		};
	}

	if ( gate.outcome === 'bypassed' && gate.mode === 'enforce' ) {
		return {
			status: 'warning',
			message: __(
				'This content is not approved yet. You can still publish it, and that will be recorded as a bypass of the review.',
				'sapphireit-editorial-workflow'
			),
		};
	}

	return null;
}

/**
 * @param {Object}  props            Props.
 * @param {?Object} props.gate       `publish_gate` from the workflow response;
 *                                   absent on servers older than 1.1.
 * @param {string}  props.postStatus Native post status.
 * @return {?Element} Notice, or null when nothing should be said.
 */
export default function PublishGateNotice( { gate, postStatus } ) {
	if ( ! gate || LIVE_STATUSES.includes( postStatus ) ) {
		return null;
	}

	const notice = getNotice( gate );

	if ( ! notice ) {
		return null;
	}

	return (
		<Notice
			className="sit-cwm-gate-notice"
			status={ notice.status }
			isDismissible={ false }
		>
			{ notice.message }
		</Notice>
	);
}
