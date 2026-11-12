/**
 * Read the server-provided bootstrap configuration.
 *
 * Authentication uses WordPress cookies plus the REST nonce. Nothing is read
 * from or written to localStorage.
 */

import type { DashboardBootstrap } from './types';

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
  canWrite: false,
  status: '',
  statusLabel: '',
  registration: false,
  dashboardUrl: '/',
  loginUrl: '/dashboard/',
  logoutUrl: '/dashboard/',
  lostPassword: '/?penalis_forgot=1',
  homeUrl: '/',
  adminUrl: '',
  reviewUrl: '',
  siteName: 'Penalis',
  siteTimezone: undefined,
  user: null,
};

export function getBootstrap(): DashboardBootstrap {
  return window.penalisDashboard ?? fallback;
}
