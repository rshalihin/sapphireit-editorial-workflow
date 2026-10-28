/**
 * WordPress dependencies
 */
import { store as coreStore } from '@wordpress/core-data';
import { resolveSelect, useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { useCallback } from '@wordpress/element';

/**
 * Re-fetches the post open in the block editor.
 *
 * The block editor decides whether to show Publish or Submit for Review from
 * the post entity's `wp:action-publish` link, which the server computes from
 * the publish gate (phase 08). It only reads that link on load, so after an
 * in-session approval the entity is re-fetched to bring the button up to date.
 *
 * The returned function never rejects: if the re-fetch fails, the button just
 * catches up on the next load, which is harmless.
 *
 * @return {() => Promise<void>} Refreshes the current editor post.
 */
export default function useRefreshEditorPost() {
	const { postType, postId } = useSelect( ( select ) => {
		const editor = select( editorStore );

		return {
			postType: editor.getCurrentPostType(),
			postId: editor.getCurrentPostId(),
		};
	}, [] );
	const { invalidateResolution } = useDispatch( coreStore );

	return useCallback( async () => {
		if ( ! postType || ! postId ) {
			return;
		}

		try {
			invalidateResolution( 'getEntityRecord', [
				'postType',
				postType,
				postId,
			] );
			await resolveSelect( coreStore ).getEntityRecord(
				'postType',
				postType,
				postId
			);
		} catch {
			// The Publish button refreshes on the next load instead.
		}
	}, [ postType, postId, invalidateResolution ] );
}
