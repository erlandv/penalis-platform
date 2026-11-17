/**
 * Password + confirmation fields shared by registration, legacy activation,
 * and password reset.
 *
 * The three flows use the same validation keys (`password` and
 * `confirm_password`) and the same persistent hint, but different labels and
 * element IDs. Keeping the markup here guarantees the show/hide control and
 * the aria-describedby wiring stay identical everywhere.
 */

import type { ChangeEventHandler } from 'react';

import { describedBy } from '../lib/a11y';
import type { FieldErrors } from '../lib/types';
import { PASSWORD_MIN_LENGTH } from '../lib/validation';
import { PasswordInput } from './PasswordInput';
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
          aria-describedby={describedBy(passwordHintId, errors.password && 'password-error')}
        />
        <FieldHint id={passwordHintId}>
          Gunakan password yang kuat dan unik minimal {PASSWORD_MIN_LENGTH} karakter.
        </FieldHint>
        <FieldError errors={errors} name="password" />
      </div>

      <div className="penalis-field">
        <label htmlFor={confirmId}>{confirmLabel}</label>
        <PasswordInput
          id={confirmId}
          value={confirm}
          onChange={onConfirmChange}
          autoComplete="new-password"
          aria-invalid={Boolean(errors.confirm_password)}
          aria-describedby={describedBy(errors.confirm_password && 'confirm_password-error')}
        />
        <FieldHint id={confirmHintId}>{confirmHint}</FieldHint>
        <FieldError errors={errors} name="confirm_password" />
      </div>
    </>
  );
}
