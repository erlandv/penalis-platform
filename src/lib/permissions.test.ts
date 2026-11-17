import { describe, expect, it } from 'vitest';

import { canReceiveNotifications } from './permissions';

describe('canReceiveNotifications', () => {
  it('allows active writers', () => {
    expect(canReceiveNotifications({ canWrite: true, isAdministrator: false, isReviewer: false })).toBe(true);
  });

  it('allows administrators and reviewers without writer capabilities', () => {
    expect(canReceiveNotifications({ canWrite: false, isAdministrator: true, isReviewer: true })).toBe(true);
    expect(canReceiveNotifications({ canWrite: false, isAdministrator: false, isReviewer: true })).toBe(true);
  });

  it('denies accounts that can neither write nor review', () => {
    expect(canReceiveNotifications({ canWrite: false, isAdministrator: false, isReviewer: false })).toBe(false);
  });
});
