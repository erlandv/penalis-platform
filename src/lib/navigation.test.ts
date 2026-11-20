import { describe, expect, it } from 'vitest';

import { isViewName, navGroupsForRole } from './navigation';

describe('navGroupsForRole', () => {
  it('gives staff a curation group and no writer entries', () => {
    for (const role of ['administrator', 'editor'] as const) {
      const groups = navGroupsForRole(role);
      const views = groups.flatMap((group) => group.items.map((item) => item.view));

      expect(views).toEqual(['overview', 'review', 'notifications', 'settings']);
      expect(views).not.toContain('editor');
      expect(views).not.toContain('articles');
      expect(views).not.toContain('media');
      expect(views).not.toContain('profile');
    }
  });

  it('gives writers the writing group and no review entry', () => {
    const groups = navGroupsForRole('writer');
    const views = groups.flatMap((group) => group.items.map((item) => item.view));

    expect(views).toEqual(['overview', 'articles', 'media', 'notifications', 'profile', 'settings']);
    expect(views).not.toContain('review');
    // Writing is an action, not a nav destination.
    expect(views).not.toContain('editor');
  });

  it('limits pending and suspended accounts to status screens', () => {
    for (const role of ['pending', 'suspended'] as const) {
      const views = navGroupsForRole(role).flatMap((group) => group.items.map((item) => item.view));
      expect(views).toEqual(['overview', 'settings']);
    }
  });

  it('labels every group', () => {
    for (const role of ['administrator', 'editor', 'writer', 'pending', 'suspended'] as const) {
      for (const group of navGroupsForRole(role)) {
        expect(group.label.length).toBeGreaterThan(0);
        expect(group.items.length).toBeGreaterThan(0);
      }
    }
  });
});

describe('isViewName', () => {
  it('accepts known views', () => {
    expect(isViewName('overview')).toBe(true);
    expect(isViewName('review')).toBe(true);
    expect(isViewName('editor')).toBe(true);
  });

  it('rejects unknown or missing values', () => {
    expect(isViewName('nope')).toBe(false);
    expect(isViewName('')).toBe(false);
    expect(isViewName(null)).toBe(false);
    expect(isViewName(undefined)).toBe(false);
  });
});
