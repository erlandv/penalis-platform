# REST API, Bootstrap & Deeplinks Reference

Penalis Platform combines WordPress core REST routes (`wp/v2`) for content CRUD
with a custom namespace (`penalis/v1`) for account lifecycle, editorial workflow,
notifications, and dashboard helpers.

All responses under `penalis/v1` send `Cache-Control: no-store, private, max-age=0`,
except `GET /penalis/v1/authors` (for deterministic sort modes), which is public and sends
`Cache-Control: public, max-age=120`, and anonymous `GET /penalis/v1/quotes`
(when `sort` is not `random`), which sends `Cache-Control: public, max-age=30`.
Authenticated requests use WordPress login cookies and the `wp_rest` nonce in
the `X-WP-Nonce` header.

---

## 1. Custom Namespace: `penalis/v1`

### 1.1 Public Auth & Verification Routes

Public routes use `__return_true` as their permission callback and enforce
strict input validation, a honeypot field (`penalis_website`), per-IP/per-account
rate limits, optional Cloudflare Turnstile verification (`cf_turnstile_response`
when Simple Cloudflare Turnstile is active and enabled), and generic responses
that prevent account enumeration.

| Route | Method | Handler | Purpose |
| --- | --- | --- | --- |
| `/registration` | `POST` | `handle_registration` | Register a new `pending_verification` account (with optional `instagram`) and email a six-digit verification code. Reports taken usernames with `400 penalis_registration_invalid`; preserves email anti-enumeration by returning a generic challenge while notifying existing/legacy account owners |
| `/verification/confirm` | `POST` | `handle_verification` | Consume `challenge_id` + `code` and immediately activate the account (`active`, `penalis_writer`) |
| `/verification/resend` | `POST` | `handle_resend` | Re-issue a verification code (60-second cooldown, 5 issues/hour) |
| `/legacy/activate` | `POST` | `handle_legacy_activate_request` | Request an activation code for a pre-plugin contributor account |
| `/legacy/activate/confirm` | `POST` | `handle_legacy_activate_confirm` | Consume `challenge_id` + `code`, set first password, and immediately activate the account (`active`) |
| `/password/forgot` | `POST` | `handle_password_forgot` | Request a password reset link by **email address only** (`user_email`) |
| `/password/reset` | `POST` | `handle_password_reset` | Validate core reset key (`key` + `login`), set new password (12+ chars), destroy sessions |
| `/account/email/confirm` | `POST` | `confirm_email_change` | Consume `challenge_id` + `code`, re-verify availability, and update `user_email` |
| `/account/email/resend` | `POST` | `resend_email_change` | Re-issue the code for the pending address without repeating the password |
| `/authors` | `GET` | `handle_public_authors` | Public paginated directory of `penalis_writer` and `contributor` accounts with `>= 1` published post (excluding `suspended`), including each author's `postCount`, total published `views`, `isVerified`, and `isSenator` (plus `totalSenator`). Supports `search`, `sort` (`most_posts`, `least_posts`, `most_views`, `least_views`, `name_asc`, `name_desc`, `random`), `verified`, `senator`, `page`, and `per_page` (default `100`, max `100`) |
| `/quotes` | `GET` | `handle_public_quotes` | Public paginated showcase of currently active 24-hour `#KalimatHariIni` entries from active writers (excluding expired entries and `suspended` accounts). Supports `search` (matches quote text, display name, or username), `sort` (`newest`, `oldest`, `random`), `page`, and `per_page` (default `24`, max `50`) |

### 1.2 Signed-In Account Routes (`require_logged_in`)

Accessible to any signed-in user (including `pending_verification` accounts
viewing their status or updating their profile). Staff (administrators and
editors, both holding `edit_others_posts`) bypass the writer status gate: they
have no Penalis writer status, so `/account` reports `isReviewer: true`,
`canView: true`, `canWrite: false`, `role: "editor"` (or `"administrator"`),
and the label `Reviewer` (or `Administrator`).

`role` is resolved by `Penalis_Policy::dashboard_role()` and is the only value
the dashboard uses to choose between the writer and Senator experiences. Staff
is resolved before writer, so an account carrying both capabilities still
reports a staff role.

