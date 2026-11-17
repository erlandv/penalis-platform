import { describe, expect, it } from 'vitest';

import { shouldShowAuthBackNav } from './authNav';

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
