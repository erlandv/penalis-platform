/**
 * Pure helpers for the notifications feed.
 *
 * Filtering and grouping are kept out of the component so they can be unit
 * tested without a DOM, matching the `src/authors/query.ts` convention.
 */

import type { DashboardNotification } from '../../lib/types';

export type NotificationFilter = 'all' | 'unread';

export interface ArticleNotificationGroup {
  articleId: number;
  articleTitle: string;
  items: DashboardNotification[];
}

/**
 * Apply the all/unread filter.
 *
 * The server list is already newest-first, so the order is preserved.
 */
export function filterNotifications(
  items: DashboardNotification[],
  filter: NotificationFilter,
): DashboardNotification[] {
  return filter === 'unread' ? items.filter((item) => !item.isRead) : items;
}

/**
 * Group notifications by article, preserving the newest-first order of both
 * the groups and the entries inside each group.
 */
export function groupNotificationsByArticle(
  items: DashboardNotification[],
): ArticleNotificationGroup[] {
  const byArticle = new Map<number, ArticleNotificationGroup>();

  for (const item of items) {
    const existing = byArticle.get(item.articleId);
    if (existing) {
      existing.items.push(item);
    } else {
      byArticle.set(item.articleId, {
        articleId: item.articleId,
        articleTitle: item.articleTitle,
        items: [item],
      });
    }
  }

  return Array.from(byArticle.values());
}
