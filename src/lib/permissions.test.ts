import { describe, expect, it } from 'vitest';

import {
  canAccessView,
  canReceiveNotifications,
  canReview,
  isStaffRole,
  resolveDashboardRole,
  resolveEffectiveView,
  roleLabel,
} from './permissions';
import type { RoleSource } from './permissions';

function source(overrides: Partial<RoleSource> = {}): RoleSource {
  return {
    isAdministrator: false,
    isReviewer: false,
    canWrite: false,
    status: 'active',
    ...overrides,
  };
}

describe('resolveDashboardRole', () => {
  it('prefers the server-resolved role', () => {
    expect(resolveDashboardRole(source({ role: 'editor', isReviewer: true }))).toBe('editor');
    expect(resolveDashboardRole(source({ role: 'writer', canWrite: true }))).toBe('writer');
  });

  it('treats an administrator as staff', () => {
    expect(resolveDashboardRole(source({ isAdministrator: true, isReviewer: true }))).toBe('administrator');
  });

  it('treats an editor as staff', () => {
    expect(resolveDashboardRole(source({ isReviewer: true }))).toBe('editor');
  });

  it('treats a staff account that also carries writer access as staff', () => {
    // Penalis policy forbids the hybrid case; the UI must not open the writer
    // experience just because a writer role was attached by mistake.
    expect(resolveDashboardRole(source({ isReviewer: true, canWrite: true }))).toBe('editor');
    expect(
      resolveDashboardRole(source({ isAdministrator: true, isReviewer: true, canWrite: true })),
    ).toBe('administrator');
  });

  it('resolves writers, pending, and suspended accounts', () => {
    expect(resolveDashboardRole(source({ canWrite: true }))).toBe('writer');
    expect(resolveDashboardRole(source({ status: 'pending_verification' }))).toBe('pending');
    expect(resolveDashboardRole(source({ status: 'suspended' }))).toBe('suspended');
  });
});

describe('isStaffRole', () => {
  it('covers administrators and editors only', () => {
    expect(isStaffRole('administrator')).toBe(true);
    expect(isStaffRole('editor')).toBe(true);
    expect(isStaffRole('writer')).toBe(false);
    expect(isStaffRole('pending')).toBe(false);
    expect(isStaffRole('suspended')).toBe(false);
  });
});

describe('roleLabel', () => {
  it('labels every role', () => {
    expect(roleLabel('administrator')).toBe('Administrator');
    expect(roleLabel('editor')).toBe('Editor');
    expect(roleLabel('writer')).toBe('Penulis');
    expect(roleLabel('pending')).toBe('Menunggu verifikasi');
    expect(roleLabel('suspended')).toBe('Ditangguhkan');
  });
});

describe('canAccessView', () => {
  it('gives staff the curation views and settings only', () => {
    for (const role of ['administrator', 'editor'] as const) {
      expect(canAccessView(role, 'overview')).toBe(true);
      expect(canAccessView(role, 'review')).toBe(true);
      expect(canAccessView(role, 'quotes')).toBe(true);
      expect(canAccessView(role, 'notifications')).toBe(true);
      expect(canAccessView(role, 'settings')).toBe(true);
      expect(canAccessView(role, 'articles')).toBe(false);
      expect(canAccessView(role, 'editor')).toBe(false);
      expect(canAccessView(role, 'media')).toBe(false);
      expect(canAccessView(role, 'profile')).toBe(false);
    }
  });

  it('gives writers the writing views and no review queue', () => {
    expect(canAccessView('writer', 'articles')).toBe(true);
    expect(canAccessView('writer', 'editor')).toBe(true);
    expect(canAccessView('writer', 'media')).toBe(true);
    expect(canAccessView('writer', 'profile')).toBe(true);
    expect(canAccessView('writer', 'notifications')).toBe(true);
    expect(canAccessView('writer', 'review')).toBe(false);
    expect(canAccessView('writer', 'quotes')).toBe(false);
  });

  it('limits pending and suspended accounts to status screens', () => {
    for (const role of ['pending', 'suspended'] as const) {
      expect(canAccessView(role, 'overview')).toBe(true);
      expect(canAccessView(role, 'settings')).toBe(true);
      expect(canAccessView(role, 'notifications')).toBe(false);
      expect(canAccessView(role, 'articles')).toBe(false);
      expect(canAccessView(role, 'editor')).toBe(false);
      expect(canAccessView(role, 'media')).toBe(false);
      expect(canAccessView(role, 'profile')).toBe(false);
      expect(canAccessView(role, 'review')).toBe(false);
      expect(canAccessView(role, 'quotes')).toBe(false);
    }
  });
});

describe('resolveEffectiveView', () => {
  it('keeps an allowed view', () => {
    expect(resolveEffectiveView('writer', 'editor')).toBe('editor');
    expect(resolveEffectiveView('editor', 'review')).toBe('review');
  });

  it('falls back to the overview for a view the role cannot open', () => {
    expect(resolveEffectiveView('editor', 'editor')).toBe('overview');
    expect(resolveEffectiveView('writer', 'review')).toBe('overview');
    expect(resolveEffectiveView('pending', 'articles')).toBe('overview');
  });
});

describe('canReceiveNotifications', () => {
  it('allows active writers', () => {
    expect(canReceiveNotifications(source({ canWrite: true }))).toBe(true);
  });

  it('allows administrators and editors without writer capabilities', () => {
    expect(canReceiveNotifications(source({ isAdministrator: true, isReviewer: true }))).toBe(true);
    expect(canReceiveNotifications(source({ isReviewer: true }))).toBe(true);
  });

  it('denies accounts that can neither write nor review', () => {
    expect(canReceiveNotifications(source({ status: 'pending_verification' }))).toBe(false);
    expect(canReceiveNotifications(source({ status: 'suspended' }))).toBe(false);
  });
});

describe('canReview', () => {
  it('allows administrators and editors', () => {
    expect(canReview(source({ isAdministrator: true, isReviewer: true }))).toBe(true);
    expect(canReview(source({ isReviewer: true }))).toBe(true);
  });

  it('denies writers and plain accounts', () => {
    expect(canReview(source({ canWrite: true }))).toBe(false);
    expect(canReview(source({ status: 'pending_verification' }))).toBe(false);
  });
});
