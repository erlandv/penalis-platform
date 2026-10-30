/**
 * Account settings: status, password change, and sign out.
 */

import { useState, type FormEvent } from 'react';

import { accountApi, ApiError } from '../../lib/api';
import { getBootstrap } from '../../lib/bootstrap';
import type { Account } from '../../lib/types';
import { formatDate } from '../../lib/format';
import { ErrorBanner, Notice, StatusBadge } from '../../components/ui';

export function Settings({ account }: { account: Account }) {
  const bootstrap = getBootstrap();
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');
    setNotice('');

    if (newPassword.length < 12) {
      setError('Kata sandi baru Anda minimal 12 karakter.');
      return;
    }

    if (newPassword !== confirmPassword) {
      setError('Kata sandi baru tidak cocok.');
      return;
    }

    setSaving(true);

    try {
      const result = await accountApi.changePassword({
        current_password: currentPassword,
        new_password: newPassword,
      });
      setNotice(`${result.message} Mengalihkan ke halaman masuk…`);
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');

      window.setTimeout(() => {
        window.location.href = bootstrap.loginUrl;
      }, 2000);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Kata sandi Anda tidak dapat diubah.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>Pengaturan akun</h2>
          <p className="penalis-muted">Kelola kredensial masuk Anda dan tinjau status akun Anda.</p>
        </div>
      </div>

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Akun</h3>
          <StatusBadge status={account.status} label={account.statusLabel} />
        </div>

        <dl className="penalis-details">
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
          <h3>Ganti kata sandi</h3>
        </div>

        {error ? <ErrorBanner message={error} /> : null}
        {notice ? <Notice tone="success">{notice}</Notice> : null}

        <form className="penalis-form penalis-form--narrow" onSubmit={submit} noValidate>
          <label className="penalis-field">
            <span>Kata sandi saat ini</span>
            <input
              type="password"
              value={currentPassword}
              onChange={(event) => setCurrentPassword(event.target.value)}
              autoComplete="current-password"
              required
            />
          </label>

          <label className="penalis-field">
            <span>Kata sandi baru</span>
            <input
              type="password"
              value={newPassword}
              onChange={(event) => setNewPassword(event.target.value)}
              autoComplete="new-password"
              required
            />
            <small>Minimal 12 karakter.</small>
          </label>

          <label className="penalis-field">
            <span>Konfirmasi kata sandi baru</span>
            <input
              type="password"
              value={confirmPassword}
              onChange={(event) => setConfirmPassword(event.target.value)}
              autoComplete="new-password"
              required
            />
          </label>

          <button type="submit" className="penalis-button penalis-button--primary" disabled={saving}>
            {saving ? 'Memperbarui…' : 'Ganti kata sandi'}
          </button>
        </form>
      </section>

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Sesi</h3>
        </div>
        <p className="penalis-muted">
          Keluar akan mengakhiri sesi peramban ini. Artikel dan draf Anda tetap tersimpan.
        </p>
        <a className="penalis-button penalis-button--ghost" href={bootstrap.logoutUrl}>
          Keluar
        </a>
      </section>
    </div>
  );
}
