export type QuotesSortMode = 'newest' | 'oldest' | 'random';

export interface PublicDailyQuoteItem {
  authorId: number;
  text: string;
  createdAt: number;
  expiresAt: number;
  displayName: string;
  username: string;
  avatarUrl: string;
  authorUrl: string;
  isVerified: boolean;
  isSenator: boolean;
}

export interface PublicDailyQuotesResponse {
  items: PublicDailyQuoteItem[];
  total: number;
  totalAll: number;
  page: number;
  perPage: number;
  hasMore: boolean;
}

export interface PenalisDailyQuotesBootConfig {
  restUrl: string;
  siteName: string;
  perPage: number | string;
  currentUserId?: number | string;
  canWrite?: boolean | string;
  isStaff?: boolean | string;
  dashboardUrl?: string;
}

declare global {
  interface Window {
    penalisDailyQuotes?: PenalisDailyQuotesBootConfig;
  }
}
