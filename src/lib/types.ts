/**
 * Types shared by the dashboard application.
 */

export type AccountStatus =
  | 'pending_verification'
  | 'active'
  | 'suspended'
  | '';

export type DisplayNameMode = 'auto' | 'first' | 'last' | 'username' | 'custom';

/**
 * The audience the dashboard renders for.
 *
 * `administrator` and `editor` are the staff side — called **Senator** in
 * user-facing copy. Penalis policy is that a staff account never writes, so
 * these roles have no writer views at all. `pending` and `suspended` are writer
 * accounts that cannot write yet (or any more).
 *
 * The server resolves this in `Penalis_Policy::dashboard_role()`; the client
 * mirrors it in `resolveDashboardRole()` for payloads from older deployments.
 */
export type DashboardRole = 'administrator' | 'editor' | 'writer' | 'pending' | 'suspended';

export interface BootstrapUser {
  id: number;
  displayName: string;
  firstName?: string;
  lastName?: string;
  displayNameMode?: DisplayNameMode;
  avatarId?: number;
  avatarUrl?: string;
  authorUrl?: string;
  email: string;
  description?: string;
  instagram?: string;
}

export interface SiteTimezone {
  /** IANA name, or a fixed `+07:00` offset when the site uses `gmt_offset`. */
  string: string;
  /** Offset from UTC in seconds at the reference timestamp. */
  offsetSeconds: number;
  /** Short human label such as `WIB` or `UTC+07:00`. */
  label: string;
}

export type TurnstileFormKey = 'login' | 'register' | 'activate' | 'forgot' | 'reset';

export interface TurnstileConfig {
  enabled: boolean;
  siteKey?: string;
  theme?: 'auto' | 'light' | 'dark';
  size?: 'normal' | 'compact' | 'flexible';
  language?: string;
  appearance?: 'always' | 'execute' | 'interaction-only';
  forms?: Partial<Record<TurnstileFormKey, boolean>>;
}

export interface DashboardBootstrap {
  restUrl: string;
  wpRestUrl: string;
  nonce: string;
  isLoggedIn: boolean;
  /** Server-resolved role, so the shell can paint the right frame before `/account` lands. */
  role?: DashboardRole;
  status: AccountStatus;
  statusLabel: string;
  registration: boolean;
  turnstile?: TurnstileConfig;
  dashboardUrl: string;
  loginUrl: string;
  logoutUrl: string;
  lostPassword: string;
  homeUrl: string;
  adminUrl: string;
  /** wp-admin review screen, empty for accounts that cannot review. */
  reviewUrl: string;
  siteName: string;
  /**
   * Informational site timezone. Dashboard timestamps stay in the browser's
   * timezone; this only lets the UI explain which timezone the site uses.
   */
  siteTimezone?: SiteTimezone;
  user: BootstrapUser | null;
}

export interface AccountCounts {
  all: number;
  publish: number;
  draft: number;
  pending: number;
  changes_requested?: number;
  rejected?: number;
  views?: number;
}

export type EditorialState =
  | 'draft'
  | 'pending_review'
  | 'changes_requested'
  | 'rejected'
  | 'published';

export type EditorialAction =
  | 'submit'
  | 'resubmit'
  | 'request_changes'
  | 'reject'
  | 'reopen'
  | 'approve';

/**
 * Section of the editor a deep link should reveal on open.
 *
 * `feedback` targets the reviewer's revision notes so a notification about
 * requested changes lands on the section the writer must act on.
 */
export type EditorFocus = 'feedback' | null;

export interface ReviewHistoryEntry {
  action: EditorialAction | string;
  actionLabel: string;
  actorName: string;
  createdAt: string;
  reviewCycle: number;
  feedback: string;
}

export interface ArticleReview {
  id: number;
  status: string;
  editorialState: EditorialState;
  editorialStateLabel: string;
  canEdit: boolean;
  canSubmit: boolean;
  canResubmit: boolean;
  feedback: string;
  reviewCycle: number;
  submittedAt: string;
  reviewedAt: string;
  reviewHistory: ReviewHistoryEntry[];
  message?: string;
}

export interface DashboardNotification {
  id: string;
  type: EditorialAction | string;
  typeLabel: string;
  articleId: number;
  articleTitle: string;
  message: string;
  feedback: string;
  createdAt: number;
  readAt: number;
  isRead: boolean;
  targetView: 'editor';
  targetUrl: string;
  reviewUrl: string;
}

export interface NotificationList {
  items: DashboardNotification[];
  unreadCount: number;
}

/** Per-action opt-outs. A missing action is treated as enabled by the server. */
export type NotificationChannelPreferences = Record<EditorialAction, boolean>;

