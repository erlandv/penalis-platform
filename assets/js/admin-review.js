/**
 * Penalis Platform — Administrator Confirmation Dialogs
 *
 * 1. Article review decisions on Tinjauan Karya (`?page=penalis-review&post_id=...`)
 * 2. Writer account actions on Penulis Penalis (`?page=penalis-writers`)
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
			title: 'Tolak pengajuan karya tulis ini?',
			description: 'Pengajuan akan ditandai Ditolak. Penulis tidak dapat mengajukannya ulang kecuali admin membukanya kembali.',
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
			description: 'Status karya tulis akan diubah menjadi Perlu revisi agar penulis dapat menyunting dan mengajukannya ulang.',
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
			description: 'Peran penulis dan akses dasbor akan dicabut serta seluruh sesi login dihentikan. Jika akun masih menunggu verifikasi, tautan verifikasi akan dibatalkan. Karya tulis yang sudah terbit tetap tayang.',
			confirmLabel: 'Ya, Tangguhkan Akun',
			confirmClass: 'button penalis-confirm-modal__btn-reject'
		},
		reactivate: {
			badge: 'Aktifkan Kembali',
			badgeClass: 'penalis-badge penalis-badge--active',
			title: 'Aktifkan kembali akun penulis ini?',
			description: 'Peran Penulis Penalis dan akses ke dasbor akan dipulihkan kembali untuk akun ini.',
			confirmLabel: 'Ya, Aktifkan Kembali',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve'
		},
		resend_verification: {
			badge: 'Verifikasi Email',
			badgeClass: 'penalis-badge penalis-badge--pending_verification',
			title: 'Kirim ulang email verifikasi?',
			description: 'Tautan verifikasi baru akan dikirimkan ke alamat email pendaftar (dibatasi maksimal 1 kali setiap 10 menit).',
			confirmLabel: 'Ya, Kirim Ulang',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve'
		},
		legacy_send_activation: {
			badge: 'Aktivasi Akun Lama',
			badgeClass: 'penalis-badge penalis-badge--pending_verification',
			title: 'Kirim tautan aktivasi ke akun lama ini?',
			description: 'Email berisi tautan aktivasi dan pembuatan kata sandi pertama akan dikirim ke alamat email kontributor lama ini.',
			confirmLabel: 'Ya, Kirim Tautan',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve'
		},
		legacy_vouch: {
			badge: 'Aktivasi Langsung',
			badgeClass: 'penalis-badge penalis-badge--active',
			title: 'Aktifkan langsung akun penulis lama ini?',
			description: 'Verifikasi email akan dilewati, akun langsung diaktifkan sebagai Penulis Penalis, dan tautan atur kata sandi dikirim ke email pemilik akun.',
			confirmLabel: 'Ya, Aktifkan Langsung',
			confirmClass: 'button button-primary penalis-confirm-modal__btn-approve'
		}
	};

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
			if ( event.key === 'Tab' && cancelBtn && submitBtn ) {
				var first = cancelBtn;
				var last = submitBtn;

				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			}
		} );
	}

	function initReviewConfirmation() {
		var form = document.getElementById( 'penalis-decision-form' );
		var modal = document.getElementById( 'penalis-confirm-modal' );
		if ( ! form || ! modal ) {
			return;
		}

		var feedbackInput = document.getElementById( 'penalis-review-feedback' );
		var feedbackError = document.getElementById( 'penalis-review-feedback-error' );
		var confirmedInput = document.getElementById( 'penalis-confirmed-action' );
		var badgeEl = document.getElementById( 'penalis-confirm-badge' );
		var titleEl = document.getElementById( 'penalis-confirm-title' );
		var descEl = document.getElementById( 'penalis-confirm-desc' );
		var feedbackBox = document.getElementById( 'penalis-confirm-feedback-box' );
		var feedbackText = document.getElementById( 'penalis-confirm-feedback-text' );
		var cancelBtn = document.getElementById( 'penalis-confirm-cancel' );
		var submitBtn = document.getElementById( 'penalis-confirm-submit' );

		var pendingAction = '';
		var lastTrigger = null;
		var isSubmitting = false;

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

		function openModal( actionKey, triggerEl ) {
			var config = REVIEW_ACTION_CONFIG[ actionKey ];
			if ( ! config ) {
				return;
			}

			var note = feedbackInput ? feedbackInput.value.trim() : '';
			if ( config.requiresFeedback && note === '' ) {
				showFeedbackError( config.emptyFeedbackError );
				return;
			}

			clearFeedbackError();
			pendingAction = actionKey;
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

			if ( feedbackBox && feedbackText ) {
				if ( config.showFeedbackBox ) {
					feedbackBox.hidden = false;
					if ( note !== '' ) {
						feedbackText.textContent = note;
						feedbackText.classList.remove( 'is-empty' );
					} else {
						feedbackText.textContent = 'Tanpa catatan tambahan untuk penulis.';
						feedbackText.classList.add( 'is-empty' );
					}
				} else {
					feedbackBox.hidden = true;
				}
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

			if ( cancelBtn ) {
				cancelBtn.focus();
			}
		}

		function closeModal() {
			if ( isSubmitting ) {
				return;
			}
			modal.hidden = true;
			document.body.classList.remove( 'penalis-modal-open' );
			pendingAction = '';

			if ( lastTrigger && typeof lastTrigger.focus === 'function' ) {
				lastTrigger.focus();
			}
		}

		var decisionButtons = form.querySelectorAll( '[data-penalis-decision]' );
		for ( var i = 0; i < decisionButtons.length; i++ ) {
			decisionButtons[ i ].addEventListener( 'click', function ( event ) {
				var actionKey = this.getAttribute( 'data-penalis-decision' );
				if ( ! actionKey || ! REVIEW_ACTION_CONFIG[ actionKey ] ) {
					return;
				}
				event.preventDefault();
				openModal( actionKey, this );
			} );
		}

		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', closeModal );
		}

		var closeTargets = modal.querySelectorAll( '[data-penalis-confirm-close]' );
		for ( var j = 0; j < closeTargets.length; j++ ) {
			closeTargets[ j ].addEventListener( 'click', closeModal );
		}

		if ( submitBtn ) {
			submitBtn.addEventListener( 'click', function () {
				if ( ! pendingAction || isSubmitting ) {
					return;
				}
				isSubmitting = true;
				submitBtn.disabled = true;
				submitBtn.textContent = 'Memproses...';
				if ( cancelBtn ) {
					cancelBtn.disabled = true;
				}
				if ( confirmedInput ) {
					confirmedInput.disabled = false;
					confirmedInput.value = pendingAction;
				}
				form.submit();
			} );
		}

		trapModalKeys( modal, cancelBtn, submitBtn, closeModal );
	}

	function initWriterConfirmation() {
		var modal = document.getElementById( 'penalis-writer-confirm-modal' );
		if ( ! modal ) {
			return;
		}

		var badgeEl = document.getElementById( 'penalis-writer-confirm-badge' );
		var titleEl = document.getElementById( 'penalis-writer-confirm-title' );
		var descEl = document.getElementById( 'penalis-writer-confirm-desc' );
		var accountText = document.getElementById( 'penalis-writer-confirm-account-text' );
		var cancelBtn = document.getElementById( 'penalis-writer-confirm-cancel' );
		var submitBtn = document.getElementById( 'penalis-writer-confirm-submit' );

		var pendingForm = null;
		var lastTrigger = null;
		var isSubmitting = false;

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

		function openModal( actionKey, triggerEl ) {
			var config = WRITER_ACTION_CONFIG[ actionKey ];
			var targetForm = triggerEl ? triggerEl.form : null;
			if ( ! config || ! targetForm ) {
				return;
			}

			pendingForm = targetForm;
			lastTrigger = triggerEl;

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
			if ( accountText ) {
				accountText.textContent = formatAccountSummary( triggerEl );
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

			if ( cancelBtn ) {
				cancelBtn.focus();
			}
		}

		function closeModal() {
			if ( isSubmitting ) {
				return;
			}
			modal.hidden = true;
			document.body.classList.remove( 'penalis-modal-open' );
			pendingForm = null;

			if ( lastTrigger && typeof lastTrigger.focus === 'function' ) {
				lastTrigger.focus();
			}
		}

		var writerButtons = document.querySelectorAll( '[data-penalis-writer-action]' );
		for ( var i = 0; i < writerButtons.length; i++ ) {
			writerButtons[ i ].addEventListener( 'click', function ( event ) {
				var actionKey = this.getAttribute( 'data-penalis-writer-action' );
				if ( ! actionKey || ! WRITER_ACTION_CONFIG[ actionKey ] ) {
					return;
				}
				event.preventDefault();
				openModal( actionKey, this );
			} );
		}

		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', closeModal );
		}

		var closeTargets = modal.querySelectorAll( '[data-penalis-writer-confirm-close]' );
		for ( var j = 0; j < closeTargets.length; j++ ) {
			closeTargets[ j ].addEventListener( 'click', closeModal );
		}

		if ( submitBtn ) {
			submitBtn.addEventListener( 'click', function () {
				if ( ! pendingForm || isSubmitting ) {
					return;
				}
				isSubmitting = true;
				submitBtn.disabled = true;
				submitBtn.textContent = 'Memproses...';
				if ( cancelBtn ) {
					cancelBtn.disabled = true;
				}
				pendingForm.submit();
			} );
		}

		trapModalKeys( modal, cancelBtn, submitBtn, closeModal );
	}

	function initAll() {
		initReviewConfirmation();
		initWriterConfirmation();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
}() );
