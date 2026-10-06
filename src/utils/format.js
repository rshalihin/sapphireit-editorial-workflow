/**
 * Display helpers over the `window.sitCwm` bootstrap object and dates.
 *
 * Bootstrap data is a UI hint only; nothing here decides what a user may do.
 */

/**
 * WordPress dependencies
 */
import { dateI18n, getSettings } from '@wordpress/date';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';

/**
 * Colour used when a status has no registered colour (mirrors
 * `StatusManager::FALLBACK_COLOR`).
 *
 * @type {string}
 */
export const FALLBACK_STATUS_COLOR = '#757575';

/**
 * The bootstrap object printed by `Sit_Cwm\Core\Assets`.
 *
 * @return {Object} Bootstrap data; empty when missing.
 */
export function getBootstrap() {
	return window.sitCwm || {};
}

/**
 * A registered status definition.
 *
 * @param {string} slug Status slug.
 * @return {?Object} `{ slug, label, description, color, order, is_final }`, or null.
 */
export function getStatusDefinition( slug ) {
	const { statuses } = getBootstrap();

	if ( ! Array.isArray( statuses ) ) {
		return null;
	}

	return (
		statuses.find( ( status ) => status && status.slug === slug ) || null
	);
}

/**
 * Whether a post type is workflow-enabled for the current user.
 *
 * @param {string} postType Post type slug.
 * @return {boolean} True when listed in the bootstrap data.
 */
export function isEnabledPostType( postType ) {
	const { postTypes } = getBootstrap();

	return (
		Array.isArray( postTypes ) &&
		postTypes.some( ( type ) => type && type.slug === postType )
	);
}

/**
 * A post title or user name from the REST API, ready to render as text.
 *
 * The API returns raw DB values, and kses stores `&` as `&amp;` for users
 * without `unfiltered_html`, so decode entities as core's DataViews screens
 * do. The result is plain text: render it only as a text node.
 *
 * @param {*} value Raw title or name.
 * @return {string} Decoded text; `''` for non-strings.
 */
export function decodeText( value ) {
	return typeof value === 'string' ? decodeEntities( value ) : '';
}

/**
 * A post title for display, with a fallback for untitled posts.
 *
 * @param {*} title Raw post title.
 * @return {string} Decoded title, or `(no title)`.
 */
export function displayTitle( title ) {
	return (
		decodeText( title ) ||
		__( '(no title)', 'sapphireit-editorial-workflow' )
	);
}

/**
 * Whether a URL is safe to put in `href`/`src`: absolute http(s) or
 * root-relative. Rejects `javascript:` and other schemes.
 *
 * @param {*} url URL.
 * @return {boolean} True when safe.
 */
export function isSafeUrl( url ) {
	return (
		typeof url === 'string' &&
		( /^https?:\/\//i.test( url ) || /^\/(?!\/)/.test( url ) )
	);
}

/**
 * Whether a value is a `Y-m-d` date string.
 *
 * @param {*} value Value.
 * @return {boolean} True for `YYYY-MM-DD`.
 */
export function isYmd( value ) {
	return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test( value );
}

/**
 * The `Y-m-d` part of a date picker value (`YYYY-MM-DDTHH:mm:ss`).
 *
 * @param {*} value Date picker value.
 * @return {string} `Y-m-d`, or `''` when unusable.
 */
export function toYmd( value ) {
	if ( typeof value !== 'string' ) {
		return '';
	}

	const ymd = value.slice( 0, 10 );

	return isYmd( ymd ) ? ymd : '';
}

/**
 * Today's date in the site's timezone.
 *
 * @return {string} `Y-m-d`.
 */
export function todayYmd() {
	return dateI18n( 'Y-m-d', new Date() );
}

/**
 * A `Y-m-d` date in the site's date format.
 *
 * Formatted as UTC midnight in UTC, so the calendar day never shifts with the
 * browser's or the site's offset.
 *
 * @param {string} ymd `Y-m-d` date.
 * @return {string} Localized date, or `''` for invalid input.
 */
export function formatDate( ymd ) {
	if ( ! isYmd( ymd ) ) {
		return '';
	}

	return dateI18n( getSettings().formats.date, `${ ymd }T00:00:00Z`, 'UTC' );
}

/**
 * Whether a due date has passed (site timezone).
 *
 * @param {string} ymd     `Y-m-d` due date; `''` for none.
 * @param {string} [today] Today as `Y-m-d`; defaults to the site's today.
 * @return {boolean} True when strictly before today.
 */
export function isOverdue( ymd, today = todayYmd() ) {
	return isYmd( ymd ) && ymd < today;
}
