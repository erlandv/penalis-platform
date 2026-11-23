/**
 * Shared client-side helpers for #KalimatHariIni.
 *
 * Mirrors the pure validation and formatting rules enforced by `Penalis_Policy`
 * on the server so writers get immediate character/line feedback and consistent
 * relative/remaining time labels across the dashboard and `/kalimat-hari-ini/`.
 */

export const DAILY_QUOTE_MAX_LENGTH = 200;
export const DAILY_QUOTE_MAX_LINES = 3;

/**
 * Normalize a #KalimatHariIni string the same way the server does:
 * unify line endings, collapse horizontal spaces per line, drop blank lines.
 */
export function normalizeDailyQuote(value: string): string {
  const unified = value.replace(/\r\n?/g, '\n');
  const lines = unified
    .split('\n')
    .map((line) => line.replace(/[ \t]+/g, ' ').trim())
    .filter((line) => line.length > 0);

  return lines.join('\n');
}

/**
 * Count Unicode code points to match PHP's `mb_strlen(..., 'UTF-8')`.
 */
export function countCodePoints(value: string): number {
  return Array.from(value).length;
}

/**
 * Count non-empty lines in a normalized #KalimatHariIni string.
 */
export function dailyQuoteLineCount(normalized: string): number {
  if (normalized === '') {
    return 0;
  }
  return normalized.split('\n').length;
}

/**
 * Validate a #KalimatHariIni draft on the client for instant feedback.
 * The server remains authoritative.
 */
export function validateDailyQuoteInput(value: string): string {
  const normalized = normalizeDailyQuote(value);

  if (normalized === '') {
    return 'Tulis kalimat terlebih dahulu sebelum menerbitkan #KalimatHariIni.';
  }

  if (dailyQuoteLineCount(normalized) > DAILY_QUOTE_MAX_LINES) {
    return `#KalimatHariIni maksimal ${DAILY_QUOTE_MAX_LINES} baris agar tetap ringkas dibaca.`;
  }

  if (countCodePoints(normalized) > DAILY_QUOTE_MAX_LENGTH) {
    return `#KalimatHariIni maksimal ${DAILY_QUOTE_MAX_LENGTH} karakter.`;
  }

  return '';
}

/**
 * Format a short Indonesian relative elapsed label from Unix seconds.
 */
export function formatQuoteRelativeTime(createdAtSec: number, nowSec = Math.floor(Date.now() / 1000)): string {
  const diff = Math.max(0, nowSec - createdAtSec);

  if (diff < 60) {
    return 'Baru saja';
  }

  if (diff < 3600) {
    const minutes = Math.max(1, Math.floor(diff / 60));
    return `${minutes} menit lalu`;
  }

  const hours = Math.max(1, Math.floor(diff / 3600));
  return `${hours} jam lalu`;
}

/**
 * Format remaining active time before a 24-hour quote expires.
 */
export function formatQuoteRemainingTime(expiresAtSec: number, nowSec = Math.floor(Date.now() / 1000)): string {
  const remaining = expiresAtSec - nowSec;

  if (remaining <= 0) {
    return 'sudah berakhir';
  }

  if (remaining < 60) {
    return 'berakhir kurang dari 1 menit';
  }

  if (remaining < 3600) {
    const minutes = Math.ceil(remaining / 60);
    return `berakhir dalam ${minutes} menit`;
  }

  const hours = Math.floor(remaining / 3600);
  const minutes = Math.floor((remaining % 3600) / 60);

  if (minutes > 0 && hours < 3) {
    return `berakhir dalam ${hours} jam ${minutes} menit`;
  }

  return `berakhir dalam ${hours} jam`;
}

/**
 * Build a shareable plain-text quote block for clipboard copy.
 */
export function buildQuoteShareText(text: string, displayName: string, url?: string): string {
  const base = `"${text}"\n— ${displayName} (#KalimatHariIni)`;
  return url ? `${base}\n${url}` : base;
}

/**
 * Copy text to clipboard with an execCommand fallback for older browsers.
 */
export async function copyToClipboard(text: string): Promise<boolean> {
  if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // Fall through to legacy textarea fallback.
    }
  }

  if (typeof document === 'undefined') {
    return false;
  }

  const textarea = document.createElement('textarea');
  textarea.value = text;
  textarea.setAttribute('readonly', '');
  textarea.style.position = 'fixed';
  textarea.style.top = '-9999px';
  document.body.appendChild(textarea);
  textarea.select();

  try {
    return document.execCommand('copy');
  } catch {
    return false;
  } finally {
    document.body.removeChild(textarea);
  }
}
