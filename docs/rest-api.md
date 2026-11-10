# REST API, Bootstrap & Deeplinks Reference

Penalis Platform combines WordPress core REST routes (`wp/v2`) for content CRUD
with a custom namespace (`penalis/v1`) for account lifecycle, editorial workflow,
notifications, and dashboard helpers.

All responses under `penalis/v1` send `Cache-Control: no-store, private, max-age=0`.
Authenticated requests use WordPress login cookies and the `wp_rest` nonce in
the `X-WP-Nonce` header.

---

## 1. Custom Namespace: `penalis/v1`

### 1.1 Public Auth & Verification Routes

Public routes use `__return_true` as their permission callback and enforce
strict input validation, a honeypot field (`penalis_website`), per-IP/per-account
rate limits, and generic responses that prevent account enumeration.

| Route | Method | Handler | Purpose |
| --- | --- | --- | --- |
| `/registration` | `POST` | `handle_registration` | Register a new `pending_verification` account (with optional `instagram`) and email a six-digit verification code |
| `/verification/confirm` | `POST` | `handle_verification` | Consume `challenge_id` + `code` and immediately activate the account (`active`, `penalis_writer`) || `/verification/resend` | `POST` | `handle_resend` | Re-issue a verification code (60-second cooldown, 5 issues/hour) |
| `/legacy/activate` | `POST` | `handle_legacy_activate_request` | Request an activation code for a pre-plugin contributor account |
| `/legacy/activate/confirm` | `POST` | `handle_legacy_activate_confirm` | Consume `challenge_id` + `code`, set first password, and immediately activate the account (`active`) |
| `/password/forgot` | `POST` | `handle_password_forgot` | Request a password reset link by **email address only** (`user_email`) |
| `/password/reset` | `POST` | `handle_password_reset` | Validate core reset key (`key` + `login`), set new password (12+ chars), destroy sessions |
| `/account/email/confirm` | `POST` | `confirm_email_change` | Consume `challenge_id` + `code`, re-verify availability, and update `user_email` |
| `/account/email/resend` | `POST` | `resend_email_change` | Re-issue the code for the pending address without repeating the password |

### 1.2 Signed-In Account Routes (`require_logged_in`)

Accessible to any signed-in user (including `pending_verification` accounts
viewing their status or updating their profile).

| Route | Method | Handler | Purpose |
| --- | --- | --- | --- |
| `/account` | `GET` | `get_account` | Return account status, capability flags, `instagram`, article counts by editorial state, and `unreadNotifications` count |
| `/account/profile` | `POST` | `update_profile` | Update `first_name`, `last_name`, `display_name_mode`, custom `display_name`, `description`, and optional `instagram` |
| `/account/password` | `POST` | `update_password` | Change own password (requires `current_password`, `new_password` >= 12 chars) |
| `/account/username` | `POST` | `change_username` | Re-authenticated `user_login` change inside a MySQL advisory lock; records 301 redirect and ends sessions |
| `/account/email` | `POST` | `request_email_change` | Re-authenticated request to change email; emails a six-digit code to the new address |
| `/account/avatar` | `POST` | `set_avatar` | Set profile photo from an attachment owned by the current user (minimum 100x100px) |
| `/account/avatar` | `DELETE` | `remove_avatar` | Remove the current user's profile photo |
| `/notifications` | `GET` | `list_notifications` | Return the signed-in user's bounded editorial notifications (up to 50) and `unreadCount` |
| `/notifications/read` | `POST` | `mark_notifications_read` | Mark all of the signed-in user's notifications as read |
| `/notifications/(?P<id>[a-zA-Z0-9_\-]+)/read` | `POST` | `mark_notifications_read` | Mark a single notification as read |

### 1.3 Writer & Editorial Routes (`require_active_writer`)

Accessible to active writers (`_penalis_account_status = active` and
`_penalis_access_enabled = 1`) and administrators.

