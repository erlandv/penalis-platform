/**
 * Autosave timing and content thresholds.
 *
 * Kept as pure functions so the schedule can be reasoned about and tested
 * without a browser; the editor only wires the returned delay into a timeout.
 *
 * Autosave writes to the draft post on the server. It deliberately does not
 * touch localStorage, sessionStorage, or IndexedDB: a manuscript belongs on the
 * server, behind the session, not on whatever device happens to be at hand.
 */

import { plainTextLength } from './html';

/** Save this long after the writer stops typing. */
export const AUTOSAVE_IDLE_MS = 4_000;

/**
 * Upper bound on how long unsaved work may sit on the server.
 *
 * Matches WordPress's documented `AUTOSAVE_INTERVAL` default of 60 seconds
 * (`wp-includes/default-constants.php` defines it as `MINUTE_IN_SECONDS`), so
 * the dashboard behaves like the rest of WordPress and an administrator only
 * has one number to reason about.
 */
export const AUTOSAVE_INTERVAL_MS = 60_000;

/**
 * Floor on the gap between two automatic writes.
 *
 * The interval alone is a maximum age, not a minimum spacing: with only the
 * idle window, a writer who types in short bursts would trigger a save every
 * few seconds and the 60-second figure would never describe reality. Three
 * writes a minute keeps the loss window small without turning one article into
 * hundreds of stored revisions.
 */
export const AUTOSAVE_MIN_GAP_MS = 20_000;

/** How long to wait before retrying an autosave that failed for another reason. */
export const AUTOSAVE_RETRY_MS = 30_000;

/** Visible characters that make an untitled draft worth creating on the server. */
export const AUTOSAVE_MIN_CHARS = 40;

/**
 * How long to wait before the next autosave attempt.
 *
 * Three clocks compete. The idle window keeps the save close to the moment the
 * writer pauses; the minimum gap stops a bursty writer from writing every few
 * seconds; the interval caps how much work can be lost when they never pause.
 *
 * The idle window can shorten the wait, the gap and the interval can only
 * lengthen it, and a failed attempt supplies `retryNotBefore`, which can only
 * push the next attempt further out.
 *
 * @param input Clock readings, all in milliseconds since the epoch.
 * @return Delay in milliseconds, never negative.
 */
export function autosaveDelay(input: {
  now: number;
  /** When the current batch of unsaved changes started. */
  dirtySince: number;
  /** When the last successful save finished, or `0` when there was none. */
  lastSaveAt: number;
  /** Earliest time a retry may run after a failure, or `0`. */
  retryNotBefore?: number;
}): number {
  const anchor = input.lastSaveAt > 0 ? input.lastSaveAt : input.dirtySince;

  // Without an anchor there is no schedule to respect; fall back to the idle
  // window instead of saving on the first keystroke.
  const base =
    anchor > 0
      ? Math.max(0, Math.min(AUTOSAVE_IDLE_MS, AUTOSAVE_INTERVAL_MS - (input.now - anchor)))
      : AUTOSAVE_IDLE_MS;

  // Only a previous save can hold the next one back.
  const gap =
    input.lastSaveAt > 0
      ? Math.max(0, AUTOSAVE_MIN_GAP_MS - (input.now - input.lastSaveAt))
      : 0;

  const backoff = Math.max(0, (input.retryNotBefore ?? 0) - input.now);

  return Math.max(base, gap, backoff);
}

/**
 * Whether the editor holds enough content to be worth a server round trip.
 *
 * An existing draft always qualifies: the writer may be deleting text, and
 * that change must reach the server too. A brand-new article only qualifies
 * once it has a title or a short paragraph, so opening the editor and closing
 * it again does not litter the writer's draft list.
 */
export function hasAutosaveContent(input: {
  title: string;
  content: string;
  /** Whether the article already exists on the server. */
  hasDraft: boolean;
}): boolean {
  if (input.hasDraft) {
    return true;
  }

  if (input.title.trim().length >= 3) {
    return true;
  }

  return plainTextLength(input.content) >= AUTOSAVE_MIN_CHARS;
}
