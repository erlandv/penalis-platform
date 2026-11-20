import type { AuthorsSortMode } from './types';

export type AuthorsBadgeFilter = 'all' | 'verified' | 'senator';

export interface AuthorsFilterState {
  search: string;
  sort: AuthorsSortMode;
  filter: AuthorsBadgeFilter;
}

const VALID_SORTS: ReadonlyArray<AuthorsSortMode> = [
  'most_posts',
  'least_posts',
  'most_views',
  'least_views',
  'name_asc',
  'name_desc',
  'random',
];

export function normalizeAuthorsSort(value: string | null | undefined): AuthorsSortMode {
  if (value && (VALID_SORTS as ReadonlyArray<string>).includes(value)) {
    return value as AuthorsSortMode;
  }
  return 'most_posts';
}

/**
 * Parse a positive integer from wp_localize_script() config, which serializes
 * top-level scalars as strings in WordPress core.
 */
export function parsePositiveInt(
  value: number | string | null | undefined,
  fallback = 0,
): number {
  if (typeof value === 'number') {
    return Number.isInteger(value) && value > 0 ? value : fallback;
  }
  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (/^\d+$/.test(trimmed)) {
      const parsed = Number(trimmed);
      return Number.isSafeInteger(parsed) && parsed > 0 ? parsed : fallback;
    }
  }
  return fallback;
}

export function parseAuthorsQuery(searchString: string): AuthorsFilterState {
  const params = new URLSearchParams(searchString);
  const rawSearch = params.get('q') ?? '';
  const rawSort = params.get('sort');
  const rawVerified = params.get('verified');
  const rawSenator = params.get('senator');

  let filter: AuthorsBadgeFilter = 'all';
  if (rawVerified === '1' || rawVerified === 'true') {
    filter = 'verified';
  } else if (rawSenator === '1' || rawSenator === 'true') {
    filter = 'senator';
  }

  return {
    search: rawSearch.trim(),
    sort: normalizeAuthorsSort(rawSort),
    filter,
  };
}

export function buildAuthorsSearchString(state: AuthorsFilterState): string {
  const params = new URLSearchParams();
  const trimmed = state.search.trim();

  if (trimmed !== '') {
    params.set('q', trimmed);
  }
  if (state.sort !== 'most_posts') {
    params.set('sort', state.sort);
  }
  if (state.filter === 'verified') {
    params.set('verified', '1');
  } else if (state.filter === 'senator') {
    params.set('senator', '1');
  }

  const serialized = params.toString();
  return serialized === '' ? '' : `?${serialized}`;
}

export function buildAuthorsApiUrl(
  restUrl: string,
  state: AuthorsFilterState,
  page: number,
  perPage: number,
): string {
  const base = restUrl.replace(/\/+$/, '');
  const params = new URLSearchParams();

  if (state.search.trim() !== '') {
    params.set('search', state.search.trim());
  }
  params.set('sort', state.sort);
  if (state.filter === 'verified') {
    params.set('verified', '1');
  } else if (state.filter === 'senator') {
    params.set('senator', '1');
  }
  params.set('page', String(Math.max(1, page)));
  params.set('per_page', String(Math.max(1, perPage)));

  return `${base}/authors?${params.toString()}`;
}

