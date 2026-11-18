/**
 * Password + confirmation fields shared by registration, legacy activation,
 * and password reset.
 *
 * The three flows use the same validation keys (`password` and
 * `confirm_password`) and the same persistent hint, but different labels and
 * element IDs. Keeping the markup here guarantees the show/hide control, the
 * strength meter, the live match feedback, and the aria-describedby wiring
 * stay identical everywhere.
 */

import { useState, type ChangeEventHandler } from 'react';

import { describedBy } from '../lib/a11y';
import type { FieldErrors } from '../lib/types';
import { PASSWORD_MIN_LENGTH, passwordMatchStatus } from '../lib/validation';
import { PasswordInput } from './PasswordInput';
import { PasswordMatchHint } from './PasswordMatchHint';
import { PasswordStrengthMeter } from './PasswordStrengthMeter';
import { FieldError, FieldHint } from './ui';

export function PasswordPairFields({
  passwordId,
  confirmId,
  passwordHintId,
  confirmHintId,
  password,
  confirm,
  onPasswordChange,
  onConfirmChange,
  errors,
  passwordLabel = 'Password',
  confirmLabel = 'Konfirmasi Password',
  confirmHint = 'Ketik ulang password untuk konfirmasi.',
}: {
  passwordId: string;
  confirmId: string;
  passwordHintId: string;
  confirmHintId: string;
  password: string;
  confirm: string;
  onPasswordChange: ChangeEventHandler<HTMLInputElement>;
  onConfirmChange: ChangeEventHandler<HTMLInputElement>;
  errors: FieldErrors;
  passwordLabel?: string;
  confirmLabel?: string;
  confirmHint?: string;
}) {
  // Derived from the field ID so every flow gets a unique, stable description
  // target without adding another required prop at the call sites.
  const strengthId = `${passwordId}-strength`;
  const matchId = `${confirmId}-match`;

  /*
   * Feedback on the confirmation waits until the field has been left once.
   * Before that, a confirmation that is still being typed is always shorter
   * than the password and therefore always "different" — reporting it would
   * turn the field red on the first keystroke and leave it red for the whole
   * word. `passwordMatchStatus()` still reports a mismatch earlier when the
   * confirmation is long enough that it cannot become correct any more.
   */
  const [confirmTouched, setConfirmTouched] = useState(false);
  const matchStatus = passwordMatchStatus(password, confirm, confirmTouched);

  // The submit-time error and the live hint say the same thing; showing both
  // would stack two sentences under one input. A matched pair also hides a
  // stale error from an earlier submit, so the message disappears the moment
  // the writer fixes it instead of waiting for the next submit.
  const showConfirmError = Boolean(errors.confirm_password) && matchStatus !== 'match';

  return (
    <>
      <div className="penalis-field">
        <label htmlFor={passwordId}>{passwordLabel}</label>
        <PasswordInput
          id={passwordId}
          value={password}
          onChange={onPasswordChange}
          autoComplete="new-password"
          aria-invalid={Boolean(errors.password)}
          aria-describedby={describedBy(
            passwordHintId,
            password !== '' && strengthId,
            errors.password && 'password-error',
          )}
        />
        <FieldHint id={passwordHintId}>
          Gunakan password yang kuat dan unik minimal {PASSWORD_MIN_LENGTH} karakter.
        </FieldHint>
        <PasswordStrengthMeter password={password} id={strengthId} />
        <FieldError errors={errors} name="password" />
      </div>

      <div className="penalis-field">
        <label htmlFor={confirmId}>{confirmLabel}</label>
        <PasswordInput
          id={confirmId}
          value={confirm}
          onChange={onConfirmChange}
          onBlur={() => setConfirmTouched(true)}
          autoComplete="new-password"
          aria-invalid={showConfirmError}
          aria-describedby={describedBy(
            confirmHintId,
            matchStatus !== 'idle' && !showConfirmError && matchId,
            showConfirmError && 'confirm_password-error',
          )}
        />
        <FieldHint id={confirmHintId}>{confirmHint}</FieldHint>
        {showConfirmError ? (
          <FieldError errors={errors} name="confirm_password" />
        ) : (
          <PasswordMatchHint status={matchStatus} id={matchId} />
        )}
      </div>
    </>
  );
}
