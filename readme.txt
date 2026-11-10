=== Penalis Platform ===
Contributors: penalis
Tags: writing, dashboard, editorial, publishing, community
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Curated publishing workflow and React writing dashboard for the Penalis literary community on WordPress.

== Description ==

Penalis Platform adds a self-service writer account lifecycle, an editorial
submission and review workflow, and a frontend React writing dashboard
(`/dashboard/`) to WordPress. It is built for the Penalis literary community
(penalis.com), where member-submitted essays, prose, and poetry are curated by
administrators before publication.

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
  directly and cannot edit published articles. Administrators review submissions
  in a 2-column workspace under **Penalis → Review Karya Tulis**, where they can
  approve and publish, request revisions with required feedback, reject with
  required feedback, or reopen a rejected submission.
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
  **Review Karya Tulis**, and **Penulis Penalis** under one top-level menu with
  pending notification badges and confirmation dialogs on all administrative
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

No. Approved writers use the frontend dashboard at `/dashboard/`. Only
administrators use `wp-admin` (under the **Penalis** menu) to review article
submissions and manage writer accounts.

= Can a writer publish an article directly or edit an already published article? =

No. Writers can only save drafts and submit or resubmit them for review. Only an
administrator approval action under **Penalis → Review Karya Tulis** can publish an
article, and published articles are strictly read-only for writers both in the
dashboard UI and at the REST API level.

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

= 1.0.0 =
* Initial production release of Penalis Platform.
* Self-service writer account lifecycle: registration with optional Instagram
  username, six-digit email verification codes that immediately activate the
  writer account, administrator suspension/reactivation, legacy contributor
  activation, and bounded audit logging.
* Editorial submission and review workflow: draft submission, resubmission,
  serialized administrator review decisions (`Setujui dan terbitkan`,
  `Minta revisi`, `Tolak`, `Buka kembali`), review cycle locking, and read-only
  protection for published articles.
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

= 1.0.0 =
Initial production release. Activation and upgrades are non-destructive.
