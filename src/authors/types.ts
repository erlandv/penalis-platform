export type AuthorsSortMode =
  | 'most_posts'
  | 'least_posts'
  | 'most_views'
  | 'least_views'
  | 'name_asc'
  | 'name_desc'
  | 'random';

export interface PublicAuthorItem {
  id: number;
  displayName: string;
  username: string;
  avatarUrl: string;
  authorUrl: string;
  postCount: number;
  views: number;
  isVerified: boolean;
}

export interface PublicAuthorsResponse {
  items: PublicAuthorItem[];
  total: number;
  totalAll: number;
  totalVerified: number;
  page: number;
  perPage: number;
  hasMore: boolean;
}

export interface PenalisAuthorsBootConfig {
  restUrl: string;
  siteName: string;
  perPage: number;
}

declare global {
  interface Window {
    penalisAuthors?: PenalisAuthorsBootConfig;
  }
}