| Route | Method | Handler | Access & Behavior |
| --- | --- | --- | --- |
| `/articles` | `GET` | `list_articles` | Paginated list of the current writer's articles (`status`: `all`, `draft`, `pending_review`, `changes_requested`, `rejected`, `published`) |
| `/articles/(?P<id>\d+)` | `GET` | `get_article` | Single article with editorial metadata; readable only by the article owner or a reviewer (`edit_others_posts`, i.e. administrator or editor) (`404` otherwise) |
| `/articles/(?P<id>\d+)/review` | `GET` | `get_article_review` | Editorial state, capability flags (`canEdit`, `canSubmit`, `canResubmit`), active feedback, review cycle, and bounded review history |
| `/articles/(?P<id>\d+)/submit` | `POST` | `submit_article` | Owner-only: submit a complete `draft` article (`title` and `content` required) to `pending_review` |
| `/articles/(?P<id>\d+)/resubmit` | `POST` | `resubmit_article` | Owner-only: resubmit a `changes_requested` article to `pending_review` |
| `/articles/(?P<id>\d+)/publish` | `POST` | `reject_legacy_publish` | Legacy route retained for explicit refusal; always returns `403 penalis_publish_forbidden` |
| `/articles/(?P<id>\d+)/unpublish` | `POST` | `reject_legacy_publish` | Legacy route retained for explicit refusal; always returns `403 penalis_publish_forbidden` |
| `/media` | `GET` | `list_media` | Paginated list of image attachments owned by the current writer |
| `/tags` | `POST` | `create_tag` | Create or resolve a post tag by name (rate-limited to 30/hour per user) |

> **Note on Administrator Review Actions:** Administrator review decisions
> (`approve`, `request_changes`, `reject`, `reopen`) and writer account status
> transitions are intentionally **not** exposed as REST endpoints. They run
> through nonce-protected `admin-post.php` handlers (`penalis_review_action` and
> `penalis_account_action`) inside `wp-admin`.

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
| `adminUrl` | `string` | Link to `admin.php?page=penalis-platform` (populated only for users with `list_users`) |
| `siteName` | `string` | WordPress blog name |
| `isLoggedIn` | `boolean` | Whether the visitor is authenticated |
| `registration` | `boolean` | Value of `penalis_registration_enabled` |
| `canWrite` | `boolean` | Result of `Penalis_Account_Status::can_write()` |
| `status` | `AccountStatus` | Current account status (`active`, `pending_verification`, etc.) |
| `statusLabel` | `string` | Indonesian label for the current account status |
| `user` | `object \| null` | Profile fields (`id`, `username`, `displayName`, `firstName`, `lastName`, `displayNameMode`, `avatarId`, `avatarUrl`, `email`, `description`, `instagram`, `authorUrl`) |

---

## 4. Dashboard Deeplinks (`src/lib/deeplink.ts`)

Query parameters on `/dashboard/` open specific views or confirmation screens
directly from transactional emails and public links:

| Query Parameter | Value Shape | Target Screen |
| --- | --- | --- |
| `penalis_signin=1` | Flag | Sign-in form (default for guests) |
| `penalis_register=1` | Flag | New writer registration form |
| `penalis_forgot=1` | Flag | Forgot-password request form |
| `penalis_resend=1` | Flag | Resend verification code form |
| `penalis_activate=1` | Flag | Legacy contributor activation request form |
| `penalis_reset=<key>&penalis_login=<login>` | Core reset key + login | Set new password form |
| `penalis_login_error=<code>` | Error code | Sign-in form with localized error banner |
| `view=editor&id=<post_id>` | Signed-in view route | Opens a specific article in the dashboard editor |

> **No bearer links for verification.** Registration, legacy activation, and
> email changes are confirmed with a six-digit code typed into the dashboard, so
> no deep link carries a single-use secret. The only remaining link-based flow
> is the WordPress core password reset key, which is deliberately separate.

