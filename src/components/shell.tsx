/**
 * Account status banner and navigation shell.
 */

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FocusEvent as ReactFocusEvent,
  type KeyboardEvent as ReactKeyboardEvent,
  type PointerEvent as ReactPointerEvent,
  type ReactNode,
} from 'react';

import { ApiError, registrationApi } from '../lib/api';
import { getBootstrap } from '../lib/bootstrap';
import type { Account, AccountStatus, ViewName } from '../lib/types';
import { Icon, type IconName } from './icons';
import { OtpForm } from './OtpForm';
import { Notice } from './ui';

/**
 * Navigation entries carry an icon so the sidebar reads as a set of places
 * rather than a list of words. The icon is decorative: the label is still the
 * accessible name, and the icon inherits the item's colour in every state.
 */
const NAV_ITEMS: Array<{ view: ViewName; label: string; icon: IconName }> = [
  { view: 'overview', label: 'Ringkasan', icon: 'layoutDashboard' },
  { view: 'articles', label: 'Daftar Karya', icon: 'fileText' },
  { view: 'editor', label: 'Buat Karya', icon: 'edit' },
  { view: 'media', label: 'Media', icon: 'image' },
  { view: 'profile', label: 'Profil', icon: 'user' },
  { view: 'settings', label: 'Pengaturan', icon: 'settings' },
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
 * Verification-pending banner with an inline code form.
 *
 * A writer who signed in before confirming can finish verification without
 * leaving the dashboard: the code is entered here and the account becomes
 * active immediately. The resend endpoint is intentionally generic, so the UI
 * does not reveal whether the address exists beyond what the signed-in user
 * already knows.
 */
function ResendBanner({ email }: { email: string }) {
  const [challenge, setChallenge] = useState<{ challengeId: string; resendAfter: number } | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');

  const verify = async (code: string) => {
    if (!challenge) {
      return;
    }

    setError('');
    setBusy(true);

    try {
      const result = await registrationApi.confirm({ challenge_id: challenge.challengeId, code });
      setMessage(result.message);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Kode verifikasi tidak dapat diproses.');
    } finally {
      setBusy(false);
    }
  };

  const resend = async () => {
    const result = await registrationApi.resend(email);
    const next = { challengeId: result.challenge_id, resendAfter: result.resend_after };
    setChallenge(next);

    return { challenge_id: next.challengeId, resend_after: next.resendAfter };
  };

  if (message) {
    return (
      <Notice tone="success">
        <p>{message}</p>
        <a className="penalis-button penalis-button--ghost" href={getBootstrap().dashboardUrl}>
          Muat ulang dasbor
        </a>
      </Notice>
    );
  }

  return (
    <Notice tone="warning">
      <p>
        Konfirmasi alamat email kamu. Kami mengirim kode verifikasi enam digit ke {email}. Kamu belum dapat
        menulis atau mengajukan karya tulis sampai alamat email kamu dikonfirmasi.
      </p>
      {challenge ? (
        <OtpForm
          challengeId={challenge.challengeId}
          email={email}
          resendAfter={challenge.resendAfter}
          onVerify={verify}
          onResend={resend}
          error={error}
          busy={busy}
        />
      ) : (
        <button
          type="button"
          className="penalis-button penalis-button--ghost"
          onClick={() => void resend()}
        >
          Kirim kode verifikasi
        </button>
      )}
    </Notice>
  );
}

/**
 * Compact account menu in the dashboard header.
 *
 * The header used to list three bare links next to the display name, which ate
 * a whole row on narrow screens. Only the name is shown now; the actions live
 * in a flyout that opens on hover (mouse) or on click/keyboard.
 *
 * Hover alone is not accessible, so opening is pinned by a click and the whole
 * widget is keyboard operable: Enter/Space or ArrowDown opens it, arrows move
 * between items, Escape closes it and returns focus to the trigger, and focus
 * leaving the widget closes it. `pointerleave` is ignored while the widget
 * still holds focus so a keyboard user is never dropped out mid-navigation.
 */
function AccountMenu({
  account,
  adminUrl,
  logoutUrl,
}: {
  account: Account;
  adminUrl?: string;
  logoutUrl: string;
}) {
  const [open, setOpen] = useState(false);
  const [pinned, setPinned] = useState(false);
  const wrapperRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const focusFirstOnOpen = useRef(false);

  const close = useCallback((refocusTrigger = false) => {
    setOpen(false);
    setPinned(false);

    if (refocusTrigger) {
      triggerRef.current?.focus();
    }
  }, []);

  useEffect(() => {
    if (!open) {
      return;
    }

    const onDocumentPointerDown = (event: PointerEvent) => {
      const wrapper = wrapperRef.current;
      if (wrapper && !wrapper.contains(event.target as Node)) {
        setOpen(false);
        setPinned(false);
      }
    };

    document.addEventListener('pointerdown', onDocumentPointerDown);
    return () => document.removeEventListener('pointerdown', onDocumentPointerDown);
  }, [open]);

  // Keyboard opening (ArrowDown) must land on the first item; hover must not
  // steal focus from wherever the user was typing.
  useEffect(() => {
    if (open && focusFirstOnOpen.current) {
      focusFirstOnOpen.current = false;
      menuRef.current?.querySelector<HTMLElement>('[role="menuitem"]')?.focus();
    }
  }, [open]);

  const menuItems = () =>
    Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);

  const onPointerEnter = (event: ReactPointerEvent<HTMLDivElement>) => {
    // Touch and pen "enter" is not hover intent; only a mouse should pre-open.
    if (event.pointerType === 'mouse') {
      setOpen(true);
    }
  };

  const onPointerLeave = (event: ReactPointerEvent<HTMLDivElement>) => {
    if (event.pointerType !== 'mouse' || pinned) {
      return;
    }

    const active = document.activeElement;
    if (active instanceof HTMLElement && wrapperRef.current?.contains(active)) {
      return;
    }

    setOpen(false);
  };

  const onBlur = (event: ReactFocusEvent<HTMLDivElement>) => {
    const next = event.relatedTarget;

    /*
     * Only close when focus provably moved to another element outside the
     * widget. `relatedTarget === null` is ambiguous: Safari does not focus a
     * link on mousedown, so clicking a menu item there fires blur with no
     * related target. Closing on that would unmount the menu before the click
     * lands and the navigation would be lost. The document-level pointerdown
     * handler already covers "clicked somewhere else".
     */
    if (!(next instanceof HTMLElement) || wrapperRef.current?.contains(next)) {
      return;
    }

    close();
  };

  const onTriggerClick = () => {
    if (open && pinned) {
      close();
      return;
    }

    setOpen(true);
    setPinned(true);
  };

  const onTriggerKeyDown = (event: ReactKeyboardEvent<HTMLButtonElement>) => {
    if (event.key !== 'ArrowDown') {
      return;
    }

    event.preventDefault();
    focusFirstOnOpen.current = true;
    setOpen(true);
    setPinned(true);
  };

  const onMenuKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Escape') {
      event.preventDefault();
      close(true);
      return;
    }

    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
      return;
    }

    const items = menuItems();
    if (items.length === 0) {
      return;
    }

    event.preventDefault();
    const index = items.indexOf(document.activeElement as HTMLElement);
    const step = event.key === 'ArrowDown' ? 1 : -1;
    const next = items[(index + step + items.length) % items.length];
    next?.focus();
  };

  return (
    <div
      className="penalis-account"
      ref={wrapperRef}
      onPointerEnter={onPointerEnter}
      onPointerLeave={onPointerLeave}
      onBlur={onBlur}
      onKeyDown={onMenuKeyDown}
    >
      <button
        type="button"
        ref={triggerRef}
        className={`penalis-account__trigger ${open ? 'is-open' : ''}`}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? 'penalis-account-menu' : undefined}
        onClick={onTriggerClick}
        onKeyDown={onTriggerKeyDown}
      >
        <span className="penalis-user">{account.displayName}</span>
        <Icon name="chevronDown" />
      </button>

      {open ? (
        <div className="penalis-account__popover">
          <div
            className="penalis-account__menu"
            id="penalis-account-menu"
            role="menu"
            aria-label="Menu akun"
            ref={menuRef}
          >
            <a
              className="penalis-account__item"
              role="menuitem"
              href={account.authorUrl}
              target="_blank"
              rel="noreferrer noopener"
            >
              <Icon name="externalLink" />
              <span>Lihat profil publik</span>
            </a>

            {adminUrl ? (
              <a className="penalis-account__item" role="menuitem" href={adminUrl}>
                <Icon name="users" />
                <span>Kelola penulis</span>
              </a>
            ) : null}

            <a
              className="penalis-account__item penalis-account__item--danger"
              role="menuitem"
              href={logoutUrl}
            >
              <Icon name="logout" />
              <span>Logout</span>
            </a>
          </div>
        </div>
      ) : null}
    </div>
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
          <AccountMenu
            account={account}
            adminUrl={bootstrap.adminUrl}
            logoutUrl={bootstrap.logoutUrl}
          />
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
            const unreadCount = item.view === 'overview' ? (account.unreadNotifications ?? 0) : 0;

            return (
              <button
                key={item.view}
                type="button"
                className={`penalis-nav__item ${view === item.view ? 'is-active' : ''}`}
                onClick={() => onNavigate(item.view)}
                aria-current={view === item.view ? 'page' : undefined}
                disabled={disabled}
                title={disabled ? 'Tersedia setelah email kamu dikonfirmasi' : undefined}
              >
                <Icon name={item.icon} />
                <span>{item.label}</span>
                {unreadCount > 0 ? (
                  <span
                    className="penalis-nav__badge"
                    aria-label={`${unreadCount} notifikasi belum dibaca`}
                  >
                    {unreadCount}
                  </span>
                ) : null}
              </button>
            );
          })}
        </nav>

        <main className="penalis-main">{children}</main>
      </div>
    </div>
  );
}
