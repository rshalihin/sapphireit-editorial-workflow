/**
 * The workflow content table: DataViews over `GET /sit-cwm/v1/posts`.
 */

/**
 * WordPress dependencies
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import buildActions from './actions';
import BulkActions from './bulk/BulkActions';
import buildFields, { mergeUsers } from './fields';
import {
	DEFAULT_LAYOUTS,
	DEFAULT_VIEW,
	hasActiveQuery,
	viewFromSearchParams,
	viewToSearchParams,
} from './urlState';
import useBulkAction from '../hooks/useBulkAction';
import usePosts, { PER_PAGE_SIZES, viewToQuery } from '../hooks/usePosts';
import useUsers from '../hooks/useUsers';
import { getBootstrap } from '../utils/format';

/**
 * DataViews static configuration.
 *
 * @type {Object}
 */
const CONFIG = { perPageSizes: PER_PAGE_SIZES };

/**
 * Row id for DataViews.
 *
 * @param {Object} item Row.
 * @return {string} Post id.
 */
const getItemId = ( item ) => String( item.post_id );

/**
 * WordPress' mobile admin breakpoint.
 *
 * @type {string}
 */
const SMALL_SCREEN_QUERY = '(max-width: 782px)';

/**
 * The view to start from: the URL's, with the grid layout as the default on
 * small screens when the URL does not name a layout.
 *
 * @return {Object} DataViews view.
 */
function getInitialView() {
	const { search } = window.location;
	const view = viewFromSearchParams( search );

	if (
		! new URLSearchParams( search ).has( 'layout' ) &&
		typeof window.matchMedia === 'function' &&
		window.matchMedia( SMALL_SCREEN_QUERY ).matches
	) {
		return { ...view, ...DEFAULT_LAYOUTS.grid, type: 'grid' };
	}

	return view;
}

/**
 * Whether the bootstrap says the user may list reviewers. A UI hint to skip a
 * request the server would refuse; the route checks for itself.
 *
 * @return {boolean} True when the reviewer list can be fetched.
 */
function canListReviewers() {
	const { capabilities = {} } = getBootstrap();

	return (
		!! capabilities.sit_cwm_assign_reviewer ||
		!! capabilities.sit_cwm_manage_workflows
	);
}

/**
 * Reviewers and authors seen in loaded rows, so filter options include them
 * even for users who cannot list reviewers.
 *
 * @param {Object[]} records Loaded rows.
 * @return {{reviewers: Object[], authors: Object[]}} Known users.
 */
function useKnownUsers( records ) {
	const [ known, setKnown ] = useState( { reviewers: [], authors: [] } );

	useEffect( () => {
		setKnown( ( previous ) => {
			const reviewers = mergeUsers(
				previous.reviewers,
				records.map( ( row ) => row.reviewer )
			);
			const authors = mergeUsers(
				previous.authors,
				records.map( ( row ) => row.author )
			);

			return reviewers.length === previous.reviewers.length &&
				authors.length === previous.authors.length
				? previous
				: { reviewers, authors };
		} );
	}, [ records ] );

	return known;
}

/**
 * Shown when nothing is in the workflow at all (no search or filter active).
 *
 * @return {Element} Empty state.
 */
function EmptyState() {
	const { adminUrl = '', postTypes } = getBootstrap();
	const type = Array.isArray( postTypes ) ? postTypes[ 0 ] : null;
	const href =
		type &&
		`${ adminUrl }post-new.php${
			type.slug === 'post'
				? ''
				: `?post_type=${ encodeURIComponent( type.slug ) }`
		}`;

	return (
		<div className="sit-cwm-dashboard-empty">
			<p>{ __( 'No content is in the workflow yet.', 'sapphireit-editorial-workflow' ) }</p>
			<p className="sit-cwm-dashboard-empty-hint">
				{ __(
					'Content of the workflow-enabled post types appears here as soon as it is created.',
					'sapphireit-editorial-workflow'
				) }
			</p>
			{ href && (
				<a className="button button-primary" href={ href }>
					{ sprintf(
						/* translators: %s: Singular post type label, e.g. "Post". */
						__( 'Add new %s', 'sapphireit-editorial-workflow' ),
						type.singularLabel
					) }
				</a>
			) }
		</div>
	);
}

/**
 * Shown when a search or filter matches nothing.
 *
 * @param {Object}   props         Props.
 * @param {Function} props.onClear Clears the search and filters.
 * @return {Element} Empty state.
 */
function NoMatches( { onClear } ) {
	return (
		<div className="sit-cwm-dashboard-empty">
			<p>
				{ __(
					'No content matches the current search and filters.',
					'sapphireit-editorial-workflow'
				) }
			</p>
			<Button __next40pxDefaultSize variant="secondary" onClick={ onClear }>
				{ __( 'Clear all filters', 'sapphireit-editorial-workflow' ) }
			</Button>
		</div>
	);
}

