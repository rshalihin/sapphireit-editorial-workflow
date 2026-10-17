/**
 * Read-only hero for the current workflow status, with a pipeline rail.
 *
 * Status changes go through `TransitionActions`, never a free-form select: a
 * dropdown of every status invites requests the server would reject.
 *
 * The rail is decorative: it repeats the pipeline order the status registry
 * already exposes, and carries no text (the label above is the accessible
 * source), so assistive tech is not read a list of unlabelled dots.
 */

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	FALLBACK_STATUS_COLOR,
	getBootstrap,
	getStatusDefinition,
} from '../../utils/format';

/**
 * The registered statuses, in pipeline order.
 *
 * @return {Object[]} Status definitions; empty when bootstrap data is missing.
 */
function orderedStatuses() {
	const { statuses } = getBootstrap();

	return Array.isArray( statuses ) ? statuses : [];
}

/**
 * @param {Object} props         Props.
 * @param {string} props.status  Status slug.
 * @param {string} [props.label] Label from the workflow response.
 * @return {Element} Hero.
 */
export default function StatusControl( { status, label } ) {
	const definition = getStatusDefinition( status );
	const color = definition?.color || FALLBACK_STATUS_COLOR;
	const text = label || definition?.label || status;
	const currentOrder = definition?.order;
	const statuses = orderedStatuses();

	return (
		<div
			className="sit-cwm-field sit-cwm-status"
			style={ { '--sit-cwm-status-color': color } }
		>
			<span className="sit-cwm-field-label">
				{ __( 'Status', 'sapphireit-editorial-workflow' ) }
			</span>
			<span className="sit-cwm-status-badge" data-status={ status }>
				<span
					className="sit-cwm-status-swatch"
					style={ { backgroundColor: color } }
					aria-hidden="true"
				/>
				<span className="sit-cwm-status-text">{ text }</span>
			</span>

			{ statuses.length > 1 && (
				<div className="sit-cwm-pipeline" aria-hidden="true">
					{ statuses.map( ( entry ) => {
						const isCurrent = entry.slug === status;
						const isDone =
							! isCurrent &&
							typeof currentOrder === 'number' &&
							typeof entry.order === 'number' &&
							entry.order < currentOrder;
						const className = [
							'sit-cwm-pipeline__step',
							isCurrent ? 'is-current' : '',
							isDone ? 'is-done' : '',
						]
							.filter( Boolean )
							.join( ' ' );

						return (
							<span
								key={ entry.slug }
								className={ className }
								style={ {
									'--sit-cwm-step-color':
										entry.color || FALLBACK_STATUS_COLOR,
								} }
							>
								<span className="sit-cwm-pipeline__dot" />
							</span>
						);
					} ) }
				</div>
			) }

			{ definition?.description && (
				<p className="sit-cwm-help">{ definition.description }</p>
			) }
		</div>
	);
}
