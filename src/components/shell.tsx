/**
 * Account status banner and navigation shell.
 */

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type KeyboardEvent as ReactKeyboardEvent,
  type ReactNode,
} from 'react';

import { ApiError, registrationApi } from '../lib/api';
import { getBootstrap } from '../lib/bootstrap';
import { canReceiveNotifications, canReview } from '../lib/permissions';
import type { Account, AccountStatus, DashboardNotification, ViewName } from '../lib/types';
import { toActiveChallenge, type ActiveChallenge } from '../lib/otp';
import { Icon, type IconName } from './icons';
import { NotificationBell } from './NotificationBell';
import { OtpForm } from './OtpForm';
import { Notice, VerifiedWriterBadge } from './ui';
import { useDisclosure } from './useDisclosure';
import { preloadEditor } from '../features/editor/editorLoader';
import type { NotificationsController } from '../features/notifications/useNotifications';

/**
 * Navigation entries carry an icon so the sidebar reads as a set of places
 * rather than a list of words. The icon is decorative: the label is still the
 * accessible name, and the icon inherits the item's colour in every state.
 */
const NAV_ITEMS: Array<{ view: ViewName; label: string; icon: IconName }> = [
  { view: 'overview', label: 'Ringkasan', icon: 'layoutDashboard' },
  { view: 'notifications', label: 'Notifikasi', icon: 'bell' },
  { view: 'review', label: 'Review Karya', icon: 'check' },
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
        Akun kamu belum memiliki password. Kami mengirim link untuk membuat password
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
  const [challenge, setChallenge] = useState<ActiveChallenge | null>(null);
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
    const next = toActiveChallenge(result, email);
    setChallenge(next);

    return { challenge_id: next.challengeId, resend_after: next.resendAfter };
  };

  const requestInitialCode = async () => {
    if (busy) return;
    setBusy(true);
    setError('');
    try {
      await resend();
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Kode tidak dapat dikirim. Silakan coba lagi.');
    } finally {
      setBusy(false);
    }
  };

  if (message) {
    return (
      <Notice tone="success">
        <p>{message}</p>
        <a className="penalis-button penalis-button--ghost" href={getBootstrap().dashboardUrl}>
          Refresh dashboard
        </a>
      </Notice>
    );
  }

  return (
    <Notice tone="warning">
      <p>
        Konfirmasi alamat email kamu. Kamu belum dapat menulis atau 
        submit karya tulis sampai alamat email kamu dikonfirmasi.
      </p>
      {!challenge && error ? <p role="alert">{error}</p> : null}
      {challenge ? (
        <OtpForm
          challengeId={challenge.challengeId}
          email={email}
          resendAfter={challenge.resendAfter}
          expiresIn={challenge.expiresIn}
          maxAttempts={challenge.maxAttempts}
          onVerify={verify}
          onResend={resend}
          error={error}
          busy={busy}
        />
      ) : (
        <button
          type="button"
          className="penalis-button penalis-button--ghost"
          onClick={() => void requestInitialCode()}
          disabled={busy}
        >
          {busy ? 'Mengirim…' : 'Kirim kode verifikasi'}
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
 * leaving the widget closes it. The shared hover/pin/focus state machine lives
 * in `useDisclosure`; `pointerleave` is ignored while the widget still holds
 * focus so a keyboard user is never dropped out mid-navigation.
 */
function AccountMenu({
  account,
  adminUrl,
  reviewUrl,
  logoutUrl,
  onNavigate,
}: {
  account: Account;
  adminUrl?: string;
  reviewUrl?: string;
  logoutUrl: string;
  onNavigate: (view: ViewName) => void;
}) {
  const [avatarError, setAvatarError] = useState(false);
  const {
    open,
    wrapperRef,
    triggerRef,
    menuRef,
    close,
    onPointerEnter,
    onPointerLeave,
    onBlur,
    onTriggerClick,
    onTriggerKeyDown,
    onMenuKeyDown: closeOnEscape,
  } = useDisclosure({ focusSelector: '[role="menuitem"]' });

  useEffect(() => {
    setAvatarError(false);
  }, [account.avatarUrl]);

  const menuItems = () =>
    Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);

  const onMenuKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>) => {
    closeOnEscape(event);

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
      className="penalis-account penalis-popover-anchor"
      ref={wrapperRef}
      onPointerEnter={onPointerEnter}
      onPointerLeave={onPointerLeave}
      onBlur={onBlur}
      onKeyDown={onMenuKeyDown}
    >
      <button
        type="button"
        ref={triggerRef}
        className={`penalis-account__trigger penalis-popover-trigger ${open ? 'is-open' : ''}`}
        aria-haspopup="menu"
        aria-expanded={open}
        aria-controls={open ? 'penalis-account-menu' : undefined}
        aria-label={`Menu akun: ${account.displayName}`}
        onClick={onTriggerClick}
        onKeyDown={onTriggerKeyDown}
      >
        {account.avatarUrl && !avatarError ? (
          <img
            className="penalis-account__avatar"
            src={account.avatarUrl}
            alt=""
            width={34}
            height={34}
            onError={() => setAvatarError(true)}
          />
        ) : (
          <span className="penalis-account__avatar penalis-account__avatar--fallback" aria-hidden="true">
            <Icon name="user" />
          </span>
        )}
        <span className="penalis-account__chevron" aria-hidden="true">
          <Icon name="chevronDown" />
        </span>
      </button>

      {open ? (
        <div className="penalis-account__popover penalis-popover">
          <div
            className="penalis-account__menu penalis-popover__panel"
            id="penalis-account-menu"
            role="menu"
            aria-label="Menu akun"
            ref={menuRef}
          >
            <div className="penalis-account__header" role="presentation">
              <div className="penalis-account__header-name">
                <span>{account.displayName}</span>
                <VerifiedWriterBadge account={account} size={15} />
              </div>
              <div className="penalis-account__header-meta">
                {account.isAdministrator ? (
                  <span className="penalis-account__badge">Admin</span>
                ) : account.isReviewer ? (
                  <span className="penalis-account__badge">Reviewer</span>
                ) : null}
                <span>@{account.username}</span>
              </div>
            </div>
            <div className="penalis-account__divider" role="separator" />

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

            <button
              type="button"
              className="penalis-account__item"
              role="menuitem"
              onClick={() => {
                close();
                onNavigate('profile');
              }}
            >
              <Icon name="user" />
              <span>Edit profil</span>
            </button>

            <button
              type="button"
              className="penalis-account__item"
              role="menuitem"
              onClick={() => {
                close();
                onNavigate('settings');
              }}
            >
              <Icon name="settings" />
              <span>Pengaturan akun</span>
            </button>

            {adminUrl || reviewUrl || canReview(account) ? (
              <>
                <div className="penalis-account__divider" role="separator" />
                {adminUrl ? (
                  <a className="penalis-account__item" role="menuitem" href={adminUrl}>
                    <Icon name="users" />
                    <span>Kelola penulis</span>
                  </a>
                ) : null}

                {canReview(account) ? (
                  // The queue lives in the dashboard now; sending a reviewer to
                  // wp-admin would take them out of the interface they are in.
                  <button
                    type="button"
                    className="penalis-account__item"
                    role="menuitem"
                    onClick={() => onNavigate('review')}
                  >
                    <Icon name="check" />
                    <span>Review karya tulis</span>
                  </button>
                ) : reviewUrl ? (
                  <a className="penalis-account__item" role="menuitem" href={reviewUrl}>
                    <Icon name="fileText" />
                    <span>Review karya tulis</span>
                  </a>
                ) : null}
              </>
            ) : null}

            <div className="penalis-account__divider" role="separator" />
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
  notifications,
  onOpenNotificationArticle,
  children,
}: {
  account: Account;
  view: ViewName;
  onNavigate: (view: ViewName) => void;
  notifications: NotificationsController;
  onOpenNotificationArticle: (item: DashboardNotification) => void;
  children: ReactNode;
}) {
  const bootstrap = getBootstrap();
  const canWrite = account.canWrite;
  /*
   * Only writers and reviewers ever receive editorial notifications, so a
   * pending or suspended account must not see an always-empty bell or an
   * always-empty Notifikasi page.
   */
  const notificationsEnabled = canReceiveNotifications(account);

  const navRef = useRef<HTMLElement>(null);
  const activeNavRef = useRef<HTMLButtonElement>(null);
  const mainRef = useRef<HTMLElement>(null);
  const [canScrollLeft, setCanScrollLeft] = useState(false);
  const [canScrollRight, setCanScrollRight] = useState(false);

  const updateNavOverflow = useCallback(() => {
    const nav = navRef.current;
    if (!nav) {
      return;
    }

    /*
     * Compare item edges with the scrollport instead of trusting raw
     * scrollLeft: `scroll-snap-align: start` parks the first item at the
     * padded content edge, so scrollLeft can be ~3px even at the beginning.
     */
    const navRect = nav.getBoundingClientRect();
    const first = nav.firstElementChild;
    const last = nav.lastElementChild;

    setCanScrollLeft(first ? first.getBoundingClientRect().left < navRect.left - 1 : false);
    setCanScrollRight(last ? last.getBoundingClientRect().right > navRect.right + 1 : false);
  }, []);

  useEffect(() => {
    const nav = navRef.current;
    if (!nav) {
      return;
    }

    updateNavOverflow();
    nav.addEventListener('scroll', updateNavOverflow, { passive: true });
    window.addEventListener('resize', updateNavOverflow);

    return () => {
      nav.removeEventListener('scroll', updateNavOverflow);
      window.removeEventListener('resize', updateNavOverflow);
    };
  }, [updateNavOverflow]);

  useEffect(() => {
    updateNavOverflow();
  }, [view, notificationsEnabled, canWrite, updateNavOverflow]);

  /*
   * Keep the current section visible in the mobile scroll strip. `nearest`
   * avoids jumping the page vertically; only the strip itself moves.
   */
  useEffect(() => {
    const nav = navRef.current;
    const active = activeNavRef.current;
    if (!nav || !active || nav.scrollWidth <= nav.clientWidth + 2) {
      return;
    }

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    active.scrollIntoView({
      behavior: reduceMotion ? 'auto' : 'smooth',
      block: 'nearest',
      inline: 'center',
    });
  }, [view]);

  /*
   * Move focus into the new screen after a view change.
   *
   * The shell — header, nav strip, `<main>` — is persistent, so without this a
   * keyboard user's next Tab continues from the nav they just left, and a
   * screen-reader user is told nothing at all: the page changed with no focus
   * move and no announcement. Focusing the new heading is the announcement —
   * assistive technology reads the heading as it takes focus — and it also
   * resets the scroll position, which otherwise stays wherever the previous
   * view left it.
   *
   * The previous view is tracked in a ref so opening the dashboard on a deep
   * link does not steal focus from the browser's own address-bar navigation.
   */
  const previousViewRef = useRef(view);

  useEffect(() => {
    if (previousViewRef.current === view) {
      return;
    }

    previousViewRef.current = view;

    const main = mainRef.current;
    if (!main) {
      return;
    }

    window.scrollTo({ top: 0, behavior: 'auto' });

    // Views that load asynchronously render a spinner first and have no
    // heading yet; `<main>` is the fallback so focus still leaves the nav.
    const target = main.querySelector<HTMLElement>('h1, h2') ?? main;

    if (!target.hasAttribute('tabindex')) {
      target.setAttribute('tabindex', '-1');
    }

    target.focus({ preventScroll: true });
  }, [view]);

  const scrollNav = (direction: 'left' | 'right') => {    const nav = navRef.current;
    if (!nav) {
      return;
    }

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const distance = Math.max(160, Math.round(nav.clientWidth * 0.7));
    nav.scrollBy({
      left: direction === 'left' ? -distance : distance,
      behavior: reduceMotion ? 'auto' : 'smooth',
    });
  };

  return (
    <div className="penalis-app">
      <header className="penalis-header">
        <div className="penalis-header__title">
          <h1>Dashboard Penulis</h1>
        </div>
        <div className="penalis-header__meta">
          {notificationsEnabled ? (
            <NotificationBell
              account={account}
              controller={notifications}
              onOpenArticle={onOpenNotificationArticle}
              onViewAll={() => onNavigate('notifications')}
            />
          ) : null}
          <AccountMenu
            account={account}
            adminUrl={bootstrap.adminUrl}
            reviewUrl={bootstrap.reviewUrl}
            logoutUrl={bootstrap.logoutUrl}
            onNavigate={onNavigate}
          />
        </div>
      </header>

      {/*
       * No role banner here.
       *
       * This strip used to say "dashboard ini khusus untuk penulis, pakai
       * WordPress Admin" to every administrator and reviewer, on every screen.
       * It was wrong twice over: reviewers now work the queue inside the
       * dashboard, and an administrator who also writes was being told the
       * dashboard was not for them. Orientation belongs on the overview, where
       * a reviewer lands, not on every page.
       */}
      <StatusBanner
        status={account.status}
        email={account.email}
        needsPassword={account.needsPassword}
      />

      <div className="penalis-layout">
        <div className="penalis-nav-wrap">
          <nav ref={navRef} className="penalis-nav" aria-label="Bagian dashboard">
            {NAV_ITEMS.filter((item) => {
              if (item.view === 'notifications') {
                return notificationsEnabled;
              }

              // A writer has no use for a review queue, and a reviewer has no
              // use for a disabled one: hide it instead of greying it out.
              if (item.view === 'review') {
                return canReview(account);
              }

              return true;
            }).map((item) => {
              const isActive = view === item.view;
              const disabled =
                !canWrite &&
                item.view !== 'overview' &&
                item.view !== 'notifications' &&
                item.view !== 'profile' &&
                item.view !== 'settings' &&
                item.view !== 'review';
              const disabledHint = account.isReviewer
                ? 'Hanya tersedia untuk akun penulis'
                : 'Tersedia setelah email kamu dikonfirmasi';

              return (
                <button
                  key={item.view}
                  ref={isActive ? activeNavRef : undefined}
                  type="button"
                  className={`penalis-nav__item ${isActive ? 'is-active' : ''}`}
                  onClick={() => onNavigate(item.view)}
                  onPointerEnter={() => {
                    if (item.view === 'editor' && !disabled) {
                      preloadEditor();
                    }
                  }}
                  onFocus={() => {
                    if (item.view === 'editor' && !disabled) {
                      preloadEditor();
                    }
                  }}
                  aria-current={isActive ? 'page' : undefined}
                  disabled={disabled}
                  title={disabled ? disabledHint : undefined}
                >
                  <Icon name={item.icon} />
                  <span>{item.label}</span>
                </button>
              );
            })}
          </nav>

          {canScrollLeft ? (
            <div className="penalis-nav__hint penalis-nav__hint--left">
              <button
                type="button"
                className="penalis-nav__next"
                onClick={() => scrollNav('left')}
                aria-label="Geser navigasi ke kiri"
              >
                <Icon name="chevronLeft" />
              </button>
            </div>
          ) : null}

          {canScrollRight ? (
            <div className="penalis-nav__hint penalis-nav__hint--right">
              <button
                type="button"
                className="penalis-nav__next"
                onClick={() => scrollNav('right')}
                aria-label="Geser navigasi ke kanan"
              >
                <Icon name="chevronRight" />
              </button>
            </div>
          ) : null}
        </div>

        <main ref={mainRef} className="penalis-main" tabIndex={-1}>
          {children}
        </main>
      </div>
    </div>
  );
}
