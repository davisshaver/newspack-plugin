/* globals newspack_reader_auth_labels */

/**
 * Internal dependencies.
 */
import './auth.scss';

/**
 * Specify a function to execute when the DOM is fully loaded.
 *
 * @see https://github.com/WordPress/gutenberg/blob/trunk/packages/dom-ready/
 *
 * @param {Function} callback A function to execute after the DOM is ready.
 * @return {void}
 */
function domReady( callback ) {
	if ( typeof document === 'undefined' ) {
		return;
	}
	if (
		document.readyState === 'complete' || // DOMContentLoaded + Images/Styles/etc loaded, so we call directly.
		document.readyState === 'interactive' // DOMContentLoaded fires at this point, so we call directly.
	) {
		return void callback();
	}
	// DOMContentLoaded has not fired yet, delay callback until then.
	document.addEventListener( 'DOMContentLoaded', callback );
}

/**
 * Converts FormData into an object.
 *
 * @param {FormData} formData       The form data to convert.
 * @param {Array}    includedFields Form fields to include.
 *
 * @return {Object} The converted form data.
 */
const convertFormDataToObject = ( formData, includedFields = [] ) =>
	Array.from( formData.entries() ).reduce( ( acc, [ key, val ] ) => {
		if ( ! includedFields.includes( key ) ) {
			return acc;
		}
		if ( key.indexOf( '[]' ) > -1 ) {
			key = key.replace( '[]', '' );
			acc[ key ] = acc[ key ] || [];
			acc[ key ].push( val );
		} else {
			acc[ key ] = val;
		}
		return acc;
	}, {} );

