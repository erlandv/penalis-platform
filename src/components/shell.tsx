/**
 * Account status banner and navigation shell.
 */

import { useState, type ReactNode } from 'react';

import { ApiError, registrationApi } from '../lib/api';
import { getBootstrap } from '../lib/bootstrap';
import type { Account, AccountStatus, ViewName } from '../lib/types';
import { Notice } from './ui';

const NAV_ITEMS: Array<{ view: ViewName; label: string }> = [
  { view: 'overview', label: 'Ringkasan' },
  { view: 'articles', label: 'Karya Tulis' },
  { view: 'editor', label: 'Karya Tulis Baru' },
  { view: 'media', label: 'Media' },
  { view: 'profile', label: 'Profil' },
  { view: 'settings', label: 'Pengaturan' },
];

export function StatusBanner({ status, email }: { status: AccountStatus; email: string }) {
  if (status === 'pending_verification') {
    return <ResendBanner email={email} />;
  }

  if (status === 'pending_approval') {
    return (
      <Notice tone="warning">
        <strong>Pendaftaran kamu menunggu persetujuan.</strong> Admin akan meninjau akun kamu. Penerbitan
        dinonaktifkan sampai akun kamu disetujui.
      </Notice>
    );
  }

  if (status === 'rejected') {
    return (
      <Notice tone="danger">
        <strong>Pendaftaran kamu tidak disetujui.</strong> Hubungi Admin Penalis jika kamu merasa ini
        keliru.
      </Notice>
    );
  }

  if (status === 'suspended') {
    return (
      <Notice tone="danger">
        <strong>Akun kamu ditangguhkan.</strong> Membuat, mengubah, dan menerbitkan tidak tersedia.
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
      setMessage(caught instanceof ApiError ? caught.message : 'Email tidak dapat dikirim saat ini.');
      setState('error');
    }
  };

  return (
    <Notice tone="warning">
      <p>
        <strong>Konfirmasi alamat email kamu.</strong> Kami mengirim tautan verifikasi ke <strong>{email}</strong>. Kamu
        belum dapat menerbitkan karya tulis sampai email kamu dikonfirmasi dan admin menyetujui akun kamu.
      </p>
      {message ? <p>{message}</p> : null}
      <button
        type="button"
        className="penalis-button penalis-button--ghost"
        onClick={() => void resend()}
        disabled={state === 'sending'}
      >
        {state === 'sending' ? 'Mengirim…' : 'Kirim ulang email verifikasi'}
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
          <h1>Dasbor Penulis</h1>
        </div>
        <div className="penalis-header__meta">
          <span className="penalis-user">{account.displayName}</span>
          {bootstrap.adminUrl ? (
            <a className="penalis-link" href={bootstrap.adminUrl}>
              Kelola penulis
            </a>
          ) : null}
          <a
            className="penalis-link"
            href={account.authorUrl}
            target="_blank"
            rel="noreferrer noopener"
          >
            Lihat profil kamu
          </a>
          <a className="penalis-link" href={bootstrap.logoutUrl}>
            Keluar
          </a>
        </div>
      </header>

      {account.isAdministrator ? (
        <Notice tone="info">
          Kamu masuk sebagai admin. Kontrol penerbitan penulis hanya berlaku untuk akun penulis; gunakan
          WordPress Admin untuk mengelola konten para penulis.
        </Notice>
      ) : (
        <StatusBanner status={account.status} email={account.email} />
      )}

      <div className="penalis-layout">
        <nav className="penalis-nav" aria-label="Bagian dasbor">
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
                title={disabled ? 'Tersedia setelah akun kamu disetujui' : undefined}
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
