# Development, Testing, Release & QA Guide

This guide covers setting up the development environment, running quality gates,
building production assets, creating releases, and executing manual QA checks.

---

## 1. Requirements

| Tool | Version | Purpose |
| --- | --- | --- |
| PHP | 8.2+ | Plugin runtime and unit test runner |
| WordPress | 6.0+ | Target CMS runtime |
| Node.js | 20+ (`.nvmrc` pins `20`) | Frontend build & Vitest (build-time only) |
| npm | 9+ | Frontend package management |
| Composer | 2.x | PHP_CodeSniffer / WPCS (dev-time only) |

Neither Node.js nor Composer is required on the production WordPress server.

---

## 2. Repository Setup & Commands

### Initial Setup

```bash
# Install frontend dependencies from package-lock.json
npm ci

# Install PHP_CodeSniffer and WordPress Coding Standards
composer install
```

### Frontend Commands (`package.json`)

```bash
npm run dev        # Start Vite dev server for standalone UI iteration
npm run lint       # Run ESLint with --max-warnings=0
npm run typecheck  # Run TypeScript compiler check (tsc --noEmit)
npm test           # Run Vitest unit test suite
npm run build      # Type-check and build production bundle into build/
npm run check      # Run lint + typecheck + test + build
```

The production build generates:
- `build/manifest.json`
- `build/assets/dashboard-<hash>.js` and `build/assets/dashboard-<hash>.css` (writer dashboard shell)
- `build/assets/authors-<hash>.js` and `build/assets/authors-<hash>.css` (public `[penalis_authors]` directory)
- `build/assets/RichTextEditor-<hash>.js` (lazy-loaded TipTap editor chunk)
- `build/assets/icons-<hash>.js` (shared SVG icon chunk)

> **Important:** `build/` is gitignored. After modifying any file under `src/`,
> run `npm run build` so WordPress loads the updated bundle from
> `build/manifest.json`.

### PHP Commands (`composer.json`)

```bash
php tests/run-tests.php  # Run the isolated PHP unit and workflow test suite
composer lint            # Run PHPCS (WordPress Coding Standards)
composer lint:fix        # Run PHPCBF auto-fixer
composer check           # Run composer lint + php tests/run-tests.php
```

---

## 3. Automated Testing Architecture

### 3.1 PHP Test Suite (`tests/run-tests.php`)
- Runs in plain PHP (< 0.1s) with zero database or WordPress installation
  dependencies.
- Uses lightweight deterministic stubs in `tests/wp-stubs.php` for users, user
  meta, posts, post meta, transients, mail capture, and MySQL advisory locks
  (`Penalis_Test_WPDB`).
- Covers account policy, status transitions, legacy conversions, editorial
  policy and state machine, serialized workflow transitions, REST route
  permissions, IDOR guards, post-insert filters, username/email changes,
  author slug redirects, avatar fallback, bounded notifications, and
  timezone-aware date formatting (`Penalis_Datetime` with IANA and fixed-offset
  site timezones, including Jakarta and year rollover).

### 3.2 Frontend Unit Tests (`src/lib/*.test.ts`, `src/authors/*.test.ts`)
- Runs via Vitest in the `node` environment.
- Tests pure logic across `src/lib/` and `src/authors/`: validation rules
  including `validatePasswordPair()` (`validation.test.ts`), deeplink parsing and
  token shape checks (`deeplink.test.ts`), verification code normalization,
  countdown formatting, and `toActiveChallenge()` (`otp.test.ts`), HTML
  normalization (`html.test.ts`), formatting (`format.test.ts`), public display
  name derivation (`profile.test.ts`), image crop math (`crop.test.ts`),
  lazy-chunk retry/preload helpers (`lazyWithRetry.test.ts`), and public authors
  URL query state (`src/authors/query.test.ts`).
- Date/time tests pass an explicit `timeZone` option (`Asia/Jakarta`, `UTC`) so
  assertions are deterministic regardless of the machine running the suite.

---

### 3.3 Timezone Policy

