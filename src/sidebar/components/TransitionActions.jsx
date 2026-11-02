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
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import AttestationCheckbox from '../../components/AttestationCheckbox';
import ConfirmDialog from '../../components/ConfirmDialog';
import { ATTESTED_STATUS } from '../../utils/attestation';
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
export function actionLabel( transition ) {
	return sprintf(
		/* translators: %s: Target workflow status label. */
		__( 'Move to %s', 'sapphireit-editorial-workflow' ),
		transition.label
	);
}

/**
 * Whether the server still offers a transition.
 *
 * @param {Object[]} transitions Available transitions.
 * @param {Object}   transition  `{ slug }`.
 * @return {boolean} Whether it is offered.
 */
function isOffered( transitions, transition ) {
	return (
		Array.isArray( transitions ) &&
		transitions.some( ( { slug } ) => slug === transition.slug )
	);
}

/**
 * A click that lands while another workflow change (reviewer, due date,
 * comment) is saving is queued and runs once that save finishes, instead of
 * being swallowed by a disabled button. It is dropped if the transition is no
 * longer offered by then.
 *
 * When the post requires an attestation, Approve stays disabled until the
 * approver ticks the statement, before any confirmation dialog. The tick is
 * cleared after each approval and whenever Approve stops being offered.
 *
 * @param {Object}   props               Props.
 * @param {Object[]} props.transitions   Available transitions.
 * @param {Function} props.onTransition  Receives the target status slug, plus
 *                                       `{ attested: true }` for an attested
 *                                       approval.
 * @param {boolean}  props.isSaving      Whether a workflow change is in flight.
 * @param {boolean}  [props.isDisabled]  Disables every action (e.g. post gone).
 * @param {Object}   [props.attestation] `{ required, statement }` from the payload.
 * @return {Element} Actions.
 */
export default function TransitionActions( {
	transitions,
	onTransition,
	isSaving,
	isDisabled = false,
	attestation = { required: false, statement: '' },
} ) {
	const [ pending, setPending ] = useState( null );
	const [ activeSlug, setActiveSlug ] = useState( null );
	const [ queued, setQueued ] = useState( null );
	const [ attested, setAttested ] = useState( false );
	const groupRef = useRef( null );

	const approvalOffered = isOffered( transitions, { slug: ATTESTED_STATUS } );
	const needsAttestation = true === attestation?.required && approvalOffered;

	// A tick confirms one approval of the content shown now.
	useEffect( () => {
		if ( ! approvalOffered ) {
			setAttested( false );
		}
	}, [ approvalOffered ] );

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
		const isApproval = transition.slug === ATTESTED_STATUS;

		setActiveSlug( transition.slug );

		try {
			if ( isApproval && attested ) {
				await onTransition( transition.slug, { attested: true } );
			} else {
				await onTransition( transition.slug );
			}
		} finally {
			if ( isApproval ) {
				setAttested( false );
			}

			setActiveSlug( null );
			restoreFocus();
		}
	};

	// Runs now, or once the save in flight finishes.
	const start = ( transition ) => {
		if ( isSaving ) {
			setQueued( transition );
		} else {
			run( transition );
		}
	};

	// Runs the queued transition after the other save. A queued transition or
	// open confirmation that the server no longer offers is dropped.
	useEffect( () => {
		if ( pending && ! isOffered( transitions, pending ) ) {
			setPending( null );
		}

		if ( ! queued || isSaving ) {
			return;
		}

		setQueued( null );

		if ( ! isDisabled && isOffered( transitions, queued ) ) {
			run( queued );
		}
		// `run` is rebuilt every render; the queue only advances on these.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ queued, pending, isSaving, isDisabled, transitions ] );

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

	const confirmation = pending ? getTransitionConfirmation( pending ) : null;

	const isBusy = isDisabled || activeSlug !== null || queued !== null;

	return (
		<>
			{ needsAttestation && (
				<AttestationCheckbox
					statement={ attestation.statement }
					checked={ attested }
					onChange={ setAttested }
					disabled={ isBusy }
				/>
			) }

			<div
				ref={ groupRef }
				className="sit-cwm-actions"
				role="group"
				aria-label={ __( 'Workflow actions', 'sapphireit-editorial-workflow' ) }
				tabIndex={ -1 }
			>
				{ transitions.map( ( transition ) => {
					// Stays focusable so the approver can find out why.
					const awaitsAttestation =
						needsAttestation &&
						! attested &&
						transition.slug === ATTESTED_STATUS;

					return (
						<Button
							key={ transition.slug }
							variant={
								transition.is_rollback ? 'secondary' : 'primary'
							}
							isDestructive={ !! transition.is_rollback }
							isBusy={
								activeSlug === transition.slug ||
								queued?.slug === transition.slug
							}
							// Only a running or queued transition blocks the
							// others; any other save in flight just queues the
							// click.
							disabled={ isBusy || awaitsAttestation }
							accessibleWhenDisabled={ ! isBusy && awaitsAttestation }
							onClick={ () =>
								requiresConfirmation( transition )
									? setPending( transition )
									: start( transition )
							}
						>
							{ actionLabel( transition ) }
						</Button>
					);
				} ) }
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
						start( transition );
					} }
				>
					{ confirmation.message && <p>{ confirmation.message }</p> }
				</ConfirmDialog>
			) }
		</>
	);
}
