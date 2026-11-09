import { describe, expect, it } from 'vitest';

import { formatCountdown, isOtpCodeComplete, normalizeOtpCode, secondsUntil } from './otp';

describe('normalizeOtpCode', () => {
  it('keeps digits and drops separators', () => {
    expect(normalizeOtpCode('123 456')).toBe('123456');
    expect(normalizeOtpCode('123-456')).toBe('123456');
    expect(normalizeOtpCode('\u00a0123456\u00a0')).toBe('123456');
  });

  it('caps the value at six digits', () => {
    expect(normalizeOtpCode('123456789')).toBe('123456');
  });

  it('preserves leading zeros', () => {
    expect(normalizeOtpCode('000123')).toBe('000123');
  });

  it('returns an empty string when there are no digits', () => {
    expect(normalizeOtpCode('abc')).toBe('');
  });
});

describe('isOtpCodeComplete', () => {
  it('accepts exactly six digits', () => {
    expect(isOtpCodeComplete('000000')).toBe(true);
    expect(isOtpCodeComplete('987654')).toBe(true);
  });

  it('rejects short, long, and non-numeric values', () => {
    expect(isOtpCodeComplete('12345')).toBe(false);
    expect(isOtpCodeComplete('1234567')).toBe(false);
    expect(isOtpCodeComplete('12345a')).toBe(false);
    expect(isOtpCodeComplete('')).toBe(false);
  });
});

describe('secondsUntil and formatCountdown', () => {
  it('never returns a negative countdown', () => {
    expect(secondsUntil(1_000, 5_000)).toBe(0);
  });

  it('rounds up partial seconds', () => {
    expect(secondsUntil(2_500, 1_000)).toBe(2);
  });

  it('formats minutes and seconds', () => {
    expect(formatCountdown(60)).toBe('1:00');
    expect(formatCountdown(9)).toBe('0:09');
    expect(formatCountdown(0)).toBe('0:00');
  });
});
