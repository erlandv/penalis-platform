import { describe, expect, it } from 'vitest';

import type { DashboardNotification } from '../../lib/types';
import {
  countForView,
  filterNotifications,
  groupNotificationsByArticle,
  selectNotifications,
} from './query';

function notification(overrides: Partial<DashboardNotification>): DashboardNotification {
  return {
    id: 'notif_1',
    type: 'submit',
    typeLabel: 'Submit',
    articleId: 1,
    articleTitle: 'Karya Satu',
    message: 'Pesan',
    feedback: '',
    createdAt: 1_700_000_000,
    readAt: 0,
    isRead: false,
    targetView: 'editor',
    targetUrl: '',
    reviewUrl: '',
    ...overrides,
  };
}

describe('notification query helpers', () => {
  it('returns every item for the all filter', () => {
    const items = [notification({ id: 'a' }), notification({ id: 'b', isRead: true })];

    expect(filterNotifications(items, 'all')).toHaveLength(2);
  });

  it('keeps only unread items for the unread filter', () => {
    const items = [
      notification({ id: 'a' }),
      notification({ id: 'b', isRead: true }),
      notification({ id: 'c' }),
    ];

    expect(filterNotifications(items, 'unread').map((item) => item.id)).toEqual(['a', 'c']);
  });

  it('keeps only read items for the read filter', () => {
    const items = [
      notification({ id: 'a' }),
      notification({ id: 'b', isRead: true }),
      notification({ id: 'c', isRead: true }),
    ];

    expect(filterNotifications(items, 'read').map((item) => item.id)).toEqual(['b', 'c']);
  });

  it('shows unread first and hides history by default', () => {
    const items = [
      notification({ id: 'read_new', isRead: true, createdAt: 300 }),
      notification({ id: 'unread_old', createdAt: 100 }),
      notification({ id: 'read_old', isRead: true, createdAt: 200 }),
      notification({ id: 'unread_new', createdAt: 400 }),
    ];

    expect(selectNotifications(items, false, 20).map((item) => item.id)).toEqual([
      'unread_old',
      'unread_new',
    ]);
    expect(selectNotifications(items, true, 20).map((item) => item.id)).toEqual([
      'unread_old',
      'unread_new',
      'read_new',
      'read_old',
    ]);
  });

  it('caps the visible feed at the requested limit', () => {
    const items = Array.from({ length: 8 }, (_, index) =>
      notification({ id: `n${index}`, createdAt: index }),
    );

    expect(selectNotifications(items, false, 3).map((item) => item.id)).toEqual([
      'n0',
      'n1',
      'n2',
    ]);
    expect(countForView(items, false)).toBe(8);
  });

  it('counts only unread entries while history is hidden', () => {
    const items = [
      notification({ id: 'a' }),
      notification({ id: 'b', isRead: true }),
      notification({ id: 'c' }),
    ];

    expect(countForView(items, false)).toBe(2);
    expect(countForView(items, true)).toBe(3);
  });

  it('groups by article while preserving newest-first order', () => {
    const items = [
      notification({ id: 'a1', articleId: 7, articleTitle: 'Tujuh' }),
      notification({ id: 'b1', articleId: 9, articleTitle: 'Sembilan' }),
      notification({ id: 'a2', articleId: 7, articleTitle: 'Tujuh' }),
    ];

    const groups = groupNotificationsByArticle(items);

    expect(groups.map((group) => group.articleId)).toEqual([7, 9]);
    expect(groups[0]?.items.map((item) => item.id)).toEqual(['a1', 'a2']);
    expect(groups[0]?.articleTitle).toBe('Tujuh');
  });

  it('returns no groups for an empty list', () => {
    expect(groupNotificationsByArticle([])).toEqual([]);
  });
});
