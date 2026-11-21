/**
 * Client-side validation mirrors the server rules for fast feedback only.
 * The server always re-validates every value.
 */

import type { FieldErrors, Term } from './types';

export const PASSWORD_MIN_LENGTH = 12;
export const BIO_MAX_LENGTH = 250;

/**
 * The one wording for a password/confirmation mismatch.
 *
 * Shared by the submit-time validator and the live hint so the writer never
 * sees two different sentences for the same problem.
 */
export const PASSWORD_MISMATCH_MESSAGE = 'Password tidak cocok.';

/**
 * Live state of the confirmation field, evaluated while typing.
 *
 * - `idle` — nothing to say yet.
 * - `match` — both fields hold the same value.
 * - `mismatch` — they differ, and that difference is worth reporting now.
 */
export type PasswordMatchStatus = 'idle' | 'match' | 'mismatch';

/**
 * Decide what the confirmation field should say before the form is submitted.
 *
 * The rule is "wait for certainty". While the writer is still typing, a
 * shorter confirmation always differs from the password, so reporting it would
 * fire an error on the very first keystroke and never stop. A mismatch is
 * reported once the field has been visited (`touched`, set on blur) or once it
 * is at least as long as the password — at that point the values cannot become
 * equal without deleting something, so the writer has genuinely made a
 * mistake. Equality is reported immediately, because good news is never
 * premature.
 *
 * This is feedback, not enforcement: the server still rejects a mismatched
 * pair on submit.
 *
 * @param password Password field value.
 * @param confirmPassword Confirmation field value.
 * @param touched Whether the confirmation field has been blurred at least once.
 * @return Live match status.
 */
export function passwordMatchStatus(
  password: string,
  confirmPassword: string,
  touched = false,
): PasswordMatchStatus {
  if (password === '' || confirmPassword === '') {
    return 'idle';
  }

  if (password === confirmPassword) {
    return 'match';
  }

  if (touched || confirmPassword.length >= password.length) {
    return 'mismatch';
  }

  return 'idle';
}

export function validatePasswordPair(password: string, confirmPassword: string): FieldErrors {
  const errors: FieldErrors = {};

  if (password.length < PASSWORD_MIN_LENGTH) {
    errors.password = `Gunakan minimal ${PASSWORD_MIN_LENGTH} karakter.`;
  }

  if (password !== confirmPassword) {
    errors.confirm_password = PASSWORD_MISMATCH_MESSAGE;
  }

  return errors;
}

export function validateRegistration(input: {
  display_name: string;
  user_login: string;
  user_email: string;
  password: string;
  confirm_password: string;
  instagram?: string;
  penalis_consent: boolean;
}): FieldErrors {
  const errors: FieldErrors = {};

  const display = input.display_name.trim();
  if (display.length < 2 || display.length > 80) {
    errors.display_name = 'Masukkan nama tampilan 2–80 karakter.';
  }

  const usernameMessage = validateUsername(input.user_login);
  if (usernameMessage !== '') {
    errors.user_login = usernameMessage;
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.user_email.trim())) {
    errors.user_email = 'Masukkan alamat email yang valid.';
  }

  Object.assign(errors, validatePasswordPair(input.password, input.confirm_password));

  if (input.instagram !== undefined && input.instagram.trim() !== '') {
    const instagramMessage = validateInstagram(input.instagram);
    if (instagramMessage !== '') {
      errors.instagram = instagramMessage;
    }
  }

  if (!input.penalis_consent) {
    errors.penalis_consent = 'Kamu harus menyetujui ketentuan untuk mendaftar.';
  }

  return errors;
}

/**
 * Normalize an optional Instagram username or profile URL into a bare handle.
 */
