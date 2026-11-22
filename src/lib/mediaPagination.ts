/**
 * Pure state helpers for paginated media lists and incremental picker loading.
 */

import type { MediaItem, MediaList } from './types';

/**
 * Recompute `totalPages` from item count and page size, keeping at least 1 page.
 */
export function computeTotalPages(total: number, perPage: number): number {
  const safePerPage = perPage > 0 ? perPage : 20;
  return Math.max(1, Math.ceil(Math.max(0, total) / safePerPage));
}

/**
 * Insert a newly uploaded item at the front of page 1, keeping the page capped
 * at `perPage` and updating `total` and `totalPages` consistently.
 */
export function prependUploadedMedia(current: MediaList | null, uploaded: MediaItem, defaultPerPage = 20): MediaList {
  if (!current) {
    return {
      items: [uploaded],
      total: 1,
      page: 1,
      perPage: defaultPerPage,
      totalPages: 1,
    };
  }

  const perPage = current.perPage > 0 ? current.perPage : defaultPerPage;
  const alreadyPresent = current.items.some((entry) => entry.id === uploaded.id);
  const nextItems = [uploaded, ...current.items.filter((entry) => entry.id !== uploaded.id)].slice(0, perPage);
  const nextTotal = alreadyPresent ? current.total : current.total + 1;

  return {
    ...current,
    items: nextItems,
    total: nextTotal,
    totalPages: computeTotalPages(nextTotal, perPage),
  };
}

/**
 * Remove a deleted item from the current (last) page and recompute `total` and
 * `totalPages`. Callers on an earlier page (`page < totalPages`) should reload
 * the page instead so the next page's first item fills the gap.
 */
export function removeDeletedMedia(current: MediaList | null, deletedId: number): MediaList | null {
  if (!current) {
    return null;
  }

  const existed = current.items.some((entry) => entry.id === deletedId);
  const nextItems = current.items.filter((entry) => entry.id !== deletedId);
  const nextTotal = existed ? Math.max(0, current.total - 1) : current.total;

  return {
    ...current,
    items: nextItems,
    total: nextTotal,
    totalPages: computeTotalPages(nextTotal, current.perPage),
  };
}

/**
 * Append a subsequent page of media items without introducing duplicate IDs
 * (which can occur when a new image was uploaded before loading the next page).
 */
export function appendUniqueMedia(existing: MediaItem[], incoming: MediaItem[]): MediaItem[] {
  if (incoming.length === 0) {
    return existing;
  }

  const seen = new Set(existing.map((entry) => entry.id));
  const fresh = incoming.filter((entry) => !seen.has(entry.id));

  return fresh.length === 0 ? existing : [...existing, ...fresh];
}
