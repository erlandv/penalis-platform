# AGENTS.md

Orientation for any AI agent working on **Penalis Platform**. Read this file
first; it explains what the product is, why it exists, how the code is
organised, and which rules must not be broken. Deeper detail lives in
[`README.md`](README.md), [`PROJECT-SPECIFICATION.md`](PROJECT-SPECIFICATION.md),
and [`SECURITY.md`](SECURITY.md) — those are authoritative on their topics, this
file is the map.

Language convention: **code, comments, documentation, commit messages and this
file are English. User-facing copy in the dashboard is Indonesian, hardcoded.**
Penalis is an Indonesian community; the writers read Indonesian, the developers
read English.

---

## 1. What Penalis is

Penalis (Penalis Komunitas Seni) is an Indonesian community for *karya seni
tulis* — literary writing: essays, short prose, poetry, reflections. The
community publishes member-submitted work on its blog, **penalis.com**.

So the product is not "a WordPress site". It is a **publishing pipeline for a
literary community**: people who write want to be read, and the site's job is to
get their work from draft to published without friction, while keeping the
publication curated (a human still approves who gets to publish).

Everything in this repository exists to serve that pipeline. When a change makes
writing slower, less pleasant, or less trustworthy, it is wrong even if it is
technically correct.

---

## 2. The problem this plugin solves

### 2.1 How contributions worked before this plugin

1. A visitor filled in an **Elementor form on the public page
   `/submit-karya-tulis`** with: nama, username, email, bio, foto profil, judul
   artikel, kategori, tag, and isi artikel.
2. The form emailed everything to the admin.
3. The admin manually created a **contributor** account in wp-admin from that
   data — with no password, because the contributor never signed in.
4. The admin then manually re-typed the article in wp-admin and set the post
   author to that account, so the piece showed up correctly under the public
   profile `/author/<username>/`.

Why that was bad:

- Every submission cost the admin two manual jobs (account + post), so
  throughput was one person's spare time.
- Contributors could not edit, preview, or unpublish their own work.
- Nobody proved they owned the email address on file, and accounts had no
  password, so "identity" was whatever the form said.
- No audit trail of who was approved, when, or by whom.
- A typo in the email created an orphan account; a missing category broke the
  archive.

### 2.2 How it works now

The plugin replaces that with a self-service workflow and its own dashboard
(**`/dashboard/`**, a React app — deliberately *not* wp-admin):

```
register -> verify email -> admin approves -> write/publish in /dashboard/
```

- Anyone can register (the whole registration flow can be disabled with the
  `penalis_registration_enabled` option).
- The email address is verified before an admin ever sees the application.
- An admin approves, rejects, suspends, or reinstates from
  **Users -> Penalis Writers** in wp-admin. That screen is the curation gate.
- Approved writers get a dedicated `penalis_writer` role and use the dashboard:
  overview, own articles, editor, media, profile, settings.
- Existing contributors (people who already published through the old form) use
  **"Aktifkan akun penulis lama"**: they enter the email they submitted with,
  verify it, set a password, and wait for approval. Their existing user ID,
  posts and author archive are preserved — nothing is migrated or re-authored.

### 2.3 What stayed the same on purpose

- Articles are still WordPress posts (`post_content` holds plain HTML), so the
  active theme renders them like any other post.
- Author archives still work, because the WordPress user account is still the
  author of the post.
- The admin still decides who may publish; the plugin automates the paperwork,
  not the editorial judgement.

---

## 3. Product principles

1. **Writing experience first.** The dashboard is the product surface. Layout,
   typography, feedback, keyboard behaviour, and loading states matter as much
   as correctness. "It works" is not done.
2. **The server is the authority.** Anything the browser sends is untrusted:
   author, status, ownership, capabilities, markup. The client validates for
   fast feedback only.
3. **Non-destructive by default.** Activation, upgrade, and uninstall never
   delete users, posts, media, or taxonomy. Data removal is opt-in only.
4. **No silent data loss.** Never rewrite a writer's content behind their back.
   When content cannot be represented, keep it (that is why the raw HTML mode
   exists).
5. **One job per screen, obvious next action.** A pending writer must always be
   able to tell what state their account is in and what to do next.
6. **Boring, legible technology.** WordPress core APIs, React, Vite, TipTap.
   No exotic dependencies; each addition has to pay for itself.
7. **Accessibility is part of "good UX"**: real labels, `aria-describedby` on
   errors, visible focus, focus trapped in dialogs, no emoji-as-icon.

---

## 4. Domain model

### 4.1 Account statuses

Statuses live in user meta (`Penalis_Account_Status`, constants in
`Penalis_Policy`).

| Status | Meaning | Label |
| --- | --- | --- |
| `pending_verification` | Registered, email not confirmed | Menunggu verifikasi email |
| `pending_approval` | Email confirmed, waiting for admin | Menunggu persetujuan admin |
| `active` | Approved writer | Penulis aktif |
| `rejected` | Application declined | Pendaftaran ditolak |
| `suspended` | Access revoked after approval | Akun ditangguhkan |

A **legacy account** has *no* status meta at all (it predates the plugin). It is
an existing `contributor` with no password. See `is_legacy_account()` and
§4.5.

