/**
 * Active-role chip for the dashboard header.
 *
 * The dashboard serves writers and staff ("Senator") from the same URL, so the
 * header must say which mode is on screen. Text carries the meaning — the chip
 * is never colour-only — and the icon is decorative. Staff roles get the
 * curation tint, writers the writing tint.
 *
 * Pending and suspended accounts render nothing here: their `StatusBanner`
 * already explains what is missing, and a chip that says "Menunggu verifikasi"
 * next to that banner would repeat it.
 */

import { roleLabel, isStaffRole } from '../lib/permissions';
import type { DashboardRole } from '../lib/types';
import { Icon } from './icons';

export function RoleChip({ role }: { role: DashboardRole }) {
  if (role === 'pending' || role === 'suspended') {
    return null;
  }

  const staff = isStaffRole(role);
  const label = roleLabel(role);
  const description = staff ? `Senator Penalis: ${label}` : 'Penulis Penalis';

  return (
    <span
      className={`penalis-role-chip ${staff ? 'penalis-role-chip--senator' : 'penalis-role-chip--writer'}`}
      title={description}
    >
      <Icon name={staff ? 'users' : 'user'} />
      <span>{staff ? `Senator · ${label}` : label}</span>
    </span>
  );
}
