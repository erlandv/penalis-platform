import { describe, expect, it } from 'vitest';
import {
  buildQuoteShareText,
  countCodePoints,
  DAILY_QUOTE_MAX_LENGTH,
  DAILY_QUOTE_REASON_MAX_LENGTH,
  DAILY_QUOTE_REMOVAL_PRESETS,
  dailyQuoteLineCount,
  formatQuoteRelativeTime,
  formatQuoteRemainingTime,
  normalizeDailyQuote,
  normalizeRemovalReason,
  validateDailyQuoteInput,
  validateRemovalReason,
} from '../lib/dailyQuote';
import {
  buildQuotesApiUrl,
  buildQuotesSearchString,
  normalizeQuotesSort,
  parseBoolFlag,
  parseQuotesQuery,
} from './query';

describe('quotes query helpers', () => {
  it('normalizes sort modes with safe fallback', () => {
    expect(normalizeQuotesSort('newest')).toBe('newest');
    expect(normalizeQuotesSort('oldest')).toBe('oldest');
    expect(normalizeQuotesSort('random')).toBe('random');
    expect(normalizeQuotesSort('unknown')).toBe('newest');
    expect(normalizeQuotesSort(null)).toBe('newest');
  });

  it('parses URL query string into filter state', () => {
    expect(parseQuotesQuery('')).toEqual({ search: '', sort: 'newest' });
    expect(parseQuotesQuery('?q=%20semangat%20&sort=oldest')).toEqual({
      search: 'semangat',
      sort: 'oldest',
    });
    expect(parseQuotesQuery('?sort=bogus')).toEqual({
      search: '',
      sort: 'newest',
    });
  });

  it('serializes only non-default filter values to search string', () => {
    expect(buildQuotesSearchString({ search: '', sort: 'newest' })).toBe('');
    expect(buildQuotesSearchString({ search: '  senja ', sort: 'random' })).toBe(
      '?q=senja&sort=random',
    );
  });

  it('builds REST API URL with pagination and filters', () => {
    expect(
      buildQuotesApiUrl(
        'https://penalis.com/wp-json/penalis/v1/',
        { search: 'semangat', sort: 'oldest' },
        2,
        24,
      ),
    ).toBe(
      'https://penalis.com/wp-json/penalis/v1/quotes?search=semangat&sort=oldest&page=2&per_page=24',
    );
  });

  it('parses localized boolean flags safely', () => {
    expect(parseBoolFlag(true)).toBe(true);
    expect(parseBoolFlag('1')).toBe(true);
    expect(parseBoolFlag('true')).toBe(true);
    expect(parseBoolFlag(1)).toBe(true);
    expect(parseBoolFlag(false)).toBe(false);
    expect(parseBoolFlag('')).toBe(false);
    expect(parseBoolFlag('0')).toBe(false);
    expect(parseBoolFlag(undefined)).toBe(false);
  });
});

describe('dailyQuote shared helpers', () => {
  it('normalizes whitespace and line breaks consistently with server policy', () => {
    expect(normalizeDailyQuote('  Baris   satu \r\n\r\n Baris\tdua  \n ')).toBe(
      'Baris satu\nBaris dua',
    );
    expect(dailyQuoteLineCount('')).toBe(0);
    expect(dailyQuoteLineCount('Satu\nDua')).toBe(2);
    expect(countCodePoints('Halo ✨')).toBe(6);
  });

  it('validates length and line limits', () => {
    expect(validateDailyQuoteInput('   ')).not.toBe('');
    expect(validateDailyQuoteInput('1\n2\n3\n4')).not.toBe('');
    expect(validateDailyQuoteInput('a'.repeat(DAILY_QUOTE_MAX_LENGTH))).toBe('');
    expect(validateDailyQuoteInput('a'.repeat(DAILY_QUOTE_MAX_LENGTH + 1))).not.toBe('');
  });

  it('formats relative and remaining time labels in Indonesian', () => {
    const now = 1_800_000_000;
    expect(formatQuoteRelativeTime(now - 20, now)).toBe('Baru saja');
    expect(formatQuoteRelativeTime(now - 300, now)).toBe('5 menit lalu');
    expect(formatQuoteRelativeTime(now - 7200, now)).toBe('2 jam lalu');

    expect(formatQuoteRemainingTime(now - 5, now)).toBe('sudah berakhir');
    expect(formatQuoteRemainingTime(now + 30, now)).toBe('berakhir kurang dari 1 menit');
    expect(formatQuoteRemainingTime(now + 900, now)).toBe('berakhir dalam 15 menit');
    expect(formatQuoteRemainingTime(now + 5400, now)).toBe('berakhir dalam 1 jam 30 menit');
    expect(formatQuoteRemainingTime(now + 36000, now)).toBe('berakhir dalam 10 jam');
  });

  it('builds shareable quote text', () => {
    expect(buildQuoteShareText('Kita gak punya apa-apa selain semangat', 'Erland Ramdhani')).toBe(
      '"Kita gak punya apa-apa selain semangat"\n— Erland Ramdhani (#KalimatHariIni)',
    );
  });

  it('normalizes and validates Senator takedown reasons', () => {
    expect(DAILY_QUOTE_REMOVAL_PRESETS.length).toBeGreaterThanOrEqual(3);
    expect(normalizeRemovalReason('  Spam   atau\npromosi  ')).toBe('Spam atau promosi');
    expect(validateRemovalReason('   ')).not.toBe('');
    expect(validateRemovalReason('a'.repeat(DAILY_QUOTE_REASON_MAX_LENGTH))).toBe('');
    expect(validateRemovalReason('a'.repeat(DAILY_QUOTE_REASON_MAX_LENGTH + 1))).not.toBe('');
  });
});