Allowed transitions (`Penalis_Policy::can_transition()`):

| From | To |
| --- | --- |
| `pending_verification` | `pending_approval`, `rejected` |
| `pending_approval` | `active`, `rejected` |
| `active` | `suspended` |
| `suspended` | `active` |
| `rejected` | `pending_approval` |

Same-status transitions are rejected. Every transition is written to the audit
log (`_penalis_status_log`, last 20 entries) and fires
`penalis_account_status_changed`.

### 4.2 What each status may do

| Status | Sign in | Reset password | Write/publish | Dashboard |
| --- | --- | --- | --- | --- |
| `pending_verification` | yes | yes | no | yes (status view) |
| `pending_approval` | yes | yes | no | yes (status view) |
| `active` | yes, if access flag set | yes, if access flag set | yes, if access flag set | yes |
| `rejected` | no | no | no | no |
| `suspended` | no | no | no | no |
| legacy (no status) + access flag | yes (native login only) | yes | no | no |

Two flags accompany the status and are separate on purpose:
`_penalis_email_verified` and `_penalis_access_enabled`. "Verified" is about the
email; "access enabled" is about the plugin gate. Rejected and suspended
accounts also get their WordPress sessions destroyed, so an already-signed-in
browser cannot keep acting.

### 4.3 Roles

`penalis_writer` ("Penulis Penalis") is granted: `read`, `upload_files`,
`edit_posts`, `publish_posts`, `edit_published_posts`, `delete_posts`,
`delete_published_posts`. Everything administrative is explicitly denied —
including `edit_others_posts`, `manage_categories`, `moderate_comments`,
`list_users`, `unfiltered_html`.

- `Penalis_Roles::assign_writer()` promotes `subscriber`, `contributor`, or an
  existing writer to `penalis_writer`.
- `revoke_writer()` demotes a writer to `subscriber` on rejection/suspension.
- Accounts holding a higher role (author, editor, administrator) are **never**
  promoted or demoted automatically. Approval of such an account fails with
  `penalis_role_conflict`; that decision belongs to a human.

### 4.4 Storage keys (know these before touching data)

**User meta**

| Key | Purpose |
| --- | --- |
| `_penalis_account_status` | Status value |
| `_penalis_access_enabled` | `1`/`0` plugin gate |
| `_penalis_email_verified` | `1`/`0` |
| `_penalis_registered_at`, `_penalis_approved_at`, `_penalis_approved_by` | Lifecycle timestamps |
| `_penalis_status_log` | Audit log (bounded to 20) |
| `_penalis_verification_token_hash`, `..._expires`, `..._created` | Email verification token (hashed) |
| `_penalis_email_change_token_hash`, `..._expires`, `..._created`, `_penalis_email_change_new_email` | Pending verified email change (hashed token + candidate address) |
| `_penalis_display_name_mode` | `auto`, `first`, `last`, `username`, `custom` |
| `_penalis_author_history` | Previous author slugs (multi-row: slug, time, reserved, redirect) |
| `_penalis_author_history_reserved` | Searchable reservation rows, one per historical slug |
| `_penalis_needs_password` | Legacy "vouch" path: owner still has no password |
| `_penalis_last_email_failed_at`, `..._context` | Mail delivery failure, surfaced to admins |
| `{prefix}user_avatar` | Avatar attachment ID, **shared with One User Avatar** |
| `first_name`, `last_name`, `description` | Native WordPress fields |

**Options**

| Option | Purpose |
| --- | --- |
| `penalis_platform_version` | Installed version; drives `maybe_upgrade()` |
| `penalis_registration_enabled` | `1`/`0`, default `1`; no UI, set via WP-CLI/options |
| `penalis_dashboard_page_id` | Page ID of `/dashboard/` |
| `penalis_dashboard_page_created_by_plugin` | Guard so uninstall only deletes a page this plugin made |
| `penalis_remove_data_on_uninstall` | Opt-in destructive uninstall |
| `avatar_default_wp_user_avatar` | Default profile photo (shared convention with One User Avatar) |

**Transients**: `penalis_pending_approval_count`, `penalis_activation_notice`,
and `penalis_rl_<md5>` for the rate limiter.

**Posts**: normal `post` entries. `post_content` is HTML from the editor,
`post_excerpt` is left to WordPress/SEO plugins, featured image is the thumbnail.
No custom post type, no custom post meta — this is why the theme can render
articles unchanged.

### 4.5 Legacy accounts

Definition: user has the `contributor` role (or lower), **no** plugin status
meta, and no usable password. They cannot sign in at all.

Conversion is the only way in. Policy (`can_convert_legacy()`) permits two
targets, `pending_verification` or `pending_approval`, and both real call sites
currently land on `pending_approval`:

- **Self-service**: the writer proves control of the stored email by consuming
  an activation token and choosing a first password. That already substitutes
  for email verification, so the account goes straight to `pending_approval`.
- **Administrator "vouch"**: the admin knows the person out of band, so the
  email proof is skipped. The account goes to `pending_approval`, is flagged
  `_penalis_needs_password`, and a password reset link is sent so the owner can
  sign in at all.

