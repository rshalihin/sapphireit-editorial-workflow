/**
 * Workflow controls for one post, shared by the block editor sidebar and the
 * classic editor meta box.
 */

/**
 * WordPress dependencies
 */
import {
	Notice,
	PanelBody,
	Spinner,
	VisuallyHidden,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { isNetworkError } from '../api/client';
import useWorkflow from '../hooks/useWorkflow';
import CommentForm from '../sidebar/components/CommentForm';
import DueDateControl from '../sidebar/components/DueDateControl';
import ReviewerControl from '../sidebar/components/ReviewerControl';
import StatusControl from '../sidebar/components/StatusControl';
import TransitionActions from '../sidebar/components/TransitionActions';
import { getStatusDefinition } from '../utils/format';
import ActivityTimeline from './ActivityTimeline';

/**
 * Shown when the post has no workflow.
 *
 * @return {Element} Notice.
 */
function Unavailable() {
	return (
		<PanelBody>
			<Notice status="info" isDismissible={ false }>
				{ __(
					'Workflow is not available for this post type. An administrator can enable it in the Editorial Workflow settings.',
					'sapphireit-editorial-workflow'
				) }
			</Notice>
		</PanelBody>
	);
}

/**
 * Shown when the first load failed.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.error   Normalized error.
 * @param {Function} props.onRetry Reloads the workflow.
 * @return {Element} Panel.
 */
function LoadError( { error, onRetry } ) {
	if ( error.status === 401 || error.status === 403 ) {
		return (
			<PanelBody>
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'You don’t have permission to view the workflow of this content.',
						'sapphireit-editorial-workflow'
					) }
				</Notice>
			</PanelBody>
		);
	}

	return (
		<PanelBody>
			<Notice
				status="error"
				isDismissible={ false }
				actions={ [
					{
						label: __( 'Retry', 'sapphireit-editorial-workflow' ),
						onClick: onRetry,
						variant: 'secondary',
					},
				] }
			>
				{ error.message }
			</Notice>
		</PanelBody>
	);
}

/**
 * Workflow controls for one post. In the block editor it is mounted only while
 * the sidebar is open; in the classic editor it lives in the meta box.
 *
 * The root keeps the `sit-cwm-sidebar` class in both editors so the shared
 * styles and e2e selectors apply; `className` is appended to it.
 *
 * @param {Object}  props             Props.
 * @param {number}  props.postId      Post id.
 * @param {string}  [props.className] Extra class for the root element.
 * @param {boolean} [props.isClassic] Rendered in the classic editor meta box.
 * @return {Element} Panel.
 */
function WorkflowPanel( { postId, className, isClassic = false } ) {
	const {
		workflow,
		isLoading,
		isSaving,
		isGone,
		error,
		activityVersion,
		updateStatus,
		assignReviewer,
		setDueDate,
		addComment,
		refresh,
		clearError,
	} = useWorkflow( postId );
	const [ isGoneDismissed, setIsGoneDismissed ] = useState( false );

	if ( ! workflow && isLoading ) {
		return (
			<div className="sit-cwm-sidebar-loading">
				<Spinner />
				<VisuallyHidden>
					{ __( 'Loading workflow…', 'sapphireit-editorial-workflow' ) }
				</VisuallyHidden>
			</div>
		);
	}

	if ( ! workflow ) {
		if ( ! error || error.status === 404 ) {
			return <Unavailable />;
		}

		return <LoadError error={ error } onRetry={ refresh } />;
	}

	const capabilities = workflow.capabilities || {};
	const definition = getStatusDefinition( workflow.status );
	const isLocked = isSaving || isGone;
	const canChangeAnything =
		!! capabilities.can_change_status ||
		!! capabilities.can_assign_reviewer ||
		!! capabilities.can_set_due_date ||
		!! capabilities.can_comment;
	// A refetch (after a 403 or 409, or Retry) keeps the controls in place.
	const isRefreshing = isLoading && ! isSaving;

	return (
		<div
			className={ [ 'sit-cwm-sidebar', className ]
				.filter( Boolean )
				.join( ' ' ) }
			aria-busy={ isLoading || isSaving }
		>
			{ isGone && ! isGoneDismissed && (
				<Notice
					className="sit-cwm-sidebar-notice"
					status="warning"
					isDismissible
					onRemove={ () => setIsGoneDismissed( true ) }
				>
					{ __(
						'This content was deleted or is no longer part of the workflow. Workflow controls are disabled.',
						'sapphireit-editorial-workflow'
					) }
				</Notice>
			) }

			{ error && ! isGone && (
				<Notice
					className="sit-cwm-sidebar-notice"
					status="error"
					isDismissible
					onRemove={ clearError }
					actions={
						isNetworkError( error )
							? [
									{
										label: __( 'Retry', 'sapphireit-editorial-workflow' ),
										onClick: () => {
											clearError();
											refresh();
										},
										variant: 'secondary',
									},
							  ]
							: []
					}
				>
					{ error.message }
				</Notice>
			) }

			{ workflow.status_is_unknown && (
				<Notice
					className="sit-cwm-sidebar-notice"
					status="warning"
					isDismissible={ false }
				>
					{ sprintf(
						/* translators: %s: Default workflow status label, e.g. "Draft". */
						__(
							'This content had a workflow status that is no longer available, so it is treated as %s. Use the workflow actions below to continue.',
							'sapphireit-editorial-workflow'
						),
						workflow.status_label
					) }
				</Notice>
			) }

			{ ! canChangeAnything && ! isGone && (
				<Notice
					className="sit-cwm-sidebar-notice"
					status="info"
					isDismissible={ false }
				>
					{ __(
						'You can follow this workflow, but your role doesn’t allow you to change it.',
						'sapphireit-editorial-workflow'
					) }
				</Notice>
			) }

			{ isRefreshing && (
				<div className="sit-cwm-sidebar-refreshing">
					<Spinner />
					<span>{ __( 'Refreshing…', 'sapphireit-editorial-workflow' ) }</span>
				</div>
			) }

			<PanelBody>
				<StatusControl
					status={ workflow.status }
					label={ workflow.status_label }
				/>

				{ capabilities.can_assign_reviewer && (
					<ReviewerControl
						postId={ postId }
						reviewer={ workflow.reviewer }
						onChange={ assignReviewer }
						isSaving={ isLocked }
					/>
				) }

				<DueDateControl
					value={ workflow.due_date }
					onChange={ setDueDate }
					canEdit={ !! capabilities.can_set_due_date }
					isSaving={ isLocked }
					isComplete={ !! definition?.is_final }
				/>
			</PanelBody>

			<PanelBody title={ __( 'Workflow actions', 'sapphireit-editorial-workflow' ) }>
				<TransitionActions
					transitions={ workflow.available_transitions }
					onTransition={ updateStatus }
					isSaving={ isSaving }
					isDisabled={ isGone }
				/>
			</PanelBody>

			{ capabilities.can_comment && (
				<PanelBody title={ __( 'Comment', 'sapphireit-editorial-workflow' ) }>
					<CommentForm
						onSubmit={ addComment }
						isSaving={ isSaving }
						isDisabled={ isGone }
					/>
				</PanelBody>
			) }

			{ /* Not mounted at all without the capability, so no request is made. */ }
			{ capabilities.can_view_activity && (
				<PanelBody title={ __( 'Activity', 'sapphireit-editorial-workflow' ) }>
					<ActivityTimeline
						postId={ postId }
						version={ activityVersion }
					/>
				</PanelBody>
			) }
		</div>
	);
}

export { Unavailable };
export default WorkflowPanel;