/**
 * Shown in place of the rows when the page could not be loaded; the error
 * notice above the table carries the reason and the Retry action.
 *
 * @return {Element} Empty state.
 */
function LoadFailed() {
	return (
		<div className="sit-cwm-dashboard-empty">
			<p>{ __( 'Content could not be loaded.', 'sapphireit-editorial-workflow' ) }</p>
		</div>
	);
}

/**
 * @return {Element} Dashboard table.
 */
export default function WorkflowDataViews() {
	const [ view, setView ] = useState( getInitialView );
	const [ selection, setSelection ] = useState( [] );
	const query = useMemo( () => viewToQuery( view ), [ view ] );
	const {
		records,
		totalItems,
		totalPages,
		isLoading,
		isRefreshing,
		error,
		refresh,
	} = usePosts( query );
	const { users } = useUsers( {
		perPage: 100,
		enabled: canListReviewers(),
	} );
	const known = useKnownUsers( records );

	// After every batch: refetch the page and start from an empty selection.
	const onBulkComplete = useCallback( () => {
		refresh();
		setSelection( [] );
	}, [ refresh ] );
	const bulk = useBulkAction( { onComplete: onBulkComplete } );

	const fields = useMemo(
		() =>
			buildFields( {
				reviewers: mergeUsers( users, known.reviewers ),
				authors: mergeUsers( users, known.authors ),
			} ),
		[ users, known ]
	);
	const actions = useMemo(
		() =>
			buildActions( {
				onChanged: refresh,
				isBulkRunning: bulk.isRunning,
			} ),
		[ refresh, bulk.isRunning ]
	);

	// Reflect the view in the URL so a filtered dashboard is linkable.
	useEffect( () => {
		const params = viewToSearchParams( view, window.location.search );
		const search = params.toString();
		const next = `${ window.location.pathname }${ search ? `?${ search }` : '' }${ window.location.hash }`;
		const current = `${ window.location.pathname }${ window.location.search }${ window.location.hash }`;

		if ( next !== current ) {
			window.history.replaceState( window.history.state, '', next );
		}
	}, [ view ] );

	// A refetch can shrink the result set (e.g. the last row of the last page
	// was approved out of a status filter): step back to the last page.
	useEffect( () => {
		if ( ! isLoading && totalPages > 0 && view.page > totalPages ) {
			setView( ( current ) => ( { ...current, page: totalPages } ) );
		}
	}, [ isLoading, totalPages, view.page ] );

	const clearFilters = () =>
		setView( ( current ) => ( {
			...current,
			search: '',
			filters: [],
			page: 1,
		} ) );

	const isDefaultView =
		viewToSearchParams( view ).toString() === '' &&
		view.type === DEFAULT_VIEW.type;
	const isFiltered = hasActiveQuery( view );

	let empty = <EmptyState />;

	if ( error ) {
		empty = <LoadFailed />;
	} else if ( isFiltered ) {
		empty = <NoMatches onClear={ clearFilters } />;
	}

	return (
		<BulkActions bulk={ bulk } onChanged={ refresh }>
			{ error && (
				<Notice
					className="sit-cwm-dashboard-error"
					status="error"
					isDismissible={ false }
					actions={ [
						{ label: __( 'Retry', 'sapphireit-editorial-workflow' ), onClick: refresh },
					] }
				>
					{ error.message }
				</Notice>
			) }
			{ isRefreshing && (
				<div className="sit-cwm-dashboard-toolbar">
					<span className="sit-cwm-dashboard-refreshing">
						<Spinner />
						{ __( 'Refreshing…', 'sapphireit-editorial-workflow' ) }
					</span>
				</div>
			) }
			<DataViews
				data={ records }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				selection={ selection }
				onChangeSelection={ setSelection }
				paginationInfo={ { totalItems, totalPages } }
				defaultLayouts={ DEFAULT_LAYOUTS }
				getItemId={ getItemId }
				isLoading={ isLoading }
				config={ CONFIG }
				empty={ empty }
				onReset={
					isDefaultView ? false : () => setView( DEFAULT_VIEW )
				}
			>
				{ /* DataViews' default layout, plus "Clear all filters" beside
				   the filter toggle. */ }
				<div className="dataviews__view-actions sit-cwm-view-actions">
					<div className="dataviews__search sit-cwm-view-search">
						<DataViews.Search
							label={ __( 'Search content', 'sapphireit-editorial-workflow' ) }
						/>
						<DataViews.FiltersToggle />
						{ isFiltered && (
							<Button
								size="compact"
								variant="tertiary"
								onClick={ clearFilters }
							>
								{ __( 'Clear all filters', 'sapphireit-editorial-workflow' ) }
							</Button>
						) }
					</div>
					<div className="sit-cwm-view-config">
						<DataViews.LayoutSwitcher />
						<DataViews.ViewConfig />
					</div>
				</div>
				<DataViews.FiltersToggled className="dataviews-filters__container" />
				<DataViews.Layout />
				<DataViews.Footer />
			</DataViews>
		</BulkActions>
	);
}
