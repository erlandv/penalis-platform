/**
 * Penalis Platform — Cache-Safe Single Post Views Beacon
 *
 * Fires a lightweight POST request to `/penalis/v1/posts/{id}/view` on single
 * published posts, skipping prerender probes and respecting the configured
 * client-side deduplication cookie before making a network request.
 */
( function () {
	'use strict';

	var config = window.penalisPostViews;
	if ( ! config || ! config.postId || ! config.endpoint ) {
		return;
	}

	var postId = String( config.postId );
	var dedupMinutes = Math.max( 0, parseInt( config.dedupMinutes, 10 ) || 0 );
	var cookieName = ( config.cookiePrefix || 'penalis_viewed_' ) + postId;

	function hasCookie( name ) {
		var prefix = name + '=';
		var parts = document.cookie ? document.cookie.split( ';' ) : [];
		for ( var i = 0; i < parts.length; i++ ) {
			if ( parts[ i ].trim().indexOf( prefix ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	function setDedupCookie( minutes ) {
		if ( minutes <= 0 ) {
			return;
		}
		var maxAge = minutes * 60;
		var attrs = '; path=/; max-age=' + maxAge + '; SameSite=Lax';
		if ( window.location && window.location.protocol === 'https:' ) {
			attrs += '; Secure';
		}
		document.cookie = cookieName + '=1' + attrs;
	}

	function sendView() {
		if ( document.visibilityState === 'prerender' ) {
			return;
		}

		if ( dedupMinutes > 0 && hasCookie( cookieName ) ) {
			return;
		}

		if ( typeof window.fetch !== 'function' ) {
			return;
		}

		window.fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			keepalive: true,
			headers: {
				Accept: 'application/json'
			}
		} )
			.then( function ( response ) {
				return response.ok ? response.json().catch( function () {
					return null;
				} ) : null;
			} )
			.then( function ( data ) {
				if ( data && data.counted && dedupMinutes > 0 ) {
					setDedupCookie( dedupMinutes );
				}
			} )
			.catch( function () {} );
	}

	function schedule() {
		if ( document.visibilityState === 'prerender' ) {
			document.addEventListener( 'visibilitychange', function onVisible() {
				if ( document.visibilityState !== 'prerender' ) {
					document.removeEventListener( 'visibilitychange', onVisible );
					sendView();
				}
			} );
			return;
		}

		sendView();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', schedule );
	} else {
		schedule();
	}
}() );
