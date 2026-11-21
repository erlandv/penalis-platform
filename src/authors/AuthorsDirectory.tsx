import React, { useCallback, useEffect, useRef, useState } from 'react';
import { SenatorBadgeIcon, VerifiedBadgeIcon } from '../components/icons';
import { formatNumber } from '../lib/format';
import {
  buildAuthorsApiUrl,
  buildAuthorsSearchString,
  normalizeAuthorsSort,
  parseAuthorsQuery,
  parsePositiveInt,
  type AuthorsFilterState,
} from './query';
import type { AuthorsSortMode, PublicAuthorItem, PublicAuthorsResponse } from './types';

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

export function AuthorsDirectory(): React.ReactElement {
  const boot = window.penalisAuthors;
  const restUrl = boot?.restUrl ?? '/wp-json/penalis/v1';
  const perPage = parsePositiveInt(boot?.perPage, 100);
  const currentUserId = parsePositiveInt(boot?.currentUserId, 0);

  const initialQuery = parseAuthorsQuery(window.location.search);

  const [searchInput, setSearchInput] = useState<string>(initialQuery.search);
  const [filter, setFilter] = useState<AuthorsFilterState>(initialQuery);
  const [items, setItems] = useState<PublicAuthorItem[]>([]);
  const [total, setTotal] = useState<number>(0);
  const [totalAll, setTotalAll] = useState<number>(0);
  const [totalVerified, setTotalVerified] = useState<number>(0);
  const [totalSenator, setTotalSenator] = useState<number>(0);
  const [page, setPage] = useState<number>(1);
  const [hasMore, setHasMore] = useState<boolean>(false);
  const [loading, setLoading] = useState<boolean>(true);
  const [loadingMore, setLoadingMore] = useState<boolean>(false);
  const [error, setError] = useState<string>('');

  const abortRef = useRef<AbortController | null>(null);

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
    const nextSearch = buildAuthorsSearchString(filter);
    const nextUrl = `${window.location.pathname}${nextSearch}${window.location.hash}`;
    if (nextUrl !== `${window.location.pathname}${window.location.search}${window.location.hash}`) {
      window.history.replaceState(null, '', nextUrl);
    }
  }, [filter]);

  const fetchAuthors = useCallback(
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
        const url = buildAuthorsApiUrl(restUrl, filter, targetPage, perPage);
        const response = await fetch(url, {
          method: 'GET',
          headers: { Accept: 'application/json' },
          signal: controller.signal,
        });

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }

        const data = (await response.json()) as PublicAuthorsResponse;
        setItems((prev) => (append ? [...prev, ...data.items] : data.items));
        setTotal(data.total);
        setTotalAll(data.totalAll);
        setTotalVerified(data.totalVerified);
        setTotalSenator(data.totalSenator ?? 0);
        setPage(data.page);
        setHasMore(data.hasMore);
      } catch (err) {
        if (err instanceof DOMException && err.name === 'AbortError') {
          return;
        }
        setError('Gagal memuat daftar kontributor. Silakan coba lagi.');
      } finally {
        if (!controller.signal.aborted) {
          setLoading(false);
          setLoadingMore(false);
        }
      }
    },
    [filter, perPage, restUrl],
  );

  useEffect(() => {
    void fetchAuthors(1, false);
    return () => {
      abortRef.current?.abort();
    };
  }, [fetchAuthors]);

  const handleReset = (): void => {
    setSearchInput('');
    setFilter({
      search: '',
      sort: 'most_posts',
      filter: 'all',
    });
  };

  const handleSortChange = (event: React.ChangeEvent<HTMLSelectElement>): void => {
    const nextSort: AuthorsSortMode = normalizeAuthorsSort(event.target.value);
    setFilter((prev) => ({ ...prev, sort: nextSort }));
  };

  const remaining = Math.max(0, total - items.length);
  const hasActiveFilters = filter.search !== '' || filter.filter !== 'all' || filter.sort !== 'most_posts';

  return (
    <div className="penalis-authors-container">
      <header className="penalis-authors-header">
        <h1 className="penalis-authors-title">Kontributor Penalis</h1>
        <p className="penalis-authors-subtitle">
          Mereka yang karya tulisnya telah publish di Penalis akan ditampilkan di halaman ini.<br />
          Klik nama kontributor untuk melihat profil dan kontribusi karya tulis mereka di Penalis.
        </p>
        <ul className="penalis-authors-legend" aria-label="Keterangan lencana kontributor">
          <li className="penalis-authors-legend-item">
            <span className="penalis-authors-badge" aria-hidden="true">
              <VerifiedBadgeIcon size={15} />
            </span>
            <span>Penulis Aktif terverifikasi</span>
          </li>
          <li className="penalis-authors-legend-item">
            <span className="penalis-authors-badge penalis-authors-badge--senator" aria-hidden="true">
              <SenatorBadgeIcon size={15} />
            </span>
            <span>Senator Penalis</span>
          </li>
        </ul>
      </header>

      <div className="penalis-authors-toolbar">
        <div className="penalis-authors-search-wrap">
          <span className="penalis-authors-search-icon">
            <SearchIcon />
          </span>
          <input
            id="penalis-authors-search"
            type="search"
            className="penalis-authors-search-input"
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            placeholder="Cari nama kontributor..."
            aria-label="Cari nama kontributor"
          />
          {searchInput !== '' && (
            <button
              type="button"
              className="penalis-authors-search-clear"
              onClick={() => setSearchInput('')}
              aria-label="Hapus pencarian"
            >
              <CloseIcon />
            </button>
          )}
        </div>

        <div className="penalis-authors-filters">
          <div className="penalis-authors-chips" role="group" aria-label="Filter status kontributor">
            <button
              type="button"
              className={`penalis-authors-chip${filter.filter === 'all' ? ' is-active' : ''}`}
              aria-pressed={filter.filter === 'all'}
              onClick={() => setFilter((prev) => ({ ...prev, filter: 'all' }))}
            >
              Semua ({totalAll})
            </button>
            <button
              type="button"
              className={`penalis-authors-chip${filter.filter === 'verified' ? ' is-active' : ''}`}
              aria-pressed={filter.filter === 'verified'}
              onClick={() => setFilter((prev) => ({ ...prev, filter: 'verified' }))}
            >
              Verified ({totalVerified})
            </button>
            <button
              type="button"
              className={`penalis-authors-chip${filter.filter === 'senator' ? ' is-active' : ''}`}
              aria-pressed={filter.filter === 'senator'}
              onClick={() => setFilter((prev) => ({ ...prev, filter: 'senator' }))}
            >
              Senator ({totalSenator})
            </button>
          </div>

          <div className="penalis-authors-sort-wrap">
            <label htmlFor="penalis-authors-sort" className="penalis-authors-sort-label">
              Urutkan:
            </label>
            <select
              id="penalis-authors-sort"
              className="penalis-authors-sort"
              value={filter.sort}
              onChange={handleSortChange}
            >
              <option value="most_posts">Karya terbanyak</option>
              <option value="least_posts">Karya paling sedikit</option>
              <option value="most_views">Views terbanyak</option>
              <option value="least_views">Views paling sedikit</option>
              <option value="name_asc">Abjad A-Z</option>
              <option value="name_desc">Abjad Z-A</option>
              <option value="random">Penulis paling cakep 🗿</option>
            </select>
          </div>
        </div>
      </div>

      <div className="penalis-authors-meta" aria-live="polite">
        {!loading && !error && (
          <span>
            Menampilkan {items.length} dari {total} kontributor
          </span>
        )}
      </div>

      {loading ? (
        <div className="penalis-authors-grid" aria-busy="true" aria-label="Memuat daftar kontributor">
          {Array.from({ length: 9 }).map((_, idx) => (
            <div key={idx} className="penalis-authors-card-skeleton">
              <div className="penalis-authors-skel-circle" />
              <div className="penalis-authors-skel-lines">
                <div className="penalis-authors-skel-line" />
                <div className="penalis-authors-skel-line is-short" />
              </div>
            </div>
          ))}
        </div>
      ) : error !== '' ? (
        <div className="penalis-authors-state" role="alert">
          <p>{error}</p>
          <button
            type="button"
            className="penalis-authors-btn"
            onClick={() => void fetchAuthors(1, false)}
          >
            Coba lagi
          </button>
        </div>
      ) : items.length === 0 ? (
        <div className="penalis-authors-state">
          <p>
            {hasActiveFilters
              ? 'Tidak ada kontributor yang cocok dengan pencarian atau filter saat ini.'
              : 'Belum ada kontributor yang memiliki karya tulis terbit.'}
          </p>
          {hasActiveFilters && (
            <button type="button" className="penalis-authors-btn" onClick={handleReset}>
              Reset filter
            </button>
          )}
        </div>
      ) : (
        <>
          <div className="penalis-authors-grid">
            {items.map((author) => {
              const isCurrentUser = currentUserId > 0 && author.id === currentUserId;
              return (
                <a
                  key={author.id}
                  href={author.authorUrl}
                  className={isCurrentUser ? 'penalis-authors-card penalis-authors-card--self' : 'penalis-authors-card'}
                >
                  <img
                    src={author.avatarUrl}
                    alt={author.displayName}
                    className="penalis-authors-avatar"
                    width={56}
                    height={56}
                    loading="lazy"
                  />
                  <div className="penalis-authors-info">
                    <div className="penalis-authors-name-row">
                      <span className="penalis-authors-name">{author.displayName}</span>
                      {(author.isVerified || author.isSenator) && (
                        <span className="penalis-authors-badges">
                          {author.isVerified && (
                            <span
                              className="penalis-authors-badge"
                              title="Penulis Aktif terverifikasi"
                              aria-label="Penulis Aktif terverifikasi"
                            >
                              <VerifiedBadgeIcon />
                            </span>
                          )}
                          {author.isSenator && (
                            <span
                              className="penalis-authors-badge penalis-authors-badge--senator"
                              title="Senator Penalis"
                              aria-label="Senator Penalis"
                            >
                              <SenatorBadgeIcon />
                            </span>
                          )}
                        </span>
                      )}
                    </div>
                    <div className="penalis-authors-count">
                      {formatNumber(author.postCount)} karya · {formatNumber(author.views)} views
                    </div>
                  </div>
                  {isCurrentUser && (
                    <span className="penalis-authors-self-tag">You</span>
                  )}
                </a>
              );
            })}
          </div>

          {hasMore && (
            <div className="penalis-authors-loadmore-wrap">
              <button
                type="button"
                className="penalis-authors-btn"
                disabled={loadingMore}
                onClick={() => void fetchAuthors(page + 1, true)}
              >
                {loadingMore ? 'Memuat...' : `Muat lebih banyak (${remaining} lagi)`}
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}

