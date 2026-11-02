/**
 * Transitions an extension refused the current user, each with its reason.
 *
 * The list comes from `blocked_transitions`; the reason is the extension's own
 * message, shown word for word as plain text.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';

/**
 * Internal dependencies
 */
import { actionLabel } from '../sidebar/components/TransitionActions';

/**
 * @param {Object}   props         Props.
 * @param {Object[]} props.blocked `[ { slug, label, code, message } ]`.
 * @return {?Element} Blocked actions, or nothing when there are none.
 */
export default function BlockedTransitions( { blocked } ) {
	if ( ! Array.isArray( blocked ) || blocked.length === 0 ) {
		return null;
	}

	return (
		<div className="sit-cwm-blocked">
			{ blocked.map( ( transition ) => {
				const reasonId = `sit-cwm-blocked-${ transition.slug }`;

				return (
					<div
						key={ transition.slug }
						className="sit-cwm-blocked__item"
					>
						<Button
							variant="secondary"
							disabled
							accessibleWhenDisabled
							aria-describedby={ reasonId }
						>
							{ actionLabel( transition ) }
						</Button>
						<p id={ reasonId } className="sit-cwm-blocked__reason">
							{ transition.message }
						</p>
					</div>
				);
			} ) }
		</div>
	);
}
