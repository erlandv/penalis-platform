# AGENTS.md

Orientation for any AI agent working on **Penalis Platform**. Read this file
first; it explains what the product is, why it exists, how the code is
organised, and which rules must not be broken. Deeper reference documentation
lives in [`docs/architecture.md`](docs/architecture.md),
[`docs/rest-api.md`](docs/rest-api.md), [`docs/security.md`](docs/security.md),
and [`docs/development.md`](docs/development.md).

Language convention: **code, comments, documentation, commit messages, and this
file are English. User-facing copy in the dashboard, admin screens, and emails
is Indonesian, hardcoded.** Penalis is an Indonesian community; the writers read
Indonesian, the developers read English.

---

## 1. What Penalis is

Penalis (Penalis Komunitas Seni) is an Indonesian community for *karya seni
tulis* — literary writing: essays, short prose, poetry, reflections. The
community publishes member-submitted work on its blog, **penalis.com**.

The product is not "a generic WordPress site". It is a **curated publishing
pipeline for a literary community**: people who write want to be read, and the
site's job is to get their work from draft to published without friction while
keeping writer accounts verified and article submissions curated by human
administrators.

Everything in this repository exists to serve that pipeline. When a change makes
writing or reviewing slower, less pleasant, or less trustworthy, it is wrong
even if it is technically correct.

---

## 2. The problem this plugin solves

### 2.1 How contributions worked before this plugin

1. A visitor filled in an Elementor form on `/submit-karya-tulis` with their
   name, username, email, bio, profile photo, article title, category, tags, and
   article body.
2. The form emailed everything to the administrator.
3. The administrator manually created a `contributor` account in `wp-admin`
   with no password, then manually re-typed the article and set the post author
   so it appeared under `/author/<username>/`.

### 2.2 How it works with Penalis Platform

The plugin replaces manual account and post entry with a self-service writer
dashboard (**`/dashboard/`**, a React SPA — deliberately *not* `wp-admin`) paired
with a centralized administrator curation console in `wp-admin` (**Penalis**):

```text
register -> verify email -> active writer -> write draft in /dashboard/ -> submit for review -> admin approves & publishes
```

- Anyone can register (or registration can be toggled via the
  `penalis_registration_enabled` option).
- Once the writer verifies their email address, their account immediately
  becomes `active` (`penalis_writer` role, `_penalis_access_enabled = 1`) —
  no administrator account approval is required.
- Registration and `/dashboard/?view=profile` include an optional **Username
  Instagram** field (`_penalis_instagram`, normalized bare handle) for internal
  administrator visibility in `wp-admin`.
- Administrators manage writer accounts (including suspending/reactivating and
  viewing Instagram handles) under **Penalis → Penulis Penalis** and review
  submitted articles under **Penalis → Tinjauan Karya** (with a unified
  overview at **Penalis → Ringkasan**).
- Active writers receive the `penalis_writer` role and use `/dashboard/` for
  overview, notifications, own articles, editor, media, profile, and settings.
- Pre-plugin contributors use **"Aktifkan akun penulis lama"** to verify their
  stored email, set a first password, and immediately become `active` while
  keeping their existing user ID, authored posts, and author archive URL.

---

## 3. Product principles

1. **Writing and reviewing experience first.** Layout, typography, confirmation
   dialogs, keyboard behaviour, and loading states matter as much as
   correctness.
2. **The server is the authority.** Anything the browser sends is untrusted:
   author, status, ownership, capabilities, markup. Client validation exists for
   fast feedback only.
3. **Non-destructive by default.** Activation, upgrade, and uninstall never
   delete users, posts, media, or taxonomy terms. Data removal on uninstall is
   strictly opt-in.
4. **No silent data loss.** Never rewrite a writer's content behind their back.
   When markup cannot be represented by the toolbar, preserve it via raw HTML
   mode.
5. **One job per screen, obvious next action.** Both pending writers in
   `/dashboard/` and administrators in `wp-admin` must always see the current
   state and available actions clearly.
6. **Boring, legible technology.** WordPress core APIs, React 18, Vite, TipTap,
   and externalized admin CSS/JS (`assets/`). No exotic dependencies.
