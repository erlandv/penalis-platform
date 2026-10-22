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
    errors.display_name = 'Enter a display name between 2 and 80 characters.';
  }

  if (!/^[a-zA-Z0-9._-]{3,60}$/.test(input.user_login.trim())) {
    errors.user_login = 'Use 3–60 letters, numbers, dots, underscores, or hyphens.';
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.user_email.trim())) {
    errors.user_email = 'Enter a valid email address.';
  }

  if (input.password.length < PASSWORD_MIN_LENGTH) {
    errors.password = `Use at least ${PASSWORD_MIN_LENGTH} characters.`;
  }

  if (input.password !== input.confirm_password) {
    errors.confirm_password = 'Passwords do not match.';
  }

  if (!input.penalis_consent) {
    errors.penalis_consent = 'You must accept the terms to register.';
  }

  return errors;
}

export function validateArticle(input: { title: string; content: string }): FieldErrors {
  const errors: FieldErrors = {};

  if (input.title.trim().length < 3) {
    errors.title = 'Please enter a title of at least 3 characters.';
  }

  if (input.title.trim().length > 200) {
    errors.title = 'Titles must be 200 characters or fewer.';
  }

  if (input.content.trim().length < 10) {
    errors.content = 'Please write at least a short paragraph before saving.';
  }

  return errors;
}

export function hasErrors(errors: FieldErrors): boolean {
  return Object.keys(errors).length > 0;
}
