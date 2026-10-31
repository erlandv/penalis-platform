=== Penalis Platform ===
Contributors: penalis
Tags: writing, dashboard, registration, publishing, community
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A controlled user-generated content workflow and React writing dashboard for Penalis.

== Description ==

Penalis Platform adds a modern frontend writing dashboard and a controlled
account lifecycle to a WordPress site. It is designed for communities where
new writers must be approved by an administrator before they can publish.

Note: "Requires at least" and "Requires PHP" describe the minimum versions
supported by the APIs and syntax this plugin uses. They are derived from the
implementation and repository checks, not from live testing. A "Tested up to"
value is intentionally not claimed until the plugin has been verified against
a running WordPress installation; see PROJECT-SPECIFICATION.md for the manual
integration test checklist.

Key features:

* **Account workflow.** Visitors register through the dashboard, confirm their
  email address with an expiring single-use link, and are then reviewed by an
  administrator. Approval grants writing access; rejection and suspension
  remove it. Every transition is audited.
* **React writing dashboard.** A responsive single-page application with an
  overview, a paginated article list, an article editor (title, content,
  categories, tags, featured image, image upload and insertion, preview), a
  media library, a profile screen, and account settings.
* **Native WordPress integration.** Authentication uses WordPress cookies and
  the REST API nonce. Articles are stored as standard posts, media uses the
  WordPress Media Library, and no custom database tables are created.
* **Legacy account safety.** Existing contributor profiles are treated as
  author profiles: they are never promoted, never given the writer role, and
  cannot sign in, reset passwords, or write until an administrator explicitly
  enables access for the account.
* **Server-side authorization.** Publishing requires an active writer status
  *and* explicit access. Authors are always derived from the authenticated
  session, article ownership is enforced on every request, and privileged
  REST routes are blocked for writers.

This version targets WordPress 6.0 and PHP 7.4 as the minimums supported by
the APIs and syntax used. Compatibility has been verified through repository
checks and unit tests; it has **not yet** been tested against a live WordPress
installation, a specific theme, or Elementor. See the included
PROJECT-SPECIFICATION.md for the manual integration test checklist.

**Editor scope.** The dashboard editor is a WYSIWYG surface built on TipTap, not
a Gutenberg block editor. It covers text formatting, headings, lists, quotes,
links, and images, and stores standard WordPress post content, so articles
render with the existing theme like any other post. A raw HTML toggle is
available for markup the toolbar cannot express.

**Email delivery.** Verification and notification emails are sent with
`wp_mail()`. Delivery depends on the host's mail configuration; an SMTP plugin
and a test send are strongly recommended. Delivery failures are recorded and
never silently advance an account's verification state.

= Why does registration create a subscriber? =

New accounts are created as subscribers with status "pending verification".
They cannot write at all until an administrator approves them, at which point
the Penalis Writer role is granted. This keeps publishing capability strictly
tied to the approval workflow rather than to the act of registering.

= Are existing contributor accounts affected? =

No. Existing contributor profiles keep their role, password, posts, author
relationships, and metadata. They are not promoted to the writer role and
cannot sign in, use password reset to gain access, or write while access is
disabled. An administrator may explicitly enable access for a single legacy
account, which restores that account's native WordPress behavior.

= Why does the plugin use a button to confirm email addresses? =

Verification links include a single-use token. Confirming requires an explicit
click on the dashboard, so automated email link scanners cannot consume the
token before the real user uses it.

= Does the plugin replace the public website or theme? =

No. It runs entirely on its own page (a plugin-created Writing Dashboard page)
and the REST API. The existing site, theme, Elementor templates, and content
are untouched.

= Where do writers manage articles? =

Writers use the frontend writing dashboard, not the WordPress admin. The
dashboard loads only on its own page, so no assets are served site-wide.
Administrators manage applications under **Users → Penalis Writers**.

= What file types can writers upload? =

Only JPEG, PNG, GIF, and WebP images. Files are validated by MIME type and by
inspecting the file contents, so renamed executables and SVG files are
rejected.

== Installation ==

1. From the repository: run `npm ci && npm run build`, then confirm the
   `build/` folder exists. The production plugin does not require Node.js.
2. Copy or symlink the plugin directory to `wp-content/plugins/penalis-platform/`.
3. Activate **Penalis Platform** under **Plugins**.
4. Save **Settings → Permalinks** once if the dashboard page or REST routes
   return 404.
5. Visit **Users → Penalis Writers** to confirm the review screen loads.

Activation is non-destructive: it registers the writer role, stores the plugin
version, and creates the Writing Dashboard page when one does not already
exist. It never deletes or migrates users, posts, comments, media, or terms.

Deactivation only clears short-lived transients. Uninstall preserves all data
unless the site owner explicitly opts in via the `PENALIS_PLATFORM_REMOVE_DATA`
constant or the `penalis_remove_data_on_uninstall` option.

Recommended configuration:

* Configure a working outgoing mail setup (SMTP plugin) and send a test email.
* Exclude `/penalis-dashboard/` and `/wp-json/penalis/*` from full-page and CDN
  caches. The plugin sends private, no-store headers; host caches must honor
  them.
* Confirm the dashboard page slug does not conflict with an existing page. If
  it does, the plugin uses a suffixed slug and stores the page ID.

== Frequently Asked Questions ==

= Do writers need a separate server or database? =

No. The plugin runs inside WordPress and uses WordPress users, roles, posts,
media, metadata, and the REST API.

= Can a suspended writer keep publishing through direct API requests? =

No. Suspension immediately revokes write capability server-side, removes the
writer role, and destroys the account's sessions. Every protected operation
checks account status and ownership on the server.

= Can writers assign their articles to other authors? =

No. The author is always derived from the authenticated session. Client-supplied
author IDs are rejected unless they match the signed-in user.

= Is there a REST API endpoint for approval? =

No. Approvals go through the nonce-protected WordPress admin screen at
**Users → Penalis Writers**, which only users with `list_users` can access.

= How are rate limits applied? =

Transient-based limits cover registration, verification, resend, password
changes, and tag creation per IP or per account. Host-level protection is still
recommended.

== Screenshots ==

Screenshots are not provided yet.

== Changelog ==

= 0.2.0 =
* Dashboard page moved from `/penalis-dashboard/` to `/dashboard/`; existing
  plugin-managed pages are renamed on upgrade and the old URL redirects.
* Writer profile: first and last name with a public name choice, and profile
  photo management.
* Avatars are served by this plugin when One User Avatar is inactive, with a
  default photo setting under Users -> Penalis Writers.
* Taxonomy names containing HTML entities are decoded before display.
* Action rows keep their alignment when the active theme styles buttons.

= 0.1.0 =
* Initial release.
* Account lifecycle: registration, email verification, administrator approval,
  rejection, suspension, reactivation, and reopen-for-review.
* React writing dashboard with articles, editor, media, profile, and settings.
* Penalis Writer role with minimal capabilities.
* Server-side authorization for ownership, status, author assignment, and
  privileged REST routes.
* Legacy contributor login and password-reset protection.
* Repository-contained PHP and frontend tests, coding standards checks, and a
  production build pipeline.

== Upgrade Notice ==

= 0.2.0 =
Renames the plugin-managed dashboard page to `/dashboard/`. No content or user
data is migrated; the previous URL redirects to the new one.

= 0.1.0 =
Initial release; no upgrade path required.