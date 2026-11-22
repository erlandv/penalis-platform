/**
 * Thin REST client with consistent error handling.
 *
 * All requests are same-origin and rely on WordPress session cookies. The
 * X-WP-Nonce header provides CSRF protection for authenticated writes.
 */

import { getBootstrap, getNonce, setNonce } from './bootstrap';
import { decodeEntities } from './format';
import { isUncategorizedTerm } from './validation';
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
  NotificationPreferences,
  ReviewDecisionAction,
  ReviewDecisionResult,
  ReviewQueueList,
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

/**
 * Error codes that mean "this request was rejected before it did any work".
 *
 * WordPress answers a stale `wp_rest` nonce with `rest_cookie_invalid_nonce`
 * (403) even when the login cookie is still valid, and a lost session with
 * either `penalis_not_authenticated` (401, plugin routes) or
 * `rest_cannot_create` (401, core post routes). All of them are safe to retry
 * once with a fresh nonce, because the write never reached its handler.
 */
const AUTH_ERROR_CODES = new Set([
  'rest_cookie_invalid_nonce',
  'penalis_not_authenticated',
  'rest_cannot_create',
]);

/**
 * Whether an error means the browser is no longer authenticated.
 *
 * Used by the editor to replace a raw WordPress message with a recovery path.
 * A 403 that reports an inactive account is deliberately excluded: the session
 * is fine there, so telling the writer to log in again would be wrong.
 */
export function isAuthFailure(caught: unknown): boolean {
  if (!(caught instanceof ApiError)) {
    return false;
  }

  if (caught.status === 401) {
    return true;
  }

  return caught.code === 'rest_cookie_invalid_nonce' || caught.code === 'penalis_not_authenticated';
}

/**
 * Fetch a fresh `wp_rest` nonce for the current session.
 *
 * WordPress core exposes `admin-ajax.php?action=rest-nonce` for exactly this
 * purpose, and it needs no nonce of its own: the login cookie authenticates
 * the call. That makes it double as a session probe — a nonce comes back when
 * the session is alive, and an empty/`0` answer when it is gone.
 *
 * Refreshing in place is what keeps an unsaved manuscript alive: the only
 * other way to get a valid nonce is reloading the page, which discards it.
 *
 * @return The new nonce, or `null` when the session itself has ended.
 */
export async function refreshNonce(): Promise<string | null> {
  const base = getBootstrap().homeUrl.replace(/\/+$/, '');
  const url = `${base}/wp-admin/admin-ajax.php?action=rest-nonce`;

  try {
    const response = await fetchWithDeadline(
      url,
      {
        credentials: 'same-origin',
        headers: { Accept: 'text/plain' },
      },
      NONCE_TIMEOUT_MS,
    );

    if (!response.ok) {
      return null;
    }

    const nonce = (await response.text()).trim();

    // A logged-out visitor receives `0`; anything that is not a core nonce
    // shape is treated the same way rather than being sent as a header.
    if (!/^[A-Za-z0-9]{8,32}$/.test(nonce)) {
      return null;
    }

    setNonce(nonce);
    return nonce;
  } catch {
    return null;
  }
}

/**
 * Deadline for a JSON request.
 *
 * A request that never settles would leave the editor's `saving` flag stuck on
 * "Menyimpan…", which silently disables autosave — the exact failure this
 * client exists to prevent. Payloads here are small, so 45 seconds is generous.
 */
const REQUEST_TIMEOUT_MS = 45_000;

/** Deadline for the nonce refresh, which must never block a retry. */
const NONCE_TIMEOUT_MS = 10_000;

/**
 * Fetch with a deadline.
 *
 * Uploads are deliberately excluded: a large image on a slow connection can
 * legitimately outlast any deadline worth setting, and aborting it would be
 * worse than waiting.
 */
async function fetchWithDeadline(
  url: string,
  init: RequestInit,
  timeoutMs: number,
): Promise<Response> {
  if (timeoutMs <= 0) {
    return fetch(url, init);
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    return await fetch(url, { ...init, signal: controller.signal });
  } finally {
    clearTimeout(timer);
  }
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  absoluteUrl?: string;
  fallbackMessage?: string;
  networkMessage?: string;
  /**
   * Retry once with a freshly fetched nonce when WordPress rejects the request
   * as unauthenticated. Enabled for writes only: a rejected write has not been
   * applied, so replaying it cannot duplicate anything.
   */
  retryAuth?: boolean;
}

interface PreparedRequest {
  url: string;
  init: RequestInit;
}

/**
 * Build a request right before sending it.
 *
 * Called again for the retry so the new nonce is picked up without the caller
 * knowing anything happened.
 */
function prepareRequest(path: string, options: RequestOptions): PreparedRequest {
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

  const nonce = getNonce();
  if (nonce) {
    headers['X-WP-Nonce'] = nonce;
  }

  return {
    url,
    init: {
      method: options.method ?? 'GET',
      credentials: 'same-origin',
      headers,
      body:
        options.body === undefined
          ? undefined
          : isFormData
            ? (options.body as FormData)
            : JSON.stringify(options.body),
    },
  };
}

async function send(prepared: PreparedRequest, options: RequestOptions): Promise<Response> {
  const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;

  try {
    return await fetchWithDeadline(
      prepared.url,
      prepared.init,
      isFormData ? 0 : REQUEST_TIMEOUT_MS,
    );
  } catch {
    throw new ApiError(
      options.networkMessage ?? 'Server tidak dapat dihubungi. Periksa koneksi kamu lalu coba lagi.',
      'network_error',
      0,
    );
  }
}

