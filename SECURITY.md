# Security Policy and Model

This document describes the security boundaries and authorization model of the
Penalis Platform plugin.

## Reporting a vulnerability

Report security issues privately to the site owner or maintainer of this
repository. Do not open a public issue for an unpatched vulnerability.

## Scope and trust boundaries

| Boundary | Trusted? | Notes |
| --- | --- | --- |
| WordPress core authentication | Yes | The plugin does not implement its own login |
| WordPress capability system | Yes, with added checks | Explicit status checks layer on top |
| Browser client | **No** | All authorization is enforced server-side |
| Client-supplied author ID | **No** | Always overwritten with the session user |
| Client-supplied post status | **No** | Restricted to draft/pending/publish |
| Client-supplied file type | **No** | Validated by MIME and image inspection |
| Email link query parameters | **No** | Token format, hash, expiry, and state checked |
| Proxy headers | **No** by default | Opt-in only via filter |

## Authentication

- Native WordPress cookie authentication.
- REST requests use the `wp_rest` nonce in the `X-WP-Nonce` header.
- No JWT, no parallel credential store, no token in `localStorage`.
- The dashboard bootstrap payload contains a nonce and public account fields
  only. It never contains password hashes, verification tokens, or secrets.

### Login gate

`Penalis_Access_Control::block_login()` runs on the `authenticate` filter:

| Account state | Can sign in? |
| --- | --- |
| Administrator | Yes (never restricted) |
| `pending_verification` | Yes, to view status |
| `pending_approval` | Yes, to view status |
| `active` with access flag | Yes |
| `active` without access flag | No |
| `rejected` | No |
| `suspended` | No |
| Legacy contributor, no access flag | No |
| Legacy contributor, access flag enabled | Yes, but no dashboard write access |
| `penalis_writer` role with no plugin metadata | No |

Login errors are normalized to a generic message so account state is not
disclosed.

### Password reset

`allow_password_reset` is filtered:

- Administrators are unaffected.
- Plugin-managed accounts may reset only if they are otherwise allowed to sign
  in.
- Legacy contributors may reset only if an administrator explicitly enabled
  access.

This closes the "reset the password to gain access" path for dormant legacy
profiles.

## Authorization

### Roles and capabilities

`penalis_writer` has:

```
read, upload_files, edit_posts, publish_posts,
edit_published_posts, delete_posts, delete_published_posts
```

It explicitly does **not** have:

```
edit_others_posts, delete_others_posts,
edit_private_posts, delete_private_posts, read_private_posts,
manage_categories, moderate_comments, manage_options,
list_users, promote_users, edit_users, delete_users, create_users,
install_plugins, activate_plugins, edit_plugins,
install_themes, switch_themes, edit_themes,
unfiltered_html, edit_files, import, export
```

### Layered enforcement

1. **Account status** — `Penalis_Account_Status::can_write()` requires
   `active` **and** the explicit access flag.
2. **Capability filter** — `user_has_cap` removes write capabilities from
   accounts that are not active writers, including dormant legacy contributors.
   Legacy accounts explicitly enabled by an administrator keep native behavior.
3. **Meta capability mapping** — `map_meta_cap` adds `do_not_allow` for
   `edit_post`, `delete_post`, and `publish_post` when the post is not owned or
   the account cannot write.
4. **REST dispatch guard** — blocks administrative routes, non-allowlisted write
   routes, and any write by inactive accounts.
5. **Post insert filter** — forces the author, validates status, blocks
   privileged fields, and verifies featured media ownership.
6. **Query filters** — forces post and media list queries to the current author
   for restricted users.
7. **Upload validation** — restricts MIME types and verifies image contents.
8. **UI restrictions** — the React app disables actions, but this is a
   convenience, not a security control.

### REST route hardening

For restricted users:

Blocked entirely:

```
/wp/v2/users, /wp/v2/settings, /wp/v2/plugins, /wp/v2/themes,
/wp/v2/templates, /wp/v2/template-parts, /wp/v2/global-styles,
/wp/v2/navigation, /wp/v2/menus, /wp/v2/menu-items, /wp/v2/menu-locations,
/wp/v2/widgets, /wp/v2/sidebars, /wp/v2/pattern-directory,
/wp/v2/font-families, /wp/v2/font-faces, /wp/v2/pages
```

Two read-only exceptions are allowed for compatibility with editor tooling:

