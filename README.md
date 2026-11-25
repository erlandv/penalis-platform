# Penalis Platform

A self-contained WordPress plugin that provides a curated writer-account
lifecycle, an editorial submission and review pipeline, a dual-mode React
dashboard (`/dashboard/`) for writers and Senators (administrators and editors),
and public contributor directory & author profile experiences for the
[Penalis](https://penalis.com) literary community.

Penalis Platform runs entirely inside WordPress using native users, roles,
capabilities, posts, media, metadata, the WordPress REST API, and a single
lightweight `{prefix}penalis_post_views` table for lifetime view counts and
`Most Viewed` sorting — with no separate backend server and no parallel
authentication store.

---

## Key Features

- **Self-Service Writer Account Workflow** — Self-service registration (with an
  administrator toggle), scanner-safe single-use email verification that
  immediately activates the `penalis_writer` role, optional Instagram username
  collection for internal admin curation, optional Cloudflare Turnstile
  protection, administrator suspension/reactivation controls, and a bounded
  audit trail.
- **Legacy Contributor Activation** — Pre-plugin `contributor` accounts (created
  without passwords) can be activated immediately via self-service email
  verification or administrator vouching while preserving existing user IDs,
  authored posts, and author archive URLs.
- **Editorial Submission & Review Pipeline** — Writers compose drafts and submit
  them for review (`Submit Review` / `Submit Ulang`). Only a reviewer approval
  action (administrator or editor, with serialized per-post advisory locking)
  can publish a submission, published articles remain read-only to writers, and
  published-post takedowns are audited and recoverable via **Karya Terhapus**.
- **Dual-Mode React Dashboard (`/dashboard/`)** — Single-page application built
  with React 18, TypeScript, and Vite that enforces the no-hybrid-account rule:
  **Dashboard Penulis** provides a lazy-loaded TipTap WYSIWYG editor (with raw
  HTML mode, keyboard shortcuts, and revision caps), a **#KalimatHariIni**
  24-hour quote composer on **Ringkasan**, media library, writer profile &
  avatar picker, per-channel notification preferences, and re-authenticated
  credential settings (password, verified email change, and 301-redirected
  username rename); **Dashboard Senator** opens an isolated curation workspace
  with an in-dashboard **Review Karya** queue, manuscript reader, and
  **Kalimat Hari Ini** moderation console.
- **#KalimatHariIni (`/kalimat-hari-ini/`, `[penalis_kalimat_hari_ini]`)** —
  Ephemeral daily writer sentences (plain text, up to 200 characters / 3 lines,
  24-hour expiry with no stored quote history, rolling quota of 3 publishes per
  24 hours) displayed as social-note cards on `/author/<username>/` and on the
  auto-created public `/kalimat-hari-ini/` showcase (with search, sorting,
  unified **Bagikan** modal, pagination, and a subtle shine highlight on the
  signed-in writer's own card). Senator moderation is centralized in
  `/dashboard/?view=quotes` and **Penalis → Kalimat Hari Ini** in `wp-admin`,
  requiring a deletion reason (with quick-reason presets) and delivering a
  dashboard-only writer notification without sending email.
- **Public Authors Directory (`[penalis_authors]`)** — Dedicated React directory
  with live search, **Semua / Verified / Senator** filter chips, views/posts/A–Z
  sorting (powered by built-in `Penalis_Views`), an inline SVG badge legend, and
  a subtle visual highlight on the signed-in author's own card.
- **Public Author Archive (`/author/<username>/`) & Badges** — Server-rendered,
  SEO-friendly author profile template (`templates/author-archive.php`, with
  theme override and opt-out filter) featuring avatar, verified and Senator star
  badges (`[penalis_verified_badge]`, `[penalis_senator_badge]`), copyable
  profile URL, Indonesian joined date, contribution/view/like totals (`Karya
  Terbit`, `Total Views`, `Total Likes`, with a single-row 3-column mobile
  layout), unified **Bagikan** triggers (profile, active `#KalimatHariIni`, and
  each published work card), owner-only quick actions (**Edit Profil**, **Tulis
  Karya**, and **#KalimatHariIni**), an active **#KalimatHariIni** social-note
  card with client-side expiry guard, and a paginated works grid with
  poetry-safe excerpts, view counts, and like counts.
- **Shareable Social Cards (`Bagikan`, `[penalis_share_article]`)** — Pure
  client-side Canvas 2D PNG card generator for Instagram Story (`9:16`,
  1080×1920) and Feed (`1:1`, 1080×1080) in three editorial themes (**Kertas**,
  **Senja**, **Malam**) with dynamic theme font inheritance, official Penalis
  branding, Web Share API file sharing + PNG download, and a secondary copy
  action inside the modal. Supports **Profile** cards (including `Karya Terbit`,
  `Total Views`, and `Total Likes`), **#KalimatHariIni** cards, and **Published
  Article** cards (`dd/mm/yyyy` publish dates) across `/dashboard/`,
  `/kalimat-hari-ini/`, `/author/<username>/`, an integrated single-post
  reactions + share bar, and the `[penalis_share_article]` shortcode.
- **Built-in Article Engagement & Reading Utilities** — Replaces five external
  WordPress plugins natively inside Penalis Platform:
  - **Post Reactions (`Penalis_Reactions`)** — Cookie-based **Like / Dislike**
    buttons (with undo & direct switching) integrated into the single-post bar,
    non-destructive `pld_like_count` / `pld_dislike_count` fallback, like counts
    in `/dashboard/` and `/author/<username>/`, and administrator overrides in
    Block Editor, Classic Editor, and Quick Edit.
  - **Post Views Counter (`Penalis_Views`)** — Lightweight
    `{prefix}penalis_post_views` table with non-destructive migration from Post
    Views Counter, cache-safe JS view beacon with bot/prefetch filtering,
    configurable cookie deduplication & staff exclusion, administrator overrides
    in Block Editor / Classic Editor / Quick Edit, and full Atlas theme
    compatibility (`pvc_get_post_views()`, `Post_Views_Counter`, and
    `orderby=post_views`).
  - **Table of Contents (`Penalis_Toc`)** — Auto-inserted collapsible editorial
    Table of Contents for long posts with lowercase `#judul-bagian` anchors,
    customizable title/min headings/levels/position/default state, per-post
    editor disable toggle (preserving legacy `_lwptoc_settings`), `[penalis_toc]`
    shortcode support, and Rank Math SEO TOC registration.
  - **Default Featured Image & Legacy Avatar Protection** — Site-wide fallback
    featured image for published posts (`Penalis_Default_Thumbnail`) plus
    automatic non-destructive backup of One User Avatar metadata
    (`Penalis_Avatar`).
- **Unified WordPress Admin Console (`Penalis`)** — Centralized `wp-admin` menu
  with **Ringkasan** (overview counters and 8-week editorial analytics),
  **Review Karya Tulis** & **Karya Terhapus**, **Kalimat Hari Ini** (active
  24-hour quote moderation), **Penulis Penalis** (writer account curation and
  **Senator Penalis** marking for active writers and legacy contributors), **Log
  Email** (transactional delivery log, preview, and resend), and tabbed
  **Pengaturan** (**Keamanan**, **Pendaftaran**, **Batas Percobaan**, **Foto
  Profil**, **Thumbnail**, **Views**, **Table of Contents**, **Email**, and
  **Data**).

---

## Requirements

| Component | Version | Notes |
| --- | --- | --- |
| WordPress | 6.0+ | Requires pretty permalinks enabled |
| PHP | 8.2+ | Production runtime |
| Node.js & npm | Node 22.12+ (`.nvmrc` pins `24`), npm 10+ | Build-time only (not needed on the WordPress server) |
| Composer | 2.x | Development tooling (PHPCS and test scripts) only |

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
