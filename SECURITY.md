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
| Client-supplied post status | **No** | Restricted to draft/publish |
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

The reset request accepts an email address only. Usernames are public on author
archives, so accepting one would let anyone turn a public profile into a reset
attempt against that account. A username sent in the email field is answered
with the same generic message and is never resolved to a user.

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

### Editorial review enforcement
Writers must never be able to publish directly or edit published articles.
New articles are submitted for review. Only an administrator approval action may
publish a writer submission. Request-changes and rejection require feedback; a
rejected submission cannot be resubmitted until an administrator reopens it.
Drafts, pending posts, feedback, and review history are visible only to the owner
and administrators. Administrators retain direct editing access to published
articles.

See `EDITORIAL-WORKFLOW-ROADMAP.md` for the phased implementation plan. Until a
phase there is marked complete, the corresponding behavior is not implemented.

Implemented in version 0.2.5 (Phase 3):

- A writer `status: publish` request through core REST is refused with
  `penalis_publish_forbidden`, and `wp_insert_post_data` refuses it again as a
  second layer.
- The legacy `/articles/{id}/publish` and `/articles/{id}/unpublish` routes stay
  registered but always answer `penalis_publish_forbidden` without touching the
  post.
- A writer update is refused unless `Penalis_Editorial_Workflow::can_writer_edit()`
  accepts it: own post, active write access, `draft` post status, and an
  editable editorial state. Published, under-review, and rejected articles are
  therefore closed to the writer.
- Stored editorial metadata is reconciled with `post_status`, so stale or
  hand-edited meta cannot make a live article editable.
- Single-post and single-media reads by ID enforce ownership; another writer's
  draft, pending submission, or media is refused, while published articles stay
  readable.
- Media may only be attached to the writer's own articles, and the check runs
  before the file is written.
- `Penalis_Editorial_Workflow::apply()` re-checks account status and ownership,
  so a direct service call cannot bypass the REST layer.

Implemented in versions 0.2.6–0.2.7 (Phases 4–5):

- Reviewer decisions in **Posts → Tinjauan Penalis** are serialized per post
  with a MySQL advisory lock and the post is re-read inside it, so two
  administrators cannot both decide the same round of review.
- `POST /penalis/v1/articles/{id}/submit` and `/resubmit` are allow-listed on
  `POST` for active writers, require ownership (`get_owned_article` returns 404
  for any other user's article), and require a non-empty title and content
  before transitioning.
- `GET /penalis/v1/articles/{id}` and `GET /penalis/v1/articles/{id}/review`
  use `get_readable_article()`, which permits only the article owner and
  reviewer administrators (`Penalis_Editorial_Workflow::can_review()`); any
  other writer receives `404 penalis_not_found`.

### Layered enforcement

1. **Account status** — `Penalis_Account_Status::can_write()` requires
   `active` **and** the explicit access flag.
2. **Capability filter** — `user_has_cap` removes write capabilities from
   accounts that are not active writers, including dormant legacy contributors.
   Legacy accounts explicitly enabled by an administrator keep native behavior.
3. **Meta capability mapping** — `map_meta_cap` adds `do_not_allow` for
   `edit_post`, `delete_post`, and `publish_post` when the post is not owned or
   the account cannot write, and for attachments the writer does not own.
4. **REST dispatch guard** — blocks administrative routes, non-allowlisted write
   routes, cross-writer single-item reads, and any write by inactive accounts.
5. **Post insert filter** — forces the author, refuses `publish`, refuses edits
   to locked editorial states, blocks privileged fields, and verifies featured
   media ownership.
6. **Query filters** — forces post and media list queries to the current author
   for restricted users.
7. **Upload validation** — restricts MIME types, verifies image contents, and
   keeps attachments on the writer's own articles.
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
  outside a verified flow. The only sanctioned path is
  `Penalis_Access_Control::apply_verified_email_change()`, which is called after
  the new address has been confirmed by its owner.

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

### Email address change

Changing the address on file is a credential change, so it uses its own token
world and never touches the account status machine.

| Property | Implementation |
| --- | --- |
| Request | `POST /penalis/v1/account/email`, signed in, current password required |
| Candidate address | Stored in `_penalis_email_change_new_email`, never taken from the confirmation request |
| Token | 32 random bytes, URL-safe base64, stored only as a SHA-256 hash |
| Expiry | 24 hours, single use |
| Delivery | Confirmation link goes to the **new** address only |
| Apply | `POST /penalis/v1/account/email/confirm`, public, token is the proof |
| Re-check | Address availability is re-checked at confirmation time |
| Notice | The previous address receives a security notice after the change |
| Status machine | Untouched: an email change never advances or resets account status |

`pending_verification` accounts cannot change their address; they use the
verification resend flow instead. `rejected` and `suspended` accounts are
denied, so a credential change cannot restore revoked access.

### Username changes

Changing the login is a credential change, so it re-authenticates and never
touches the account status machine.

| Property | Implementation |
| --- | --- |
| Route | `POST /penalis/v1/account/username`, signed in, nonce-authenticated |
| Scope | Self only: the user ID comes from the session, never the request |
| Re-authentication | Current password required |
| Eligibility | Same rule as email changes, plus the administrator exemption |
| Validation | 3–50 lowercase ASCII letters, digits, `_`, `-`; starts and ends with a letter or digit; at least one letter or digit; no dots |
| Normalization | Rejected, never applied: a value that only becomes valid after `sanitize_user()` is refused |
| Duplicates | Checked inside a users-table advisory lock; duplicate, reserved and lock-failure errors are identical |
| Write | Direct `$wpdb->update()` on `user_login` and `user_nicename`, because `wp_update_user()` ignores the login field |
| Preserved | User ID; the previous slug is stored in `_penalis_author_history` and 301-redirected, so author archives and post links keep working |
| Display name | Updated only when the public name follows the username |
| Caches | `clean_user_cache()` plus old and new `userlogins` and `userslugs` keys |
| Sessions | All session tokens destroyed; the current auth cookie cleared |
| Notice | Security email to the current address; a delivery failure is recorded, not rolled back |

WordPress has no unique index on `user_login`, so the advisory lock only
serializes this endpoint. Native registration, other plugins and direct SQL do
not take it and can still race; this is not a database-wide uniqueness
guarantee. The endpoint fails closed when the database cannot provide the lock.

## Abuse controls

Transient-based limits:

| Action | Limit |
| --- | --- |
| Registration per IP | 5 per hour |
| Verification attempts per IP | 20 per hour |
| Resend per IP | 5 per hour |
| Password change per user | 5 per hour |
| Email change per IP | 5 per hour |
| Email change per user | 3 per hour |
| Email change confirmation per IP | 10 per hour |
| Username change per IP | 5 per hour |
| Username change per user | 3 per hour |
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
