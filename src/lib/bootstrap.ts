/**
 * Read the server-provided bootstrap configuration.
 *
 * Authentication uses WordPress cookies plus the REST nonce. Nothing is read
 * from or written to localStorage.
 */

import type { DashboardBootstrap, TurnstileFormKey } from './types';

declare global {
  interface Window {
    penalisDashboard?: DashboardBootstrap;
  }
}

const fallback: DashboardBootstrap = {
  restUrl: '/wp-json/penalis/v1',
  wpRestUrl: '/wp-json/wp/v2',
  nonce: '',
  isLoggedIn: false,
  role: undefined,
  status: '',
  statusLabel: '',
  registration: false,
  dashboardUrl: '/',
  loginUrl: '/dashboard/',
  logoutUrl: '/dashboard/',
  lostPassword: '/?penalis_forgot=1',
  homeUrl: '/',
  authorsUrl: '/author/',
  adminUrl: '',
  reviewUrl: '',
  siteName: 'Penalis',
  siteTimezone: undefined,
  user: null,
};

/**
 * Nonce replaced at runtime by `refreshNonce()`.
 *
 * Kept in a module variable rather than written back into the page only, so a
 * missing `window.penalisDashboard` (tests, unexpected boot order) still
 * leaves the client with a usable value. It lives for the lifetime of the tab
 * and is never persisted anywhere.
 */
let runtimeNonce = '';

export function getBootstrap(): DashboardBootstrap {
  return window.penalisDashboard ?? fallback;
}

/**
 * The `wp_rest` nonce every write currently uses.
 *
 * The value printed into the page goes stale after 12-24 hours, and also when
 * the session is renewed elsewhere (a login in another tab, a password reset,
 * a username change). Reading it through this accessor is what lets a stale
 * tab recover without reloading the page — and reloading is exactly what would
 * destroy an unsaved manuscript.
 */
export function getNonce(): string {
  return runtimeNonce || getBootstrap().nonce;
}

/**
 * Store a freshly issued nonce for the rest of the tab's lifetime.
 *
 * @param nonce Nonce returned by `admin-ajax.php?action=rest-nonce`.
 */
export function setNonce(nonce: string): void {
  runtimeNonce = nonce;

  if (window.penalisDashboard) {
    window.penalisDashboard.nonce = nonce;
  }
}

/**
 * Whether Cloudflare Turnstile is enabled for a specific AuthGate form.
 */
export function isTurnstileEnabledFor(form: TurnstileFormKey): boolean {
  const config = getBootstrap().turnstile;
  return Boolean(config?.enabled && config.siteKey && config.forms?.[form]);
}
