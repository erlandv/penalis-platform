/**
 * Shared email-request form for password reset and legacy account activation.
 *
 * Both flows ask for an address, run the same honeypot and Turnstile checks,
 * and differ only in validation, request endpoint, and the copy shown above the
 * field. Keeping the form here means a fix to the security checks lands in both
 * places at once.
 */

import { useState, type FormEvent } from 'react';

import { ApiError } from '../../lib/api';
import { describedBy } from '../../lib/a11y';
import { ErrorBanner, FieldError, FieldHint, HoneypotField, Spinner } from '../../components/ui';
import { TurnstileWidget } from '../../components/TurnstileWidget';
import { TURNSTILE_REQUIRED_MESSAGE, useTurnstileGate } from '../../components/useTurnstileGate';

export function EmailRequestForm<T>({
  form,
  emailId,
  email,
  onEmailChange,
  validate,
  request,
  onSuccess,
  description,
  hint,
  submitLabel,
  busyLabel,
}: {
  form: 'forgot' | 'activate';
  emailId: string;
  email: string;
  onEmailChange: (email: string) => void;
  validate: (email: string) => string;
  request: (email: string, honeypot: string, turnstileToken: string) => Promise<T>;
  onSuccess: (result: T) => void;
  description?: string;
  hint: string;
  submitLabel: string;
  busyLabel: string;
}) {
  const [honeypot, setHoneypot] = useState('');
  const [fieldError, setFieldError] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const turnstile = useTurnstileGate(form);

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setError('');

    // The server answers generically for every outcome, so an empty or
    // malformed address would otherwise look like a successful request.
    const invalid = validate(email);
    if (invalid) {
      setFieldError(invalid);
      return;
    }
    if (turnstile.isMissing()) {
      setFieldError('');
      turnstile.setError(TURNSTILE_REQUIRED_MESSAGE);
      return;
    }
    setFieldError('');
    turnstile.setError('');
    setSubmitting(true);

    try {
      const result = await request(email.trim(), honeypot, turnstile.token);
      onSuccess(result);
    } catch (caught) {
      turnstile.reset();
      setError(caught instanceof ApiError ? caught.message : 'Permintaan tidak dapat diselesaikan.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <form className="penalis-form" onSubmit={submit} noValidate>
      {description ? <p className="penalis-muted">{description}</p> : null}
      {error ? <ErrorBanner message={error} /> : null}

      <div className="penalis-field">
        <label htmlFor={emailId}>Email</label>
        <input
          id={emailId}
          type="email"
          value={email}
          onChange={(event) => {
            onEmailChange(event.target.value);
            setFieldError('');
          }}
          autoComplete="email"
          maxLength={254}
          aria-invalid={Boolean(fieldError)}
          aria-describedby={describedBy(`${emailId}-hint`, fieldError && `${emailId}-error`)}
          required
        />
        <FieldHint id={`${emailId}-hint`}>{hint}</FieldHint>
        <FieldError errors={{ [emailId]: fieldError }} name={emailId} />
      </div>

      <HoneypotField value={honeypot} onChange={setHoneypot} />

      <TurnstileWidget
        form={form}
        resetKey={turnstile.resetKey}
        onTokenChange={turnstile.onTokenChange}
        error={turnstile.error}
      />

      <button type="submit" className="penalis-button penalis-button--primary" disabled={submitting}>
        {submitting ? <Spinner label={busyLabel} /> : submitLabel}
      </button>
    </form>
  );
}
