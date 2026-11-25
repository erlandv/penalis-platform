/**
 * Penalis Platform — Block Editor Post Sidebar Controls for Likes & Dislikes
 *
 * Renders administrator-only Likes and Dislikes rows inside the Post sidebar
 * (PluginPostStatusInfo), matching WordPress's native Post panel rows.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.plugins || ! wp.components || ! wp.apiFetch ) {
		return;
	}

	var config = window.penalisReactionsEditor;
	if ( ! config || ! config.postId || ! config.canEdit ) {
		return;
	}

	var PluginPostStatusInfo =
		( wp.editor && wp.editor.PluginPostStatusInfo ) ||
		( wp.editPost && wp.editPost.PluginPostStatusInfo );

	if ( ! PluginPostStatusInfo ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var Dropdown = wp.components.Dropdown;
	var Button = wp.components.Button;
	var TextControl = wp.components.TextControl;

	function formatCount( value ) {
		var num = Math.max( 0, parseInt( value, 10 ) || 0 );
		if ( typeof Intl !== 'undefined' && typeof Intl.NumberFormat === 'function' ) {
			try {
				return new Intl.NumberFormat( 'id-ID' ).format( num );
			} catch ( err ) {
				return String( num );
			}
		}
		return String( num );
	}

	function parseNonNegativeInt( raw ) {
		var clean = String( raw === undefined || raw === null ? '' : raw ).trim();
		if ( ! /^\d+$/.test( clean ) ) {
			return null;
		}
		var parsed = parseInt( clean, 10 );
		return isFinite( parsed ) && parsed >= 0 ? parsed : null;
	}

	function notify( type, message ) {
		if ( ! wp.data || ! wp.data.dispatch ) {
			return;
		}
		var notices = wp.data.dispatch( 'core/notices' );
		if ( ! notices ) {
			return;
		}
		if ( type === 'error' && notices.createErrorNotice ) {
			notices.createErrorNotice( message, { id: 'penalis-reactions-admin', isDismissible: true } );
		} else if ( notices.createSuccessNotice ) {
			notices.createSuccessNotice( message, { id: 'penalis-reactions-admin', type: 'snackbar', isDismissible: true } );
		}
	}

	function ReactionRow( props ) {
		var label = props.label;
		var value = props.value;
		var saving = props.saving;
		var onSave = props.onSave;

		return el(
			PluginPostStatusInfo,
			{ className: 'penalis-editor-reaction-row' },
			el( 'div', { className: 'editor-post-panel__row-label' }, label ),
			el(
				'div',
				{ className: 'editor-post-panel__row-control' },
				el( Dropdown, {
					popoverProps: { placement: 'bottom-end' },
					renderToggle: function ( toggleProps ) {
						return el(
							Button,
							{
								size: 'compact',
								variant: 'tertiary',
								className: 'penalis-editor-reaction-toggle',
								'aria-expanded': toggleProps.isOpen,
								'aria-label': 'Ubah jumlah ' + label + ' (' + formatCount( value ) + ')',
								onClick: toggleProps.onToggle,
								disabled: saving
							},
							formatCount( value )
						);
					},
					renderContent: function ( contentProps ) {
						return el( ReactionPopoverForm, {
							label: label,
							initialValue: value,
							saving: saving,
							onClose: contentProps.onClose,
							onSubmit: function ( nextVal ) {
								onSave( nextVal, contentProps.onClose );
							}
						} );
					}
				} )
			)
		);
	}

	function ReactionPopoverForm( props ) {
		var state = useState( String( props.initialValue ) );
		var draft = state[ 0 ];
		var setDraft = state[ 1 ];
		var errState = useState( '' );
		var error = errState[ 0 ];
		var setError = errState[ 1 ];

		function handleSubmit( event ) {
			if ( event && event.preventDefault ) {
				event.preventDefault();
			}
			var parsed = parseNonNegativeInt( draft );
			if ( parsed === null ) {
				setError( 'Masukkan angka bulat 0 atau lebih.' );
				return;
			}
			setError( '' );
			props.onSubmit( parsed );
		}

		return el(
			'form',
			{
				className: 'penalis-editor-reactions-popover',
				onSubmit: handleSubmit
			},
			el( TextControl, {
				__nextHasNoMarginBottom: true,
				label: props.label,
				type: 'number',
				min: 0,
				step: 1,
				inputMode: 'numeric',
				value: draft,
				disabled: props.saving,
				onChange: function ( next ) {
					setDraft( next );
					if ( error ) {
						setError( '' );
					}
				}
			} ),
			error ? el( 'p', { className: 'penalis-editor-reactions-popover__error' }, error ) : null,
			el(
				'p',
				{ className: 'penalis-editor-reactions-popover__help' },
				'Ubah angka secara manual untuk post ini.'
			),
			el(
				'div',
				{ className: 'penalis-editor-reactions-popover__actions' },
				el(
					Button,
					{
						type: 'submit',
						variant: 'primary',
						size: 'compact',
						isBusy: props.saving,
						disabled: props.saving
					},
					'Simpan'
				),
				el(
					Button,
					{
						type: 'button',
						variant: 'tertiary',
						size: 'compact',
						disabled: props.saving,
						onClick: props.onClose
					},
					'Batal'
				)
			)
		);
	}

	function PenalisReactionsSidebar() {
		var likeState = useState( Math.max( 0, parseInt( config.like, 10 ) || 0 ) );
		var likeCount = likeState[ 0 ];
		var setLikeCount = likeState[ 1 ];

		var dislikeState = useState( Math.max( 0, parseInt( config.dislike, 10 ) || 0 ) );
		var dislikeCount = dislikeState[ 0 ];
		var setDislikeCount = dislikeState[ 1 ];

		var savingState = useState( false );
		var saving = savingState[ 0 ];
		var setSaving = savingState[ 1 ];

		function persist( nextLike, nextDislike, onDone ) {
			setSaving( true );

			wp.apiFetch( {
				path: config.restPath,
				method: 'PUT',
				data: {
					like: nextLike,
					dislike: nextDislike
				}
			} )
				.then( function ( response ) {
					if ( response && typeof response.like === 'number' ) {
						setLikeCount( response.like );
					} else {
						setLikeCount( nextLike );
					}
					if ( response && typeof response.dislike === 'number' ) {
						setDislikeCount( response.dislike );
					} else {
						setDislikeCount( nextDislike );
					}
					notify( 'success', 'Jumlah Likes & Dislikes diperbarui.' );
					if ( typeof onDone === 'function' ) {
						onDone();
					}
				} )
				.catch( function ( err ) {
					var msg = err && err.message ? err.message : 'Jumlah Likes & Dislikes tidak dapat disimpan.';
					notify( 'error', msg );
				} )
				.finally( function () {
					setSaving( false );
				} );
		}

		return el(
			Fragment,
			null,
			el( ReactionRow, {
				label: 'Likes',
				value: likeCount,
				saving: saving,
				onSave: function ( nextLike, close ) {
					persist( nextLike, dislikeCount, close );
				}
			} ),
			el( ReactionRow, {
				label: 'Dislikes',
				value: dislikeCount,
				saving: saving,
				onSave: function ( nextDislike, close ) {
					persist( likeCount, nextDislike, close );
				}
			} )
		);
	}

	wp.plugins.registerPlugin( 'penalis-post-reactions', {
		icon: null,
		render: PenalisReactionsSidebar
	} );
}( window.wp ) );
