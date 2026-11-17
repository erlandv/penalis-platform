/**
 * Notifications page.
 *
 * Unread notifications come first and the list is capped at a page-sized
 * slice, so a full 50-entry feed never becomes one very long page. Readers
 * opt in to read history and reveal older entries explicitly. The header bell
 * shows the same list in a compact form; both read from the controller owned
 * by `App` so the unread count can never disagree between them.
 */

import { useMemo, useState } from 'react';

import type { Account, DashboardNotification } from '../../lib/types';
import { EmptyState, ErrorBanner, MarkAllReadButton, PageHeading, Spinner } from '../../components/ui';
import { NotificationList } from './NotificationList';
import {
  countForView,
  groupNotificationsByArticle,
  NOTIFICATION_PAGE_SIZE,
  selectNotifications,
} from './query';
import type { NotificationsController } from './useNotifications';

type Grouping = 'chronological' | 'article';

export function Notifications({
  account,
  controller,
  onOpenArticle,
}: {
  account: Account;
  controller: NotificationsController;
  onOpenArticle: (item: DashboardNotification) => void;
}) {
  const [showHistory, setShowHistory] = useState(false);
  const [grouping, setGrouping] = useState<Grouping>('chronological');
  const [visibleCount, setVisibleCount] = useState(NOTIFICATION_PAGE_SIZE);

  const { data, loading, error, markingId, unreadCount, load, markRead } = controller;

  const items = useMemo(() => data?.items ?? [], [data]);
  const total = countForView(items, showHistory);

  const visible = useMemo(
    () => selectNotifications(items, showHistory, visibleCount),
    [items, showHistory, visibleCount],
  );

  /*
   * Grouping is a secondary view, not the default: writers care about what just
   * happened, which is chronological order. Grouping answers the different
   * question "what is the state of each article". It only groups the entries
   * currently revealed, so a collapsed older round never forces the page long.
   */
  const groups = useMemo(() => groupNotificationsByArticle(visible), [visible]);

  const toggleHistory = () => {
    setShowHistory((current) => !current);
    setVisibleCount(NOTIFICATION_PAGE_SIZE);
  };

  const loadMore = () => {
    setVisibleCount((current) => Math.min(current + NOTIFICATION_PAGE_SIZE, total));
  };

  if (loading && !data) {
    return <Spinner label="Memuat notifikasi…" />;
  }

  if (error && !data) {
    return <ErrorBanner message={error} onRetry={() => void load()} />;
  }

  return (
    <div className="penalis-stack">
      <PageHeading
        title="Notifikasi"
        description="Status submit karya tulis, catatan revisi, dan penerbitan muncul di sini. Buka notifikasi untuk langsung menuju karya tulis atau catatan review yang dimaksud."
      />

      <div className="penalis-notifications">
        <div className="penalis-notification-toolbar">
          <div className="penalis-notification-filters" role="group" aria-label="Filter notifikasi">
            <button
              type="button"
              className={`penalis-notification-filter ${showHistory ? '' : 'is-active'}`}
              aria-pressed={!showHistory}
              onClick={() => {
                if (showHistory) {
                  toggleHistory();
                }
              }}
            >
              Belum dibaca{unreadCount > 0 ? ` (${unreadCount})` : ''}
            </button>
            <button
              type="button"
              className={`penalis-notification-filter ${showHistory ? 'is-active' : ''}`}
              aria-pressed={showHistory}
              onClick={() => {
                if (!showHistory) {
                  toggleHistory();
                }
              }}
            >
              Semua
            </button>
          </div>

          <div className="penalis-notification-toolbar__actions">
            <label className="penalis-notification-grouping penalis-notification-switch">
              <input
                type="checkbox"
                checked={grouping === 'article'}
                onChange={(event) => setGrouping(event.target.checked ? 'article' : 'chronological')}
              />
              <span>Kelompokkan per karya</span>
            </label>

            {unreadCount > 0 ? (
              <MarkAllReadButton
                markingId={markingId}
                onMarkRead={() => void markRead()}
                label="Tandai semua sudah dibaca"
              />
            ) : null}
          </div>
        </div>

        {error ? <ErrorBanner message={error} onRetry={() => void load()} /> : null}

        {total === 0 ? (
          <EmptyState
            title={showHistory ? 'Belum ada notifikasi' : 'Tidak ada notifikasi belum dibaca'}
            description={
              showHistory
                ? 'Status submit karya tulis, catatan revisi, dan penerbitan akan muncul di sini.'
                : 'Semua notifikasi sudah kamu baca. Pilih "Semua" untuk melihat riwayat lengkap.'
            }
          />
        ) : (
          <>
            {grouping === 'article' ? (
              <div className="penalis-notification-groups">
                {groups.map((group) => (
                  <section key={group.articleId} className="penalis-notification-group">
                    <h3 className="penalis-notification-group__title">
                      {group.articleTitle || 'Tanpa judul'}
                    </h3>
                    <NotificationList
                      items={group.items}
                      account={account}
                      markingId={markingId}
                      onMarkRead={(id) => void markRead(id)}
                      onOpenArticle={onOpenArticle}
                    />
                  </section>
                ))}
              </div>
            ) : (
              <NotificationList
                items={visible}
                account={account}
                markingId={markingId}
                onMarkRead={(id) => void markRead(id)}
                onOpenArticle={onOpenArticle}
              />
            )}

            {visibleCount < total ? (
              <div className="penalis-notification-more">
                <button
                  type="button"
                  className="penalis-notification-action penalis-notification-action--primary"
                  onClick={loadMore}
                >
                  Muat lebih banyak ({total - visibleCount} tersisa)
                </button>
              </div>
            ) : null}
          </>
        )}
      </div>
    </div>
  );
}
