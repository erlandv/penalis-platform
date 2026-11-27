(() => {
	'use strict';

	const settings = document.querySelector('.penalis-settings');
	if (!settings) return;

	const confirmation = settings.querySelector('[data-penalis-uninstall-confirm]');
	if (confirmation) {
		const option = settings.querySelector('#penalis-remove-data');
		const input = confirmation.querySelector('input');
		const sync = () => {
			const needsConfirmation = option.checked && confirmation.dataset.penalisUninstallConfirm === 'inactive';
			confirmation.hidden = !needsConfirmation;
			input.disabled = !needsConfirmation;
			input.required = needsConfirmation;
			input.setCustomValidity(
				needsConfirmation && input.value && input.value.trim().toUpperCase() !== 'HAPUS'
					? 'Ketik HAPUS untuk mengaktifkan penghapusan data.'
					: ''
			);
		};
		option.addEventListener('change', sync);
		input.addEventListener('input', sync);
		sync();
	}

	const dirtyForms = new Set();
	let submitting = false;
	const formValues = (form) => JSON.stringify(Array.from(new FormData(form)));
	settings.querySelectorAll('[data-penalis-save-state]').forEach((status) => {
		const form = status.closest('form');
		const initial = formValues(form);
		const check = () => {
			submitting = false;
			const dirty = formValues(form) !== initial;
			if (dirty) dirtyForms.add(form);
			else dirtyForms.delete(form);
			const message = dirty ? 'Ada perubahan belum disimpan' : '';
			if (status.textContent !== message) status.textContent = message;
		};
		form.addEventListener('input', check);
		form.addEventListener('change', check);
		window.addEventListener('pageshow', check);
	});

	settings.addEventListener('submit', (event) => {
		if (event.target.method !== 'post') return;

		const confirmationMessage = event.submitter?.dataset.penalisConfirm;
		const otherChanges = [...dirtyForms].some((form) => form !== event.target);
		const messages = [
			confirmationMessage,
			otherChanges ? 'Perubahan yang belum disimpan di bagian lain akan hilang. Lanjutkan?' : ''
		].filter(Boolean);
		if (messages.length && !window.confirm(messages.join('\n\n'))) {
			event.preventDefault();
			return;
		}
		submitting = true;
	});
	window.addEventListener('beforeunload', (event) => {
		if (!submitting && dirtyForms.size) {
			event.preventDefault();
			event.returnValue = '';
		}
	});

	const feedback = settings.querySelector('[data-penalis-feedback]');
	if (feedback?.dataset.penalisFeedback) {
		const section = document.getElementById('penalis-' + feedback.dataset.penalisFeedback);
		if (section && settings.contains(section)) {
			section.prepend(feedback);
			feedback.focus({ preventScroll: true });
		}
	}

	const editor = settings.querySelector('.penalis-settings__template-editor');
	if (!editor) return;

	editor.querySelectorAll('[data-email-toolbar], [data-email-format]').forEach((element) => {
		element.hidden = false;
	});
	editor.querySelectorAll('[data-email-variable-text]').forEach((element) => {
		element.hidden = true;
	});

	editor.addEventListener('click', (event) => {
		const button = event.target.closest('[data-email-format]');
		if (!button) return;

		const field = document.getElementById(button.dataset.emailTarget);
		const start = field.selectionStart;
		const end = field.selectionEnd;
		const selected = field.value.slice(start, end);
		let insert = button.dataset.emailVariable;

		if (button.dataset.emailFormat === 'bold') {
			insert = '**' + (selected || 'teks tebal') + '**';
		}

		if (button.dataset.emailFormat === 'link') {
			const label = window.prompt('Teks tautan', selected || 'Teks tautan')?.trim();
			if (!label) return;
			const url = window.prompt('URL tautan', 'https://')?.trim();
			if (!url || url === 'https://') return;
			insert = '[' + label + '](' + url + ')';
		}

		field.setRangeText(insert, start, end, 'end');
		field.focus();
		field.dispatchEvent(new Event('input', { bubbles: true }));
	});

	const examples = {
		'{kode_otp}': '123456',
		'{menit_berlaku}': '10',
		'{maksimal_percobaan}': '5',
		'{nama_situs}': 'Penalis',
		'{nama_penulis}': 'Dewi',
		'{judul_karya}': 'Catatan dari Kota Hujan',
		'{catatan_review}': 'Mohon lengkapi bagian penutup.',
		'{email_baru}': 'dewi@example.com',
		'{review_cycle}': '2',
		'{username_baru}': 'dewi-menulis',
		'{username_penulis}': 'dewi',
		'{link_aktivasi}': 'https://example.com/aktivasi/',
		'{link_editor}': 'https://example.com/dashboard/editor/',
		'{link_review_admin}': 'https://example.com/wp-admin/admin.php?page=penalis-review',
		'{link_trash_admin}': 'https://example.com/wp-admin/admin.php?page=penalis-trashed',
		'{link_karya_terbit}': 'https://example.com/catatan-kota-hujan/',
		'{link_dashboard}': 'https://example.com/dashboard/',
		'{link_reset_password}': 'https://example.com/atur-password/',
	};
	const preview = editor.querySelector('[data-email-preview]');
	const updatePreview = () => {
		preview.querySelectorAll('[data-email-preview-field]').forEach((output) => {
			const field = document.getElementById('penalis-template-' + output.dataset.emailPreviewField);
			output.textContent = field.value.replace(/\{[a-z_]+\}/g, (tag) => examples[tag] || tag);
		});
	};
	preview.hidden = false;
	preview.addEventListener('toggle', () => {
		if (preview.open) updatePreview();
	});
	editor.addEventListener('input', () => {
		if (preview.open) updatePreview();
	});
})();