7. **Accessibility is part of UX.** Real `<label>` elements, `aria-describedby`
   on errors, visible focus, focus trapped in dialogs (`Dialog.tsx`,
   `ConfirmDialog.tsx`, and `assets/js/admin-review.js`), and inline SVG icons
   instead of emoji.

---

## 4. Domain model summary

Full details and storage tables live in
[`docs/architecture.md`](docs/architecture.md).

### 4.1 Account statuses (`Penalis_Account_Status`, `Penalis_Policy`)

| Status | Meaning | Can Sign In? | Can Write? |
| --- | --- | --- | --- |
| `pending_verification` | Registered, email unconfirmed | Yes (status view) | No |
| `active` (+ access flag `1`) | Verified active writer (`penalis_writer`) | Yes | Yes |
| `suspended` | Access revoked by administrator | No (sessions destroyed) | No |
| Legacy (no status meta) | Pre-plugin contributor without password | No (unless access flag `1`) | No |

Allowed transitions:
- `pending_verification` -> `active`, `suspended`
- `active` -> `suspended`
- `suspended` -> `active`
- Legacy conversion (`convert_legacy()`) -> `pending_verification` or `active` (self-service email activation and administrator `legacy_vouch` convert directly to `active`).

### 4.2 Editorial workflow (`Penalis_Editorial_Policy`, `Penalis_Editorial_Workflow`)

WordPress post statuses stay `draft`, `pending`, and `publish`. The Penalis
editorial state lives in `_penalis_editorial_state` and is reconciled against
`post_status` via `Penalis_Editorial_Policy::effective_state()`:

| Action | Actor | Transition | Feedback |
| --- | --- | --- | --- |
| `submit` | Article owner (`active` writer) | `draft` -> `pending_review` (`pending`) | N/A |
| `resubmit` | Article owner (`active` writer) | `changes_requested` -> `pending_review` (`pending`) | Clears active feedback |
| `approve` | Reviewer administrator | `pending_review` -> `published` (`publish`) | Optional |
| `request_changes` | Reviewer administrator | `pending_review` -> `changes_requested` (`draft`) | **Required** |
| `reject` | Reviewer administrator | `pending_review` -> `rejected` (`draft`) | **Required** |
| `reopen` | Reviewer administrator | `rejected` -> `changes_requested` (`draft`) | Optional |

Invariants that must never be broken:
- Only the administrator `approve` action may publish a writer submission.
- Writers cannot edit published articles (`post_status = publish`), under-review
  articles (`pending_review`), or rejected articles (`rejected`).
- Administrators may edit posts directly in `wp-admin` at any time.
- Reviewer decisions acquire a per-post MySQL advisory lock (`GET_LOCK`) and
  verify `expected_cycle` so concurrent admin decisions cannot collide.
- Drafts, pending submissions, review feedback, review history, and
  notifications are visible only to the article owner and reviewer
  administrators (`404 penalis_not_found` to other writers).

---

## 5. Architecture & file map

```text
penalis-platform.php        Plugin header, constants, Penalis_ autoloader, activation hooks
uninstall.php               Opt-in cleanup only
includes/                   PHP classes (Penalis_ prefix, one concern per file)
assets/css/admin.css        Shared wp-admin stylesheet (Ringkasan, Tinjauan Karya, Penulis Penalis)
assets/js/admin-review.js   Confirmation modals and inline validation for wp-admin actions
templates/dashboard.php     Minimal theme wrapper for [penalis_dashboard]
src/                        React SPA (app/, components/, features/, lib/, styles/)
build/                      Generated Vite output and manifest.json (gitignored)
tests/                      Plain-PHP unit/workflow tests (run-tests.php) and WP stubs (wp-stubs.php)
docs/                       Developer documentation (architecture, rest-api, security, development)
readme.txt                  WordPress plugin readme and changelog
```

