import { describe, expect, it } from 'vitest';
import {
  buildAuthorsApiUrl,
  buildAuthorsSearchString,
  normalizeAuthorsSort,
  parseAuthorsQuery,
} from './query';

describe('authors query helpers', () => {
  it('normalizes sort modes with safe fallback', () => {
    expect(normalizeAuthorsSort('most_posts')).toBe('most_posts');
    expect(normalizeAuthorsSort('least_posts')).toBe('least_posts');
    expect(normalizeAuthorsSort('most_views')).toBe('most_views');
    expect(normalizeAuthorsSort('least_views')).toBe('least_views');
    expect(normalizeAuthorsSort('name_asc')).toBe('name_asc');
    expect(normalizeAuthorsSort('name_desc')).toBe('name_desc');
    expect(normalizeAuthorsSort('random')).toBe('random');
    expect(normalizeAuthorsSort('unknown')).toBe('most_posts');
    expect(normalizeAuthorsSort(null)).toBe('most_posts');
  });

  it('parses URL query string into filter state', () => {
    expect(parseAuthorsQuery('')).toEqual({
      search: '',
      sort: 'most_posts',
      verifiedOnly: false,
    });

    expect(parseAuthorsQuery('?q=%20Erland%20&sort=name_asc&verified=1')).toEqual({
      search: 'Erland',
      sort: 'name_asc',
      verifiedOnly: true,
    });

    expect(parseAuthorsQuery('?verified=true&sort=bogus')).toEqual({
      search: '',
      sort: 'most_posts',
      verifiedOnly: true,
    });
  });

  it('serializes only non-default filter values to search string', () => {
    expect(
      buildAuthorsSearchString({ search: '', sort: 'most_posts', verifiedOnly: false }),
    ).toBe('');

    expect(
      buildAuthorsSearchString({ search: '  Vitri ', sort: 'name_desc', verifiedOnly: true }),
    ).toBe('?q=Vitri&sort=name_desc&verified=1');
  });

  it('builds REST API URL with pagination and filters', () => {
    expect(
      buildAuthorsApiUrl(
        'https://penalis.com/wp-json/penalis/v1/',
        { search: 'Jaxson', sort: 'most_posts', verifiedOnly: true },
        2,
        100,
      ),
    ).toBe(
      'https://penalis.com/wp-json/penalis/v1/authors?search=Jaxson&sort=most_posts&verified=1&page=2&per_page=100',
    );
  });
});