async function readPayload(response: Response): Promise<unknown> {
  const contentType = response.headers.get('content-type') ?? '';
  return contentType.includes('application/json') ? await response.json().catch(() => null) : null;
}

function toApiError(response: Response, payload: unknown, options: RequestOptions): ApiError {
  const shape = (payload ?? {}) as ApiErrorShape;
  const message =
    typeof shape.message === 'string' && shape.message.length > 0
      ? stripTags(shape.message)
      : (options.fallbackMessage ?? 'Terjadi kesalahan. Silakan coba lagi.');

  return new ApiError(message, shape.code ?? 'http_error', response.status, shape.data?.fields ?? {});
}

function isAuthErrorPayload(payload: unknown): boolean {
  const shape = (payload ?? {}) as ApiErrorShape;
  return typeof shape.code === 'string' && AUTH_ERROR_CODES.has(shape.code);
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  let response = await send(prepareRequest(path, options), options);

  if (response.status === 204) {
    return undefined as T;
  }

  let payload = await readPayload(response);

  if (!response.ok && options.retryAuth && isAuthErrorPayload(payload)) {
    const nonce = await refreshNonce();

    if (nonce) {
      response = await send(prepareRequest(path, options), options);

      if (response.status === 204) {
        return undefined as T;
      }

      payload = await readPayload(response);
    }
  }

  if (!response.ok) {
    throw toApiError(response, payload, options);
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

  /** Upload a cropped profile photo to dedicated avatar storage. */
  setAvatar: (file: File) => {
    const form = new FormData();
    form.append('file', file, file.name);

    return request<Account>('/account/avatar', {
      method: 'POST',
      body: form,
      fallbackMessage: 'Foto profil kamu tidak dapat diperbarui.',
      networkMessage: 'Foto profil kamu tidak dapat diperbarui. Periksa koneksi kamu.',
      retryAuth: true,
    });
  },

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
    cf_turnstile_response?: string;
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
  request: (email: string, honeypot = '', turnstileToken = '') =>
    request<ChallengeResponse>('/legacy/activate', {
      method: 'POST',
      body: {
        user_email: email,
        penalis_website: honeypot,
        ...(turnstileToken ? { cf_turnstile_response: turnstileToken } : {}),
      },
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
  forgot: (email: string, honeypot = '', turnstileToken = '') =>
    request<{ message: string }>('/password/forgot', {
      method: 'POST',
      body: {
        user_email: email,
        penalis_website: honeypot,
        ...(turnstileToken ? { cf_turnstile_response: turnstileToken } : {}),
      },
    }),

  reset: (payload: {
    key: string;
    login: string;
    password: string;
    cf_turnstile_response?: string;
  }) => request<{ message: string }>('/password/reset', { method: 'POST', body: payload }),
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

  /** Save per-action dashboard/email opt-outs for the current account. */
  savePreferences: (preferences: NotificationPreferences) =>
    request<{ message: string; preferences: NotificationPreferences }>('/notifications/preferences', {
      method: 'POST',
      body: preferences,
      retryAuth: true,
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
  submit: (id: number) =>
    request<ArticleReview>(`/articles/${id}/submit`, { method: 'POST', retryAuth: true }),

  /** Resubmit an owned article after administrator requested changes. */
  resubmit: (id: number) =>
    request<ArticleReview>(`/articles/${id}/resubmit`, { method: 'POST', retryAuth: true }),

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
      retryAuth: true,
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
    request<Term[]>('/categories?per_page=100&orderby=name&order=asc&_fields=id,name,slug,count,parent', {
      absoluteUrl: getBootstrap().wpRestUrl,
    }).then((terms) => terms.map(decodeTermName).filter((term) => !isUncategorizedTerm(term))),

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
    request<{ id: number; name: string }>('/tags', {
      method: 'POST',
      body: { name },
      retryAuth: true,
    }).then((created) => ({
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
    inUse: false,
    canDelete: true,
    usageCount: 0,
    usedIn: [],
    deleteBlockedReason: '',
  };
}

/* --------------------------- Review queue ---------------------------- */

export const reviewApi = {
  queue: (page: number) => request<ReviewQueueList>(`/review/queue?page=${page}&per_page=10`),

  /**
   * Record a decision.
   *
   * `expectedCycle` is the cycle the reviewer saw on screen. The server treats
   * a missing or different cycle as stale, which is what stops a second
   * reviewer from overwriting a round they never read.
   */
  decide: (id: number, action: ReviewDecisionAction, feedback: string, expectedCycle: number) =>
    request<ReviewDecisionResult>(`/articles/${id}/decision`, {
      method: 'POST',
      body: { action, feedback, expected_cycle: expectedCycle },
      retryAuth: true,
    }),
};

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
      retryAuth: true,
    });

    return mapWpMedia(media, file.name, file.type);
  },

  /** Permanently delete an unused attachment owned by the current writer. */
  remove: (id: number) =>
    request<{ deleted: boolean; id: number; message: string }>(`/media/${id}`, {
      method: 'DELETE',
      fallbackMessage: 'Gambar tidak dapat dihapus.',
      retryAuth: true,
    }),
};
