import { describe, expect, it } from 'vitest';

import {
  hasErrors,
  normalizeLinkHref,
  validateArticle,
  validateEmail,
  validateLoginOrEmail,
  validateRegistration,
} from './validation';

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

describe('validateEmail', () => {
  it('accepts a valid address', () => {
    expect(validateEmail('ada@example.com')).toBe('');
  });

  it('accepts an address with surrounding whitespace', () => {
    expect(validateEmail('  ada@example.com  ')).toBe('');
  });

  it('rejects an empty value', () => {
    // Regression: submitting an empty request form used to show the generic
    // "we sent a link" notice, which reads as success.
    expect(validateEmail('')).not.toBe('');
    expect(validateEmail('   ')).not.toBe('');
  });

  it('rejects a malformed address', () => {
    expect(validateEmail('not-an-email')).not.toBe('');
    expect(validateEmail('ada@')).not.toBe('');
    expect(validateEmail('@example.com')).not.toBe('');
    expect(validateEmail('ada@example')).not.toBe('');
  });

  it('rejects an overlong address', () => {
    expect(validateEmail(`${'a'.repeat(250)}@example.com`)).not.toBe('');
  });
});

describe('validateLoginOrEmail', () => {
  it('accepts a bare username', () => {
    expect(validateLoginOrEmail('ada.lovelace')).toBe('');
  });

  it('accepts a valid email address', () => {
    expect(validateLoginOrEmail('ada@example.com')).toBe('');
  });

  it('rejects an empty value', () => {
    expect(validateLoginOrEmail('')).not.toBe('');
    expect(validateLoginOrEmail('   ')).not.toBe('');
  });

  it('rejects a malformed email attempt', () => {
    expect(validateLoginOrEmail('ada@')).not.toBe('');
    expect(validateLoginOrEmail('@example.com')).not.toBe('');
  });

  it('rejects an overlong value', () => {
    expect(validateLoginOrEmail('a'.repeat(255))).not.toBe('');
  });
});

describe('normalizeLinkHref', () => {
  it('keeps absolute http and https links', () => {
    expect(normalizeLinkHref('https://example.com/tulisan?q=1#bagian')).toBe(
      'https://example.com/tulisan?q=1#bagian',
    );
    expect(normalizeLinkHref('http://example.com')).toBe('http://example.com');
    expect(normalizeLinkHref('  https://example.com  ')).toBe('https://example.com');
  });

  it('keeps mailto addresses', () => {
    expect(normalizeLinkHref('mailto:redaksi@penalis.id')).toBe('mailto:redaksi@penalis.id');
    expect(normalizeLinkHref('MAILTO:redaksi@penalis.id')).toBe('MAILTO:redaksi@penalis.id');
  });

  it('keeps root-relative paths and fragments', () => {
    expect(normalizeLinkHref('/tentang-kami')).toBe('/tentang-kami');
    expect(normalizeLinkHref('#catatan-kaki')).toBe('#catatan-kaki');
  });

  it('rejects empty input so the caller can remove the link instead', () => {
    expect(normalizeLinkHref('')).toBeNull();
    expect(normalizeLinkHref('   ')).toBeNull();
  });

  it('rejects script and data URLs', () => {
    expect(normalizeLinkHref('javascript:alert(1)')).toBeNull();
    expect(normalizeLinkHref('JavaScript:alert(1)')).toBeNull();
    expect(normalizeLinkHref('data:text/html,<script>alert(1)</script>')).toBeNull();
    expect(normalizeLinkHref('vbscript:msgbox(1)')).toBeNull();
  });

  it('rejects protocol-relative URLs', () => {
    expect(normalizeLinkHref('//example.com/tulisan')).toBeNull();
  });

  it('rejects values without a scheme the site can serve', () => {
    expect(normalizeLinkHref('example.com')).toBeNull();
    expect(normalizeLinkHref('www.example.com/tulisan')).toBeNull();
    expect(normalizeLinkHref('https://')).toBeNull();
    expect(normalizeLinkHref('ftp://example.com/berkas.zip')).toBeNull();
    expect(normalizeLinkHref('mailto:bukan-email')).toBeNull();
  });

  it('rejects internal whitespace that would break the href', () => {
    expect(normalizeLinkHref('https://example.com/a b')).toBeNull();
  });
});
