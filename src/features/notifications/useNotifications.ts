/**
 * Shared notification state for the dashboard.
 *
 * The header bell, the notifications page, and the overview panel all read the
 * same list, so the unread badge can never disagree with the feed. The server
 * returns the full list after a read receipt, so an optimistic update is always
 * reconciled against the authoritative response.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { ApiError, notificationsApi } from '../../lib/api';
import type { NotificationList } from '../../lib/types';

export interface NotificationsController {
  data: NotificationList | null;
  loading: boolean;
  error: string;
  /** Notification ID being marked, `'all'` for a bulk receipt, or `null`. */
  markingId: string | null;
  unreadCount: number;
  load: () => Promise<void>;
  markRead: (id?: string) => Promise<void>;
}

export function useNotifications({
  enabled,
  initialUnreadCount = 0,
  onUnreadChange,
}: {
  enabled: boolean;
  /** Count from the account payload, shown until the list finishes loading. */
  initialUnreadCount?: number;
  onUnreadChange?: (unreadCount: number) => void;
}): NotificationsController {
  const [data, setData] = useState<NotificationList | null>(null);
  const [loading, setLoading] = useState(enabled);
  const [error, setError] = useState('');
  const [markingId, setMarkingId] = useState<string | null>(null);

  const onUnreadChangeRef = useRef(onUnreadChange);
  onUnreadChangeRef.current = onUnreadChange;

  const load = useCallback(async () => {
    if (!enabled) {
      return;
    }

    setLoading(true);
    setError('');

    try {
      const result = await notificationsApi.list();
      setData(result);
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : 'Notifikasi kamu tidak dapat dimuat.');
    } finally {
      setLoading(false);
    }
  }, [enabled]);

  useEffect(() => {
    void load();
  }, [load]);

  /*
   * Refresh when the tab becomes visible again instead of polling on a timer.
   * `count_unread()` re-checks every stored entry against its current post, so
   * an interval would repeat that work for a writer who is not even looking.
   */
  useEffect(() => {
    if (!enabled) {
      return;
    }

    const refresh = () => {
      if (document.visibilityState === 'visible') {
        void load();
      }
    };

    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', refresh);

    return () => {
      window.removeEventListener('focus', refresh);
      document.removeEventListener('visibilitychange', refresh);
    };
  }, [enabled, load]);

  /*
   * The account payload carries `unreadNotifications`, so the badge is correct
   * on first paint instead of flashing zero until the list arrives. Every later
   * change is reported back to the account from the authoritative list.
   */
  useEffect(() => {
    if (data) {
      onUnreadChangeRef.current?.(data.unreadCount);
    }
  }, [data]);

  const markRead = useCallback(
    async (id?: string) => {
      if (!enabled) {
        return;
      }

      setMarkingId(id ?? 'all');
      setError('');

      // Optimistic: flip the receipt locally so the badge reacts immediately.
      setData((previous) => {
        if (!previous) {
          return previous;
        }

        const items = previous.items.map((item) =>
          !id || item.id === id
            ? { ...item, isRead: true, readAt: item.readAt || Math.floor(Date.now() / 1000) }
            : item,
        );

        return { items, unreadCount: items.filter((item) => !item.isRead).length };
      });

      try {
        /*
         * The response is authoritative. `mark_read()` is fail-closed behind a
         * per-user advisory lock: when the lock is busy the stored list is left
         * untouched while the endpoint still answers 200. The optimistic state
         * must therefore be replaced by the response, never merely assumed.
         */
        const updated = await notificationsApi.markRead(id);
        setData(updated);
      } catch (caught) {
        setError(caught instanceof ApiError ? caught.message : 'Status notifikasi tidak dapat diperbarui.');
        await load();
      } finally {
        setMarkingId(null);
      }
    },
    [enabled, load],
  );

  return {
    data,
    loading,
    error,
    markingId,
    unreadCount: data?.unreadCount ?? initialUnreadCount,
    load,
    markRead,
  };
}
