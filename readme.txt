=== Penalis Platform ===
Contributors: penalis
Tags: writing, dashboard, editorial, publishing, community
Requires at least: 6.0
Requires PHP: 8.2
Stable tag: 2.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Curated publishing workflow and React writing dashboard for the Penalis literary community on WordPress.

== Description ==

Penalis Platform adds a self-service writer account lifecycle, an editorial
submission and review workflow, and a frontend React writing dashboard
(`/dashboard/`) to WordPress. It is built for the Penalis literary community
(penalis.com), where member-submitted essays, prose, and poetry are curated by
reviewers (administrators and editors) before publication.

Key features:

* **Self-service writer account workflow.** Visitors register through
  `/dashboard/` (with an optional Instagram username for internal admin
  curation) and confirm their email address with a six-digit code to
  immediately become active writers (`penalis_writer` role). Administrators can
  manage accounts, resend verification codes, or suspend/reactivate accounts
  under **Penalis → Penulis Penalis**. Suspension immediately revokes access and
  ends active sessions. Every transition is recorded in a bounded audit log.
* **Legacy contributor activation.** Pre-plugin contributor accounts (created
  without passwords for past submissions) stay protected from unauthorized login
  or password resets until activated through self-service email verification or
  administrator vouching, which immediately activates the account while
  preserving existing user IDs, authored posts, and author archives.
* **Editorial submission and review workflow.** Writers save drafts and submit
  them for review (`Submit Review` / `Submit Ulang`). Writers cannot publish
  directly and cannot edit published articles. Reviewers (administrators and
  editors) review submissions in a 2-column workspace under **Penalis → Review
  Karya Tulis**, where they can approve and publish, request revisions with
  required feedback, reject with required feedback, or reopen a rejected
  submission.
* **Email and in-dashboard notifications.** Submissions and review decisions
  dispatch branded responsive HTML emails and record bounded in-dashboard
  notifications on the **Ringkasan** screen.
* **React writing dashboard (`/dashboard/`).** Includes an overview with
  editorial status counters and notifications, a filterable article list, a
  lazy-loaded TipTap WYSIWYG editor with raw HTML mode, a media library, a
  writer profile with One User Avatar-compatible profile photos, and account
  settings (password change, verified email change, and re-authenticated
  username change with 301 author-archive redirects).
* **Senator curation mode in the same dashboard.** Administrators and editors
  (called **Senator** in the interface) open `/dashboard/` in a separate mode:
  the title reads **Dashboard Senator**, a role chip names the active role, the
  navigation is the curation set (Ringkasan, Review Karya, Notifikasi,
  Pengaturan), and the review queue is worked in place. Writer screens are not
  rendered at all, and a staff account never gains writer capabilities.
* **Unified WordPress admin console (`Penalis`).** Groups **Ringkasan**,
  **Review Karya Tulis**, **Penulis Penalis**, and **Pengaturan Email** under
  one top-level menu with pending notification badges, customizable
  transactional email templates, and confirmation dialogs on all administrative
  actions.

== Installation ==

1. Upload the `penalis-platform` plugin folder (including the compiled `build/`
   and `assets/` directories) to `wp-content/plugins/penalis-platform/`, or
   install the release ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate **Penalis Platform** in **Plugins**.
3. Visit **Settings → Permalinks** and save once so the `/dashboard/` page and
   REST routes resolve cleanly.
4. Open **Penalis → Ringkasan** in `wp-admin` to access the admin overview,
   article review queue, and writer curation screen.

Activation is non-destructive: it registers the `penalis_writer` role, records
the plugin version, and creates the `/dashboard/` page if needed. It never
deletes or modifies existing users, posts, media, comments, or taxonomy terms.

Recommended site configuration:

* Configure reliable outgoing email delivery (SMTP plugin) so verification,
  password reset, and editorial notification emails are delivered.
* Exclude `/dashboard/*` and `/wp-json/penalis/*` from full-page and CDN caches.

== Frequently Asked Questions ==

= Do writers use wp-admin to write articles? =

