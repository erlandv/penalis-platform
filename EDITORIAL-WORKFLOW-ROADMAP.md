# Penalis Editorial Workflow V2 Roadmap

Status: planning only — no implementation from this roadmap has started.
Last updated: 2026-11-05 (published articles are writer-read-only).

This document tracks the migration from direct writer publishing to admin review and curation. It is the implementation checklist for the V2 editorial workflow. Account registration, verification, legacy activation, login, profile, media, and dashboard authentication remain unchanged unless explicitly noted below.

## Target workflow

### New article

```text
draft → pending_review → published
                  ├── changes_requested → pending_review
                  └── rejected → (admin reopen) → changes_requested/draft
```

### Published articles

Writers cannot edit published articles. This applies to newly published articles and older articles owned by the writer. Administrators may still edit published articles directly in wp-admin.

WordPress post statuses remain `draft`, `pending`, and `publish`. Penalis editorial state and review metadata are stored separately in post meta. The server is authoritative for every transition.

### What this decision removed

Because writers cannot edit published articles, V2 no longer needs a pending-revision model. There is no `_penalis_pending_revision_id`, no revision promotion step, and no “old version stays public while the new one is reviewed” state. A submission is always a single post moving through `draft` → `pending` → `publish`, which keeps the state machine, the REST surface, and the admin review screen smaller.

## Editorial rules agreed with Penalis

- Writers cannot publish directly.
- New articles must be reviewed before their first publication.
- Writers cannot edit published articles, whether the article was recently approved or published before V2.
- Administrators may edit published articles directly; those administrator edits are not subject to the writer submission workflow.
- A rejected submission cannot be resubmitted unless an administrator reopens it.
- Administrators may edit posts directly, including small corrections before approval (tags, categories, featured image, and content).
- Multiple administrators may review the same article; every decision records the reviewer and timestamp.
- Feedback is general text, not inline paragraph annotations.
- Feedback is required for “request changes” and “reject”, but not for approval.
- Email notifications and dashboard notifications are both required.
- Pending or private content is visible only to its owner and administrators; other writers and anonymous visitors must never receive it.
- Admin approval is the only workflow path that may publish a writer submission.

## Data model to implement

### Editorial state

Recommended values for `_penalis_editorial_state`:

- `draft`
- `pending_review`
- `changes_requested`
- `rejected`
- `published`

`published` is informational; WordPress `post_status = publish` remains the public visibility authority.

### Review metadata

Initial metadata design:

- `_penalis_editorial_state`
- `_penalis_review_feedback` — current feedback, if any
- `_penalis_submitted_at`
- `_penalis_submitted_by`
- `_penalis_reviewed_at`
- `_penalis_reviewed_by`
- `_penalis_review_cycle`
- `_penalis_review_history` — bounded history of submissions and decisions

Each history entry must include the action, reviewer or submitter ID, timestamp, review cycle, and feedback when applicable. History must be bounded to prevent unbounded post meta growth.

### Notifications

Notifications are stored in bounded user-scoped data. Each notification needs an ID, type, article ID, message, created timestamp, and optional read timestamp. The implementation must define a retention/boundary (recommended: at most 50 recent notifications per user).

## Phased implementation plan

Each phase is complete only when its exit criteria pass. Update the checkbox and the implementation status after completing the phase; do not mark a phase complete merely because files were edited.

### Phase 0 — Documentation and baseline

- [x] Record the agreed editorial decisions in this roadmap.
- [x] Update `PROJECT-SPECIFICATION.md` to describe the V2 editorial model.
- [x] Update `SECURITY.md` with submission, admin-approval, and visibility invariants.
- [x] Update `README.md` and `readme.txt` where they describe direct writer publishing.
- [x] Record the current test/build baseline before code changes.

Baseline measured on 2026-11-05 (`feat/upload-image`, before any V2 code change):

- `composer lint` — 8 files, 0 errors.
- `composer test` — 348 assertions passed.
- `npm run check` — ESLint clean, `tsc --noEmit` clean, Vitest 72 tests in 5 files, build OK
  (index 70.11 kB gzip, RichTextEditor chunk 135.64 kB gzip).

Exit criteria: all product and security rules have one unambiguous source of truth, and the baseline commands/results are recorded.

### Phase 1 — Pure policy and data contracts

- [ ] Define editorial states, actions, metadata keys, and bounded history shape.
- [ ] Add pure transition rules for submit, resubmit, request changes, reject, reopen, and approve.
- [ ] Define separate rules for new posts and for posts that are already published.
- [ ] Define admin reviewer permission policy.
- [ ] Add unit tests for every valid and invalid transition, including stale review cycles.

Exit criteria: policy tests cover the complete state machine without WordPress dependencies.

### Phase 2 — Server-side submission model

