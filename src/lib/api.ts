/**
 * Thin REST client with consistent error handling.
 *
 * All requests are same-origin and rely on WordPress session cookies. The
 * X-WP-Nonce header provides CSRF protection for authenticated writes.
 */

import { getBootstrap } from './bootstrap';
import { decodeEntities } from './format';
import type {
  Account,
  ApiErrorShape,
  Article,
  ArticleList,
  ArticleReview,
  ChallengeResponse,
  DisplayNameMode,
  FieldErrors,
  MediaItem,
  MediaList,
  NotificationList,
  Term,
} from './types';

export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly fields: FieldErrors;

  constructor(message: string, code = 'unknown', status = 0, fields: FieldErrors = {}) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.fields = fields;
  }
}

export function isStaleEditorialError(caught: unknown): boolean {
  if (!(caught instanceof ApiError)) {
    return false;
  }

  return (
    caught.status === 409 ||
    caught.code === 'penalis_editorial_denied' ||
    caught.code === 'penalis_editorial_locked' ||
    caught.code === 'penalis_editorial_busy'
  );
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  absoluteUrl?: string;
  fallbackMessage?: string;
  networkMessage?: string;
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const bootstrap = getBootstrap();
  const base = options.absoluteUrl ?? bootstrap.restUrl;
  const url = `${base.replace(/\/$/, '')}${path.startsWith('/') ? path : `/${path}`}`;
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;

  const headers: Record<string, string> = {
    Accept: 'application/json',
  };

  if (options.body !== undefined && !isFormData) {
    headers['Content-Type'] = 'application/json';
  }

  if (bootstrap.nonce) {
    headers['X-WP-Nonce'] = bootstrap.nonce;
  }

  let response: Response;

  try {
    response = await fetch(url, {
      method: options.method ?? 'GET',
      credentials: 'same-origin',
      headers,
      body:
        options.body === undefined
          ? undefined
          : isFormData
            ? (options.body as FormData)
            : JSON.stringify(options.body),
    });
  } catch {
    throw new ApiError(
      options.networkMessage ?? 'Server tidak dapat dihubungi. Periksa koneksi kamu lalu coba lagi.',
      'network_error',
      0,
    );
  }

  if (response.status === 204) {
    return undefined as T;
  }

  const contentType = response.headers.get('content-type') ?? '';
  const payload: unknown = contentType.includes('application/json')
    ? await response.json().catch(() => null)
    : null;

  if (!response.ok) {
    const shape = (payload ?? {}) as ApiErrorShape;
    const message =
      typeof shape.message === 'string' && shape.message.length > 0
        ? stripTags(shape.message)
        : (options.fallbackMessage ?? 'Terjadi kesalahan. Silakan coba lagi.');

    throw new ApiError(
      message,
      shape.code ?? 'http_error',
      response.status,
      shape.data?.fields ?? {},
    );
  }

  return payload as T;
}

function stripTags(value: string): string {
  return value.replace(/<[^>]*>/g, '').trim();
}

/**
 * Decode a taxonomy name for display.
 *
 * WordPress stores term names as HTML and the taxonomy endpoints return them
 * encoded (`Budaya &amp; Agama`). Article titles and content are deliberately
 * left alone: they are editable fields, so decoding them would rewrite the
 * stored value the next time the writer saves.
 */
function decodeTermName<T extends { name: string }>(term: T): T {
  return { ...term, name: decodeEntities(term.name) };
}

/* ------------------------------ Account ------------------------------ */

