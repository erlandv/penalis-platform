/**
 * Six-digit verification code form.
 *
 * The code and challenge ID live in component state only. Nothing is written to
 * `localStorage` or `sessionStorage`, so closing the tab discards the flow.
 */

import { useEffect, useId, useRef, useState, type ClipboardEvent, type FormEvent, type KeyboardEvent, type ReactNode } from 'react';

import { describedBy } from '../lib/a11y';
import {
  applyOtpInput,
  applyOtpPaste,
  createEmptyOtpDigits,
  formatCountdown,
  isOtpCodeComplete,
  OTP_LENGTH,
  secondsUntil,
} from '../lib/otp';
import { ErrorBanner, FieldHint, Notice, Spinner } from './ui';

export interface OtpFormProps {
  /** Opaque challenge locator returned by the issuing endpoint. */
  challengeId: string;
  /** Address the code was sent to, shown so the writer can check it. */
  email: string;
  /** Seconds the server asked the client to wait before a resend. */
  resendAfter: number;
  /** Seconds the code stays valid, as reported by the server. */
  expiresIn?: number;
  /** Attempts allowed per code, as reported by the server. */
  maxAttempts?: number;
  /** Submit the code. Rejections are surfaced by the parent through `error`. */
  onVerify: (code: string) => Promise<void>;
  /** Request a new code. Must resolve with the new challenge metadata. */
  onResend: () => Promise<{ challenge_id: string; resend_after: number }>;
  /** Server-side error message for the last verification attempt. */
  error?: string;
  /** Whether the parent is currently submitting the code. */
  busy?: boolean;
  /** Primary button label. */
  verifyLabel?: string;
  /** Extra fields rendered above the submit button (for example a password). */
  children?: ReactNode;
}

