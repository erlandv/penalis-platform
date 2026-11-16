import { describe, expect, it } from 'vitest';

import type { DashboardNotification } from '../../lib/types';
import { filterNotifications, groupNotificationsByArticle } from './query';

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
