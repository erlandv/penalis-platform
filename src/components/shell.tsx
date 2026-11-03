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
  { view: 'articles', label: 'Daftar Karya' },
  { view: 'editor', label: 'Buat Karya' },
  { view: 'media', label: 'Media' },
  { view: 'profile', label: 'Profil' },
  { view: 'settings', label: 'Pengaturan' },
];

export function StatusBanner({
  status,
  email,
  needsPassword = false,
}: {
  status: AccountStatus;
  email: string;
  needsPassword?: boolean;
}) {
  if (needsPassword) {
    return (
      <Notice tone="warning">
        Akun kamu belum memiliki kata sandi. Kami mengirim link untuk membuat kata sandi
        ke {email}. Buka link tersebut agar kamu dapat login kembali.
      </Notice>
    );
  }

  if (status === 'pending_verification') {
    return <ResendBanner email={email} />;
  }

  if (status === 'pending_approval') {
    return (
      <Notice tone="warning">
        Pendaftaran kamu menunggu persetujuan. Admin akan meninjau akun kamu. Pengelolaan karya tulis
        dinonaktifkan sampai akun kamu disetujui.
      </Notice>
    );
  }

  if (status === 'rejected') {
    return (
      <Notice tone="danger">
        Pendaftaran kamu tidak disetujui. Hubungi Admin Penalis jika kamu merasa ini
        keliru.
      </Notice>
    );
  }

  if (status === 'suspended') {
    return (
      <Notice tone="danger">
        Akun Penalis kamu ditangguhkan. Pengelolaan karya tulis tidak tersedia.
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
        Konfirmasi alamat email kamu. Kami mengirim tautan verifikasi ke {email}. Kamu
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
            Logout
          </a>
        </div>
      </header>

      {account.isAdministrator ? (
        <Notice tone="info">
          Kamu masuk sebagai admin. Kontrol penerbitan penulis hanya berlaku untuk akun penulis; gunakan
          WordPress Admin untuk mengelola konten para penulis.
        </Notice>
      ) : (
        <StatusBanner
          status={account.status}
          email={account.email}
          needsPassword={account.needsPassword}
        />
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