| Route | Method | Handler | Purpose |
| --- | --- | --- | --- |
| `/account` | `GET` | `get_account` | Return account status, resolved dashboard `role` (`administrator`, `editor`, `writer`, `pending`, `suspended`), capability flags (`isAdministrator`, `isReviewer`, `canWrite`, `canView`), `instagram`, article counts by editorial state (plus total published `counts.views`), `unreadNotifications` count, and `notificationPreferences` (per-action `dashboard`/`email` opt-outs) |
| `/account/profile` | `POST` | `update_profile` | Update `first_name`, `last_name`, `display_name_mode`, custom `display_name`, `description`, and optional `instagram` |
| `/account/password` | `POST` | `update_password` | Change own password (requires `current_password`, `new_password` >= 12 chars) |
| `/account/username` | `POST` | `change_username` | Re-authenticated `user_login` change inside a MySQL advisory lock; records 301 redirect and ends sessions |
| `/account/email` | `POST` | `request_email_change` | Re-authenticated request to change email; emails a six-digit code to the new address |
| `/account/avatar` | `POST` | `set_avatar` | Upload a profile photo (`multipart/form-data` field `file`, JPEG/PNG/GIF/WebP, minimum 100×100px, max 5 MB) into `uploads/penalis-avatars/u{id}-{token}.jpg` and immediately delete the superseded standalone file (rate-limited per account) |
| `/account/avatar` | `DELETE` | `remove_avatar` | Remove the current user's profile photo (deletes the standalone file inside `penalis-avatars/`; legacy Media Library attachments are only detached) |
| `/notifications` | `GET` | `list_notifications` | Return the signed-in user's bounded editorial notifications (up to 50) and `unreadCount`. Each item carries `articleId`, `articleTitle`, `feedback`, `targetView`, `targetUrl`, and `reviewUrl`; `targetUrl` adds `penalis_focus=feedback` when the entry has revision notes |
| `/notifications/read` | `POST` | `mark_notifications_read` | Mark all of the signed-in user's notifications as read |
| `/notifications/(?P<id>[a-zA-Z0-9_\-]+)/read` | `POST` | `mark_notifications_read` | Mark a single notification as read |
| `/notifications/preferences` | `POST` | `save_notification_preferences` | Save `{ dashboard, email }` opt-out matrices keyed by editorial action; omitted actions stay enabled. Returns the normalized `preferences` and a message |

### 1.3 Writer & Editorial Routes (`require_active_writer` / `require_active_writer_or_reviewer`)

Accessible to active writers (`_penalis_account_status = active` and
`_penalis_access_enabled = 1`). The read-only article detail and review routes
(`/articles/(?P<id>\d+)` and `/articles/(?P<id>\d+)/review`) use
`require_active_writer_or_reviewer` so reviewers (`edit_others_posts`, i.e.
administrators and editors) without writer status can also read them.

