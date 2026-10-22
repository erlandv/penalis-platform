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
      setError('Your new password must be at least 12 characters long.');
      return;
    }

    if (newPassword !== confirmPassword) {
      setError('The new passwords do not match.');
      return;
    }

    setSaving(true);

    try {
      const result = await accountApi.changePassword({
        current_password: currentPassword,
        new_password: newPassword,
      });
      setNotice(`${result.message} Redirecting to sign in…`);
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');

      window.setTimeout(() => {
        window.location.href = bootstrap.loginUrl;
      }, 2000);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Your password could not be changed.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>Account settings</h2>
          <p className="penalis-muted">Manage your sign-in credentials and review your account state.</p>
        </div>
      </div>

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Account</h3>
          <StatusBadge status={account.status} label={account.statusLabel} />
        </div>

        <dl className="penalis-details">
          <div>
            <dt>Status</dt>
            <dd>{account.statusLabel}</dd>
          </div>
          <div>
            <dt>Email verified</dt>
            <dd>{account.emailVerified ? 'Yes' : 'No'}</dd>
          </div>
          <div>
            <dt>Registered</dt>
            <dd>{account.registeredAt ? formatDate(account.registeredAt) : '—'}</dd>
          </div>
          <div>
            <dt>Approved</dt>
            <dd>{account.approvedAt ? formatDate(account.approvedAt) : '—'}</dd>
          </div>
        </dl>
      </section>

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Change password</h3>
        </div>

        {error ? <ErrorBanner message={error} /> : null}
        {notice ? <Notice tone="success">{notice}</Notice> : null}

        <form className="penalis-form penalis-form--narrow" onSubmit={submit} noValidate>
          <label className="penalis-field">
            <span>Current password</span>
            <input
              type="password"
              value={currentPassword}
              onChange={(event) => setCurrentPassword(event.target.value)}
              autoComplete="current-password"
              required
            />
          </label>

          <label className="penalis-field">
            <span>New password</span>
            <input
              type="password"
              value={newPassword}
              onChange={(event) => setNewPassword(event.target.value)}
              autoComplete="new-password"
              required
            />
            <small>At least 12 characters.</small>
          </label>

          <label className="penalis-field">
            <span>Confirm new password</span>
            <input
              type="password"
              value={confirmPassword}
              onChange={(event) => setConfirmPassword(event.target.value)}
              autoComplete="new-password"
              required
            />
          </label>

          <button type="submit" className="penalis-button penalis-button--primary" disabled={saving}>
            {saving ? 'Updating…' : 'Change password'}
          </button>
        </form>
      </section>

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Session</h3>
        </div>
        <p className="penalis-muted">
          Signing out ends this browser session. Your articles and drafts are kept.
        </p>
        <a className="penalis-button penalis-button--ghost" href={bootstrap.logoutUrl}>
          Sign out
        </a>
      </section>
    </div>
  );
}
