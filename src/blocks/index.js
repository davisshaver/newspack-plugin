/* globals newspack_blocks */

/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks';

/**
 * Internal dependencies
 */
import * as readerRegistration from './reader-registration';
import * as correctionBox from './correction-box';
import * as correctionItem from './correction-item';
import * as avatar from './avatar';

/**
 * Block Scripts
 */
import './core-image';

export const blocks = [ readerRegistration, correctionBox, correctionItem, avatar ];

const readerActivationBlocks = [ 'newspack/reader-registration' ];
const correctionBlocks = [ 'newspack/correction-box', 'newspack/correction-item' ];

/**
 * Function to register an individual block.
 *
 * @param {Object} block The block to be registered.
 */
const registerBlock = block => {
	if ( ! block ) {
		return;
	}

	const { metadata, settings, name } = block;

	/** Do not register reader activation blocks if it's disabled. */
	if ( readerActivationBlocks.includes( name ) && ! newspack_blocks.has_reader_activation ) {
		return;
	}

	/** Do not register correction blocks if it's disabled. */
	if ( correctionBlocks.includes( name ) && ! newspack_blocks.corrections_enabled ) {
		return;
	}

	registerBlockType( { name, ...metadata }, settings );
};

for ( const block of blocks ) {
	registerBlock( block );
}