| Route | Method | Handler | Access & Behavior |
| --- | --- | --- | --- |
| `/articles` | `GET` | `list_articles` | Paginated list of the current writer's articles (`status`: `all`, `draft`, `pending_review`, `changes_requested`, `rejected`, `published`); includes per-article `views` (`0` for unpublished articles) and author-wide `counts.views` via Post Views Counter |
| `/articles/(?P<id>\d+)` | `GET` | `get_article` | Single article with editorial metadata, author identity (`authorName`, `authorLogin`), featured `thumbnail` URL, and lifetime `views` (`0` when unpublished); readable only by the article owner or a reviewer (`edit_others_posts`, i.e. administrator or editor) (`404` otherwise) |
| `/articles/(?P<id>\d+)/review` | `GET` | `get_article_review` | Editorial state, capability flags (`canEdit`, `canSubmit`, `canResubmit`), active feedback, review cycle, and bounded review history |
| `/articles/(?P<id>\d+)/decision` | `POST` | `decide_article` | Reviewer-only (`edit_others_posts`): `action` (`approve`, `request_changes`, `reject`), `feedback`, and `expected_cycle`. Runs `Penalis_Editorial_Workflow::apply()`, so the per-article lock, the stale-cycle guard and the feedback requirement are the same as in wp-admin. Returns the new review payload and a `message` |
| `/review/queue` | `GET` | `list_review_queue` | Reviewer-only: paginated `pending_review` queue, oldest first, with author, submission time and review cycle |
| `/articles/(?P<id>\d+)/submit` | `POST` | `submit_article` | Owner-only: submit a complete `draft` article (`title` and `content` required) to `pending_review` |
| `/articles/(?P<id>\d+)/resubmit` | `POST` | `resubmit_article` | Owner-only: resubmit a `changes_requested` article to `pending_review` |
| `/articles/(?P<id>\d+)/publish` | `POST` | `reject_legacy_publish` | Legacy route retained for explicit refusal; always returns `403 penalis_publish_forbidden` |
| `/articles/(?P<id>\d+)/unpublish` | `POST` | `reject_legacy_publish` | Legacy route retained for explicit refusal; always returns `403 penalis_publish_forbidden` |
| `/media` | `GET` | `list_media` | Paginated list of image attachments owned by the current writer, including per-item usage status (`inUse`, `canDelete`, `usageCount`, `usedIn`, `deleteBlockedReason`) |
| `/media/(?P<id>\d+)` | `DELETE` | `delete_media` | Permanently delete an unused attachment owned by the current writer (rate-limited per user; returns `409 penalis_media_in_use` if referenced by any published, pending, draft, scheduled, or private post, or by an avatar) |
| `/tags` | `POST` | `create_tag` | Create or resolve a post tag by name (rate-limited per user, 30/hour by default; configurable at Pengaturan → Batas Percobaan) |
| `/quotes/me` | `GET` | `handle_my_quote` | Active-writer only: return the writer's active `#KalimatHariIni` (or `null`), rolling 24-hour `quota` (`used`, `limit`, `remaining`, `resetAt`), `maxLength` (`200`), `maxLines` (`3`), `ttlSeconds` (`86400`), `canPublish`, and `publicUrl` |
| `/quotes` | `POST` | `handle_publish_quote` | Active-writer only: publish a new `#KalimatHariIni` (`text`, max 200 chars, max 3 lines, 24h TTL) inside a per-user advisory lock. Returns `201` with updated writer state, `409 penalis_quote_already_active` if a quote is still live, `422 penalis_validation_error` on invalid input, or `429 penalis_quote_limit_reached` once 3 publishes occurred in the rolling 24-hour window |
| `/quotes/me` | `DELETE` | `handle_delete_my_quote` | Active-writer only: delete the writer's active `#KalimatHariIni` immediately without restoring the rolling 24-hour publish quota |
| `/quotes/(?P<user_id>\d+)` | `DELETE` | `handle_moderate_delete_quote` | Reviewer-only (`edit_others_posts`): remove a writer's active `#KalimatHariIni` from the Dashboard Senator moderation console (`/dashboard/?view=quotes`); returns `{ deleted: true, authorId }` |

> **Note on Review Actions:** Reviewer decisions are exposed twice, on purpose.
> The dashboard queue (`POST /articles/{id}/decision`) and the wp-admin screen
> (`admin-post.php?action=penalis_review_action`) both call
> `Penalis_Editorial_Workflow::apply()`, which is where the state machine, the
> per-article advisory lock, the stale-cycle guard and the feedback rule live.
> Neither entry point decides anything on its own, so the two cannot drift.
> `reopen` and writer account status transitions remain wp-admin only
> (`penalis_account_action`).

---

## 2. Core WordPress REST Endpoints Used by the Dashboard

Content CRUD uses standard WordPress REST routes guarded by
`Penalis_Access_Control`:

| Core Route | Usage in Dashboard | Server-Side Guard |
| --- | --- | --- |
| `POST /wp/v2/posts` | Create new draft article | Forces `post_author` to current user; refuses non-`draft` status |
| `POST /wp/v2/posts/{id}` | Save edits to an editable draft | Requires ownership and `Penalis_Editorial_Workflow::can_writer_edit()`; refuses `publish` |
| `DELETE /wp/v2/posts/{id}?force=true` | Permanently delete own draft/rejected article | `map_meta_cap` blocks deleting non-owned or under-review posts |
| `POST /wp/v2/media` | Upload image (`jpeg`, `png`, `gif`, `webp`) | Validates MIME + `getimagesize()`, forces `author`, verifies parent post ownership |
| `GET /wp/v2/categories` | Populate category selector in editor | Read-only |
| `GET /wp/v2/tags` | Search existing tags in editor | Read-only |

---

## 3. Dashboard Bootstrap Payload (`window.penalisDashboard`)

`Penalis_Dashboard::maybe_enqueue_assets()` localizes `window.penalisDashboard`
before the Vite module executes. The TypeScript contract lives in
`src/lib/types.ts` (`DashboardBootstrap`) with a safe fallback in
`src/lib/bootstrap.ts`.

