/**
 * Overview: account status, article counts, notifications, and recent activity.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { articlesApi, notificationsApi, ApiError } from '../../lib/api';
import type { Account, ArticleList, DashboardNotification, NotificationList } from '../../lib/types';
import { editorialStateLabel, formatDate } from '../../lib/format';
import { EmptyState, ErrorBanner, Spinner, StatusBadge } from '../../components/ui';

const INITIAL_VISIBLE_NOTIFICATIONS = 5;

export function Overview({
  account,
  onOpenEditor,
  onOpenArticle,
  onOpenArticles,
  onNotificationsChanged,
}: {
  account: Account;
  onOpenEditor: () => void;
  onOpenArticle: (id: number) => void;
  onOpenArticles: () => void;
  onNotificationsChanged?: (unreadCount: number) => void;
}) {
  const [data, setData] = useState<ArticleList | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(account.canWrite);

  const [notifications, setNotifications] = useState<NotificationList | null>(null);
  const [notificationsLoading, setNotificationsLoading] = useState(
    account.canWrite || account.isAdministrator || account.isReviewer,
  );
  const [notificationsError, setNotificationsError] = useState('');
  const [markingId, setMarkingId] = useState<string | null>(null);
  const [showAllNotifications, setShowAllNotifications] = useState(false);

  const onNotificationsChangedRef = useRef(onNotificationsChanged);
  onNotificationsChangedRef.current = onNotificationsChanged;

  const loadNotifications = useCallback(async () => {
    if (!account.canWrite && !account.isAdministrator && !account.isReviewer) {
      return;
    }

    setNotificationsLoading(true);
    setNotificationsError('');

    try {
      const result = await notificationsApi.list();
      setNotifications(result);
      onNotificationsChangedRef.current?.(result.unreadCount);
    } catch (caught) {
      setNotificationsError(
        caught instanceof ApiError ? caught.message : 'Notifikasi kamu tidak dapat dimuat.',
      );
    } finally {
      setNotificationsLoading(false);
    }
  }, [account.canWrite, account.isAdministrator, account.isReviewer]);

  useEffect(() => {
    void loadNotifications();
  }, [loadNotifications]);

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

  const markRead = async (id?: string) => {
    setMarkingId(id ?? 'all');
    setNotificationsError('');

    try {
      const updated = await notificationsApi.markRead(id);
      setNotifications(updated);
      onNotificationsChanged?.(updated.unreadCount);
    } catch (caught) {
      setNotificationsError(
        caught instanceof ApiError ? caught.message : 'Status notifikasi tidak dapat diperbarui.',
      );
    } finally {
      setMarkingId(null);
    }
  };

  const openNotificationArticle = (item: DashboardNotification) => {
    if (!item.isRead) {
      void notificationsApi
        .markRead(item.id)
        .then((updated) => {
          setNotifications(updated);
          onNotificationsChanged?.(updated.unreadCount);
        })
        .catch(() => {
          // Navigating to the article should never be blocked by a read-receipt failure.
        });
    }

    onOpenArticle(item.articleId);
  };

  const counts = data?.counts ?? account.counts;
  const unreadCount = notifications?.unreadCount ?? account.unreadNotifications ?? 0;
  const totalNotifications = notifications?.items.length ?? 0;
  const visibleNotifications = notifications
    ? showAllNotifications
      ? notifications.items
      : notifications.items.slice(0, INITIAL_VISIBLE_NOTIFICATIONS)
    : [];

  return (
    <div className="penalis-stack">
      <section className="penalis-hero">
        <div>
          <p className="penalis-eyebrow">Halo sahabat Penalis</p>
          <h2>{account.displayName}</h2>
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
        <StatCard label="Diterbitkan" value={counts.publish} />
        <StatCard label="Review" value={counts.pending} />
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

          {notificationsLoading ? (
            <Spinner label="Memuat notifikasi…" />
          ) : notificationsError ? (
            <ErrorBanner message={notificationsError} onRetry={() => void loadNotifications()} />
          ) : !notifications || notifications.items.length === 0 ? (
            <p className="penalis-muted">
              Belum ada notifikasi. Status submit karya tulis, catatan revisi, dan penerbitan akan muncul di sini.
            </p>
          ) : (
            <>
              <ul className={`penalis-notification-list ${showAllNotifications ? 'is-expanded' : ''}`}>
                {visibleNotifications.map((item) => (
                  <li
                    key={item.id}
                    className={`penalis-notification-item ${item.isRead ? '' : 'is-unread'}`}
                    aria-label={`${item.isRead ? 'Sudah dibaca' : 'Belum dibaca'}: ${item.typeLabel}`}
                  >
                    <span className="penalis-notification-item__dot" aria-hidden="true" />

                    <div className="penalis-notification-item__body">
                      <div className="penalis-notification-item__head">
                        <StatusBadge status={item.type} label={item.typeLabel} />
                        <span className="penalis-notification-item__meta">{formatDate(item.createdAt)}</span>
                      </div>

                      <p className="penalis-notification-item__message">{item.message}</p>
                    </div>

                    <div className="penalis-notification-item__actions">
                      {account.canWrite ? (
                        <button
                          type="button"
                          className="penalis-notification-action penalis-notification-action--primary"
                          onClick={() => openNotificationArticle(item)}
                        >
                          Buka karya
                        </button>
                      ) : null}

                      {item.reviewUrl ? (
                        <a
                          className="penalis-notification-action penalis-notification-action--primary"
                          href={item.reviewUrl}
                        >
                          Review di WP Admin
                        </a>
                      ) : null}

                      {!item.isRead ? (
                        <button
                          type="button"
                          className="penalis-notification-action"
                          onClick={() => void markRead(item.id)}
                          disabled={markingId !== null}
                        >
                          {markingId === item.id ? 'Menandai…' : 'Tandai dibaca'}
                        </button>
                      ) : null}
                    </div>
                  </li>
                ))}
              </ul>

              {totalNotifications > INITIAL_VISIBLE_NOTIFICATIONS ? (
                <div className="penalis-notification-footer">
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
                </div>
              ) : null}
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
      <span className="penalis-card__value">{value}</span>
      <span className="penalis-card__label">{label}</span>
    </div>
  );
}