Direct conversion to `active` is impossible by design: every legacy account
must pass an administrator decision.

Conversions never change the user ID and never touch their posts, so author
archives keep working.

---

## 5. User journeys

### 5.1 New writer

1. `/dashboard/` for a guest shows the auth surface (`AuthGate`): sign in,
   register, activate a legacy account, forgot password.
2. `POST /penalis/v1/registration` with display name, username, email, password,
   consent checkbox, plus a honeypot field (`penalis_website`) that must stay
   empty.
3. Server validates (`Penalis_Policy::validate_registration`), creates a
   `subscriber` via `wp_insert_user`, sets status `pending_verification`, issues
   a token, emails a confirmation link.
4. Writer opens the link (`?penalis_verify=<token>`), sees an explicit "confirm"
   page — the token is consumed only when the button is pressed, so mail
   scanners cannot burn it.
5. Status becomes `pending_approval`; admins get a notification email.
6. An admin approves on **Users -> Penalis Writers**: status `active`, access
   enabled, role promoted, writer notified.
7. The writer signs in and gets the full dashboard.

Token rules: 32 random bytes, base64url (43 characters), stored **hashed**,
24-hour TTL, resend allowed after 600 seconds, single use.

### 5.2 Legacy contributor

1. Choose "Aktifkan akun penulis lama", enter the email used for past
   submissions.
2. `POST /penalis/v1/legacy/activate` always answers generically (no account
   enumeration). A real legacy account receives an activation link.
3. `POST /penalis/v1/legacy/activate/confirm` consumes the token, sets the first
   password via `reset_password()`, and converts the account straight to
   `pending_approval` — the token already proved control of the email, so the
   separate verification step would be redundant.
4. Admins are notified; the same approval step as §5.1 applies.

An administrator can also drive this from wp-admin: "kirim tautan aktivasi"
sends the same link, and "aktifkan langsung" (vouch) converts to
`pending_approval`, flags `_penalis_needs_password`, and emails a password reset
link.

### 5.3 Password reset

`POST /penalis/v1/password/forgot` (generic answer, per-IP and per-account rate
limits) -> email with a **WordPress core** reset key ->
`?penalis_reset=<key>&penalis_login=<login>` -> `POST /penalis/v1/password/reset`.

The request accepts an **email address only** (`user_email`). Usernames are
public on author archives, so accepting one would let anyone turn a public
profile into a reset attempt against that account. A username sent in the email
field is answered with the same generic message and never looked up. The reset
link still carries `penalis_login` because `check_password_reset_key()` needs
the login; that value comes from the server, not from the visitor.

Two different token worlds, do not mix them:

- plugin tokens: 43-char base64url, hashed, plugin-managed (verification,
  legacy activation, email change);
- core reset keys: 20-character alphanumeric from `get_password_reset_key()`.

`src/lib/deeplink.ts` documents this; `isTokenShaped()` must never be applied to
a reset key.

### 5.3a Email address change

`POST /penalis/v1/account/email` (signed in, current password required) stores
the candidate address next to a hashed single-use token and emails the
confirmation link to that **new** address. `POST /penalis/v1/account/email/confirm`
(public, token is the proof) re-checks availability and only then updates
`user_email`.

Until confirmation the old address keeps receiving resets and notifications;
afterwards it receives a security notice. The status machine is never touched.
`pending_verification` accounts use the verification resend instead, and
`rejected`/`suspended` accounts are denied.

### 5.3b Username change

`POST /penalis/v1/account/username` (signed in, current password required)
changes the login of the caller only. Eligibility is the same rule as an email
change, including the administrator exemption; attempts are limited to 5 per IP
and 3 per account per hour.

- Validation is strict and never normalizes: 3–50 lowercase ASCII letters,
  digits, `_` or `-`, starting and ending with a letter or digit, at least one
  letter or digit, no dots. A value that only becomes valid after
  `sanitize_user()` is rejected rather than rewritten.
- `wp_update_user()` ignores `user_login`, so the write is a direct
  `$wpdb->update()` inside a users-table advisory lock. The lock only serializes
  this endpoint; native registration and other plugins can still race.
- The user ID is preserved and `user_nicename` follows the new login, so the
  author archive moves. The previous slug is stored in
  `_penalis_author_history` and 301-redirected, so published links keep working
  even with a custom author base. Only a public name that follows the username
  is updated; custom pen names and first/last-name compositions are untouched.
- Old and new login caches are cleared, all sessions are destroyed, the current
  auth cookie is cleared, and the current email receives a security notice.
  Settings redirects to login afterwards.

### 5.4 Writing and publishing

- The editor saves through **core** `/wp/v2/posts` (create/update) and
  `/wp/v2/posts/{id}?force=true` (delete). The plugin does not re-implement
  content CRUD.
- Author is always derived server-side; the client never sends one.
- Writers may set `draft` or `publish`, and only for their own posts.
  Ownership is enforced by capability filtering, `map_meta_cap`, and query
  forcing in `Penalis_Access_Control`. `pending` is not offered: there is no
  submit-for-review step in the dashboard.
