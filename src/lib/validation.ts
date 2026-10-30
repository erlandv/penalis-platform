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
