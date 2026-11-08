import { describe, expect, it } from 'vitest';

import {
  hasErrors,
  normalizeInstagram,
  normalizeLinkHref,
  validateArticle,
  validateEmail,
  validateInstagram,
  validateResetEmail,
  validateRegistration,
  validateUsername,
} from './validation';

const validRegistration = {
  display_name: 'Ada Lovelace',
  user_login: 'ada_lovelace',
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

  it('accepts an optional valid Instagram username or empty value', () => {
    expect(hasErrors(validateRegistration({ ...validRegistration, instagram: '' }))).toBe(false);
    expect(hasErrors(validateRegistration({ ...validRegistration, instagram: '@penalis.id' }))).toBe(false);
  });

  it('rejects an invalid Instagram username on registration', () => {
    const errors = validateRegistration({ ...validRegistration, instagram: 'invalid handle!' });
    expect(errors.instagram).toBeDefined();
  });
});

describe('normalizeInstagram and validateInstagram', () => {
  it('normalizes handles and profile URLs', () => {
    expect(normalizeInstagram('  @penalis.id  ')).toBe('penalis.id');
    expect(normalizeInstagram('https://www.instagram.com/penalis_id/?igsh=123')).toBe('penalis_id');
    expect(normalizeInstagram('instagram.com/@penalis.id/')).toBe('penalis.id');
    expect(normalizeInstagram('   ')).toBe('');
  });

  it('accepts valid handles and empty values', () => {
    expect(validateInstagram('')).toBe('');
    expect(validateInstagram('@penalis.id_01')).toBe('');
    expect(validateInstagram('https://instagram.com/penalis.id')).toBe('');
  });

  it('rejects invalid handles', () => {
    expect(validateInstagram('a'.repeat(31))).not.toBe('');
    expect(validateInstagram('...')).not.toBe('');
    expect(validateInstagram('___')).not.toBe('');
    expect(validateInstagram('bad handle')).not.toBe('');
    expect(validateInstagram('bad-handle')).not.toBe('');
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

describe('validateResetEmail', () => {
  it('accepts a valid email address', () => {
    expect(validateResetEmail('ada@example.com')).toBe('');
  });

  it('rejects a bare username', () => {
    // Usernames are public on author archives, so they must not work as a
    // reset handle.
    expect(validateResetEmail('ada_lovelace')).not.toBe('');
  });

  it('rejects an empty value', () => {
    expect(validateResetEmail('')).not.toBe('');
    expect(validateResetEmail('   ')).not.toBe('');
  });

  it('rejects a malformed email attempt', () => {
    expect(validateResetEmail('ada@')).not.toBe('');
    expect(validateResetEmail('@example.com')).not.toBe('');
    expect(validateResetEmail('ada@example')).not.toBe('');
  });

  it('rejects an overlong value', () => {
    expect(validateResetEmail('a'.repeat(255))).not.toBe('');
  });
});

describe('validateUsername', () => {
  it('accepts a plain login', () => {
    expect(validateUsername('penulis_baru-01')).toBe('');
    expect(validateUsername('a_b-c1')).toBe('');
  });

  it('rejects an empty value', () => {
    expect(validateUsername('')).not.toBe('');
  });

  it('rejects values outside the length bounds', () => {
    expect(validateUsername('ab')).not.toBe('');
    expect(validateUsername('a'.repeat(51))).not.toBe('');
    expect(validateUsername('a'.repeat(50))).toBe('');
  });

  it('rejects dots, uppercase letters and edge punctuation', () => {
    expect(validateUsername('ada.lovelace')).not.toBe('');
    expect(validateUsername('NamaBaru')).not.toBe('');
    expect(validateUsername('_nama')).not.toBe('');
    expect(validateUsername('nama-')).not.toBe('');
  });

  it('rejects characters the server would strip instead of rewriting them', () => {
    // Regression: trimming or sanitizing here would let the form submit a
    // login the writer never typed, and the server would reject it anyway.
    expect(validateUsername('bad name')).not.toBe('');
    expect(validateUsername(' nama')).not.toBe('');
    expect(validateUsername('nama ')).not.toBe('');
    expect(validateUsername('nama@penalis')).not.toBe('');
    expect(validateUsername('nama!')).not.toBe('');
  });

  it('rejects a login with no letter or digit', () => {
    expect(validateUsername('---')).not.toBe('');
    expect(validateUsername('___')).not.toBe('');
  });

  it('rejects the current login, including a case-only change', () => {
    expect(validateUsername('user30', 'user30')).not.toBe('');
    expect(validateUsername('USER30', 'user30')).not.toBe('');
    expect(validateUsername('user31', 'user30')).toBe('');
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
