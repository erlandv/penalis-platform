/**
 * Deep-link parameters understood by the dashboard.
 *
 * These let the public site link straight to a specific form, for example from
 * the retired `/submit-karya-tulis` page. Two shapes exist:
 *
 * - **Flag** (`=1`): opens a form. Nothing is consumed, so the link is safe to
 *   bookmark and share.
 * - **Token**: opens a confirmation page that consumes a single-use token.
 *
 * `penalis_activate` accepts both, which is why the value has to be inspected
 * rather than just checked for presence.
 */

export const DEEPLINK = {
  /** Token: confirm an email address. Plugin token, 43 characters. */
  verify: 'penalis_verify',
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
  resetLogin: 'penalis_login',
  /** Token or flag: activate a legacy account, or open the request form. */
  activate: 'penalis_activate',
  /** Flag: open the password reset request form. */
  forgot: 'penalis_forgot',
  /** Flag: open the registration form. */
  register: 'penalis_register',
  /** Flag: open the verification resend form. */
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
 * The accepted range is deliberately wide so this stays a shape test rather
 * than a guess at the exact length.
 *
 * This is what separates `?penalis_activate=1` (open the form) from
 * `?penalis_activate=<token>` (consume the token).
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
