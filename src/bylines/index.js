/* globals newspackBylines */

/**
 * WordPress dependencies
 */
import { Button, Modal, ToggleControl } from '@wordpress/components';
import {
	useCallback,
	useMemo,
	useEffect,
	useState,
	useRef,
} from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { Icon, plus } from '@wordpress/icons';
import { store as coreStore } from '@wordpress/core-data';

/**
 * Internal dependencies
 */
import './style.scss';

const BASE_QUERY = {
	_fields: 'id,name',
	context: 'view', // Allows non-admins to perform requests.
};

/** Close icon copied from @wordpress/icons/src/library/close.js to be used as markup */
const close = `
	<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
		<path d="M13 11.8l6.1-6.3-1-1-6.1 6.2-6.1-6.2-1 1 6.1 6.3-6.5 6.7 1 1 6.5-6.6 6.5 6.6 1-1z" />
	</svg>
`;

/**
 * Parse byline meta to convert custom tags (<Author></Author> or [Author][/Author]) to token markup.
 *
 * @see    {@link https://github.com/Automattic/newspack-plugin/tree/trunk/includes/bylines#readme|Custom Bylines}
 * @param {string} metaByline Value of byline as stored in meta key.
 * @return {string}            Parsed byline looking up for <Author id=1></Author> tags and replacing them.
 */
const parseForEdit = metaByline => {
	const tokenMarkup = `<span id="token-$1" contenteditable="false" draggable="true" class="components-form-token-field__token token-inline-block author-token" data-token="$1">
		<span class="components-form-token-field__token-text">$2</span>
		<button
			class="components-button components-form-token-field__remove-token token-inline-block__remove"
			type="button"
			data-token="$1"
		>
			${ close }
		</button>
	</span>`;

	return metaByline.replace(
		/\[Author id=(\d*)\](\D*)\[\/Author\]/g,
		tokenMarkup
	);
};

/**
 * Parse byline meta to convert custom tags (<Author></Author> or [Author][/Author]) to token markup.
 *
 * @see    {@link https://github.com/Automattic/newspack-plugin/tree/trunk/includes/bylines#readme|Custom Bylines}
 * @param {string} metaByline Value of byline as stored in meta key.
 * @return {string}            Parsed byline looking up for <Author id=1></Author> tags and replacing them.
 */
const parseForPreview = metaByline => {
	const tokenMarkup = `<span class="newspack-byline-author" id="token-$1" data-token="$1">$2</span>`;

	return metaByline.replace(
		/\[Author id=(\d*)\](\D*)\[\/Author\]/g,
		tokenMarkup
	);
};

/**
 * Transform the bylineElement innerHTML into the format that we expect to save.
 *
 * @see   {@link https://github.com/Automattic/newspack-plugin/tree/trunk/includes/bylines#readme|Custom Bylines}
 * @param {Element} element Byline element reference.
 * @return {string}         Updated byline text, transformed into the save format.
 */
const transformByline = element => {
	const clonebylineElement = element.cloneNode( true );

	const tokenElements =
		clonebylineElement.querySelectorAll( 'span[data-token]' );

	tokenElements.forEach( tokenElement => {
		const authorID = tokenElement.dataset.token;
		const authorNode = tokenElement.querySelector( 'span' );
		const authorName = authorNode ? authorNode.innerText.trim() : '';

		if ( authorID && authorName ) {
			tokenElement.replaceWith(
				document.createTextNode(
					`[Author id=${ authorID }]${ authorName }[/Author]`
				)
			);
		}
	} );

	return clonebylineElement.innerHTML;
};

/**
 * Component for the custom byline modal.
 *
 * @param {Object}   props             Component props.
 * @param {Function} props.insertToken Callback when a token is added to the byline.
 * @param {Object[]} props.tokens      All author values to be inserted.
 * @param {number[]} props.tokensInUse Array of author IDs already inserted in byline.
 * @param {Element}  props.textArea    The text area element.
 * @param {boolean}  props.isOpen      Whether the modal is open.
 * @param {Function} props.setOpen     Callback to set the modal open state.
 */
const CustomBylineModal = ( {
	insertToken,
	tokens,
	tokensInUse,
	textArea,
	isOpen,
	setOpen,
} ) => {
	const openModal = () => setOpen( true );
	const closeModal = () => setOpen( false );

	return (
		<>
			<Button
				className="newspack-byline-customize-btn"
				variant="secondary"
				onClick={ openModal }
			>
				Edit byline
			</Button>

			{ isOpen && (
				<Modal
					className="newspack-byline-customize-modal"
					title="Edit byline"
					onRequestClose={ closeModal }
				>
					{ textArea }
					<Tokens
						tokens={ tokens }
						tokensInUse={ tokensInUse }
						insertToken={ insertToken }
					/>
					<div className="newspack-byline-customize-modal-btns">
						<Button variant="primary" onClick={ closeModal }>
							Save
						</Button>
					</div>
				</Modal>
			) }
		</>
	);
};

