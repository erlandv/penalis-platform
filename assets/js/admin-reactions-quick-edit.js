/**
 * Penalis Platform — Quick Edit Hydration for Post Likes & Dislikes
 *
 * Populates the Likes and Dislikes Quick Edit inputs on `/wp-admin/edit.php`
 * from the row's server-rendered data attributes, including after inline-save
 * refreshes the row.
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

			var source = postRow.querySelector( '[data-penalis-like]' );
			if ( ! source ) {
				return;
			}

			var likeVal = source.getAttribute( 'data-penalis-like' ) || '0';
			var dislikeVal = source.getAttribute( 'data-penalis-dislike' ) || '0';

			var likeInput = editRow.querySelector( 'input[name="penalis_reactions_like"]' );
			var dislikeInput = editRow.querySelector( 'input[name="penalis_reactions_dislike"]' );
			var origLikeInput = editRow.querySelector( 'input[name="penalis_reactions_orig_like"]' );
			var origDislikeInput = editRow.querySelector( 'input[name="penalis_reactions_orig_dislike"]' );

			if ( likeInput ) {
				likeInput.value = likeVal;
			}
			if ( dislikeInput ) {
				dislikeInput.value = dislikeVal;
			}
			if ( origLikeInput ) {
				origLikeInput.value = likeVal;
			}
			if ( origDislikeInput ) {
				origDislikeInput.value = dislikeVal;
			}
		};
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', attachQuickEditHook );
	} else {
		attachQuickEditHook();
	}
}() );
