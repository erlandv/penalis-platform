/**
 * Shared Turnstile gate for public auth forms.
 *
 * Every form that can show the widget needs the same three pieces of state:
 * the token, the validation message, and a key that resets the widget after a
 * failed request. The forms also need the same "required but empty" check, so
 * keeping it here avoids five slightly different implementations.
 */

import { useState } from 'react';

import { isTurnstileEnabledFor } from '../lib/bootstrap';
import type { TurnstileFormKey } from '../lib/types';

export const TURNSTILE_REQUIRED_MESSAGE =
  'Harap selesaikan verifikasi keamanan (CAPTCHA) terlebih dahulu.';

export function useTurnstileGate(form: TurnstileFormKey) {
  const [token, setToken] = useState('');
  const [error, setError] = useState('');
  const [resetKey, setResetKey] = useState(0);
  const required = isTurnstileEnabledFor(form);

  const onTokenChange = (next: string) => {
    setToken(next);
    if (next) {
      setError('');
    }
  };

  const reset = () => setResetKey((current) => current + 1);
  const isMissing = () => required && token === '';

  return {
    token,
    error,
    setError,
    resetKey,
    reset,
    required,
    onTokenChange,
    isMissing,
  };
}
