/**
 * Password field with a show/hide toggle.
 *
 * Typing a long password blind is the most common source of failed sign-ins
 * and failed registrations, so every password field in the dashboard gets an
 * eye button that reveals the value. The toggle is a real `<button>` with an
 * accessible name and `aria-pressed`, not a clickable icon, so keyboard and
 * screen-reader users can operate it too.
 *
 * The component accepts every `<input>` prop except `type`, which it owns:
 * callers keep their existing `id`, `name`, `autoComplete`, `aria-*` and
 * validation wiring unchanged.
 */

import { useState, type InputHTMLAttributes } from 'react';

import { Icon } from './icons';

export type PasswordInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>;

export function PasswordInput({ disabled, ...props }: PasswordInputProps) {
  const [visible, setVisible] = useState(false);

  return (
    <div className="penalis-password">
      <input {...props} type={visible ? 'text' : 'password'} disabled={disabled} />
      <button
        type="button"
        className="penalis-password__toggle"
        onClick={() => setVisible((current) => !current)}
        disabled={disabled}
        // A constant label plus `aria-pressed` is the toggle-button pattern:
        // the name stays stable while the state is announced separately.
        aria-label="Tampilkan password"
        aria-pressed={visible}
        title={visible ? 'Sembunyikan password' : 'Tampilkan password'}
      >
        <Icon name={visible ? 'eyeOff' : 'eye'} />
      </button>
    </div>
  );
}
