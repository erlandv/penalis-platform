/**
 * Overview: account status, article counts, notification preview, and recent
 * activity. The full lists live on their own pages; this screen is a compact
 * entry point with a single action bar.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { articlesApi, ApiError, reviewApi } from '../../lib/api';
import { canReceiveNotifications, isStaffRole, roleLabel } from '../../lib/permissions';
import type {
  Account,
  ArticleList,
  DashboardNotification,
  DashboardRole,
  ReviewQueueList,
} from '../../lib/types';
import { editorialStateLabel, formatDate, formatNumber, getTimeGreeting } from '../../lib/format';
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
import { DailyQuotePanel } from '../quotes/DailyQuotePanel';

const PREVIEW_NOTIFICATIONS = 3;
const PREVIEW_ARTICLES = 3;

export function Overview({
  account,
  role,
  notifications,
  onOpenEditor,
  onOpenArticle,
  onOpenReviewArticle,
  onOpenArticles,
  onOpenNotifications,
  onOpenReview,
}: {
  account: Account;
  role: DashboardRole;
  notifications: NotificationsController;
  onOpenEditor: () => void;
  onOpenArticle: (id: number) => void;
  /** Open one submission in the reviewer reader (staff only). */
  onOpenReviewArticle: (id: number) => void;
  onOpenArticles: () => void;
  onOpenNotifications: () => void;
  onOpenReview: () => void;
}) {
  const staff = isStaffRole(role);
  const [data, setData] = useState<ArticleList | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(role === 'writer');

  /*
   * A staff account gets a different overview: the writer panels (article
   * counts, "Karya terbaru") are about work they never do, and the queue is the
   * reason they are here.
   */
  const [queueData, setQueueData] = useState<ReviewQueueList | null>(null);
  const [queueLoading, setQueueLoading] = useState(staff);
  const [queueError, setQueueError] = useState('');
  const queueRequestRef = useRef(0);

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
    if (role !== 'writer') {
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
  }, [role]);

  const loadQueue = useCallback(async () => {
    const requestId = queueRequestRef.current + 1;
    queueRequestRef.current = requestId;
    setQueueLoading(true);
    setQueueError('');

    try {
      const result = await reviewApi.queue(1);
      if (queueRequestRef.current === requestId) {
        setQueueData(result);
      }
    } catch (caught: unknown) {
      if (queueRequestRef.current === requestId) {
        setQueueError(caught instanceof ApiError ? caught.message : 'Antrean review tidak dapat dimuat.');
      }
    } finally {
      if (queueRequestRef.current === requestId) {
        setQueueLoading(false);
      }
    }
  }, []);

  // Staff land here first, so the queue summary belongs on this screen.
  useEffect(() => {
    if (!staff) {
      return;
    }

    void loadQueue();

    return () => {
      queueRequestRef.current += 1;
    };
  }, [staff, loadQueue]);

  const openNotificationArticle = (item: DashboardNotification) => {
    if (!item.isRead) {
      // Navigating to the article should never be blocked by a read-receipt failure.
      void markRead(item.id);
    }

    if (staff) {
      // Land on the submission the notification is about, not the bare queue.
      onOpenReviewArticle(item.articleId);
      return;
    }

    onOpenArticle(item.articleId);
  };

  const counts = data?.counts ?? account.counts;
  const visibleNotifications = notificationData?.items.slice(0, PREVIEW_NOTIFICATIONS) ?? [];
  const canSeeNotifications = canReceiveNotifications(account);

  const heroCopy =
    role === 'writer'
      ? 'Di dashboard penulis ini kamu dapat menulis draft, submit karya tulis untuk di-review Senator, dan memantau status submit karyamu di satu tempat. Jadi, mau menulis apa hari ini?'
      : staff
        ? 'Kamu masuk sebagai Senator Penalis. Karya tulis yang dikirim penulis menunggu keputusanmu di antrean review.'
        : role === 'suspended'
          ? 'Akun Penalis kamu ditangguhkan, jadi pengelolaan karya tulis tidak tersedia.'
          : 'Konfirmasi alamat email kamu untuk mulai menulis dan mengirim karya tulis.';

  return (
    <div className="penalis-stack">
      <section className="penalis-hero">
        <div>
          {role !== 'suspended' ? (
            <p className="penalis-hero__greeting">{getTimeGreeting()},</p>
          ) : null}
          <h2 className="penalis-hero__name">
            <span>{account.displayName}</span>
            <VerifiedWriterBadge account={account} size={20} />
          </h2>
          <p className="penalis-muted">{heroCopy}</p>
        </div>
        <div className="penalis-hero__status">
          {staff ? (
            <StatusBadge status="active" label={`Senator · ${roleLabel(role)}`} />
          ) : (
            <StatusBadge status={account.status} label={account.statusLabel} />
          )}
        </div>
      </section>

      {staff ? (
        <>
          <Panel
            label="Antrean review"
            header={
              <div className="penalis-notification-header">
                <h3>Antrean review</h3>
                {queueData ? (
                  <StatusBadge
                    status={queueData.total > 0 ? 'pending_review' : 'published'}
                    label={
                      queueData.total > 0
                        ? `${formatNumber(queueData.total)} menunggu review`
                        : 'Antrean bersih'
                    }
                  />
                ) : null}
              </div>
            }
          >
            {queueError ? (
              <ErrorBanner message={queueError} onRetry={() => void loadQueue()} />
            ) : queueLoading || queueData === null ? (
              <Spinner label="Memuat antrean review…" />
            ) : (
              <div className="penalis-overview-queue__callout">
                <div className="penalis-overview-queue__summary">
                  <p className="penalis-overview-queue__headline">
                    {queueData.total > 0 ? (
                      <>
                        Ada <strong>{formatNumber(queueData.total)} karya tulis</strong> yang menunggu untuk
                        di-review.
                      </>
                    ) : (
                      <strong>Tidak ada karya tulis yang menunggu review saat ini.</strong>
                    )}
                  </p>
                  <p className="penalis-overview-queue__subtext penalis-muted">
                    {queueData.total > 0
                      ? 'Penulis sudah meluangkan waktunya untuk berkontribusi karya kepada Penalis, mohon hargai usaha mereka dengan segera review karya tulis dan berikan kepastian.'
                      : 'Semua kiriman karya tulis telah diproses. Antrean akan terisi saat penulis mengirim karya baru atau hasil revisi.'}
                  </p>
                </div>

                <div className="penalis-overview-queue__cta">
                  <button
                    type="button"
                    className="penalis-button penalis-button--primary"
                    onClick={onOpenReview}
                  >
                    Buka antrean review
                  </button>
                </div>
              </div>
            )}
          </Panel>

          {/*
           * The two-accounts rule, stated once where a Senator lands rather
           * than on every screen: writing is done with the writer account.
           */}
          <div className="penalis-senator-note">
            <Icon name="users" />
            <p>
              <strong>Ingin menulis?</strong> Akun Senator hanya untuk mengelola platform. Logout lalu masuk
              dengan akun Penulis kamu untuk membuat karya tulis.
            </p>
          </div>
        </>
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
          {role === 'writer' ? (
            <>
              <button type="button" className="penalis-button penalis-button--primary" onClick={onOpenEditor}>
                Buat karya baru
              </button>
              <button type="button" className="penalis-button penalis-button--ghost" onClick={onOpenArticles}>
                Lihat semua karya
              </button>
            </>
          ) : staff && queueData && queueData.total > 0 ? (
            <button type="button" className="penalis-button penalis-button--primary" onClick={onOpenReview}>
              Buka antrean review
            </button>
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

      {role === 'writer' ? <DailyQuotePanel account={account} /> : null}

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
              {staff
                ? 'Karya tulis baru dan revisi yang menunggu keputusanmu akan muncul di sini.'
                : 'Status submit karya tulis, catatan revisi, dan penerbitan akan muncul di sini.'}
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

      {/* Writer-only panel: staff have no articles of their own here. */}
      {!staff ? (
        <Panel title="Karya terbaru">
          {role !== 'writer' ? (
            <p className="penalis-muted">
              {role === 'suspended'
                ? 'Akun kamu ditangguhkan, jadi kamu belum dapat mengelola karya tulis.'
                : 'Kamu dapat menulis dan submit karya tulismu setelah alamat email kamu dikonfirmasi.'}
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
