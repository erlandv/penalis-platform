# Security Model & Authorization

This document describes the threat model, trust boundaries, and server-side
security controls enforced by Penalis Platform.

---

## 1. Reporting a Vulnerability

Report security issues privately to the repository maintainer or the
[Penalis](https://penalis.com) site administrator. Do not open a public issue
for an unpatched vulnerability.

---

## 2. Trust Boundaries

| Boundary | Trusted? | Enforcement |
| --- | --- | --- |
| WordPress core cookie authentication | Yes | Standard WordPress session cookies + `wp_rest` nonce |
| WordPress capability system | Yes, with explicit status layer | Filtered by `user_has_cap` and `map_meta_cap` |
| Browser client / React SPA | **No** | Every capability, status, and ownership rule is enforced server-side |
| Client-supplied `author` ID | **No** | Always derived from the authenticated session (`get_current_user_id()`) |
| Client-supplied `post_status` | **No** | Restricted to `draft` for writers; `publish` is blocked |
| Client-supplied file uploads | **No** | Validated by MIME allowlist and `getimagesize()` content inspection |
| Email link query parameters | **No** | Validated for format, SHA-256 hash match, TTL expiry, and account state |
| Reverse-proxy IP headers | **No** by default | `X-Forwarded-For` is ignored unless `penalis_trust_proxy_headers` is enabled |

---

## 3. Authentication & Session Controls

- **Cookie + Nonce Authentication:** Authenticated REST requests use WordPress
  login cookies and the `wp_rest` nonce sent in `X-WP-Nonce`. No JWTs, API keys,
  or tokens are stored in `localStorage` or `sessionStorage`.
- **Login Gate (`Penalis_Access_Control::block_login` on `authenticate`):**

| Account State | Allowed to Sign In? |Allowed to Write? |
| --- | --- | --- |
| Administrator | Yes | Yes (unrestricted) |
| `pending_verification` | Yes (status view only) | No |
| `pending_approval` | Yes (status view only) | No |
| `active` with `_penalis_access_enabled = 1` | Yes | Yes |
| `active` with `_penalis_access_enabled = 0` | No | No |
| `rejected` | No | No |
| `suspended` | No | No |
| Dormant legacy contributor (no status meta) | No | No |
| Legacy contributor with explicit `_penalis_access_enabled = 1` | Yes (native WP login) | No |
| `penalis_writer` role with missing status metadata | No | No |

Login failure messages are normalized to a single generic error so attackers
cannot enumerate account existence or status.

- **Immediate Session Destruction:** Transitioning an account to `rejected` or
  `suspended`, resetting a password, or changing a username immediately destroys
  all active `WP_Session_Tokens` for that user.
- **Password Reset Hardening:**
  - `POST /penalis/v1/password/forgot` accepts an **email address only**
    (`user_email`). Because usernames are public on `/author/<username>/`
    archives, usernames are never accepted as a reset handle.
  - `allow_password_reset` is filtered so dormant legacy contributors,
    `rejected` accounts, and `suspended` accounts cannot reset passwords to
    regain access.

---

## 4. Layered Authorization Enforcement

Penalis Platform enforces authorization across eight independent layers so a bug
or misconfiguration in one layer cannot expose private content or grant
unauthorized publishing:

1. **Account Status Check (`Penalis_Account_Status::can_write()`):** Requires
   both `_penalis_account_status === 'active'` and `_penalis_access_enabled === '1'`.
2. **Dynamic Capability Filter (`user_has_cap`):** Strips write capabilities
   from any restricted account that does not satisfy `can_write()`.
3. **Meta Capability Mapping (`map_meta_cap`):** Adds `do_not_allow` to:
   - `publish_post` for all writers (writers can never publish directly);
   - `edit_post` when the post is not owned by the writer, is already published,
     or is locked by its editorial state (`pending_review` or `rejected`);
   - `delete_post` when the post or attachment is not owned by the writer or is
     currently under review (`pending`).
4. **REST Dispatch Guard (`rest_pre_dispatch`):**
   - Blocks restricted users from administrative core routes (`/wp/v2/users`,
     `/wp/v2/settings`, `/wp/v2/plugins`, `/wp/v2/themes`, `/wp/v2/pages`,
     `/wp/v2/templates`, `/wp/v2/menus`, `/wp/v2/widgets`, etc.), allowing only
     `GET /wp/v2/users/me` and `GET /wp/v2/block-types`.
   - Blocks cross-writer reads of single posts and media items by ID (`404`).
   - Blocks non-allowlisted write routes and verifies media parent ownership
     before upload processing begins.
5. **REST Post & Media Insert Filters (`rest_pre_insert_post`, `rest_pre_insert_attachment`):**
   - Forces `post_author` to the authenticated session user.
   - Refuses `status: publish` (`403 penalis_publish_forbidden`).
   - Refuses edits to articles where `Penalis_Editorial_Workflow::can_writer_edit()`
     is false.
   - Rejects privileged post fields (`sticky`, `password`, `meta`, `template`).
   - Verifies that `featured_media` belongs to the authenticated writer.
6. **Core Insert Data Guard (`wp_insert_post_data`):** Last-line defense that
   downgrades any non-draft status (`publish`, `future`, `private`) to `draft`
   if triggered by a writer outside the administrator approval workflow.
7. **List Query Forcing (`rest_post_query`, `rest_attachment_query`):** Forces
   `author = get_current_user_id()` on post and media collection queries for
   restricted users.
8. **Upload Content Validation (`wp_handle_upload_prefilter`):** Restricts
   writer uploads to `image/jpeg`, `image/png`, `image/gif`, and `image/webp`,
   and inspects file headers via `getimagesize()` to block renamed scripts or
   SVG payloads.

---

## 5. Editorial Workflow Security Invariants

- **Publication is Administrator-Only:** Only `Penalis_Editorial_Workflow::apply()`
  with action `approve` by a user satisfying `Penalis_Editorial_Policy::can_review()`
  (administrator + `edit_others_posts`) can transition a writer submission to
  `publish`.
- **Published Articles are Immutable to Writers:** `Penalis_Editorial_Policy::effective_state()`
  reconciles stored `_penalis_editorial_state` against WordPress `post_status`.
  Any post with `post_status === 'publish'` is treated as `published` regardless
  of post meta, preventing writers from editing live articles.
- **Per-Post Advisory Lock for Review Decisions:** `Penalis_Editorial_Workflow::apply()`
  acquires a MySQL advisory lock (`GET_LOCK`, 5-second timeout) per post,
  re-reads the post and `_penalis_review_cycle` inside the lock, and rejects
  stale or concurrent decisions (`penalis_editorial_denied` or
  `penalis_editorial_busy`).
- **IDOR & Information Disclosure Prevention:**
  - `GET /penalis/v1/articles/{id}` and `GET /penalis/v1/articles/{id}/review`
    return `404 penalis_not_found` (never `403`) when accessed by another
    writer, avoiding existence leaks.
  - Editorial notifications (`_penalis_notifications`) and transactional emails
    are strictly recipient-scoped (`can_receive_notification()`) and never
    include private `post_content`.

---

## 6. Cryptographic Tokens & Credential Changes

### 6.1 Plugin-Managed Tokens
Used for email verification, legacy contributor activation, and email address
changes:
- **Entropy & Format:** 32 bytes from `random_bytes()`, encoded as 43-character
  URL-safe base64 (`Penalis_Policy::is_token_format_valid()`).
- **Storage:** Only the ` hash('sha256', $token) ` value is stored in user meta;
  raw tokens exist only in the outgoing email link.
- **Scanner-Safe Consumption:** Visiting a `/dashboard/?penalis_verify=...` or
  `?penalis_email_change=...` link renders an explicit confirmation button; the
  token is consumed only via `POST` when the user clicks confirm, preventing
  automated mail scanners from burning single-use links.
- **Expiry:** 24-hour TTL (`86400` seconds), single use.

### 6.2 Verified Email Address Change
- Requires current password re-authentication (`POST /penalis/v1/account/email`).
- Stores candidate email in `_penalis_email_change_new_email` and sends the
  confirmation link exclusively to the **new** email address.
- Re-checks email uniqueness upon confirmation (`POST /penalis/v1/account/email/confirm`),
  updates `user_email`, and sends a security alert to the **previous** email
  address.
- Direct edits to `user_email` via profile updates are reverted by
  `Penalis_Access_Control::prevent_direct_email_change()`.

### 6.3 Re-Authenticated Username Change
- Requires current password re-authentication (`POST /penalis/v1/account/username`).
- Validates strict username format (`3–50` lowercase ASCII alphanumeric, `_`,
  or `-`; no dots; never silently normalized).
- Executes inside a MySQL advisory lock (`GET_LOCK('penalis_username_change')`)
  to prevent race conditions on `user_login` and `user_nicename`.
- Records the old slug in `_penalis_author_history` (serving a 301 redirect for
  old author archive URLs) and `_penalis_author_history_reserved` (blocking
  other users from claiming the old username).
- Clears user caches, destroys all sessions, clears auth cookies, and emails a
  security notice.

---

## 7. Rate Limits & Abuse Controls

Transient-based rate limits (`Penalis_Rate_Limiter`):

| Operation | Scope | Limit |
| --- | --- | --- |
| Registration | Per IP | 5 / hour |
| Email verification confirm | Per IP | 20 / hour |
| Verification resend | Per IP / Per account | 5 / hour per IP, 1 / 10 min per account |
| Legacy activation request | Per IP / Per email | 5 / hour per IP, 3 / hour per email |
| Legacy activation confirm | Per IP | 10 / hour |
| Password forgot request | Per IP / Per account | 5 / hour per IP, 3 / hour per account |
| Password reset submit | Per IP | 10 / hour |
| Password change (Settings) | Per user | 5 / hour |
| Email change request | Per IP / Per user | 5 / hour per IP, 3 / hour per user |
| Email change confirm | Per IP | 10 / hour |
| Username change | Per IP / Per user | 5 / hour per IP, 3 / hour per user |
| Tag creation | Per user | 30 / hour |

---

## 8. Production Operational Recommendations

1. **HTTPS Everywhere:** Serve the entire site and `/dashboard/` over HTTPS so
   WordPress authentication cookies and REST nonces are encrypted in transit.
2. **SMTP Delivery:** Configure a reliable transactional SMTP provider and
   monitor `_penalis_last_email_failed_at` badges in **Penalis → Penulis Penalis**.
3. **Cache Exclusions:** Exclude `/dashboard/*` and `/wp-json/penalis/*` from
   CDN and full-page caches. The plugin sends `Cache-Control: private, no-store`
   and `X-Robots-Tag: noindex`, which reverse proxies must honor.
4. **WAF / Edge Rate Limiting:** Transient rate limits operate per WordPress
   instance; pair them with Cloudflare or Nginx rate limiting on public
   authentication endpoints in production.