/**
 * An author "token" button, to add an author to the byline.
 *
 * @param {Object}   props          Component props.
 * @param {Object}   props.token    Author data, with @id and @name.
 * @param {Function} props.onInsert Callback when the token is added to the byline.
 */
const Token = ( { token, onInsert } ) => {
	return (
		<span className="components-form-token-field__token token-inline-block">
			<span className="components-form-token-field__token-text">
				{ token.name }
			</span>
			<Button
				className="components-form-token-field__insert-token is-small has-icon token-inline-block__insert"
				onClick={ onInsert }
			>
				<Icon icon={ plus } />
			</Button>
		</span>
	);
};

/**
 * The list of available tokens to insert.
 *
 * @param {Object}   props             Component props.
 * @param {Object[]} props.tokens      All author values to be inserted.
 * @param {number[]} props.tokensInUse Array of author IDs already inserted in byline.
 * @param {Function} props.insertToken Callback when a token is added to the byline.
 */
const Tokens = ( { tokens, tokensInUse, insertToken } ) => {
	return (
		<div className="tokens">
			{ tokens.map(
				token =>
					! tokensInUse.includes( token.id ) && (
						<Token
							key={ token.id }
							token={ token }
							onInsert={ () => insertToken( token ) }
						/>
					)
			) }
		</div>
	);
};

/**
 * The byline settings panel component.
 */
