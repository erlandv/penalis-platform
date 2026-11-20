/**
 * Presentational notification feed.
 *
 * Shared by the header bell, the overview panel, and the notifications page so
 * the row markup, the unread styling, and the available actions stay identical
 * everywhere. The caller owns the data and the read receipts.
 */

import type { Account, DashboardNotification } from '../../lib/types';
import { formatDateTime } from '../../lib/format';
import { isStaffRole, resolveDashboardRole } from '../../lib/permissions';
import { StatusBadge } from '../../components/ui';

export function NotificationList({
  items,
  account,
  markingId,
  onMarkRead,
  onOpenArticle,
  compact = false,
}: {
  items: DashboardNotification[];
  account: Account;
  markingId: string | null;
  onMarkRead: (id: string) => void;
  onOpenArticle: (item: DashboardNotification) => void;
  /** Preview mode: clamped message, no per-row actions, whole row opens the article. */
  compact?: boolean;
}) {
  /*
   * Who is looking decides what a row offers. Both audiences land on the
   * article the notification is about: a writer opens their own draft in the
   * editor, a Senator opens that submission in the reviewer reader — the same
   * workspace "Baca karya" opens from the queue. The label says so, because
   * "Buka antrean review" promised a list and delivered a search.
   */
  const staff = isStaffRole(resolveDashboardRole(account));

  return (
    <ul className={`penalis-notification-list ${compact ? 'is-compact' : ''}`}>
      {items.map((item) => {

        return (
          <li
            key={item.id}
            className={`penalis-notification-item ${item.isRead ? '' : 'is-unread'}`}
            aria-label={`${item.isRead ? 'Sudah dibaca' : 'Belum dibaca'}: ${item.typeLabel}`}
          >
            <span className="penalis-notification-item__dot" aria-hidden="true" />

            {compact ? (
              <button
                type="button"
                className="penalis-notification-item__body penalis-notification-item__body--link"
                onClick={() => onOpenArticle(item)}
                aria-label={`Buka karya: ${item.articleTitle || 'Tanpa judul'}`}
              >
                <span className="penalis-notification-item__head">
                  <StatusBadge status={item.type} label={item.typeLabel} />
                  <span className="penalis-notification-item__meta">{formatDateTime(item.createdAt)}</span>
                </span>
                <span className="penalis-notification-item__message">{item.message}</span>
              </button>
            ) : (
              <div className="penalis-notification-item__body">
                <div className="penalis-notification-item__head">
                  <StatusBadge status={item.type} label={item.typeLabel} />
                  <span className="penalis-notification-item__meta">{formatDateTime(item.createdAt)}</span>
                </div>

                <p className="penalis-notification-item__message">{item.message}</p>
              </div>
            )}

            {compact ? null : (
              <div className="penalis-notification-item__actions">
                {/*
                 * Every editorial notification is about one article, and the
                 * server rejects an entry with `article_id = 0`. The guard is
                 * future-proofing: if a non-article notification is ever added,
                 * its row must not offer a "Baca karya" button that opens an
                 * empty reader.
                 */}
                {item.articleId > 0 ? (
                  <button
                    type="button"
                    className="penalis-notification-action penalis-notification-action--primary"
                    onClick={() => onOpenArticle(item)}
                  >
                    {staff ? 'Baca karya' : 'Buka karya'}
                  </button>
                ) : null}

                {!item.isRead ? (
                  <button
                    type="button"
                    className="penalis-notification-action"
                    onClick={() => onMarkRead(item.id)}
                    disabled={markingId !== null}
                  >
                    {markingId === item.id ? 'Menandai…' : 'Tandai dibaca'}
                  </button>
                ) : null}
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}