- The articles list publishes/unpublishes through `penalis/v1`
  (`/articles/{id}/publish`, `/unpublish`) so the state machine stays explicit;
  the editor saves the chosen status (`draft`, `publish`) through core
  `wp/v2/posts`.
- Markup is filtered by WordPress `wp_kses` (writers lack `unfiltered_html`).
  That is the only authority on allowed HTML.

### 5.5 Suspension and reinstatement

Admin suspends: access off, writer role revoked, sessions destroyed. Posts stay
published — suspension removes the ability to act, it is not a content takedown.
Reinstatement (`suspended` -> `active`) restores access and the role.

### 5.6 Admin console (wp-admin, not the dashboard)

One screen carries the whole curation surface: **Users -> Penalis Writers**
(`add_users_page`, `includes/class-admin.php`). It lists accounts by status and
offers, per account: approve, reject, reopen a rejected application, suspend,
reactivate, resend the verification email, send a legacy activation link, and
vouch for a legacy account. It also holds the default profile photo setting.

Everything here goes through `Penalis_Account_Status::transition()` (or
`convert_legacy()`), so the status machine and the audit log stay the only path
— never write status meta directly from an admin action.

Registration on/off has **no UI**: it is the `penalis_registration_enabled`
option, read by `Penalis_Registration::is_registration_enabled()` and exposed to
the SPA as `bootstrap.registration`.

---

## 6. Architecture

### 6.1 Shape

A single WordPress plugin with two halves that meet at one page:

```
WordPress (PHP)                          Browser (React SPA)
  plugin bootstrap                         /dashboard/ page
  roles, status machine, policy   <---->   AuthGate, Overview, Articles,
  REST: penalis/v1 (workflow)              Editor (TipTap), Media, Profile,
  core wp/v2 (content CRUD)                Settings
  admin screens (approval)
  emails, rate limits, guards
```

- **No separate app server, no headless WordPress, no auth tokens in
  localStorage.** Authentication is WordPress cookies plus the `wp_rest` nonce
  sent as `X-WP-Nonce`.
- The dashboard is a page (`[penalis_dashboard]` shortcode) rendered through
  `templates/dashboard.php`, which keeps the theme header/footer and drops
  sidebars. A theme may override it with `penalis-dashboard.php`.
- Asset loading: `class-dashboard.php` reads `build/manifest.json`, enqueues the
  entry, then rewrites the tag to `type="module"` via `script_loader_tag` —
  Vite output is an ES module and WordPress prints classic tags by default.
  Removing that filter blanks the entire dashboard.

### 6.2 PHP structure

| File | Responsibility |
| --- | --- |
| `penalis-platform.php` | Header, constants, `Penalis_*` autoloader, activation hooks, boot on `plugins_loaded` |
| `includes/class-plugin.php` | Singleton container: upgrade check, component init order, admin notice |
| `includes/class-activator.php` / `-deactivator.php` | Activation defaults, dashboard page creation + legacy slug rename |
| `includes/class-roles.php` | Writer role, promotion/demotion |
| `includes/class-account-status.php` | Status persistence, transitions, audit log, tokens, mail-failure markers |
| `includes/class-policy.php` | Pure rules: statuses, transitions, limits, validation, display names, tokens |
| `includes/class-rest-api.php` | `penalis/v1` routes and their handlers |
| `includes/class-access-control.php` | Login gate, capability filtering, REST blocking, upload restrictions, post insert enforcement, privilege-escalation guards |
| `includes/class-registration.php` | Registration, verification, resend |
| `includes/class-legacy-activation.php` | Legacy request/confirm conversion |
| `includes/class-password-reset.php` | Forgot/reset flow |
| `includes/class-email-change.php` | Verified email address change (request + confirmation) |
| `includes/class-username-change.php` | Re-authenticated login change (direct users-table write) |
| `includes/class-author-history.php` | Historical author slugs, reservations, native validation hooks, 301 redirects |
| `includes/class-emails.php` | All outgoing mail (verification, reset, legacy, status, admin notice) |
| `includes/class-email-template.php` | Self-contained branded HTML layout and safe action links for transactional email |
| `includes/class-dashboard.php` | Page/shortcode/template, asset enqueue, login POST, logout, cache headers, noindex, legacy slug redirect |
| `includes/class-admin.php` | Users -> Penalis Writers screen: filters, status actions, legacy activation, default avatar |
| `includes/class-avatar.php` | Avatar storage and provider, One User Avatar coexistence |
| `includes/class-rate-limiter.php` | Transient-based limiter |
| `uninstall.php` | Opt-in cleanup only |
| `templates/dashboard.php` | Dashboard page template |
| `tests/run-tests.php`, `tests/wp-stubs.php` | Plain-PHP unit tests and WordPress stubs |

Autoloading is convention-based: `Penalis_Foo_Bar` -> `includes/class-foo-bar.php`.
No Composer autoloader at runtime; `vendor/` is dev tooling (PHPCS) only.

Init order (`Penalis_Plugin::run()`): `Access_Control`, `Rest_Api`, `Dashboard`,
`Avatar::register_provider()`, then `Admin` only when `is_admin()`.

### 6.3 Frontend structure

