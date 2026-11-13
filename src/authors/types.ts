export type AuthorsSortMode = 'most_posts' | 'least_posts' | 'name_asc' | 'name_desc';

export interface PublicAuthorItem {
  id: number;
  displayName: string;
  username: string;
  avatarUrl: string;
  authorUrl: string;
  postCount: number;
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

