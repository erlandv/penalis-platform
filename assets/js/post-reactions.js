/**
 * Penalis Platform — Single Post Reactions (Like & Dislike)
 *
 * Hydrates per-visitor cookie state locally so full-page-cached articles stay
 * cache-safe, and toggles reactions via the public Penalis REST endpoint.
 */
( function () {
	'use strict';

	var COOKIE_PREFIX = 'penalis_react_';
	var LEGACY_PREFIX = 'pld_';
	var COOKIE_MAX_AGE = 31536000; // 365 days.
	var inFlight = {};

	function readCookie( name ) {
		var prefix = name + '=';
		var parts = document.cookie ? document.cookie.split( ';' ) : [];

		for ( var i = 0; i < parts.length; i++ ) {
			var item = parts[ i ].trim();
			if ( item.indexOf( prefix ) === 0 ) {
				try {
					return decodeURIComponent( item.slice( prefix.length ) );
				} catch ( err ) {
					return item.slice( prefix.length );
				}
			}
		}

		return null;
	}

	function readReactionCookie( postId ) {
		var primary = readCookie( COOKIE_PREFIX + postId );
		if ( primary !== null ) {
			if ( primary === 'like' || primary === 'dislike' ) {
				return primary;
			}
			return '';
		}

		var legacy = readCookie( LEGACY_PREFIX + postId );
		if ( legacy === 'like' || legacy === 'dislike' ) {
			return legacy;
		}

		return '';
	}

	function writeReactionCookie( postId, reaction ) {
		var value = reaction === 'like' || reaction === 'dislike' ? reaction : 'none';
		var attrs = '; path=/; max-age=' + COOKIE_MAX_AGE + '; SameSite=Lax';
		if ( window.location && window.location.protocol === 'https:' ) {
			attrs += '; Secure';
		}
		document.cookie = COOKIE_PREFIX + postId + '=' + value + attrs;
	}

	function formatCount( count ) {
		var num = Math.max( 0, parseInt( count, 10 ) || 0 );
		if ( typeof Intl !== 'undefined' && typeof Intl.NumberFormat === 'function' ) {
			try {
				return new Intl.NumberFormat( 'id-ID' ).format( num );
			} catch ( err ) {
				return String( num );
			}
		}
		return String( num );
	}

	function getContainers( postId ) {
		return document.querySelectorAll( '[data-penalis-reactions][data-post-id="' + postId + '"]' );
	}

	function applyState( postId, reaction, counts ) {
		var containers = getContainers( postId );

		for ( var i = 0; i < containers.length; i++ ) {
			var container = containers[ i ];
			var buttons = container.querySelectorAll( '[data-reaction]' );

			for ( var j = 0; j < buttons.length; j++ ) {
				var btn = buttons[ j ];
				var btnType = btn.getAttribute( 'data-reaction' );
				var active = reaction !== '' && btnType === reaction;
				btn.classList.toggle( 'is-active', active );
				btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			}

			if ( counts ) {
				var likeEl = container.querySelector( '[data-reaction-count="like"]' );
				var dislikeEl = container.querySelector( '[data-reaction-count="dislike"]' );
				if ( likeEl && typeof counts.like === 'number' ) {
					likeEl.textContent = formatCount( counts.like );
				}
				if ( dislikeEl && typeof counts.dislike === 'number' ) {
					dislikeEl.textContent = formatCount( counts.dislike );
				}
			}
		}
	}

	function setBusy( postId, busy ) {
		var containers = getContainers( postId );
		for ( var i = 0; i < containers.length; i++ ) {
			var buttons = containers[ i ].querySelectorAll( '[data-reaction]' );
			for ( var j = 0; j < buttons.length; j++ ) {
				buttons[ j ].disabled = busy;
				if ( busy ) {
					buttons[ j ].setAttribute( 'aria-busy', 'true' );
				} else {
					buttons[ j ].removeAttribute( 'aria-busy' );
				}
			}
		}
	}

	function announce( postId, message ) {
		var containers = getContainers( postId );
		for ( var i = 0; i < containers.length; i++ ) {
			var statusEl = containers[ i ].querySelector( '[data-reaction-status]' );
			if ( statusEl ) {
				statusEl.textContent = message;
			}
		}
	}

	function handleClick( container, button ) {
		var postId = container.getAttribute( 'data-post-id' );
		var endpoint = container.getAttribute( 'data-endpoint' );
		var type = button.getAttribute( 'data-reaction' );

		if ( ! postId || ! endpoint || ! type || inFlight[ postId ] ) {
			return;
		}

		inFlight[ postId ] = true;
		setBusy( postId, true );

		window.fetch( endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json'
			},
			body: JSON.stringify( { type: type } )
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return null;
				} ).then( function ( body ) {
					return { ok: response.ok, body: body };
				} );
			} )
			.then( function ( res ) {
				if ( ! res.ok || ! res.body || typeof res.body.like !== 'number' ) {
					var msg = ( res.body && res.body.message ) ? String( res.body.message ) : 'Reaksi tidak dapat disimpan. Coba lagi.';
					announce( postId, msg );
					return;
				}

				var nextReaction = res.body.reaction === 'like' || res.body.reaction === 'dislike' ? res.body.reaction : '';
				writeReactionCookie( postId, nextReaction );
				applyState( postId, nextReaction, {
					like: res.body.like,
					dislike: res.body.dislike
				} );

				if ( nextReaction === 'like' ) {
					announce( postId, 'Terima kasih telah menyukai karya ini.' );
				} else if ( nextReaction === 'dislike' ) {
					announce( postId, 'Reaksi tidak suka tersimpan.' );
				} else {
					announce( postId, 'Reaksi dibatalkan.' );
				}
			} )
			.catch( function () {
				announce( postId, 'Koneksi bermasalah. Coba lagi beberapa saat lagi.' );
			} )
			.then( function () {
				inFlight[ postId ] = false;
				setBusy( postId, false );
			} );
	}

	function init() {
		var containers = document.querySelectorAll( '[data-penalis-reactions]' );
		if ( ! containers.length ) {
			return;
		}

		var hydrated = {};
		for ( var i = 0; i < containers.length; i++ ) {
			var container = containers[ i ];
			var postId = container.getAttribute( 'data-post-id' );
			if ( postId && ! hydrated[ postId ] ) {
				hydrated[ postId ] = true;
				var saved = readReactionCookie( postId );
				if ( saved ) {
					applyState( postId, saved, null );
				}
			}

			container.addEventListener( 'click', function ( event ) {
				var target = event.target && event.target.closest ? event.target.closest( '[data-reaction]' ) : null;
				if ( target && this.contains( target ) ) {
					handleClick( this, target );
				}
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
