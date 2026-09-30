/**
 * One button per transition the server offers the current user.
 *
 * The list comes from `available_transitions`; this component never works out
 * which moves are allowed.
 */

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ConfirmDialog from '../../components/ConfirmDialog';
import {
	getTransitionConfirmation,
	requiresConfirmation,
} from '../../utils/confirmations';

export { requiresConfirmation };

/**
 * Button label of a transition.
 *
 * @param {Object} transition `{ slug, label }`.
 * @return {string} Label.
 */
function actionLabel( transition ) {
	return sprintf(
		/* translators: %s: Target workflow status label. */
		__( 'Move to %s', 'sapphireit-editorial-workflow' ),
		transition.label
	);
}

/**
 * @param {Object}   props              Props.
 * @param {Object[]} props.transitions  Available transitions.
 * @param {Function} props.onTransition Receives the target status slug.
 * @param {boolean}  props.isSaving     Whether a workflow change is in flight.
 * @param {boolean}  [props.isDisabled] Disables every action (e.g. post gone).
 * @return {Element} Actions.
 */
export default function TransitionActions( {
	transitions,
	onTransition,
	isSaving,
	isDisabled = false,
} ) {
	const [ pending, setPending ] = useState( null );
	const [ activeSlug, setActiveSlug ] = useState( null );
	const groupRef = useRef( null );

	if ( ! Array.isArray( transitions ) || transitions.length === 0 ) {
		return (
			<p className="sit-cwm-help">
				{ __(
					'No workflow actions are available to you right now. The actions offered depend on the current status and your role.',
					'sapphireit-editorial-workflow'
				) }
			</p>
		);
	}

	// The clicked button is disabled while saving and may be replaced once the
	// status changes; keep keyboard focus in the actions rather than on <body>.
	const restoreFocus = () => {
		window.requestAnimationFrame( () => {
			const group = groupRef.current;

			if ( ! group ) {
				return;
			}

			const { activeElement, body } = group.ownerDocument;

			if ( activeElement && activeElement !== body ) {
				return;
			}

			( group.querySelector( 'button:not([disabled])' ) || group ).focus();
		} );
	};

	const run = async ( transition ) => {
		setActiveSlug( transition.slug );

		try {
			await onTransition( transition.slug );
		} finally {
			setActiveSlug( null );
			restoreFocus();
		}
	};

	const confirmation = pending ? getTransitionConfirmation( pending ) : null;

	return (
		<>
			<div
				ref={ groupRef }
				className="sit-cwm-actions"
				role="group"
				aria-label={ __( 'Workflow actions', 'sapphireit-editorial-workflow' ) }
				tabIndex={ -1 }
			>
				{ transitions.map( ( transition ) => (
					<Button
						key={ transition.slug }
						variant={
							transition.is_rollback ? 'secondary' : 'primary'
						}
						isDestructive={ !! transition.is_rollback }
						isBusy={ activeSlug === transition.slug }
						disabled={ isSaving || isDisabled }
						onClick={ () =>
							requiresConfirmation( transition )
								? setPending( transition )
								: run( transition )
						}
					>
						{ actionLabel( transition ) }
					</Button>
				) ) }
			</div>

			{ confirmation && (
				<ConfirmDialog
					title={ confirmation.title }
					confirmLabel={ confirmation.confirmLabel }
					isDestructive={ confirmation.isDestructive }
					onCancel={ () => setPending( null ) }
					onConfirm={ () => {
						const transition = pending;

						setPending( null );
						run( transition );
					} }
				>
					{ confirmation.message && <p>{ confirmation.message }</p> }
				</ConfirmDialog>
			) }
		</>
	);
}
