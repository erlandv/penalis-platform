import { describe, expect, it } from 'vitest';

import {
  applyOtpInput,
  applyOtpPaste,
  createEmptyOtpDigits,
  formatCountdown,
  isOtpCodeComplete,
  normalizeOtpCode,
  secondsUntil,
} from './otp';

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

describe('createEmptyOtpDigits', () => {
  it('creates six empty slots', () => {
    expect(createEmptyOtpDigits()).toEqual(['', '', '', '', '', '']);
  });
});

describe('applyOtpInput', () => {
  it('fills a single digit and advances focus to the next box', () => {
    const result = applyOtpInput(['', '', '', '', '', ''], 0, '4');
    expect(result.digits).toEqual(['4', '', '', '', '', '']);
    expect(result.nextFocusIndex).toBe(1);
  });

  it('replaces an existing digit when another digit is typed', () => {
    const result = applyOtpInput(['1', '2', '', '', '', ''], 0, '19');
    expect(result.digits).toEqual(['9', '2', '', '', '', '']);
    expect(result.nextFocusIndex).toBe(1);
  });

  it('ignores non-digit characters without advancing focus', () => {
    const result = applyOtpInput(['1', '', '', '', '', ''], 0, '1a');
    expect(result.digits).toEqual(['1', '', '', '', '', '']);
    expect(result.nextFocusIndex).toBe(0);
  });

  it('distributes a full 6-digit autofill across all boxes', () => {
    const result = applyOtpInput(['', '', '', '', '', ''], 0, '654321');
    expect(result.digits).toEqual(['6', '5', '4', '3', '2', '1']);
    expect(result.nextFocusIndex).toBe(5);
  });

  it('clamps focus to the last box when typing into box 5', () => {
    const result = applyOtpInput(['1', '2', '3', '4', '5', ''], 5, '6');
    expect(result.digits).toEqual(['1', '2', '3', '4', '5', '6']);
    expect(result.nextFocusIndex).toBe(5);
  });
});

describe('applyOtpPaste', () => {
  it('pastes a full 6-digit code from index 0 even when pasted into a middle box', () => {
    const result = applyOtpPaste(['', '', '', '', '', ''], 2, ' 123-456 ');
    expect(result.digits).toEqual(['1', '2', '3', '4', '5', '6']);
    expect(result.nextFocusIndex).toBe(5);
  });

  it('pastes a partial code starting at the focused box', () => {
    const result = applyOtpPaste(['1', '', '', '', '', ''], 1, '234');
    expect(result.digits).toEqual(['1', '2', '3', '4', '', '']);
    expect(result.nextFocusIndex).toBe(4);
  });

  it('ignores non-numeric clipboard content', () => {
    const result = applyOtpPaste(['1', '', '', '', '', ''], 1, 'hello');
    expect(result.digits).toEqual(['1', '', '', '', '', '']);
    expect(result.nextFocusIndex).toBe(1);
  });
});
