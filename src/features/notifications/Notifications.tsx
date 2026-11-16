/**
 * Notifications page.
 *
 * The full feed with an all/unread filter, a bulk read receipt, and an optional
 * per-article grouping. The header bell shows the same list in a compact form;
 * both read from the controller owned by `App` so the unread count can never
 * disagree between them.
 */

import { useMemo, useState } from 'react';

import type { Account, DashboardNotification } from '../../lib/types';
import { EmptyState, ErrorBanner, Spinner } from '../../components/ui';
import { NotificationList } from './NotificationList';
import { filterNotifications, groupNotificationsByArticle, type NotificationFilter } from './query';
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
  const [filter, setFilter] = useState<NotificationFilter>('all');
  const [grouping, setGrouping] = useState<Grouping>('chronological');

  const { data, loading, error, markingId, unreadCount, load, markRead } = controller;

  const visible = useMemo(
    () => filterNotifications(data?.items ?? [], filter),
    [data, filter],
  );

  /*
   * Grouping is a secondary view, not the default: writers care about what just
   * happened, which is chronological order. Grouping answers the different
   * question "what is the state of each article".
   */
  const groups = useMemo(() => groupNotificationsByArticle(visible), [visible]);

  if (loading && !data) {
    return <Spinner label="Memuat notifikasi…" />;
  }

  if (error && !data) {
    return <ErrorBanner message={error} onRetry={() => void load()} />;
  }

  return (
    <div className="penalis-stack">
      <div className="penalis-page-heading">
        <div>
          <h2>Notifikasi</h2>
          <p className="penalis-muted">
            Status submit karya tulis, catatan revisi, dan penerbitan muncul di sini. Buka notifikasi
            untuk langsung menuju karya tulis atau catatan review yang dimaksud.
          </p>
        </div>
      </div>

      <div className="penalis-notifications">
        <div className="penalis-notification-toolbar">
          <div className="penalis-notification-filters" role="group" aria-label="Filter notifikasi">
            <button
              type="button"
              className={`penalis-notification-filter ${filter === 'all' ? 'is-active' : ''}`}
              aria-pressed={filter === 'all'}
              onClick={() => setFilter('all')}
            >
              Semua
            </button>
            <button
              type="button"
              className={`penalis-notification-filter ${filter === 'unread' ? 'is-active' : ''}`}
              aria-pressed={filter === 'unread'}
              onClick={() => setFilter('unread')}
            >
              Belum dibaca{unreadCount > 0 ? ` (${unreadCount})` : ''}
            </button>
          </div>

          <div className="penalis-notification-toolbar__actions">
            <label className="penalis-notification-grouping">
              <input
                type="checkbox"
                checked={grouping === 'article'}
                onChange={(event) => setGrouping(event.target.checked ? 'article' : 'chronological')}
              />
              <span>Kelompokkan per karya</span>
            </label>

            {unreadCount > 0 ? (
              <button
                type="button"
                className="penalis-notification-action"
                onClick={() => void markRead()}
                disabled={markingId !== null}
              >
                {markingId === 'all' ? 'Memperbarui…' : 'Tandai semua sudah dibaca'}
              </button>
            ) : null}
          </div>
        </div>

        {error ? <ErrorBanner message={error} onRetry={() => void load()} /> : null}

        {visible.length === 0 ? (
          <EmptyState
            title={filter === 'unread' ? 'Tidak ada notifikasi belum dibaca' : 'Belum ada notifikasi'}
            description={
              filter === 'unread'
                ? 'Semua notifikasi sudah kamu baca. Pilih "Semua" untuk melihat riwayat lengkap.'
                : 'Status submit karya tulis, catatan revisi, dan penerbitan akan muncul di sini.'
            }
          />
        ) : grouping === 'article' ? (
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
      </div>
    </div>
  );
}