| Path | Responsibility |
| --- | --- |
| `src/app/index.tsx` | React root, error boundary, mounts on `#penalis-dashboard-root` |
| `src/app/App.tsx` | Auth gate vs shell, deeplink handling, view routing, verification page |
| `src/components/shell.tsx` | Navigation, status banner, layout |
| `src/components/ui.tsx` | Buttons, banners, badges, field error/hint, spinner, empty state, pagination |
| `src/components/PasswordInput.tsx` | Password field with the eye show/hide toggle; use it instead of a bare `<input type="password">` |
| `src/components/Dialog.tsx` | Accessible modal: focus in/trap/restore, Escape, backdrop click |
| `src/components/icons.tsx` | Inline SVG icon set (18px, stroke 2, `currentColor`) |
| `src/components/AppErrorBoundary.tsx`, `ChunkErrorBoundary.tsx` | App-level and lazy-chunk failure handling |
| `src/features/auth/AuthGate.tsx` | Login, register, legacy activation, forgot/reset |
| `src/features/overview`, `articles`, `editor`, `media`, `profile`, `settings` | One folder per dashboard view |
| `src/features/editor/RichTextEditor.tsx` | TipTap instance, toolbar, raw HTML mode, link dialog |
| `src/lib/api.ts` | Typed API client (throws `ApiError` with the server message) |
| `src/lib/bootstrap.ts` | Reads `window.penalisDashboard`, nothing from storage |
| `src/lib/types.ts` | Shared types, including `DashboardBootstrap` and `ViewName` |
| `src/lib/validation.ts` | Client-side mirrors of server rules |
| `src/lib/deeplink.ts` | Query parameter names, token shape test |
| `src/lib/html.ts`, `format.ts`, `a11y.ts`, `profile.ts` | HTML trimming, labels/formatting, aria helper, public-name logic |
| `src/styles/main.css` | Single stylesheet, tokens on `.penalis-dashboard-root` |

Build: Vite, `base: './'`, `outDir: build`, manifest `build/manifest.json`,
entry `src/app/index.tsx`, TipTap split into a lazy chunk. `build/` is
**gitignored** — it is generated, never edited, and CI regenerates it.

### 6.4 REST surface

Namespace `penalis/v1` (workflow). Permission callbacks:
`__return_true` = public but rate-limited and honeypot-protected;
`require_logged_in` = any signed-in account (pending accounts may maintain
profile fields); `require_active_writer` = `active` + access flag.

| Route | Method | Handler | Access |
| --- | --- | --- | --- |
| `/registration` | POST | `handle_registration` | public |
| `/verification/confirm` | POST | `handle_verification` | public |
| `/verification/resend` | POST | `handle_resend` | public |
| `/legacy/activate` | POST | `handle_legacy_activate_request` | public |
| `/legacy/activate/confirm` | POST | `handle_legacy_activate_confirm` | public |
| `/password/forgot` | POST | `handle_password_forgot` | public |
| `/password/reset` | POST | `handle_password_reset` | public |
| `/account` | GET | `get_account` | logged in |
| `/account/profile` | POST | `update_profile` | logged in |
| `/account/password` | POST | `update_password` | logged in |
| `/account/username` | POST | `change_username` | logged in |
| `/account/email` | POST | `request_email_change` | logged in |
| `/account/email/confirm` | POST | `confirm_email_change` | public (token) |
| `/account/avatar` | POST, DELETE | `set_avatar`, `remove_avatar` | logged in |
| `/articles` | GET | `list_articles` | active writer |
| `/articles/{id}` | GET | `get_article` | active writer |
| `/articles/{id}/publish` | POST | `publish_article` | active writer |
| `/articles/{id}/unpublish` | POST | `unpublish_article` | active writer |
| `/media` | GET | `list_media` | active writer |
| `/tags` | POST | `create_tag` | active writer |

Content CRUD goes through WordPress core instead:
`wp/v2/posts`, `wp/v2/media`, `wp/v2/categories`, `wp/v2/tags`, `wp/v2/users/me`.
All plugin responses under this namespace are sent with private cache headers
(`Penalis_Rest_Api::add_cache_headers`).

### 6.5 Dashboard bootstrap payload

`wp_localize_script( 'penalis-dashboard', 'penalisDashboard', [...] )` exposes:
`restUrl`, `wpRestUrl`, `nonce`, `dashboardUrl`, `loginUrl`, `logoutUrl`,
`lostPassword`, `homeUrl`, `adminUrl` (only for `list_users`), `siteName`,
`isLoggedIn`, `registration`, `canWrite`, `status`, `statusLabel`, `user`
(id, displayName, firstName, lastName, displayNameMode, avatarId, avatarUrl,
email, description, authorUrl). If you add a field, update
`src/lib/types.ts` (`DashboardBootstrap`) and `src/lib/bootstrap.ts` fallback.

### 6.6 Deeplinks (`src/lib/deeplink.ts`)

| Parameter | Kind | Opens |
| --- | --- | --- |
| `penalis_register` | flag | Registration form |
| `penalis_signin` | flag | Login (also the default view) |
| `penalis_forgot` | flag | Reset request |
| `penalis_resend` | flag | Resend verification |
| `penalis_verify` | token | Verification confirmation |
| `penalis_reset` + `penalis_login` | core key + login | Set a new password |
| `penalis_activate` | flag or token | Legacy request form or confirmation |
| `penalis_email_change` | token | Confirm a requested email address change |
| `penalis_login_error` | flag | Failed dashboard login |

