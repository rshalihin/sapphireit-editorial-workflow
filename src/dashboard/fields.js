/**
 * DataViews field definitions for the dashboard.
 *
 * Status and post type options come from the `window.sitCwm` bootstrap;
 * reviewer and author options are passed in from `/users` and the loaded rows.
 * Only `title` and `due_date` sort, and filters only offer operators
 * `viewToQuery()` maps to the REST API.
 *
 * Cell renderers are memoized components: DataViews re-renders every row on
 * selection or hover changes, and a row whose `item` did not change skips it.
 *
 * Add-ons append fields through the `sitCwm.dashboard.fields` filter. They can
 * never replace or remove a core field.
 */

/**
 * WordPress dependencies
 */
import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';
import { memo } from '@wordpress/element';
import { applyFilters } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';
import { caution, Icon } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import StatusBadge from './StatusBadge';
import { FILTER_OPERATORS } from '../hooks/usePosts';
import { aiOriginLabel } from '../utils/aiOrigin';
import {
	decodeText,
	displayTitle,
	formatDate,
	getBootstrap,
	isSafeUrl,
} from '../utils/format';

/**
 * A dash for an empty cell, with a spoken label.
 *
 * @param {Object} props       Props.
 * @param {string} props.label Screen reader text.
 * @return {Element} Placeholder.
 */
function EmptyValue( { label } ) {
	return (
		<span className="sit-cwm-empty-value">
			<span aria-hidden="true">—</span>
			<span className="screen-reader-text">{ label }</span>
		</span>
	);
}

/**
 * Merges user summaries, keeping the first occurrence of each id.
 *
 * @param {...Array} lists Lists of `{ id, name }` (null entries are skipped).
 * @return {Object[]} Unique users.
 */
export function mergeUsers( ...lists ) {
	const seen = new Map();

	lists.flat().forEach( ( user ) => {
		if (
			user &&
			Number.isInteger( user.id ) &&
			user.id > 0 &&
			! seen.has( user.id )
		) {
			seen.set( user.id, user );
		}
	} );

	return [ ...seen.values() ];
}

/**
 * Filter elements for users, sorted by name.
 *
 * @param {Object[]} users User summaries.
 * @return {Object[]} `{ value, label }` elements.
 */
function userElements( users ) {
	return mergeUsers( users )
		.map( ( user ) => ( {
			value: user.id,
			label: decodeText( user.name ),
		} ) )
		.sort( ( a, b ) => a.label.localeCompare( b.label ) );
}

const TitleCell = memo( ( { item } ) => {
	const title = displayTitle( item.title );

	return isSafeUrl( item.edit_link ) ? (
		<a className="sit-cwm-title-link" href={ item.edit_link }>
			{ title }
		</a>
	) : (
		<span>{ title }</span>
	);
} );

const StatusCell = memo( ( { item } ) => {
	return (
		<StatusBadge
			status={ item.status }
			label={ item.status_label }
			isUnknown={ !! item.status_is_unknown }
		/>
	);
} );

const AiOriginCell = memo( ( { item } ) => {
	return <span>{ aiOriginLabel( item.ai_origin || '' ) }</span>;
} );

const ReviewerCell = memo( ( { item } ) => {
	const { reviewer } = item;

	if ( ! reviewer ) {
		return (
			<EmptyValue
				label={ __( 'Unassigned', 'sapphireit-editorial-workflow' ) }
			/>
		);
	}

	return (
		<span className="sit-cwm-user">
			{ isSafeUrl( reviewer.avatar ) && (
				<img
					className="sit-cwm-avatar"
					src={ reviewer.avatar }
					alt=""
					width={ 24 }
					height={ 24 }
				/>
			) }
			<span>{ decodeText( reviewer.name ) }</span>
		</span>
	);
} );

const DueDateCell = memo( ( { item } ) => {
	const text = formatDate( item.due_date );

	if ( ! text ) {
		return (
			<EmptyValue
				label={ __( 'No due date', 'sapphireit-editorial-workflow' ) }
			/>
		);
	}

	// `is_overdue` is computed by the server in the site timezone.
	if ( ! item.is_overdue ) {
		return <span>{ text }</span>;
	}

	return (
		<span className="sit-cwm-due-date is-overdue">
			<Icon icon={ caution } size={ 16 } />
			{ sprintf(
				/* translators: %s: Due date. */
				__( '%s (overdue)', 'sapphireit-editorial-workflow' ),
				text
			) }
		</span>
	);
} );

