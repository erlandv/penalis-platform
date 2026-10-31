# Penalis Platform

A WordPress plugin that adds a controlled writer-account workflow and a React
writing dashboard to the Penalis literary community.

The plugin is self-contained: it adds no separate backend, no second user
database, and no parallel authentication system. It uses WordPress users, roles,
capabilities, posts, media, and the WordPress REST API.

This plugin was originally developed for internal use at [Penalis](https://penalis.com). It is publicly available so others can use it in their own projects if they find it useful.

## Contents

- [What it does](#what-it-does)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Repository setup](#repository-setup)
- [Build commands](#build-commands)
- [Plugin installation](#plugin-installation)
- [Site configuration](#site-configuration)
- [Development notes](#development-notes)
- [Testing](#testing)
- [Documentation](#documentation)
- [Known limitations](#known-limitations)

## What it does

### Account workflow

1. A visitor registers through the dashboard page.
2. The account is created as `subscriber` with status `pending_verification`
   and no publishing capability.
3. The visitor receives an expiring, single-use verification email.
4. After confirming, the account moves to `pending_approval`.
5. An administrator approves or rejects the application under
   **Users → Penalis Writers**.
6. Approved accounts receive the `penalis_writer` role and status `active`.
7. Administrators can suspend an active writer. Suspension revokes publishing
   ability immediately and ends existing sessions.

### Dashboard

A React single-page dashboard at the plugin-managed `/dashboard/` page:

- Overview with account status and article counts.
- Paginated list of the writer's own articles.
- Article editor with a WYSIWYG surface (TipTap), title, excerpt, categories,
  tags, featured image, and image upload/insertion.
- Preview and publish/unpublish actions.
- Writer profile with first name, last name, and a public name choice: first +
  last, first only, last only, username, or a custom pen name.
- Profile photo selection from the writer's own media library. Avatars are
  served by the [One User Avatar](https://wordpress.org/plugins/one-user-avatar/)
  plugin while it is active, and by this plugin once that plugin is deactivated.
- A default profile photo for accounts without one, managed under
  **Users → Penalis Writers**.
- A **View public profile** link to the writer's author archive.
- Account settings including password change.
- Media library view.

The dashboard is only loaded on its own page. It is not enqueued site-wide.

### Password reset

Writers who forget their password stay inside the dashboard:

1. **Lupa kata sandi?** on the login screen opens a reset request form.
2. The request always answers generically, so it cannot be used to discover
   which addresses are registered.
3. The email links back to `/dashboard/` with a single-use key.
4. The key is consumed only after the new password is submitted, so email link
   scanners cannot burn it.
5. Every existing session is destroyed after a successful reset.

`wp-login.php?action=lostpassword` still works as a fallback.

### Legacy contributor activation

Contributor accounts created before this plugin existed have no password, so
their owners cannot sign in. They can activate the account they already have:

1. **Aktifkan akun penulis lama** on the login screen, or
   `/dashboard/?penalis_activate=1`.
2. Enter the email used when submitting work. The response is always generic.
3. The emailed link opens a form to set the first password.
4. The account moves to `pending_approval`; an administrator still approves it.

Administrators can also act from **Users → Penalis Writers → Akun lama**:

- **Kirim tautan aktivasi** emails the same link.
- **Aktifkan langsung** skips the email step, records the decision in the audit
  log, and sends a password reset link.

The user ID never changes, so existing articles and author archives stay
attached. Posts are never modified.

### Legacy contributor protection

Existing contributor accounts are treated as author profiles, not interactive
accounts:

- They are not promoted to `penalis_writer`.
- They are not given plugin access metadata.
- They cannot sign in while access remains disabled.
- Password reset for them is blocked while access remains disabled.
- Their posts, author relationships, and metadata are untouched.

## Architecture

```
penalis-platform/
├── penalis-platform.php        Plugin bootstrap and autoloader
├── uninstall.php               Opt-in, non-destructive-by-default uninstall
├── includes/
│   ├── class-plugin.php        Component container and upgrade checks
│   ├── class-activator.php     Non-destructive activation
│   ├── class-deactivator.php   Transient cleanup only
│   ├── class-policy.php        Pure, testable policy rules
│   ├── class-account-status.php Status persistence and transitions
│   ├── class-roles.php         Writer role and capabilities
│   ├── class-access-control.php Login, capability, and REST hardening
│   ├── class-registration.php  Registration, verification, resend
│   ├── class-password-reset.php Dashboard password reset (forgot/reset)
│   ├── class-legacy-activation.php Legacy contributor activation
│   ├── class-rest-api.php      penalis/v1 endpoints
│   ├── class-dashboard.php     Page, shortcode, asset enqueue
│   ├── class-emails.php        wp_mail() templates and failure recording
│   ├── class-rate-limiter.php  Transient-based abuse controls
│   └── class-admin.php         Users → Penalis Writers screen
├── templates/
│   └── dashboard.php           Minimal theme-independent wrapper
├── src/
│   ├── app/                    React entry point and root
│   ├── components/             Shared UI primitives
│   ├── features/               Auth, overview, articles, editor, profile, settings, media
│   ├── lib/                    API client, types, validation, formatting
│   └── styles/                 Scoped dashboard CSS
├── build/                      Generated production assets and manifest
├── tests/
│   ├── run-tests.php           PHP policy and status tests
│   └── wp-stubs.php            Minimal WordPress stubs for tests
├── package.json
├── composer.json
├── phpcs.xml.dist
├── README.md
├── SECURITY.md
└── PROJECT-SPECIFICATION.md
```

### Data storage

| Data | Storage |
| --- | --- |
| Account status | User meta `_penalis_account_status` |
| Access flag | User meta `_penalis_access_enabled` |
| Verification token hash | User meta `_penalis_verification_token_hash` |
| Token expiry | User meta `_penalis_verification_token_expires` |
| Audit trail | User meta `_penalis_status_log` (bounded) |
| Public name preference | User meta `_penalis_display_name_mode` |
| Profile photo | User meta `{prefix}user_avatar` (owned by One User Avatar) |
| Dashboard page ID | Option `penalis_dashboard_page_id` |
| Settings | Options `penalis_registration_enabled`, `penalis_remove_data_on_uninstall` |

No custom database tables are created.

## Requirements

| Component | Minimum | Notes |
| --- | --- | --- |
| PHP | 7.4 | The plugin avoids PHP 8-only syntax. |
| WordPress | 6.0 | Uses the REST API, `wp_robots`, and modern user APIs. |
| Node.js | 20 | Build-time only; not required in production. |
| npm | 9+ | Build-time only. |
| Composer | 2.x | Development tooling only; not required in production. |

These are implementation-derived minimums. They have **not** been verified
against a running WordPress installation in this repository.

## Repository setup

```bash
# Frontend dependencies and lockfile
npm ci

# Development-only PHP tooling (PHPCS)
composer install
```

`node_modules/` and `vendor/` are ignored by Git. `package-lock.json` and
`composer.lock` are committed.

## Build commands

```bash
npm run dev        # Vite dev server (does not run inside WordPress)
npm run typecheck  # TypeScript type checking
npm run lint       # ESLint
npm test           # Vitest unit tests
npm run build      # Type-check and build production assets into build/
npm run check      # Lint, typecheck, test, build
```

The production build writes:

```
build/manifest.json
build/assets/index-<hash>.js
build/assets/index-<hash>.css
```

The plugin reads `build/manifest.json` and enqueues the hashed entry assets.
No Node.js runtime is needed on the server.

PHP checks:

```bash
php tests/run-tests.php     # Policy and status workflow tests
composer lint               # WordPress Coding Standards
composer lint:fix           # Auto-fix fixable formatting
composer check              # Lint and test
```

## Plugin installation

1. Run `npm ci && npm run build` in the repository.
2. Copy or symlink the repository directory into
   `wp-content/plugins/penalis-platform/`.
   Ensure `build/` is present.
3. Activate **Penalis Platform** in **Plugins**.
4. Visit **Settings → Permalinks** and save once if REST URLs or the dashboard
   page return 404.
5. Visit **Users → Penalis Writers** to confirm the admin screen loads.

Activation:

- Registers the `penalis_writer` role.
- Creates a **Writing Dashboard** page with `[penalis_dashboard]` if no
  plugin-managed dashboard page exists.
- Stores the plugin version.
- Does **not** modify existing users, posts, comments, media, or terms.

## Site configuration

### Required

| Setting | Where | Why |
| --- | --- | --- |
| Pretty permalinks | Settings → Permalinks | Clean REST routes and dashboard URL |
| Working email delivery | Host or SMTP plugin | Verification and approval emails |
| Cache exclusion for logged-in users | Host/CDN configuration | Prevent private dashboard caching |

### Recommended

- Configure an SMTP plugin and send a test email. The plugin records delivery
  failures in user meta and does not advance verification state when sending
  fails.
- Exclude `/dashboard/` and `/wp-json/penalis/*` from full-page and CDN
  caches. The plugin sends `no-store`/`private` headers, but host caches must
  also honor them.
- Confirm the dashboard page slug does not conflict with an existing page. If it
  does, the plugin creates a suffixed slug and stores the page ID.

### Optional filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `penalis_trust_proxy_headers` | `false` | Trust `X-Forwarded-For` behind a known proxy |
| `penalis_use_dashboard_template` | `true` | Disable the bundled dashboard template |

### Optional constants

| Constant | Default | Purpose |
| --- | --- | --- |
| `PENALIS_PLATFORM_REMOVE_DATA` | not defined | Set to `true` to allow opt-in data removal on uninstall |

## Development notes

### Editor scope

Articles are written in a **TipTap** editor: a headless ProseMirror wrapper that
renders a WYSIWYG surface and returns plain HTML. That HTML is exactly what
`post_content` already stores, so existing articles load unchanged and the
public theme renders them like any other post.

The toolbar covers bold, italic, underline, strike, H2/H3, bullet and ordered
lists, blockquotes, links, images, undo/redo, and a raw HTML toggle. Images are
inserted at the caret from the media panel. The HTML toggle is an escape hatch
for markup the toolbar cannot express.

TipTap is loaded lazily, so it only downloads when a writer opens the editor.
The main bundle stays small for everyone else.

This is not a block editor: there are no layout blocks, embeds, or reusable
patterns. Gutenberg was rejected because running it outside wp-admin requires
the `@wordpress/*` toolchain and an editor settings endpoint, and writers lack
`unfiltered_html`, which would make several blocks inert.

Server-side `wp_kses` remains the only authority on allowed markup.

### Why Vite

Vite produces a small React bundle and a stable manifest. WordPress integration
is explicit in `Penalis_Dashboard::maybe_enqueue_assets()`.

### Author assignment

Article creation and updates go through the native `/wp/v2/posts` endpoint.
`Penalis_Access_Control::enforce_post_insert()` forces `post_author` to the
authenticated user and rejects client attempts to change it.

## Testing

Repository-contained checks:

```bash
php tests/run-tests.php
composer lint
npm run check
```

Manual integration testing requires a real WordPress installation. See the
checklist in [PROJECT-SPECIFICATION.md](PROJECT-SPECIFICATION.md#13-manual-test-checklist).

## Documentation

- [PROJECT-SPECIFICATION.md](PROJECT-SPECIFICATION.md) — features, lifecycle,
  permission rules, status, assumptions, manual test checklist.
- [SECURITY.md](SECURITY.md) — security boundaries and authorization model.

## Known limitations

- Profile photos have no crop UI; the uploaded image is used as-is. One User
  Avatar remains the avatar provider while it is active, so this plugin's own
  provider only takes over after that plugin is deactivated.
- The dashboard editor is a WYSIWYG surface, not a block editor.
- Email delivery depends on host configuration; no SMTP library is bundled.
- Rate limiting uses transients, not a distributed store.
- Multisite behavior is not specifically implemented or tested.
- Legacy profile claiming is out of scope.
- Integration with the live WordPress installation, theme, and Elementor has
  not been verified from this repository.
