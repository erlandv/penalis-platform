/**
 * The shared "back to login" link is an escape hatch for forms still in
 * progress. Success notices already carry their own login CTA, so showing
 * both would offer two labels for the exact same action.
 */

export function shouldShowAuthBackNav(mode: string, finished: boolean): boolean {
  return mode !== 'login' && !finished;
}

/** Auth screens a deep link can open directly. */
export type AuthEntryMode = 'login' | 'register' | 'forgot' | 'activate';

/** Deep-link flags read by {@link resolveEntryMode}. */
export type AuthEntryFlags = {
  register: boolean;
  activate: boolean;
  forgot: boolean;
};

/**
 * Which form a deep link opens.
 *
 * | Flag | Opens |
 * | --- | --- |
 * | `penalis_signin=1` | Login (also the default) |
 * | `penalis_register=1` | Registration |
 * | `penalis_activate=1` | Legacy account activation |
 * | `penalis_forgot=1` | Password reset request |
 *
 * Registration is the only auth form that can be closed site-wide, so a
 * `penalis_register=1` link on a closed site falls back to login instead of
 * opening a form the server would reject at the very last step.
 */
export function resolveEntryMode(flags: AuthEntryFlags, registrationOpen: boolean): AuthEntryMode {
  if (flags.register) {
    return registrationOpen ? 'register' : 'login';
  }

  if (flags.activate) {
    return 'activate';
  }

  if (flags.forgot) {
    return 'forgot';
  }

  return 'login';
}
