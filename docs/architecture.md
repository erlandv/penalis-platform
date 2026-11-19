# Architecture & Data Model

Penalis Platform is a self-contained WordPress plugin that powers the writer
account lifecycle, editorial submission and review pipeline, and frontend React
writing dashboard (`/dashboard/`) for the [Penalis](https://penalis.com)
literary community.

---

## 1. System Overview

```text
WordPress (PHP)                               Browser (React SPA)
  penalis-platform.php                          /dashboard/ page
  roles, account & editorial state    <---->    AuthGate, Overview, Articles,
  REST: penalis/v1 (workflow)                   Editor (TipTap), Media, Profile,
  core wp/v2 (content CRUD)                     Settings
  wp-admin curation console (Penalis)
  emails, rate limits, access guards
```

- **No separate backend server, no custom database tables, and no parallel
  authentication store.** Authentication relies on standard WordPress cookies
  and the `wp_rest` nonce passed in `X-WP-Nonce`.
- **Articles are native WordPress `post` records.** `post_content` stores plain
  HTML produced by TipTap, featured images use standard post thumbnails, and
  categories/tags use native taxonomies. The active WordPress theme renders
  published articles and author archives without modification.
- **Scoped asset loading.** The React dashboard is mounted via the
  `[penalis_dashboard]` shortcode on the `/dashboard/` page and rendered through
  `templates/dashboard.php`. Dashboard scripts and styles are enqueued only on
  that page, never site-wide.

---

## 2. Repository Structure

```text
penalis-platform/
├── penalis-platform.php                  Plugin bootstrap, constants, autoloader, hooks
├── uninstall.php                         Non-destructive-by-default uninstall handler
├── includes/
│   ├── class-plugin.php                  Singleton container, upgrade runner, admin notices
│   ├── class-activator.php               Role registration, dashboard page setup, legacy slug rename
│   ├── class-deactivator.php             Transient cleanup on deactivation
│   ├── class-roles.php                   penalis_writer role definition and promotion/demotion
│   ├── class-policy.php                  Pure account lifecycle, validation, and challenge rules
│   ├── class-account-status.php          Account status persistence, transitions, eligibility & advisory lock helpers, audit log
│   ├── class-otp.php                     Purpose-bound six-digit verification challenges
│   ├── class-editorial-policy.php        Pure editorial state machine, review cycle, notification rules
│   ├── class-editorial-workflow.php      Serialized editorial transitions, shared state query/count helpers, and post meta persistence
│   ├── class-editorial-notifications.php Bounded dashboard notifications and editorial email dispatch
│   ├── class-article-takedown.php        Published-article deletion guard, trash audit, reviewer report & restore helpers
│   ├── class-revisions.php               Revision limit for drafts so autosave cannot grow the history without bound
│   ├── class-editorial-admin.php         Penalis → Review Karya Tulis and Karya Terhapus admin screens
│   ├── class-admin.php                   Penalis top-level menu, Ringkasan overview, Penulis Penalis screen, shared modal & asset helpers
│   ├── class-analytics.php               Eight-week submission/review trend metrics for the Ringkasan screen
│   ├── class-email-settings.php          Penalis → Pengaturan Email customizable transactional email registry & admin console
│   ├── class-email-log.php               Capped delivery log for transactional email; never stores one-time secrets
│   ├── class-email-log-admin.php         Penalis → Log Email screen: test send, template preview, resend
│   ├── class-access-control.php          Login gate, capability filters, REST guards, upload validation
│   ├── class-rest-api.php                penalis/v1 REST endpoints
│   ├── class-registration.php            Self-service writer registration and email verification
│   ├── class-legacy-activation.php       Legacy contributor self-service and admin activation
│   ├── class-password-reset.php          Dashboard forgot/reset password flow
│   ├── class-email-change.php            Verified email address change flow
│   ├── class-username-change.php         Re-authenticated login rename with advisory lock
│   ├── class-author-history.php          Historical author slug reservation and 301 redirects
│   ├── class-avatar.php                  Avatar storage and fallback provider (One User Avatar compatible)
│   ├── class-datetime.php                Site timezone-aware date and time formatting helpers
│   ├── class-dashboard.php               Dashboard page, shortcode, template, shared Vite manifest/module loader & SPA mount helper
│   ├── class-authors.php                 Public authors directory ([penalis_authors]) and verified badge integration
│   ├── class-emails.php                  Centralized template-driven transactional email dispatcher and failure tracking
│   ├── class-email-template.php          Branded responsive HTML email layout and Markdown renderer
│   ├── class-rate-limiter.php            Transient-based per-IP and per-account rate limiting, configurable limits, proxy-aware client IP
│   └── class-turnstile.php               Optional Cloudflare Turnstile adapter via Simple Cloudflare Turnstile
├── assets/
│   ├── css/admin.css                     WordPress admin stylesheet (Ringkasan, Review Karya Tulis, Penulis Penalis, Pengaturan Email)
│   └── js/admin-review.js                Shared modal controller (createModalController) and inline validation for wp-admin actions
├── templates/
│   ├── dashboard.php                     Minimal theme-compatible wrapper for [penalis_dashboard]
│   └── authors.php                       Minimal theme-compatible wrapper for [penalis_authors]
├── src/
│   ├── app/                              React entry point (index.tsx) and root router (App.tsx)
│   ├── authors/                          Separate public React entry point for [penalis_authors] directory (authors.css)
│   ├── components/                       Shared UI primitives (ui.tsx), Dialog, ConfirmDialog, MediaSelection/MediaPickerDialog, OtpForm, PasswordInput, PasswordMatchHint, PasswordPairFields, PasswordStrengthMeter, Toast, Toggle, TurnstileWidget
│   ├── features/                         Feature views: auth, overview, articles, editor, media, profile, settings
│   ├── lib/                              Typed API client, validation, OTP challenge helpers, deeplinks, HTML/profile/crop helpers
│   └── styles/                           Dashboard CSS: tokens.css, main.css (@import index), section files
├── build/                                Generated production Vite bundle and manifest (gitignored)
├── tests/
│   ├── run-tests.php                     Zero-dependency PHP unit and workflow test suite
│   └── wp-stubs.php                      Minimal WordPress stubs for isolated PHP testing
└── docs/                                 Developer documentation
```

### Autoloading & Boot Order

Classes prefixed with `Penalis_` autoload from `includes/class-<slug>.php`
(for example, `Penalis_Editorial_Workflow` maps to
`includes/class-editorial-workflow.php`). No Composer autoloader is needed in
production.

On `plugins_loaded`, `Penalis_Plugin::run()` initializes components in order:
1. `Penalis_Plugin::maybe_upgrade()`
2. `Penalis_Access_Control::init()`
3. `Penalis_Editorial_Notifications::init()`
4. `Penalis_Rest_Api::init()`
5. `Penalis_Dashboard::init()`
6. `Penalis_Authors::init()`
7. `Penalis_Author_History::init()`
8. `Penalis_Avatar::register_provider()`
9. `Penalis_Admin::init()`, `Penalis_Editorial_Admin::init()`, and `Penalis_Email_Settings::init()` (when `is_admin()`)

### Optional Third-Party Integrations

- **Simple Cloudflare Turnstile (`Penalis_Turnstile`):** When the third-party
  *Simple Cloudflare Turnstile* plugin is active and configured (`cfturnstile_key`
  and `cfturnstile_secret`), `Penalis_Turnstile` exposes its widget settings to
  `window.penalisDashboard.turnstile` and validates tokens on `AuthGate`
  (`LoginForm`, `RegistrationForm`, `ActivateForm`, `ForgotForm`) and
  `ResetPasswordPage` (`Reset`). All calls are guarded with `function_exists()`
  so Penalis Platform degrades gracefully when the plugin is absent or inactive.

---

## 3. Account Lifecycle Model

Account status is managed by `Penalis_Account_Status` and validated by
`Penalis_Policy`.

### 3.1 Statuses

| Status | Meaning | Dashboard UI Label |
| --- | --- | --- |
| `pending_verification` | Registered, email not yet confirmed | Menunggu verifikasi email |
| `active` | Verified active writer with dashboard write access | Penulis aktif |
| `suspended` | Writer account whose access was revoked by an administrator | Akun ditangguhkan |

Reviewers (administrators and editors) are created in `wp-admin` and have no
Penalis status metadata. They are not a fourth status: the dashboard API admits
them through `edit_others_posts`, reports `isReviewer: true`, and keeps
`canWrite: false`, so `/dashboard/` opens in reviewer mode instead of showing
"Akun tidak tersedia".

Reviewer mode is not read-only any more: the queue is worked in the dashboard
(§7.16). A reviewer-only account sees a different overview — hero copy that
names their role, an "Antrean review" panel with the pending count and a CTA,
and notifications. The writer panels (article counts, "Karya terbaru") are
hidden rather than shown at zero, and there is no standing banner telling them
the dashboard is not for them.

```text
pending_verification ──► active ◄──► suspended
         │                              ▲
         └──────────────────────────────┘
```

Allowed transitions (`Penalis_Policy::can_transition()`):

| From | Allowed Target(s) |
| --- | --- |
| `pending_verification` | `active`, `suspended` |
| `active` | `suspended` |
| `suspended` | `active` |

Every transition updates `_penalis_account_status`, appends an entry to the
bounded audit log (`_penalis_status_log`, max 20 entries), and fires
`penalis_account_status_changed`.

### 3.2 Capabilities & Role Management

- New registrations are created with the WordPress `subscriber` role,
  `_penalis_account_status = pending_verification`, and
  `_penalis_access_enabled = 0`.
- Verifying the email address (or reactivating a suspended account) transitions
  the account to `active`, sets `_penalis_access_enabled = 1`, and promotes the
  user to `penalis_writer` (`Penalis_Roles::assign_writer()`).
- Suspending an account (`active -> suspended` or `pending_verification -> suspended`)
  sets `_penalis_access_enabled = 0`, clears any pending verification challenge,
  demotes `penalis_writer` back to `subscriber`
  (`Penalis_Roles::revoke_writer()`), and destroys all active WordPress session
  tokens for that user.
- Higher roles (`author`, `editor`, `administrator`) are never promoted or
  demoted automatically; attempting to promote such an account returns
  `penalis_role_conflict`.

### 3.3 Legacy Contributor Accounts

Before Penalis Platform existed, administrators manually created `contributor`
accounts without passwords to attribute community submissions. These accounts
have no `_penalis_account_status` metadata (`Penalis_Account_Status::is_legacy_account()`).

- Dormant legacy accounts cannot sign in, cannot reset passwords, and have no
  write capabilities.
- Legacy accounts can only enter the active workflow through conversion
  (`Penalis_Account_Status::convert_legacy()`), which preserves the user ID,
  authored posts, and author archive URL:
  - **Self-service activation:** The writer requests an activation code, proves
    control of their email address, sets a first password, and immediately
    becomes `active` (`penalis_writer` role, `_penalis_access_enabled = 1`).
  - **Administrator vouch ("Aktifkan langsung"):** An administrator converts the
    account directly to `active` (`penalis_writer` role,
    `_penalis_access_enabled = 1`), sets `_penalis_needs_password = 1`, and
    triggers a password reset email so the writer can set a password.
- **Registration collision handling:** If a visitor attempts to register with an
  email address belonging to a dormant legacy contributor, the registration
  endpoint preserves anti-enumeration by returning a generic success challenge,
  while sending a dedicated guidance notice (`legacy_register_notice`) to the
  contributor's email address with a direct deep-link to the activation form
  (`/dashboard/?penalis_activate=1`). Usernames are checked explicitly for
  availability; conflicting usernames return an immediate field error.

---

## 4. Editorial Workflow Model

Writers never publish directly and cannot edit published articles. All writer
submissions pass through editorial review (`Penalis_Editorial_Policy` and
`Penalis_Editorial_Workflow`). Reviewing requires `edit_others_posts`, which
administrators and editors both hold.

### 4.1 Editorial States & Post Status Mapping

| Editorial State (`_penalis_editorial_state`) | WordPress `post_status` | Writer Can Edit? | UI Label |
| --- | --- | --- | --- |
| `draft` | `draft` | Yes | Draft |
| `pending_review` | `pending` | No | Menunggu review |
| `changes_requested` | `draft` | Yes | Perlu revisi |
| `rejected` | `draft` | No | Ditolak |
| `published` | `publish` | No | Terbit |

```text
draft ──(submit)──► pending_review ──(approve)──► published
                          │
                          ├──(request_changes)──► changes_requested ──(resubmit)──► pending_review
                          │
                          └──(reject)───────────► rejected ──(reopen)────────────► changes_requested
```

### 4.2 Transition Rules & Invariants

| Action | Actor | From State | To State | Feedback |
| --- | --- | --- | --- | --- |
| `submit` | Article owner (active writer) | `draft` | `pending_review` | N/A |
| `resubmit` | Article owner (active writer) | `changes_requested` | `pending_review` | Clears active feedback |
| `approve` | Reviewer (administrator or editor) | `pending_review` | `published` | Optional |
| `request_changes` | Reviewer (administrator or editor) | `pending_review` | `changes_requested` | **Required** |
| `reject` | Reviewer (administrator or editor) | `pending_review` | `rejected` | **Required** |
| `reopen` | Reviewer (administrator or editor) | `rejected` | `changes_requested` | Optional |

Key invariants:
- **Reconciliation against `post_status`:** `Penalis_Editorial_Policy::effective_state()`
  always reconciles stored metadata with the underlying WordPress `post_status`.
  Any post with `post_status = publish` (including articles published before the
  plugin was installed) resolves to `published` and is read-only to writers.
- **Review cycles & serialized decisions:** Each `submit` or `resubmit`
  increments `_penalis_review_cycle`. Reviewer decisions (`approve`,
  `request_changes`, `reject`) acquire a per-post MySQL advisory lock
  (`GET_LOCK`, 5-second timeout), re-read the post inside the lock, and verify
  `expected_cycle` so two reviewers cannot apply conflicting decisions to the
  same submission round.
- **Notifications:** Every transition fires `penalis_editorial_action` exactly
  once. That hook is the only dispatch path: `Penalis_Editorial_Notifications`
  registers the default listener during plugin bootstrap, and the workflow
  service never calls the listener directly, so removing or re-registering the
  callback cannot silently duplicate or suppress notifications. Both channels
  are opt-out per action: `_penalis_notification_prefs` stores a `dashboard`
  and an `email` matrix edited from **Pengaturan → Preferensi Notifikasi**, and
  `wants()` is checked before a notification row is written or a decision mail
  is sent. Omitted actions stay enabled, and the takedown report to reviewers
  stays unconditional because it is an audit message, not a workflow notice.
  `Penalis_Editorial_Notifications` records a bounded in-dashboard notification
  (`_penalis_notifications`, max 50 per user) and dispatches a branded HTML
  email via `Penalis_Emails`. Mail failures are recorded on the author's account
  without rolling back the editorial transition. Because the list is one
  serialized user-meta array, `record_for_user()` and `mark_read()` perform
  their read-modify-write cycle inside a per-recipient MySQL advisory lock
  (`GET_LOCK`, 5-second timeout) and re-read the list inside it, so two
  concurrent submissions for the same reviewer cannot drop each other's entry.
  `mark_read()` is fail-closed: when the lock is busy the stored list is left
  untouched while the endpoint still answers `200`, so the dashboard replaces
  its optimistic read receipt with the authoritative response instead of
  assuming the write landed.
- **Notification surfaces:** The dashboard exposes the same list in three
  places, all fed by one `useNotifications()` controller in `App.tsx` so the
  unread badge can never disagree with the feed: the header bell
  (`NotificationBell.tsx`, a compact dropdown), the **Notifikasi** view
  (`features/notifications/Notifications.tsx`, full feed with an all/unread
  filter, bulk read receipt, and optional per-article grouping), and the
  overview panel. The list refreshes on `focus`/`visibilitychange` rather than
  on a timer, because `count_unread()` re-checks every stored entry against its
  current post. A notification with revision notes deep-links to the editor's
  feedback panel through `penalis_focus=feedback`.

---

## 5. WordPress Admin Console

All curation surfaces live under a single top-level menu, **Penalis**
(`admin.php?page=penalis-platform`). The menu requires `edit_others_posts`, so
both administrators and editors see it; writers never do. Account actions stay
administrator-only (`list_users`), and every site-wide setting lives behind
`manage_options` on **Pengaturan**, which makes **Penulis Penalis** read-only for
editors.

1. **Penalis → Ringkasan** (`?page=penalis-platform`, `Penalis_Admin::render_overview_page()`)
   - Summary metric cards for pending article reviews, revisions, published
     works, active writers, unverified registrations, suspended accounts, and
     legacy accounts, plus quick-action panels for the latest pending article
     submissions and newly registered writers.
2. **Penalis → Review Karya Tulis** (`?page=penalis-review`, `Penalis_Editorial_Admin::render_page()`)
   - Filter tabs by editorial state (`Menunggu review`, `Perlu revisi`,
     `Ditolak`, `Terbit`) and a filter toolbar for search (title/body), author,
     category, submitted-date range, review cycle, sort field, and sort
     direction. Active filters are preserved across tabs, pagination, the
     detail screen, and the post-decision redirect; tab counts stay global.
   - A 2-column detail review workspace with the rendered article preview,
     metadata header, unified decision form with an accessible confirmation
     modal, link to the native WordPress editor for pre-publication corrections,
     and a vertical review history timeline.
3. **Penalis → Karya Terhapus** (`?page=penalis-trashed`, `Penalis_Editorial_Admin::render_trashed_page()`)
   - Recovery console for published articles that entered the WordPress trash.
     Each entry records the takedown time, acting user, and original editorial
     state; a reviewer can restore it through `wp_untrash_post()` with an
     explicit confirmation. Untrashing from the native Posts screen clears the
     same record, so the console never shows an already-live article.
4. **Penalis → Penulis Penalis** (`?page=penalis-writers`, `Penalis_Admin::render_page()`)
   - Account management table with status tabs (`Semua yang dikelola`, `Aktif`,
     `Menunggu verifikasi`, `Ditangguhkan`, `Akun lama`), search toolbar,
     Instagram handle column (`@username`), collapsible audit history, and
     confirmation modals for all account actions (`Tangguhkan`,
     `Aktifkan kembali`, `Kirim ulang verifikasi`, `Kirim link aktivasi`,
     `Aktifkan langsung`). Site-wide settings moved to **Pengaturan**; the screen
     only links there.
5. **Penalis → Pengaturan** (`?page=penalis-settings`, `Penalis_Settings::render_page()`)
   - Centralized home for every site-wide setting, administrator-only
     (`manage_options`). One tab per setting group, rendered server-side
     (`&tab=<slug>`) so a tab can be linked, bookmarked, and restored after
     save. `Penalis_Settings::tabs()` is the registry; a new group only adds an
     entry plus a renderer.
   - **Keamanan** (`captcha`, `Penalis_Settings::render_captcha_tab()`) holds the
     dashboard CAPTCHA switch (`penalis_dashboard_captcha_enabled`) and a
     read-only summary of the Simple Cloudflare Turnstile configuration.
   - **Foto Profil** (`avatar`, `Penalis_Settings::render_avatar_tab()`) sets the
     site-wide default profile photo through the WordPress media picker; saving
     is handled by `Penalis_Admin::save_default_avatar()`.
   - **Email** (`email`, `Penalis_Email_Settings::render_tab()`) manages all 15
     transactional emails, accessible only to administrators:
     - Categorized tabbed navigation (`Semua Email`, `Verifikasi & Aktivasi`,
       `Keamanan Akun`, `Status Penulis`, `Kurasi Karya Tulis`).
     - Customization fields for subject, body, and per-email contextual footer.
     - Rich Markdown formatting with live toolbar buttons for bold (`**text**`),
       inline links (`[Text](url)`), and one-click tag insertion chips.
     - Built-in safety net that guarantees critical secret placeholders (such as
       `{kode_otp}` and `{link_reset_password}`) cannot be lost.
     - Single-template and all-template "Kembalikan ke Bawaan Plugin" reset
       actions.
   - The retired `?page=penalis-email-settings` URL redirects to
     `?page=penalis-settings&tab=email` on `admin_init`, so bookmarks and old
     notices keep working.
   - Save feedback is centralized in `Penalis_Settings::message_for()` /
     `error_for()`; tab handlers redirect back with `penalis_message` or
     `penalis_error` instead of rendering their own notices.

---

## 6. Data Storage Reference

No custom database tables are created.

### 6.1 User Meta

| Meta Key | Purpose |
| --- | --- |
| `_penalis_account_status` | Current account lifecycle status |
| `_penalis_access_enabled` | Explicit access gate (`1` or `0`) |
| `_penalis_email_verified` | Email verification flag (`1` or `0`) |
| `_penalis_registered_at` | Unix timestamp of registration |
| `_penalis_approved_at` | Unix timestamp of administrator approval |
| `_penalis_approved_by` | User ID of the approving administrator |
| `_penalis_status_log` | Bounded array of status transitions (max 20) |
| `_penalis_otp_registration` | Pending registration verification challenge (opaque ID, HMAC digest, expiry, attempts) |
| `_penalis_otp_legacy` | Pending legacy activation challenge |
| `_penalis_otp_email_change` | Pending email-change challenge |
| `_penalis_email_change_new_email` | Candidate email address awaiting confirmation |
| `_penalis_display_name_mode` | Public name mode (`auto`, `first`, `last`, `username`, `custom`) |
| `_penalis_instagram` | Normalized optional Instagram handle (without leading `@`, max 30 chars) for internal admin use |
| `_penalis_author_history` | Historical author slugs for 301 redirects |
| `_penalis_author_history_reserved` | Indexed reservation rows preventing slug reuse |
| `_penalis_needs_password` | Flag (`1`) for admin-vouched legacy accounts without a password |
| `_penalis_last_email_failed_at` | Timestamp of most recent outgoing mail failure |
| `_penalis_last_email_failed_context` | Context identifier of most recent mail failure |
| `_penalis_notifications` | Bounded array of editorial notifications (max 50) |
| `{prefix}user_avatar` | Profile photo attachment ID (shared with One User Avatar) |

### 6.2 Post Meta

| Meta Key | Purpose |
| --- | --- |
| `_penalis_editorial_state` | Editorial state (`draft`, `pending_review`, `changes_requested`, `rejected`, `published`) |
| `_penalis_review_feedback` | Active reviewer feedback note for the writer |
| `_penalis_submitted_at` | Timestamp of latest writer submission/resubmission |
| `_penalis_submitted_by` | User ID of submitting writer |
| `_penalis_reviewed_at` | Timestamp of latest reviewer decision |
| `_penalis_reviewed_by` | User ID of the reviewer (administrator or editor) |
| `_penalis_review_cycle` | Integer counter incremented on each submission round |
| `_penalis_review_history` | Bounded array of submission and review events (max 50) |

### 6.3 Options & Transients

| Key | Kind | Purpose |
| --- | --- | --- |
| `penalis_platform_version` | Option | Installed plugin version for `maybe_upgrade()` |
| `penalis_registration_enabled` | Option | Public writer registration switch (`1`/`0`, default `1`), managed on **Pengaturan → Pendaftaran** |
| `penalis_registration_audit` | Option | Last registration switch change: `state`, `user_id`, `user_login`, `time` |
| `penalis_email_templates` | Option | Serialized associative array containing custom subjects, bodies, and footers for transactional emails |
| `penalis_dashboard_page_id` | Option | Page ID of the `/dashboard/` page |
| `penalis_dashboard_page_created_by_plugin` | Option | Guard ensuring uninstall only removes a plugin-created page |
| `penalis_remove_data_on_uninstall` | Option | Opt-in flag (`1`/`0`, default `0`) for destructive uninstall, managed on **Pengaturan → Data** |
| `penalis_remove_data_audit` | Option | Last uninstall opt-in change: `state`, `user_id`, `user_login`, `time` |
| `penalis_dashboard_captcha_enabled` | Option | Dashboard-only CAPTCHA switch (`1`/`0`, default `1`). Never mirrors `cfturnstile_login`/`_register`/`_reset` |
| `penalis_dashboard_captcha_audit` | Option | Last CAPTCHA switch change: `state`, `user_id`, `user_login`, `time` |
| `avatar_default_wp_user_avatar` | Option | Default profile photo attachment ID (shared with One User Avatar) |
| `penalis_pending_review_count` | Transient | Cached count of `pending_review` article submissions |
| `penalis_activation_notice` | Transient | Short-lived post-activation admin notice flag |
| `penalis_dup_notice_<id>` | Transient | One-hour throttle for duplicate registration and legacy notices |
| `penalis_rl_<md5>` | Transient | Rate-limiter bucket counters |

---

## 7. Key Technical Decisions

### 7.1 Vite Instead of `@wordpress/scripts`
The writer dashboard (`/dashboard/`) and the public authors directory
(`[penalis_authors]`) are standalone React SPAs, not Gutenberg plugins inside
`wp-admin`. Vite builds both entry points (`src/app/index.tsx` and
`src/authors/index.tsx`), splits the TipTap editor into a lazy-loaded chunk, and
outputs `build/manifest.json`. Both SPAs share the manifest/asset helpers on
`Penalis_Dashboard` (`enqueue_vite_entry()`, `ensure_same_origin_url()`,
`format_module_script_tag()`, `resolve_bundled_template()`, and
`render_spa_mount()`).

`ensure_same_origin_url()` compares full origins (`scheme://host[:port]`), not
bare hosts, and rebuilds from the complete `home_url()` origin, so an off-site
CDN URL (`cdn.penalis.com`), a non-standard port, or an `http`/`https` mismatch
is normalised to the site origin instead of being left cross-origin. Cross-origin
ES modules are blocked without `Access-Control-Allow-Origin`, and an `http`
module on an `https` page is blocked as mixed content; either case leaves the
dashboard and authors directory root empty with no visible error.

### 7.2 TipTap Instead of Gutenberg
Running Gutenberg outside `wp-admin` requires the `@wordpress/*` block registry
and `unfiltered_html` for several blocks. TipTap is headless, MIT-licensed,
lazy-loaded (`React.lazy`), and serializes directly to standard HTML in
`post_content`, with a raw HTML toggle so existing markup is never silently
rewritten. Server-side `wp_kses` remains the sole authority on allowed HTML.

For mobile-first writing ergonomics, `EnterHardBreak`
(`src/features/editor/enterHardBreak.ts`) overrides `Enter` inside non-empty
paragraphs (outside lists) so a single `Enter` inserts a `<br>` line break and
a double `Enter` splits into a new `<p>` paragraph, while headings, lists, and
empty paragraphs keep default TipTap behavior.

### 7.3 One User Avatar Coexistence
Profile photo attachment IDs are stored in `{prefix}user_avatar`, the exact
meta key used by the One User Avatar plugin. While One User Avatar is active, it
serves all avatars; when it is inactive, `Penalis_Avatar` hooks into
`pre_get_avatar_data` and serves them from the same metadata with zero
migration.

### 7.4 Hardcoded Indonesian UI Copy
Penalis is an Indonesian literary community. User-facing copy in the dashboard,
admin screens, and transactional emails is written in natural Indonesian without
a `gettext` translation layer, while code, comments, and developer documentation
are written in English.

Common technical terms and web/publishing concepts are intentionally retained in
English where Indonesian literal translations feel unnatural or awkward to
writers (e.g., *login*, *logout*, *dashboard*, *password*, *link*,
*featured image*, *publish*, *draft*, *review*, *submit*, *read-only*,
*upload*, *download*, *views*, etc.).

### 7.5 Customizable Transactional Emails & Markdown Support
Transactional emails can be adjusted by non-technical administrators via
`Penalis_Email_Settings` without modifying PHP code.
- **Unified Registry:** All 17 email types define standard defaults, metadata,
  allowed variables (`{tag}`), and mandatory required tags.
- **Markdown & CTA Buttons:** Body copy supports inline links
  (`[WhatsApp Group](url)`), bold text (`**bold**`), and standalone links
  rendered as branded table CTA buttons.
- **Safety Net:** Required secret placeholders (`{kode_otp}`,
  `{link_reset_password}`, `{link_review_admin}`, `{link_editor}`) cannot be
  accidentally removed; if absent from a customized body, they are automatically
  appended during save and before dispatch.
- **Recipient Isolation:** Reviewer notifications are dispatched individually in
  a loop rather than pooled via CC/BCC, ensuring private delivery across
  administrators, editors, and the site notification address.
- **Delivery log (Penalis → Log Email):** every attempt that passes through
  `Penalis_Emails::send_mail()` is recorded with its recipient, template,
  subject, status, and — when `wp_mail()` failed — the reason published by the
  `wp_mail_failed` action. The boolean `wp_mail()` returns cannot tell a broken
  mail transport from a mistyped address; the reason can.
- **The log is capped, not archived:** it lives in one option
  (`penalis_email_log`), newest first, capped at 100 entries, and two sends
  finishing in the same instant can drop one entry. That is the trade for having
  no schema and no migration; clearing it is one `delete_option()`. It is also
  removed by the opt-in uninstall, because it stores recipients' addresses.
- **Secrets are never stored:** `{kode_otp}`, `{link_reset_password}`, and
  `{link_aktivasi}` are one-time credentials until they expire or are used. An
  entry carrying any of them stores no payload at all and is not resendable,
  because the message it produced cannot be reproduced.
- **Preview and test send use sample data:** `sample_variables()` fills every
  tag the registry declares with a readable stand-in, so an administrator can
  check the branded layout without minting a working code. Resending re-renders
  the stored payload, which is why only non-secret, non-test entries offer it.
- **Two tabs, not one long page:** *Riwayat pengiriman* opens first — it answers
  the question an administrator actually has — and *Kirim percobaan* holds the
  test form and preview. With a full 100-entry history, one page pushed the form
  below the fold; every action now redirects to the history tab so the entry
  that proves what happened is on screen.
- **Both options are removed on the opt-in uninstall:** `penalis_email_templates`
  (site content) and `penalis_email_log` (recipients' addresses) live in
  `uninstall.php`, so "remove data" means all of it.

### 7.6 Post Views Counter Integration
Penalis Platform integrates with the [Post Views Counter](https://wordpress.org/plugins/post-views-counter/)
plugin to surface article view statistics inside the `/dashboard/` React SPA
(`Overview.tsx`, `Articles.tsx`, and `Editor.tsx`) as well as the public
contributors directory (`[penalis_authors]` / `AuthorsDirectory.tsx`).
- **Per-Article Views:** `Penalis_Rest_Api::get_post_views()` reads lifetime
  totals via `pvc_get_post_views( $post_id )` for published posts
  (`post_status === 'publish'`). Unpublished articles (`draft`,
  `pending_review`, `changes_requested`, `rejected`) always return `0` so
  internal admin previews or stray database rows never leak into writer stats.
- **Author Total Views:** `Penalis_Rest_Api::author_total_views()` and
  `Penalis_Authors::get_author_views_map()` fetch the IDs of all published posts
  owned by the writer(s) and pass the `int[]` array to
  `pvc_get_post_views( $post_ids )`, computing total views in an aggregate SQL
  query (`WHERE id IN (...) AND type = 4 AND period = 'total'`) cached in the
  `pvc-get_post_views` object-cache group (and in the public authors directory
  transient).
- **Graceful Degradation:** Every call is guarded by
  `function_exists( 'pvc_get_post_views' )`. When Post Views Counter is
  deactivated or absent, all view counts safely fall back to `0`.

### 7.7 Frontend CSS Architecture
The dashboard and authors directory use plain CSS with shared custom
properties; there is no preprocessor, utility framework, or CSS-in-JS layer.
- **One token source:** `src/styles/tokens.css` declares brand, state, radius,
  and shadow tokens on both `.penalis-dashboard-root` and
  `.penalis-authors-root`. Each root overrides neutral values (border, text,
  muted, shadow) in its own entry stylesheet; the authors directory uses a
  lighter frame because it renders inside a public theme. A shared value must
  keep the same custom-property name in both roots.
- **Cascade-ordered sections:** `src/styles/main.css` is only an `@import`
  index. It loads `tokens.css` first, then `base`, `layout`, `components`,
  `forms`, `articles`, `editor`, `media`, `overlays`, `responsive`, `features`,
  and `notifications`. That order is the cascade order; do not reorder imports
  without checking selectors that rely on source order.
- **Vite inlines CSS imports:** Vite merges CSS `@import` statements into one
  stylesheet per entry, so `build/manifest.json` still lists one CSS file for
  `src/app/index.tsx` and one for `src/authors/index.tsx`. Importing a shared
  CSS file from a TSX entry instead makes Vite emit a separate CSS chunk that
  is not listed in the entry's `css` array. `Penalis_Dashboard::enqueue_vite_entry()`
  enqueues only that array, so such a stylesheet would never load in WordPress.
  Use CSS `@import` inside the entry stylesheet for shared CSS.
- **Admin CSS is separate:** `assets/css/admin.css` is loaded directly by
  `Penalis_Admin` and is not part of the Vite build. It keeps its own shared
  status-pill, avatar, and pagination primitives; do not inline those rules in
  PHP.
- **Fixed brand glyph:** the verified badge SVG uses a literal Twitter blue
  (`#1d9bf0`) in `base.css` rather than a token, because it is a fixed brand
  mark that must not be recoloured by a theme override.

### 7.8 Dashboard CAPTCHA Switch vs. Site-Wide Turnstile
`Penalis_Turnstile` adapts the Simple Cloudflare Turnstile plugin instead of
reimplementing verification. That plugin's options (`cfturnstile_login`,
`cfturnstile_register`, `cfturnstile_reset`) describe the *whole site*, not just
`/dashboard/`, so switching one of them off from Penalis would strip protection
from `wp-login.php` as well — the exact problem the dashboard switch exists to
avoid.
- **One Penalis-owned flag:** `penalis_dashboard_captcha_enabled` is applied
  inside `Penalis_Turnstile::is_enabled_for()`, after the third-party toggles
  are read. Rendering (`frontend_config()`, `enqueue_scripts()`) and server
  verification (`verify()`) both pass through that method, so a single flag
  silences widget, token, and verification together — no React change is
  needed, and the SPA simply receives `{ enabled: false }`.
- **`is_available()` is never gated:** it reports whether the third-party plugin
  is present and usable, so the settings screen can still explain the site-wide
  state while the dashboard is silenced.
- **Availability carries a reason:** `is_available()` is a thin wrapper around
  `availability()`, which returns `{ available, reason }` with one of
  `plugin_missing`, `keys_missing`, `untested`, `whitelisted`, `filtered`,
  `failover`, or `available`. Only the first three are configuration problems;
  whitelist rules, the outage failover, and the `cfturnstile_widget_disable`
  filter bypass Turnstile for a single request on a site that is otherwise fully
  protected. Never collapse them back into one boolean: the status card used to
  report a healthy site as "not configured" purely because the administrator
  reading the screen matched a whitelist rule.
- **Status card splits configuration from runtime:** `Penalis_Settings::turnstile_status_rows()`
  renders the six English rows (`Plugin`, `Configuration`, `Site key`,
  `Secret key`, `Protected WordPress forms`, `Current status`). The
  `Configuration` row ignores bypasses; the `Current status` row names them. The
  copy is English on purpose (see the language convention in `AGENTS.md`) while
  the rest of the tab stays Indonesian.
- **The login bypass is unconditional:** Simple Cloudflare Turnstile hooks
  `authenticate` whenever `cfturnstile_login` is on, and that check runs for
  every `wp_signon()` call, including the dashboard form. Penalis verifies the
  single-use token itself, so it always bypasses the third-party check around
  `wp_signon()`. Making that bypass conditional on the dashboard switch would
  break every dashboard login while the switch is off.

### 7.9 One Settings Screen
Site-wide settings used to live wherever the related feature did — email
templates on their own submenu, the default profile photo on **Penulis
Penalis**. Each new option therefore needed a new decision about placement, and
the curation screens slowly mixed account work with configuration.
- **Single entry point:** `Penalis_Settings` owns the screen shell (heading, tab
  navigation, save and error notices) and nothing else. A setting group is one
  entry in `tabs()` plus a renderer, which keeps the shell free of feature code.
- **Renderers live with their feature:** the Email tab is
  `Penalis_Email_Settings::render_tab()` and the avatar tab is
  `Penalis_Settings::render_avatar_tab()`. Handlers keep their own
  `admin_post_*` actions and redirect back with `penalis_message` /
  `penalis_error`, so a tab never renders its own notices.
- **Tab order encodes risk:** Keamanan, Pendaftaran, Foto Profil, Email, then
  Data. Destructive configuration is last so it is never one stray click away
  from the screens an administrator uses daily.
- **Every switch is audited:** the CAPTCHA switch, the registration switch, and
  the uninstall opt-in each write their own `*_audit` option (`state`,
  `user_id`, `user_login`, `time`) through one `record_audit()` helper. When a
  feature silently stops working, "who changed this and when" is the first
  question, and it should not need a separate log table.
- **Destructive settings need a typed confirmation:** enabling data removal
  requires typing `HAPUS`, and only on the off → on transition.
  `Penalis_Settings::is_data_confirmation_valid()` holds that rule so it is unit
  tested instead of living only inside the POST handler.
- **Registration is gated twice:** the server rejects signups when the switch is
  closed (`penalis_registration_closed`, 403), and the SPA hides the entry point
  *and* falls back to login for a stale `?penalis_register=1` deep link
  (`resolveEntryMode()` in `src/features/auth/authNav.ts`). Legacy activation,
  login, and password reset are deliberately untouched by the switch.
- **Server-rendered tabs:** `&tab=<slug>` survives reloads, can be bookmarked,
  and lets a redirect land on the right group. JavaScript tab switching is only
  used *inside* the Email tab, for the template groups.
- **Retired URLs redirect:** `?page=penalis-email-settings` forwards to
  `?page=penalis-settings&tab=email` on `admin_init` rather than being deleted,
  because bookmarks and old admin notices still point at it.

### 7.10 Autosave Without Browser Storage
A writer who loses a manuscript stops trusting the platform. The obvious fix —
mirroring the draft into `localStorage` — was rejected: the privacy policy
promises the dashboard never stores draft content on the device, and that
promise matters most on a shared computer or a borrowed phone.
- **Drafts live on the server:** autosave writes through the same
  `POST /wp/v2/posts` endpoint as the button, so no manuscript ever exists in
  `localStorage`, `sessionStorage`, or IndexedDB. The draft also follows the
  writer to another device, which device storage could never do.
- **Timing is a pure function:** `src/lib/autosave.ts` decides the delay —
  four seconds after the writer pauses, at most one write every 20 seconds, at
  most 60 seconds of unsaved work while typing continues, and 30 seconds of
  backoff after a failure. Keeping it out of the component is what makes the
  schedule testable.
- **The 60-second cap is WordPress's own default:** `AUTOSAVE_INTERVAL` is
  defined as `MINUTE_IN_SECONDS` in `wp-includes/default-constants.php`, so the
  dashboard matches the number an administrator already knows instead of
  inventing a second one.
- **The minimum gap is what makes that number true:** an interval is a maximum
  age, not a minimum spacing, so without the 20-second floor a writer who types
  in bursts would still save every few seconds and the interval would describe
  nothing.
- **Why 60 seconds is safe here but would not be without the other layers:**
  core's editor can afford the same interval because it mirrors the draft into
  `sessionStorage` between saves (`wp-includes/js/dist/editor.js`). Penalis
  keeps no local copy by design, so the hidden-tab flush and the recovery
  banner carry the weight that local autosave carries in Gutenberg.
- **A new article is created only when there is something to keep:** a title of
  three characters or roughly 40 visible characters of body text. Merely
  opening the editor still creates nothing.
- **The nonce is refreshed in place, never by reloading:** `refreshNonce()`
  calls core's `admin-ajax.php?action=rest-nonce`, which doubles as a session
  probe — a nonce means the session is alive, `0` means it is gone. Writes
  retry once with the new nonce, so a stale tab heals itself. Reloading is the
  one recovery path that would destroy the very text it is meant to save.
- **Failures surface where the writer is looking:** the sticky action bar
  carries `Gagal menyimpan — sesi berakhir`, and the recovery banner keeps the
  text in the tab behind three actions: retry, sign in from a new tab, or copy
  the manuscript. Raw WordPress messages ("Cookie check failed") are never shown
  for an authentication failure.
- **Background refreshes must not remount the editor:** `loadAccount({ silent:
  true })` skips the full-screen loading gate, because that gate replaces the
  whole shell and would discard everything held in memory.
- **Revisions are the cost, so drafts are capped at 20:** every autosave creates
  a WordPress revision. `Penalis_Revisions` caps `wp_revisions_to_keep` for
  `post` drafts, and a stricter site setting still wins — including `0`, which
  keeps revisions disabled. Twenty is derived from the autosave cadence: one to
  two writes a minute means ten to twenty minutes of recoverable history, and
  an 8 KB article is capped at about 160 KB. Sites can change the depth with
  the `penalis_draft_revisions` filter; returning `0` opts out of the cap.

### 7.11 Password Strength Meter Is Advisory Only
The server enforces one password rule: `PASSWORD_MIN_LENGTH` characters. The
meter in `src/components/PasswordStrengthMeter.tsx` deliberately does not add a
second, stricter one.
- **A client-only meter cannot lock anyone out:** if the browser rejected a
  password the server would have accepted, a writer on another browser (or with
  JavaScript disabled) would have no way to know which rule applied. Guidance
  that is always satisfiable is worth more than a gate that disagrees with the
  authority.
- **Scoring is a rubric, not an entropy estimate:** `src/lib/passwordStrength.ts`
  awards points for reaching 12 characters (2), 16 characters (1), mixed case
  (1), a digit (1), a symbol (1), and variety (1), then maps 0–7 points onto the
  five labels. Every point corresponds to something the writer can act on, which
  is what makes the guidance line useful.
- **Weak shapes are capped, not docked:** a single repeated character, digits
  only, or a dictionary word with decoration (`P@ssword2024`) is limited to
  "Lemah" no matter how the rubric scores it. Dictionary matching is by prefix
  after undoing the usual substitutions, because appending digits to a known
  word is the most common weak pattern and an exact match would miss it.
- **The meter is part of the field's description:** it renders inside
  `PasswordPairFields` (registration, legacy activation, password reset) and in
  **Pengaturan → Ganti Password**, carries the `id` referenced by the input's
  `aria-describedby`, and announces changes through `role="status"`. Its five
  segments are `aria-hidden` decoration — the label carries the message, so the
  score is never communicated by colour alone.
- **Nothing is rendered before the first keystroke:** a meter pinned at "Sangat
  lemah" for an untouched field is noise, and the persistent hint already states
  the minimum length.

### 7.12 Confirmation Feedback Waits for Certainty
`passwordMatchStatus()` in `src/lib/validation.ts` decides what the confirmation
field says while the writer is still typing. It exists because the obvious
implementation — compare on every keystroke — is unusable: a confirmation being
typed is always shorter than the password and therefore always "different", so
the field would turn red on the first character and stay red until the last.
- **Two triggers, one rule:** a mismatch is reported once the field has been
  blurred at least once, or as soon as the confirmation is at least as long as
  the password. The second trigger is safe because at that length the values can
  no longer become equal without deleting something.
- **Equality is never premature:** a match is reported the moment both fields
  hold the same value, with no wait for blur. Positive feedback costs nothing.
- **One sentence, two moments:** the submit-time validator and the live hint
  share `PASSWORD_MISMATCH_MESSAGE`, so the writer does not read "Password tidak
  cocok." from one and a reworded variant from the other.
- **The hint and the submit error never stack:** when a submit error is present
  the live hint steps aside, and a matched pair hides a stale error from the
  previous submit instead of leaving it on screen until the next one.
- **Still not enforcement:** the browser reports; the server decides. A writer
  who submits a mismatched pair gets the same rejection as before, and no
  JavaScript at all only loses the early warning.

### 7.13 Editor Keyboard Shortcuts
`src/lib/shortcuts.ts` maps `Ctrl/Cmd+S` to save, `Ctrl/Cmd+Enter` to the submit
confirmation, and `?` to the shortcut reference. Both decisions — which chord is
which shortcut, and whether a keystroke belongs to a text field — are pure
functions, which is why they can be tested without a DOM.
- **`Ctrl+S` must be intercepted:** in a browser it opens "save page", which is
  never what a writer wants mid-sentence. `Ctrl+Shift+S` is deliberately left
  alone so the browser keeps its "save as" meaning instead of silently becoming
  a second save.
- **`Ctrl+Enter` opens the confirmation, never submits:** submitting locks the
  article until an administrator decides, so the keyboard takes the writer to
  the same dialog as the button rather than skipping the explanation.
- **`?` is a printable character:** the handler skips it whenever the event
  target is a form control or a `contenteditable` element, or sits inside one.
  Without that check the first question mark in a manuscript would open a dialog
  instead of appearing on the page. `Ctrl+S` and `Ctrl+Enter` still work there —
  that is where a writer actually is when reaching for them.
- **The listener captures:** the rich text editor handles keydown on its own
  element and may stop propagation, which would hide `Ctrl+S` from a bubbling
  listener. Capturing is safe because no printable character is intercepted.
- **Shortcuts are inert while a dialog is open,** but `preventDefault()` still
  runs: leaving `Ctrl+S` to the browser would stack the "save page" prompt on
  top of the editor's own dialog.
- **The trigger is hidden on touch screens:** the `?` button advertises
  something a phone cannot do and costs a slot in an action bar that already
  wraps. The test is `@media (pointer: coarse)`, not viewport width — a narrow
  desktop window still has a keyboard, a tablet at 1024px does not. The
  keyboard handler itself stays active everywhere, so a tablet with a physical
  keyboard keeps working shortcuts and only loses the reminder.
- **`window.confirm` is gone:** leaving the editor with unsaved work now uses
  `ConfirmDialog`, which can name the article and focuses "Tetap di sini" first.
  `beforeunload` stays as-is — closing a tab is the one prompt the browser owns,
  and it cannot be replaced by page UI.

### 7.14 Toasts and View-Change Focus
Two gaps that only show up once the dashboard is treated as a single page rather
than a set of pages: a confirmation that appears far from the action, and a view
change that nobody announces.
- **Toasts are for one-line confirmations only.** "Karya tulis dihapus" and
  "Foto profil diperbarui" are reported by a fixed stack at the bottom-right,
  because an inline banner at the top of the screen reports an action the writer
  took further down — and inserting it pushes the list they were looking at.
  Anything that needs explaining (a save failure with a retry, a multi-line
  warning) stays an inline banner: a message that expires cannot carry a button
  the writer still needs.
- **The live region is always mounted.** Assistive technology only announces
  changes inside a region that already exists, so a container rendered together
  with its first toast would be silent. `Toast.tsx` renders the empty region on
  every page and appends into it.
- **Errors do not expire.** `toastLifetime()` returns 0 for `danger`: a message
  that disappears after six seconds is a message a writer may never read, and
  the action that failed is usually the one they were waiting for. Success is
  self-evident once seen, so it leaves on its own. The stack caps at three,
  dropping the oldest — the newest describes what just happened.
- **Which notices stay banners:** anything that instructs the next step,
  carries a button, or is followed by navigation. That covers the three terminal
  success screens in the auth gate (a notice, an explanation, and a button — the
  screen's content, not a confirmation), the settings messages that end in
  "Mengalihkan ke halaman login…" and a two-second redirect, and the
  email-change instruction that has to stay visible while the code is typed.
  A toast in any of those places would expire mid-task.
- **A view change moves focus to the new heading.** The shell is persistent, so
  without this a keyboard user's next Tab continues from the nav they just left
  and a screen-reader user is told nothing. Focusing the heading *is* the
  announcement — the heading is read as it takes focus — so there is no second
  live region repeating it. The effect also resets the scroll position, which
  otherwise stays wherever the previous view left it.
- **A ref guards the first render.** `previousViewRef` starts at the current
  view, so opening the dashboard on a deep link does not move focus away from
  the browser's own navigation.
- **Async views fall back to `<main>`:** a view that renders a spinner first has
  no heading yet, and the landmark still takes focus away from the nav strip.

### 7.15 Overview Trend Metrics
The Ringkasan screen shows an eight-week trend (`Penalis_Analytics`) computed
entirely from the editorial metadata the review workflow already writes. There is
no second source of truth and no extra table.
- **Submissions per week** counts articles whose *latest* `_penalis_submitted_at`
  falls in that week. The meta is overwritten on every resubmission, so this is
  "articles submitted that week", not "every submit event in history".
- **Time to publish** is the median from the last submission to the approving
  review. Median, not mean: one article that sat for two months must not define
  the typical turnaround.
- **Approval rate** compares published against rejected decisions in the window.
  "Changes requested" is excluded — it is not a decision yet, and counting it as
  a rejection would understate the rate.
- **Durations pick their own unit.** A community that approves in minutes would
  read "0,0 hari"; `duration()` returns minutes under an hour, hours under a day,
  days above.
- **The chart is a list, not a canvas.** Every bar carries its value and week
  label as text, so the numbers read without colour, hover, or JavaScript.
- **Weeks start on Monday in the site timezone,** and boundaries are computed
  with `modify( '+1 week' )` so a daylight-saving shift moves the boundary with
  local midnight instead of sliding the week by an hour.

### 7.16 Review Queue in the Dashboard
Reviewers work the queue at `/dashboard/?view=review` instead of switching to
wp-admin. The view is `src/features/review/ReviewQueue.tsx`.
- **One service, two entry points.** `POST /articles/{id}/decision` calls
  `Penalis_Editorial_Workflow::apply()`, exactly like the wp-admin handler. The
  state machine, the per-article advisory lock, the stale-cycle guard and the
  "feedback is required" rule live there, so the SPA cannot approve something
  wp-admin would refuse.
- **The reviewer does not need writer capabilities.** `require_reviewer()` checks
  `edit_others_posts` on its own; an administrator without the writer role can
  clear the queue. The nav item is hidden for writers rather than shown disabled.
- **`expected_cycle` is mandatory.** The client sends the cycle it displayed.
  The policy treats a missing cycle as stale, which is what stops a second
  reviewer from overwriting a round they never read.
- **A failed decision keeps the dialog open.** A stale cycle or a lock is
  recoverable once the reviewer reads the reason; closing would discard the
  notes they typed.
- **Queue order is oldest first.** A review queue is worked front to back, so
  `modified ASC` puts the longest wait on screen.



