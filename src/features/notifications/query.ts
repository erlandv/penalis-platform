/**
 * Pure helpers for the notifications feed.
 *
 * Filtering and grouping are kept out of the component so they can be unit
 * tested without a DOM, matching the `src/authors/query.ts` convention.
 */

import type { DashboardNotification } from '../../lib/types';

export type NotificationFilter = 'all' | 'unread' | 'read';

/** Number of notifications rendered per page of the full feed. */
export const NOTIFICATION_PAGE_SIZE = 20;

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
  if (filter === 'unread') {
    return items.filter((item) => !item.isRead);
  }

  if (filter === 'read') {
    return items.filter((item) => item.isRead);
  }

  return items;
}

/**
 * Build the feed shown on the notifications page.
 *
 * Unread entries always come first so the actionable items stay above the
 * fold; read history is appended only when the reader opts in. The server
 * already returns newest-first, so filtering preserves recency inside each
 * group. Capping at `limit` keeps a bounded 50-entry list from turning into a
 * single very long page — the caller reveals more with an explicit action.
 */
export function selectNotifications(
  items: DashboardNotification[],
  showHistory: boolean,
  limit: number,
): DashboardNotification[] {
  const unread = filterNotifications(items, 'unread');
  if (!showHistory) {
    return unread.slice(0, limit);
  }

  const read = filterNotifications(items, 'read');
  return [...unread, ...read].slice(0, limit);
}

/** Total entries the current view can reveal before the cap. */
export function countForView(items: DashboardNotification[], showHistory: boolean): number {
  return showHistory ? items.length : filterNotifications(items, 'unread').length;
}

/**
 * Select a bounded feed for the explicit all/unread/read filter.
 *
 * `all` keeps actionable unread entries above read history; `read` and
 * `unread` show only that slice, newest-first as delivered by the server.
 */
export function selectNotificationsByFilter(
  items: DashboardNotification[],
  filter: NotificationFilter,
  limit: number,
): DashboardNotification[] {
  if (filter === 'all') {
    return selectNotifications(items, true, limit);
  }

  return filterNotifications(items, filter).slice(0, limit);
}

/** Number of entries an explicit filter can reveal. */
export function countForNotificationsFilter(
  items: DashboardNotification[],
  filter: NotificationFilter,
): number {
  return filter === 'all' ? items.length : filterNotifications(items, filter).length;
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
