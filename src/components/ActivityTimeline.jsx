/**
 * A post's activity history, grouped by day and loaded page by page.
 *
 * Mount it only for users who may view the activity: mounting is what fetches.
 */

/**
 * WordPress dependencies
 */
import {
	Button,
	Notice,
	Spinner,
	VisuallyHidden,
} from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import useActivity from '../hooks/useActivity';
import groupByDay from '../utils/groupByDay';
import ActivityItem from './ActivityItem';
import './activity.scss';

/**
 * Placeholder rows shown while the first page loads.
 *
 * @type {number}
 */
const SKELETON_ROWS = 3;

/**
 * Placeholder rows for the first load.
 *
 * @return {Element} Skeleton list.
 */
function Skeleton() {
	return (
		<>
			<VisuallyHidden>
				{ __( 'Loading activity…', 'sapphireit-editorial-workflow' ) }
			</VisuallyHidden>
			<ol className="sit-cwm-activity-list is-loading" aria-hidden="true">
				{ Array.from( { length: SKELETON_ROWS }, ( _, index ) => (
					<li
						key={ index }
						className="sit-cwm-activity-item sit-cwm-activity-skeleton"
					>
						<span className="sit-cwm-activity-avatar" />
						<span className="sit-cwm-activity-body">
							<span className="sit-cwm-skeleton-line" />
							<span className="sit-cwm-skeleton-line is-short" />
						</span>
					</li>
				) ) }
			</ol>
		</>
	);
}

/**
 * Text announcing entries that appeared at the top of the list.
 *
 * @param {Object[]} items Current entries.
 * @return {string} Announcement; `''` when nothing to announce.
 */
function useNewEntriesAnnouncement( items ) {
	const [ announcement, setAnnouncement ] = useState( '' );
	const newestIdRef = useRef( null );

	useEffect( () => {
		if ( items.length === 0 ) {
			return;
		}

		const previous = newestIdRef.current;

		newestIdRef.current = items[ 0 ].id;

		// The first page, or an older page appended below: nothing new on top.
		if ( previous === null || previous === items[ 0 ].id ) {
			return;
		}

		const count = items.findIndex( ( item ) => item.id === previous );

		if ( count > 0 ) {
			setAnnouncement(
				sprintf(
					/* translators: %d: Number of new activity entries. */
					_n(
						'%d new activity entry.',
						'%d new activity entries.',
						count,
						'sapphireit-editorial-workflow'
					),
					count
				)
			);
		}
	}, [ items ] );

	return announcement;
}

/**
 * @param {Object} props           Props.
 * @param {number} props.postId    Post id.
 * @param {number} [props.version] Reloads the newest entries when it changes.
 * @param {number} [props.perPage] Entries per page.
 * @return {Element} Timeline.
 */
export default function ActivityTimeline( { postId, version = 0, perPage } ) {
	const {
		items,
		isLoading,
		isRefreshing,
		error,
		hasMore,
		loadMore,
		retry,
	} = useActivity( postId, { perPage, version } );
	const groups = useMemo( () => groupByDay( items ), [ items ] );
	const announcement = useNewEntriesAnnouncement( items );

	const isEmpty = items.length === 0;

	return (
		<div className="sit-cwm-activity" aria-busy={ isLoading }>
			<VisuallyHidden aria-live="polite">{ announcement }</VisuallyHidden>

			{ error && (
				<div className="sit-cwm-activity-error">
					<Notice status="error" isDismissible={ false }>
						{ error.message }
					</Notice>
					<Button
						variant="secondary"
						onClick={ retry }
						disabled={ isLoading }
						accessibleWhenDisabled
					>
						{ __( 'Retry', 'sapphireit-editorial-workflow' ) }
					</Button>
				</div>
			) }

			{ isEmpty && isLoading && ! error && <Skeleton /> }

			{ isEmpty && ! isLoading && ! error && (
				<div className="sit-cwm-activity-empty">
					<p>{ __( 'No activity yet.', 'sapphireit-editorial-workflow' ) }</p>
					<p>
						{ __(
							'Status changes, reviewer assignments, due dates and comments will appear here.',
							'sapphireit-editorial-workflow'
						) }
					</p>
				</div>
			) }

			{ isRefreshing && (
				<p className="sit-cwm-activity-refreshing">
					<Spinner />
					{ __( 'Updating…', 'sapphireit-editorial-workflow' ) }
				</p>
			) }

			{ groups.map( ( group ) => (
				<div className="sit-cwm-activity-day" key={ group.day }>
					<h3 className="sit-cwm-activity-day-heading">
						{ group.label }
					</h3>
					<ol className="sit-cwm-activity-list">
						{ group.items.map( ( entry ) => (
							<ActivityItem key={ entry.id } entry={ entry } />
						) ) }
					</ol>
				</div>
			) ) }

			{ hasMore && ! error && (
				<Button
					className="sit-cwm-activity-more"
					variant="secondary"
					onClick={ loadMore }
					isBusy={ isLoading }
					disabled={ isLoading }
					accessibleWhenDisabled
				>
					{ __( 'Load more', 'sapphireit-editorial-workflow' ) }
				</Button>
			) }
		</div>
	);
}