- **Admin screens (`wp-admin`):** Top-level menu **Penalis**
  (`admin.php?page=penalis-platform`) with submenus **Ringkasan**
  (`penalis-platform`), **Tinjauan Karya** (`penalis-review`), and
  **Penulis Penalis** (`penalis-writers`). All admin styles live in
  `assets/css/admin.css` and confirmation dialog logic in
  `assets/js/admin-review.js` — do not inline large CSS or JS blocks inside PHP
  classes.
- **Password inputs:** Always use `src/components/PasswordInput.tsx` in the
  React dashboard so the accessible show/hide toggle stays consistent.
- **Two token worlds (never mix them):**
  - Email verification codes (`Penalis_Otp`): six digits from `random_int()`,
    bound to a purpose (`registration`, `legacy`, `email_change`) and an opaque
    43-char challenge ID. Only an HMAC-SHA-256 digest keyed with
    `wp_salt( 'auth' )` is stored in user meta. Codes expire after 10 minutes,
    allow 5 attempts, and are single use.
  - WordPress core password reset keys: 20-char alphanumeric from
    `get_password_reset_key()`. Never pass a reset key through `isTokenShaped()`.
  - `isTokenShaped()` in `src/lib/deeplink.ts` only describes the opaque
    challenge ID shape; it must never be used to validate a six-digit code.

---

## 6. Security invariants

See [`docs/security.md`](docs/security.md) for the complete security model.
Summary of rules that must always hold:

- Never trust client input for `author`, `status`, `role`, ownership, or HTML.
- Keep all eight authorization layers intact (`can_write()`, `user_has_cap`,
  `map_meta_cap`, `rest_pre_dispatch`, `rest_pre_insert_post`,
  `wp_insert_post_data`, query forcing, and upload validation).
- Every public endpoint must enforce rate limits, check the `penalis_website`
  honeypot where applicable, and return generic messages that do not leak
  account existence.
- Password reset requests (`POST /penalis/v1/password/forgot`) accept an **email
  address only**, never a username.
- Verification codes are consumed only through an explicit `POST` with the
  opaque `challenge_id` and the six-digit `code`. Never accept a code without
  its challenge ID, and never accept a challenge ID under a different purpose.
- Password reset keys in email links must only be consumed on an explicit
  `POST` action after the user submits a new password in the UI.

---

## 7. Working agreements & conventions

### 7.1 How to approach a task

1. Read this file and the relevant guide in `docs/`, then inspect the code
   before proposing changes.
2. For non-trivial changes, state the design, trade-offs, and recommendation
   before implementing if requirements are open to interpretation.
3. **Do not commit or push unless explicitly asked** by the user.
4. Verify every change by running the automated quality gates and reporting the
   exact command output.

### 7.2 Version synchronization

When bumping the plugin version, update all four places together:
1. `penalis-platform.php` header `Version:`
2. `PENALIS_PLATFORM_VERSION` in `penalis-platform.php`
3. `readme.txt` `Stable tag:` and `== Changelog ==`
4. `package.json` and `package-lock.json` `"version"`

### 7.3 Code conventions

- **PHP:** WordPress Coding Standards (`phpcs.xml.dist`), tabs for indentation,
  PHP 7.4 syntax compatibility, Yoda conditions, full docblocks, `penalis_` /
  `Penalis_` / `PENALIS_` prefixes, sanitize on input, escape on output. Keep
  pure business rules in `Penalis_Policy` and `Penalis_Editorial_Policy`.
- **TypeScript / React:** `strict` mode (`noUncheckedIndexedAccess`,
  `noUnusedLocals`, `noUnusedParameters`, no `any`), 2-space indentation, single
  quotes, function components with hooks, no auth or draft data in
  `localStorage` or `sessionStorage`.

---

## 8. Commands & quality gates

```bash
# Setup
composer install
npm ci

# PHP gates
composer lint             # PHPCS (WordPress Coding Standards)
composer test             # php tests/run-tests.php
composer check            # lint + test

# Frontend gates
npm run lint              # ESLint (--max-warnings=0)
npm run typecheck         # tsc --noEmit
npm test                  # Vitest unit tests
npm run build             # tsc --noEmit && vite build -> build/
npm run check             # lint + typecheck + test + build
```

All checks in `composer check` and `npm run check` must pass with zero errors
and zero warnings before a task is complete.
