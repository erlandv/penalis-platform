import { describe, expect, it } from 'vitest';

import { DEEPLINK, isTokenShaped } from './deeplink';

/**
 * A real token is 32 random bytes encoded as URL-safe base64 without padding,
 * which is 43 characters. The server accepts 40-64.
 */
const REAL_TOKEN = 'A'.repeat(43);

describe('isTokenShaped', () => {
  it('accepts a real-length token', () => {
    expect(isTokenShaped(REAL_TOKEN)).toBe(true);
  });

  it('accepts the shortest and longest allowed lengths', () => {
    expect(isTokenShaped('a'.repeat(40))).toBe(true);
    expect(isTokenShaped('a'.repeat(64))).toBe(true);
  });

  it('accepts URL-safe base64 characters', () => {
    expect(isTokenShaped(`${'a'.repeat(41)}-_`)).toBe(true);
  });

  it('rejects the flag value used by deep links', () => {
    // This is the regression that made ?penalis_activate=1 render the
    // confirmation page instead of the request form.
    expect(isTokenShaped('1')).toBe(false);
  });

  it('rejects other short values', () => {
    expect(isTokenShaped('')).toBe(false);
    expect(isTokenShaped('true')).toBe(false);
    expect(isTokenShaped('a'.repeat(39))).toBe(false);
  });

  it('rejects overlong values', () => {
    expect(isTokenShaped('a'.repeat(65))).toBe(false);
  });

  it('rejects characters outside the URL-safe alphabet', () => {
    expect(isTokenShaped(`${'a'.repeat(42)}+`)).toBe(false);
    expect(isTokenShaped(`${'a'.repeat(42)}/`)).toBe(false);
    expect(isTokenShaped(`${'a'.repeat(42)}=`)).toBe(false);
    expect(isTokenShaped(`${'a'.repeat(42)} `)).toBe(false);
  });

  it('rejects null and undefined', () => {
    expect(isTokenShaped(null)).toBe(false);
    expect(isTokenShaped(undefined)).toBe(false);
  });

  it('rejects a WordPress password reset key', () => {
    // Regression: the reset key comes from get_password_reset_key(), which
    // uses wp_generate_password( 20, false ). Applying this check to it made
    // every reset link fall through to the login form.
    const coreKey = 'Ab3dE6gH9jK2mN5pQ8sT';
    expect(coreKey).toHaveLength(20);
    expect(isTokenShaped(coreKey)).toBe(false);
  });
});

describe('DEEPLINK', () => {
  it('keeps the email-change token separate from the verification token', () => {
    // A registration token advances the account status machine; an email
    // change must never do that, so the two parameters cannot be shared.
    expect(DEEPLINK.emailChange).not.toBe(DEEPLINK.verify);
    expect(DEEPLINK.emailChange).toBe('penalis_email_change');
  });
});
