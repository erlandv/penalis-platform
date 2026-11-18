/**
 * Pure helpers for notification preference forms.
 *
 * The server treats preferences as opt-out: an action missing from the payload
 * stays enabled. The UI therefore normalizes a partial account payload into a
 * complete matrix before rendering checkboxes, so an older account cannot
 * appear to have silently disabled every channel.
 */

import type {
  EditorialAction,
  NotificationChannelPreferences,
  NotificationPreferences,
} from './types';

export const NOTIFICATION_ACTIONS: EditorialAction[] = [
  'submit',
  'resubmit',
  'request_changes',
  'reject',
  'reopen',
  'approve',
];

export const NOTIFICATION_ACTION_LABELS: Record<EditorialAction, string> = {
  submit: 'Karya di-submit untuk review',
  resubmit: 'Karya di-submit ulang',
  request_changes: 'Reviewer meminta revisi',
  reject: 'Karya ditolak reviewer',
  reopen: 'Karya dibuka kembali',
  approve: 'Karya disetujui dan terbit',
};

function allEnabled(): Record<EditorialAction, boolean> {
  return {
    submit: true,
    resubmit: true,
    request_changes: true,
    reject: true,
    reopen: true,
    approve: true,
  };
}

/** Fill missing channels and actions with the enabled default. */
export function normalizeNotificationPreferences(
  value?: Partial<Record<keyof NotificationPreferences, Partial<NotificationChannelPreferences>>>,
): NotificationPreferences {
  return {
    dashboard: { ...allEnabled(), ...(value?.dashboard ?? {}) },
    email: { ...allEnabled(), ...(value?.email ?? {}) },
  };
}