```
GET /wp/v2/users/me      (own record only)
GET /wp/v2/block-types   (public block metadata)
```

Write methods blocked except for:

```
/wp/v2/posts, /wp/v2/media, /penalis/v1/
```

Pending accounts may additionally call the account password, profile, and
verification routes so they can manage their own credentials and confirm their
email. Those handlers accept only safe, self-scoped fields.

Comment creation and moderation are denied.

### Ownership and IDOR

- `Penalis_Rest_Api::get_owned_article()` returns **404** (not 403) for another
  writer's article, avoiding disclosure of its existence.
- Post insert rejects updates where `post_author` is not the current user.
- Native post queries are forced to `author=<current user>`.
- Media queries are forced to `author=<current user>`.
- Featured images must be attachments owned by the current user.

### Privilege escalation

- Client-supplied `author` is rejected unless it matches the session user.
- `sticky`, `password`, `meta`, and `template` fields are rejected for writers.
- Approval refuses accounts that already hold a role other than `subscriber`,
  `contributor` (legacy accounts), or `penalis_writer`.
- `set_user_role` reverts administrator promotion attempts by non-administrators.
- Profile updates restore the email address if a writer attempts to change it
  outside a verified flow.

## CSRF

- All authenticated state-changing REST requests require the `wp_rest` nonce.
- Admin approval actions use `check_admin_referer()` and `wp_nonce_field()`.
- Anonymous registration cannot use a session nonce; it relies on strict
  validation, a honeypot, and IP rate limiting. It performs no privileged
  action beyond creating a pending account.

## Verification tokens

| Property | Implementation |
| --- | --- |
| Entropy | 32 bytes from `random_bytes()` |
| Encoding | URL-safe base64 |
| Storage | SHA-256 hash in user meta |
| Expiry | 24 hours |
| Reuse | Token cleared on successful verification; reuse rejected |
| Resend throttle | 10 minutes per account, 5 per hour per IP |
| Link scanning | Token is consumed only after an explicit button click |
| Logging | Tokens are never written to logs or API responses |

Expired tokens are cleared and reported as expired. Invalid tokens return a
generic message.

## Abuse controls

Transient-based limits:

| Action | Limit |
| --- | --- |
| Registration per IP | 5 per hour |
| Verification attempts per IP | 20 per hour |
| Resend per IP | 5 per hour |
| Password change per user | 5 per hour |
| Tag creation per user | 30 per hour |

Limitations: transients are not distributed, and object-cache eviction resets
counters. Host-level protection (WAF, fail2ban) is recommended.

## Data exposure

- REST responses are limited to the current user's own data.
- Draft and pending posts are never returned to other users.
- Error messages avoid confirming whether an account exists.
- Registration duplicates return a generic failure.
- Verification resend always returns the same message.
- No sensitive values are printed to logs.
- Dashboard and REST responses send `Cache-Control: no-store, private` and
  `X-Robots-Tag: noindex`.

## Uploads

Writers may upload only:

- `image/jpeg`
- `image/png`
- `image/gif`
- `image/webp`

Files are additionally validated with `getimagesize()` so a renamed non-image
file is rejected. SVG is intentionally not allowed because it can carry script.

## Account suspension

Suspension:

- Sets status `suspended` and access flag `0`.
- Removes the writer role (downgrades to subscriber).
- Destroys all session tokens for the user.
- Denies all write routes and capability checks.
- Preserves the user's posts and author attribution.

## Non-destructive behavior

- Activation does not migrate, delete, or reassign data.
- Deactivation only clears transients.
- Uninstall preserves data unless the site owner opts in.
- Existing posts, comments, media, terms, and author relationships are not
  modified.

## Operational recommendations

1. Serve the site over HTTPS.
2. Configure SMTP and monitor email failures.
3. Exclude authenticated pages and `/wp-json/penalis/*` from caches.
4. Keep WordPress, PHP, and the plugin updated.
5. Back up the database before plugin updates, particularly user meta.
6. Restrict administrator accounts and use strong passwords/2FA.
7. Review the audit log for status changes.
8. Do not enable access for legacy contributors without a verified ownership
   process.

## Out of scope

- Multisite-specific behavior.
- Claiming legacy profiles.
- Custom CAPTCHA integration.
- Distributed rate limiting.
- Auditing the existing theme, Elementor templates, or host configuration.
