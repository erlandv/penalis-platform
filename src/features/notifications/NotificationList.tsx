/**
 * Presentational notification feed.
 *
 * Shared by the header bell, the overview panel, and the notifications page so
 * the row markup, the unread styling, and the available actions stay identical
 * everywhere. The caller owns the data and the read receipts.
 */

import type { Account, DashboardNotification } from '../../lib/types';
import { formatDateTime } from '../../lib/format';
import { canReview } from '../../lib/permissions';
import { StatusBadge } from '../../components/ui';

export function NotificationList({
  items,
  account,
  markingId,
  onMarkRead,
  onOpenArticle,
  compact = false,
  showAdminLink = true,
}: {
  items: DashboardNotification[];
  account: Account;
  markingId: string | null;
  onMarkRead: (id: string) => void;
  onOpenArticle: (item: DashboardNotification) => void;
  /** Preview mode: clamped message, no per-row actions, whole row opens the article. */
  compact?: boolean;
  /**
   * Whether to keep the wp-admin review link alongside the SPA action.
   * Disabled in the header bell dropdown so reviewers stay inside the dashboard.
   */
  showAdminLink?: boolean;
}) {
  return (
    <ul className={`penalis-notification-list ${compact ? 'is-compact' : ''}`}>
      {items.map((item) => {
        const isReviewNotification =
          canReview(account) &&
          (!account.canWrite || item.type === 'submit' || item.type === 'resubmit');

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
                {isReviewNotification ? (
                  <button
                    type="button"
                    className="penalis-notification-action penalis-notification-action--primary"
                    onClick={() => onOpenArticle(item)}
                  >
                    Buka antrean review
                  </button>
                ) : account.canWrite ? (
                  <button
                    type="button"
                    className="penalis-notification-action penalis-notification-action--primary"
                    onClick={() => onOpenArticle(item)}
                  >
                    Buka karya
                  </button>
                ) : null}

                {showAdminLink && item.reviewUrl ? (
                  <a
                    className={`penalis-notification-action ${
                      isReviewNotification || account.canWrite ? '' : 'penalis-notification-action--primary'
                    }`.trim()}
                    href={item.reviewUrl}
                  >
                    Review di WP Admin
                  </a>
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