export function normalizeInstagram(value: string): string {
  let clean = value.trim();
  if (clean === '') {
    return '';
  }

  const match = /^(?:https?:\/\/)?(?:www\.)?instagram\.com\/([^/?#]+)/i.exec(clean);
  if (match && match[1]) {
    clean = match[1];
  }

  return clean.replace(/^[/\s]+|[/\s]+$/g, '').replace(/^@+/, '');
}

/**
 * Validate an optional Instagram username.
 */
export function validateInstagram(value: string): string {
  const handle = normalizeInstagram(value);
  if (handle === '') {
    return '';
  }

  if (handle.length > 30 || !/^[A-Za-z0-9._]{1,30}$/.test(handle) || !/[A-Za-z0-9]/.test(handle)) {
    return 'Masukkan username Instagram yang valid (huruf, angka, titik, atau garis bawah, maksimal 30 karakter).';
  }

  return '';
}

/**
 * Collapse consecutive blank lines inside a bio textarea while typing so
 * repeated Enter presses cannot create empty vertical gaps.
 */
export function collapseBioNewlines(value: string): string {
  return value.replace(/\r\n?/g, '\n').replace(/[ \t]*\n(?:[ \t]*\n)+/g, '\n');
}

/**
 * Normalize a writer bio identically to `Penalis_Policy::normalize_bio()`.
 */
export function normalizeBio(value: string): string {
  const normalized = value.replace(/\r\n?/g, '\n').trim();
  if (normalized === '') {
    return '';
  }

  return normalized.replace(/[ \t]*\n(?:[ \t]*\n)*/g, '\n').trim();
}

/**
 * Validate a writer bio against the maximum length after newline normalization.
 */
export function validateBio(value: string): string {
  if (normalizeBio(value).length > BIO_MAX_LENGTH) {
    return `Bio maksimal ${BIO_MAX_LENGTH} karakter.`;
  }

  return '';
}

/**
 * Validate an email address for the request forms.
 *
 * These forms intentionally answer generically on the server, so a client-side
 * check is the only place a visitor learns that the field is empty or
 * malformed. Without it, submitting an empty form shows the same "we sent a
 * link" notice as a real request, which reads as a success.
 *
 * @param value Raw input.
 * @return Error message, or an empty string when valid.
 */
export function validateEmail(value: string): string {
  const email = value.trim();

  if (email === '') {
    return 'Masukkan alamat email kamu.';
  }

  if (email.length > 254) {
    return 'Alamat email terlalu panjang.';
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    return 'Masukkan alamat email yang valid.';
  }

  return '';
}

/**
 * Validate a requested new login.
 *
 * Mirrors `Penalis_Policy::validate_username_change()`. The server rejects a
 * value that would only become valid after sanitization, so this must not trim
 * or rewrite the input either: a login the writer cannot reproduce is worse
 * than an error message.
 *
 * @param value Raw input.
 * @param current Login currently on file.
 * @return Error message, or an empty string when valid.
 */
export function validateUsername(value: string, current = ''): string {
  if (value === '') {
    return 'Masukkan username baru kamu.';
  }

  if (value.length < 3 || value.length > 50) {
    return 'Username harus 3-50 karakter.';
  }

  if (!/^[a-z0-9][a-z0-9_-]{1,48}[a-z0-9]$/.test(value)) {
    return 'Gunakan huruf kecil, angka, garis bawah, atau tanda hubung; awali dan akhiri dengan huruf atau angka.';
  }

  if (current !== '' && value.toLowerCase() === current.toLowerCase()) {
    return 'Username baru sama dengan username kamu saat ini.';
  }

  return '';
}

/**
 * Validate the email field used by the password reset request.
 *
 * The reset request accepts an email address only. Usernames are public on
 * author archives, so accepting one would let anyone turn a public profile
 * into a reset attempt against that account. The server enforces the same
 * rule; this check exists so the visitor gets feedback instead of the generic
 * "we sent a link" notice for a value that was never looked up.
 *
 * @param value Raw input.
 * @return Error message, or an empty string when valid.
 */
export function validateResetEmail(value: string): string {
  const email = value.trim();

  // A value without "@" is almost certainly a username. Say so explicitly:
  // the generic "invalid email" message would leave the visitor guessing why
  // the login they use elsewhere is rejected here.
  if (email !== '' && !email.includes('@')) {
    return 'Masukkan alamat email kamu. Username tidak dapat dipakai untuk reset password.';
  }

  return validateEmail(email);
}

/**
 * Normalise a link target for the article editor.
 *
 * Writers may enter absolute http(s) links, mailto addresses, root-relative
 * paths, or in-page fragments. Everything else (javascript:, data:,
 * protocol-relative URLs, bare host names) is rejected. The server stays the
 * authority through the WordPress content filter; this only keeps an unsafe
 * or broken value from ever reaching the document.
 *
 * @param raw Raw input from the link dialog.
 * @return The normalised href, or null when the value is not usable.
 */
export function normalizeLinkHref(raw: string): string | null {
  const value = raw.trim();

  if (value === '') {
    return null;
  }

  // In-page fragment.
  if (value.startsWith('#')) {
    return value;
  }

  // Root-relative path. A double slash would be protocol-relative, which is
  // rejected below because it can silently become any scheme.
  if (/^\/(?!\/)/.test(value)) {
    return value;
  }

  const match = /^([a-z][a-z0-9+.-]*):/i.exec(value);
  if (!match) {
    return null;
  }

  const scheme = (match[1] ?? '').toLowerCase();

  if ((scheme === 'http' || scheme === 'https') && /^https?:\/\/\S+$/i.test(value)) {
    return value;
  }

  if (scheme === 'mailto' && /^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$/i.test(value)) {
    return value;
  }

  return null;
}

export function validateArticle(input: { title: string; content: string }): FieldErrors {
  const errors: FieldErrors = {};

  if (input.title.trim().length < 3) {
    errors.title = 'Judul minimal 3 karakter.';
  }

  if (input.title.trim().length > 200) {
    errors.title = 'Judul maksimal 200 karakter.';
  }

  if (input.content.trim().length < 10) {
    errors.content = 'Tulis minimal satu paragraf singkat sebelum menyimpan.';
  }

  return errors;
}

export function isUncategorizedTerm(term: Pick<Term, 'name' | 'slug'>): boolean {
  const slug = term.slug?.trim().toLowerCase() ?? '';
  const name = term.name.trim().toLowerCase();
  return slug === 'uncategorized' || name === 'uncategorized';
}

/**
 * Validate an article before submitting or resubmitting for editorial review.
 *
 * Drafts only require title and content; review submission additionally
 * requires a featured image, at least one non-uncategorized category, and
 * at least one tag.
 */
export function validateArticleSubmission(input: {
  title: string;
  content: string;
  featuredMedia: number;
  categories: Term[];
  tags: Term[];
}): FieldErrors {
  const errors = validateArticle({ title: input.title, content: input.content });

  if (input.featuredMedia <= 0) {
    errors.featuredMedia = 'Pilih thumbnail karya tulis terlebih dahulu.';
  }

  const validCategories = input.categories.filter((term) => !isUncategorizedTerm(term));
  if (validCategories.length === 0) {
    errors.categories = 'Pilih minimal satu kategori karya tulis.';
  }

  if (input.tags.length === 0) {
    errors.tags = 'Tambahkan minimal satu tag karya tulis.';
  }

  return errors;
}

export function hasErrors(errors: FieldErrors): boolean {
  return Object.keys(errors).length > 0;
}
