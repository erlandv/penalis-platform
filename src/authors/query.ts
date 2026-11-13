import type { AuthorsSortMode } from './types';

export interface AuthorsFilterState {
  search: string;
  sort: AuthorsSortMode;
  verifiedOnly: boolean;
}

const VALID_SORTS: ReadonlyArray<AuthorsSortMode> = [
  'most_posts',
  'least_posts',
  'name_asc',
  'name_desc',
];

export function normalizeAuthorsSort(value: string | null | undefined): AuthorsSortMode {
  if (value && (VALID_SORTS as ReadonlyArray<string>).includes(value)) {
    return value as AuthorsSortMode;
  }
  return 'most_posts';
}

export function parseAuthorsQuery(searchString: string): AuthorsFilterState {
  const params = new URLSearchParams(searchString);
  const rawSearch = params.get('q') ?? '';
  const rawSort = params.get('sort');
  const rawVerified = params.get('verified');

  return {
    search: rawSearch.trim(),
    sort: normalizeAuthorsSort(rawSort),
    verifiedOnly: rawVerified === '1' || rawVerified === 'true',
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
  if (state.verifiedOnly) {
    params.set('verified', '1');
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
  if (state.verifiedOnly) {
    params.set('verified', '1');
  }
  params.set('page', String(Math.max(1, page)));
  params.set('per_page', String(Math.max(1, perPage)));

  return `${base}/authors?${params.toString()}`;
}

