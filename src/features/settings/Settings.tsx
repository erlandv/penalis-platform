/**
 * Account settings: status, email change, password change, and sign out.
 */

import { useState, type FormEvent } from 'react';

import { accountApi, ApiError } from '../../lib/api';
import { getBootstrap } from '../../lib/bootstrap';
import type { Account, FieldErrors } from '../../lib/types';
import { formatDate } from '../../lib/format';
import { describedBy } from '../../lib/a11y';
import { validateEmail } from '../../lib/validation';
import { ErrorBanner, FieldError, FieldHint, Notice, StatusBadge } from '../../components/ui';

export function Settings({ account, onUpdated }: { account: Account; onUpdated: (account: Account) => void }) {
  const bootstrap = getBootstrap();
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);

  const [newEmail, setNewEmail] = useState('');
  const [emailPassword, setEmailPassword] = useState('');
  const [emailErrors, setEmailErrors] = useState<FieldErrors>({});
  const [emailError, setEmailError] = useState('');
  const [emailNotice, setEmailNotice] = useState('');
  const [emailSaving, setEmailSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setNotice('');

    if (newPassword.length < 12) {
      setError('Password baru minimal 12 karakter.');
      return;
    }

    if (newPassword !== confirmPassword) {
      setError('Password baru tidak cocok.');
      return;
    }

    setSaving(true);

    try {
      const result = await accountApi.changePassword({
        current_password: currentPassword,
        new_password: newPassword,
      });
      setNotice(`${result.message} Mengalihkan ke halaman login…`);
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');

      window.setTimeout(() => {
        window.location.href = bootstrap.loginUrl;
      }, 2000);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Password kamu tidak dapat diubah.');
    } finally {
      setSaving(false);
    }
  };

  const submitEmail = async (event: FormEvent) => {
    event.preventDefault();
    setEmailError('');
    setEmailNotice('');
    setEmailErrors({});

    const clientErrors: FieldErrors = {};
    const emailMessage = validateEmail(newEmail);
    if (emailMessage !== '') {
      clientErrors.new_email = emailMessage;
    }
    if (emailPassword === '') {
      clientErrors.current_password = 'Masukkan password kamu saat ini.';
    }
    if (Object.keys(clientErrors).length > 0) {
      setEmailErrors(clientErrors);
      return;
    }

    setEmailSaving(true);

    try {
      const result = await accountApi.requestEmailChange({
        new_email: newEmail.trim(),
        current_password: emailPassword,
      });
      setEmailNotice(result.message);
      setEmailPassword('');
      setNewEmail('');

      // The server now reports the address as pending. Refresh so the panel
      // shows it without a manual reload; a failed refresh must not turn a
      // successful request into an error message.
      try {
        onUpdated(await accountApi.get());
      } catch {
        // The request itself succeeded, so the notice above stays accurate.
      }
    } catch (caught) {
      if (caught instanceof ApiError) {
        setEmailErrors(caught.fields);
        setEmailError(caught.message);
      } else {
        setEmailError('Permintaan perubahan email tidak dapat diproses.');
      }
    } finally {
      setEmailSaving(false);
    }
  };

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>Pengaturan Akun</h2>
          <p className="penalis-muted">Tinjau informasi akun kamu, ubah email, dan ubah password kamu di sini.</p>
        </div>
      </div>

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Informasi Akun</h3>
          <StatusBadge status={account.status} label={account.statusLabel} />
        </div>

        <p className="penalis-muted">
          Username belum dapat diubah melalui halaman ini; hubungi admin Penalis jika perlu diperbarui. Alamat email
          dapat diubah sendiri, tetapi baru berlaku setelah kamu mengonfirmasi link yang dikirim ke alamat email baru.
        </p>

        <dl className="penalis-details">
          <div>
            <dt>Username</dt>
            <dd>{account.username}</dd>
          </div>
          <div>
            <dt>Email</dt>
            <dd>{account.email}</dd>
          </div>
          {account.pendingEmail ? (
            <div>
              <dt>Email menunggu konfirmasi</dt>
              <dd>{account.pendingEmail}</dd>
            </div>
          ) : null}
          <div>
            <dt>Status</dt>
            <dd>{account.statusLabel}</dd>
          </div>
          <div>
            <dt>Email terverifikasi</dt>
            <dd>{account.emailVerified ? 'Ya' : 'Tidak'}</dd>
          </div>
          <div>
            <dt>Terdaftar</dt>
            <dd>{account.registeredAt ? formatDate(account.registeredAt) : '—'}</dd>
          </div>
          <div>
            <dt>Disetujui</dt>
            <dd>{account.approvedAt ? formatDate(account.approvedAt) : '—'}</dd>
          </div>
        </dl>
      </section>

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Ganti Email</h3>
        </div>

        {account.canChangeEmail ? (
          <>
            <p className="penalis-muted">
              Jika kamu mengubah email, kami akan mengirim link konfirmasi ke alamat email baru kamu. 
              Alamat email baru tersebut tidak akan aktif sebelum dikonfirmasi.
            </p>

            {emailError ? <ErrorBanner message={emailError} /> : null}
            {emailNotice ? <Notice tone="success">{emailNotice}</Notice> : null}

            <form className="penalis-form penalis-form--narrow" onSubmit={submitEmail} noValidate>
              <label className="penalis-field">
                <span>Email Baru</span>
                <input
                  type="email"
                  value={newEmail}
                  onChange={(event) => setNewEmail(event.target.value)}
                  autoComplete="email"
                  maxLength={254}
                  aria-invalid={Boolean(emailErrors.new_email)}
                  aria-describedby={describedBy(
                    'new_email-hint',
                    emailErrors.new_email && 'new_email-error',
                  )}
                />
                <FieldHint id="new_email-hint">Gunakan email yang benar-benar kamu miliki.</FieldHint>
                <FieldError errors={emailErrors} name="new_email" />
              </label>

              <label className="penalis-field">
                <span>Password Saat Ini</span>
                <input
                  type="password"
                  value={emailPassword}
                  onChange={(event) => setEmailPassword(event.target.value)}
                  autoComplete="current-password"
                  aria-invalid={Boolean(emailErrors.current_password)}
                  aria-describedby={describedBy(
                    'email_password-hint',
                    emailErrors.current_password && 'current_password-error',
                  )}
                />
                <FieldHint id="email_password-hint">
                  Kami meminta password untuk memastikan perubahan ini benar-benar dari kamu.
                </FieldHint>
                <FieldError errors={emailErrors} name="current_password" />
              </label>

              <button type="submit" className="penalis-button penalis-button--primary" disabled={emailSaving}>
                {emailSaving ? 'Mengirim Link Konfirmasi…' : 'Kirim Link Konfirmasi'}
              </button>
            </form>
          </>
        ) : (
          <p className="penalis-muted">
            Alamat email akun ini belum dapat diubah dari dashboard. Silakan hubungi admin Penalis jika perlu
            diperbarui.
          </p>
        )}
      </section>

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Ganti Password</h3>
        </div>

        <p className="penalis-muted">
          Gunakan password yang kuat, unik, dan sulit ditebak. 
          Password minimal 12 karakter, dan sebaiknya mengandung huruf besar, huruf kecil, angka, dan simbol.
        </p>

        {error ? <ErrorBanner message={error} /> : null}
        {notice ? <Notice tone="success">{notice}</Notice> : null}

        <form className="penalis-form penalis-form--narrow" onSubmit={submit} noValidate>
          <label className="penalis-field">
            <span>Password Saat Ini</span>
            <input
              type="password"
              value={currentPassword}
              onChange={(event) => setCurrentPassword(event.target.value)}
              autoComplete="current-password"
              required
            />
          </label>

          <label className="penalis-field">
            <span>Password Baru</span>
            <input
              type="password"
              value={newPassword}
              onChange={(event) => setNewPassword(event.target.value)}
              autoComplete="new-password"
              required
            />            
          </label>

          <label className="penalis-field">
            <span>Konfirmasi Password Baru</span>
            <input
              type="password"
              value={confirmPassword}
              onChange={(event) => setConfirmPassword(event.target.value)}
              autoComplete="new-password"
              required
            />
          </label>

          <button type="submit" className="penalis-button penalis-button--primary" disabled={saving}>
            {saving ? 'Memperbarui…' : 'Ganti Password'}
          </button>
        </form>
      </section>

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Sesi</h3>
        </div>
        <p className="penalis-muted">
          Logout akan mengakhiri sesi browser ini. Karya tulis dan draft kamu tetap aman tersimpan.
        </p>
        <a className="penalis-button penalis-button--ghost" href={bootstrap.logoutUrl}>
          Logout
        </a>
      </section>
    </div>
  );
}
