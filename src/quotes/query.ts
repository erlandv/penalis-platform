import { parsePositiveInt } from '../authors/query';
import type { QuotesSortMode } from './types';

export { parsePositiveInt };

export interface QuotesFilterState {
  search: string;
  sort: QuotesSortMode;
}

const VALID_SORTS: ReadonlyArray<QuotesSortMode> = ['newest', 'oldest', 'random'];

export function normalizeQuotesSort(value: string | null | undefined): QuotesSortMode {
  if (value && (VALID_SORTS as ReadonlyArray<string>).includes(value)) {
    return value as QuotesSortMode;
  }
  return 'newest';
}

export function parseBoolFlag(value: boolean | string | number | null | undefined): boolean {
  if (typeof value === 'boolean') {
    return value;
  }
  if (typeof value === 'number') {
    return value === 1;
  }
  if (typeof value === 'string') {
    return value === '1' || value.toLowerCase() === 'true';
  }
  return false;
}

export function parseQuotesQuery(searchString: string): QuotesFilterState {
  const params = new URLSearchParams(searchString);
  const rawSearch = params.get('q') ?? '';
  const rawSort = params.get('sort');

  return {
    search: rawSearch.trim(),
    sort: normalizeQuotesSort(rawSort),
  };
}

export function buildQuotesSearchString(state: QuotesFilterState): string {
  const params = new URLSearchParams();
  const trimmed = state.search.trim();

  if (trimmed !== '') {
    params.set('q', trimmed);
  }
  if (state.sort !== 'newest') {
    params.set('sort', state.sort);
  }

  const serialized = params.toString();
  return serialized === '' ? '' : `?${serialized}`;
}

export function buildQuotesApiUrl(
  restUrl: string,
  state: QuotesFilterState,
  page: number,
  perPage: number,
): string {
  const base = restUrl.replace(/\/+$/, '');
  const params = new URLSearchParams();

  if (state.search.trim() !== '') {
    params.set('search', state.search.trim());
  }
  params.set('sort', state.sort);
  params.set('page', String(Math.max(1, page)));
  params.set('per_page', String(Math.max(1, perPage)));

  return `${base}/quotes?${params.toString()}`;
}