const BylinesSettingsPanel = () => {
	/** Tokens with authors assigned to the post */
	const [ tokens, setTokens ] = useState( [] );

	/** Tokens that are in use by the custom byline */
	const [ tokensInUse, setTokensInUse ] = useState( [] );

	const [ cursorPos, setCursorPos ] = useState( null );

	/** Reference to contenteditable element to add event listners */
	const editableRef = useRef( null );

	const { editPost } = useDispatch( 'core/editor' );

	/** Current post data */
	const { postId } = useSelect(
		select => ( {
			postId: select( 'core/editor' ).getCurrentPostId(),
		} ),
		[]
	);

	const { getEditedPostAttribute } = useSelect( select =>
		select( 'core/editor' )
	);

	/** Fetch post author(s) */
	const { postAuthor, coAuthors } = useSelect( select => {
		const { getUser } = select( coreStore );
		const _authorId = getEditedPostAttribute( 'author' );

		return {
			postAuthor: getUser( _authorId, BASE_QUERY ),
			coAuthors:
				postId && select( 'cap/authors' )
					? select( 'cap/authors' ).getAuthors( postId )
					: [],
		};
	} );

	/**
	 * Set tokens when authors change.
	 */
	useEffect( () => {
		if ( coAuthors?.length ) {
			setTokens(
				coAuthors.map( author => ( {
					id: parseInt( author.id ),
					name: author.display,
				} ) )
			);
		} else {
			setTokens( [ postAuthor ] );
		}
	}, [ coAuthors, postAuthor ] );

	/** Toggle if custom byline is enabled */
	const [ isEnabled, setIsEnabled ] = useState(
		!! getEditedPostAttribute( 'meta' )[ newspackBylines.metaKeyActive ]
	);

	/** Toggle if custom byline modal is open */
	const [ isModalOpen, setModalOpen ] = useState( false );

	const customByline =
		getEditedPostAttribute( 'meta' )[ newspackBylines.metaKeyByline ];

	/**
	 * Stores the byline as meta.
	 * @param {string} element The contenteditable element to read content from.
	 */
	const updateBylineMetaFromContentEditable = useCallback( element => {
		editPost( {
			meta: {
				[ newspackBylines.metaKeyByline ]: transformByline( element ),
			},
		} );

		setTokensInUseFromContentEditable( element );
	} );

	/**
	 * Update the "tokens in use" based on the content.
	 *
	 * @param {Element} element The contenteditable element.
	 */
	const setTokensInUseFromContentEditable = element => {
		const tokenElements = element.querySelectorAll(
			'span button[data-token]'
		);
		const inUse = [ ...tokenElements ].map( span =>
			Number( span.dataset.token )
		);

		setTokensInUse( inUse );
	};

	/**
	 * Insert token into the custom byline contenteditable div.
	 *
	 * @param {Object} token Token prop.
	 */
	const insertToken = token => {
		let { innerHTML } = editableRef.current;

		const tokenId = `token-${ token.id }`;

		// Compound new token element with token data.
		const tokenElement = `<span id="${ tokenId }" contenteditable="false" draggable="true" class="components-form-token-field__token token-inline-block author-token" data-token="${ token.id }">
				<span class="components-form-token-field__token-text">
					${ token.name }
				</span>
				<button
					class="components-button components-form-token-field__remove-token token-inline-block__remove"
					type="button"
					data-token="${ token.id }"
				>
					${ close }
				</button>
			</span>`;

		const insertLocation = cursorPos ?? innerHTML.length;

		if ( insertLocation === innerHTML.length ) {
			innerHTML += '&nbsp;';
		}

		// Assign new token to byline innerHTML (Adds a space to the end allowing insertion of content after token).
		editableRef.current.innerHTML =
			innerHTML.slice( 0, insertLocation ) +
			tokenElement +
			innerHTML.slice( insertLocation );

		// Update byline meta.
		updateBylineMetaFromContentEditable( editableRef.current );

		// Get index of the new token.
		const tokenIndex = Array.from(
			editableRef.current.querySelectorAll( 'span[data-token]' )
		).indexOf( editableRef.current.querySelector( `#${ tokenId }` ) );

		// Set cursor position and focus on the editable element.
		const range = document.createRange();
		range.setStart( editableRef.current, ( tokenIndex + 1 ) * 2 );
		range.collapse( true );
		const selection = editableRef.current.ownerDocument.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );
		editableRef.current.focus();
	};

	/**
	 * Insert the default custom byline.
	 * Used when the custom byline setting is first enabled.
	 */
	const insertDefaultByline = () => {
		let defaultCustomByline;

		// Add author tags and connecting text for each token.
		tokens.forEach( ( token, index ) => {
			if ( index === 0 ) {
				defaultCustomByline = 'By';
			} else if ( index === tokens.length - 1 ) {
				defaultCustomByline =
					tokens.length > 2
						? defaultCustomByline + ', and'
						: defaultCustomByline + ' and';
			} else {
				defaultCustomByline = defaultCustomByline + ',';
			}

			defaultCustomByline =
				defaultCustomByline +
				` [Author id=${ token.id }]${ token.name }[/Author]`;
		} );

		// Don't edit post meta if the string is still empty.
		if ( ! defaultCustomByline ) {
			return;
		}

		// Edit the post meta with the new byline.
		editPost( {
			meta: { [ newspackBylines.metaKeyByline ]: defaultCustomByline },
		} );
	};

	/**
	 * Enable toggle handler.
	 *
	 * @param {boolean} value Boolean, true if custom byline is enabled, false if not.
	 */
	const handleEnableToggle = value => {
		editPost( { meta: { [ newspackBylines.metaKeyActive ]: value } } );
		setIsEnabled( value );
		if ( ! customByline ) {
			insertDefaultByline();
		}
	};

	/**
	 * Initialize the contenteditable element.
	 *
	 * Sets the div's inner HTML with the byline text, sets initial
	 * tokensInUse, and adds event listeners to the remove buttons in token
	 * spans.
	 *
	 * @param {Element} HTML element being rendered.
	 */
	const onMount = useCallback(
		element => {
			if ( ! element || ! isModalOpen ) {
				return;
			}

			editableRef.current = element;
			element.innerHTML = parseForEdit( customByline );
			element.addEventListener( 'blur', updateCursorPos );
			element.addEventListener( 'input', () =>
				updateBylineMetaFromContentEditable( element )
			);
			element.addEventListener( 'click', ( { target } ) => {
				if (
					target.classList.contains( 'token-inline-block__remove' )
				) {
					target.closest( '.token-inline-block' ).remove();
					updateBylineMetaFromContentEditable( element );
				}
			} );
			setTokensInUseFromContentEditable( element );
		},
		[ isModalOpen ]
	);

	/**
	 * Save the current cursor position on blur.
	 *
	 * Stores the cursor offset (in characters of rendered HTML) within the
	 * contentEditable div. This is used to insert the author span "token" at,
	 * as well as to restore cursor position when clicking back into the
	 * editor.
	 */
	const updateCursorPos = () => {
		const { current } = editableRef;
		const selection = current.ownerDocument.getSelection();
		const range = selection.getRangeAt( 0 );

		const clonedRange = range.cloneRange();
		clonedRange.selectNodeContents( current );
		clonedRange.setEnd( range.endContainer, range.endOffset );

		const tempDiv = current.ownerDocument.createElement( 'div' );
		tempDiv.appendChild( clonedRange.cloneContents() );

		setCursorPos( tempDiv.innerHTML.length );
	};

	const textArea = useMemo( () => {
		return (
			<div
				contentEditable
				className="newspack-byline-textarea"
				ref={ onMount }
			/>
		);
	}, [ isModalOpen ] );

	return (
		<PluginDocumentSettingPanel
			className="newspack-byline"
			name="Newspack Byline Settings Panel"
			title={ __( 'Byline', 'newspack-plugin' ) }
		>
			<ToggleControl
				className="newspack-byline-toggle"
				checked={ isEnabled }
				help={ __(
					'Provides flexibility in defining how the byline appears.',
					'newspack-plugin'
				) }
				label={ __( 'Enable custom byline', 'newspack-plugin' ) }
				onChange={ () => handleEnableToggle( ! isEnabled ) }
			/>
			{ isEnabled && (
				<>
					<p
						className="description newspack-byline-preview"
						dangerouslySetInnerHTML={ {
							__html: parseForPreview( customByline ),
						} }
					/>
					<CustomBylineModal
						insertToken={ insertToken }
						tokens={ tokens }
						tokensInUse={ tokensInUse }
						textArea={ textArea }
						isOpen={ isModalOpen }
						setOpen={ setModalOpen }
					/>
				</>
			) }
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'newspack-bylines-sidebar', {
	render: BylinesSettingsPanel,
	icon: false,
} );
