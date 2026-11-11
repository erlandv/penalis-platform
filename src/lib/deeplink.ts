/**
 * Deep-link parameters understood by the dashboard.
 *
 * These let the public site link straight to a specific form, for example from
 * the retired `/submit-karya-tulis` page. Most parameters are **flags** (`=1`)
 * that only open a form and never carry a secret. Email verification uses a
 * six-digit code typed into the dashboard, so no confirmation link exists.
 *
 * The one exception is the password reset pair (`reset` + `resetLogin`), which
 * carries a WordPress core reset key.
 */

export const DEEPLINK = {
  /**
   * Key: set a new password from a reset email.
   *
   * This is a WordPress core key from `get_password_reset_key()`, which uses
   * `wp_generate_password( 20, false )` — 20 alphanumeric characters. It is
   * deliberately NOT validated with `isTokenShaped()`, which only describes
   * plugin-issued tokens.
   */
  reset: 'penalis_reset',
  /** Companion to `reset`: the username the key belongs to. */
  resetLogin: 'penalis_user',
  /** Legacy companion to `reset` for backwards compatibility with earlier emails. */
  legacyResetLogin: 'penalis_login',
  /** Flag: open the legacy account activation form. */
  activate: 'penalis_activate',
  /** Flag: open the password reset request form. */
  forgot: 'penalis_forgot',
  /** Flag: open the registration form. */
  register: 'penalis_register',
  /** Flag: open the verification code resend form. */
  resend: 'penalis_resend',
  /** Flag: open the login form. Login is also the default. */
  signin: 'penalis_signin',
  /** Flag: a dashboard login attempt failed. */
  loginError: 'penalis_login_error',
} as const;

/**
 * Whether a value looks like a plugin-issued token.
 *
 * Mirrors `Penalis_Policy::is_token_format_valid()` on the server: 32 random
 * bytes encoded as URL-safe base64 without padding, which is 43 characters.
 *
 * Verification codes are six digits and are never accepted by this check, so a
 * code can never be mistaken for a bearer token.
 *
 * Do NOT use this for the password reset key: that comes from WordPress core
 * and is only 20 characters.
 *
 * @param value Query parameter value.
 * @return Whether the value has the shape of a plugin token.
 */
export function isTokenShaped(value: string | null | undefined): value is string {
  return typeof value === 'string' && /^[A-Za-z0-9_-]{40,64}$/.test(value);
}

/**
 * Read a query parameter from the current URL.
 *
 * @param name Parameter name.
 * @return The value, or null when absent.
 */
export function readParam(name: string): string | null {
  return new URLSearchParams(window.location.search).get(name);
}

/**
 * Whether a flag parameter is present.
 *
 * @param name Parameter name.
 * @return Whether the parameter exists.
 */
export function hasParam(name: string): boolean {
  return new URLSearchParams(window.location.search).has(name);
}