const AuthorCell = memo( ( { item } ) => {
	return item.author?.name ? (
		<span>{ decodeText( item.author.name ) }</span>
	) : (
		<EmptyValue
			label={ __( 'Unknown author', 'sapphireit-editorial-workflow' ) }
		/>
	);
} );

const PostTypeCell = memo( ( { item } ) => {
	const { postTypes } = getBootstrap();
	const type = Array.isArray( postTypes )
		? postTypes.find( ( entry ) => entry && entry.slug === item.post_type )
		: null;

	return <span>{ type?.singularLabel || item.post_type }</span>;
} );

const LastActivityCell = memo( ( { item } ) => {
	const entry = item.last_activity;

	if ( ! entry || ! entry.created_at ) {
		return (
			<EmptyValue
				label={ __( 'No activity', 'sapphireit-editorial-workflow' ) }
			/>
		);
	}

	const { formats } = getSettings();

	return (
		<span className="sit-cwm-last-activity">
			<span className="sit-cwm-last-activity-action">
				{ entry.action_label }
			</span>
			<time
				dateTime={ entry.created_at }
				title={ dateI18n(
					`${ formats.date } ${ formats.time }`,
					entry.created_at
				) }
			>
				{ humanTimeDiff( entry.created_at ) }
			</time>
		</span>
	);
} );

const OverdueCell = memo( ( { item } ) => {
	return item.is_overdue ? (
		<span className="sit-cwm-due-date is-overdue">
			<Icon icon={ caution } size={ 16 } />
			{ __( 'Overdue', 'sapphireit-editorial-workflow' ) }
		</span>
	) : (
		<EmptyValue
			label={ __( 'Not overdue', 'sapphireit-editorial-workflow' ) }
		/>
	);
} );

/**
 * Whether an add-on field can be filtered: the server gets one value from a
 * fixed list, as `<field.id>=<value>`.
 *
 * @param {Object} field Field from the filter.
 * @return {boolean} True for `elements` plus the `is` operator alone.
 */
function isFilterable( field ) {
	const operators = field.filterBy?.operators;

	return (
		Array.isArray( field.elements ) &&
		field.elements.length > 0 &&
		Array.isArray( operators ) &&
		operators.length === 1 &&
		operators[ 0 ] === 'is'
	);
}

/**
 * Applies the add-on filter and keeps what it may change.
 *
 * Fields can always be hidden, and never sort, because the route only sorts on
 * its own columns.
 *
 * @param {Object[]} core Core fields.
 * @return {Object[]} Added fields.
 */
function filteredFields( core ) {
	let filtered = [];

	// A filter callback that throws costs the added fields, not the dashboard.
	try {
		filtered = applyFilters( 'sitCwm.dashboard.fields', [ ...core ] );
	} catch ( error ) {
		// eslint-disable-next-line no-console
		console.error( error );
	}

	const seen = new Set( core.map( ( field ) => field.id ) );

	return ( Array.isArray( filtered ) ? filtered : [] )
		.filter( ( field ) => {
			if ( ! field || typeof field.id !== 'string' || ! field.id ) {
				return false;
			}

			const isNew = ! seen.has( field.id );

			seen.add( field.id );

			return isNew;
		} )
		.map( withValidElements )
		.map( ( field ) => ( {
			...field,
			enableHiding: true,
			enableSorting: false,
			filterBy: isFilterable( field ) ? field.filterBy : false,
		} ) );
}

/**
 * Drops `elements` entries that are not objects.
 *
 * @param {Object} field Field from the filter.
 * @return {Object} Field.
 */
function withValidElements( field ) {
	return Array.isArray( field.elements )
		? {
				...field,
				elements: field.elements.filter(
					( element ) => element && typeof element === 'object'
				),
			}
		: field;
}

/**
 * The fields add-ons added.
 *
 * @return {Object[]} DataViews fields.
 */
export function addonFields() {
	return filteredFields( coreFields() );
}

/**
 * The add-on fields the dashboard can filter on.
 *
 * @return {Object[]} DataViews fields.
 */
export function addonFilterFields() {
	return addonFields().filter( ( field ) => field.filterBy !== false );
}

/**
 * Builds the dashboard fields: the core ones in their fixed order, then any
 * added by add-ons.
 *
 * @param {Object}   [options]           Options.
 * @param {Object[]} [options.reviewers] Known reviewers `{ id, name }`.
 * @param {Object[]} [options.authors]   Known authors `{ id, name }`.
 * @return {Object[]} DataViews fields.
 */