No. Approved writers use the frontend dashboard at `/dashboard/`. Senators
(administrators and editors) also use `/dashboard/` for curation — the review
queue lives there — and use `wp-admin` (under the **Penalis** menu) for account
management and site-wide settings: administrators and editors can both curate
articles, while only administrators can manage writer accounts and site-wide
settings.

= Can a Senator write articles with their staff account? =

No. Penalis policy is that a staff account never writes. A Senator who wants to
publish logs out and signs in with their own writer account; the dashboard says
so on Ringkasan and in the account menu.

= Can a writer publish an article directly or edit an already published article? =

No. Writers can only save drafts and submit or resubmit them for review. Only a
reviewer approval action (administrator or editor) under **Penalis → Review Karya
Tulis** can publish an article, and published articles are strictly read-only for
writers both in the dashboard UI and at the REST API level.

= Are existing contributor accounts and posts affected on activation? =

No. Existing posts, author archives, and contributor accounts remain untouched.
Dormant legacy contributor accounts cannot sign in or reset passwords until they
complete the legacy activation flow to verify their email and set a password.

= What happens when a writer changes their username? =

The writer must re-authenticate with their current password. Their user ID is
preserved, their author archive moves to the new slug, and their previous author
slug is reserved and 301-redirected so existing public links keep working.

= What file types can writers upload? =

Writers may upload JPEG, PNG, GIF, and WebP images. Uploads are validated both
by MIME type and by inspecting the image header (`getimagesize()`), so SVG and
renamed executable files are rejected.

== Changelog ==

= 2.5.0 =
* Feature: Add built-in **Post Reactions** (`Penalis_Reactions`, replacing *Posts Like Dislike*) with cookie-based **Like / Dislike** buttons (supporting undo and direct switching under per-post advisory locks) integrated into the single-post bar alongside **Bagikan**, non-destructive read-only fallback to `pld_like_count` / `pld_dislike_count` and legacy `pld_{id}` voter cookies, rate limiting (`post_reaction_ip`), like counts surfaced across `/dashboard/` (**Ringkasan**, **Daftar Karya**, published **Editor**), `/author/<username>/` (**Total Likes** and per-card likes), and administrator-only (`manage_options`) manual count overrides in Block Editor, Classic Editor, and `/wp-admin/edit.php` Quick Edit.
* Feature: Add built-in **Post Views Counter** (`Penalis_Views`, replacing *Post Views Counter*) backed by a lightweight `{prefix}penalis_post_views` table, automatic non-destructive `GREATEST` migration from `{prefix}post_views` (plus manual sync and status panel in **Penalis → Pengaturan → Views**), cache-safe JS view beacon (`assets/js/post-views.js`) with bot and browser prefetch/prerender filtering, configurable cookie deduplication (`0–1440` minutes, default `30`), optional logged-in staff exclusion, administrator overrides in Block Editor, Classic Editor, and Quick Edit (with sortable `Views` column), and an Atlas theme compatibility bridge (`includes/pvc-compat.php`, `includes/pvc-compat-class.php`, and `orderby=post_views` `posts_clauses`).
* Feature: Add built-in **Table of Contents** (`Penalis_Toc`, replacing *LuckyWP Table of Contents*) that auto-inserts a collapsible editorial TOC card (`assets/css/toc.css`, `assets/js/toc.js`) on long posts (default title `Table of Contents`, default collapsed, `4+` `H2`/`H3` headings, lowercase `#judul-bagian` anchors, hierarchical decimal numbering, smooth scroll), with a **Penalis → Pengaturan → Table of Contents** tab, `[penalis_toc]` / `[lwptoc]` shortcode support, per-post disable toggle in Block Editor (`assets/js/editor-toc.js`) and Classic Editor preserving legacy `_lwptoc_settings`, automatic coexistence while LuckyWP TOC is active, and Rank Math SEO TOC registration.
* Feature: Add built-in **Default Featured Image** fallback (`Penalis_Default_Thumbnail`, replacing *Default Featured Image*) for published `post` records, configurable under **Penalis → Pengaturan → Thumbnail** and protected against writer deletion in `Penalis_Media_Usage`.
* Feature: Add automatic non-destructive backup of legacy *One User Avatar* metadata (`{prefix}user_avatar` → `_penalis_legacy_avatar_id`, `avatar_default_wp_user_avatar` → `penalis_default_avatar_id`) in `Penalis_Avatar` with migration status and manual re-sync under **Penalis → Pengaturan → Foto Profil**, protecting existing writer profile photos when uninstalling One User Avatar.
* Feature: Include **Total Likes** alongside **Karya Terbit** and **Total Views** on generated **Profile Share Cards** (`buildProfileCardStats()`), format **Article Share Card** dates as `dd/mm/yyyy` (`formatNumericDate()`), refine the **Senja** Share Card theme to a warmer golden-hour palette, and boost Penalis watermark visibility on light themes (**Kertas** and **Senja**).
* Polish: Switch the public author archive stats strip (`.penalis-profile-stats`) to a compact single-row 3-column grid on mobile viewports (`<= 640px`).
* Maintenance: Upgrade frontend toolchain to Vite 8.3.8, `@vitejs/plugin-react` 6.3.0, Vitest 5.1.1, TipTap 3.35.0, `@types/react` 18.3.32, and `typescript-eslint` 8.74.0 (zero `npm audit` vulnerabilities), pin `.nvmrc` to Node 24 LTS (`engines.node >= 22.12.0`), add `.github/dependabot.yml`, add an automated atomic VPS production deploy job to `.github/workflows/release.yml`, and refresh developer documentation (`AGENTS.md`, `README.md`, `docs/`).

