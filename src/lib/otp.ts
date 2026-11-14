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
