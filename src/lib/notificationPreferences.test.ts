import { describe, expect, it } from 'vitest';

import {
  normalizeNotificationPreferences,
  NOTIFICATION_ACTIONS,
  NOTIFICATION_ACTION_LABELS,
} from './notificationPreferences';

describe('notification preference helpers', () => {
  it('fills a missing payload with every channel enabled', () => {
    const preferences = normalizeNotificationPreferences(undefined);

    expect(NOTIFICATION_ACTIONS).toHaveLength(6);
    expect(preferences.dashboard.submit).toBe(true);
    expect(preferences.email.approve).toBe(true);
    expect(Object.keys(preferences.email)).toEqual(NOTIFICATION_ACTIONS);
  });

  it('keeps explicit opt-outs and fills the rest of the matrix', () => {
    const preferences = normalizeNotificationPreferences({
      dashboard: { submit: false },
      email: { reject: false },
    });

    expect(preferences.dashboard.submit).toBe(false);
    expect(preferences.dashboard.resubmit).toBe(true);
    expect(preferences.email.reject).toBe(false);
    expect(preferences.email.approve).toBe(true);
  });

  it('labels every action for the settings table', () => {
    for (const action of NOTIFICATION_ACTIONS) {
      expect(NOTIFICATION_ACTION_LABELS[action].length).toBeGreaterThan(0);
    }
  });
});
