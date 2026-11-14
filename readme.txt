=== Penalis Platform ===
Contributors: penalis
Tags: writing, dashboard, editorial, publishing, community
Requires at least: 6.0
Requires PHP: 8.2
Stable tag: 1.4.0
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

No. Approved writers use the frontend dashboard at `/dashboard/`. Reviewers use
`wp-admin` (under the **Penalis** menu) to review article submissions:
administrators and editors can both curate articles, while only administrators
can manage writer accounts and site-wide settings.

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
