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
│   ├── class-account-status.php          Account status persistence, transitions, audit log
│   ├── class-otp.php                     Purpose-bound six-digit verification challenges
│   ├── class-editorial-policy.php        Pure editorial state machine, review cycle, notification rules
│   ├── class-editorial-workflow.php      Serialized editorial transitions and post meta persistence
│   ├── class-editorial-notifications.php Bounded dashboard notifications and editorial email dispatch
│   ├── class-editorial-admin.php         Penalis → Review Karya Tulis admin review workspace
│   ├── class-admin.php                   Penalis top-level menu, Ringkasan overview, Penulis Penalis screen
│   ├── class-access-control.php          Login gate, capability filters, REST guards, upload validation
│   ├── class-rest-api.php                penalis/v1 REST endpoints
│   ├── class-registration.php            Self-service writer registration and email verification
│   ├── class-legacy-activation.php       Legacy contributor self-service and admin activation
│   ├── class-password-reset.php          Dashboard forgot/reset password flow
│   ├── class-email-change.php            Verified email address change flow
│   ├── class-username-change.php         Re-authenticated login rename with advisory lock
│   ├── class-author-history.php          Historical author slug reservation and 301 redirects
│   ├── class-avatar.php                  Avatar storage and fallback provider (One User Avatar compatible)
│   ├── class-dashboard.php               Dashboard page, shortcode, template, Vite manifest loader
│   ├── class-emails.php                  Transactional emails and failure tracking
│   ├── class-email-template.php          Branded responsive HTML email layout
│   └── class-rate-limiter.php            Transient-based per-IP and per-account rate limiting
├── assets/
│   ├── css/admin.css                     WordPress admin stylesheet (Ringkasan, Review Karya Tulis, Penulis Penalis)
│   └── js/admin-review.js                Confirmation dialogs and inline validation for wp-admin actions
├── templates/
│   └── dashboard.php                     Minimal theme-compatible wrapper for [penalis_dashboard]
├── src/
│   ├── app/                              React entry point (index.tsx) and root router (App.tsx)
│   ├── components/                       Shared UI primitives, Dialog, ConfirmDialog, MediaSelection, PasswordInput
│   ├── features/                         Feature views: auth, overview, articles, editor, media, profile, settings
│   ├── lib/                              Typed API client, validation, deeplinks, HTML/profile helpers
│   └── styles/                           Scoped dashboard CSS (main.css)
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
3. `Penalis_Author_History::init()`
4. `Penalis_Rest_Api::init()`
5. `Penalis_Dashboard::init()`
6. `Penalis_Editorial_Notifications::init()`
7. `Penalis_Avatar::register_provider()` (on `init`)
8. `Penalis_Admin::init()` and `Penalis_Editorial_Admin::init()` (when `is_admin()`)

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
`canWrite: false`, so `/dashboard/` opens in a read-only reviewer mode instead
of showing "Akun tidak tersedia".

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
- **Notifications:** Every transition fires `penalis_editorial_action`.
  `Penalis_Editorial_Notifications` records a bounded in-dashboard notification
  (`_penalis_notifications`, max 50 per user) and dispatches a branded HTML
  email via `Penalis_Emails`. Mail failures are recorded on the author's account
  without rolling back the editorial transition.

---

## 5. WordPress Admin Console

All curation surfaces live under a single top-level menu, **Penalis**
(`admin.php?page=penalis-platform`). The menu requires `edit_others_posts`, so
both administrators and editors see it; writers never do. Account actions and
the site-wide default avatar stay administrator-only (`list_users` and
`manage_options` respectively), which makes **Penulis Penalis** read-only for
editors.

1. **Penalis → Ringkasan** (`?page=penalis-platform`, `Penalis_Admin::render_overview_page()`)
   - Summary metric cards for pending article reviews, revisions, published
     works, active writers, unverified registrations, suspended accounts, and
     legacy accounts, plus quick-action panels for the latest pending article
     submissions and newly registered writers.
2. **Penalis → Review Karya Tulis** (`?page=penalis-review`, `Penalis_Editorial_Admin::render_page()`)
   - Filter tabs by editorial state (`Menunggu review`, `Perlu revisi`,
     `Ditolak`, `Terbit`) and a 2-column detail review workspace with the
     rendered article preview, metadata header, unified decision form with an
     accessible confirmation modal, link to the native WordPress editor for
     pre-publication corrections, and a vertical review history timeline.
3. **Penalis → Penulis Penalis** (`?page=penalis-writers`, `Penalis_Admin::render_page()`)
   - Account management table with status tabs (`Semua yang dikelola`, `Aktif`,
     `Menunggu verifikasi`, `Ditangguhkan`, `Akun lama`), search toolbar,
     Instagram handle column (`@username`), collapsible audit history,
     confirmation modals for all account actions (`Tangguhkan`,
     `Aktifkan kembali`, `Kirim ulang verifikasi`, `Kirim link aktivasi`,
     `Aktifkan langsung`), and the site-wide default profile photo setting.

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
| `penalis_registration_enabled` | Option | Enables/disables public writer registration (`1`/`0`, default `1`) |
| `penalis_dashboard_page_id` | Option | Page ID of the `/dashboard/` page |
| `penalis_dashboard_page_created_by_plugin` | Option | Guard ensuring uninstall only removes a plugin-created page |
| `penalis_remove_data_on_uninstall` | Option | Opt-in flag (`1`/`0`) for destructive uninstall |
| `avatar_default_wp_user_avatar` | Option | Default profile photo attachment ID (shared with One User Avatar) |
| `penalis_pending_review_count` | Transient | Cached count of `pending_review` article submissions |
| `penalis_activation_notice` | Transient | Short-lived post-activation admin notice flag |
| `penalis_rl_<md5>` | Transient | Rate-limiter bucket counters |

---

## 7. Key Technical Decisions

### 7.1 Vite Instead of `@wordpress/scripts`
The writer dashboard is a standalone React SPA mounted on `/dashboard/`, not a
Gutenberg plugin inside `wp-admin`. Vite produces a compact bundle, splits the
editor into a lazy-loaded chunk, and outputs `build/manifest.json`.
`Penalis_Dashboard::maybe_enqueue_assets()` reads the manifest and adds
`type="module"` via `script_loader_tag`.

### 7.2 TipTap Instead of Gutenberg
Running Gutenberg outside `wp-admin` requires the `@wordpress/*` block registry
and `unfiltered_html` for several blocks. TipTap is headless, MIT-licensed,
lazy-loaded (`React.lazy`), and serializes directly to standard HTML in
`post_content`, with a raw HTML toggle so existing markup is never silently
rewritten. Server-side `wp_kses` remains the sole authority on allowed HTML.

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
*upload*, *download*, etc.).

