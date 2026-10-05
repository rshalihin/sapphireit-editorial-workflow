/**
 * Searchable reviewer options for a `ComboboxControl`.
 */

/**
 * WordPress dependencies
 */
import { useDebounce } from '@wordpress/compose';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import useUsers from './useUsers';
import { decodeText } from '../utils/format';

/**
 * Option value meaning "no reviewer" (`reviewer_id: 0`).
 *
 * @type {string}
 */
export const UNASSIGNED = '0';

/**
 * Options for the reviewer picker: "Unassigned", the current reviewer (so it
 * stays selectable even when outside the search results), then the assignable
 * users matching the debounced search.
 *
 * @param {Object}  [options]          Options.
 * @param {number}  [options.postId]   Limit to users who can review this post.
 * @param {?Object} [options.reviewer] Current reviewer `{ id, name, avatar }`.
 * @return {{options: Object[], isLoading: boolean, error: ?Object, onFilterValueChange: Function}} Result.
 */
export default function useReviewerOptions( {
	postId = 0,
	reviewer = null,
} = {} ) {
	const [ search, setSearch ] = useState( '' );
	const onFilterValueChange = useDebounce( setSearch, 300 );
	const { users, isLoading, error } = useUsers( { search, postId } );

	useEffect(
		() => () => onFilterValueChange.cancel(),
		[ onFilterValueChange ]
	);

	const options = useMemo( () => {
		const list = [
			{
				value: UNASSIGNED,
				label: __( '— Unassigned —', 'sapphireit-editorial-workflow' ),
			},
		];
		const added = new Set();
		const add = ( user ) => {
			if ( user && ! added.has( user.id ) ) {
				added.add( user.id );
				list.push( {
					value: String( user.id ),
					label: decodeText( user.name ),
				} );
			}
		};

		add( reviewer );
		users.forEach( add );

		return list;
	}, [ reviewer, users ] );

	return { options, isLoading, error, onFilterValueChange };
}
