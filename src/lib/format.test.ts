import { describe, expect, it } from 'vitest';

import {
  decodeEntities,
  editorialStateLabel,
  formatDate,
  formatDateTime,
  formatNumber,
  formatTime,
  parseDateValue,
  statusLabel,
  statusTone,
} from './format';

describe('parseDateValue', () => {
  it('parses offset-less WordPress GMT strings as UTC', () => {
    expect(parseDateValue('2025-11-12 20:30:00')?.toISOString()).toBe('2025-11-12T20:30:00.000Z');
    expect(parseDateValue('2025-11-12T20:30:00')?.toISOString()).toBe('2025-11-12T20:30:00.000Z');
  });

  it('preserves explicit UTC and numeric offsets', () => {
    expect(parseDateValue('2025-11-12T20:30:00Z')?.toISOString()).toBe('2025-11-12T20:30:00.000Z');
    expect(parseDateValue('2025-11-12T20:30:00+07:00')?.toISOString()).toBe('2025-11-12T13:30:00.000Z');
    expect(parseDateValue('2025-11-12T20:30:00+0700')?.toISOString()).toBe('2025-11-12T13:30:00.000Z');
  });

  it('accepts epoch seconds and milliseconds', () => {
    expect(parseDateValue(1762979400)?.toISOString()).toBe('2025-11-12T20:30:00.000Z');
    expect(parseDateValue(1762979400000)?.toISOString()).toBe('2025-11-12T20:30:00.000Z');
  });

  it('rejects empty and invalid values', () => {
    expect(parseDateValue('')).toBeNull();
    expect(parseDateValue('   ')).toBeNull();
    expect(parseDateValue('not-a-date')).toBeNull();
    expect(parseDateValue(0)).toBeNull();
    expect(parseDateValue(Number.NaN)).toBeNull();
    expect(parseDateValue(new Date('invalid'))).toBeNull();
  });
});

describe('formatDate', () => {
  it('formats in the browser timezone by default', () => {
    // No `timeZone` option means the reader's own timezone, which is exactly
    // what Intl resolves without an override. This asserts the helper never
    // silently switches to the site timezone.
    const value = '2025-11-12T20:30:00Z';
    const expected = new Intl.DateTimeFormat('id-ID', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    }).format(new Date(value));

    expect(formatDate(value)).toBe(expected);
    expect(formatDate(value)).toMatch(/^\d{1,2} \w{3} \d{4}$/);
  });

  it('formats Jakarta dates with the Indonesian locale', () => {
    expect(formatDate('2025-11-12T20:30:00Z', { timeZone: 'Asia/Jakarta' })).toBe('13 Nov 2025');
    expect(formatDate('2025-11-12T20:30:00Z', { timeZone: 'UTC' })).toBe('12 Nov 2025');
  });

  it('rolls over into the next year in Jakarta', () => {
    expect(formatDate('2025-12-31T20:00:00Z', { timeZone: 'Asia/Jakarta' })).toBe('1 Jan 2026');
    expect(formatDate('2025-12-31T20:00:00Z', { timeZone: 'UTC' })).toBe('31 Des 2025');
  });

  it('returns an em dash for empty values', () => {
    expect(formatDate('')).toBe('—');
    expect(formatDate(0)).toBe('—');
  });
});

describe('formatDateTime', () => {
  it('includes hours, minutes, and a short timezone label', () => {
    expect(formatDateTime('2025-11-12T20:30:00Z', { timeZone: 'Asia/Jakarta' })).toBe('13 Nov 2025, 03.30 WIB');
    expect(formatDateTime('2025-11-12T20:30:00Z', { timeZone: 'UTC' })).toBe('12 Nov 2025, 20.30 UTC');
  });

  it('handles the Jakarta year rollover', () => {
    expect(formatDateTime('2025-12-31T20:00:00Z', { timeZone: 'Asia/Jakarta' })).toBe('1 Jan 2026, 03.00 WIB');
  });

  it('formats epoch seconds from notifications and account metadata', () => {
    expect(formatDateTime(1762979400, { timeZone: 'Asia/Jakarta' })).toBe('13 Nov 2025, 03.30 WIB');
  });

  it('respects explicit offsets instead of reinterpreting them as UTC', () => {
    expect(formatDateTime('2025-11-12T20:30:00+07:00', { timeZone: 'Asia/Jakarta' })).toBe(
      '12 Nov 2025, 20.30 WIB',
    );
  });

  it('returns an em dash for empty values', () => {
    expect(formatDateTime('')).toBe('—');
  });
});

