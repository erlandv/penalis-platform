/**
 * Overview: account status, article counts, notification preview, and recent
 * activity. The full lists live on their own pages; this screen is a compact
 * entry point with a single action bar.
 */

import { useEffect, useState } from 'react';

import { articlesApi, ApiError, reviewApi } from '../../lib/api';
import { canReceiveNotifications, canReview } from '../../lib/permissions';
import type { Account, ArticleList, DashboardNotification } from '../../lib/types';
import { editorialStateLabel, formatDate, formatNumber } from '../../lib/format';
import { Icon } from '../../components/icons';
import {
  EmptyState,
  ErrorBanner,
  MarkAllReadButton,
  Panel,
  Spinner,
  StatusBadge,
  VerifiedWriterBadge,
} from '../../components/ui';
import { NotificationList } from '../notifications/NotificationList';
import type { NotificationsController } from '../notifications/useNotifications';

const PREVIEW_NOTIFICATIONS = 3;
const PREVIEW_ARTICLES = 3;

export function Overview({
  account,
  notifications,
  onOpenEditor,
  onOpenArticle,
  onOpenArticles,
  onOpenNotifications,
  onOpenReview,
}: {
  account: Account;
  notifications: NotificationsController;
  onOpenEditor: () => void;
  onOpenArticle: (id: number) => void;
  onOpenArticles: () => void;
  onOpenNotifications: () => void;
  onOpenReview: () => void;
}) {
  const [data, setData] = useState<ArticleList | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(account.canWrite);

  /*
   * A reviewer without writer capabilities gets a different overview: the
   * writer panels (article counts, "Karya terbaru") are all zeros for them and
   * the queue is the reason they are here.
   */
  const isReviewerOnly = canReview(account) && !account.canWrite;
  const [queueTotal, setQueueTotal] = useState<number | null>(null);
  const [queueError, setQueueError] = useState('');

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

  // Reviewers land here first, so the queue size belongs on this screen.
  useEffect(() => {
    if (!isReviewerOnly) {
      return;
    }

    let cancelled = false;
    setQueueError('');

    reviewApi
      .queue(1)
      .then((result) => {
        if (!cancelled) {
          setQueueTotal(result.total);
        }
      })
      .catch((caught: unknown) => {
        if (!cancelled) {
          setQueueError(caught instanceof ApiError ? caught.message : 'Antrean review tidak dapat dimuat.');
        }
      });

    return () => {
      cancelled = true;
    };
  }, [isReviewerOnly]);

  const openNotificationArticle = (item: DashboardNotification) => {
    if (!item.isRead) {
      // Navigating to the article should never be blocked by a read-receipt failure.
      void markRead(item.id);
    }

    onOpenArticle(item.articleId);
  };

  const counts = data?.counts ?? account.counts;
  const visibleNotifications = notificationData?.items.slice(0, PREVIEW_NOTIFICATIONS) ?? [];
  const canSeeNotifications = canReceiveNotifications(account);

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
              : isReviewerOnly
                ? 'Kamu masuk sebagai reviewer. Karya tulis penulis dikurasi dari antrean review di dashboard ini.'
                : 'Akun Penalis kamu belum dapat mengelola karya tulis. Hubungi admin jika ada pertanyaan.'}
          </p>
        </div>
        <div className="penalis-hero__status">
          <StatusBadge status={account.status} label={account.statusLabel} />
        </div>
      </section>

      {isReviewerOnly ? (
        <Panel
          title="Antrean review"
          aside={
            <button type="button" className="penalis-button penalis-button--primary" onClick={onOpenReview}>
              Buka antrean review
            </button>
          }
        >
          {queueError ? (
            <ErrorBanner message={queueError} />
          ) : queueTotal === null ? (
            <Spinner label="Memuat antrean review…" />
          ) : queueTotal === 0 ? (
            <p className="penalis-muted">
              Tidak ada karya tulis yang menunggu review. Antrean akan terisi saat penulis mengirim karya.
            </p>
          ) : (
            <p>
              <strong>{formatNumber(queueTotal)}</strong> karya tulis menunggu keputusan. Buka antrean untuk
              membaca dan memutuskan.
            </p>
          )}
        </Panel>
      ) : (
        <section className="penalis-cards" aria-label="Ringkasan karya tulis">
          <StatCard label="Total karya" value={counts.all} />
          <StatCard label="Total views" value={counts.views ?? 0} />
          <StatCard label="Diterbitkan" value={counts.publish} />
          <StatCard label="Review" value={counts.pending} />
          <StatCard label="Revisi" value={counts.changes_requested ?? 0} />
          <StatCard label="Draft" value={counts.draft} />
        </section>
      )}

      {canSeeNotifications ? (
        <div className="penalis-quick-actions" role="group" aria-label="Aksi cepat">
          <span className="penalis-quick-actions__label">Aksi cepat</span>
          {account.canWrite ? (
            <>
              <button type="button" className="penalis-button penalis-button--primary" onClick={onOpenEditor}>
                Buat karya baru
              </button>
              <button type="button" className="penalis-button penalis-button--ghost" onClick={onOpenArticles}>
                Lihat semua karya
              </button>
            </>
          ) : null}
          <button type="button" className="penalis-button penalis-button--ghost" onClick={onOpenNotifications}>
            Lihat semua notifikasi
            {unreadCount > 0 ? (
              <span className="penalis-quick-actions__badge" aria-label={`${unreadCount} belum dibaca`}>
                {unreadCount}
              </span>
            ) : null}
          </button>
        </div>
      ) : null}

      {canSeeNotifications ? (
        <Panel
          label="Notifikasi review karya tulis"
          header={
            <>
              <div className="penalis-notification-header">
                <h3>Notifikasi terbaru</h3>
                {unreadCount > 0 ? (
                  <StatusBadge status="pending_review" label={`${unreadCount} belum dibaca`} />
                ) : null}
              </div>
              {unreadCount > 0 ? (
                <MarkAllReadButton markingId={markingId} onMarkRead={() => void markRead()} />
              ) : null}
            </>
          }
        >

          {notificationsLoading && !notificationData ? (
            <Spinner label="Memuat notifikasi…" />
          ) : notificationsError && !notificationData ? (
            <ErrorBanner message={notificationsError} onRetry={() => void loadNotifications()} />
          ) : visibleNotifications.length === 0 ? (
            <p className="penalis-muted">
              Belum ada notifikasi. Status submit karya tulis, catatan revisi, dan penerbitan akan muncul di sini.
            </p>
          ) : (
            <NotificationList
              items={visibleNotifications}
              account={account}
              markingId={markingId}
              onMarkRead={(id) => void markRead(id)}
              onOpenArticle={openNotificationArticle}
              compact
            />
          )}
        </Panel>
      ) : null}

      {/* Writer-only panel: a reviewer has no articles of their own here. */}
      {!isReviewerOnly ? (
        <Panel title="Karya terbaru">
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
              {data?.items.slice(0, PREVIEW_ARTICLES).map((article) => (
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
        </Panel>
      ) : null}
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
