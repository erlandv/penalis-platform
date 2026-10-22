import { describe, expect, it } from 'vitest';

import { hasErrors, validateArticle, validateRegistration } from './validation';

const validRegistration = {
  display_name: 'Ada Lovelace',
  user_login: 'ada.lovelace',
  user_email: 'ada@example.com',
  password: 'correct horse battery',
  confirm_password: 'correct horse battery',
  penalis_consent: true,
};

describe('validateRegistration', () => {
  it('accepts a valid registration', () => {
    expect(hasErrors(validateRegistration(validRegistration))).toBe(false);
  });

  it('rejects short display names', () => {
    const errors = validateRegistration({ ...validRegistration, display_name: 'A' });
    expect(errors.display_name).toBeDefined();
  });

  it('rejects invalid usernames', () => {
    const errors = validateRegistration({ ...validRegistration, user_login: 'a b' });
    expect(errors.user_login).toBeDefined();
  });

  it('rejects invalid email addresses', () => {
    const errors = validateRegistration({ ...validRegistration, user_email: 'not-an-email' });
    expect(errors.user_email).toBeDefined();
  });

  it('rejects short passwords', () => {
    const errors = validateRegistration({ ...validRegistration, password: 'short', confirm_password: 'short' });
    expect(errors.password).toBeDefined();
  });

  it('rejects mismatched passwords', () => {
    const errors = validateRegistration({ ...validRegistration, confirm_password: 'different password' });
    expect(errors.confirm_password).toBeDefined();
  });

  it('requires consent', () => {
    const errors = validateRegistration({ ...validRegistration, penalis_consent: false });
    expect(errors.penalis_consent).toBeDefined();
  });
});

describe('validateArticle', () => {
  it('accepts a valid article', () => {
    expect(hasErrors(validateArticle({ title: 'A title', content: 'Enough content here.' }))).toBe(false);
  });

  it('rejects an empty title', () => {
    expect(validateArticle({ title: '', content: 'Enough content here.' }).title).toBeDefined();
  });

  it('rejects content that is too short', () => {
    expect(validateArticle({ title: 'A title', content: 'short' }).content).toBeDefined();
  });
});