describe('formatTime', () => {
  it('shows only the clock time with a timezone label', () => {
    expect(formatTime('2025-11-12T20:30:00Z', { timeZone: 'Asia/Jakarta' })).toBe('03.30 WIB');
    expect(formatTime('2025-11-12T20:30:00Z', { timeZone: 'UTC' })).toBe('20.30 UTC');
  });

  it('accepts Date objects from the editor save indicator', () => {
    expect(formatTime(new Date('2025-11-12T20:30:00Z'), { timeZone: 'Asia/Jakarta' })).toBe('03.30 WIB');
  });

  it('returns an em dash for empty values', () => {
    expect(formatTime('')).toBe('—');
  });
});

describe('decodeEntities', () => {
  it('decodes named entities', () => {
    expect(decodeEntities('Budaya &amp; Agama')).toBe('Budaya & Agama');
    expect(decodeEntities('A &lt; B &gt; C')).toBe('A < B > C');
    expect(decodeEntities('&quot;quoted&quot;')).toBe('"quoted"');
    expect(decodeEntities("R&amp;D&apos;s")).toBe("R&D's");
  });

  it('decodes decimal and hexadecimal entities', () => {
    expect(decodeEntities('don&#8217;t')).toBe('don\u2019t');
    expect(decodeEntities('caf&#xE9;')).toBe('caf\u00e9');
  });

  it('leaves plain text and bare ampersands alone', () => {
    expect(decodeEntities('Budaya & Agama')).toBe('Budaya & Agama');
    expect(decodeEntities('Penalis')).toBe('Penalis');
  });

  it('leaves unknown entities and invalid code points untouched', () => {
    expect(decodeEntities('&unknown;')).toBe('&unknown;');
    expect(decodeEntities('&#0;')).toBe('&#0;');
    expect(decodeEntities('&#xD800;')).toBe('&#xD800;');
  });

  it('decodes a single level only', () => {
    expect(decodeEntities('&amp;amp;')).toBe('&amp;');
  });
});

describe('editorialStateLabel and statusTone', () => {
  it('returns Indonesian labels for every V2 editorial state', () => {
    expect(editorialStateLabel('draft')).toBe('Draft');
    expect(editorialStateLabel('pending_review')).toBe('Menunggu review');
    expect(editorialStateLabel('changes_requested')).toBe('Perlu revisi');
    expect(editorialStateLabel('rejected')).toBe('Ditolak');
    expect(editorialStateLabel('published')).toBe('Terbit');
    expect(editorialStateLabel('unknown')).toBe('Tidak diketahui');
  });

  it('maps editorial and post statuses to badge tones and Indonesian labels', () => {
    expect(statusLabel('published')).toBe('Terbit');
    expect(statusLabel('pending_review')).toBe('Menunggu review');
    expect(statusLabel('changes_requested')).toBe('Perlu revisi');
    expect(statusLabel('draft')).toBe('Draft');
    expect(statusLabel('rejected')).toBe('Ditolak');

    expect(statusTone('published')).toBe('success');
    expect(statusTone('publish')).toBe('success');
    expect(statusTone('approve')).toBe('success');
    expect(statusTone('pending_review')).toBe('warning');
    expect(statusTone('changes_requested')).toBe('warning');
    expect(statusTone('submit')).toBe('warning');
    expect(statusTone('resubmit')).toBe('warning');
    expect(statusTone('request_changes')).toBe('warning');
    expect(statusTone('reopen')).toBe('warning');
    expect(statusTone('rejected')).toBe('danger');
    expect(statusTone('reject')).toBe('danger');
    expect(statusTone('draft')).toBe('neutral');
  });
});

describe('formatNumber', () => {
  it('formats non-negative integers with Indonesian thousands separators', () => {
    expect(formatNumber(0)).toBe('0');
    expect(formatNumber(42)).toBe('42');
    expect(formatNumber(1250)).toBe('1.250');
    expect(formatNumber(1000000)).toBe('1.000.000');
    expect(formatNumber(1250.9)).toBe('1.250');
  });

  it('returns 0 for negative, non-finite, or missing values', () => {
    expect(formatNumber(-5)).toBe('0');
    expect(formatNumber(Number.NaN)).toBe('0');
    expect(formatNumber(Number.POSITIVE_INFINITY)).toBe('0');
    expect(formatNumber(null)).toBe('0');
    expect(formatNumber(undefined)).toBe('0');
  });
});
