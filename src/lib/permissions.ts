/**
 * Shared capability predicates for dashboard views.
 *
 * Keeping these rules in one place matters because the navigation, the
 * notification hook, and the overview panel all need to agree on who may see
 * editorial notifications.
 */

import type { Account } from './types';

/**
 * Editorial notifications are delivered only to writers and reviewers.
 * Pending and suspended accounts must not see an always-empty bell or page.
 */
export function canReceiveNotifications(
  account: Pick<Account, 'canWrite' | 'isAdministrator' | 'isReviewer'>,
): boolean {
  return account.canWrite || account.isAdministrator || account.isReviewer;
}