export default function buildFields( options ) {
	const core = coreFields( options );

	return [ ...core, ...filteredFields( core ) ];
}

/**
 * Builds the core fields.
 *
 * @param {Object}   [options]           Options.
 * @param {Object[]} [options.reviewers] Known reviewers `{ id, name }`.
 * @param {Object[]} [options.authors]   Known authors `{ id, name }`.
 * @return {Object[]} DataViews fields.
 */
function coreFields( { reviewers = [], authors = [] } = {} ) {
	const { statuses, postTypes } = getBootstrap();
	const authorElements = userElements( authors );

	return [
		{
			id: 'title',
			type: 'text',
			label: __( 'Title', 'sapphireit-editorial-workflow' ),
			getValue: ( { item } ) => decodeText( item.title ),
			render: TitleCell,
			enableSorting: true,
			enableGlobalSearch: true,
			enableHiding: false,
			filterBy: false,
		},
		{
			id: 'status',
			type: 'text',
			label: __( 'Status', 'sapphireit-editorial-workflow' ),
			getValue: ( { item } ) => item.status,
			render: StatusCell,
			elements: ( Array.isArray( statuses ) ? statuses : [] )
				.filter( Boolean )
				.map( ( status ) => ( {
					value: status.slug,
					label: status.label,
				} ) ),
			enableSorting: false,
			filterBy: { operators: FILTER_OPERATORS.status },
		},
		{
			id: 'ai_origin',
			type: 'text',
			label: __( 'AI use', 'sapphireit-editorial-workflow' ),
			getValue: ( { item } ) => item.ai_origin || '',
			render: AiOriginCell,
			enableSorting: false,
			filterBy: false,
		},
		{
			id: 'reviewer',
			type: 'integer',
			label: __( 'Reviewer', 'sapphireit-editorial-workflow' ),
			getValue: ( { item } ) => ( item.reviewer ? item.reviewer.id : 0 ),
			render: ReviewerCell,
			elements: [
				{
					value: 0,
					label: __( 'Unassigned', 'sapphireit-editorial-workflow' ),
				},
				...userElements( reviewers ),
			],
			enableSorting: false,
			filterBy: { operators: FILTER_OPERATORS.reviewer },
		},
		{
			id: 'due_date',
			type: 'date',
			label: __( 'Due', 'sapphireit-editorial-workflow' ),
			getValue: ( { item } ) => item.due_date || '',
			render: DueDateCell,
			enableSorting: true,
			filterBy: { operators: FILTER_OPERATORS.due_date },
		},
		{
			id: 'author',
			type: 'integer',
			label: __( 'Author', 'sapphireit-editorial-workflow' ),
			getValue: ( { item } ) => ( item.author ? item.author.id : 0 ),
			render: AuthorCell,
			elements: authorElements,
			enableSorting: false,
			// Without options the filter would be a raw user id input.
			filterBy: authorElements.length
				? { operators: FILTER_OPERATORS.author }
				: false,
		},
		{
			id: 'post_type',
			type: 'text',
			label: __( 'Type', 'sapphireit-editorial-workflow' ),
			getValue: ( { item } ) => item.post_type,
			render: PostTypeCell,
			elements: ( Array.isArray( postTypes ) ? postTypes : [] )
				.filter( Boolean )
				.map( ( type ) => ( {
					value: type.slug,
					label: type.singularLabel,
				} ) ),
			enableSorting: false,
			filterBy: { operators: FILTER_OPERATORS.post_type },
		},
		{
			id: 'last_activity',
			label: __( 'Last activity', 'sapphireit-editorial-workflow' ),
			getValue: ( { item } ) => item.last_activity?.created_at || '',
			render: LastActivityCell,
			enableSorting: false,
			filterBy: false,
		},
		{
			// Filter-only field: the "Overdue only" quick filter. Never a column.
			id: 'is_overdue',
			label: __( 'Overdue', 'sapphireit-editorial-workflow' ),
			getValue: ( { item } ) => !! item.is_overdue,
			render: OverdueCell,
			elements: [
				{
					value: true,
					label: __(
						'Overdue only',
						'sapphireit-editorial-workflow'
					),
				},
			],
			enableSorting: false,
			enableHiding: false,
			filterBy: { operators: FILTER_OPERATORS.is_overdue },
		},
	];
}
