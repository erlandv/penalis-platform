/**
 * Formatting helpers.
 */

export function formatDate(value: string | number): string {
  if (!value) {
    return '—';
  }

  const date =
    typeof value === 'number' ? new Date(value * 1000) : new Date(value.endsWith('Z') ? value : `${value}Z`);

  if (Number.isNaN(date.getTime())) {
    return '—';
  }

  return new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  }).format(date);
}

export function statusLabel(status: string): string {
  const labels: Record<string, string> = {
    publish: 'Publish',
    draft: 'Draft',
    pending: 'Pending',
    future: 'Terjadwal',
    private: 'Private',
    pending_verification: 'Menunggu Verifikasi Email',
    pending_approval: 'Menunggu Persetujuan',
    active: 'Aktif',
    rejected: 'Ditolak',
    suspended: 'Ditangguhkan',
  };

  return labels[status] ?? status;
}

export function statusTone(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
  switch (status) {
    case 'publish':
    case 'active':
      return 'success';
    case 'pending':
    case 'pending_verification':
    case 'pending_approval':
    case 'future':
      return 'warning';
    case 'rejected':
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
