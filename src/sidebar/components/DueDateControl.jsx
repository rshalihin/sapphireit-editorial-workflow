/**
 * Due date display and picker.
 */

/**
 * WordPress dependencies
 */
import { Button, DatePicker, Dropdown } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { caution, Icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { formatDate, isOverdue, isYmd, toYmd } from '../../utils/format';

/**
 * Warning icon for an overdue date, so the state is never shown by colour
 * alone. Decorative: the text already says "overdue".
 *
 * @return {Element} Icon.
 */
function OverdueIcon() {
	return (
		<Icon className="sit-cwm-overdue-icon" icon={ caution } size={ 16 } />
	);
}

/**
 * The picker stays usable while another workflow change is saving: opening it
 * changes nothing, and a date picked (or cleared) meanwhile is queued and sent
 * once that save finishes, instead of the click being swallowed by a disabled
 * button.
 *
 * @param {Object}   props            Props.
 * @param {string}   props.value      `Y-m-d` due date, or `''`.
 * @param {Function} props.onChange   Receives the new `Y-m-d` date (`''` clears).
 * @param {boolean}  props.canEdit    Whether the user may change it.
 * @param {boolean}  props.isSaving   Whether a workflow change is in flight.
 * @param {boolean}  props.isComplete Whether the workflow cycle is complete
 *                                    (a passed date is then not overdue).
 * @return {Element} Control.
 */
export default function DueDateControl( {
	value,
	onChange,
	canEdit,
	isSaving,
	isComplete,
} ) {
	// `Y-m-d`, or `''` to clear; `null` when nothing is waiting.
	const [ queued, setQueued ] = useState( null );

	useEffect( () => {
		if ( queued === null || isSaving ) {
			return;
		}

		setQueued( null );

		if ( queued !== value ) {
			onChange( queued );
		}
		// `onChange` may be rebuilt every render; the queue only advances on these.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ queued, isSaving ] );

	// Sends now, or once the save in flight finishes.
	const change = ( next ) => {
		if ( isSaving ) {
			setQueued( next );
		} else {
			onChange( next );
		}
	};

	const hasDate = isYmd( value );
	const overdue = hasDate && ! isComplete && isOverdue( value );
	const isQueued = queued !== null;

	let text = __( 'No due date', 'sapphireit-editorial-workflow' );

	if ( hasDate ) {
		text = overdue
			? sprintf(
					/* translators: %s: Due date. */
					__( '%s (overdue)', 'sapphireit-editorial-workflow' ),
					formatDate( value )
			  )
			: formatDate( value );
	}

	const valueClass = `sit-cwm-due-date-value${
		overdue ? ' is-overdue' : ''
	}`;

	return (
		<div className="sit-cwm-field sit-cwm-due-date">
			<span className="sit-cwm-field-label">
				{ __( 'Due date', 'sapphireit-editorial-workflow' ) }
			</span>

			{ ! canEdit && (
				<span className={ valueClass }>
					{ overdue && <OverdueIcon /> }
					{ text }
				</span>
			) }

			{ canEdit && (
				<div className="sit-cwm-due-date-row">
					<Dropdown
						popoverProps={ { placement: 'left-start' } }
						renderToggle={ ( { isOpen, onToggle } ) => (
							<Button
								className={ valueClass }
								variant="tertiary"
								onClick={ onToggle }
								aria-expanded={ isOpen }
								isBusy={ isQueued }
								label={ sprintf(
									/* translators: %s: Current due date, or "No due date". */
									__( 'Change due date: %s', 'sapphireit-editorial-workflow' ),
									text
								) }
								showTooltip={ false }
							>
								{ overdue && <OverdueIcon /> }
								{ text }
							</Button>
						) }
						renderContent={ ( { onClose } ) => (
							<div className="sit-cwm-due-date-picker">
								<DatePicker
									currentDate={
										hasDate ? `${ value }T00:00:00` : null
									}
									onChange={ ( next ) => {
										const ymd = toYmd( next );

										onClose();

										if ( ymd && ymd !== value ) {
											change( ymd );
										}
									} }
								/>
							</div>
						) }
					/>
					{ hasDate && (
						<Button
							variant="link"
							isDestructive
							onClick={ () => change( '' ) }
							disabled={ isQueued }
						>
							{ __( 'Clear', 'sapphireit-editorial-workflow' ) }
						</Button>
					) }
				</div>
			) }
		</div>
	);
}
