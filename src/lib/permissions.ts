/**
 * Role model and capability predicates for dashboard views.
 *
 * The dashboard serves two mutually exclusive audiences: writers, who own
 * articles, and staff ("Senator" in user-facing copy — administrators and
 * editors), who curate but never write with their staff account. Penalis policy
 * forbids the hybrid case, so every branch in the SPA keys off one resolved
 * role instead of re-deriving the answer from three capability booleans in
 * three different files.
 *
 * `role` arrives from the server (`GET /account`, `role` field). The boolean
 * fallback exists only for pre-role payloads and for unit tests; the server
 * remains the authority and every guarded REST route re-checks for real.
 */

import type { AccountStatus, DashboardRole, ViewName } from './types';

/** The fields role resolution reads. */
export interface RoleSource {
  isAdministrator: boolean;
  isReviewer: boolean;
  canWrite: boolean;
  status?: AccountStatus;
  /** Server-resolved role; absent on payloads produced before the field existed. */
  role?: DashboardRole;
}

/**
 * Whether a role belongs to the curation side of the dashboard.
 *
 * Staff never gain writer views, even if a writer role was attached to their
 * account by mistake: `resolveDashboardRole()` checks this branch first.
 */
export function isStaffRole(role: DashboardRole): boolean {
  return role === 'administrator' || role === 'editor';
}

/**
 * Resolve the single role the dashboard renders.
 *
 * Staff is checked before writer on purpose. Penalis policy says an
 * administrator or editor never writes with the staff account, so an account
 * that somehow carries both capabilities must still be treated as staff rather
 * than silently opening the writer UI and violating the rule.
 *
 * @param account Account payload (or bootstrap fragment).
 * @return Resolved dashboard role.
 */
export function resolveDashboardRole(account: RoleSource): DashboardRole {
  if (account.role) {
    return account.role;
  }

  if (account.isReviewer) {
    return account.isAdministrator ? 'administrator' : 'editor';
  }

  if (account.canWrite) {
    return 'writer';
  }

  return account.status === 'suspended' ? 'suspended' : 'pending';
}

/** Human-readable role label used in chips and account menus. */
export function roleLabel(role: DashboardRole): string {
  switch (role) {
    case 'administrator':
      return 'Administrator';
    case 'editor':
      return 'Editor';
    case 'writer':
      return 'Penulis';
    case 'suspended':
      return 'Ditangguhkan';
    case 'pending':
    default:
      return 'Menunggu verifikasi';
  }
}

/**
 * Which dashboard views each role may open.
 *
 * This table is the only place view access is decided. `navigate()`, the
 * deep-link fallback, and the render switch all read it, so they cannot drift
 * apart the way three hand-written conditions did.
 *
 * A staff account has no article list, editor, media library, or public
 * profile: those screens describe a writer's own work, which staff do not
 * have. Their account information lives in Pengaturan.
 */
const VIEW_ACCESS: Record<ViewName, readonly DashboardRole[]> = {
  overview: ['administrator', 'editor', 'writer', 'pending', 'suspended'],
  notifications: ['administrator', 'editor', 'writer'],
  review: ['administrator', 'editor'],
  quotes: ['administrator', 'editor'],
  articles: ['writer'],
  editor: ['writer'],
  media: ['writer'],
  profile: ['writer'],
  settings: ['administrator', 'editor', 'writer', 'pending', 'suspended'],
};

/** Whether a role may open a view. */
export function canAccessView(role: DashboardRole, view: ViewName): boolean {
  return VIEW_ACCESS[view].includes(role);
}

/**
 * Clamp a requested view to one the role may actually render.
 *
 * Used for deep links and browser history: a staff account opening
 * `?view=editor` from a stale bookmark lands on Ringkasan instead of an empty
 * page, and the caller rewrites the URL to match.
 */
export function resolveEffectiveView(role: DashboardRole, view: ViewName): ViewName {
  return canAccessView(role, view) ? view : 'overview';
}

/**
 * Editorial notifications are delivered only to writers and staff.
 * Pending and suspended accounts must not see an always-empty bell or page.
 */
export function canReceiveNotifications(account: RoleSource): boolean {
  const role = resolveDashboardRole(account);
  return role === 'writer' || isStaffRole(role);
}

/**
 * Whether the account may work the review queue.
 *
 * Staff do not need writer capabilities: an administrator or editor without
 * the writer role still curates the site. This is the client mirror of
 * `Penalis_Editorial_Workflow::can_review()`; the server decides for real.
 */
export function canReview(account: RoleSource): boolean {
  return isStaffRole(resolveDashboardRole(account));
}
