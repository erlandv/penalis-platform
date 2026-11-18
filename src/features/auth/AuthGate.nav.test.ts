import { describe, expect, it } from 'vitest';

import { resolveEntryMode, shouldShowAuthBackNav } from './authNav';

describe('shouldShowAuthBackNav', () => {
  it('hides the link on the login screen itself', () => {
    expect(shouldShowAuthBackNav('login', false)).toBe(false);
  });

  it('keeps the link available while a form is in progress', () => {
    expect(shouldShowAuthBackNav('activateVerify', false)).toBe(true);
    expect(shouldShowAuthBackNav('forgot', false)).toBe(true);
    expect(shouldShowAuthBackNav('register', false)).toBe(true);
  });

  it('hides the link once a success notice has its own login CTA', () => {
    expect(shouldShowAuthBackNav('activateVerify', true)).toBe(false);
    expect(shouldShowAuthBackNav('verify', true)).toBe(false);
    expect(shouldShowAuthBackNav('forgot', true)).toBe(false);
  });
});

const NO_FLAGS = { register: false, activate: false, forgot: false };

describe('resolveEntryMode', () => {
  it('opens registration while registration is open', () => {
    expect(resolveEntryMode({ ...NO_FLAGS, register: true }, true)).toBe('register');
  });

  it('falls back to login when the registration link is used on a closed site', () => {
    expect(resolveEntryMode({ ...NO_FLAGS, register: true }, false)).toBe('login');
  });

  it('keeps legacy activation reachable while registration is closed', () => {
    expect(resolveEntryMode({ ...NO_FLAGS, activate: true }, false)).toBe('activate');
  });

  it('keeps the password reset link reachable while registration is closed', () => {
    expect(resolveEntryMode({ ...NO_FLAGS, forgot: true }, false)).toBe('forgot');
  });

  it('defaults to login without a flag', () => {
    expect(resolveEntryMode(NO_FLAGS, true)).toBe('login');
  });
});
