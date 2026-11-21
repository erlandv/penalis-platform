/**
 * Penalis Platform — Public Author Archive Interactions
 *
 * Handles native Web Share API with clipboard fallback on the Share button and
 * direct copy-to-clipboard on the profile URL button.
 */
( function () {
	'use strict';

	function copyText( text ) {
		if ( navigator.clipboard && typeof navigator.clipboard.writeText === 'function' ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var input = document.createElement( 'textarea' );
			input.value = text;
			input.setAttribute( 'readonly', '' );
			input.style.position = 'fixed';
			input.style.top = '-9999px';
			document.body.appendChild( input );
			input.select();

			try {
				var ok = document.execCommand( 'copy' );
				document.body.removeChild( input );
				if ( ok ) {
					resolve();
				} else {
					reject( new Error( 'Copy failed' ) );
				}
			} catch ( err ) {
				document.body.removeChild( input );
				reject( err );
			}
		} );
	}

	function announceCopied( button, labelEl ) {
		var defaultLabel = button.getAttribute( 'data-default-label' ) || '';
		var copiedLabel = button.getAttribute( 'data-copied-label' ) || 'Tautan disalin!';
		var statusEl = document.querySelector( '[data-penalis-archive-status]' );

		if ( labelEl ) {
			labelEl.textContent = copiedLabel;
		}
		button.classList.add( 'is-copied' );

		if ( statusEl ) {
			statusEl.textContent = copiedLabel;
		}

		if ( button._penalisTimer ) {
			window.clearTimeout( button._penalisTimer );
		}

		button._penalisTimer = window.setTimeout( function () {
			if ( labelEl && defaultLabel ) {
				labelEl.textContent = defaultLabel;
			}
			button.classList.remove( 'is-copied' );
		}, 2200 );
	}

	function initShareButton() {
		var shareButtons = document.querySelectorAll( '[data-penalis-share]' );
		for ( var i = 0; i < shareButtons.length; i++ ) {
			shareButtons[ i ].addEventListener( 'click', function () {
				var btn = this;
				var url = btn.getAttribute( 'data-share-url' ) || window.location.href;
				var title = btn.getAttribute( 'data-share-title' ) || document.title;
				var text = btn.getAttribute( 'data-share-text' ) || '';
				var labelEl = btn.querySelector( '[data-penalis-share-label]' );

				if ( typeof navigator.share === 'function' ) {
					navigator.share( { title: title, text: text, url: url } ).catch( function ( err ) {
						if ( err && err.name === 'AbortError' ) {
							return;
						}
						copyText( url ).then( function () {
							announceCopied( btn, labelEl );
						} ).catch( function () {} );
					} );
					return;
				}

				copyText( url ).then( function () {
					announceCopied( btn, labelEl );
				} ).catch( function () {} );
			} );
		}
	}

	function initCopyUrlButton() {
		var copyButtons = document.querySelectorAll( '[data-penalis-copy-url]' );
		for ( var i = 0; i < copyButtons.length; i++ ) {
			copyButtons[ i ].addEventListener( 'click', function () {
				var btn = this;
				var url = btn.getAttribute( 'data-copy-url' ) || window.location.href;
				var labelEl = btn.querySelector( '[data-penalis-copy-label]' );

				copyText( url ).then( function () {
					announceCopied( btn, labelEl );
				} ).catch( function () {} );
			} );
		}
	}

	function init() {
		initShareButton();
		initCopyUrlButton();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