= 2.4.1 =
* Fix: Prevent the Share Card modal (`ShareCardModal`) from overflowing behind mobile browser address bars and toolbars on iOS Safari and Brave by switching height constraints from `100vh` to dynamic viewport height (`100dvh`) with `safe-area-inset` padding and proper flex scroll containment (`flex-shrink: 0` on header, `flex: 1 1 auto; min-height: 0` on scrollable body).
* Polish: Render the Share Card modal as a compact mobile bottom sheet (`<= 720px`) across `/dashboard/`, `/kalimat-hari-ini/`, `/author/<username>/`, and single posts with viewport-adaptive preview sizing and a two-column secondary actions row (**Unduh PNG** and **Salin**) below **Bagikan Gambar**.

= 2.4.0 =
* Feature: Add a client-side Canvas 2D **Shareable Social Card** generator (`src/lib/shareCard.ts`, `ShareCardModal.tsx`, and a zero-React vanilla-TS bundle `src/share-card/author-archive.ts` for public pages) supporting **Profile**, **#KalimatHariIni**, and **Published Article** cards in Instagram Story (`9:16`, 1080×1920) and Feed (`1:1`, 1080×1080) formats across three editorial themes (**Kertas**, **Senja**, **Malam**) with dynamic theme font inheritance (`Plus Jakarta Sans` fallback), official Penalis brand assets (`assets/images/logo.png`, `icon.png` via `Penalis_Dashboard::brand_assets()`), Web Share API file sharing, PNG download, and in-modal link/quote copy.
* Feature: Add **Published Article** sharing (`Penalis_Article_Share`, `assets/css/article-share.css`) with an automatic share callout bar on published single posts (rendered below like/dislike controls and `.single-tags` via `th90_article_elements_below` on Atlas or late on `the_content` on generic themes, with `penalis_auto_append_article_share` opt-out), the `[penalis_share_article]` shortcode (with automatic deduplication), per-card **Bagikan** buttons on `/author/<username>/` (`.penalis-archive-card`) and Dashboard **Daftar Karya**, and a shared poetry-safe excerpt formatter (`Penalis_Article_Share::clean_excerpt()`) that preserves spaces across `<br>` and block boundaries.
* Feature: Unify sharing across `/author/<username>/`, `/kalimat-hari-ini/`, and `/dashboard/` under a single **Bagikan** trigger, consolidating profile, quote, and article copy-to-clipboard actions inside the Share Card modal.
* Feature: Require a deletion reason (up to 300 characters, with 4 quick-reason presets) when a Senator removes an active **#KalimatHariIni** from **Dashboard Senator** (`DELETE /penalis/v1/quotes/{user_id}`) or `wp-admin` (**Penalis → Kalimat Hari Ini**), delivering a dashboard-only `quote_removed` notification to the writer (no email, exempt from article opt-out preferences).
* Feature: Add a **Senator** filter tab with live count to **Penalis → Penulis Penalis**, and add direct clickable links to public author archives (`authorUrl`) across **Penulis Penalis**, **Review Karya Tulis**, and the Dashboard Senator **Review Karya** manuscript reader.
* Fix: Prevent `Dialog.tsx` from re-running its mount focus-trap effect on parent re-renders (`onCloseRef`), eliminating focus stealing while typing inside modal forms.