These exist so the public site (including the retired `/submit-karya-tulis`
page, links in posts, and emails) can point straight at the right form. Do not
rename them without checking `class-emails.php` and `class-dashboard.php`.

---

## 7. Security invariants

Read `SECURITY.md` before changing anything in this area. The short version:

- **Never trust the client** for author, status, role, ownership, or markup.
- Every new REST route: explicit `permission_callback`, explicit status check,
  and — if public — a rate limit plus a generic error message.
- Authorization is layered: capability policy, `map_meta_cap`, query forcing,
  `enforce_post_insert`, and REST route blocking. Do not remove a layer because
  another one "should" cover it.
- Writers are blocked from administrative REST surfaces (`/wp/v2/users`,
  settings, plugins, themes, menus, widgets, templates, ...). Comments are
  read-only for them.
- Uploads are restricted to jpeg/png/gif/webp for writers; avatar validation
  checks MIME, dimensions (min edge 100px), and ownership.
- Public endpoints answer generically to prevent account enumeration
  (registration, verification, legacy activation, password reset).
- The password reset request accepts an email address only. Usernames are
  public on author archives, so they must never work as a reset handle.
- Honeypot field `penalis_website` must stay in the registration, legacy and
  password forms; a filled value is a silent no-op.
- Tokens are random, stored only as a hash (`Penalis_Policy::hash_token`) and
  looked up by that hash; plaintext tokens exist only in the email. They are
  single-use and expire (24 hours).
- Rate limits (per IP unless noted): registration 5/h, verification 20/h,
  resend 5/h, legacy activate 5/h + 3/h per email, legacy confirm 10/h, password
  forgot 5/h + 3/h per account, password reset 10/h, password change 5/h per
  user, email change 5/h per IP + 3/h per user, email change confirmation 10/h,
  username change 5/h per IP + 3/h per user, tag creation 30/h per user.
- Private data never goes to page caches: `private, no-store` cache headers,
  `X-Robots-Tag: noindex`, and `Referrer-Policy: no-referrer` on dashboard
  requests. Every write goes through REST with a nonce; there is no admin-ajax
  endpoint.
- Suspension/rejection destroys sessions.

---

## 8. Repository layout

```
penalis-platform.php        plugin header, constants, autoloader, hooks
includes/                   PHP classes (one concern per file, Penalis_ prefix)
templates/                  dashboard page template
src/                        React dashboard (app, components, features, lib, styles)
tests/                      plain-PHP tests + WordPress stubs
build/                      generated Vite output (gitignored)
.github/workflows/          ci.yml (lint/tests/build), release.yml (tag -> ZIP)
README.md                   operator + developer guide
PROJECT-SPECIFICATION.md    product and architecture decisions
SECURITY.md                 threat model and enforcement details
readme.txt                  WordPress.org-style readme (changelog, stable tag)
```

Ownership rule: if a behaviour is documented in one of the three docs and you
change it, update the doc in the same change.

---

## 9. Working agreements

### 9.1 How to approach a task

1. Read this file plus the relevant doc section; open the code before proposing
   a design.
2. For anything larger than a bug fix: state the design, the trade-offs, and a
   recommendation **before** implementing. The project owner approves first and
   prefers work staged in phases.
3. Work on a branch (usually off `dev`), keep commits focused, and **do not
   commit or push unless explicitly asked** — the owner reviews diffs in VS Code
   and commits themselves.
4. Finish with evidence: commands run, test counts, build hashes, URLs checked.
   "Should work" is not a result.
5. If a requirement is ambiguous or a rule here blocks the obvious solution,
   ask; do not silently invent policy (especially around approval, roles, or
   data deletion).

### 9.2 Branching and commits

- `dev` is the integration branch; `main` holds the last releasable state.
- Commit messages follow the existing history: `feat(area): ...`,
  `fix(area): ...`, `chore: ...`, imperative, lowercase after the colon,
  one line plus an optional body explaining *why*.
- The repository has no remote yet. It is planned as a **private** GitHub repo,
  not open source, not WordPress.org.

### 9.3 Version bumps

A user-visible change means a version bump in **all** of:

1. `penalis-platform.php` header `Version:`
2. `PENALIS_PLATFORM_VERSION`
3. `readme.txt` `Stable tag:` + a changelog entry
4. `package.json` `version` (and `package-lock.json` root entries)

`Penalis_Plugin::maybe_upgrade()` compares the stored option with the constant,
so a mismatch silently skips the upgrade path.

### 9.4 Code conventions

**PHP**

- WordPress Coding Standards via `phpcs.xml.dist` (WordPress ruleset), tabs for
  indentation, PHP 7.4 syntax floor — CI parses every shipped file under 7.4 —
  Yoda conditions where WPCS requires them, full docblocks on every class,
  method, and parameter.
