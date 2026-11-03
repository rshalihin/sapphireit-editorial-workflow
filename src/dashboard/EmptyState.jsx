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
 * Play icon of the Getting Started callout.
 *
 * @return {Element} Icon.
 */
function PlayIcon() {
	return (
		<span className="sit-cwm-gs-callout__icon" aria-hidden="true">
			<svg width="18" height="18" viewBox="0 0 24 24" focusable="false">
				<path d="M8 5v14l11-7z" fill="currentColor" />
			</svg>
		</span>
	);
}

/**
 * Shown when nothing is in the workflow at all (no search or filter active):
 * the Getting Started callout (design A), then what to expect and a link to
 * add content of the first enabled type.
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
			{ gettingStartedUrl && (
				<div className="sit-cwm-gs-callout">
					<PlayIcon />
					<div className="sit-cwm-gs-callout__text">
						<p className="sit-cwm-gs-callout__title">
							{ __(
								'New to Editorial Workflow?',
								'sapphireit-editorial-workflow'
							) }
						</p>
						<p>
							{ __(
								'Getting Started has the setup steps and the documentation.',
								'sapphireit-editorial-workflow'
							) }
						</p>
					</div>
					<a
						className="button button-primary"
						href={ gettingStartedUrl }
					>
						{ __(
							'Open Getting Started',
							'sapphireit-editorial-workflow'
						) }
					</a>
				</div>
			) }
			<p>
				{ __(
					'No content is in the workflow yet.',
					'sapphireit-editorial-workflow'
				) }
			</p>
			<p className="sit-cwm-dashboard-empty-hint">
				{ __(
					'Content of the workflow-enabled post types appears here as soon as it is created.',
					'sapphireit-editorial-workflow'
				) }
			</p>
			{ href && (
				<p className="sit-cwm-dashboard-empty-actions">
					<a className="button" href={ href }>
						{ sprintf(
							/* translators: %s: Singular post type label, e.g. "Post". */
							__( 'Add new %s', 'sapphireit-editorial-workflow' ),
							type.singularLabel
						) }
					</a>
				</p>
			) }
		</div>
	);
}
