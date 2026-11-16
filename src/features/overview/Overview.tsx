/**
 * Overview: account status, article counts, notifications, and recent activity.
 */

import { useEffect, useState } from 'react';

import { articlesApi, ApiError } from '../../lib/api';
import type { Account, ArticleList, DashboardNotification } from '../../lib/types';
import { editorialStateLabel, formatDate, formatNumber } from '../../lib/format';
import { Icon } from '../../components/icons';
import { EmptyState, ErrorBanner, Spinner, StatusBadge, VerifiedWriterBadge } from '../../components/ui';
import { NotificationList } from '../notifications/NotificationList';
import type { NotificationsController } from '../notifications/useNotifications';

const INITIAL_VISIBLE_NOTIFICATIONS = 5;

export function Overview({
  account,
  notifications,
  onOpenEditor,
  onOpenArticle,
  onOpenArticles,
  onOpenNotifications,
}: {
  account: Account;
  notifications: NotificationsController;
  onOpenEditor: () => void;
  onOpenArticle: (id: number) => void;
  onOpenArticles: () => void;
  onOpenNotifications: () => void;
}) {
  const [data, setData] = useState<ArticleList | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(account.canWrite);
  const [showAllNotifications, setShowAllNotifications] = useState(false);

  const {
    data: notificationData,
    loading: notificationsLoading,
    error: notificationsError,
    markingId,
    unreadCount,
    load: loadNotifications,
    markRead,
  } = notifications;

  useEffect(() => {
    if (!account.canWrite) {
      return;
    }

    let cancelled = false;
    setLoading(true);
    setError('');

    articlesApi
      .list(1, 'all')
      .then((result) => {
        if (!cancelled) {
          setData(result);
        }
      })
      .catch((caught: unknown) => {
        if (!cancelled) {
          setError(caught instanceof ApiError ? caught.message : 'Karya tulis kamu tidak dapat dimuat.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [account.canWrite]);

  const openNotificationArticle = (item: DashboardNotification) => {
    if (!item.isRead) {
      // Navigating to the article should never be blocked by a read-receipt failure.
      void markRead(item.id);
    }

    onOpenArticle(item.articleId);
  };

  const counts = data?.counts ?? account.counts;
  const totalNotifications = notificationData?.items.length ?? 0;
  const visibleNotifications = notificationData
    ? showAllNotifications
      ? notificationData.items
      : notificationData.items.slice(0, INITIAL_VISIBLE_NOTIFICATIONS)
    : [];

  return (
    <div className="penalis-stack">
      <section className="penalis-hero">
        <div>
          <p className="penalis-eyebrow">Halo sahabat Penalis</p>
          <h2 className="penalis-hero__name">
            <span>{account.displayName}</span>
            <VerifiedWriterBadge account={account} size={20} />
          </h2>
          <p className="penalis-muted">
            {account.canWrite
              ? 'Di dashboard penulis ini kamu dapat menulis draft, submit karya tulis untuk di-review admin, dan memantau status submit karyamu di satu tempat. Jadi, mau menulis apa hari ini?'
              : account.isReviewer
                ? 'Kamu login sebagai Reviewer. Kamu dapat melihat bagaimana dashboard penulis bekerja; untuk mengambil keputusan review, gunakan Review Karya Tulis di WordPress Admin.'
                : 'Akun Penalis kamu saat ini belum dapat mengelola karya tulis. Kamu dapat meninjau detail akun di bawah ini. Jika ada pertanyaan, hubungi admin Penalis.'}
          </p>
        </div>
        <div className="penalis-hero__status">
          <StatusBadge status={account.status} label={account.statusLabel} />
        </div>
      </section>

      <section className="penalis-cards" aria-label="Ringkasan karya tulis">
        <StatCard label="Total karya" value={counts.all} />
        <StatCard label="Total views" value={counts.views ?? 0} />
        <StatCard label="Diterbitkan" value={counts.publish} />
        <StatCard label="Review" value={counts.pending} />
        <StatCard label="Revisi" value={counts.changes_requested ?? 0} />
        <StatCard label="Draft" value={counts.draft} />
      </section>

      {account.canWrite ? (
        <div className="penalis-actions-row">
          <button type="button" className="penalis-button penalis-button--primary" onClick={onOpenEditor}>
            Buat karya baru
          </button>
          <button type="button" className="penalis-button penalis-button--ghost" onClick={onOpenArticles}>
            Lihat semua karya
          </button>
        </div>
      ) : null}

      {account.canWrite || account.isAdministrator || account.isReviewer ? (
        <section className="penalis-panel" aria-label="Notifikasi review karya tulis">
          <div className="penalis-panel__header">
            <div className="penalis-notification-header">
              <h3>Notifikasi Review Karya Tulis</h3>
              {unreadCount > 0 ? (
                <StatusBadge status="pending_review" label={`${unreadCount} belum dibaca`} />
              ) : null}
            </div>
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

          {notificationsLoading && !notificationData ? (
            <Spinner label="Memuat notifikasi…" />
          ) : notificationsError && !notificationData ? (
            <ErrorBanner message={notificationsError} onRetry={() => void loadNotifications()} />
          ) : visibleNotifications.length === 0 ? (
            <p className="penalis-muted">
              Belum ada notifikasi. Status submit karya tulis, catatan revisi, dan penerbitan akan muncul di sini.
            </p>
          ) : (
            <>
              <NotificationList
                items={visibleNotifications}
                account={account}
                markingId={markingId}
                onMarkRead={(id) => void markRead(id)}
                onOpenArticle={openNotificationArticle}
              />

              <div className="penalis-notification-footer">
                {totalNotifications > INITIAL_VISIBLE_NOTIFICATIONS ? (
                  <button
                    type="button"
                    className="penalis-notification-toggle"
                    aria-expanded={showAllNotifications}
                    onClick={() => setShowAllNotifications((prev) => !prev)}
                  >
                    {showAllNotifications
                      ? 'Tampilkan lebih sedikit'
                      : `Lihat semua (${totalNotifications} notifikasi)`}
                  </button>
                ) : null}
                <button
                  type="button"
                  className="penalis-notification-toggle"
                  onClick={onOpenNotifications}
                >
                  Buka halaman notifikasi
                </button>
              </div>
            </>
          )}
        </section>
      ) : null}

      <section className="penalis-panel">
        <div className="penalis-panel__header">
          <h3>Karya Tulis Terbaru</h3>
        </div>

        {!account.canWrite ? (
          <p className="penalis-muted">
            Kamu dapat menulis dan submit karya tulismu setelah alamat email kamu dikonfirmasi.
          </p>
        ) : loading ? (
          <Spinner label="Memuat karya tulismu…" />
        ) : error ? (
          <ErrorBanner message={error} />
        ) : data && data.items.length === 0 ? (
          <EmptyState
            title="Belum ada karya"
            description="Karya tulis pertama kamu dimulai di sini. Kamu dapat menyimpan draft dan kembali kapan saja."
            action={
              <button type="button" className="penalis-button penalis-button--primary" onClick={onOpenEditor}>
                Buat karya tulis pertama
              </button>
            }
          />
        ) : (
          <ul className="penalis-article-list">
            {data?.items.slice(0, 5).map((article) => (
              <li key={article.id} className="penalis-article-row">
                <div>
                  <strong>{article.title || 'Tanpa judul'}</strong>
                  <p className="penalis-muted">{article.excerpt}</p>
                </div>
                <div className="penalis-article-row__meta">
                  <StatusBadge
                    status={article.editorialState}
                    label={article.editorialStateLabel || editorialStateLabel(article.editorialState)}
                  />
                  {article.editorialState === 'published' ? (
                    <span className="penalis-article-views" aria-label={`${formatNumber(article.views)} views`}>
                      <Icon name="eye" />
                      <span>{formatNumber(article.views)} views</span>
                    </span>
                  ) : null}
                  <span>{formatDate(article.modified)}</span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function StatCard({ label, value }: { label: string; value: number }) {
  return (
    <div className="penalis-card">
      <span className="penalis-card__value">{formatNumber(value)}</span>
      <span className="penalis-card__label">{label}</span>
    </div>
  );
}
