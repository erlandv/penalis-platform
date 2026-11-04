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
 * Preview the author archive URL after a login change.
 *
 * The server rewrites `user_nicename` to the new login, so the last path
 * segment of the current author URL is the part that changes. Everything
 * before it — including a custom author base such as `/penulis/` — is kept,
 * because the site may not use WordPress's default `/author/` prefix.
 *
 * Returns the input unchanged when either value is empty or the URL cannot be
 * parsed, so the preview never invents a link the server would not produce.
 */
export function previewAuthorUrl(authorUrl: string, newUsername: string): string {
  const login = newUsername.trim();

  if (authorUrl === '' || login === '') {
    return authorUrl;
  }

  try {
    const url = new URL(authorUrl);
    const segments = url.pathname.split('/');
    let last = -1;

    for (let index = segments.length - 1; index >= 0; index -= 1) {
      if (segments[index] !== '') {
        last = index;
        break;
      }
    }

    if (last === -1) {
      return authorUrl;
    }

    segments[last] = encodeURIComponent(login);
    url.pathname = segments.join('/');

    return url.toString();
  } catch {
    return authorUrl;
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
