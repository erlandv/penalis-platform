/**
 * Penalis Platform — Administrator Confirmation Dialogs
 *
 * 1. Article review decisions on Review Karya Tulis (`?page=penalis-review&post_id=...`)
 * 2. Writer account actions on Penulis Penalis (`?page=penalis-writers`)
 * 3. Published-article recovery on Karya Terhapus (`?page=penalis-trashed`)
 */
( function () {
	'use strict';

	var REVIEW_ACTION_CONFIG = {
		approve: {
			badge: 'Setujui & Terbitkan',
			badgeClass: 'penalis-badge penalis-badge--active',
			title: 'Setujui dan terbitkan karya tulis ini?',
			description: 'Karya tulis akan langsung diterbitkan di penalis.com dan penulis akan menerima notifikasi persetujuan.',
			confirmLabel: 'Ya, Setujui & Terbitkan',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve',
			requiresFeedback: false,
			showFeedbackBox: true
		},
		request_changes: {
			badge: 'Minta Revisi',
			badgeClass: 'penalis-badge penalis-badge--changes_requested',
			title: 'Minta revisi kepada penulis?',
			description: 'Karya tulis akan dikembalikan ke penulis dengan status Perlu revisi beserta catatan perbaikan di bawah ini.',
			confirmLabel: 'Ya, Minta Revisi',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-changes',
			requiresFeedback: true,
			emptyFeedbackError: 'Catatan untuk penulis wajib diisi sebelum meminta revisi.',
			showFeedbackBox: true
		},
		reject: {
			badge: 'Tolak Karya Tulis',
			badgeClass: 'penalis-badge penalis-badge--rejected',
			title: 'Tolak submit karya tulis ini?',
			description: 'Submit karya tulis ini akan ditandai Ditolak. Penulis tidak dapat submit ulang kecuali dibuka kembali oleh Senator.',
			confirmLabel: 'Ya, Tolak Karya Tulis',
			confirmClass: 'button penalis-confirm-modal__btn-reject',
			requiresFeedback: true,
			emptyFeedbackError: 'Catatan alasan penolakan wajib diisi sebelum menolak karya tulis.',
			showFeedbackBox: true
		},
		reopen: {
			badge: 'Buka Kembali',
			badgeClass: 'penalis-badge penalis-badge--pending_verification',
			title: 'Buka kembali untuk revisi?',
			description: 'Status karya tulis akan diubah menjadi Perlu revisi agar penulis dapat edit dan submit ulang.',
			confirmLabel: 'Ya, Buka Kembali',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve',
			requiresFeedback: false,
			showFeedbackBox: false
		}
	};

	var WRITER_ACTION_CONFIG = {
		suspend: {
			badge: 'Tangguhkan Akun',
			badgeClass: 'penalis-badge penalis-badge--suspended',
			title: 'Tangguhkan akun penulis ini?',
			description: 'Peran Penulis Aktif dan akses dashboard akan dicabut serta seluruh sesi login dihentikan. Jika akun masih menunggu verifikasi, link verifikasi akan dibatalkan. Karya tulis yang sudah terbit tetap tayang.',
			confirmLabel: 'Ya, Tangguhkan Akun',
			confirmClass: 'button penalis-confirm-modal__btn-reject'
		},
		reactivate: {
			badge: 'Aktifkan Kembali',
			badgeClass: 'penalis-badge penalis-badge--active',
			title: 'Aktifkan kembali akun penulis ini?',
			description: 'Peran Penulis Aktif dan akses ke dashboard akan dipulihkan kembali untuk akun ini.',
			confirmLabel: 'Ya, Aktifkan Kembali',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve'
		},
		resend_verification: {
			badge: 'Verifikasi Email',
			badgeClass: 'penalis-badge penalis-badge--pending_verification',
			title: 'Kirim ulang email verifikasi?',
			description: 'Email verifikasi baru akan dikirimkan ke alamat email pendaftar (dibatasi maksimal 1 kali setiap 10 menit).',
			confirmLabel: 'Ya, Kirim Ulang',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve'
		},
		legacy_send_activation: {
			badge: 'Aktivasi Akun Lama',
			badgeClass: 'penalis-badge penalis-badge--pending_verification',
			title: 'Kirim email aktivasi ke akun lama ini?',
			description: 'Email aktivasi dan pembuatan password pertama akan dikirim ke alamat email kontributor lama ini.',
			confirmLabel: 'Ya, Kirim Email Aktivasi',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve'
		},
		legacy_vouch: {
			badge: 'Aktivasi Langsung',
			badgeClass: 'penalis-badge penalis-badge--active',
			title: 'Aktifkan langsung akun penulis lama ini?',
			description: 'Verifikasi email akan dilewati, akun langsung diaktifkan sebagai Penulis Aktif, dan link reset password dikirim ke email pemilik akun.',
			confirmLabel: 'Ya, Aktifkan Langsung',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve'
		},
		mark_senator: {
			badge: 'Tandai Senator',
			badgeClass: 'penalis-badge penalis-badge--pending_verification',
			title: 'Tandai akun ini sebagai Senator Penalis?',
			description: 'Ikon bintang Senator Penalis akan ditampilkan di samping nama penulis ini pada direktori kontributor, arsip penulis, dan halaman artikel. Tindakan ini tidak mengubah peran atau hak akses akun.',
			confirmLabel: 'Ya, Tandai Senator',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve'
		},
		unmark_senator: {
			badge: 'Hapus Tanda Senator',
			badgeClass: 'penalis-badge penalis-badge--suspended',
			title: 'Hapus tanda Senator dari akun ini?',
			description: 'Ikon bintang Senator Penalis tidak akan lagi ditampilkan pada profil dan artikel penulis ini.',
			confirmLabel: 'Ya, Hapus Tanda',
			confirmClass: 'button penalis-confirm-modal__btn-reject'
		}
	};

	var RESTORE_ACTION_CONFIG = {
		restore: {
			badge: 'Pulihkan Karya Tulis',
			badgeClass: 'penalis-badge penalis-badge--active',
			title: 'Pulihkan karya tulis dari trash?',
			description: 'Karya tulis akan kembali ke WordPress dengan status terbit seperti sebelum dihapus. Penulis tidak dapat mengeditnya tanpa review.',
			confirmLabel: 'Ya, Pulihkan',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve'
		}
	};

	var QUOTE_ACTION_CONFIG = {
		delete_quote: {
			badge: 'Takedown #KalimatHariIni',
			badgeClass: 'penalis-badge penalis-badge--rejected',
			title: 'Takedown #KalimatHariIni ini?',
			description: 'Kalimat akan segera di-takedown dari halaman profil penulis dan halaman publik. Penulis akan menerima notifikasi di dashboard beserta alasan takedown di bawah ini.',
			confirmLabel: 'Ya, Takedown Kalimat',
			confirmClass: 'button penalis-confirm-modal__btn-reject'
		}
	};

	var FOCUSABLE_SELECTOR = 'button:not([disabled]), [href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function trapModalKeys( modal, cancelBtn, submitBtn, onClose ) {
		document.addEventListener( 'keydown', function ( event ) {
			if ( modal.hidden ) {
				return;
			}
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				onClose();
				return;
			}
			if ( event.key === 'Tab' ) {
				var nodes = modal.querySelectorAll( FOCUSABLE_SELECTOR );
				var focusables = [];
				for ( var i = 0; i < nodes.length; i++ ) {
					if ( nodes[ i ].offsetParent !== null ) {
						focusables.push( nodes[ i ] );
					}
				}
				var first = focusables.length > 0 ? focusables[ 0 ] : cancelBtn;
				var last = focusables.length > 0 ? focusables[ focusables.length - 1 ] : submitBtn;

				if ( ! first || ! last ) {
					return;
				}

				if ( event.shiftKey && ( document.activeElement === first || ! modal.contains( document.activeElement ) ) ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			}
		} );
	}

	function createModalController( options ) {
		var prefix = options.prefix;
		var modal = document.getElementById( prefix + '-modal' );
		if ( ! modal ) {
			return null;
		}

		var badgeEl = document.getElementById( prefix + '-badge' );
		var titleEl = document.getElementById( prefix + '-title' );
		var descEl = document.getElementById( prefix + '-desc' );
		var cancelBtn = document.getElementById( prefix + '-cancel' );
		var submitBtn = document.getElementById( prefix + '-submit' );

		var pendingPayload = null;
		var lastTrigger = null;
		var isSubmitting = false;

		function open( config, triggerEl, payload ) {
			pendingPayload = payload;
			lastTrigger = triggerEl || null;

			if ( badgeEl ) {
				badgeEl.textContent = config.badge;
				badgeEl.className = config.badgeClass;
			}
			if ( titleEl ) {
				titleEl.textContent = config.title;
			}
			if ( descEl ) {
				descEl.textContent = config.description;
			}
			if ( typeof options.onOpen === 'function' ) {
				options.onOpen( config, triggerEl, payload );
			}
			if ( submitBtn ) {
				submitBtn.textContent = config.confirmLabel;
				submitBtn.className = config.confirmClass;
				submitBtn.disabled = false;
			}
			if ( cancelBtn ) {
				cancelBtn.disabled = false;
			}

			modal.hidden = false;
			document.body.classList.add( 'penalis-modal-open' );

			if ( options.initialFocusEl && typeof options.initialFocusEl.focus === 'function' ) {
				options.initialFocusEl.focus();
			} else if ( cancelBtn ) {
				cancelBtn.focus();
			}
		}

		function close() {
			if ( isSubmitting ) {
				return;
			}
			modal.hidden = true;
			document.body.classList.remove( 'penalis-modal-open' );
			pendingPayload = null;

			if ( lastTrigger && typeof lastTrigger.focus === 'function' ) {
				lastTrigger.focus();
			}
		}

		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', close );
		}

		var closeTargets = modal.querySelectorAll( '[data-' + prefix + '-close]' );
		for ( var i = 0; i < closeTargets.length; i++ ) {
			closeTargets[ i ].addEventListener( 'click', close );
		}

		if ( submitBtn ) {
			submitBtn.addEventListener( 'click', function () {
				if ( ! pendingPayload || isSubmitting ) {
					return;
				}
				if ( typeof options.validateConfirm === 'function' && ! options.validateConfirm( pendingPayload ) ) {
					return;
				}
				isSubmitting = true;
				submitBtn.disabled = true;
				submitBtn.textContent = 'Memproses...';
				if ( cancelBtn ) {
					cancelBtn.disabled = true;
				}
				options.onConfirm( pendingPayload );
			} );
		}

		trapModalKeys( modal, cancelBtn, submitBtn, close );

		return {
			open: open,
			close: close
		};
	}

	function initReviewConfirmation() {
		var form = document.getElementById( 'penalis-decision-form' );
		if ( ! form ) {
			return;
		}

		var feedbackInput = document.getElementById( 'penalis-review-feedback' );
		var feedbackError = document.getElementById( 'penalis-review-feedback-error' );
		var confirmedInput = document.getElementById( 'penalis-confirmed-action' );
		var feedbackBox = document.getElementById( 'penalis-confirm-feedback-box' );
		var feedbackText = document.getElementById( 'penalis-confirm-feedback-text' );

		var controller = createModalController( {
			prefix: 'penalis-confirm',
			onOpen: function ( config, triggerEl, payload ) {
				if ( ! feedbackBox || ! feedbackText ) {
					return;
				}
				if ( config.showFeedbackBox ) {
					feedbackBox.hidden = false;
					if ( payload.note !== '' ) {
						feedbackText.textContent = payload.note;
						feedbackText.classList.remove( 'is-empty' );
					} else {
						feedbackText.textContent = 'Tanpa catatan tambahan untuk penulis.';
						feedbackText.classList.add( 'is-empty' );
					}
				} else {
					feedbackBox.hidden = true;
				}
			},
			onConfirm: function ( payload ) {
				if ( confirmedInput ) {
					confirmedInput.disabled = false;
					confirmedInput.value = payload.actionKey;
				}
				form.submit();
			}
		} );

		if ( ! controller ) {
			return;
		}

		function clearFeedbackError() {
			if ( feedbackError ) {
				feedbackError.textContent = '';
				feedbackError.hidden = true;
			}
			if ( feedbackInput ) {
				feedbackInput.classList.remove( 'is-invalid' );
				feedbackInput.removeAttribute( 'aria-invalid' );
			}
		}

		function showFeedbackError( message ) {
			if ( feedbackError ) {
				feedbackError.textContent = message;
				feedbackError.hidden = false;
			}
			if ( feedbackInput ) {
				feedbackInput.classList.add( 'is-invalid' );
				feedbackInput.setAttribute( 'aria-invalid', 'true' );
				feedbackInput.focus();
			}
		}

		if ( feedbackInput ) {
			feedbackInput.addEventListener( 'input', function () {
				if ( feedbackInput.value.trim() !== '' ) {
					clearFeedbackError();
				}
			} );
		}

		var decisionButtons = form.querySelectorAll( '[data-penalis-decision]' );
		for ( var i = 0; i < decisionButtons.length; i++ ) {
			decisionButtons[ i ].addEventListener( 'click', function ( event ) {
				var actionKey = this.getAttribute( 'data-penalis-decision' );
				var config = actionKey ? REVIEW_ACTION_CONFIG[ actionKey ] : null;
				if ( ! config ) {
					return;
				}
				event.preventDefault();

				var note = feedbackInput ? feedbackInput.value.trim() : '';
				if ( config.requiresFeedback && note === '' ) {
					showFeedbackError( config.emptyFeedbackError );
					return;
				}

				clearFeedbackError();
				controller.open( config, this, { actionKey: actionKey, note: note } );
			} );
		}
	}

	function initWriterConfirmation() {
		var accountText = document.getElementById( 'penalis-writer-confirm-account-text' );

		function formatAccountSummary( triggerEl ) {
			var name = triggerEl.getAttribute( 'data-penalis-writer-name' ) || '';
			var login = triggerEl.getAttribute( 'data-penalis-writer-login' ) || '';
			var email = triggerEl.getAttribute( 'data-penalis-writer-email' ) || '';

			var identity = name;
			if ( login && login !== name ) {
				identity = identity ? identity + ' (@' + login + ')' : '@' + login;
			} else if ( login && ! identity ) {
				identity = login;
			}

			if ( email ) {
				return identity ? identity + ' — ' + email : email;
			}
			return identity || 'Akun terpilih';
		}

		var controller = createModalController( {
			prefix: 'penalis-writer-confirm',
			onOpen: function ( config, triggerEl ) {
				if ( accountText && triggerEl ) {
					accountText.textContent = formatAccountSummary( triggerEl );
				}
			},
			onConfirm: function ( targetForm ) {
				targetForm.submit();
			}
		} );

		if ( ! controller ) {
			return;
		}

		var writerButtons = document.querySelectorAll( '[data-penalis-writer-action]' );
		for ( var i = 0; i < writerButtons.length; i++ ) {
			writerButtons[ i ].addEventListener( 'click', function ( event ) {
				var actionKey = this.getAttribute( 'data-penalis-writer-action' );
				var config = actionKey ? WRITER_ACTION_CONFIG[ actionKey ] : null;
				if ( ! config || ! this.form ) {
					return;
				}
				event.preventDefault();
				controller.open( config, this, this.form );
			} );
		}
	}

	function initRestoreConfirmation() {
		var articleText = document.getElementById( 'penalis-restore-confirm-context-text' );

		var controller = createModalController( {
			prefix: 'penalis-restore-confirm',
			onOpen: function ( config, triggerEl ) {
				if ( articleText && triggerEl ) {
					articleText.textContent = triggerEl.getAttribute( 'data-penalis-restore-title' ) || 'Karya tulis terpilih';
				}
			},
			onConfirm: function ( targetForm ) {
				targetForm.submit();
			}
		} );

		if ( ! controller ) {
			return;
		}

		var restoreButtons = document.querySelectorAll( '[data-penalis-restore]' );
		for ( var i = 0; i < restoreButtons.length; i++ ) {
			restoreButtons[ i ].addEventListener( 'click', function ( event ) {
				var config = RESTORE_ACTION_CONFIG.restore;
				if ( ! config || ! this.form ) {
					return;
				}
				event.preventDefault();
				controller.open( config, this, this.form );
			} );
		}
	}

	function initQuoteDeleteConfirmation() {
		var contextText = document.getElementById( 'penalis-quote-confirm-context-text' );
		var reasonInput = document.getElementById( 'penalis-quote-confirm-reason' );
		var reasonCount = document.getElementById( 'penalis-quote-confirm-reason-count' );
		var reasonError = document.getElementById( 'penalis-quote-confirm-reason-error' );
		var presetButtons = document.querySelectorAll( '[data-penalis-quote-preset]' );
		var maxLength = reasonInput && reasonInput.maxLength > 0 ? reasonInput.maxLength : 300;

		function normalizeReason( value ) {
			return String( value || '' ).replace( /\s+/g, ' ' ).trim();
		}

		function countChars( value ) {
			return Array.from ? Array.from( value ).length : value.length;
		}

		function clearReasonError() {
			if ( reasonError ) {
				reasonError.textContent = '';
				reasonError.hidden = true;
			}
			if ( reasonInput ) {
				reasonInput.classList.remove( 'is-invalid' );
				reasonInput.removeAttribute( 'aria-invalid' );
			}
		}

		function showReasonError( message ) {
			if ( reasonError ) {
				reasonError.textContent = message;
				reasonError.hidden = false;
			}
			if ( reasonInput ) {
				reasonInput.classList.add( 'is-invalid' );
				reasonInput.setAttribute( 'aria-invalid', 'true' );
				reasonInput.focus();
			}
		}

		function syncReasonState() {
			var normalized = normalizeReason( reasonInput ? reasonInput.value : '' );
			var length = countChars( normalized );

			if ( reasonCount ) {
				reasonCount.textContent = length + '/' + maxLength;
				reasonCount.classList.toggle( 'is-over', length > maxLength );
			}

			for ( var j = 0; j < presetButtons.length; j++ ) {
				var presetValue = presetButtons[ j ].getAttribute( 'data-penalis-quote-preset' ) || '';
				var isMatch = normalized === presetValue;
				presetButtons[ j ].classList.toggle( 'is-active', isMatch );
				presetButtons[ j ].setAttribute( 'aria-pressed', isMatch ? 'true' : 'false' );
			}
		}

		if ( reasonInput ) {
			reasonInput.addEventListener( 'input', function () {
				syncReasonState();
				var normalized = normalizeReason( reasonInput.value );
				if ( normalized !== '' && countChars( normalized ) <= maxLength ) {
					clearReasonError();
				}
			} );
		}

		for ( var p = 0; p < presetButtons.length; p++ ) {
			presetButtons[ p ].addEventListener( 'click', function () {
				var preset = this.getAttribute( 'data-penalis-quote-preset' ) || '';
				if ( ! reasonInput ) {
					return;
				}
				reasonInput.value = preset;
				clearReasonError();
				syncReasonState();
				reasonInput.focus();
			} );
		}

		var controller = createModalController( {
			prefix: 'penalis-quote-confirm',
			initialFocusEl: reasonInput,
			onOpen: function ( config, triggerEl ) {
				if ( contextText && triggerEl ) {
					var author = triggerEl.getAttribute( 'data-penalis-quote-author' ) || '';
					var text = triggerEl.getAttribute( 'data-penalis-quote-text' ) || '';
					contextText.textContent = author ? author + ' — "' + text + '"' : text;
				}
				if ( reasonInput ) {
					reasonInput.value = '';
				}
				clearReasonError();
				syncReasonState();
			},
			validateConfirm: function () {
				var normalized = normalizeReason( reasonInput ? reasonInput.value : '' );
				if ( normalized === '' ) {
					showReasonError( 'Alasan takedown wajib diisi agar penulis mengetahui penyebabnya.' );
					return false;
				}
				if ( countChars( normalized ) > maxLength ) {
					showReasonError( 'Alasan takedown maksimal ' + maxLength + ' karakter.' );
					return false;
				}
				clearReasonError();
				return true;
			},
			onConfirm: function ( targetForm ) {
				var hiddenReason = targetForm.querySelector( 'input[name="reason"]' );
				if ( hiddenReason && reasonInput ) {
					hiddenReason.value = normalizeReason( reasonInput.value );
				}
				targetForm.submit();
			}
		} );

		if ( ! controller ) {
			return;
		}

		var quoteButtons = document.querySelectorAll( '[data-penalis-delete-quote]' );
		for ( var i = 0; i < quoteButtons.length; i++ ) {
			quoteButtons[ i ].addEventListener( 'click', function ( event ) {
				var config = QUOTE_ACTION_CONFIG.delete_quote;
				if ( ! config || ! this.form ) {
					return;
				}
				event.preventDefault();
				controller.open( config, this, this.form );
			} );
		}
	}

	function initAll() {
		initReviewConfirmation();
		initWriterConfirmation();
		initRestoreConfirmation();
		initQuoteDeleteConfirmation();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
}() );
