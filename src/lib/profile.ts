/**
 * Public name options and resolution.
 *
 * WordPress derives a display name from the first/last name or the username
 * when no explicit value is supplied. The dashboard mirrors that rule so the
 * preview matches what the server saves; the server recomputes it
 * authoritatively and never trusts a derived value sent by the client.
 */

import type { DisplayNameMode } from './types';

export interface DisplayNameOption {
  mode: DisplayNameMode;
  label: string;
}

/** Trim a person name and collapse internal whitespace. */
export function normalizePersonName(value: string): string {
  return value.replace(/\s+/g, ' ').trim();
}

/**
 * Resolve the public name for a mode.
 *
 * `auto` follows the WordPress fallback chain: first + last, first, last,
 * username.
 */
export function resolveDisplayName(
  mode: DisplayNameMode,
  firstName: string,
  lastName: string,
  username: string,
  customName = '',
): string {
  const first = normalizePersonName(firstName);
  const last = normalizePersonName(lastName);
  const login = username.trim();

  switch (mode) {
    case 'first':
      return first;
    case 'last':
      return last;
    case 'username':
      return login;
    case 'custom':
      return normalizePersonName(customName);
    case 'auto':
    default:
      if (first && last) {
        return `${first} ${last}`;
      }
      if (first) {
        return first;
      }
      if (last) {
        return last;
      }
      return login;
  }
}

/** Describe which fields the automatic option currently uses. */
export function automaticSource(firstName: string, lastName: string): string {
  const first = normalizePersonName(firstName);
  const last = normalizePersonName(lastName);

  if (first && last) {
    return 'First + Last name';
  }
  if (first) {
    return 'First name';
  }
  if (last) {
    return 'Last name';
  }
  return 'Username';
}

/**
 * Options for the "Display name publicly as" control.
 *
 * Options that need a field the writer has not filled in are omitted, so the
 * select never offers a value the server would reject.
 */
export function displayNameOptions(firstName: string, lastName: string, username: string): DisplayNameOption[] {
  const first = normalizePersonName(firstName);
  const last = normalizePersonName(lastName);
  const login = username.trim();

  const options: DisplayNameOption[] = [
    {
      mode: 'auto',
      label: `${resolveDisplayName('auto', first, last, login)} (${automaticSource(first, last)})`,
    },
  ];

  if (first) {
    options.push({ mode: 'first', label: `${first} (First name only)` });
  }

  if (last) {
    options.push({ mode: 'last', label: `${last} (Last name only)` });
  }

  // With no name fields the automatic option already resolves to the username,
  // so a separate username entry would be a duplicate.
  if (login && (first || last)) {
    options.push({ mode: 'username', label: `${login} (Username)` });
  }

  options.push({ mode: 'custom', label: 'Custom (pen name)…' });

  return options;
}