Penalis stores and displays time in two deliberately different ways. Mixing
them is the most common source of "off by seven hours" bugs, so the rules are
enforced by helpers rather than by ad-hoc `date()` calls.

### PHP (`wp-admin`)

- **Never call `date_i18n()`, `mysql2date()`, `date()`, or `strtotime()` on a
  stored timestamp.** They use the server's default timezone, which is not the
  site timezone.
- Use `Penalis_Datetime` (`includes/class-datetime.php`):
  - `Penalis_Datetime::format( $timestamp, $format )` — Unix timestamps
    (`_penalis_submitted_at`, `_penalis_reviewed_at`, review history
    `created_at`, account status log `time`). Formats with `wp_date()` in the
    site timezone.
  - `Penalis_Datetime::format_gmt( $mysql_gmt, $format )` — GMT columns such as
    `user_registered`, `post_date_gmt`, and `post_modified_gmt`. Parses the
    value explicitly as UTC, then formats it in the site timezone.
  - `Penalis_Datetime::format_local( $mysql_local, $format )` — local columns
    such as `post_modified`. Parses and formats in the site timezone without
    converting, preserving the stored calendar day.
  - `Penalis_Datetime::date_format()` / `time_format()` — the site's
    `date_format` / `time_format` options with safe defaults.
- The site timezone comes from `wp_timezone()`, which honours both
  `timezone_string` (IANA) and `gmt_offset` (fixed offset, including half-hour
  offsets such as `+05:30`).
- `Penalis_Datetime::site_timezone_payload()` is exposed to the dashboard as
  `penalisDashboard.siteTimezone` (`string`, `offsetSeconds`, `label`). It is
  informational only.

### React (`/dashboard/`)

- Timestamps are displayed in the **reader's browser timezone**, never forced
  to the site timezone. A writer travelling abroad reads times on their own
  clock; the bootstrap `siteTimezone` payload only explains which timezone the
  site uses.
- Use the helpers in `src/lib/format.ts`:
  - `parseDateValue()` — accepts epoch seconds, epoch milliseconds, `Date`,
    ISO strings with `Z`/numeric offsets, and offset-less WordPress GMT strings
    (treated as UTC). Explicit offsets are preserved.
  - `formatDate()` — calendar day only (`13 Nov 2025`).
  - `formatDateTime()` — calendar day, hours, minutes, and a short timezone
    label (`13 Nov 2025, 03.30 WIB`). Use this for editorial timestamps
    (submission, review, review history) and notifications.
  - `formatTime()` — clock time and a short timezone label (`03.30 WIB`). Use
    this for the editor's save indicator, where the calendar day is noise.
- All helpers use the `id-ID` locale and accept an optional `{ timeZone }`
  override for deterministic tests. Production call sites must not pass it.

---

## 4. Versioning & Release Workflow

### 4.1 Version Synchronization
Whenever bumping the plugin version, update all four locations together:
1. `penalis-platform.php` — plugin header `* Version:`
2. `penalis-platform.php` — `define( 'PENALIS_PLATFORM_VERSION', '...' );`
3. `readme.txt` — `Stable tag:` and `== Changelog ==`
4. `package.json` and `package-lock.json` — `"version"`

`Penalis_Plugin::maybe_upgrade()` compares the stored `penalis_platform_version`
option against `PENALIS_PLATFORM_VERSION` to run non-destructive upgrades.

### 4.2 GitHub Actions CI & Release
- **`.github/workflows/ci.yml`** runs on every push and pull request targeting `main`:
  - PHP 8.2 coding standards (`composer lint`) and unit tests (`composer test`).
  - PHP 8.2 syntax lint (`php -l`) across every tracked `.php` file.
  - Frontend quality gate (`npm run check`) and `build/manifest.json` verification.