= 2.3.0 =
* Feature: Add **#KalimatHariIni** (`Penalis_Daily_Quotes`, `Penalis_Daily_Quotes_Admin`) — ephemeral 24-hour writer sentences (plain text, up to 200 characters and 3 lines, no stored quote history, rolling quota of 3 publishes per 24 hours serialized via per-user MySQL advisory locks). Includes a social-note composer on the writer dashboard **Ringkasan** (`GET/DELETE /penalis/v1/quotes/me`, `POST /penalis/v1/quotes`), an active social-note card below the profile card on `/author/<username>/` (with client-side `data-expires-at` cache expiry guard and copy-quote action), and an auto-created `/kalimat-hari-ini/` showcase (`[penalis_kalimat_hari_ini]`, `GET /penalis/v1/quotes`, separate Vite bundle) with search, sorting, pagination, copy-quote, and a subtle shine highlight on the signed-in writer's own card.
* Feature: Add centralized Senator moderation for **#KalimatHariIni** in **Dashboard Senator** (`/dashboard/?view=quotes`, `DELETE /penalis/v1/quotes/{user_id}`, with search, sort, load-more pagination, and confirmation dialogs) and in `wp-admin` (**Penalis → Kalimat Hari Ini**, `?page=penalis-quotes`), keeping the public `/kalimat-hari-ini/` page read-only.
* Feature: Upgrade dashboard **Media** management with direct image uploads, pagination, per-user rate-limited deletion of unused writer-owned images (`DELETE /penalis/v1/media/{id}`), and strict usage tracking (`Penalis_Media_Usage`) that blocks deleting any image still referenced as a featured image, inline article image, or avatar (`409 penalis_media_in_use`).
* Feature: Store cropped writer profile photos as standalone opaque `256×256` JPEGs (quality 85, PNG transparency flattened onto white) under `wp-content/uploads/penalis-avatars/` (`_penalis_avatar_file`, `Penalis_Avatar`) via rate-limited `POST /penalis/v1/account/avatar` instead of creating Media Library attachments, automatically deleting superseded files while keeping read-only fallback for legacy `{prefix}user_avatar` attachments and opt-in uninstall cleanup.
* Feature: Redesign the writer dashboard **Daftar Karya** screen (`Articles.tsx`) with a responsive card layout and per-status article counts.

= 2.2.0 =
* Feature: Add a bundled, server-rendered public author archive template (`Penalis_Author_Archive`, `templates/author-archive.php`, `assets/css/author-archive.css`, `assets/js/author-archive.js`) for `/author/<username>/` with opt-out filter (`penalis_use_author_archive_template`) and theme override (`penalis-author-archive.php`) support. Includes a social-style profile header (avatar, verified and Senator badges, `@username`, copyable profile URL, locale-independent Indonesian joined date via `Penalis_Datetime::format_indonesian_month_year_gmt()`, published post & view totals, native Web Share API with clipboard fallback, and owner-only **Edit Profil** / **Tulis Karya** actions below the bio) plus a 3-column works grid with `dd/m/Y` dates and WordPress pagination.
* Feature: Enforce a normalized 250-character bio limit (`Penalis_Policy::MAX_BIO`) and collapse consecutive blank lines to a single newline (`Penalis_Policy::normalize_bio()`, `normalizeBio()`, `collapseBioNewlines()`) without deleting existing longer bios. In **Profil** (`/dashboard/?view=profile`), writers with legacy bios longer than 250 characters see a clear over-limit counter/hint and can edit freely to shorten before saving; on public author archives, bios exceeding 250 characters (or 3+ newlines) render with CSS `line-clamp` (3 lines desktop, 5 lines mobile) and a **Selengkapnya** / **Lebih sedikit** toggle.
* Feature: Add **Semua Kontributor** link (`window.penalisDashboard.authorsUrl`) directly below **Lihat profil publik** in the writer account menu.
* Polish: Tighten consecutive badge spacing (`Name` <-> `Verified` <-> `Senator`) across `AuthorsDirectory`, `templates/author-archive.php`, and inline post badges, and skip `wp_footer` DOM badge injection on bundled author archives.

