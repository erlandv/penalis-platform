/**
 * Senator moderation screen for #KalimatHariIni (`/dashboard/?view=quotes`).
 *
 * Lists all currently active 24-hour quotes with search, sort, pagination
 * (load more), and confirmation-gated deletion so administrators and editors
 * can take down spam or guideline violations without leaving the dashboard.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError, quotesApi } from '../../lib/api';
import { getBootstrap } from '../../lib/bootstrap';
import { formatQuoteRelativeTime, formatQuoteRemainingTime } from '../../lib/dailyQuote';
import { formatDateTime, formatNumber } from '../../lib/format';
import type { DailyQuoteModerationItem } from '../../lib/types';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { Icon, SenatorBadgeIcon, VerifiedBadgeIcon } from '../../components/icons';
import { useToast } from '../../components/toastContext';
import { EmptyState, ErrorBanner, PageHeading, Panel, Spinner, StatusBadge } from '../../components/ui';

const PER_PAGE = 12;

export function QuotesModeration() {
  const showToast = useToast();
  const publicUrl = getBootstrap().dailyQuotesUrl || '';

  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [sort, setSort] = useState<'newest' | 'oldest'>('newest');

  const [items, setItems] = useState<DailyQuoteModerationItem[]>([]);
  const [total, setTotal] = useState(0);
  const [totalAll, setTotalAll] = useState(0);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');

  const [pendingDelete, setPendingDelete] = useState<DailyQuoteModerationItem | null>(null);
  const [deleting, setDeleting] = useState(false);

  const requestRef = useRef(0);

  // Debounce search input so typing does not fire a request on every keystroke.
  useEffect(() => {
    const trimmed = searchInput.trim();
    if (trimmed === search) {
      return;
    }

    const timer = window.setTimeout(() => {
      setSearch(trimmed);
    }, 250);

    return () => window.clearTimeout(timer);
  }, [searchInput, search]);

  const load = useCallback(
    async (targetPage: number, append: boolean) => {
      const reqId = requestRef.current + 1;
      requestRef.current = reqId;

      if (append) {
        setLoadingMore(true);
      } else {
        setLoading(true);
      }
      setError('');

      try {
        const result = await quotesApi.list({
          search,
          sort,
          page: targetPage,
          perPage: PER_PAGE,
        });

        if (requestRef.current !== reqId) {
          return;
        }

        setItems((prev) => (append ? [...prev, ...result.items] : result.items));
        setTotal(result.total);
        setTotalAll(result.totalAll);
        setPage(result.page);
        setHasMore(result.hasMore);
      } catch (caught: unknown) {
        if (requestRef.current !== reqId) {
          return;
        }

        setError(
          caught instanceof ApiError
            ? caught.message
            : 'Daftar #KalimatHariIni tidak dapat dimuat.',
        );
      } finally {
        if (requestRef.current === reqId) {
          setLoading(false);
          setLoadingMore(false);
        }
      }
    },
    [search, sort],
  );

  useEffect(() => {
    void load(1, false);
    return () => {
      requestRef.current += 1;
    };
  }, [load]);

  const handleLoadMore = () => {
    if (loading || loadingMore || !hasMore) {
      return;
    }
    void load(page + 1, true);
  };

  const handleConfirmDelete = async () => {
    if (!pendingDelete || deleting) {
      return;
    }

    setDeleting(true);
    try {
      const target = pendingDelete;
      await quotesApi.moderateDelete(target.authorId);
      setItems((prev) => prev.filter((item) => item.authorId !== target.authorId));
      setTotal((prev) => Math.max(0, prev - 1));
      setTotalAll((prev) => Math.max(0, prev - 1));
      setPendingDelete(null);
      showToast(`#KalimatHariIni milik ${target.displayName} dihapus.`);
    } catch (caught: unknown) {
      showToast(
        caught instanceof ApiError ? caught.message : '#KalimatHariIni tidak dapat dihapus.',
        'danger',
      );
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="penalis-stack">
      <PageHeading
        title="Kalimat Hari Ini"
        description="Pantau #KalimatHariIni yang sedang tayang dalam 24 jam terakhir dan hapus kalimat yang melanggar norma komunitas."
        action={
          <div className="penalis-quotes-mod__header-actions">
            {publicUrl ? (
              <a
                href={publicUrl}
                target="_blank"
                rel="noopener noreferrer"
                className="penalis-button penalis-button--ghost"
              >
                <span>Buka halaman publik</span>
                <Icon name="externalLink" />
              </a>
            ) : null}
            <button
              type="button"
              className="penalis-button penalis-button--ghost"
              onClick={() => void load(1, false)}
              disabled={loading || loadingMore}
            >
              Muat ulang
            </button>
          </div>
        }
      />

      <Panel
        label="Daftar moderasi Kalimat Hari Ini"
        header={
          <>
            <div className="penalis-daily-quote-panel__title-group">
              <h3>Sedang tayang</h3>
              {!loading && !error ? (
                <StatusBadge
                  status={totalAll > 0 ? 'active' : 'draft'}
                  label={`${formatNumber(totalAll)} aktif`}
                />
              ) : null}
            </div>
            <div className="penalis-quotes-mod__toolbar">
              <input
                id="penalis-quotes-mod-search"
                name="quotes_search"
                type="search"
                className="penalis-quotes-mod__search"
                placeholder="Cari kalimat atau penulis…"
                value={searchInput}
                onChange={(event) => setSearchInput(event.target.value)}
                aria-label="Cari kalimat atau nama penulis"
              />
              <select
                id="penalis-quotes-mod-sort"
                name="quotes_sort"
                className="penalis-quotes-mod__sort"
                value={sort}
                onChange={(event) => setSort(event.target.value === 'oldest' ? 'oldest' : 'newest')}
                aria-label="Urutkan daftar kalimat"
              >
                <option value="newest">Terbaru</option>
                <option value="oldest">Terlama</option>
              </select>
            </div>
          </>
        }
      >
        {loading ? (
          <Spinner label="Memuat #KalimatHariIni…" />
        ) : error ? (
          <ErrorBanner message={error} onRetry={() => void load(1, false)} />
        ) : items.length === 0 ? (
          search !== '' ? (
            <EmptyState
              title="Tidak ada kalimat yang cocok"
              description={`Tidak ditemukan #KalimatHariIni aktif untuk pencarian "${search}".`}
              action={
                <button
                  type="button"
                  className="penalis-button penalis-button--ghost"
                  onClick={() => {
                    setSearchInput('');
                    setSearch('');
                  }}
                >
                  Hapus pencarian
                </button>
              }
            />
          ) : (
            <EmptyState
              title="Belum ada #KalimatHariIni aktif"
              description="Semua kalimat yang diterbitkan penulis dalam 24 jam terakhir akan muncul di sini untuk dipantau."
            />
          )
        ) : (
          <>
            <p className="penalis-muted penalis-quotes-mod__summary">
              Menampilkan <strong>{formatNumber(items.length)}</strong> dari{' '}
              <strong>{formatNumber(total)}</strong> #KalimatHariIni
              {search !== '' ? ` untuk "${search}"` : ' aktif'}.
            </p>

            <ul className="penalis-quotes-mod__list">
              {items.map((item) => (
                <li key={item.authorId} className="penalis-quotes-mod__item">
                  <div className="penalis-quotes-mod__main">
                    <div className="penalis-daily-quote-note__head">
                      <img
                        src={item.avatarUrl}
                        alt=""
                        className="penalis-daily-quote-note__avatar"
                        width={36}
                        height={36}
                        aria-hidden="true"
                      />
                      <div className="penalis-daily-quote-note__identity">
                        <a
                          href={item.authorUrl}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="penalis-quotes-mod__author-link"
                        >
                          <span>{item.displayName}</span>
                          {item.isVerified ? (
                            <span className="penalis-verified-badge" aria-label="Penulis Aktif terverifikasi">
                              <VerifiedBadgeIcon size={14} />
                            </span>
                          ) : null}
                          {item.isSenator ? (
                            <span className="penalis-quotes-mod__senator" aria-label="Senator Penalis">
                              <SenatorBadgeIcon size={14} />
                            </span>
                          ) : null}
                        </a>
                        <span className="penalis-daily-quote-note__submeta">
                          @{item.username} · {formatQuoteRelativeTime(item.createdAt)} (
                          {formatDateTime(item.createdAt)}) · {formatQuoteRemainingTime(item.expiresAt)}
                        </span>
                      </div>
                    </div>

                    <p className="penalis-daily-quote-note__text">{item.text}</p>
                    <span className="penalis-daily-quote-hashtag">#KalimatHariIni</span>
                  </div>

                  <div className="penalis-quotes-mod__actions">
                    <button
                      type="button"
                      className="penalis-button penalis-button--danger"
                      onClick={() => setPendingDelete(item)}
                    >
                      <Icon name="trash" />
                      <span>Hapus</span>
                    </button>
                  </div>
                </li>
              ))}
            </ul>

            {hasMore ? (
              <div className="penalis-quotes-mod__loadmore">
                <button
                  type="button"
                  className="penalis-button penalis-button--ghost"
                  onClick={handleLoadMore}
                  disabled={loadingMore}
                >
                  {loadingMore ? 'Memuat…' : 'Muat lebih banyak'}
                </button>
              </div>
            ) : null}
          </>
        )}
      </Panel>

      {pendingDelete ? (
        <ConfirmDialog
          title="Hapus #KalimatHariIni ini?"
          description={
            <>
              <p>
                Kalimat milik <strong>{pendingDelete.displayName}</strong> (@{pendingDelete.username}) akan
                langsung diturunkan dari halaman profil penulis dan halaman publik #KalimatHariIni.
              </p>
              <div className="penalis-quotes-mod__confirm-preview">{pendingDelete.text}</div>
            </>
          }
          confirmLabel="Ya, hapus kalimat"
          tone="danger"
          busy={deleting}
          onConfirm={() => void handleConfirmDelete()}
          onCancel={() => setPendingDelete(null)}
        />
      ) : null}
    </div>
  );
}
