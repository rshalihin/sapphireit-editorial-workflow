/**
 * The dashboard's empty state: nothing is in the workflow yet.
 */

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { getBootstrap } from '../utils/format';

/**
 * Shown when nothing is in the workflow at all (no search or filter active).
 *
 * @return {Element} Empty state.
 */
export default function EmptyState() {
	const { adminUrl = '', postTypes, gettingStartedUrl } = getBootstrap();
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
			{ ( href || gettingStartedUrl ) && (
				<p className="sit-cwm-dashboard-empty-actions">
					{ href && (
						<a className="button button-primary" href={ href }>
							{ sprintf(
								/* translators: %s: Singular post type label, e.g. "Post". */
								__( 'Add new %s', 'sapphireit-editorial-workflow' ),
								type.singularLabel
							) }
						</a>
					) }
					{ gettingStartedUrl && (
						<a className="button" href={ gettingStartedUrl }>
							{ __( 'Open Getting Started', 'sapphireit-editorial-workflow' ) }
						</a>
					) }
				</p>
			) }
		</div>
	);
}