export function OtpForm({
  challengeId,
  email,
  resendAfter,
  expiresIn = 600,
  onVerify,
  onResend,
  error = '',
  busy = false,
  verifyLabel = 'Verifikasi Kode',
  children,
}: OtpFormProps) {
  const fieldId = useId();
  const [digits, setDigits] = useState<string[]>(() => createEmptyOtpDigits());
  const inputRefs = useRef<Array<HTMLInputElement | null>>([]);
  const [deadline, setDeadline] = useState(() => Date.now() + resendAfter * 1000);
  const [now, setNow] = useState(() => Date.now());
  const [resending, setResending] = useState(false);
  const [resendError, setResendError] = useState('');
  const [resendNotice, setResendNotice] = useState('');

  const code = digits.join('');

  // A new challenge restarts the cooldown and clears the previous code.
  useEffect(() => {
    setDeadline(Date.now() + resendAfter * 1000);
    setDigits(createEmptyOtpDigits());
  }, [challengeId, resendAfter]);

  const focusBox = (index: number) => {
    const target = inputRefs.current[index];
    if (target) {
      target.focus();
      target.select();
    }
  };

  const handleBoxChange = (index: number, rawValue: string) => {
    const result = applyOtpInput(digits, index, rawValue);
    setDigits(result.digits);
    if (result.nextFocusIndex !== index) {
      focusBox(result.nextFocusIndex);
    }
  };

  const handleBoxPaste = (index: number, event: ClipboardEvent<HTMLInputElement>) => {
    event.preventDefault();
    const result = applyOtpPaste(digits, index, event.clipboardData.getData('text'));
    setDigits(result.digits);
    focusBox(result.nextFocusIndex);
  };

  const handleBoxKeyDown = (index: number, event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Backspace') {
      event.preventDefault();
      if (digits[index]) {
        const nextDigits = [...digits];
        nextDigits[index] = '';
        setDigits(nextDigits);
      } else if (index > 0) {
        const nextDigits = [...digits];
        nextDigits[index - 1] = '';
        setDigits(nextDigits);
        focusBox(index - 1);
      }
      return;
    }

    if (event.key === 'Delete') {
      event.preventDefault();
      if (digits[index]) {
        const nextDigits = [...digits];
        nextDigits[index] = '';
        setDigits(nextDigits);
      }
      return;
    }

    if (event.key === 'ArrowLeft' && index > 0) {
      event.preventDefault();
      focusBox(index - 1);
      return;
    }

    if (event.key === 'ArrowRight' && index < OTP_LENGTH - 1) {
      event.preventDefault();
      focusBox(index + 1);
      return;
    }

    if (event.key === 'Home') {
      event.preventDefault();
      focusBox(0);
      return;
    }

    if (event.key === 'End') {
      event.preventDefault();
      focusBox(OTP_LENGTH - 1);
    }
  };

  const remaining = secondsUntil(deadline, now);
  const coolingDown = remaining > 0;

  // One interval for the lifetime of the cooldown. Re-creating it on every tick
  // would reset the timer roughly once a second.
  useEffect(() => {
    if (!coolingDown) {
      return;
    }

    const timer = window.setInterval(() => setNow(Date.now()), 1000);

    return () => window.clearInterval(timer);
  }, [coolingDown]);

  const submit = (event: FormEvent) => {
    event.preventDefault();

    if (!isOtpCodeComplete(code) || busy) {
      return;
    }

    void onVerify(code);
  };

  const resend = async () => {
    if (remaining > 0 || resending) {
      return;
    }

    setResending(true);
    setResendError('');
    setResendNotice('');

    try {
      const result = await onResend();

      // The server may answer with the existing challenge when the request is
      // throttled, so the cooldown is restarted from its answer rather than
      // from a challenge change.
      setDeadline(Date.now() + result.resend_after * 1000);
      setNow(Date.now());
      setResendNotice('Jika permintaan memenuhi syarat, kode verifikasi telah dikirim.');
    } catch {
      setResendError('Kode baru tidak dapat dikirim. Silakan coba lagi nanti.');
    } finally {
      setResending(false);
    }
  };

  return (
    <form className="penalis-form" onSubmit={submit} noValidate>
      <p className="penalis-muted">
        Kami mengirim kode enam digit ke <strong>{email}</strong>. Kode berlaku {Math.round(expiresIn / 60)}{' '}
        menit, jangan bagikan kode kepada siapa pun.
      </p>
      {error ? <ErrorBanner message={error} /> : null}
      {resendError ? <ErrorBanner message={resendError} /> : null}
      {resendNotice ? <Notice tone="success">{resendNotice}</Notice> : null}

      <div className="penalis-field">
        <span id={`${fieldId}-label`} className="penalis-field-label">
          Kode Verifikasi
        </span>
        <div
          className="penalis-otp-input"
          role="group"
          aria-labelledby={`${fieldId}-label`}
          aria-describedby={describedBy(`${fieldId}-hint`, error && `${fieldId}-error`)}
        >
          {digits.map((digit, index) => (
            <input
              key={index}
              ref={(element) => {
                inputRefs.current[index] = element;
              }}
              id={index === 0 ? fieldId : undefined}
              type="text"
              className="penalis-otp-input__box"
              value={digit}
              onChange={(event) => handleBoxChange(index, event.target.value)}
              onPaste={(event) => handleBoxPaste(index, event)}
              onKeyDown={(event) => handleBoxKeyDown(index, event)}
              onFocus={(event) => event.currentTarget.select()}
              inputMode="numeric"
              autoComplete={index === 0 ? 'one-time-code' : 'off'}
              pattern="[0-9]*"
              maxLength={OTP_LENGTH}
              autoFocus={index === 0}
              aria-label={`Digit ${index + 1} dari ${OTP_LENGTH}`}
              aria-invalid={Boolean(error)}
              disabled={busy}
            />
          ))}
        </div>
        <FieldHint id={`${fieldId}-hint`}>
          Tempel atau ketik kode dari email.
        </FieldHint>
        {error ? (
          <p className="penalis-field-error" id={`${fieldId}-error`}>
            {error}
          </p>
        ) : null}
      </div>

      {children}

      <button
        type="submit"
        className="penalis-button penalis-button--primary"
        disabled={busy || !isOtpCodeComplete(code)}
      >
        {busy ? <Spinner label="Memverifikasi…" /> : verifyLabel}
      </button>

      <p className="penalis-otp-resend">
        Belum menerima kode?{' '}
        <button
          type="button"
          className="penalis-link-button"
          onClick={() => void resend()}
          disabled={remaining > 0 || resending}
        >
          {resending
            ? 'Mengirim…'
            : remaining > 0
              ? `Kirim ulang dalam ${formatCountdown(remaining)}`
              : 'Kirim ulang'}
        </button>
      </p>
    </form>
  );
}
