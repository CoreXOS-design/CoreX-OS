# System Updates — Bulk Email — Spec

> **Status:** APPROVED — Johan approved the three business decisions in §8 live in
> chat, 2026-08-24. Technical/architectural calls below are the implementer's,
> per CLAUDE.md rule #8 (Johan decides WHAT, not HOW).
> **Author:** Claude (agent), drafted 2026-08-24
> **Extends:** `.ai/specs/system-updates.md` — a new capability living in the same
> admin area, NOT a change to that spec's modal/audience decisions.

---

## 1. What this feature does and why

The System Updates modal (see `system-updates.md`) tells users about product
changes inside CoreX. It cannot reach a user who isn't logged in, and it cannot
be used for operational announcements ("CoreX is going into maintenance
tonight at 22:00") that a person needs to see **whether or not** they happen to
open CoreX that day.

**Bulk Email** closes that gap: the System Owner writes a subject + message,
picks either every CoreX user or one specific agency's users, and sends it as
a branded CoreX email. It lives as a second tab inside the existing System
Updates admin page, because both are "the System Owner broadcasting a message
to users" — same authors, same audience-scoping problem, same page.

## 2. Pillar connections

| Pillar | Relationship |
|--------|--------------|
| **Agent** (`User`) | **Reads.** Recipients are resolved from `User` (email, `is_active`, `agency_id`). No write-back to the `User` row itself. |
| **Agent** (`Agency`) | **Reads.** Agency-scoped sends resolve `Agency::users()`. |
| Property / Contact / Deal | No linkage — this is product/operational communication, not transactional data. |

Every send is logged to its own table (§4), which is the write-back that
satisfies non-negotiable #4 the same way System Updates' adoption tracking
does: an honest, queryable record of what was sent, to whom, by whom, when.

## 3. Tenancy

`bulk_email_broadcasts` carries **no `agency_id` global scope** — same
documented exception as `system_updates` (see that spec's §3). It has a
nullable `target_agency_id` **column** (not a scope) recording which agency a
given broadcast targeted, distinct in kind from `BelongsToAgency` tenancy.

Write access is `owner_only` (§7 below) — identical reasoning to
`system-updates.md` §10: a permission key here is a grantable path to
"agency admin accidentally emails every user in every other agency," which
`owner_only` closes structurally.

## 4. Data model

### 4.1 `bulk_email_broadcasts`

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | bigint PK | — | |
| `subject` | string(200) | NOT NULL | |
| `body` | text | NOT NULL | Plain text; rendered escaped with paragraph breaks, same rule as `system_updates.body` (§9.3 of that spec) — never raw HTML. |
| `target_type` | string(20) | NOT NULL | `all` \| `agency`, app-level allow-list (same reasoning as `system_updates.type` — no `ALTER TABLE` to add a target kind later). |
| `target_agency_id` | bigint FK → `agencies` | NULL | Set only when `target_type = 'agency'`. `nullOnDelete` — a deleted agency does not erase the audit row. |
| `recipient_count` | int unsigned | NOT NULL | Snapshot of how many users the send actually queued to, computed server-side at send time (§9.1) — never the client-submitted count. |
| `sent_by_user_id` | bigint FK → `users` | NULL | `nullOnDelete`; UI shows "System" if the sender account is later deleted, matching `system_updates.created_by_user_id`. |
| `created_at` / `updated_at` | timestamps | | `created_at` is the send timestamp — no `updated_at` mutation path exists; broadcasts are never edited. |

No `deleted_at`. This is an immutable audit log of a thing that already
happened (an email that was sent) — there is nothing to soft-delete, same
treatment as `system_update_views`.

Index: `(target_type, target_agency_id)`, `created_at`.

Model: `App\Models\BulkEmailBroadcast` — no `BelongsToAgency`.

### 4.2 Migration

`database/migrations/2026_08_24_000001_create_bulk_email_broadcasts_table.php`

Per non-negotiable #12a, `database/schema/mysql-schema.sql` is re-dumped from
the **test** database and the `DEFINER` clause stripped, in the same commit.

## 5. UI placement, navigation, user flow

### 5.1 Tab bar on the existing System Updates admin page

`resources/views/admin/system-updates/index.blade.php` gains a two-item tab
bar above the page content: **Updates** (existing index — Active/Archived
toggle nests inside this tab, unchanged) and **Bulk Email** (new). No new
sidebar entry — System Updates already has one (non-negotiable #2 is already
satisfied by the parent page).

### 5.2 Compose flow

1. Owner → System Developer → System Updates → **Bulk Email** tab
2. **Send to:** dropdown — "All CoreX Users (N)" or "Home Finders Coastal (M)"
   style per-agency options, each showing its own live active-user count so
   the owner sees the blast radius before typing a word
3. **Subject** (text input, required)
4. **Message** (textarea, required) — rendered into the branded template
   exactly as typed, escaped, paragraph breaks preserved
5. **Send** → confirmation dialog: *"This will email **{count}** user(s) —
   {target label}. This cannot be undone. Send now?"* (STANDARDS —
   confirmation before consequential/irreversible actions; same pattern as
   System Updates' "Re-notify everyone")
6. Confirm → server recomputes the recipient list and count fresh (§9.1),
   queues one mail job per recipient, writes the `bulk_email_broadcasts` row,
   redirects back to the Bulk Email tab with a flash: *"Queued to {count}
   users."*
7. Below the compose form: **Recent broadcasts** table — subject, target,
   recipient count, sent by, sent at, newest first. This is the audit trail;
   no edit or resend action, since a broadcast already happened.

### 5.3 Zero recipients

Target resolves to zero active users (e.g. an agency with no active accounts)
→ **absorbed**: the Send button is disabled and the dropdown option reads
"(0 users)" — never lets the owner reach the confirmation dialog for an empty
send, and never creates a broadcast row for nothing.

## 6. Email template

`resources/views/emails/bulk-announcement.blade.php`, structurally copied
from the branded pattern in `resources/views/emails/user-invite.blade.php`
(centred 560px table, CoreX wordmark, white rounded card, footer) — there is
no shared layout component in this codebase yet (confirmed by inspection), so
every branded mailable is self-contained; this one follows the existing
convention rather than introducing a new abstraction for a single caller.

Mailable: `App\Mail\BulkAnnouncementMail implements ShouldQueue` — matches
the house rule (`FeedbackReportMail`, `DemoAccessGrantMail`): SMTP runs on the
worker, never inline in the request. No custom `onQueue()` — lands on the
default queue connection's default queue, same as every other queued
mailable in this codebase, which is what both the QA2 and production queue
workers actually drain.

Subject = the broadcast's `subject` verbatim. Body = the broadcast's `body`,
escaped, newline-to-paragraph, no raw HTML — identical rule to
`system_updates.body` (§9.3 of that spec), for the identical reason: the
author is trusted, the rendering surface (every recipient's inbox, including
HTML-rendering webmail) is not a place to take that risk.

## 7. Permissions

`owner_only` middleware on the routes (§10 below), plus
`abort_unless($request->user()?->isOwnerRole(), 403)` in the controller —
same belt-and-braces pattern as System Updates and Demo Access Control.
**No grantable permission key** in `config/corex-permissions.php`, for the
same reasoning as `system-updates.md` §10.

## 8. Decisions on the record

| # | Decision | Made by | Date |
|---|----------|---------|------|
| 1 | QA/staging sends go to the environment's configured mail catcher (already Mailpit on QA2 via `MAIL_HOST=127.0.0.1:1025` — no redirect logic needed, this is how every other mailable in the app already behaves per-environment); production sends reach real inboxes | Johan | 2026-08-24 |
| 2 | Confirmation dialog required before send, naming the recipient count | Johan | 2026-08-24 |
| 3 | Owner only — no delegation to agency admins | Johan | 2026-08-24 |
| 4 | Lives as a tab on the existing System Updates admin page, not a new sidebar entry | Johan (via original request) | 2026-08-24 |
| 5 | Immutable audit log table, no edit/resend of a past broadcast | Claude (spec, technical) | 2026-08-24 |
| 6 | No custom mail queue name — uses the default queue like every other queued mailable | Claude (spec, technical) | 2026-08-24 |

### Still open
Nothing. Buildable.

## 9. Robustness (BUILD_STANDARD §2, §3)

### 9.1 Recipient resolution — never trust the client
The count shown in the dropdown at page-load time is informational only. At
send time, the controller re-resolves recipients fresh:
`User::withoutGlobalScope(AgencyScope::class)->whereNotNull('email')->where('is_active', true)`,
further filtered by `agency_id` when `target_type = 'agency'`. Explicit
`withoutGlobalScope` regardless of the owner's switcher state — identical
defensive reasoning to `system-updates.md` §7.1's adoption-count note: the
owner-role scope bypass stops the moment they've switched into an agency, and
that must never silently narrow "All CoreX Users" to one agency.

### 9.2 Validation
`subject` required, max 200, trimmed (whitespace-only rejected).
`body` required, max 5000, trimmed. `target_type` required, `Rule::in(['all','agency'])`.
`target_agency_id` required when `target_type = agency`, must exist in `agencies`.
A tampered `target_type` is rejected, never silently coerced to `all`.

### 9.3 Idempotency
The Send POST is not naturally idempotent (a resubmit would send twice) — the
Send button disables itself on click (Alpine.js) and the confirmation dialog
is the deliberate friction that makes a double-submit unlikely. No dedupe
token in v1; this mirrors how every other one-shot "fire an email" action in
this codebase behaves (e.g. `UserInviteMail`) — a resend concern would be
solved by not clicking Send twice, not by infrastructure, exactly as
elsewhere in CoreX.

### 9.4 Failure handling
Per-recipient send failures land in Laravel's existing `failed_jobs` table
(standard queue infra) — same as any other queued mailable in this codebase.
No new failure UI in v1; this is consistent with the fact that no other
queued mailable in CoreX has bespoke failure surfacing either.

## 10. Routes

`routes/web.php`, inside the existing
`Route::middleware('owner_only')->prefix('admin/system-updates')->name('admin.system-updates.')`
group (`system-updates.md` §11.1):

```
GET  /bulk-email          admin.system-updates.bulk-email.create
POST /bulk-email/send     admin.system-updates.bulk-email.send
```

Controller: `App\Http\Controllers\Admin\BulkEmailController` (new — kept
separate from `SystemUpdateController` for separation of concerns; the tab
UI is the only shared surface).

## 11. Design system compliance

New Blade views carry the header
`DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20`, use
`var(--token, #fallback)` for colours, introduce no new tokens, dark and light
theme correct, usable at 360px wide.

Files:
- `resources/views/admin/system-updates/bulk-email.blade.php` — compose tab +
  recent broadcasts table
- `resources/views/emails/bulk-announcement.blade.php` — the branded email

## 12. Domain events (non-negotiable #9)

No domain event in v1 — sending a broadcast produces no cross-pillar
reactivity (identical reasoning to `system-updates.md` §13).

## 13. Setup Wizard (non-negotiable #10a)

N/A — adds no agency setting.

## 14. Acceptance criteria

1. Owner sends to "All CoreX Users" → every active user with an email
   receives the branded email; a `bulk_email_broadcasts` row records the
   correct `recipient_count`.
2. Owner sends to one specific agency → only that agency's active users
   receive it, verified against a second agency's users receiving nothing.
3. Confirmation dialog shows the correct count before send; cancelling sends
   nothing and creates no log row.
4. Non-owner (agency admin, agent) hitting either route directly gets 403.
5. Empty subject/body rejected with a plain-language message; nothing queued.
6. `target_type = agency` with no `target_agency_id` rejected.
7. Tampered `target_type` rejected.
8. Agency with zero active users → Send is disabled, no broadcast attempted.
9. A `<script>` tag typed into the message renders as visible text in the
   received email, never executes / never breaks the template.
10. Mail is queued (`Mail::to()->queue()`), never sent synchronously in the
    request — verified via `Mail::fake()` + queue assertion in tests.
11. Recent broadcasts table shows newest-first, correct target label per row.

## 15. Test matrix

`tests/Feature/SystemUpdates/BulkEmailBroadcastTest.php` — covers items 1–11
above. Per non-negotiable #13, only this file runs during the build.

## 16. Files to create / modify

**Create**
```
database/migrations/2026_08_24_000001_create_bulk_email_broadcasts_table.php
app/Models/BulkEmailBroadcast.php
app/Http/Controllers/Admin/BulkEmailController.php
app/Mail/BulkAnnouncementMail.php
resources/views/admin/system-updates/bulk-email.blade.php
resources/views/emails/bulk-announcement.blade.php
tests/Feature/SystemUpdates/BulkEmailBroadcastTest.php
```

**Modify**
```
routes/web.php                                        bulk-email routes in existing owner_only group
resources/views/admin/system-updates/index.blade.php  tab bar (Updates | Bulk Email)
database/schema/mysql-schema.sql                       re-dumped (#12a)
.ai/CHAT_STARTER.md                                    status move on landing
```
