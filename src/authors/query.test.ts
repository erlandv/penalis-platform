import { describe, expect, it } from 'vitest';
import {
  buildAuthorsApiUrl,
  buildAuthorsSearchString,
  normalizeAuthorsSort,
  parseAuthorsQuery,
  parsePositiveInt,
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
      filter: 'all',
    });

    expect(parseAuthorsQuery('?q=%20Erland%20&sort=name_asc&verified=1')).toEqual({
      search: 'Erland',
      sort: 'name_asc',
      filter: 'verified',
    });

    expect(parseAuthorsQuery('?verified=true&sort=bogus')).toEqual({
      search: '',
      sort: 'most_posts',
      filter: 'verified',
    });

    expect(parseAuthorsQuery('?senator=1&sort=most_views')).toEqual({
      search: '',
      sort: 'most_views',
      filter: 'senator',
    });

    expect(parseAuthorsQuery('?senator=true')).toEqual({
      search: '',
      sort: 'most_posts',
      filter: 'senator',
    });
  });

  it('serializes only non-default filter values to search string', () => {
    expect(
      buildAuthorsSearchString({ search: '', sort: 'most_posts', filter: 'all' }),
    ).toBe('');

    expect(
      buildAuthorsSearchString({ search: '  Vitri ', sort: 'name_desc', filter: 'verified' }),
    ).toBe('?q=Vitri&sort=name_desc&verified=1');

    expect(
      buildAuthorsSearchString({ search: 'Sakha', sort: 'most_posts', filter: 'senator' }),
    ).toBe('?q=Sakha&senator=1');
  });

  it('builds REST API URL with pagination and filters', () => {
    expect(
      buildAuthorsApiUrl(
        'https://penalis.com/wp-json/penalis/v1/',
        { search: 'Jaxson', sort: 'most_posts', filter: 'verified' },
        2,
        100,
      ),
    ).toBe(
      'https://penalis.com/wp-json/penalis/v1/authors?search=Jaxson&sort=most_posts&verified=1&page=2&per_page=100',
    );

    expect(
      buildAuthorsApiUrl(
        'https://penalis.com/wp-json/penalis/v1/',
        { search: '', sort: 'most_posts', filter: 'senator' },
        1,
        100,
      ),
    ).toBe(
      'https://penalis.com/wp-json/penalis/v1/authors?sort=most_posts&senator=1&page=1&per_page=100',
    );
  });

  it('parses positive integers from both numeric and wp_localize_script string values', () => {
    expect(parsePositiveInt(42)).toBe(42);
    expect(parsePositiveInt('42')).toBe(42);
    expect(parsePositiveInt(' 7 ')).toBe(7);
    expect(parsePositiveInt('0')).toBe(0);
    expect(parsePositiveInt(0)).toBe(0);
    expect(parsePositiveInt(-5)).toBe(0);
    expect(parsePositiveInt(undefined)).toBe(0);
    expect(parsePositiveInt('100', 100)).toBe(100);
    expect(parsePositiveInt('invalid', 100)).toBe(100);
  });
});

