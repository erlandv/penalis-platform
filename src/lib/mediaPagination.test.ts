import { describe, expect, it } from 'vitest';
import type { MediaItem, MediaList } from './types';
import {
  appendUniqueMedia,
  computeTotalPages,
  prependUploadedMedia,
  removeDeletedMedia,
} from './mediaPagination';

function makeItem(id: number): MediaItem {
  return {
    id,
    title: `Image ${id}`,
    mime: 'image/jpeg',
    url: `https://example.com/${id}.jpg`,
    thumbnail: `https://example.com/${id}-thumb.jpg`,
    full: `https://example.com/${id}.jpg`,
    date: '2026-11-22 10:00:00',
  };
}

describe('computeTotalPages', () => {
  it('returns at least 1 page and rounds up across page boundaries', () => {
    expect(computeTotalPages(0, 20)).toBe(1);
    expect(computeTotalPages(1, 20)).toBe(1);
    expect(computeTotalPages(20, 20)).toBe(1);
    expect(computeTotalPages(21, 20)).toBe(2);
    expect(computeTotalPages(40, 20)).toBe(2);
    expect(computeTotalPages(41, 20)).toBe(3);
  });
});

describe('prependUploadedMedia', () => {
  it('caps page 1 at perPage and increments totalPages across the 20 -> 21 boundary', () => {
    const initial: MediaList = {
      items: Array.from({ length: 20 }, (_, index) => makeItem(20 - index)),
      total: 20,
      page: 1,
      perPage: 20,
      totalPages: 1,
    };

    const next = prependUploadedMedia(initial, makeItem(21));

    expect(next.items).toHaveLength(20);
    expect(next.items[0]?.id).toBe(21);
    expect(next.items[19]?.id).toBe(2);
    expect(next.total).toBe(21);
    expect(next.totalPages).toBe(2);
  });

  it('does not double-count if the same attachment ID is already present', () => {
    const initial: MediaList = {
      items: [makeItem(5), makeItem(4)],
      total: 2,
      page: 1,
      perPage: 20,
      totalPages: 1,
    };

    const next = prependUploadedMedia(initial, makeItem(5));
    expect(next.total).toBe(2);
    expect(next.items.map((item) => item.id)).toEqual([5, 4]);
  });
});

describe('removeDeletedMedia', () => {
  it('updates total and recomputes totalPages when crossing a boundary downward', () => {
    const initial: MediaList = {
      items: [makeItem(1)],
      total: 21,
      page: 2,
      perPage: 20,
      totalPages: 2,
    };

    const next = removeDeletedMedia(initial, 1);

    expect(next?.items).toEqual([]);
    expect(next?.total).toBe(20);
    expect(next?.totalPages).toBe(1);
  });
});

describe('appendUniqueMedia', () => {
  it('appends new items while skipping duplicates shifted by an earlier upload', () => {
    const existing = [makeItem(25), makeItem(24), makeItem(23)];
    const incoming = [makeItem(23), makeItem(22), makeItem(21)];

    const merged = appendUniqueMedia(existing, incoming);
    expect(merged.map((item) => item.id)).toEqual([25, 24, 23, 22, 21]);
  });
});
