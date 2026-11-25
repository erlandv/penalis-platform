/**
 * Penalis Platform — Block Editor Post Sidebar Control for Views
 *
 * Renders an administrator-only Views row inside the Post sidebar
 * (PluginPostStatusInfo) when Penalis Views is active.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.plugins || ! wp.components || ! wp.apiFetch ) {
		return;
	}

	var config = window.penalisViewsEditor;
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
			notices.createErrorNotice( message, { id: 'penalis-views-admin', isDismissible: true } );
		} else if ( notices.createSuccessNotice ) {
			notices.createSuccessNotice( message, { id: 'penalis-views-admin', type: 'snackbar', isDismissible: true } );
		}
	}

	function ViewsPopoverForm( props ) {
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
				label: 'Views',
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
				'Ubah jumlah views secara manual untuk post ini.'
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

	function PenalisViewsSidebar() {
		var viewsState = useState( Math.max( 0, parseInt( config.views, 10 ) || 0 ) );
		var viewsCount = viewsState[ 0 ];
		var setViewsCount = viewsState[ 1 ];

		var savingState = useState( false );
		var saving = savingState[ 0 ];
		var setSaving = savingState[ 1 ];

		function persist( nextViews, onDone ) {
			setSaving( true );

			wp.apiFetch( {
				path: config.restPath,
				method: 'PUT',
				data: {
					views: nextViews
				}
			} )
				.then( function ( response ) {
					if ( response && typeof response.views === 'number' ) {
						setViewsCount( response.views );
					} else {
						setViewsCount( nextViews );
					}
					notify( 'success', 'Jumlah Views diperbarui.' );
					if ( typeof onDone === 'function' ) {
						onDone();
					}
				} )
				.catch( function ( err ) {
					var msg = err && err.message ? err.message : 'Jumlah Views tidak dapat disimpan.';
					notify( 'error', msg );
				} )
				.finally( function () {
					setSaving( false );
				} );
		}

		return el(
			PluginPostStatusInfo,
			{ className: 'penalis-editor-reaction-row' },
			el( 'div', { className: 'editor-post-panel__row-label' }, 'Views' ),
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
								'aria-label': 'Ubah jumlah Views (' + formatCount( viewsCount ) + ')',
								onClick: toggleProps.onToggle,
								disabled: saving
							},
							formatCount( viewsCount )
						);
					},
					renderContent: function ( contentProps ) {
						return el( ViewsPopoverForm, {
							initialValue: viewsCount,
							saving: saving,
							onClose: contentProps.onClose,
							onSubmit: function ( nextVal ) {
								persist( nextVal, contentProps.onClose );
							}
						} );
					}
				} )
			)
		);
	}

	wp.plugins.registerPlugin( 'penalis-post-views', {
		icon: null,
		render: PenalisViewsSidebar
	} );
}( window.wp ) );
