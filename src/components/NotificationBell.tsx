/**
 * Header notification bell with a compact dropdown.
 *
 * The badge used to live on the "Ringkasan" navigation item, which a writer
 * only sees while already on that page. The bell is visible from every view, so
 * a new review decision is noticed wherever the writer happens to be.
 *
 * The interaction model is deliberately the same as `AccountMenu`: hover opens
 * it for a mouse, click/Enter/Space/ArrowDown pins it for keyboard and touch,
 * Escape closes it and returns focus to the trigger, and focus leaving the
 * widget closes it. Reusing the pattern keeps the header predictable.
 *
 * Unlike `AccountMenu` this is a disclosure panel, not an ARIA menu: it holds a
 * mixed list of notification rows plus actions, so it must not claim
 * `role="menu"` (which would promise that every child is a `menuitem`). Tab
 * moves through the rows and buttons in document order.
 */

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FocusEvent as ReactFocusEvent,
  type KeyboardEvent as ReactKeyboardEvent,
  type PointerEvent as ReactPointerEvent,
} from 'react';

import type { Account, DashboardNotification } from '../lib/types';
import { Icon } from './icons';
import { ErrorBanner, Spinner } from './ui';
import { NotificationList } from '../features/notifications/NotificationList';
import type { NotificationsController } from '../features/notifications/useNotifications';

const PREVIEW_LIMIT = 5;

export function NotificationBell({
  account,
  controller,
  onOpenArticle,
  onViewAll,
}: {
  account: Account;
  controller: NotificationsController;
  onOpenArticle: (item: DashboardNotification) => void;
  onViewAll: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [pinned, setPinned] = useState(false);
  const wrapperRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  const focusFirstOnOpen = useRef(false);

  const { data, loading, error, markingId, unreadCount, load, markRead } = controller;

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

  // Keyboard opening (ArrowDown) must land on the first control; hover must not
  // steal focus from wherever the user was typing.
  useEffect(() => {
    if (open && focusFirstOnOpen.current) {
      focusFirstOnOpen.current = false;
      menuRef.current?.querySelector<HTMLElement>('button, a[href]')?.focus();
    }
  }, [open]);

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
     * related target. The document-level pointerdown handler already covers
     * "clicked somewhere else".
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
    if (event.key !== 'Escape') {
      return;
    }

    event.preventDefault();
    close(true);
  };

  const preview = (data?.items ?? []).slice(0, PREVIEW_LIMIT);
  const hasMore = (data?.items.length ?? 0) > PREVIEW_LIMIT;

  return (
    <div
      className="penalis-bell"
      ref={wrapperRef}
      onPointerEnter={onPointerEnter}
      onPointerLeave={onPointerLeave}
      onBlur={onBlur}
      onKeyDown={onMenuKeyDown}
    >
      <button
        type="button"
        ref={triggerRef}
        className={`penalis-bell__trigger ${open ? 'is-open' : ''}`}
        aria-haspopup="true"
        aria-expanded={open}
        aria-controls={open ? 'penalis-notification-menu' : undefined}
        aria-label={
          unreadCount > 0 ? `Notifikasi: ${unreadCount} belum dibaca` : 'Notifikasi'
        }
        onClick={onTriggerClick}
        onKeyDown={onTriggerKeyDown}
      >
        <Icon name="bell" />
        {unreadCount > 0 ? (
          <span className="penalis-bell__badge" aria-hidden="true">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        ) : null}
      </button>

      {open ? (
        <div className="penalis-bell__popover">
          <div
            className="penalis-bell__menu"
            id="penalis-notification-menu"
            aria-label="Notifikasi"
            ref={menuRef}
          >
            <div className="penalis-bell__header">
              <span className="penalis-bell__title">Notifikasi</span>
              {unreadCount > 0 ? (
                <button
                  type="button"
                  className="penalis-notification-action"
                  onClick={() => void markRead()}
                  disabled={markingId !== null}
                >
                  {markingId === 'all' ? 'Memperbarui…' : 'Tandai semua dibaca'}
                </button>
              ) : null}
            </div>

            <div className="penalis-bell__body">
              {loading && !data ? (
                <Spinner label="Memuat notifikasi…" />
              ) : error && !data ? (
                <ErrorBanner message={error} onRetry={() => void load()} />
              ) : preview.length === 0 ? (
                <p className="penalis-muted penalis-bell__empty">
                  Belum ada notifikasi. Status submit karya tulis, catatan revisi, dan penerbitan
                  akan muncul di sini.
                </p>
              ) : (
                <NotificationList
                  items={preview}
                  account={account}
                  markingId={markingId}
                  onMarkRead={(id) => void markRead(id)}
                  onOpenArticle={(item) => {
                    close();
                    onOpenArticle(item);
                  }}
                />
              )}
            </div>

            <div className="penalis-bell__footer">
              <button
                type="button"
                className="penalis-notification-toggle"
                onClick={() => {
                  close();
                  onViewAll();
                }}
              >
                {hasMore ? 'Lihat semua notifikasi' : 'Buka halaman notifikasi'}
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