= 2.1.0 =
* Feature: Allow administrators to mark active `penalis_writer` accounts and unactivated legacy `contributor` accounts as **Senator Penalis** (`_penalis_is_senator`) from **Penalis → Penulis Penalis** with confirmation dialogs, status flags, and preserved filter/search pagination state, without granting staff capabilities to personal writer accounts.
* Feature: Display an amber star Senator badge across the public authors directory (`[penalis_authors]`), single-post author boxes, author archive headings, and the `[penalis_senator_badge]` shortcode, backed by `Penalis_Policy::is_senator_author()`, `Penalis_Authors::is_user_senator()`, and automatic cache invalidation (`penalis_public_authors_v4`).
* Feature: Add a **Senator** filter chip (`?senator=1`) and `totalSenator` counter to `GET /penalis/v1/authors` and `AuthorsDirectory`, plus a compact inline SVG badge legend below the shortened directory subtitle.
* Feature: Highlight the currently signed-in author's own card in `AuthorsDirectory` (`penalisAuthors.currentUserId`) with a subtle accent tint, avatar ring, periodic shine animation (respecting `prefers-reduced-motion`), and `You` badge while keeping `GET /penalis/v1/authors` publicly cacheable.
* Fix: Parse `wp_localize_script()` boot integers (`currentUserId`, `perPage`) with `parsePositiveInt()` so strict ID matching works reliably when WordPress serializes scalars as strings.

= 2.0.0 =
* Feature: Split `/dashboard/` into explicit writer (**Dashboard Penulis**) and staff (**Dashboard Senator**) modes. `Penalis_Policy::dashboard_role()` resolves one role (`administrator`, `editor`, `writer`, `pending`, `suspended`) and publishes it on `GET /account` and the SPA bootstrap, rendering a role chip, Senator teal accent palette, role-specific navigation groups, and no disabled writer entries for staff.
* Feature: Enforce the no-hybrid-account rule in the dashboard UI. Staff accounts that also carry writer access still resolve to staff, and deep links to writer views clamp to Ringkasan with an explanatory notice and rewritten URL.
* Feature: Add an in-dashboard **Review Karya** queue and full manuscript reader for Senators (`GET /penalis/v1/review/queue` and `POST /penalis/v1/articles/{id}/decision`), supporting approve, request-changes, and reject actions with cycle locking, deep-linked submissions (`?view=review&id=<post_id>`), and Back/Forward browser history (`pushState` + `popstate`).
* Feature: Add submission trends and editorial review metrics analytics (`Penalis_Analytics`) to **Penalis → Ringkasan**.
* Feature: Add published-article takedown auditing and recovery (`Penalis_Article_Takedown`) with a **Penalis → Karya Terhapus** screen, protecting published posts from writer deletion and notifying Senators when a published post is trashed.
* Feature: Add transactional email delivery logging (`Penalis_Email_Log`) with a **Penalis → Log Email** admin screen for inspecting, filtering, and clearing log entries.
* Feature: Add configurable rate limiting and trusted proxy IP detection (`Penalis_Rate_Limiter`) with an audit trail under **Penalis → Pengaturan**.
* Feature: Add per-channel (dashboard and email) editorial notification preferences (`GET/POST /penalis/v1/notifications/preferences`) in Settings and read/unread filtering on the Notifications screen.
* Feature: Add editor keyboard shortcuts (`EditorShortcutsDialog`), action confirmation dialogs, refined autosave thresholds, draft revision limits (`Penalis_Revisions`), and automatic `wp_rest` nonce refresh on session expiration.
* Feature: Add global toast notifications (`ToastProvider`), a password strength meter with live match feedback, and a time-based Indonesian greeting on Overview.
* Feature: Add a registration switch (`penalis_registration_enabled`), opt-in uninstall data removal settings, and expanded Simple Cloudflare Turnstile plugin status reporting under **Penalis → Pengaturan → Keamanan**.
* Security: Scope OTP advisory locks per account (`Penalis_Otp::lock_key()`) and standardize editorial notification dispatch through the `penalis_editorial_action` hook.
* Refactor: Enhance **Penalis → Review Karya Tulis** filter/query handling (`Penalis_Editorial_Admin`) and unify user-facing staff terminology around **Senator** across admin screens, emails, and the dashboard.

