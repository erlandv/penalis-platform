/**
 * Role-specific dashboard navigation.
 *
 * The nav is data, not a filtered list: a role gets the groups it can actually
 * use, and nothing is rendered disabled. A staff account never sees "Buat
 * Karya" or "Media" — a greyed-out door is still a door that will never open.
 *
 * Group labels are hidden on the mobile scroll strip (`display: contents` in
 * `responsive.css`), where the items read as one row of places.
 */

import type { IconName } from '../components/icons';
import { isStaffRole } from './permissions';
import type { DashboardRole, ViewName } from './types';

export interface NavItem {
  view: ViewName;
  label: string;
  icon: IconName;
}

export interface NavGroup {
  id: string;
  label: string;
  items: NavItem[];
}

const OVERVIEW: NavItem = { view: 'overview', label: 'Ringkasan', icon: 'layoutDashboard' };
const NOTIFICATIONS: NavItem = { view: 'notifications', label: 'Notifikasi', icon: 'bell' };
const REVIEW: NavItem = { view: 'review', label: 'Review Karya', icon: 'check' };
const ARTICLES: NavItem = { view: 'articles', label: 'Daftar Karya', icon: 'fileText' };
const MEDIA: NavItem = { view: 'media', label: 'Media', icon: 'image' };
const PROFILE: NavItem = { view: 'profile', label: 'Profil', icon: 'user' };
const SETTINGS: NavItem = { view: 'settings', label: 'Pengaturan', icon: 'settings' };

/**
 * Build the navigation for a role.
 *
 * Note there is no "Buat Karya" entry: writing is an action, not a place. The
 * primary button on Ringkasan and the articles screen opens the editor for a
 * writer, and staff simply have no such button.
 *
 * @param role Resolved dashboard role.
 * @return Ordered navigation groups.
 */
export function navGroupsForRole(role: DashboardRole): NavGroup[] {
  if (isStaffRole(role)) {
    return [
      {
        id: 'curation',
        label: 'Kurasi',
        items: [OVERVIEW, REVIEW, NOTIFICATIONS],
      },
      {
        id: 'account',
        label: 'Akun',
        items: [SETTINGS],
      },
    ];
  }

  if (role === 'writer') {
    return [
      {
        id: 'writing',
        label: 'Menulis',
        items: [OVERVIEW, ARTICLES, MEDIA],
      },
      {
        id: 'account',
        label: 'Akun',
        items: [NOTIFICATIONS, PROFILE, SETTINGS],
      },
    ];
  }

  // Pending verification and suspended accounts can only look at their status.
  return [
    {
      id: 'account',
      label: 'Akun',
      items: [OVERVIEW, SETTINGS],
    },
  ];
}

/** The known dashboard views, for validating a value that came from the URL. */
const VIEWS: readonly ViewName[] = [
  'overview',
  'notifications',
  'articles',
  'editor',
  'profile',
  'settings',
  'media',
  'review',
];

/** Whether a query-string value names a dashboard view. */
export function isViewName(value: string | null | undefined): value is ViewName {
  return typeof value === 'string' && (VIEWS as readonly string[]).includes(value);
}
