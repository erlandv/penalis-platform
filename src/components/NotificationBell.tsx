/**
 * Header notification bell with a compact dropdown.
 *
 * The badge used to live on the "Ringkasan" navigation item, which a writer
 * only sees while already on that page. The bell is visible from every view, so
 * a new review decision is noticed wherever the writer happens to be.
 *
 * Interaction is shared with `AccountMenu` through `useDisclosure`: hover opens
 * it for a mouse, click/Enter/Space/ArrowDown pins it for keyboard and touch,
 * Escape closes it and returns focus to the trigger, and focus leaving the
 * widget closes it. Reusing the pattern keeps the header predictable.
 *
 * Unlike `AccountMenu` this is a disclosure panel, not an ARIA menu: it holds a
 * mixed list of notification rows plus actions, so it must not claim
 * `role="menu"` (which would promise that every child is a `menuitem`). Tab
 * moves through the rows and buttons in document order.
 */

import type { Account, DashboardNotification } from '../lib/types';
import { Icon } from './icons';
import { ErrorBanner, MarkAllReadButton, Spinner } from './ui';
import { useDisclosure } from './useDisclosure';
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
    onMenuKeyDown,
  } = useDisclosure({ focusSelector: 'button, a[href]' });

  const { data, loading, error, markingId, unreadCount, load, markRead } = controller;

  const preview = (data?.items ?? []).slice(0, PREVIEW_LIMIT);
  const hasMore = (data?.items.length ?? 0) > PREVIEW_LIMIT;

  return (
    <div
      className="penalis-bell penalis-popover-anchor"
      ref={wrapperRef}
      onPointerEnter={onPointerEnter}
      onPointerLeave={onPointerLeave}
      onBlur={onBlur}
      onKeyDown={onMenuKeyDown}
    >
      <button
        type="button"
        ref={triggerRef}
        className={`penalis-bell__trigger penalis-popover-trigger ${open ? 'is-open' : ''}`}
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
        <div className="penalis-bell__popover penalis-popover">
          <div
            className="penalis-bell__menu penalis-popover__panel"
            id="penalis-notification-menu"
            aria-label="Notifikasi"
            ref={menuRef}
          >
            <div className="penalis-bell__header">
              <span className="penalis-bell__title">Notifikasi</span>
              {unreadCount > 0 ? (
                <MarkAllReadButton markingId={markingId} onMarkRead={() => void markRead()} />
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
                  showAdminLink={false}
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
