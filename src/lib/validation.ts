/**
 * Client-side validation mirrors the server rules for fast feedback only.
 * The server always re-validates every value.
 */

import type { FieldErrors } from './types';

export const PASSWORD_MIN_LENGTH = 12;

export function validateRegistration(input: {
  display_name: string;
  user_login: string;
  user_email: string;
  password: string;
  confirm_password: string;
  penalis_consent: boolean;
}): FieldErrors {
  const errors: FieldErrors = {};

  const display = input.display_name.trim();
  if (display.length < 2 || display.length > 80) {
    errors.display_name = 'Masukkan nama tampilan 2–80 karakter.';
  }

  if (!/^[a-zA-Z0-9._-]{3,60}$/.test(input.user_login.trim())) {
    errors.user_login = 'Gunakan 3–60 huruf, angka, titik, garis bawah, atau tanda hubung.';
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.user_email.trim())) {
    errors.user_email = 'Masukkan alamat email yang valid.';
  }

  if (input.password.length < PASSWORD_MIN_LENGTH) {
    errors.password = `Gunakan minimal ${PASSWORD_MIN_LENGTH} karakter.`;
  }

  if (input.password !== input.confirm_password) {
    errors.confirm_password = 'Kata sandi tidak cocok.';
  }

  if (!input.penalis_consent) {
    errors.penalis_consent = 'Anda harus menyetujui ketentuan untuk mendaftar.';
  }

  return errors;
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

  if (value.length < 3 || value.length > 60) {
    return 'Username harus 3–60 karakter.';
  }

  if (!/^[a-zA-Z0-9._-]+$/.test(value)) {
    return 'Gunakan hanya huruf, angka, titik, garis bawah, atau tanda hubung.';
  }

  if (!/[a-zA-Z0-9]/.test(value)) {
    return 'Username harus mengandung huruf atau angka.';
  }

  if (current !== '' && value.toLowerCase() === current.toLowerCase()) {
    return 'Username baru sama dengan username kamu saat ini.';
  }

  return '';
}

/**
 * Validate the login-or-email field used by the password reset request.
 *
 * The server accepts either a username or an email address, so this only
 * rejects an empty value and an obviously malformed address. A bare username
 * is valid.
 *
 * @param value Raw input.
 * @return Error message, or an empty string when valid.
 */
export function validateLoginOrEmail(value: string): string {
  const login = value.trim();

  if (login === '') {
    return 'Masukkan email atau nama pengguna kamu.';
  }

  if (login.length > 254) {
    return 'Nilai yang dimasukkan terlalu panjang.';
  }

  // Only validate the shape when it looks like an email attempt.
  if (login.includes('@') && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(login)) {
    return 'Masukkan alamat email yang valid.';
  }

  return '';
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

export function hasErrors(errors: FieldErrors): boolean {
  return Object.keys(errors).length > 0;
}
