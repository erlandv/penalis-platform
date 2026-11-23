import React, { useCallback, useEffect, useRef, useState } from 'react';
import { SenatorBadgeIcon, VerifiedBadgeIcon } from '../components/icons';
import {
  buildQuoteShareText,
  copyToClipboard,
  formatQuoteRelativeTime,
  formatQuoteRemainingTime,
} from '../lib/dailyQuote';
import { formatNumber } from '../lib/format';
import {
  buildQuotesApiUrl,
  buildQuotesSearchString,
  normalizeQuotesSort,
  parseBoolFlag,
  parsePositiveInt,
  parseQuotesQuery,
  type QuotesFilterState,
} from './query';
import type { PublicDailyQuoteItem, PublicDailyQuotesResponse, QuotesSortMode } from './types';

function SearchIcon(): React.ReactElement {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="11" cy="11" r="8" />
      <line x1="21" y1="21" x2="16.65" y2="16.65" />
    </svg>
  );
}

function CloseIcon(): React.ReactElement {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <line x1="18" y1="6" x2="6" y2="18" />
      <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
  );
}

function CopyIcon(): React.ReactElement {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <rect x="9" y="9" width="13" height="13" rx="2" ry="2" />
      <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
    </svg>
  );
}

export function QuotesDirectory(): React.ReactElement {
  const boot = window.penalisDailyQuotes;
  const restUrl = boot?.restUrl ?? '/wp-json/penalis/v1';
  const perPage = parsePositiveInt(boot?.perPage, 24);
  const currentUserId = parsePositiveInt(boot?.currentUserId, 0);
  const canWrite = parseBoolFlag(boot?.canWrite);
  const isStaff = parseBoolFlag(boot?.isStaff);
  const dashboardUrl = boot?.dashboardUrl ?? '/dashboard/#kalimat-hari-ini';

  const initialQuery = parseQuotesQuery(window.location.search);

  const [searchInput, setSearchInput] = useState<string>(initialQuery.search);
  const [filter, setFilter] = useState<QuotesFilterState>(initialQuery);
  const [items, setItems] = useState<PublicDailyQuoteItem[]>([]);
  const [total, setTotal] = useState<number>(0);
  const [totalAll, setTotalAll] = useState<number>(0);
  const [page, setPage] = useState<number>(1);
  const [hasMore, setHasMore] = useState<boolean>(false);
  const [loading, setLoading] = useState<boolean>(true);
  const [loadingMore, setLoadingMore] = useState<boolean>(false);
  const [error, setError] = useState<string>('');
  const [copiedAuthorId, setCopiedAuthorId] = useState<number>(0);
  const [statusAnnouncement, setStatusAnnouncement] = useState<string>('');

  const abortRef = useRef<AbortController | null>(null);
  const copyTimerRef = useRef<number | null>(null);

  useEffect(() => {
    return () => {
      if (copyTimerRef.current !== null) {
        window.clearTimeout(copyTimerRef.current);
      }
    };
  }, []);

  // Debounce search input into active filter state.
  useEffect(() => {
    const trimmed = searchInput.trim();
    if (trimmed === filter.search) {
      return;
    }

    const timer = window.setTimeout(() => {
      setFilter((prev) => ({ ...prev, search: trimmed }));
    }, 250);

    return () => {
      window.clearTimeout(timer);
    };
  }, [searchInput, filter.search]);

  // Sync active filter state to URL query parameters.
  useEffect(() => {
    const nextSearch = buildQuotesSearchString(filter);
    const nextUrl = `${window.location.pathname}${nextSearch}${window.location.hash}`;
    if (nextUrl !== `${window.location.pathname}${window.location.search}${window.location.hash}`) {
      window.history.replaceState(null, '', nextUrl);
    }
  }, [filter]);

  const fetchQuotes = useCallback(
    async (targetPage: number, append: boolean) => {
      if (abortRef.current) {
        abortRef.current.abort();
      }
      const controller = new AbortController();
      abortRef.current = controller;

      if (append) {
        setLoadingMore(true);
      } else {
        setLoading(true);
      }
      setError('');

      try {
        const url = buildQuotesApiUrl(restUrl, filter, targetPage, perPage);
        const response = await fetch(url, {
          method: 'GET',
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }

        const data = (await response.json()) as PublicDailyQuotesResponse;
        setItems((prev) => (append ? [...prev, ...data.items] : data.items));
        setTotal(data.total);
        setTotalAll(data.totalAll);
        setPage(data.page);
        setHasMore(data.hasMore);
      } catch (err) {
        if (err instanceof DOMException && err.name === 'AbortError') {
          return;
        }
        setError('Gagal memuat #KalimatHariIni. Silakan coba lagi.');
      } finally {
        if (abortRef.current === controller) {
          setLoading(false);
          setLoadingMore(false);
        }
      }
    },
    [restUrl, filter, perPage],
  );

  useEffect(() => {
    void fetchQuotes(1, false);
    return () => {
      if (abortRef.current) {
        abortRef.current.abort();
      }
    };
  }, [fetchQuotes]);

  const handleSortChange = (event: React.ChangeEvent<HTMLSelectElement>) => {
    const nextSort: QuotesSortMode = normalizeQuotesSort(event.target.value);
    setFilter((prev) => ({ ...prev, sort: nextSort }));
  };

  const handleClearSearch = () => {
    setSearchInput('');
    setFilter((prev) => ({ ...prev, search: '' }));
  };

  const handleResetFilters = () => {
    setSearchInput('');
    setFilter({ search: '', sort: 'newest' });
  };

  const handleLoadMore = () => {
    if (loading || loadingMore || !hasMore) {
      return;
    }
    void fetchQuotes(page + 1, true);
  };

  const handleCopyQuote = async (item: PublicDailyQuoteItem) => {
    const ok = await copyToClipboard(buildQuoteShareText(item.text, item.displayName, item.authorUrl));
    if (!ok) {
      return;
    }

    setCopiedAuthorId(item.authorId);
    setStatusAnnouncement(`Kutipan dari ${item.displayName} disalin.`);
    if (copyTimerRef.current !== null) {
      window.clearTimeout(copyTimerRef.current);
    }
    copyTimerRef.current = window.setTimeout(() => {
      setCopiedAuthorId(0);
    }, 2200);
  };

  const hasActiveSearch = filter.search !== '';
  const showWriterCta = !isStaff && (canWrite || currentUserId === 0);

  return (
    <div className="penalis-quotes-root">
      <div className="penalis-quotes-container">
        <header className="penalis-quotes-header">
          <div className="penalis-quotes-header-main">
            <h1 className="penalis-quotes-title">
              <span className="penalis-quotes-title-hash">#</span>KalimatHariIni
            </h1>
            <p className="penalis-quotes-subtitle">
              Kumpulan kalimat singkat dari para penulis Penalis yang tayang selama 24 jam sebelum
              berganti dengan cerita esok hari.
            </p>
          </div>
          {showWriterCta ? (
            <div className="penalis-quotes-header-cta">
              <a className="penalis-quotes-cta-btn" href={dashboardUrl}>
                {canWrite ? 'Tulis #KalimatHariIni' : 'Masuk untuk Menulis'}
              </a>
            </div>
          ) : null}
        </header>

        <div className="penalis-quotes-toolbar" role="search" aria-label="Cari dan urutkan Kalimat Hari Ini">
          <div className="penalis-quotes-search-wrap">
            <span className="penalis-quotes-search-icon" aria-hidden="true">
              <SearchIcon />
            </span>
            <input
              id="penalis-quotes-search"
              name="q"
              type="search"
              className="penalis-quotes-search-input"
              placeholder="Cari kalimat atau nama penulis…"
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
              aria-label="Cari kalimat atau nama penulis"
            />
            {searchInput !== '' ? (
              <button
                type="button"
                className="penalis-quotes-search-clear"
                onClick={handleClearSearch}
                aria-label="Hapus pencarian"
              >
                <CloseIcon />
              </button>
            ) : null}
          </div>

          <div className="penalis-quotes-controls">
            <label className="penalis-quotes-sort-label" htmlFor="penalis-quotes-sort">
              <span className="penalis-quotes-sort-text">Urutkan</span>
              <select
                id="penalis-quotes-sort"
                name="sort"
                className="penalis-quotes-sort-select"
                value={filter.sort}
                onChange={handleSortChange}
                aria-label="Urutkan #KalimatHariIni"
              >
                <option value="newest">Terbaru</option>
                <option value="oldest">Terlama</option>
                <option value="random">Terkeren (tapi boong)</option> {/* this copy is easter egg */}
              </select>
            </label>
          </div>
        </div>

        <div className="penalis-quotes-summary" aria-live="polite">
          {loading ? (
            <span>Memuat #KalimatHariIni…</span>
          ) : error ? null : hasActiveSearch ? (
            <span>
              Ditemukan <strong>{formatNumber(total)}</strong> dari{' '}
              <strong>{formatNumber(totalAll)}</strong> #KalimatHariIni aktif untuk pencarian{' '}
              &ldquo;{filter.search}&rdquo;
            </span>
          ) : (
            <span>
              <strong>{formatNumber(totalAll)} #KalimatHariIni</strong> aktif dalam 24 jam terakhir
            </span>
          )}
        </div>

        <p className="penalis-quotes-sr-status" role="status" aria-live="polite">
          {statusAnnouncement}
        </p>

        {error ? (
          <div className="penalis-quotes-state" role="alert">
            <p className="penalis-quotes-state-text">{error}</p>
            <button
              type="button"
              className="penalis-quotes-btn"
              onClick={() => void fetchQuotes(1, false)}
            >
              Coba lagi
            </button>
          </div>
        ) : loading ? (
          <div className="penalis-quotes-grid" aria-busy="true" aria-label="Memuat daftar kalimat">
            {Array.from({ length: 6 }).map((_, index) => (
              <div key={index} className="penalis-quote-card penalis-quote-card--skeleton" aria-hidden="true">
                <div className="penalis-quote-skeleton penalis-quote-skeleton--tag" />
                <div className="penalis-quote-skeleton penalis-quote-skeleton--line" />
                <div className="penalis-quote-skeleton penalis-quote-skeleton--line-short" />
                <div className="penalis-quote-skeleton-author">
                  <div className="penalis-quote-skeleton penalis-quote-skeleton--avatar" />
                  <div className="penalis-quote-skeleton penalis-quote-skeleton--name" />
                </div>
              </div>
            ))}
          </div>
        ) : items.length === 0 ? (
          <div className="penalis-quotes-state">
            {hasActiveSearch ? (
              <>
                <p className="penalis-quotes-state-title">Tidak ada kalimat yang cocok</p>
                <p className="penalis-quotes-state-text">
                  Coba kata kunci lain atau hapus pencarian untuk melihat semua #KalimatHariIni yang
                  sedang tayang.
                </p>
                <button type="button" className="penalis-quotes-btn" onClick={handleResetFilters}>
                  Tampilkan semua
                </button>
              </>
            ) : (
              <>
                <p className="penalis-quotes-state-title">Belum ada #KalimatHariIni saat ini</p>
                <p className="penalis-quotes-state-text">
                  Setiap kalimat hanya tayang selama 24 jam. Jadilah penulis pertama yang membagikan
                  satu kalimat hari ini!
                </p>
                {showWriterCta ? (
                  <a className="penalis-quotes-cta-btn" href={dashboardUrl}>
                    {canWrite ? 'Tulis #KalimatHariIni' : 'Masuk untuk Menulis'}
                  </a>
                ) : null}
              </>
            )}
          </div>
        ) : (
          <>
            <div className="penalis-quotes-grid">
              {items.map((item) => {
                const isOwn = currentUserId > 0 && item.authorId === currentUserId;
                const isCopied = copiedAuthorId === item.authorId;

                return (
                  <article
                    key={item.authorId}
                    className={`penalis-quote-card${isOwn ? ' penalis-quote-card--self' : ''}`}
                  >
                    <div className="penalis-quote-card__head">
                      <a className="penalis-quote-card__author" href={item.authorUrl}>
                        <img
                          src={item.avatarUrl}
                          alt={item.displayName}
                          className="penalis-quote-card__avatar"
                          width={40}
                          height={40}
                          loading="lazy"
                        />
                        <span className="penalis-quote-card__identity">
                          <span className="penalis-quote-card__name-row">
                            <span className="penalis-quote-card__name">{item.displayName}</span>
                            {item.isVerified ? (
                              <span
                                className="penalis-quote-card__verified"
                                title="Penulis Aktif terverifikasi"
                                aria-label="Penulis Aktif terverifikasi"
                              >
                                <VerifiedBadgeIcon size={14} />
                              </span>
                            ) : null}
                            {item.isSenator ? (
                              <span
                                className="penalis-quote-card__senator"
                                title="Senator Penalis"
                                aria-label="Senator Penalis"
                              >
                                <SenatorBadgeIcon size={14} />
                              </span>
                            ) : null}
                          </span>
                          <span className="penalis-quote-card__submeta">
                            <span>@{item.username}</span>
                            <span aria-hidden="true">·</span>
                            <span title={formatQuoteRemainingTime(item.expiresAt)}>
                              {formatQuoteRelativeTime(item.createdAt)}
                            </span>
                          </span>
                        </span>
                      </a>
                    </div>

                    <div className="penalis-quote-card__body">
                      <p className="penalis-quote-card__text">{item.text}</p>
                      <span className="penalis-quote-card__hashtag">#KalimatHariIni</span>
                    </div>

                    <div className="penalis-quote-card__foot">
                      <span className="penalis-quote-card__expiry">
                        {formatQuoteRemainingTime(item.expiresAt)}
                      </span>
                      <div className="penalis-quote-card__actions">
                        <button
                          type="button"
                          className={`penalis-quote-card__btn${isCopied ? ' is-copied' : ''}`}
                          onClick={() => void handleCopyQuote(item)}
                          aria-label={`Salin kutipan dari ${item.displayName}`}
                        >
                          <CopyIcon />
                          <span>{isCopied ? 'Disalin!' : 'Salin'}</span>
                        </button>
                      </div>
                    </div>
                  </article>
                );
              })}
            </div>

            {hasMore ? (
              <div className="penalis-quotes-loadmore">
                <button
                  type="button"
                  className="penalis-quotes-btn"
                  onClick={handleLoadMore}
                  disabled={loadingMore}
                >
                  {loadingMore ? 'Memuat…' : 'Muat lebih banyak'}
                </button>
              </div>
            ) : null}
          </>
        )}
      </div>
    </div>
  );
}
