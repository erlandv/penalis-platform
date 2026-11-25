/**
 * Penalis Platform — Quick Edit Hydration for Post Views
 *
 * Populates the Views Quick Edit input on `/wp-admin/edit.php` from the row's
 * server-rendered data attribute, including after inline-save refreshes the row.
 */
( function () {
	'use strict';

	function attachQuickEditHook() {
		if ( ! window.inlineEditPost || typeof window.inlineEditPost.edit !== 'function' ) {
			return;
		}

		var origEdit = window.inlineEditPost.edit;

		window.inlineEditPost.edit = function ( id ) {
			origEdit.apply( this, arguments );

			var postId = 0;
			if ( typeof id === 'object' && id !== null ) {
				postId = parseInt( this.getId( id ), 10 ) || 0;
			} else {
				postId = parseInt( id, 10 ) || 0;
			}

			if ( postId <= 0 ) {
				return;
			}

			var postRow = document.getElementById( 'post-' + postId );
			var editRow = document.getElementById( 'edit-' + postId );
			if ( ! postRow || ! editRow ) {
				return;
			}

			var source = postRow.querySelector( '[data-penalis-views]' );
			if ( ! source ) {
				return;
			}

			var viewsVal = source.getAttribute( 'data-penalis-views' ) || '0';
			var viewsInput = editRow.querySelector( 'input[name="penalis_views_count"]' );
			var origInput = editRow.querySelector( 'input[name="penalis_views_orig"]' );

			if ( viewsInput ) {
				viewsInput.value = viewsVal;
			}
			if ( origInput ) {
				origInput.value = viewsVal;
			}
		};
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', attachQuickEditHook );
	} else {
		attachQuickEditHook();
	}
}() );
