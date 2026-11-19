/**
 * Notifications page.
 *
 * Unread notifications come first and the list is capped at a page-sized
 * Readers choose between unread, read, and all entries explicitly. The
 * header bell shows the same list in a compact form; both read from the
 * controller owned by `App` so the unread count can never disagree.
 */

import { useMemo, useState } from 'react';

import type { Account, DashboardNotification } from '../../lib/types';
import { EmptyState, ErrorBanner, MarkAllReadButton, PageHeading, Spinner } from '../../components/ui';
import { Toggle } from '../../components/Toggle';
import { NotificationList } from './NotificationList';
import {
  countForNotificationsFilter,
  groupNotificationsByArticle,
  NOTIFICATION_PAGE_SIZE,
  selectNotificationsByFilter,
} from './query';
import type { NotificationFilter } from './query';
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
  const [filter, setFilter] = useState<NotificationFilter>('unread');
  const [grouping, setGrouping] = useState<Grouping>('chronological');
  const [visibleCount, setVisibleCount] = useState(NOTIFICATION_PAGE_SIZE);

  const { data, loading, error, markingId, unreadCount, load, markRead } = controller;

  const items = useMemo(() => data?.items ?? [], [data]);
  const total = countForNotificationsFilter(items, filter);

  const visible = useMemo(
    () => selectNotificationsByFilter(items, filter, visibleCount),
    [items, filter, visibleCount],
  );

  /*
   * Grouping is a secondary view, not the default: writers care about what just
   * happened, which is chronological order. Grouping answers the different
   * question "what is the state of each article". It only groups the entries
   * currently revealed, so a collapsed older round never forces the page long.
   */
  const groups = useMemo(() => groupNotificationsByArticle(visible), [visible]);

  const chooseFilter = (next: NotificationFilter) => {
    setFilter(next);
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
        description="Pemberitahuan status submit karya tulis muncul di sini. Buka notifikasi untuk melihat detailnya."
      />

      <div className="penalis-notifications">
        <div className="penalis-notification-toolbar">
          <div className="penalis-notification-filters" role="group" aria-label="Filter notifikasi">
            <button
              type="button"
              className={`penalis-notification-filter ${filter === 'unread' ? 'is-active' : ''}`}
              aria-pressed={filter === 'unread'}
              onClick={() => chooseFilter('unread')}
            >
              Belum dibaca{unreadCount > 0 ? ` (${unreadCount})` : ''}
            </button>
            <button
              type="button"
              className={`penalis-notification-filter ${filter === 'read' ? 'is-active' : ''}`}
              aria-pressed={filter === 'read'}
              onClick={() => chooseFilter('read')}
            >
              Sudah dibaca
            </button>
            <button
              type="button"
              className={`penalis-notification-filter ${filter === 'all' ? 'is-active' : ''}`}
              aria-pressed={filter === 'all'}
              onClick={() => chooseFilter('all')}
            >
              Semua
            </button>
          </div>

          <div className="penalis-notification-toolbar__actions">
            <div className="penalis-notification-grouping">
              <Toggle
                label="Kelompokkan per karya"
                checked={grouping === 'article'}
                onChange={(enabled) => setGrouping(enabled ? 'article' : 'chronological')}
                size="md"
              />
              <span>Kelompokkan per karya</span>
            </div>

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
            title={
              filter === 'unread'
                ? 'Tidak ada notifikasi belum dibaca'
                : filter === 'read'
                  ? 'Belum ada notifikasi yang dibaca'
                  : 'Belum ada notifikasi'
            }
            description={
              filter === 'unread'
                ? 'Semua notifikasi sudah kamu baca. Pilih "Sudah dibaca" atau "Semua" untuk melihat riwayat.'
                : 'Status submit karya tulis, catatan revisi, dan penerbitan akan muncul di sini.'
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