export interface NotificationPreferences {
  dashboard: NotificationChannelPreferences;
  email: NotificationChannelPreferences;
}

export interface Account {
  id: number;
  username: string;
  displayName: string;
  firstName: string;
  lastName: string;
  displayNameMode: DisplayNameMode;
  avatarId: number;
  avatarUrl: string;
  authorUrl: string;
  email: string;
  /** Address awaiting confirmation, or an empty string when none is pending. */
  pendingEmail: string;
  /** Whether this account may request an email change at all. */
  canChangeEmail: boolean;
  /** Whether this account may change its login (same eligibility as email). */
  canChangeUsername: boolean;
  description: string;
  instagram: string;
  status: AccountStatus;
  statusLabel: string;
  /** Authoritative dashboard role, resolved by the server. */
  role: DashboardRole;
  isAdministrator: boolean;
  /** Whether this account may review submissions (administrator or editor). */
  isReviewer: boolean;
  canWrite: boolean;
  /** Whether the dashboard API admits this account at all (client mirror of `require_logged_in()`). */
  canView: boolean;
  emailVerified: boolean;
  needsPassword: boolean;
  registeredAt: number;
  approvedAt: number;
  counts: AccountCounts;
  unreadNotifications?: number;
  notificationPreferences?: NotificationPreferences;
  registrationOn: boolean;
}

export interface ArticleSummary {
  id: number;
  title: string;
  excerpt: string;
  status: string;
  editorialState: EditorialState;
  editorialStateLabel: string;
  canEdit: boolean;
  canSubmit: boolean;
  canResubmit: boolean;
  feedback: string;
  reviewCycle: number;
  submittedAt: string;
  reviewedAt: string;
  date: string;
  modified: string;
  link: string;
  views: number;
  editUrl: string;
  thumbnail: string;
}

export interface ArticleList {
  items: ArticleSummary[];
  total: number;
  page: number;
  perPage: number;
  totalPages: number;
  counts: AccountCounts;
}

/** One article waiting in the reviewer queue. */
export interface ReviewQueueItem {
  id: number;
  title: string;
  excerpt: string;
  authorName: string;
  authorLogin: string;
  /** GMT `Y-m-d H:i:s`, empty when the article has never been submitted. */
  submittedAt: string;
  reviewCycle: number;
  editorialState: EditorialState;
  editorialStateLabel: string;
}

export interface ReviewQueueList {
  items: ReviewQueueItem[];
  total: number;
  page: number;
  perPage: number;
  totalPages: number;
}

/** Reviewer actions exposed by `POST /articles/{id}/decision`. */
export type ReviewDecisionAction = 'approve' | 'request_changes' | 'reject';

export interface ReviewDecisionResult {
  message: string;
  review: ArticleReview;
}

export interface ArticleTerm {
  id: number;
  name: string;
}

export interface Article {
  id: number;
  title: string;
  content: string;
  excerpt: string;
  authorName?: string;
  authorLogin?: string;
  status: string;
  editorialState: EditorialState;
  editorialStateLabel: string;
  canEdit: boolean;
  canSubmit: boolean;
  canResubmit: boolean;
  feedback: string;
  reviewCycle: number;
  submittedAt: string;
  reviewedAt: string;
  reviewHistory: ReviewHistoryEntry[];
  date: string;
  modified: string;
  link: string;
  views: number;
  featuredMedia: number;
  thumbnail?: string;
  categories: ArticleTerm[];
  tags: ArticleTerm[];
}

export interface MediaItem {
  id: number;
  title: string;
  mime: string;
  url: string;
  thumbnail: string;
  full: string;
  date: string;
}

export interface MediaList {
  items: MediaItem[];
  total: number;
  page: number;
  perPage: number;
  totalPages: number;
}

export interface Term {
  id: number;
  name: string;
  slug?: string;
  count?: number;
  parent?: number;
}

export interface FieldErrors {
  [field: string]: string;
}

/**
 * Response returned when a verification code is issued.
 *
 * The challenge ID is an opaque locator, not a secret: the six-digit code sent
 * by email is the proof. Both are kept in component memory only.
 */
export interface ChallengeResponse {
  challenge_id: string;
  resend_after: number;
  expires_in: number;
  max_attempts: number;
  message: string;
}

export interface ApiErrorShape {
  code?: string;
  message?: string;
  data?: {
    status?: number;
    fields?: FieldErrors;
    [key: string]: unknown;
  };
}

export type ViewName =
  | 'overview'
  | 'notifications'
  | 'articles'
  | 'editor'
  | 'profile'
  | 'settings'
  | 'media'
  | 'review';
