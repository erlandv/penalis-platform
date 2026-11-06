/**
 * Types shared by the dashboard application.
 */

export type AccountStatus =
  | 'pending_verification'
  | 'pending_approval'
  | 'active'
  | 'rejected'
  | 'suspended'
  | '';

export type DisplayNameMode = 'auto' | 'first' | 'last' | 'username' | 'custom';

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
}

export interface DashboardBootstrap {
  restUrl: string;
  wpRestUrl: string;
  nonce: string;
  isLoggedIn: boolean;
  canWrite: boolean;
  status: AccountStatus;
  statusLabel: string;
  registration: boolean;
  dashboardUrl: string;
  loginUrl: string;
  logoutUrl: string;
  lostPassword: string;
  homeUrl: string;
  adminUrl: string;
  siteName: string;
  user: BootstrapUser | null;
}

export interface AccountCounts {
  all: number;
  publish: number;
  draft: number;
  pending: number;
  changes_requested?: number;
  rejected?: number;
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
  status: AccountStatus;
  statusLabel: string;
  isAdministrator: boolean;
  canWrite: boolean;
  canView: boolean;
  emailVerified: boolean;
  needsPassword: boolean;
  registeredAt: number;
  approvedAt: number;
  counts: AccountCounts;
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

export interface ArticleTerm {
  id: number;
  name: string;
}

export interface Article {
  id: number;
  title: string;
  content: string;
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
  reviewHistory: ReviewHistoryEntry[];
  date: string;
  modified: string;
  link: string;
  featuredMedia: number;
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
  count?: number;
  parent?: number;
}

export interface FieldErrors {
  [field: string]: string;
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
  | 'articles'
  | 'editor'
  | 'profile'
  | 'settings'
  | 'media';
