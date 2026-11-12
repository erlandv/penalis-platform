/**
 * Formatting helpers.
 *
 * All timestamps coming from WordPress are UTC unless they carry an explicit
 * offset. They are displayed in the reader's browser timezone (never forced to
 * the site timezone) using the Indonesian locale, so a writer travelling
 * abroad still reads times on their own clock.
 */

/** Value shapes accepted by the date helpers. */
export type DateInput = string | number | Date;

/** Optional overrides used by deterministic tests. */
export interface FormatOptions {
  /** IANA timezone; defaults to the browser timezone. */
  timeZone?: string;
}

const LOCALE = 'id-ID';

/**
 * Epoch values at or above this magnitude are already milliseconds.
 *
 * The REST API exposes account timestamps as Unix seconds, while JavaScript
 * callers naturally produce milliseconds. Anything below the threshold (year
 * 5138 in milliseconds) is treated as seconds so both shapes keep working.
 */
const MILLISECOND_THRESHOLD = 1e11;

/** Matches a trailing `Z`, `+07:00`, or `+0700` zone designator. */
const ZONE_SUFFIX = /(?:[zZ]|[+-]\d{2}:?\d{2})$/;

/**
 * Parse a value produced by the WordPress REST API into a Date.
 *
 * - numeric values are parsed as Unix epoch seconds or milliseconds;
 * - strings keep an explicit `Z` or numeric offset and are never rewritten;
 * - offset-less MySQL/GMT strings (`2025-11-12 20:30:00`) are UTC by contract;
 * - `post_date_gmt` style strings use a space separator instead of `T`.
 *
 * @param value Timestamp, ISO/MySQL string, or Date.
 * @returns Parsed date, or `null` when the value is empty or invalid.
 */
export function parseDateValue(value: DateInput | null | undefined): Date | null {
  if (value === null || value === undefined) {
    return null;
  }

  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value;
  }

  if (typeof value === 'number') {
    if (!Number.isFinite(value) || value === 0) {
      return null;
    }

    const milliseconds = Math.abs(value) >= MILLISECOND_THRESHOLD ? value : value * 1000;
    const date = new Date(milliseconds);

    return Number.isNaN(date.getTime()) ? null : date;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  // `post_date_gmt` uses a space separator; ISO 8601 expects `T`.
  const normalized = trimmed.replace(' ', 'T');
  // Offset-less values are WordPress GMT columns, so they are UTC.
  const candidate = ZONE_SUFFIX.test(normalized) ? normalized : `${normalized}Z`;
  const date = new Date(candidate);

  return Number.isNaN(date.getTime()) ? null : date;
}

function dateOptions(options: FormatOptions): Intl.DateTimeFormatOptions {
  return options.timeZone ? { timeZone: options.timeZone } : {};
}

/**
 * Format a date without the time in the reader's timezone.
 *
 * @param value Timestamp, ISO/MySQL string, or Date.
 * @param options Optional timezone override for tests.
 */
export function formatDate(value: DateInput, options: FormatOptions = {}): string {
  const date = parseDateValue(value);

  if (!date) {
    return '—';
  }

  return new Intl.DateTimeFormat(LOCALE, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    ...dateOptions(options),
  }).format(date);
}

/**
 * Format a date and time plus a short timezone label (`13 Nov 2025, 03.30 WIB`).
 *
 * Used for editorial timestamps (submission, review, review history) and
 * notifications, where readers need both the calendar day and the hour.
 *
 * @param value Timestamp, ISO/MySQL string, or Date.
 * @param options Optional timezone override for tests.
 */
export function formatDateTime(value: DateInput, options: FormatOptions = {}): string {
  const date = parseDateValue(value);

  if (!date) {
    return '—';
  }

  return new Intl.DateTimeFormat(LOCALE, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    timeZoneName: 'short',
    ...dateOptions(options),
  }).format(date);
}

/**
 * Format only the clock time plus a short timezone label (`03.30 WIB`).
 *
 * Used by the editor's save indicator, where the calendar day is noise because
 * the save just happened in this session.
 *
 * @param value Timestamp, ISO/MySQL string, or Date.
 * @param options Optional timezone override for tests.
 */
export function formatTime(value: DateInput, options: FormatOptions = {}): string {
  const date = parseDateValue(value);

  if (!date) {
    return '—';
  }

  return new Intl.DateTimeFormat(LOCALE, {
    hour: '2-digit',
    minute: '2-digit',
    timeZoneName: 'short',
    ...dateOptions(options),
  }).format(date);
}

export function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    publish: 'Terbit',
    published: 'Terbit',
    draft: 'Draft',
    pending: 'Menunggu review',
    pending_review: 'Menunggu review',
    changes_requested: 'Perlu revisi',
    future: 'Terjadwal',
    private: 'Privat',
    pending_verification: 'Menunggu Verifikasi Email',
    active: 'Aktif',
    rejected: 'Ditolak',
    suspended: 'Ditangguhkan',
  };

  return labels[status] ?? status;
}

export function editorialStateLabel(state: string): string {
  const labels: Record<string, string> = {
    draft: 'Draft',
    pending_review: 'Menunggu review',
    changes_requested: 'Perlu revisi',
    rejected: 'Ditolak',
    published: 'Terbit',
    publish: 'Terbit',
    pending: 'Menunggu review',
  };

  return labels[state] ?? 'Tidak diketahui';
}

export function statusTone(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
  switch (status) {
    case 'publish':
    case 'published':
    case 'approve':
    case 'active':
      return 'success';
    case 'pending':
    case 'pending_review':
    case 'changes_requested':
    case 'submit':
    case 'resubmit':
    case 'request_changes':
    case 'reopen':
    case 'pending_verification':
    case 'future':
      return 'warning';
    case 'rejected':
    case 'reject':
    case 'suspended':
      return 'danger';
    default:
      return 'neutral';
  }
}

export function truncate(value: string, length = 160): string {
  const text = value.trim();
  if (text.length <= length) {
    return text;
  }
  return `${text.slice(0, length - 1)}…`;
}

const NAMED_ENTITIES: Record<string, string> = {
  amp: '&',
  lt: '<',
  gt: '>',
  quot: '"',
  apos: "'",
  nbsp: '\u00a0',
};

/**
 * Decode the HTML entities that WordPress stores in taxonomy names.
 *
 * Imported terms keep literal entities in the database (`Budaya &amp; Agama`)
 * and the taxonomy endpoints return them unchanged. React renders strings as
 * text, so the entity would appear verbatim unless it is decoded first.
 *
 * Only one level is decoded, matching how a browser treats the same string as
 * HTML text. Unknown entities and invalid code points are left untouched.
 */
export function decodeEntities(value: string): string {
  if (!value.includes('&')) {
    return value;
  }

  return value.replace(
    /&(#[xX][0-9a-fA-F]+|#[0-9]+|[a-zA-Z][a-zA-Z0-9]*);/g,
    (match: string, entity: string) => {
      if (entity.startsWith('#')) {
        const isHex = entity[1] === 'x' || entity[1] === 'X';
        const code = Number.parseInt(isHex ? entity.slice(2) : entity.slice(1), isHex ? 16 : 10);

        if (!Number.isFinite(code) || code < 1 || code > 0x10ffff || (code >= 0xd800 && code <= 0xdfff)) {
          return match;
        }

        return String.fromCodePoint(code);
      }

      return NAMED_ENTITIES[entity.toLowerCase()] ?? match;
    },
  );
}
