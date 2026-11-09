/**
 * Six-digit verification code form.
 *
 * The code and challenge ID live in component state only. Nothing is written to
 * `localStorage` or `sessionStorage`, so closing the tab discards the flow.
 */

import { useEffect, useId, useState, type FormEvent, type ReactNode } from 'react';

import { describedBy } from '../lib/a11y';
import { formatCountdown, isOtpCodeComplete, normalizeOtpCode, secondsUntil } from '../lib/otp';
import { ErrorBanner, FieldHint, Notice, Spinner } from './ui';

export interface OtpFormProps {
  /** Opaque challenge locator returned by the issuing endpoint. */
  challengeId: string;
  /** Address the code was sent to, shown so the writer can check it. */
  email: string;
  /** Seconds the server asked the client to wait before a resend. */
  resendAfter: number;
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
  onVerify,
  onResend,
  error = '',
  busy = false,
  verifyLabel = 'Verifikasi kode',
  children,
}: OtpFormProps) {
  const fieldId = useId();
  const [code, setCode] = useState('');
  const [deadline, setDeadline] = useState(() => Date.now() + resendAfter * 1000);
  const [now, setNow] = useState(() => Date.now());
  const [resending, setResending] = useState(false);
  const [resendError, setResendError] = useState('');
  const [resendNotice, setResendNotice] = useState('');

  // A new challenge restarts the cooldown and clears the previous code.
  useEffect(() => {
    setDeadline(Date.now() + resendAfter * 1000);
    setCode('');
  }, [challengeId, resendAfter]);

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
        Kami mengirim kode enam digit ke {email}. Kode berlaku 10 menit dan hanya dapat
        dicoba lima kali.
      </p>

      {error ? <ErrorBanner message={error} /> : null}
      {resendError ? <ErrorBanner message={resendError} /> : null}
      {resendNotice ? <Notice tone="success">{resendNotice}</Notice> : null}

      <div className="penalis-field">
        <label htmlFor={fieldId}>Kode Verifikasi</label>
        <input
          id={fieldId}
          type="text"
          value={code}
          onChange={(event) => setCode(normalizeOtpCode(event.target.value))}
          inputMode="numeric"
          autoComplete="one-time-code"
          pattern="[0-9]*"
          maxLength={6}
          placeholder="000000"
          autoFocus
          aria-invalid={Boolean(error)}
          aria-describedby={describedBy(`${fieldId}-hint`, error && `${fieldId}-error`)}
          disabled={busy}
        />
        <FieldHint id={`${fieldId}-hint`}>
          Tempel atau ketik kode dari email. Jangan bagikan kode ini kepada siapa pun.
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

      <div className="penalis-form__actions">
        <button
          type="button"
          className="penalis-button penalis-button--ghost"
          onClick={() => void resend()}
          disabled={remaining > 0 || resending}
        >
          {resending
            ? 'Mengirim…'
            : remaining > 0
              ? `Kirim ulang dalam ${formatCountdown(remaining)}`
              : 'Kirim ulang kode'}
        </button>
      </div>
    </form>
  );
}
