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

/**
 * Options for the "Display name publicly as" control.
 *
 * Each option shows only the resulting public name. Options that need a field
 * the writer has not filled in are omitted, and values that would render the
 * same text as the automatic option are dropped, so the select never offers a
 * choice the server would reject or a duplicate label.
 */
export function displayNameOptions(firstName: string, lastName: string, username: string): DisplayNameOption[] {
  const first = normalizePersonName(firstName);
  const last = normalizePersonName(lastName);
  const login = username.trim();

  const automatic = resolveDisplayName('auto', first, last, login);
  const options: DisplayNameOption[] = [{ mode: 'auto', label: automatic }];
  const seen = new Set<string>([automatic]);

  const add = (mode: DisplayNameMode, label: string) => {
    if ('' !== label && !seen.has(label)) {
      seen.add(label);
      options.push({ mode, label });
    }
  };

  if (first) {
    add('first', first);
  }

  if (last) {
    add('last', last);
  }

  if (login) {
    add('username', login);
  }

  options.push({ mode: 'custom', label: 'Kustom' });

  return options;
}
