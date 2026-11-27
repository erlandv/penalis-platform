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
import {
  countCodePoints,
  DAILY_QUOTE_REASON_MAX_LENGTH,
  DAILY_QUOTE_REMOVAL_PRESETS,
  formatQuoteRelativeTime,
  formatQuoteRemainingTime,
  normalizeRemovalReason,
  validateRemovalReason,
} from '../../lib/dailyQuote';
import { formatDateTime, formatNumber } from '../../lib/format';
import type { DailyQuoteModerationItem } from '../../lib/types';
import { Dialog } from '../../components/Dialog';
import { Icon, SenatorBadgeIcon, VerifiedBadgeIcon } from '../../components/icons';
import { useToast } from '../../components/toastContext';
import { EmptyState, ErrorBanner, PageHeading, Panel, Spinner, StatusBadge } from '../../components/ui';

const PER_PAGE = 12;

export function QuotesModeration() {
  const showToast = useToast();
  const bootstrap = getBootstrap();
  const publicUrl = bootstrap.dailyQuotesUrl || '';
  const reasonMaxLength = bootstrap.config?.quoteReasonMaxLength ?? DAILY_QUOTE_REASON_MAX_LENGTH;
  const removalPresets =
    bootstrap.config?.quoteRemovalPresets && bootstrap.config.quoteRemovalPresets.length > 0
      ? bootstrap.config.quoteRemovalPresets
      : DAILY_QUOTE_REMOVAL_PRESETS;

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
  const [deleteReason, setDeleteReason] = useState('');
  const [reasonError, setReasonError] = useState('');
  const [deleting, setDeleting] = useState(false);

  const requestRef = useRef(0);
  const reasonInputRef = useRef<HTMLTextAreaElement>(null);

  const openDeleteDialog = (item: DailyQuoteModerationItem) => {
    setPendingDelete(item);
    setDeleteReason('');
    setReasonError('');
  };

  const closeDeleteDialog = useCallback(() => {
    if (deleting) {
      return;
    }
    setPendingDelete(null);
    setDeleteReason('');
    setReasonError('');
  }, [deleting]);

  const pendingAuthorId = pendingDelete?.authorId ?? null;
  useEffect(() => {
    if (pendingAuthorId !== null) {
      reasonInputRef.current?.focus();
    }
  }, [pendingAuthorId]);

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

    const validationError = validateRemovalReason(deleteReason, reasonMaxLength);
    if (validationError) {
      setReasonError(validationError);
      reasonInputRef.current?.focus();
      return;
    }

    const normalizedReason = normalizeRemovalReason(deleteReason);
    setReasonError('');
    setDeleting(true);
    try {
      const target = pendingDelete;
      await quotesApi.moderateDelete(target.authorId, normalizedReason);
      setItems((prev) => prev.filter((item) => item.authorId !== target.authorId));
      setTotal((prev) => Math.max(0, prev - 1));
      setTotalAll((prev) => Math.max(0, prev - 1));
      setPendingDelete(null);
      setDeleteReason('');
      setReasonError('');
      showToast(`#KalimatHariIni milik ${target.displayName} di-takedown dan notifikasi dikirim ke penulis.`);
    } catch (caught: unknown) {
      if (caught instanceof ApiError && caught.fields?.reason) {
        setReasonError(caught.fields.reason);
        reasonInputRef.current?.focus();
      } else {
        showToast(
          caught instanceof ApiError ? caught.message : '#KalimatHariIni tidak dapat di-takedown.',
          'danger',
        );
      }
    } finally {
      setDeleting(false);
    }
  };

  const normalizedReason = normalizeRemovalReason(deleteReason);
  const reasonLength = countCodePoints(normalizedReason);

  return (
    <div className="penalis-stack">
      <PageHeading
        title="Kalimat Hari Ini"
        description="Pantau #KalimatHariIni yang sedang tayang dalam 24 jam terakhir dan takedown kalimat yang melanggar norma komunitas."
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
              Refresh
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
                      onClick={() => openDeleteDialog(item)}
                    >
                      <Icon name="trash" />
                      <span>Takedown</span>
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
        <Dialog label="Takedown #KalimatHariIni ini?" onClose={closeDeleteDialog}>
          <div className="penalis-modal__content penalis-modal__content--confirm penalis-quotes-mod__confirm-dialog">
            <div className="penalis-modal__header">
              <h3>Takedown #KalimatHariIni ini?</h3>
            </div>

            <div className="penalis-confirm__body">
              <p>
                Kalimat milik <strong>{pendingDelete.displayName}</strong> (@{pendingDelete.username}) akan
                langsung di-takedown dari halaman profil penulis dan halaman publik. Penulis akan menerima
                notifikasi di dashboard beserta alasan takedown di bawah ini.
              </p>
              <div className="penalis-quotes-mod__confirm-preview">{pendingDelete.text}</div>

              <div className="penalis-quotes-mod__reason-field">
                <div className="penalis-quotes-mod__reason-head">
                  <label htmlFor="penalis-quotes-mod-reason" className="penalis-quotes-mod__reason-label">
                    Alasan takedown <span className="penalis-quotes-mod__required" aria-hidden="true">*</span>
                  </label>
                  <span
                    className={`penalis-quotes-mod__reason-count ${
                      reasonLength > reasonMaxLength ? 'is-over' : ''
                    }`}
                  >
                    {reasonLength}/{reasonMaxLength}
                  </span>
                </div>

                <div className="penalis-quotes-mod__presets" role="group" aria-label="Pilihan alasan cepat">
                  {removalPresets.map((preset) => {
                    const isActive = normalizedReason === preset;
                    return (
                      <button
                        key={preset}
                        type="button"
                        className={`penalis-quotes-mod__preset ${isActive ? 'is-active' : ''}`}
                        aria-pressed={isActive}
                        disabled={deleting}
                        onClick={() => {
                          setDeleteReason(preset);
                          setReasonError('');
                          reasonInputRef.current?.focus();
                        }}
                      >
                        {preset}
                      </button>
                    );
                  })}
                </div>

                <textarea
                  ref={reasonInputRef}
                  id="penalis-quotes-mod-reason"
                  name="quotes_removal_reason"
                  className={`penalis-quotes-mod__reason-input ${reasonError ? 'is-invalid' : ''}`}
                  rows={3}
                  maxLength={reasonMaxLength}
                  placeholder="Pilih alasan cepat di atas atau tulis alasan spesifik untuk penulis…"
                  value={deleteReason}
                  disabled={deleting}
                  required
                  aria-invalid={reasonError ? 'true' : undefined}
                  aria-describedby={
                    reasonError
                      ? 'penalis-quotes-mod-reason-hint penalis-quotes-mod-reason-error'
                      : 'penalis-quotes-mod-reason-hint'
                  }
                  onChange={(event) => {
                    const nextValue = event.target.value;
                    setDeleteReason(nextValue);
                    if (reasonError && validateRemovalReason(nextValue, reasonMaxLength) === '') {
                      setReasonError('');
                    }
                  }}
                />
                <p id="penalis-quotes-mod-reason-hint" className="penalis-quotes-mod__reason-hint">
                  Alasan ini akan tampil pada notifikasi di dashboard penulis.
                </p>
                {reasonError ? (
                  <p id="penalis-quotes-mod-reason-error" className="penalis-quotes-mod__reason-error" role="alert">
                    {reasonError}
                  </p>
                ) : null}
              </div>
            </div>

            <div className="penalis-confirm__actions">
              <button
                type="button"
                className="penalis-button penalis-button--ghost"
                onClick={closeDeleteDialog}
                disabled={deleting}
              >
                Batal
              </button>
              <button
                type="button"
                className="penalis-button penalis-button--danger-solid"
                onClick={() => void handleConfirmDelete()}
                disabled={deleting}
              >
                {deleting ? 'Memproses…' : 'Ya, takedown'}
              </button>
            </div>
          </div>
        </Dialog>
      ) : null}
    </div>
  );
}