- Prefix everything: `penalis_`, `Penalis_`, `PENALIS_`.
- No `gettext`: UI copy is Indonesian and hardcoded (see §9.5).
- Comments explain *why* and the failure mode being prevented, not *what* the
  next line does. Existing code is written this way; match it.
- Sanitise on input, escape on output (`esc_html`, `esc_url`, `esc_attr`,
  `wp_kses_post`).
- Keep policy pure and testable in `Penalis_Policy`; keep WordPress side effects
  in the feature classes.

**TypeScript / React**

- `strict` with `noUncheckedIndexedAccess`, `noUnusedLocals`,
  `noUnusedParameters`; `@typescript-eslint/no-explicit-any` is an error.
- 2-space indent, single quotes, trailing commas, named exports, function
  components with hooks. No class components except error boundaries.
- Feature folders own their state; shared primitives live in `src/components`,
  pure helpers in `src/lib` (and get a colocated `*.test.ts`).
- Client validation mirrors the server for feedback only.
- No `localStorage`/`sessionStorage` for auth or drafts. Nothing sensitive is
  persisted in the browser.
- Comments again explain the trade-off or the bug being avoided.

### 9.5 UI copy and iconography

- All user-facing text is Indonesian, written the way the community speaks
  (second person "kamu" in the dashboard, "Anda" is used in a few older strings —
  keep consistency with the surrounding screen).
- Plain language, no jargon, no internal status codes shown to writers.
- **No emoji in UI.** Use the inline SVG set in `src/components/icons.tsx`;
  add a new icon there rather than dropping a character into JSX.
