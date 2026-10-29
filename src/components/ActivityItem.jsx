/**
 * One activity timeline entry.
 */

/**
 * WordPress dependencies
 */
import { dateI18n, getSettings } from '@wordpress/date';
import { createInterpolateElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { cog, commentAuthorAvatar, Icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { decodeText } from '../utils/format';

/**
 * Avatar size in pixels.
 *
 * @type {number}
 */
const AVATAR_SIZE = 24;

/**
 * Elements that contribute no readable text.
 *
 * @type {string}
 */
const SILENT_ELEMENTS = 'script, style, template, noscript';

/**
 * Elements after which a line break is kept.
 *
 * @type {string}
 */
const BLOCK_ELEMENTS =
	'p, div, li, blockquote, pre, h1, h2, h3, h4, h5, h6, tr';

/**
 * Plain-text projection of a comment's post-safe HTML.
 *
 * The server's `wp_kses_post` is the guarantee; this keeps the client from
 * re-introducing markup. `DOMParser` builds an inert document, so nothing in
 * it runs or loads, and the result is rendered as a text node.
 *
 * @param {*} html Comment HTML.
 * @return {string} Text with paragraph and line breaks kept.
 */
export function toPlainText( html ) {
	if ( typeof html !== 'string' || html === '' ) {
		return '';
	}

	const doc = new window.DOMParser().parseFromString( html, 'text/html' );

	doc.querySelectorAll( SILENT_ELEMENTS ).forEach( ( node ) =>
		node.remove()
	);
	doc.querySelectorAll( 'br' ).forEach( ( node ) =>
		node.replaceWith( '\n' )
	);
	doc.querySelectorAll( BLOCK_ELEMENTS ).forEach( ( node ) =>
		node.append( '\n' )
	);

	return ( doc.body.textContent || '' ).replace( /\n{3,}/g, '\n\n' ).trim();
}

/**
 * Display name of the acting user.
 *
 * @param {Object} entry Activity entry.
 * @return {string} Name, `System` for `user_id` 0, `Someone` for a deleted user.
 */
export function actorName( entry ) {
	if ( entry.user?.name ) {
		return decodeText( entry.user.name );
	}

	if ( ! entry.user && entry.user_id === 0 ) {
		return __( 'System', 'sapphireit-editorial-workflow' );
	}

	return __( 'Someone', 'sapphireit-editorial-workflow' );
}

/**
 * Translated sentence template for an entry.
 *
 * Placeholders are filled with `<actor />`, `<from />`, `<to />`,
 * `<wfstatus />` and `<label />` tags, which `createInterpolateElement` swaps
 * for elements, so names and labels never become part of the string being
 * parsed. An entry missing a label its sentence needs gets the generic
 * "actor: action label" sentence.
 *
 * @param {Object} entry Activity entry.
 * @return {string} Template.
 */
function sentenceTemplate( entry ) {
	const actor = '<actor />';
	const from = '<from />';
	const to = '<to />';
	const wfstatus = '<wfstatus />';

	switch ( entry.action ) {
		case 'status_changed':
			if ( entry.old_label && entry.new_label ) {
				return sprintf(
					/* translators: 1: user name, 2: previous status, 3: new status */
					__(
						'%1$s changed status from %2$s to %3$s',
						'sapphireit-editorial-workflow'
					),
					actor,
					from,
					to
				);
			}

			return sprintf(
				/* translators: 1: user name, 2: new status */
				__(
					'%1$s set the status to %2$s',
					'sapphireit-editorial-workflow'
				),
				actor,
				to
			);

		case 'reviewer_assigned':
			return sprintf(
				/* translators: 1: user name, 2: reviewer name */
				__(
					'%1$s assigned %2$s as reviewer',
					'sapphireit-editorial-workflow'
				),
				actor,
				to
			);

		case 'reviewer_cleared':
			if ( entry.old_label ) {
				return sprintf(
					/* translators: 1: user name, 2: reviewer name */
					__(
						'%1$s removed %2$s as reviewer',
						'sapphireit-editorial-workflow'
					),
					actor,
					from
				);
			}

			return sprintf(
				/* translators: %s: user name */
				__(
					'%s removed the reviewer',
					'sapphireit-editorial-workflow'
				),
				actor
			);

		case 'due_date_set':
			return sprintf(
				/* translators: 1: user name, 2: due date */
				__(
					'%1$s set the due date to %2$s',
					'sapphireit-editorial-workflow'
				),
				actor,
				to
			);

		case 'due_date_cleared':
			return sprintf(
				/* translators: %s: user name */
				__(
					'%s removed the due date',
					'sapphireit-editorial-workflow'
				),
				actor
			);

		case 'comment_added':
			return sprintf(
				/* translators: %s: user name */
				__( '%s commented', 'sapphireit-editorial-workflow' ),
				actor
			);

		case 'post_published':
			if ( ! entry.workflow_status_label ) {
				break;
			}

			if ( entry.user_id === 0 && entry.old_value === 'future' ) {
				return sprintf(
					/* translators: 1: user name, usually "System", 2: workflow status, e.g. "Approved" */
					__(
						'%1$s published this on schedule while it was at %2$s',
						'sapphireit-editorial-workflow'
					),
					actor,
					wfstatus
				);
			}

			return sprintf(
				/* translators: 1: user name, 2: workflow status, e.g. "Review" */
				__(
					'%1$s published this while it was at %2$s',
					'sapphireit-editorial-workflow'
				),
				actor,
				wfstatus
			);

		case 'post_unpublished':
			if ( ! entry.new_label ) {
				break;
			}

			return sprintf(
				/* translators: 1: user name, 2: new post status, e.g. "Draft" */
				__(
					'%1$s unpublished this (now %2$s)',
					'sapphireit-editorial-workflow'
				),
				actor,
				to
			);

		case 'publish_gate_refused':
			if ( ! entry.workflow_status_label ) {
				break;
			}

			return sprintf(
				/* translators: 1: user name, 2: workflow status, e.g. "Writing" */
				__(
					'Publishing by %1$s was blocked: it was at %2$s, not approved',
					'sapphireit-editorial-workflow'
				),
				actor,
				wfstatus
			);

		case 'publish_gate_bypassed':
			if ( entry.gate_reason === 'error' ) {
				return sprintf(
					/* translators: %s: user name */
					__(
						'%s published this without the approval check (internal error)',
						'sapphireit-editorial-workflow'
					),
					actor
				);
			}

			if ( ! entry.workflow_status_label ) {
				break;
			}

			return sprintf(
				/* translators: 1: user name, 2: workflow status, e.g. "Draft" */
				__(
					'%1$s published this without approval (it was at %2$s)',
					'sapphireit-editorial-workflow'
				),
				actor,
				wfstatus
			);
	}

	return sprintf(
		/* translators: 1: user name, 2: activity label, e.g. "Status changed" */
		__( '%1$s: %2$s', 'sapphireit-editorial-workflow' ),
		actor,
		'<label />'
	);
}

/**
 * Avatar image, or a generic icon for the system and deleted users.
 *
 * @param {Object} props       Props.
 * @param {Object} props.entry Activity entry.
 * @return {Element} Avatar.
 */
function Avatar( { entry } ) {
	if ( entry.user?.avatar ) {
		return (
			<img
				className="sit-cwm-avatar sit-cwm-activity-avatar"
				src={ entry.user.avatar }
				alt=""
				width={ AVATAR_SIZE }
				height={ AVATAR_SIZE }
				loading="lazy"
			/>
		);
	}

	const isSystem = ! entry.user && entry.user_id === 0;

	return (
		<span className="sit-cwm-activity-avatar is-generic" aria-hidden="true">
			<Icon icon={ isSystem ? cog : commentAuthorAvatar } size={ 20 } />
		</span>
	);
}

/**
 * @param {Object} props       Props.
 * @param {Object} props.entry Activity entry from the REST API.
 * @return {Element} List item.
 */
export default function ActivityItem( { entry } ) {
	const sentence = createInterpolateElement( sentenceTemplate( entry ), {
		actor: (
			<strong className="sit-cwm-activity-actor">
				{ actorName( entry ) }
			</strong>
		),
		from: <strong>{ entry.old_label || '' }</strong>,
		to: <strong>{ entry.new_label || '' }</strong>,
		wfstatus: <strong>{ decodeText( entry.workflow_status_label ) }</strong>,
		label: <span>{ entry.action_label || entry.action || '' }</span>,
	} );
	const message = toPlainText( entry.message );
	const absolute = entry.created_at
		? dateI18n( getSettings().formats.datetime, entry.created_at )
		: '';

	return (
		<li className="sit-cwm-activity-item" data-action={ entry.action }>
			<span className="sit-cwm-activity-marker">
				<Avatar entry={ entry } />
				<span className="sit-cwm-activity-marker__dot" aria-hidden="true" />
			</span>
			<div className="sit-cwm-activity-body">
				<p className="sit-cwm-activity-sentence">{ sentence }</p>
				{ message && (
					<p className="sit-cwm-activity-message">{ message }</p>
				) }
				{ entry.created_at && (
					<time
						className="sit-cwm-activity-time"
						dateTime={ entry.created_at }
						title={ absolute }
					>
						{ entry.created_at_human || absolute }
					</time>
				) }
			</div>
		</li>
	);
}