export const accountApi = {
  get: () => request<Account>('/account'),

  changeUsername: (payload: { new_username: string; current_password: string }) =>
    request<{ message: string }>('/account/username', { method: 'POST', body: payload }),

  updateProfile: (payload: {
    first_name?: string;
    last_name?: string;
    display_name_mode?: DisplayNameMode;
    display_name?: string;
    description?: string;
    instagram?: string;
  }) => request<Account>('/account/profile', { method: 'POST', body: payload }),

  changePassword: (payload: { current_password: string; new_password: string }) =>
    request<{ message: string }>('/account/password', { method: 'POST', body: payload }),

  /**
   * Request a change of the account email address.
   *
   * The address is not changed by this call: the server emails a six-digit code
   * to the new address and applies the change only when it is confirmed.
   */
  requestEmailChange: (payload: { new_email: string; current_password: string }) =>
    request<ChallengeResponse & { pendingEmail: string }>('/account/email', {
      method: 'POST',
      body: payload,
    }),

  /** Consume the code sent to the candidate address. */
  confirmEmailChange: (payload: { challenge_id: string; code: string }) =>
    request<{ message: string; email: string }>('/account/email/confirm', {
      method: 'POST',
      body: payload,
    }),

  /** Reissue the code for the pending address without repeating the password. */
  resendEmailChange: () =>
    request<ChallengeResponse & { pendingEmail: string }>('/account/email/resend', {
      method: 'POST',
    }),

  /** Assign an existing media attachment as the profile photo. */
  setAvatar: (attachmentId: number) =>
    request<Account>('/account/avatar', { method: 'POST', body: { attachment_id: attachmentId } }),

  /** Detach the profile photo and fall back to the site default. */
  removeAvatar: () => request<Account>('/account/avatar', { method: 'DELETE' }),
};

/* ----------------------------- Registration ---------------------------- */

export const registrationApi = {
  register: (payload: {
    display_name: string;
    user_login: string;
    user_email: string;
    password: string;
    instagram?: string;
    penalis_consent: string;
    penalis_website: string;
  }) => request<ChallengeResponse>('/registration', { method: 'POST', body: payload }),

  resend: (email: string) =>
    request<ChallengeResponse>('/verification/resend', {
      method: 'POST',
      body: { user_email: email },
    }),

  confirm: (payload: { challenge_id: string; code: string }) =>
    request<{ message: string; status: string }>('/verification/confirm', {
      method: 'POST',
      body: payload,
    }),
};

/* --------------------------- Legacy activation ------------------------ */

export const legacyApi = {
  /**
   * Request an activation code for a legacy contributor account.
   *
   * The server always answers generically, so the UI must not imply that the
   * address belongs to a legacy account.
   */
  request: (email: string, honeypot = '') =>
    request<ChallengeResponse>('/legacy/activate', {
      method: 'POST',
      body: { user_email: email, penalis_website: honeypot },
    }),

  /** Consume the activation code and set the first password. */
  confirm: (payload: { challenge_id: string; code: string; password: string }) =>
    request<{ message: string; status: string }>('/legacy/activate/confirm', {
      method: 'POST',
      body: payload,
    }),
};

/* --------------------------- Password reset --------------------------- */

export const passwordApi = {
  /**
   * Request a reset link.
   *
   * Only an email address is accepted: usernames are public on author
   * archives, so they must not work as a reset handle. The server always
   * answers generically, so the UI must not imply that the address exists.
   */
  forgot: (email: string, honeypot = '') =>
    request<{ message: string }>('/password/forgot', {
      method: 'POST',
      body: { user_email: email, penalis_website: honeypot },
    }),

  reset: (payload: { key: string; login: string; password: string }) =>
    request<{ message: string }>('/password/reset', { method: 'POST', body: payload }),
};

/* --------------------------- Notifications ---------------------------- */

export const notificationsApi = {
  /** List the current user's bounded editorial notifications, newest first. */
  list: () => request<NotificationList>('/notifications'),

  /** Mark one notification (or all notifications when `id` is omitted) as read. */
  markRead: (id?: string) =>
    request<NotificationList>('/notifications/read', {
      method: 'POST',
      body: id ? { id } : {},
    }),
};

/* ------------------------------ Articles ----------------------------- */

