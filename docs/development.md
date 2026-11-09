# Development, Testing, Release & QA Guide

This guide covers setting up the development environment, running quality gates,
building production assets, creating releases, and executing manual QA checks.

---

## 1. Requirements

| Tool | Version | Purpose |
| --- | --- | --- |
| PHP | 7.4+ (8.2+ recommended for dev) | Plugin runtime and unit test runner |
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
- `build/assets/index-<hash>.js` (main dashboard shell)
- `build/assets/RichTextEditor-<hash>.js` (lazy-loaded TipTap editor chunk)
- `build/assets/index-<hash>.css`

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
  author slug redirects, avatar fallback, and bounded notifications.

### 3.2 Frontend Unit Tests (`src/lib/*.test.ts`)
- Runs via Vitest in the `node` environment.
- Tests pure logic in `src/lib/`: validation rules (`validation.test.ts`),
  deeplink parsing and token shape checks (`deeplink.test.ts`), verification
  code normalization and countdown formatting (`otp.test.ts`), HTML
  normalization (`html.test.ts`), formatting (`format.test.ts`), and public
  display name derivation (`profile.test.ts`).

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
  - PHP 7.4 syntax lint (`php -l`) across every tracked `.php` file.
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
     `wp-admin` with submenus **Ringkasan**, **Tinjauan Karya**, and
     **Penulis Penalis**.
2. **Registration & Email Verification**
   - Register a new account from `/dashboard/?penalis_register=1` (including an
     optional **Username Instagram**).
   - Confirm that the account is created as `subscriber` with status
     `pending_verification` and cannot create articles via the UI or REST API.
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
     category, tag, and featured image, and click **Simpan Draf**.
   - Click **Ajukan Tinjauan** and confirm. Verify the article state changes to
     **Menunggu tinjauan**, the editor switches to read-only mode, the admin
     receives a submission email, and the **Penalis** admin menu shows a pending
     badge.
5. **Editorial Review: Request Changes & Resubmission**
   - As an administrator, open **Penalis → Tinjauan Karya**, select the
     submission, enter feedback, click **Minta revisi**, and confirm in the
     dialog.
   - Verify the writer receives an email and a compact unread notification on
     the dashboard **Ringkasan** screen, can edit the article again, and can
     click **Ajukan Ulang**.
6. **Editorial Review: Approval & Read-Only Published State**
   - As an administrator, click **Setujui dan terbitkan** and confirm.
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

