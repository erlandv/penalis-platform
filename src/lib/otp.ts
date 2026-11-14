/**
 * Verification-code helpers.
 *
 * The six-digit code is the only secret in the flow. It is never persisted in
 * `localStorage` or `sessionStorage`; callers keep it in component state for the
 * duration of the form.
 */

import type { ChallengeResponse } from './types';

export const OTP_LENGTH = 6;

/**
 * Active verification code challenge held in component state.
 */
export interface ActiveChallenge {
  challengeId: string;
  email: string;
  resendAfter: number;
  expiresIn: number;
  maxAttempts: number;
}

/**
 * Normalize a server `ChallengeResponse` payload into an `ActiveChallenge` state object.
 */
export function toActiveChallenge(result: ChallengeResponse, email: string): ActiveChallenge {
  return {
    challengeId: result.challenge_id,
    email,
    resendAfter: result.resend_after,
    expiresIn: result.expires_in,
    maxAttempts: result.max_attempts,
  };
}

/**
 * Keep only digits and cap the value at the code length.
 *
 * Pasting a code from an email often brings surrounding whitespace or a
 * non-breaking space, so the value is normalized rather than rejected.
 *
 * @param value Raw input.
 * @return Digits only, at most six.
 */
export function normalizeOtpCode(value: string): string {
  return value.replace(/\D/g, '').slice(0, OTP_LENGTH);
}

/**
 * Whether a value is a complete six-digit code.
 *
 * @param value Raw input.
 * @return Whether the code can be submitted.
 */
export function isOtpCodeComplete(value: string): boolean {
  return new RegExp(`^\\d{${OTP_LENGTH}}$`).test(value);
}

/**
 * Seconds remaining until a deadline.
 *
 * @param deadline Absolute timestamp in milliseconds.
 * @param now      Current timestamp in milliseconds.
 * @return Whole seconds, never negative.
 */
export function secondsUntil(deadline: number, now: number): number {
  return Math.max(0, Math.ceil((deadline - now) / 1000));
}

/**
 * Format a countdown as `m:ss`.
 *
 * @param seconds Remaining seconds.
 * @return Display string.
 */
export function formatCountdown(seconds: number): string {
  const safe = Math.max(0, Math.floor(seconds));
  const minutes = Math.floor(safe / 60);
  const rest = safe % 60;

  return `${minutes}:${String(rest).padStart(2, '0')}`;
}

/**
 * Create an empty array of OTP digit slots.
 */
export function createEmptyOtpDigits(): string[] {
  return Array.from({ length: OTP_LENGTH }, () => '');
}

/**
 * Apply a change event value from one OTP digit box and compute the next focus index.
 */
export function applyOtpInput(
  digits: readonly string[],
  index: number,
  rawValue: string
): { digits: string[]; nextFocusIndex: number } {
  const nextDigits = [...digits];
  const rawDigits = rawValue.replace(/\D/g, '');
  const current = digits[index] ?? '';

  if (rawDigits.length === 0) {
    nextDigits[index] = '';
    return { digits: nextDigits, nextFocusIndex: index };
  }

  if (rawDigits.length === 1) {
    nextDigits[index] = rawDigits;
    const shouldAdvance = rawValue === rawDigits || current === '';
    return {
      digits: nextDigits,
      nextFocusIndex: shouldAdvance ? Math.min(index + 1, OTP_LENGTH - 1) : index,
    };
  }

  if (rawDigits.length === 2 && current !== '') {
    const newDigit = rawDigits[0] === current ? (rawDigits[1] ?? '') : (rawDigits[0] ?? '');
    nextDigits[index] = newDigit;
    return {
      digits: nextDigits,
      nextFocusIndex: Math.min(index + 1, OTP_LENGTH - 1),
    };
  }

  const start = rawDigits.length >= OTP_LENGTH ? 0 : index;
  const slice = rawDigits.slice(0, OTP_LENGTH - start);

  for (let offset = 0; offset < slice.length; offset += 1) {
    nextDigits[start + offset] = slice[offset] ?? '';
  }

  return {
    digits: nextDigits,
    nextFocusIndex: Math.min(start + slice.length, OTP_LENGTH - 1),
  };
}

/**
 * Apply a pasted OTP string into the digit boxes and compute the next focus index.
 */
export function applyOtpPaste(
  digits: readonly string[],
  index: number,
  rawValue: string
): { digits: string[]; nextFocusIndex: number } {
  const pasted = normalizeOtpCode(rawValue);

  if (!pasted) {
    return { digits: [...digits], nextFocusIndex: index };
  }

  const nextDigits = [...digits];
  const start = pasted.length >= OTP_LENGTH ? 0 : index;
  const slice = pasted.slice(0, OTP_LENGTH - start);

  for (let offset = 0; offset < slice.length; offset += 1) {
    nextDigits[start + offset] = slice[offset] ?? '';
  }

  return {
    digits: nextDigits,
    nextFocusIndex: Math.min(start + slice.length, OTP_LENGTH - 1),
  };
}
