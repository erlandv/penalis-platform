# Penalis Platform

A self-contained WordPress plugin that provides a curated writer-account
lifecycle, an editorial submission and review pipeline, and a React writing
dashboard (`/dashboard/`) for the [Penalis](https://penalis.com) literary
community.

Penalis Platform runs entirely inside WordPress using native users, roles,
capabilities, posts, media, metadata, and the WordPress REST API — with no
separate backend server, no custom database tables, and no parallel
authentication store.

---

## Key Features

- **Self-Service Writer Account Workflow** — Self-service registration,
  scanner-safe single-use email verification that immediately activates the
  `penalis_writer` role, optional Instagram username collection for internal
  admin curation, administrator suspension/reactivation controls, and a bounded
  audit trail.
- **Legacy Contributor Activation** — Pre-plugin `contributor` accounts (created
  without passwords) can be activated immediately via self-service email
  verification or administrator vouching while preserving existing user IDs,
  authored posts, and author archive URLs.
- **Editorial Submission & Review Pipeline** — Writers compose drafts and submit
  them for review (`Submit Review` / `Submit Ulang`). Only an administrator
  approval action can publish a submission, and published articles remain
  read-only to writers.
- **React Writing Dashboard (`/dashboard/`)** — Single-page application built
  with React 18, TypeScript, Vite, and a lazy-loaded TipTap WYSIWYG editor
  (with raw HTML escape hatch), media library, writer profile & avatar picker,
  compact editorial notifications, and re-authenticated credential settings
  (password, verified email change, and 301-redirected username rename).
- **Unified WordPress Admin Console (`Penalis`)** — Centralized `wp-admin` menu
  with **Ringkasan** (overview metrics and pending queues), **Review Karya Tulis**
  (2-column article review workspace with serialized per-post locking and
  confirmation dialogs), and **Penulis Penalis** (writer account curation and
  default profile photo).

---

## Requirements

| Component | Version | Notes |
| --- | --- | --- |
| WordPress | 6.0+ | Requires pretty permalinks enabled |
| PHP | 7.4+ | Production runtime |
| Node.js & npm | Node 20+, npm 9+ | Build-time only (not needed on the WordPress server) |
| Composer | 2.x | Development tooling (PHPCS) only |

---

## Quick Start

### Option A: Install from a Release ZIP

1. Download `penalis-platform-<version>.zip` from
   [GitHub Releases](https://github.com/erlandv/penalis-platform/releases).
2. In WordPress Admin, go to **Plugins → Add New → Upload Plugin**, upload the
   ZIP, and activate **Penalis Platform**.
3. Visit **Settings → Permalinks** and click **Save Changes** once.
4. Open **Penalis → Ringkasan** in `wp-admin` and `/dashboard/` on the frontend.

### Option B: Build from Source

```bash
git clone https://github.com/erlandv/penalis-platform.git
cd penalis-platform

npm ci
npm run build
```

Copy or symlink the plugin directory into `wp-content/plugins/penalis-platform/`
(ensuring the generated `build/` directory is present) and activate the plugin.

---

## Development & Quality Checks

```bash
# Install dev dependencies
npm ci
composer install

# Frontend checks (ESLint, TypeScript, Vitest, Vite production build)
npm run check

# PHP checks (WordPress Coding Standards + unit & workflow tests)
composer check
```

---

## Documentation

Detailed technical documentation is maintained in [`docs/`](docs/):

- [**Architecture & Data Model**](docs/architecture.md) — System overview, file
  map, account and editorial state machines, user/post metadata schema, and key
  technical decisions.
- [**REST API, Bootstrap & Deeplinks**](docs/rest-api.md) — Reference for all
  `penalis/v1` routes, core `wp/v2` usage, `window.penalisDashboard` bootstrap
  payload, and `/dashboard/` deeplink parameters.
- [**Security Model & Authorization**](docs/security.md) — Trust boundaries,
  8-layer authorization enforcement, REST route hardening, cryptographic token
  design, advisory locks, upload validation, and rate limits.
- [**Development, Testing, Release & QA Guide**](docs/development.md) — Build
  commands, test architecture, CI/CD release pipeline, and manual QA checklist.
- [**AI Agent Orientation (`AGENTS.md`)**](AGENTS.md) — Context, invariants, and
  working conventions for AI coding assistants.

---

## License

Licensed under the [GNU General Public License v2.0 or later](license.txt).