- Layout uses design tokens from `src/styles/main.css`
  (`--penalis-accent` #7c3aed, surfaces, borders, radius, shadow). Light theme,
  desktop-first but responsive.
- Every interactive control needs a visible focus style and an accessible name;
  errors are wired with `aria-describedby`; dialogs use `src/components/Dialog`.
- Loading, empty, error, and success states are part of the design, not an
  afterthought. So is the "not allowed yet" state for pending accounts.

### 9.6 Dependencies

Justify every new runtime dependency. Current runtime set is deliberately small:
React, React DOM, TipTap (+ starter kit, image, placeholder, pm). Dev tooling:
Vite, TypeScript, ESLint, Vitest, PHPCS. Anything that ships to the browser in
`build/` needs a clear reason and a size check (`npm run build` prints gzip
sizes; TipTap already costs ~136 kB gzip and is lazy-loaded on purpose).

---

## 10. Commands and quality gates

```bash
# one-time setup
composer install          # PHPCS only, dev-only
npm ci                    # frontend dependencies from the lockfile

# gates (all must pass before you claim a change is done)
composer lint             # phpcs over shipped PHP (includes/, templates/, entry files)
composer test             # php tests/run-tests.php (no WordPress required)
npm run lint              # eslint, --max-warnings=0
npm run typecheck         # tsc --noEmit
npm run test              # vitest (node environment)
npm run build             # tsc --noEmit && vite build  -> build/
npm run check             # lint + typecheck + test + build
```

CI (`.github/workflows/ci.yml`): PHP 8.2 lint + tests, PHP 7.4 parse check of
every shipped file, and a frontend job running `npm run check` plus a manifest
verification step. `release.yml` runs on a `v*` tag and builds the distributable
ZIP.

Testing conventions and gaps:

- `tests/run-tests.php` covers pure rules (policy, statuses, avatars, display
  names, dashboard script tag) with hand-written stubs in `tests/wp-stubs.php`.
  Add stubs there instead of mocking WordPress globally. No WordPress bootstrap,
  no database.
- Vitest runs in the **node** environment: `src/lib/*.test.ts` only. There is no
  jsdom and no component test setup, so UI behaviour (editor, dialogs, forms) is
  verified manually in the browser. If you add a component test, you must add
  the environment too — and say so in the change.
- Nothing here replaces a real browser check: log in on the local site, exercise
  the flow you touched, and check the console.

---

## 11. Local development environment

Reference setup on the maintainer's machine (WSL2):

| Item | Value |
| --- | --- |
| Distro / user | Ubuntu-24.04, `erland` |
| Repository | `/home/erland/penalis-platform` |
| Test site | `/home/erland/wp-penalis`, symlinked as `wp-content/plugins/penalis-platform` |
| URL | `http://localhost:2121` (nginx), dashboard at `/dashboard/` |
| Credentials | Username: `erland`. Password: `wmvbPHqWw8wF3Ry` for local testing purposes only |
| Database table prefix | `wpec_` (not `wp_`) |
| PHP / Node | PHP 8.4 runtime, plugin targets 7.4+; Node 24 (`.nvmrc` pins 20 for CI) |
| WP-CLI | `/usr/local/bin/wp` |

Known quirks — these cost time if forgotten:

- `npm`/`node` only resolve in a login shell inside WSL: run
  `wsl -d Ubuntu-24.04 bash -lc '...'`, not `bash -c`.
- `wp plugin activate` as the normal user dies on an Elementor `ftp_nlist`
  fatal plus upload permission errors. Use
  `sudo -u www-data wp plugin <activate|deactivate> <slug> --path=/home/erland/wp-penalis --skip-plugins`.
  `--skip-plugins` also skips that plugin's own activation hooks, so settings are
  preserved.
- The `zoho-mail` plugin on the test site redefines `wp_mail()`, so
  `pre_wp_mail` never fires. Deactivate it (`--skip-plugins`) when you need to
  capture or inspect plugin email, then reactivate.
- `build/` is gitignored. After any frontend change you must run
  `npm run build` (or `npm run dev` while iterating) or the browser keeps
  serving the old hashed bundle. Hard-reload after a build.
- The test site is a disposable duplicate of production: local data may be
  modified freely. Production runs on a VPS and is not reachable from here.

Public site context: the old Elementor submit form on `/submit-karya-tulis` is
the legacy intake path. If you touch it, the intended direction is to point it
at the dashboard deeplinks instead of emailing the admin.

---

## 12. Known limitations (the honest backlog)

- Email delivery depends entirely on host/SMTP configuration; no SMTP library is
  bundled. Failures are recorded in user meta but the flow stops there.
- Rate limiting uses transients — single-server only, not a distributed store.
- Profile photos have no crop UI; the uploaded file is used as-is.
- The dashboard editor is a WYSIWYG surface, not a block editor (decision, see
  §13.2).
- Multisite is neither implemented nor tested.
- "Claiming" an existing legacy profile without a verified email is out of scope;
  the admin vouch path covers the human case.
- No component-level test environment (see §10).
- Registration can only be switched off through the `penalis_registration_enabled`
  option; there is no settings screen for it yet.
- The public site, theme, and Elementor integration are not verified from this
  repository.

There is no roadmap file in the repo. Treat this list plus the owner's stated
priorities as the backlog, and ask before inventing features.

---

## 13. Decision log (why things are the way they are)

### 13.1 Vite instead of `@wordpress/scripts`

The dashboard is a standalone React app mounted on one page, not a wp-admin
screen. Vite gives a manifest-driven build, fast HMR, code-splitting and no
WordPress build-toolchain coupling. PHP reads `build/manifest.json`, so asset
hashes change freely without touching PHP.

### 13.2 TipTap instead of Gutenberg or the Classic Editor

- Gutenberg outside wp-admin needs the `@wordpress/*` toolchain, an editor
  settings endpoint, and `unfiltered_html` for several blocks — writers have
  none of that.
- The Classic Editor is not React-compatible and cannot be styled to the
  dashboard.
- TipTap is headless, MIT, Vite-friendly, and serialises to **plain HTML** into
  `post_content`, which means zero migration and theme compatibility.
- A raw HTML mode exists as an escape hatch so markup the toolbar cannot express
  is never silently rewritten.

### 13.3 Core REST for content, custom REST for workflow

Posts, media, and taxonomies already have correct, capability-checked endpoints.
Duplicating them would duplicate security bugs. The plugin only implements what
core cannot know: account lifecycle, approval, legacy conversion, password
policy, publishing state machine.

### 13.4 No i18n layer

The community is Indonesian-only today, so `gettext` would add runtime cost and
translation files nobody maintains. Consequence: UI strings are hardcoded
Indonesian; if this ever changes it is a deliberate migration, not an
"improvement" to sprinkle into a feature change.

### 13.5 One User Avatar coexistence

Avatars are stored in `{prefix}user_avatar` — the key One User Avatar uses — so
existing assignments survive. While One User Avatar is active it stays the only
provider; when it is inactive the plugin registers `pre_get_avatar_data` itself.
Never write a second storage key.

---

## 14. Checklist before you call a change done

- [ ] Behaviour matches the status machine and capability table (§4).
- [ ] Server derives author/status/role; the client sends none of them.
- [ ] Any new REST route has a permission callback, validation, and (if public)
      a rate limit and a generic error message.
- [ ] Indonesian UI copy, no emoji, icons from `icons.tsx`, accessible labels,
      focus management, loading/empty/error states.
- [ ] `composer lint`, `composer test`, `npm run check` all pass.
- [ ] `npm run build` run, and the dashboard actually exercised in a browser
      (hard reload, console clean).
- [ ] `README.md` / `PROJECT-SPECIFICATION.md` / `SECURITY.md` / `readme.txt`
      updated where behaviour changed.
- [ ] Version bumped in all four places if the change is user-visible.
- [ ] No new dependency without a stated reason and a size check.
- [ ] Diff left uncommitted on a branch unless the owner asked for a commit.

---

## 15. Glossary

| Term | Meaning |
| --- | --- |
| **Penalis** | Penalis Komunitas Seni, the community and the site penalis.com |
| **penulis** | Writer — an approved account with the `penalis_writer` role |
| **karya tulis** | A literary article, i.e. a WordPress post |
| **dashboard** | The React writer UI at `/dashboard/`, not wp-admin |
| **legacy contributor** | Pre-plugin contributor account with no status meta and no password |
| **vouch** | Admin-assisted legacy conversion that skips the email proof but still requires approval |
| **deeplink** | Query parameter that opens a specific dashboard form (`src/lib/deeplink.ts`) |
| **kses** | WordPress HTML filtering; the only authority on allowed markup |
| **access gate** | `_penalis_access_enabled` plus status, enforced on login, REST, and capabilities |