= 1.8.0 =
* Feature: Add a centralized **Penalis → Pengaturan** screen for administrators with `Keamanan`, `Foto Profil`, and `Email` tabs, replacing the standalone **Pengaturan Email** submenu; the retired URL redirects to the Email tab so bookmarks and old notices keep working.
* Feature: Add a dashboard-only CAPTCHA switch (`penalis_dashboard_captcha_enabled`) that silences the Turnstile widget, script, and server-side verification for `/dashboard/` forms without touching site-wide `wp-login.php` protection, with an audit trail of every change and a persistent warning while disabled.
* Feature: Restrict editorial notifications to writers and reviewers through a shared capability predicate, so pending and suspended accounts no longer see an always-empty bell or notification view.
* Feature: Add shared dashboard building blocks: password/confirmation fields, a hover-and-click disclosure controller, a Turnstile gate hook, and a reusable email request form.
* Refactor: Adopt the shared components across authentication, notification bell, account menu, and settings screens for consistent popover behavior and improved keyboard and screen-reader support.
* Fix: Bypass the Simple Cloudflare Turnstile `authenticate` check unconditionally around `wp_signon()` so dashboard login keeps working when the dashboard CAPTCHA switch is off.

= 1.7.0 =
* Feature: Add a dashboard notification center with a header bell, a full `Notifikasi` feed with all/unread filtering, bulk read receipt, optional per-article grouping, and a compact list mode, all driven by one shared controller.
* Feature: Deep-link notifications carrying revision notes to the editor feedback panel via `penalis_focus=feedback`.
* Feature: Allow reviewers (administrators and editors) without writer status to open article detail and review routes in `/dashboard/`.
* Feature: Add a change-address affordance to the OTP form and back-navigation logic in the authentication flow.
* Feature: Preserve plain-text pastes as hard line breaks in the TipTap editor with the `PasteHardBreak` extension.
* Security: Guard notification writes and read receipts with per-recipient MySQL advisory locks so concurrent submissions cannot drop an entry.
* Fix: Normalize dashboard asset URLs to the site origin, including off-site CDN URLs, non-standard ports, and http/https mismatches.
* Refactor: Split dashboard and authors CSS into shared design tokens and section stylesheets, with responsive navigation hints and media grid styling.

= 1.6.0 =
* Feature: Change TipTap editor `Enter` behavior so a single `Enter` inserts a line break (`<br>`) and a double `Enter` starts a new paragraph (`<p>`), removing the redundant line-break toolbar button for faster mobile writing.

= 1.5.0 =
* Feature: Integrate Cloudflare Turnstile protection across authentication and account request forms.
* Feature: Require thumbnail (featured image), non-uncategorized category, and at least one tag before submitting or resubmitting an article for review, while hiding Uncategorized from the editor category list.
* Feature: Add `Perlu revisi` stat card to the writer dashboard overview.
* Feature: Improve the dashboard account popover with email display, `Edit profil` and `Pengaturan akun` shortcuts, grouped dividers, and theme-safe styling.
* Refactor: Remove redundant input placeholders and refine form field hints across authentication and profile views, plus compact thumbnail action controls in the editor.