- **`.github/workflows/release.yml`** runs automatically when a `v*` tag (for
  example `v1.0.0`) is pushed:
  1. Verifies that the git tag matches `penalis-platform.php` and `readme.txt`.
  2. Runs `composer lint`, `composer test`, and `npm run check`.
  3. Packages only production runtime files (`penalis-platform.php`,
     `uninstall.php`, `readme.txt`, `license.txt`, `assets/`, `includes/`,
     `templates/`, `build/`) into `penalis-platform-<version>.zip`.
  4. Validates that `build/manifest.json` is present and that no development
     directories (`src/`, `tests/`, `docs/`, `vendor/`, `node_modules/`,
     `.github/`) leaked into the archive.
  5. Publishes a GitHub Release with the `.zip` asset attached.

---

## 5. Manual QA Checklist

Use this checklist to verify a deployment on a staging or local WordPress site:

1. **Activation & Admin Console**
   - Activate **Penalis Platform**, save **Settings → Permalinks**, and verify
     that `/dashboard/` loads and the top-level **Penalis** menu appears in
     `wp-admin` with submenus **Ringkasan**, **Review Karya Tulis**,
     **Penulis Penalis**, and **Pengaturan Email** (administrators only).
2. **Registration & Email Verification**
   - Register a new account from `/dashboard/?penalis_register=1` (including an
     optional **Username Instagram**).
   - Confirm that the account is created as `subscriber` with status
     `pending_verification` and cannot create articles via the UI or REST API.
   - Confirm that attempting to register with an already taken username immediately
     displays an inline field error and does not proceed to verification.
   - Confirm that attempting to register with an already registered email returns
     a generic challenge (anti-enumeration) and delivers `duplicate_notice` or
     `legacy_register_notice` to the stored email address.
   - Confirm the dashboard immediately shows the **Masukkan Kode Verifikasi**
     screen, and that the email contains a six-digit code and no link.
   - Enter the code and verify the account immediately becomes `active` with the
     `penalis_writer` role and receives the active writer welcome email.
   - Confirm a wrong code is rejected, that five wrong codes invalidate the
     challenge, and that **Kirim ulang kode** is throttled by a countdown.
3. **Administrator Account Management**
   - In **Penalis → Penulis Penalis**, verify the writer's **Instagram** handle
     is displayed and linked, and test the confirmation modal for account
     actions (`Tangguhkan` and `Aktifkan kembali`).
4. **Draft Creation & Editorial Submission**
   - Sign in as the approved writer, create a draft with title, content,
     category, tag, and featured image, and click **Simpan Draft**.
   - Click **Submit Review** and confirm. Verify the article state changes to
     **Menunggu review**, the editor switches to read-only mode, the
     reviewers (administrators and editors) receive a submission email, and the
     **Penalis** admin menu shows a pending badge.
5. **Editorial Review: Request Changes & Resubmission**
   - As a reviewer (administrator or editor), open **Penalis → Review Karya
     Tulis**, select the submission, enter feedback, click **Minta revisi**, and
     confirm in the dialog.
   - Verify the writer receives an email and a compact unread notification on
     the dashboard **Ringkasan** screen, can edit the article again, and can
     click **Submit Ulang**.
6. **Editorial Review: Approval & Read-Only Published State**
   - As a reviewer (administrator or editor), click **Setujui dan terbitkan**
     and confirm.
   - Verify the article is published on the public site, the writer receives a
     publication notification, and the article remains read-only to the writer
     in the dashboard (and refuses direct `POST /wp/v2/posts/{id}` edits from
     the writer).
7. **Cross-Writer Isolation & Direct REST Hardening**
   - With two writer accounts (Writer A and Writer B), verify that Writer A gets
     `404` when requesting `GET /wp-json/penalis/v1/articles/<B_draft_id>` or
     `/review`, and `403` (`penalis_publish_forbidden`) when attempting to send
     `"status": "publish"` to `POST /wp-json/wp/v2/posts`.
8. **Profile, Avatar & Credential Changes**
   - Upload a profile photo (JPEG/PNG/GIF/WebP >= 100x100px), test public name
     display modes, test verified email change, and test username change
     (confirming the old `/author/<old-slug>/` URL 301-redirects to the new
     author archive).
9. **Account Suspension**
   - Suspend an active writer from **Penalis → Penulis Penalis**. Confirm their
     active browser session is logged out immediately while their previously
     published articles remain live on the public site.