- [ ] Add a dedicated editorial workflow service/class for persistence and transitions.
- [ ] Initialize editorial metadata safely for existing and newly created posts.
- [ ] Implement new-article submission: `draft` to WordPress `pending` plus `pending_review`.
- [ ] Implement resubmission after requested changes.
- [ ] Prevent writers from editing or resubmitting a submission while it is being reviewed, unless the state explicitly permits it.
- [ ] Ensure writers cannot edit any `publish` post through the dashboard or direct REST requests, including older published posts.
- [ ] Bound and validate review history.

Exit criteria: server tests prove that all state changes are centralized, ownership is enforced, published posts are immutable to writers, and no content is silently lost.

### Phase 3 — Authorization and native REST hardening

- [ ] Prevent writer requests from setting or changing a post to `publish`.
- [ ] Ensure only the approval action can publish a writer submission.
- [ ] Preserve administrator/editor native workflows.
- [ ] Enforce ownership for posts, pending submissions, media, taxonomies, and featured images.
- [ ] Prevent access to another writer’s `pending`, draft, feedback, or review history.
- [ ] Deny writer updates to any `publish` post through core REST while preserving administrator editing.
- [ ] Add direct-request and IDOR tests for every protected route.

Exit criteria: authorization tests pass for anonymous users, pending writers, active writers, multiple writers, and administrators.

### Phase 4 — Administrator review surface

- [ ] Add an admin review screen under a clear wp-admin menu location.
- [ ] List pending review submissions with writer, title, date, and review cycle.
- [ ] Show the submitted content, with a link to the public article when one exists.
- [ ] Add actions: approve and publish, request changes, reject, reopen.
- [ ] Require feedback for request-changes and reject actions.
- [ ] Allow admin corrections before approval without accidentally bypassing audit history.
- [ ] Handle concurrent reviewer decisions safely using current state/review cycle checks.
- [ ] Record reviewer identity and timestamps.

Exit criteria: an administrator can complete every review decision without using direct database changes, and duplicate/stale actions fail safely.

### Phase 5 — Writer dashboard workflow

- [ ] Replace direct publish actions with save draft, submit, and resubmit actions.
- [ ] Display editorial state, current feedback, and review history.
- [ ] Explain that published articles are read-only for writers and changes must be requested from an administrator.
- [ ] Hide or disable editing and submission actions that are invalid for the current state.
- [ ] Show pending submissions only to the owner and administrators.
- [ ] Add loading, empty, error, success, and stale-review states.
- [ ] Keep all user-facing copy in Indonesian and preserve accessibility rules.

Exit criteria: a writer can create, submit, revise, resubmit, and understand every state from the dashboard, while published articles stay read-only.

### Phase 6 — Notifications

- [ ] Add email templates for submission, requested changes, rejection, reopening, and publication.
- [ ] Add bounded dashboard notifications with read/unread behavior.
- [ ] Link notifications to the relevant dashboard article/review view.
- [ ] Record mail failures without rolling back a successful editorial decision.
- [ ] Prevent notification content from leaking private article content to the wrong recipient.

Exit criteria: every required event produces an email and dashboard notification, with safe failure handling.

### Phase 7 — Integration tests and manual verification

- [ ] Extend PHP tests for the complete editorial state machine.
- [ ] Extend frontend tests for status parsing, visibility, and workflow helpers.
- [ ] Run PHP lint/tests and frontend lint/typecheck/tests/build.
- [ ] Manually test two writers and at least two administrators.
- [ ] Verify new article approval, requested changes, rejection, reopen, and resubmission.
- [ ] Verify writers cannot edit newly or previously published articles, while administrators still can.
- [ ] Verify direct REST attempts cannot bypass review or edit protection.
- [ ] Verify pending content is not exposed to another writer or anonymous visitor.
- [ ] Verify email delivery/failure behavior and dashboard notification retention.

Exit criteria: all automated gates pass and the complete manual checklist passes on the local WordPress site.

### Phase 8 — Release readiness

- [ ] Reconcile all documentation with the implemented behavior.
- [ ] Update version metadata in all required files for the user-visible change.
- [ ] Review upgrade behavior for installations with existing drafts, published posts, and legacy accounts.
- [ ] Confirm activation, upgrade, deactivation, and uninstall remain non-destructive.
- [ ] Review the final diff and leave changes uncommitted unless explicitly requested.

Exit criteria: the plugin is ready for owner review and a later staging deployment.

## Tracking log

| Date | Phase | Change | Evidence |
|---|---|---|---|
| 2026-11-05 | Phase 0 | Roadmap created from Penalis editorial decisions | Planning discussion; implementation not started |
| 2026-11-05 | Phase 0 | Documentation aligned with V2 model; baseline recorded | `composer lint` 8 files/0 errors, `composer test` 348 assertions, `npm run check` 72 tests + build OK |
| 2026-11-05 | Planning | Published articles made writer-read-only | Penalis team decision; pending revisions removed from V2 scope |

## Explicit non-goals for V2

- Inline paragraph-level comments or annotations.
- A separate custom post type or separate article database.
- A separate app server or authentication system.
- Automatic publication by any writer-facing endpoint.
- Deleting or reassigning existing posts during migration.
