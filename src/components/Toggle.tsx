/**
 * Shared pill toggle.
 *
 * A real checkbox stays in the DOM for semantics, keyboard, and form behavior;
 * only its appearance is replaced. The three sizes follow the design system:
 * small 28x16 (12px thumb), medium 36x20 (16px thumb), large 42x24 (20px
 * thumb), each with a 2px inset. Hover, focus-visible, and disabled states are
 * defined centrally so every screen uses the same feedback.
 */

import type { ChangeEvent } from 'react';

export type ToggleSize = 'sm' | 'md' | 'lg';

export function Toggle({
  checked,
  onChange,
  label,
  size = 'md',
  disabled = false,
  describedBy,
}: {
  checked: boolean;
  onChange: (checked: boolean) => void;
  label: string;
  size?: ToggleSize;
  disabled?: boolean;
  describedBy?: string;
}) {
  const classes = [
    'penalis-toggle',
    `penalis-toggle--${size}`,
    disabled ? 'penalis-toggle--disabled' : '',
  ]
    .filter(Boolean)
    .join(' ');

  return (
    <label className={classes}>
      <input
        className="penalis-toggle__input"
        type="checkbox"
        role="switch"
        checked={checked}
        disabled={disabled}
        aria-checked={checked}
        aria-label={label}
        aria-describedby={describedBy}
        onChange={(event: ChangeEvent<HTMLInputElement>) => onChange(event.target.checked)}
      />
      <span className="penalis-toggle__track" aria-hidden="true">
        <span className="penalis-toggle__thumb" />
      </span>
    </label>
  );
}
