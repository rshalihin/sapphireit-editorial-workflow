/**
 * Review disclosure block: editor registration.
 *
 * Dynamic, so nothing is saved; `DisclosureBlock::render_block()` prints it.
 * The metadata lives in `blocks/review-disclosure/block.json`, which PHP
 * registers from too.
 */

/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import metadata from '../../../blocks/review-disclosure/block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
