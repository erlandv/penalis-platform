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
  email: string;
  description?: string;
  website?: string;
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
  loginPostUrl?: string;
  logoutUrl: string;
  lostPassword: string;
  homeUrl: string;
  adminUrl: string;
  siteName: string;
  user: BootstrapUser | null;
  i18n: Record<string, string>;
}

export interface AccountCounts {
  all: number;
  publish: number;
  draft: number;
  pending: number;
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
  email: string;
  description: string;
  website: string;
  status: AccountStatus;
  statusLabel: string;
  isAdministrator: boolean;
  canWrite: boolean;
  canView: boolean;
  emailVerified: boolean;
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
