/**
 * The "Content Workflow" editor sidebar.
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
import { useSelect } from '@wordpress/data';
import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
	store as editorStore,
} from '@wordpress/editor';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { seen } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { isNetworkError } from '../api/client';
import ActivityTimeline from '../components/ActivityTimeline';
import useWorkflow from '../hooks/useWorkflow';
import { getStatusDefinition, isEnabledPostType } from '../utils/format';
import CommentForm from './components/CommentForm';
import DueDateControl from './components/DueDateControl';
import ReviewerControl from './components/ReviewerControl';
import StatusControl from './components/StatusControl';
import TransitionActions from './components/TransitionActions';

/**
 * Plugin name passed to `registerPlugin()`.
 *
 * @type {string}
 */
export const PLUGIN_NAME = 'sit-cwm-sidebar';

/**
 * Sidebar name within the plugin.
 *
 * @type {string}
 */
const SIDEBAR_NAME = 'workflow';

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
					'Workflow is not available for this post type. An administrator can enable it in the Content Workflow settings.',
					'sit-cwm'
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
						'sit-cwm'
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
						label: __( 'Retry', 'sit-cwm' ),
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
 * Workflow controls for one post. Mounted only while the sidebar is open.
 *
 * @param {Object} props        Props.
 * @param {number} props.postId Post id.
 * @return {Element} Panel.
 */
function WorkflowPanel( { postId } ) {
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
					{ __( 'Loading workflow…', 'sit-cwm' ) }
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
		<div className="sit-cwm-sidebar" aria-busy={ isLoading || isSaving }>
			{ isGone && ! isGoneDismissed && (
				<Notice
					className="sit-cwm-sidebar-notice"
					status="warning"
					isDismissible
					onRemove={ () => setIsGoneDismissed( true ) }
				>
					{ __(
						'This content was deleted or is no longer part of the workflow. Workflow controls are disabled.',
						'sit-cwm'
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
										label: __( 'Retry', 'sit-cwm' ),
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
							'sit-cwm'
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
						'sit-cwm'
					) }
				</Notice>
			) }

			{ isRefreshing && (
				<div className="sit-cwm-sidebar-refreshing">
					<Spinner />
					<span>{ __( 'Refreshing…', 'sit-cwm' ) }</span>
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

			<PanelBody title={ __( 'Workflow actions', 'sit-cwm' ) }>
				<TransitionActions
					transitions={ workflow.available_transitions }
					onTransition={ updateStatus }
					isSaving={ isSaving }
					isDisabled={ isGone }
				/>
			</PanelBody>

			{ capabilities.can_comment && (
				<PanelBody title={ __( 'Comment', 'sit-cwm' ) }>
					<CommentForm
						onSubmit={ addComment }
						isSaving={ isSaving }
						isDisabled={ isGone }
					/>
				</PanelBody>
			) }

			{ /* Not mounted at all without the capability, so no request is made. */ }
			{ capabilities.can_view_activity && (
				<PanelBody title={ __( 'Activity', 'sit-cwm' ) }>
					<ActivityTimeline
						postId={ postId }
						version={ activityVersion }
					/>
				</PanelBody>
			) }
		</div>
	);
}

/**
 * Sidebar and its "more menu" entry.
 *
 * @return {Element} Plugin output.
 */
export default function Sidebar() {
	const { postId, postType } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
		};
	}, [] );

	const title = __( 'Content Workflow', 'sit-cwm' );

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME } icon={ seen }>
				{ title }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar name={ SIDEBAR_NAME } title={ title } icon={ seen }>
				{ postId && isEnabledPostType( postType ) ? (
					<WorkflowPanel key={ postId } postId={ postId } />
				) : (
					<Unavailable />
				) }
			</PluginSidebar>
		</>
	);
}