( function ( readerActivation ) {
	domReady( function () {
		if ( ! readerActivation ) {
			return;
		}

		const containers = [ ...document.querySelectorAll( '.newspack-reader-auth' ) ];
		if ( ! containers.length ) {
			return;
		}

		let currentlyOpenOverlayPrompts = [];
		let overlayPromptOrigin = null;
		const hideCurrentlyOpenOverlayPrompts = () =>
			currentlyOpenOverlayPrompts.forEach( promptElement =>
				promptElement.setAttribute( 'amp-access-hide', '' )
			);
		const displayCurrentlyOpenOverlayPrompts = () => {
			const reader = readerActivation.getReader();
			const loginFromPrompt = reader?.email && overlayPromptOrigin;
			currentlyOpenOverlayPrompts.forEach( promptElement => {
				if ( loginFromPrompt && overlayPromptOrigin.isEqualNode( promptElement ) ) {
					promptElement.setAttribute( 'amp-access-hide', '' );
				} else {
					promptElement.removeAttribute( 'amp-access-hide' );
				}
			} );
		};

		let accountLinks, triggerLinks;
		const initLinks = function () {
			accountLinks = document.querySelectorAll( '.newspack-reader__account-link' );
			triggerLinks = document.querySelectorAll( '[data-newspack-reader-account-link]' );
			triggerLinks.forEach( link => {
				link.addEventListener( 'click', handleAccountLinkClick );
			} );
		};
		initLinks();
		/** Re-initialize links in case the navigation DOM was modified by a third-party. */
		setTimeout( initLinks, 1000 );

		/**
		 * Handle reader changes.
		 */
		function handleReaderChanges() {
			const allContainers = [ ...document.querySelectorAll( '.newspack-reader-auth' ) ];
			/** If no modal login form, bail. */
			if ( ! allContainers.length ) {
				return;
			}

			allContainers.forEach( container => {
				const form = container.querySelector( 'form' );
				const emailInput = container.querySelector( 'input[name="npe"]' );
				const redirectInput = container.querySelector( 'input[name="redirect"]' );
				const reader = readerActivation.getReader();

				if ( emailInput ) {
					emailInput.value = reader?.email || '';
				}

				if ( accountLinks?.length ) {
					accountLinks.forEach( link => {
						/** If there's a pre-auth, signing in redirects to the reader account. */
						if ( reader?.email && ! reader?.authenticated ) {
							link.setAttribute( 'data-redirect', link.getAttribute( 'href' ) );
							redirectInput.value = link.getAttribute( 'href' );
						} else {
							link.removeAttribute( 'data-redirect' );
						}
						try {
							const labels = JSON.parse( link.getAttribute( 'data-labels' ) );
							link.querySelector( '.newspack-reader__account-link__label' ).textContent =
								reader?.email ? labels.signedin : labels.signedout;
						} catch {}
					} );
				}
				if ( reader?.authenticated ) {
					const messageContentElement = container.querySelector(
						'.newspack-reader__auth-form__response__content'
					);
					if ( messageContentElement ) {
						form.replaceWith( messageContentElement.parentNode );
					}
				}
			} );
		}
		readerActivation.on( 'reader', handleReaderChanges );
		handleReaderChanges();

		/**
		 * Handle account links.
		 */
		function handleAccountLinkClick( ev ) {
			const reader = readerActivation.getReader();
			/** If logged in, bail and allow page redirection. */
			if ( reader?.authenticated ) {
				return;
			}

			const container = document.querySelector(
				'.newspack-reader-auth:not(.newspack-reader__auth-form__inline)'
			);
			/** If no modal login form, bail. */
			if ( ! container ) {
				return;
			}

			ev.preventDefault();

			const authLinkMessage = container.querySelector( '[data-has-auth-link]' );
			const emailInput = container.querySelector( 'input[name="npe"]' );
			const redirectInput = container.querySelector( 'input[name="redirect"]' );
			const passwordInput = container.querySelector( 'input[name="password"]' );
			const actionInput = container.querySelector( 'input[name="action"]' );

			if ( authLinkMessage ) {
				if ( readerActivation.hasAuthLink() ) {
					authLinkMessage.style.display = 'flex';
				} else {
					authLinkMessage.style.display = 'none';
				}
			}

			if ( emailInput ) {
				emailInput.value = reader?.email || '';
			}

			if ( redirectInput && ev.target.getAttribute( 'data-redirect' ) ) {
				redirectInput.value = ev.target.getAttribute( 'data-redirect' );
			}

			container.hidden = false;
			container.style.display = 'flex';

			currentlyOpenOverlayPrompts = document.querySelectorAll(
				'.newspack-lightbox:not([amp-access-hide])'
			);
			overlayPromptOrigin = ev.currentTarget.closest( '.newspack-lightbox' );

			hideCurrentlyOpenOverlayPrompts();

			if ( passwordInput && emailInput?.value && 'pwd' === actionInput?.value ) {
				passwordInput.focus();
			} else {
				emailInput.focus();
			}
		}

		containers.forEach( container => {
			const initialForm = container.querySelector( 'form' );
			let form;
			/** Workaround AMP's enforced XHR strategy. */
			if ( initialForm.getAttribute( 'action-xhr' ) ) {
				initialForm.removeAttribute( 'action-xhr' );
				form = initialForm.cloneNode( true );
				initialForm.replaceWith( form );
			} else {
				form = initialForm;
			}

			const actionInput = form.querySelector( 'input[name="action"]' );
			const emailInput = form.querySelector( 'input[name="npe"]' );
			const passwordInput = form.querySelector( 'input[name="password"]' );
			const submitButtons = form.querySelectorAll( '[type="submit"]' );
			const closeButton = container.querySelector( 'button[data-close]' );

			if ( closeButton ) {
				closeButton.addEventListener( 'click', function ( ev ) {
					ev.preventDefault();
					container.classList.remove( 'newspack-reader__auth-form__visible' );
					container.style.display = 'none';
					displayCurrentlyOpenOverlayPrompts();
				} );
			}

			const messageContentElement = container.querySelector(
				'.newspack-reader__auth-form__response__content'
			);

			const authLinkMessage = container.querySelector( '[data-has-auth-link]' );
			authLinkMessage.hidden = true;

			/**
			 * Handle auth form action selection.
			 */
			function setFormAction( action ) {
				if ( [ 'link', 'pwd' ].includes( action ) ) {
					readerActivation.setAuthStrategy( action );
				}
				actionInput.value = action;
				container.removeAttribute( 'data-form-status' );
				messageContentElement.innerHTML = '';
				container.querySelectorAll( '[data-action]' ).forEach( item => {
					if ( 'none' !== item.style.display ) {
						item.prevDisplay = item.style.display;
					}
					item.style.display = 'none';
				} );
				container.querySelectorAll( '[data-action~="' + action + '"]' ).forEach( item => {
					item.style.display = item.prevDisplay;
				} );
				try {
					const labels = JSON.parse( container.getAttribute( 'data-labels' ) );
					const label = 'register' === action ? labels.register : labels.signin;
					container.querySelector( 'h2' ).textContent = label;
				} catch {}
				if ( action === 'pwd' && emailInput.value ) {
					passwordInput.focus();
				} else {
					emailInput.focus();
				}
			}
			setFormAction( readerActivation.getAuthStrategy() || 'pwd' );
			container.querySelectorAll( '[data-set-action]' ).forEach( item => {
				item.addEventListener( 'click', function ( ev ) {
					ev.preventDefault();
					setFormAction( ev.target.getAttribute( 'data-set-action' ) );
				} );
			} );

			form.startLoginFlow = () => {
				container.removeAttribute( 'data-form-status' );
				submitButtons.forEach( button => {
					button.disabled = true;
				} );
				messageContentElement.innerHTML = '';
				form.style.opacity = 0.5;
			};

			form.endLoginFlow = ( message = null, status = 500, data = null, redirect ) => {
				if ( message ) {
					const messageNode = document.createElement( 'p' );
					messageNode.textContent = message;
					messageContentElement.appendChild( messageNode );
				}
				if ( status === 200 && data ) {
					const authenticated = !! data?.authenticated;
					readerActivation.setReaderEmail( data.email );
					readerActivation.setAuthenticated( authenticated );
					if ( authenticated ) {
						if ( redirect ) {
							window.location = redirect;
						}
					} else {
						form.replaceWith( messageContentElement.parentNode );
					}
				}
				container.setAttribute( 'data-form-status', status );
				form.style.opacity = 1;
				submitButtons.forEach( button => {
					button.disabled = false;
				} );
			};

			/**
			 * Handle auth form submission.
			 */
			form.addEventListener( 'submit', ev => {
				ev.preventDefault();
				form.startLoginFlow();

				if ( ! form.npe?.value ) {
					return form.endLoginFlow( newspack_reader_auth_labels.invalid_email, 400 );
				}

				if ( 'pwd' === actionInput?.value && ! form.password?.value ) {
					return form.endLoginFlow( newspack_reader_auth_labels.invalid_password, 400 );
				}

				readerActivation
					.getCaptchaToken()
					.then( captchaToken => {
						if ( ! captchaToken ) {
							return;
						}
						let tokenField = form.captcha_token;
						if ( ! tokenField ) {
							tokenField = document.createElement( 'input' );
							tokenField.setAttribute( 'type', 'hidden' );
							tokenField.setAttribute( 'name', 'captcha_token' );
							form.appendChild( tokenField );
						}
						tokenField.value = captchaToken;
					} )
					.catch( e => {
						form.endLoginFlow( e, 400 );
					} )
					.finally( () => {
						const body = new FormData( ev.target );
						if ( ! body.has( 'npe' ) || ! body.get( 'npe' ) ) {
							return form.endFlow( newspack_reader_auth_labels.invalid_email, 400 );
						}
						readerActivation.setReaderEmail( body.get( 'npe' ) );
						fetch( form.getAttribute( 'action' ) || window.location.pathname, {
							method: 'POST',
							headers: {
								Accept: 'application/json',
							},
							body,
						} )
							.then( res => {
								container.setAttribute( 'data-form-status', res.status );
								res
									.json()
									.then( ( { message, data } ) => {
										form.endLoginFlow( message, res.status, data, body.get( 'redirect' ) );
									} )
									.catch( () => {
										form.endLoginFlow();
									} );
							} )
							.catch( () => {
								form.endLoginFlow();
							} );
					} );
			} );
		} );

		/**
		 * Third party auth.
		 */
		const loginsElements = document.querySelectorAll( '.newspack-reader__logins' );
		[ ...loginsElements ].forEach( element => {
			element.classList.remove( 'newspack-reader__logins--disabled' );
		} );
		const googleLoginElements = document.querySelectorAll( '.newspack-reader__logins__google' );
		googleLoginElements.forEach( googleLoginElement => {
			const googleLoginForm = googleLoginElement.closest( 'form' );
			const redirectInput = googleLoginForm.querySelector( 'input[name="redirect"]' );
			const checkLoginStatus = metadata => {
				fetch(
					`/wp-json/newspack/v1/login/google/register?metadata=${ JSON.stringify( metadata ) }`
				)
					.then( res => {
						res
							.json()
							.then( ( { message, data } ) => {
								const redirect = redirectInput?.value || null;
								if ( googleLoginForm?.endLoginFlow ) {
									googleLoginForm.endLoginFlow( message, res.status, data, redirect );
								}
							} )
							.catch( error => {
								if ( googleLoginForm?.endLoginFlow ) {
									googleLoginForm.endLoginFlow( error?.message, res.status );
								}
							} );
					} )
					.catch( error => {
						if ( googleLoginForm?.endLoginFlow ) {
							googleLoginForm.endLoginFlow( error?.message );
						}
					} );
			};

			googleLoginElement.addEventListener( 'click', () => {
				if ( googleLoginForm?.startLoginFlow ) {
					googleLoginForm.startLoginFlow();
				}

				const metadata = googleLoginForm
					? convertFormDataToObject( new FormData( googleLoginForm ), [ 'lists[]' ] )
					: {};
				metadata.current_page_url = window.location.href;
				const authWindow = window.open(
					'about:blank',
					'newspack_google_login',
					'width=500,height=600'
				);
				fetch( '/wp-json/newspack/v1/login/google' )
					.then( res => res.json().then( data => Promise.resolve( { data, status: res.status } ) ) )
					.then( ( { data, status } ) => {
						if ( status !== 200 ) {
							if ( authWindow ) {
								authWindow.close();
							}
							if ( googleLoginForm?.endLoginFlow ) {
								googleLoginForm.endLoginFlow( data.message, status );
							}
						} else if ( authWindow ) {
							authWindow.location = data;
							const interval = setInterval( () => {
								if ( authWindow.closed ) {
									checkLoginStatus( metadata );
									clearInterval( interval );
								}
							}, 500 );
						} else if ( googleLoginForm?.endLoginFlow ) {
							googleLoginForm.endLoginFlow( newspack_reader_auth_labels.blocked_popup );
						}
					} )
					.catch( error => {
						console.log( error );
						if ( googleLoginForm?.endLoginFlow ) {
							googleLoginForm.endLoginFlow( error?.message, 400 );
						}
						if ( authWindow ) {
							authWindow.close();
						}
					} );
			} );
		} );
	} );
} )( window.newspackReaderActivation );