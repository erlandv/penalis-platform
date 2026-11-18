/**
 * Live match feedback for the password confirmation field.
 *
 * Sits between the confirmation input and its submit-time error. The two never
 * render together: the caller hides the error once the values match again, so
 * a stale "Password tidak cocok." cannot survive the correction that fixed it.
 *
 * The line is a `role="status"` region, which means a screen-reader user hears
 * the result without moving focus, and the text — not just the colour — carries
 * the verdict.
 */

import { PASSWORD_MISMATCH_MESSAGE, type PasswordMatchStatus } from '../lib/validation';
import { Icon } from './icons';

export function PasswordMatchHint({ status, id }: { status: PasswordMatchStatus; id: string }) {
  if (status === 'idle') {
    return null;
  }

  const matched = status === 'match';

  return (
    <small
      className={`penalis-password-match penalis-password-match--${status}`}
      id={id}
      role="status"
      aria-atomic="true"
    >
      <Icon name={matched ? 'check' : 'close'} />
      <span>{matched ? 'Password cocok.' : PASSWORD_MISMATCH_MESSAGE}</span>
    </small>
  );
}
