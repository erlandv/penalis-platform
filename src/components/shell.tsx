/**
 * Account status banner and navigation shell.
 */

import { useState, type ReactNode } from 'react';

import { ApiError, registrationApi } from '../lib/api';
import { getBootstrap } from '../lib/bootstrap';
import type { Account, AccountStatus, ViewName } from '../lib/types';
import { Notice } from './ui';

const NAV_ITEMS: Array<{ view: ViewName; label: string }> = [
  { view: 'overview', label: 'Overview' },
  { view: 'articles', label: 'My Articles' },
  { view: 'editor', label: 'New Article' },
  { view: 'media', label: 'Media' },
  { view: 'profile', label: 'Profile' },
  { view: 'settings', label: 'Settings' },
];

export function StatusBanner({ status, email }: { status: AccountStatus; email: string }) {
  if (status === 'pending_verification') {
    return <ResendBanner email={email} />;
  }

  if (status === 'pending_approval') {
    return (
      <Notice tone="warning">
        <strong>Your application is awaiting approval.</strong> An administrator will review your account. Publishing
        is disabled until you are approved.
      </Notice>
    );
  }

  if (status === 'rejected') {
    return (
      <Notice tone="danger">
        <strong>Your application was not approved.</strong> Contact the site administrators if you believe this is a
        mistake.
      </Notice>
    );
  }

  if (status === 'suspended') {
    return (
      <Notice tone="danger">
        <strong>Your account is suspended.</strong> Creating, editing, and publishing are unavailable.
      </Notice>
    );
  }

  return null;
}

/**
 * Verification-pending banner with a resend action.
 *
 * The resend endpoint is intentionally generic, so the UI does not reveal
 * whether the address exists beyond what the signed-in user already knows.
 */
function ResendBanner({ email }: { email: string }) {
  const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'error'>('idle');
  const [message, setMessage] = useState('');

  const resend = async () => {
    setState('sending');
    setMessage('');

    try {
      const result = await registrationApi.resend(email);
      setMessage(result.message);
      setState('sent');
    } catch (caught) {
      setMessage(caught instanceof ApiError ? caught.message : 'The email could not be sent right now.');
      setState('error');
    }
  };

  return (
    <Notice tone="warning">
      <p>
        <strong>Confirm your email address.</strong> We sent a verification link to <strong>{email}</strong>. You
        cannot publish until your email is confirmed and an administrator approves your account.
      </p>
      {message ? <p>{message}</p> : null}
      <button
        type="button"
        className="penalis-button penalis-button--ghost"
        onClick={() => void resend()}
        disabled={state === 'sending'}
      >
        {state === 'sending' ? 'Sending…' : 'Resend verification email'}
      </button>
    </Notice>
  );
}

export function Shell({
  account,
  view,
  onNavigate,
  children,
}: {
  account: Account;
  view: ViewName;
  onNavigate: (view: ViewName) => void;
  children: ReactNode;
}) {
  const bootstrap = getBootstrap();
  const canWrite = account.canWrite;

  return (
    <div className="penalis-app">
      <header className="penalis-header">
        <div>
          <p className="penalis-eyebrow">{bootstrap.siteName}</p>
          <h1>Writing dashboard</h1>
        </div>
        <div className="penalis-header__meta">
          <span className="penalis-user">{account.displayName}</span>
          {bootstrap.adminUrl ? (
            <a className="penalis-link" href={bootstrap.adminUrl}>
              Manage writers
            </a>
          ) : null}
          <a
            className="penalis-link"
            href={account.authorUrl}
            target="_blank"
            rel="noreferrer noopener"
          >
            View public profile
          </a>
          <a className="penalis-link" href={bootstrap.logoutUrl}>
            Sign out
          </a>
        </div>
      </header>

      {account.isAdministrator ? (
        <Notice tone="info">
          You are signed in as an administrator. Writer publishing controls apply to writer accounts; use
          WordPress admin to manage content.
        </Notice>
      ) : (
        <StatusBanner status={account.status} email={account.email} />
      )}

      <div className="penalis-layout">
        <nav className="penalis-nav" aria-label="Dashboard sections">
          {NAV_ITEMS.map((item) => {
            const disabled =
              !canWrite && item.view !== 'overview' && item.view !== 'profile' && item.view !== 'settings';

            return (
              <button
                key={item.view}
                type="button"
                className={`penalis-nav__item ${view === item.view ? 'is-active' : ''}`}
                onClick={() => onNavigate(item.view)}
                aria-current={view === item.view ? 'page' : undefined}
                disabled={disabled}
                title={disabled ? 'Available after your account is approved' : undefined}
              >
                {item.label}
              </button>
            );
          })}
        </nav>

        <main className="penalis-main">{children}</main>
      </div>
    </div>
  );
}
