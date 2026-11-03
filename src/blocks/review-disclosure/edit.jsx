/**
 * Editor view of the Review disclosure block.
 *
 * The block is dynamic and the editor shows a static placeholder: what it
 * prints depends on approval and publication, which the server decides (F11).
 */

/**
 * WordPress dependencies
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, Placeholder, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { shield } from '@wordpress/icons';

/**
 * Whether the site setting is on. Printed by `DisclosureBlock` next to the
 * editor script; assumed on when missing.
 *
 * @return {boolean} Enabled.
 */
const isEnabled = () => false !== window.sitCwmDisclosure?.enabled;

/**
 * @param {Object}   props               Block edit props.
 * @param {Object}   props.attributes    Block attributes.
 * @param {Function} props.setAttributes Attribute setter.
 * @return {Element} Edit view.
 */
export default function Edit( { attributes, setAttributes } ) {
	const { showReviewer, showAiOrigin } = attributes;

	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody
					title={ __( 'Settings', 'sapphireit-editorial-workflow' ) }
				>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show reviewer and date',
							'sapphireit-editorial-workflow'
						) }
						checked={ showReviewer }
						onChange={ ( value ) =>
							setAttributes( { showReviewer: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __(
							'Show AI use',
							'sapphireit-editorial-workflow'
						) }
						checked={ showAiOrigin }
						onChange={ ( value ) =>
							setAttributes( { showAiOrigin: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<Placeholder
				icon={ shield }
				label={ __(
					'Review disclosure',
					'sapphireit-editorial-workflow'
				) }
				instructions={
					isEnabled()
						? __(
								'The review disclosure appears here once this content is approved and published.',
								'sapphireit-editorial-workflow'
						  )
						: __(
								'Review disclosures are turned off in Workflow settings.',
								'sapphireit-editorial-workflow'
						  )
				}
			/>
		</div>
	);
}