| Field | Type | Description |
| --- | --- | --- |
| `restUrl` | `string` | Base URL for `penalis/v1` endpoints |
| `wpRestUrl` | `string` | Base URL for `wp/v2` endpoints |
| `nonce` | `string` | `wp_rest` nonce for `X-WP-Nonce` |
| `dashboardUrl` | `string` | Canonical URL of `/dashboard/` |
| `loginUrl` | `string` | Dashboard login POST target |
| `logoutUrl` | `string` | Nonce-signed logout URL redirecting back to `/dashboard/` |
| `lostPassword` | `string` | Native fallback lost-password URL |
| `homeUrl` | `string` | Public site home URL |
| `authorsUrl` | `string` | Public contributors directory URL (`Penalis_Author_Archive::directory_url()`) |
| `dailyQuotesUrl` | `string` | Public `#KalimatHariIni` showcase URL (`Penalis_Daily_Quotes::page_url()`) |
| `adminUrl` | `string` | Link to `admin.php?page=penalis-writers` (populated only for users with `list_users`) |
| `reviewUrl` | `string` | Link to `admin.php?page=penalis-review` (populated for users with `edit_others_posts`) |
| `siteName` | `string` | WordPress blog name |
| `siteTimezone` | `object` | Site timezone metadata from `Penalis_Datetime::site_timezone_payload()` (`timezone`, `offsetSeconds`, `label`) |
| `isLoggedIn` | `boolean` | Whether the visitor is authenticated |
| `registration` | `boolean` | Value of `penalis_registration_enabled` |
| `turnstile` | `TurnstileConfig` | Optional Cloudflare Turnstile widget settings (`enabled`, `siteKey`, `theme`, `size`, `language`, `appearance`, `forms` per auth screen) |
| `role` | `DashboardRole` | Resolved role from `Penalis_Policy::dashboard_role()`: `administrator`, `editor`, `writer`, `pending`, or `suspended` (empty string for guests) |
| `status` | `AccountStatus` | Current account status (`active`, `pending_verification`, etc.) |
| `statusLabel` | `string` | Indonesian label for the current account status |
| `user` | `object \| null` | Profile fields (`id`, `displayName`, `firstName`, `lastName`, `displayNameMode`, `avatarId`, `hasAvatar`, `avatarUrl`, `email`, `description`, `instagram`, `authorUrl`) |

---

## 4. Dashboard Deeplinks (`src/lib/deeplink.ts`)

Query parameters on `/dashboard/` open specific views or confirmation screens
directly from transactional emails and public links:

| Query Parameter | Value Shape | Target Screen |
| --- | --- | --- |
| `penalis_signin=1` | Flag | Sign-in form (default for guests) |
| `penalis_register=1` | Flag | New writer registration form |
| `penalis_forgot=1` | Flag | Forgot-password request form |
| `penalis_activate=1` | Flag | Legacy contributor activation request form |
| `penalis_reset=<key>&penalis_user=<login>` | Core reset key + login (also accepts legacy `penalis_login`) | Set new password form |
| `penalis_login_error=<code>` | Error code | Sign-in form with localized error banner |
| `penalis_turnstile_error=1` | Flag | Sign-in form with CAPTCHA verification failure banner |
| `view=editor&id=<post_id>` | Signed-in view route | Opens a specific article in the dashboard editor. A view the resolved role cannot open (for example `view=editor` for a Senator) is clamped to `view=overview` and the URL is rewritten |
| `view=review&id=<post_id>` | Signed-in view route (staff) | Opens a specific submission in the reviewer reader, the same workspace **Baca karya** opens. Notification rows use this so a Senator lands on the article instead of the bare queue. A non-reviewer role is clamped to `view=overview` |
| `view=quotes` | Signed-in view route (staff) | Opens the Senator `#KalimatHariIni` moderation console (`QuotesModeration.tsx`). Non-reviewer roles are clamped to `view=overview` |
| `#kalimat-hari-ini` | Hash anchor on Overview (writer) | Smoothly scrolls an active writer to the `#KalimatHariIni` composer panel on Ringkasan (used by the author-archive quick action and `/kalimat-hari-ini/` CTA) |

> **No bearer links for verification.** Registration, legacy activation, and
> email changes are confirmed with a six-digit code typed into the dashboard, so
> no deep link carries a single-use secret. The only remaining link-based flow
> is the WordPress core password reset key, which is deliberately separate.

