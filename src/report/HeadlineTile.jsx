/**
 * The governance report's headline: the share published after approval, with
 * a bar of approved-first against without-approval publications.
 */

/**
 * WordPress dependencies
 */
import { useInstanceId } from '@wordpress/compose';
import { __, sprintf } from '@wordpress/i18n';

/**
 * A full-width card with the large share, its label and the split bar. The
 * value is described by the label, like a KpiTile; the bar is an image with a
 * sentence for screen readers, and its legend repeats the counts as text.
 *
 * @param {Object}   props              Props.
 * @param {string}   props.label        Label.
 * @param {string}   props.value        Formatted share.
 * @param {string}   [props.help]       Text under the value.
 * @param {number}   props.approved     Publications made after approval.
 * @param {number}   props.total        All publications in the period.
 * @param {Function} props.formatNumber Formats a count.
 * @return {Element} Headline card.
 */
export default function HeadlineTile( {
	label,
	value,
	help,
	approved,
	total,
	formatNumber,
} ) {
	const id = useInstanceId( HeadlineTile, 'sit-cwm-report-headline' );
	const without = Math.max( 0, total - approved );
	const width = total > 0 ? `${ ( approved / total ) * 100 }%` : '0%';

	return (
		<div className="sit-cwm-report__headline">
			<div className="sit-cwm-report__headline-figure">
				<span id={ id } className="sit-cwm-report__tile-label">
					{ label }
				</span>
				<span
					className="sit-cwm-report__headline-value"
					aria-describedby={ id }
				>
					{ value }
				</span>
				{ help && (
					<span className="sit-cwm-report__tile-help">{ help }</span>
				) }
			</div>
			{ total > 0 && (
				<div className="sit-cwm-report__headline-bar">
					<div
						className="sit-cwm-report__split"
						role="img"
						aria-label={ sprintf(
							/* translators: 1: publications made after approval, 2: all publications in the period. */
							__(
								'%1$s of %2$s publications were approved first',
								'sapphireit-editorial-workflow'
							),
							formatNumber( approved ),
							formatNumber( total )
						) }
					>
						<div
							className="sit-cwm-report__split-fill"
							style={ { width } }
						/>
					</div>
					<div
						className="sit-cwm-report__split-legend"
						aria-hidden="true"
					>
						<span>
							{ sprintf(
								/* translators: %s: publications made after approval. */
								__(
									'Approved first · %s',
									'sapphireit-editorial-workflow'
								),
								formatNumber( approved )
							) }
						</span>
						<span>
							{ sprintf(
								/* translators: %s: publications made without approval. */
								__(
									'Without approval · %s',
									'sapphireit-editorial-workflow'
								),
								formatNumber( without )
							) }
						</span>
					</div>
				</div>
			) }
		</div>
	);
}