= 1.4.0 =
* Feature: Integrate Post Views Counter for articles and display view counts across the dashboard UI.
* Feature: Enhance the public authors directory (`[penalis_authors]`) with author views tracking and views-based sorting options.
* Feature: Enhance OTP input handling with new UI and improved verification UX.
* Refactor: Streamline OTP handling and validation logic.

= 1.3.0 =
* Feature: Add public authors directory shortcode (`[penalis_authors]`) with separate Vite bundle, search, status filter (`Semua` / `Verified`), sorting, URL query sync, and server-side pagination (`GET /penalis/v1/authors`, default 100 per page).
* Feature: Introduce verified active writer badge for active `penalis_writer` accounts across the public authors directory, dashboard overview & account menu, post author boxes, Elementor author profile headings, and `[penalis_verified_badge]` shortcode.
* Feature: Add Terms of Service and Privacy Policy links to the registration form.

= 1.2.0 =
* Feature: Implement timezone-aware date and time formatting via the new Penalis_Datetime class in PHP and enhanced frontend format utilities with site timezone context.
* Feature: Update dashboard shell account menu to display user avatar, full name, email, and handle/role.
* Feature: Redesign dashboard pagination component using icon buttons.
* Feature: Improve registration collision handling and send email notifications for existing accounts and legacy contributor activations.
* Refactor: Raise minimum PHP requirement from 7.4 to 8.2 across codebase, CI workflows, and runtime version guards.
* Refactor: Remove standalone resend verification code form and related deeplink handling from authentication flow.
* Maintenance: Upgrade TipTap editor dependencies to v3.33.1.

= 1.1.0 =
* Security: Restrict non-staff accounts (writers, contributors, subscribers) from accessing wp-admin and wp-login.php, automatically redirecting them to the frontend dashboard (/dashboard/).
* Fix: Prevent CORS blocking for dashboard ES modules by enforcing same-origin asset URLs and adding crossorigin attribute to module scripts and preloads.
* Performance: Implement code-splitting and lazy-loading with automated retry and preloading for the TipTap editor chunk, accelerating initial dashboard load time.
* Refactor: Rename password reset login parameter to penalis_user while maintaining backwards compatibility with legacy penalis_login URLs.

= 1.0.0 =
* Initial production release of Penalis Platform.
* Self-service writer account lifecycle: registration with optional Instagram
  username, six-digit email verification codes that immediately activate the
  writer account, administrator suspension/reactivation, legacy contributor
  activation, and bounded audit logging.
* Editorial submission and review workflow: draft submission, resubmission,
  serialized reviewer decisions (`Setujui dan terbitkan`, `Minta revisi`,
  `Tolak`, `Buka kembali`), review cycle locking, and read-only protection for
  published articles.
* Branded HTML transactional emails and compact, bounded in-dashboard editorial
  notifications.
* React writing dashboard (`/dashboard/`) with lazy-loaded TipTap WYSIWYG
  editor, raw HTML mode, media library, profile & avatar management, verified
  email change, and re-authenticated username change with 301 author redirects.
* Unified **Penalis** WordPress admin console (**Ringkasan**, **Review Karya Tulis**,
  and **Penulis Penalis**) with externalized admin assets (`assets/css/admin.css`,
  `assets/js/admin-review.js`) and confirmation dialogs for all reviewer and
  account actions.

== Upgrade Notice ==

= 2.5.0 =
Replaces five external plugins natively inside Penalis Platform: Default Featured Image (`Penalis_Default_Thumbnail`), One User Avatar backup protection (`Penalis_Avatar`), Posts Like Dislike (`Penalis_Reactions`), Post Views Counter with full Atlas theme compatibility (`Penalis_Views`), and LuckyWP Table of Contents with Rank Math SEO integration (`Penalis_Toc`). Also adds Total Likes to Profile Share Cards, standardizes Article Share Card dates to `dd/mm/yyyy`, refines the Senja card theme and mobile author stats layout, and upgrades the frontend build toolchain to Vite 8 / Vitest 5 / TipTap 3.35. Upgrade is non-destructive: built-in Views, Reactions, and TOC automatically defer while their external counterparts remain active, and legacy avatar and view counts migrate safely before you deactivate or delete the old plugins.