export const articlesApi = {
  list: (page: number, status: string) => {
    const params = new URLSearchParams({ page: String(page), per_page: '10' });
    if (status && status !== 'all') {
      params.set('status', status);
    }
    return request<ArticleList>(`/articles?${params.toString()}`);
  },

  get: (id: number) =>
    request<Article>(`/articles/${id}`).then((article) => ({
      ...article,
      categories: article.categories.map(decodeTermName),
      tags: article.tags.map(decodeTermName),
    })),

  /** Fetch the editorial review state, feedback, and review history for an article. */
  review: (id: number) => request<ArticleReview>(`/articles/${id}/review`),

  /** Submit an owned draft article for administrator editorial review. */
  submit: (id: number) => request<ArticleReview>(`/articles/${id}/submit`, { method: 'POST' }),

  /** Resubmit an owned article after administrator requested changes. */
  resubmit: (id: number) => request<ArticleReview>(`/articles/${id}/resubmit`, { method: 'POST' }),

  /**
   * Create or update a draft through the native WordPress posts endpoint.
   *
   * Writers may only save drafts; publication is reserved for administrator
   * approval in the editorial review workflow.
   *
   * The author is always derived server-side; it is never sent by the client.
   *
   * `excerpt` is deliberately not sent: WordPress generates it from the
   * content, and SEO plugins usually override it anyway. Sending an empty
   * value would store an empty excerpt and suppress that automatic behaviour.
   */
  save: (payload: {
    id?: number;
    title: string;
    content: string;
    status: 'draft';
    categories: number[];
    tags: number[];
    featured_media: number;
  }) => {
    const body = {
      title: payload.title,
      content: payload.content,
      status: payload.status,
      categories: payload.categories,
      tags: payload.tags,
      featured_media: payload.featured_media,
    };

    const path = payload.id ? `/posts/${payload.id}` : '/posts';

    return request<{ id: number; status: string; link: string }>(path, {
      method: 'POST',
      body,
      absoluteUrl: getBootstrap().wpRestUrl,
    });
  },

  /** Delete an owned article through the native endpoint. */
  remove: (id: number) =>
    request<{ deleted: boolean; previous?: { id: number } }>(`/posts/${id}?force=true`, {
      method: 'DELETE',
      absoluteUrl: getBootstrap().wpRestUrl,
    }),
};

/* ------------------------------ Taxonomy ----------------------------- */

export const taxonomyApi = {
  categories: () =>
    request<Term[]>('/categories?per_page=100&orderby=name&order=asc&_fields=id,name,count,parent', {
      absoluteUrl: getBootstrap().wpRestUrl,
    }).then((terms) => terms.map(decodeTermName)),

  tags: (search: string) => {
    const params = new URLSearchParams({
      per_page: '20',
      orderby: 'name',
      order: 'asc',
      _fields: 'id,name',
    });
    if (search) {
      params.set('search', search);
    }
    return request<Term[]>(`/tags?${params.toString()}`, { absoluteUrl: getBootstrap().wpRestUrl }).then((terms) =>
      terms.map(decodeTermName),
    );
  },

  createTag: (name: string) =>
    request<{ id: number; name: string }>('/tags', { method: 'POST', body: { name } }).then((created) => ({
      id: created.id,
      name: decodeEntities(created.name),
    })),
};

/* -------------------------------- Media ------------------------------ */

interface WpMediaPayload {
  id: number;
  title?: { rendered?: string };
  mime_type?: string;
  source_url?: string;
  date_gmt?: string;
  media_details?: { sizes?: { thumbnail?: { source_url?: string } } };
}

function mapWpMedia(media: WpMediaPayload, fallbackTitle = '', fallbackMime = ''): MediaItem {
  const sourceUrl = media.source_url ?? '';
  return {
    id: media.id,
    title: media.title?.rendered ?? fallbackTitle,
    mime: media.mime_type ?? fallbackMime,
    url: sourceUrl,
    thumbnail: media.media_details?.sizes?.thumbnail?.source_url ?? sourceUrl,
    full: sourceUrl,
    date: media.date_gmt ?? '',
  };
}

export const mediaApi = {
  list: (page: number) => request<MediaList>(`/media?page=${page}&per_page=20`),

  /** Fetch a single attachment (used for the featured-image preview). */
  get: async (id: number): Promise<MediaItem> => {
    const media = await request<WpMediaPayload>(
      `/media/${id}?_fields=id,title,mime_type,source_url,date_gmt,media_details`,
      { absoluteUrl: getBootstrap().wpRestUrl },
    );

    return mapWpMedia(media);
  },

  /**
   * Upload an image through the native WordPress media endpoint.
   *
   * The server restricts MIME types and validates image contents. Files are
   * sent as multipart/form-data, which the REST API expects for uploads.
   */
  upload: async (file: File): Promise<MediaItem> => {
    const form = new FormData();
    form.append('file', file, file.name);

    const media = await request<WpMediaPayload>('/media', {
      method: 'POST',
      body: form,
      absoluteUrl: getBootstrap().wpRestUrl,
      fallbackMessage: 'Gambar tidak dapat di-upload.',
      networkMessage: 'Gambar tidak dapat di-upload. Periksa koneksi kamu.',
    });

    return mapWpMedia(media, file.name, file.type);
  },
};