= 2.4.1 =
Fixes Share Card modal clipping behind mobile browser toolbars (iOS Safari and Brave) using dynamic viewport heights (`dvh`), safe-area insets, and a compact mobile bottom-sheet layout across all share surfaces.

= 2.4.0 =
Adds client-side Shareable Social Cards (Instagram Story 9:16 and Feed 1:1 PNGs in three themes with dynamic theme font inheritance) for writer profiles, #KalimatHariIni quotes, and published articles (including an auto-appended single-post share bar and the [penalis_share_article] shortcode), unifies all share triggers under a single Bagikan modal with in-modal link/quote copy, requires a moderation reason with dashboard-only writer notifications when Senators remove #KalimatHariIni quotes, and adds a Senator filter tab plus clickable public author profile links across review screens. Upgrade is non-destructive and requires no manual migration.

= 2.3.0 =
Adds #KalimatHariIni (24-hour writer sentences on author archives and the auto-created /kalimat-hari-ini/ page, with Senator moderation in Dashboard Senator and Penalis → Kalimat Hari Ini), upgrades the writer Media library with upload, delete, and strict usage protection, moves cropped profile photos to standalone 256x256 JPEG storage (keeping legacy avatars working without migration), and refreshes the Daftar Karya article list with counts and a card layout. Upgrading automatically creates the /kalimat-hari-ini/ page if it does not already exist; visit Settings → Permalinks and save once if needed.

= 2.2.0 =
Introduces the bundled public author archive template (`/author/<username>/`) with social-style profile cards and native Web Share, enforces a normalized 250-character bio cap (preserving legacy long bios behind a collapsible toggle on public profiles), and adds a Semua Kontributor link to the writer dashboard account menu. If you previously used an Elementor Theme Builder Author Archive template, remove its display condition or set it to Draft so the bundled template takes over cleanly.

= 2.1.0 =
Adds public Senator Penalis star badges (managed per writer or legacy contributor in **Penalis → Penulis Penalis**), a Senator filter chip and badge legend in the public authors directory, inline Senator badges on posts and author archives, and a visual highlight on the signed-in author's own directory card.

= 2.0.0 =
Introduces the dual-mode dashboard (Dashboard Penulis and Dashboard Senator) with an in-dashboard review queue and manuscript reader, plus overview analytics, published-article takedown recovery, email delivery logging, configurable rate limits, notification preferences, editor shortcuts, and automatic REST nonce recovery. Staff accounts now always open in Senator mode and cannot open writer views from the dashboard.

= 1.8.0 =
Consolidates site-wide settings into **Penalis → Pengaturan** (Keamanan, Foto Profil, Email) and adds a dashboard-only CAPTCHA switch that leaves site-wide Turnstile protection intact. The old Pengaturan Email URL redirects automatically.

= 1.7.0 =
Adds a dashboard notification center with a header bell, filtering, bulk read receipts, and deep links to reviewer feedback, plus shared CSS design tokens and reviewer access to article detail routes.

= 1.6.0 =
Changes the editor so a single Enter inserts a line break and a double Enter starts a new paragraph.

= 1.5.0 =
Integrates Cloudflare Turnstile on authentication forms, enforces complete article metadata (thumbnail, category, and tags) on review submission, adds the `Perlu revisi` overview card, and improves the account menu and form hints.

= 1.4.0 =
Adds Post Views Counter integration across the dashboard and public authors directory, views-based sorting options, and an enhanced OTP verification input UI.

= 1.3.0 =
Adds the public authors directory shortcode (`[penalis_authors]`), verified active writer badges across public profiles, post author boxes, and the dashboard, and Terms of Service / Privacy Policy links in registration.

= 1.2.0 =
Requires PHP 8.2+. Adds timezone-aware date formatting, improved user account menu and pagination in the dashboard, and enhanced registration collision handling.

= 1.1.0 =
Includes security hardening for wp-admin/wp-login access, dashboard performance improvements with editor lazy-loading, and module asset loading fixes.

= 1.0.0 =
Initial production release. Activation and upgrades are non-destructive.
