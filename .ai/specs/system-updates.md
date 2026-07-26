# System Updates — Spec

> **Ticket:** AT-338 — New feature alert pop-up
> **Status:** DRAFT — awaiting Johan's sign-off. No code until approved.
> **Author:** Andre (drafted 2026-07-26)
> **Branch:** `AT-338-New-feature-alert-pop-up-l`
> **Supersedes:** the `new-feature-announcements.md` working draft (renamed — the
> module covers fixes and improvements, not only new features).

---

## 1. What this feature does and why

When something ships in CoreX, nobody tells the people who use it. Agents find out
months later — or never. The Setup Wizard tells an *agency* what CoreX can do once,
at onboarding; nothing tells a *user* what changed last Tuesday. Features ship
inert because the humans who would use them never learn they exist, and bug fixes
land silently so the person who reported the fault never learns it was fixed.

**System Updates** closes that loop. A System Owner writes an update on a System
Developer page — what it is, what type it is, a screenshot, and a button that takes
the user straight to it. The next time any CoreX user opens the system, a pop-up
shows them what changed. They read it, they close it, and it never interrupts them
again.

This is the CoreX-side companion to non-negotiable #10a. #10a makes sure a new
setting reaches the *agency* at setup time. This makes sure a change to CoreX
reaches the *user* at ship time.

### The standard this is held to

Best-in-class here is Intercom's "what's new" widget and Linear's changelog modal.
Ours must beat both on the axis that matters to an estate agent: **an update is not
read, it is acted on.** Every update can carry a "Take me there" button that lands
the user on the live feature, not on a page describing it. Reading about a change
and using it are one click apart, not two tabs and a search.

---

## 2. Pillar connections

| Pillar | Relationship |
|--------|--------------|
| **Agent** (`User`) | **Reads and writes.** The acknowledgement record is keyed to a `User`, and eligibility reads that user's `created_at`. Every update writes back per-user adoption data: which practitioners have seen which change, and when. The admin detail page surfaces that as an adoption count ("41 of 58 CoreX users have seen this"). |
| Property / Contact / Deal | No direct linkage. An update is a message *about* CoreX, not a record *in* CoreX. |

Non-negotiable #4 is satisfied via the Agent pillar: the feature reads `User` and
writes enriched data (per-user acknowledgement + adoption metrics) back against it.
That adoption data is what makes this more than a modal — it is the first honest
answer CoreX has ever had to "did anyone actually see this?"

---

## 3. Tenancy — deliberately global, and why

`system_updates` carries **no `agency_id`** and does **not** use `BelongsToAgency`.
This is a deliberate, documented exception to non-negotiable #7, of exactly the same
kind already granted to `user_tour_progress`.

**Reason:** a system update is a CoreX **product release note**, authored by the
System Owner, describing a change to the CoreX codebase that every tenant just
received. It is not tenant-owned data — it is data *about the product*, addressed to
everyone using it. Stamping it with an `agency_id` would either (a) force the owner
to re-author the same note once per agency, or (b) create a row whose `agency_id` is
a lie.

`system_update_views` likewise carries no `agency_id` — it is personal UI state keyed
to `user_id`, identical in kind and justification to `user_tour_progress` (see that
migration's docblock).

**Consequences accepted, and the guards that make them safe:**

- `AgencyScope` is never registered on either model, so no scope-bypass call
  (`withoutGlobalScope`) appears anywhere in request code. There is nothing to
  bypass — we stay compliant with the letter of the multi-tenancy spec rather than
  carving a hole in it.
- Write access is `owner_only` (§7). No agency user can author a row, so a global
  table cannot become a cross-tenant leak vector.
- Read exposure is by design: every user is the intended reader (§6).
- `system_update_views` is written self-scoped from `auth()->id()` only — a user can
  never mark another user's row, and the endpoint takes no user id as input.

---

## 4. Data model

### 4.1 `system_updates`

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | bigint PK | — | |
| `title` | string(160) | NOT NULL | Plain-English headline. STANDARDS F.8 applies — no jargon, no codenames, no "AT-338". |
| `body` | text | NOT NULL | What changed, in full sentences. Rendered as escaped text with paragraph breaks preserved — **never** raw HTML (§9.3). |
| `type` | string(20) | NOT NULL, default `feature` | `feature` \| `improvement` \| `fix` — see §5. |
| `link_url` | string(255) | NULL | Where the change lives. Internal path (`/corex/properties`) or absolute URL. Validated per §9.2. |
| `link_label` | string(60) | NULL | Button text. Defaults to "Take me there" when `link_url` is set and this is blank. |
| `image_path` | string(255) | NULL | Screenshot/GIF on the `public` disk under `system-updates/`. |
| `status` | string(20) | NOT NULL, default `draft` | `draft` \| `published`. |
| `published_at` | timestamp | NULL | Set on publish, cleared on unpublish. Ordering key for the modal. |
| `notify_reset_at` | timestamp | NULL | "Re-notify everyone" watermark — §7.4. |
| `created_by_user_id` | bigint FK → `users` | NULL | `nullOnDelete`. Deleted-author path renders "System" (BUILD_STANDARD §4). |
| `deleted_at` | timestamp | NULL | `SoftDeletes` — non-negotiable #1. |
| `created_at` / `updated_at` | timestamps | | |

Indexes: `(status, published_at)`, `deleted_at`.

Model: `App\Models\SystemUpdate` — `SoftDeletes`, **no** `BelongsToAgency`.

`type` is stored as a plain string with an application-level allow-list (not a DB
enum) — adding a fourth type must never require an `ALTER TABLE` on a live
database. There is **no `audience` column** (§6).

### 4.2 `system_update_views`

| Column | Type | Null | Notes |
|--------|------|------|-------|
| `id` | bigint PK | — | |
| `system_update_id` | bigint FK | NOT NULL | `cascadeOnDelete` |
| `user_id` | bigint FK → `users` | NOT NULL | `cascadeOnDelete` |
| `dismissed_at` | timestamp | NOT NULL | When the user closed the pop-up. |
| `created_at` / `updated_at` | timestamps | | |

Unique: `(system_update_id, user_id)`. Index: `user_id`.

A row exists **only** once a user has actually closed the pop-up. Absence means "not
yet acknowledged" — the simplest possible truth, and the one that survives a browser
crash correctly (§9.5).

Model: `App\Models\SystemUpdateView` — no SoftDeletes (personal UI state, same
treatment as `user_tour_progress`; re-notify uses a watermark rather than deleting
rows, so nothing is ever destroyed — §7.4).

### 4.3 Migrations

- `2026_07_26_000001_create_system_updates_table.php`
- `2026_07_26_000002_create_system_update_views_table.php`

No global reference rows are required, so nothing needs registering in
`deploy:sync-reference-data` (BUILD_STANDARD §8). Per non-negotiable #12a,
`DB_DATABASE=hfc_dash_test php artisan schema:dump` is re-run and the refreshed
`database/schema/mysql-schema.sql` committed **in the same commit** as these
migrations.

---

## 5. Update types

Three types, shown as a coloured chip on the modal card, in the admin list, and as
a filter on the archive:

| Key | Chip label | Meaning | Token |
|-----|-----------|---------|-------|
| `feature` | **New Feature** | Something CoreX could not do before. | `--ds-cyan` (brand accent) |
| `improvement` | **Improvement** | Something CoreX did before, now better or faster. | `--ds-amber` |
| `fix` | **Fixed** | Something that was broken and now works. | `--ds-emerald` |

The chip is what lets a user triage in half a second: *New Feature* means go learn
something; *Fixed* means note it and carry on. Without it, every card demands the
same attention regardless of weight.

**Where the vocabulary lives:** `config/system-updates.php`, as a keyed array of
`label` + `token` + `sort`. Fixed in the codebase — **no settings table, no admin
UI to edit it, no per-agency knob.** All three surfaces (chip, admin form dropdown,
archive filter) and the validation allow-list read from that one array, so the three
types can never drift apart across surfaces.

**On SYSTEM.md §3 (No Hardcoding):** §3 exists so that *agencies* can configure
*their own* terminology — property types, deal stages, contact types. This
vocabulary is not agency terminology. It describes what CoreX the product did in a
release, it is authored only by the System Owner, and it is identical for every
tenant by definition. A settings table would give agencies a knob over a vocabulary
they do not own and cannot meaningfully change. **Confirmed hardcoded by Johan,
2026-07-26** — the set is New Feature / Improvement / Fixed, permanently.

---

## 6. Audience — everyone, always

**Every published update goes to every authenticated CoreX user, in every agency,
in every role.** There is no audience control, no role targeting, no per-agency
targeting, and no `audience` column. (Johan, 2026-07-26 — an earlier draft carried
an Everyone / Admins-only switch; it was removed before promotion.)

The only thing that narrows who sees an update is §8.1 rule 2: a user never sees
updates published before their own account existed.

**Why there is no targeting at all.** A release note that some users could be
excluded from — by an agency switch, a role filter, or a feature toggle — recreates
exactly the problem this feature exists to solve: a change ships, the people who
would use it are never told, and it stays inert. The moment an update can be
addressed to a subset, someone has to decide the subset correctly every single
time, and the failure mode of getting it wrong is **silent** — the update simply
reaches nobody and nothing errors.

Consequently this feature is deliberately **not** in `config/corex-features.php`:
it is not a toggleable agency module.

**If per-role targeting is ever wanted**, the thing NOT to do is resolve "admin"
from a role-name list. `roles` is per-agency (`roles.agency_id`) and
agency-editable, so `['admin','super_admin']` is wrong on the first agency that
renames its role to "Principal" or "Office Manager". It would have to resolve by
**capability** — e.g. `isOwnerRole() || hasPermission('sidebar.section.admin')` —
which is tenant-agnostic and follows an agency's own role changes. Recorded here so
a future prompt does not reach for the name list.

---

## 7. UI placement, navigation, and user flow

### 7.1 Admin page — System Developer → **System Updates**

Sidebar entry inside the existing `@if($isOwner)` System Developer block in
`resources/views/layouts/corex-sidebar.blade.php`, placed directly after
**Dev Settings**. Label: **System Updates**. Nav entry ships in the same commit —
non-negotiable #2.

Pages (`/admin/system-updates`):

| Screen | Purpose |
|--------|---------|
| **Index** | Table of every update: type chip, title, status chip (Draft / Published / Archived), published date, adoption ("41 / 58 seen"), actions. Archived rows behind a "Show archived" toggle, with Restore. |
| **Create / Edit** | Type, title, body, optional link URL + label, optional screenshot, live preview pane. |
| **Show** | The update as authored, plus the adoption list — who has seen it, who hasn't, when. |
| **Preview** | Renders the *real* modal component with this update, so the owner sees exactly what a user will see before publishing. |

**Adoption denominator.** Every user — an update is addressed to all of them (§6).
Counted with `AgencyScope` explicitly dropped: this is a cross-agency owner view,
and the scope's owner bypass stops the moment the owner has entered an agency via
the switcher, which would silently return a single agency's count with no error.
Documented exception per the multi-tenancy rule; safe because the surface is
`owner_only`. Runs only on the admin Show/Index pages, never on the hot path.

### 7.2 Author flow

1. Owner → System Developer → System Updates → **New update**
2. Picks the **type** (New Feature / Improvement / Fixed)
3. Types title + body, optionally uploads a screenshot, optionally sets the link
   ("Where does this live?") and the button label
4. **Preview** — the actual modal renders over the page
5. **Save as draft** (nobody sees it) or **Publish now**
6. On publish: `status = published`, `published_at = now()`, published-list cache
   busted (§9.6). Every user sees it on their next page load.

### 7.3 User flow

1. User opens any CoreX page
2. Modal appears, centre-screen, over a scrim — type chip, title, screenshot, body,
   and (if set) a **Take me there** button
3. **Take me there** → navigates to the feature. *This also dismisses the update* —
   acting on it is a stronger acknowledgement than closing it.
4. Or **Next** through the remaining cards, then **Close**
5. Dismissal POSTs once, covering every card shown. It never appears again.

### 7.4 Re-notify

Editing a published update does **not** re-show it to people who already dismissed
it — a typo fix must not re-interrupt 58 people. To deliberately re-show, the owner
clicks **Re-notify everyone**, setting `notify_reset_at = now()`. Per §8.1 rule 4,
every dismissal older than that watermark stops counting and the update becomes
pending again.

This is a watermark, not a delete: no `system_update_views` row is ever removed, so
the "who saw the original" audit survives intact. The button carries a confirmation
dialog naming how many users will see it again (STANDARDS — confirmations before
consequential actions).

### 7.5 User-facing archive — **What's New**

A read-only page at `/corex/whats-new` listing every update the user is eligible
for, newest first, filterable by type, with a "New" chip on anything still pending.
Nav entry: sidebar, beside **Guided Tours**; also linked from the modal footer.

Rationale: a user who closes the modal in a hurry otherwise has no way back to the
information — the standing CoreX rule against dead ends. It also gives the modal its
overflow destination (§8.3). Always available, no feature toggle, for the same
reason as §6.3.

---

## 8. Who sees what, and when

### 8.1 Eligibility (the exact rule)

An update is **pending** for a user when all of the following hold:

1. `status = 'published'` and `published_at <= now()`
2. `published_at >= user.created_at` — **a user never sees changes that predate
   their own account**
3. No `system_update_views` row for `(update, user)` with
   `dismissed_at > COALESCE(update.notify_reset_at, update.published_at)`

   The comparison is **strict**: "re-notify at time T" voids every dismissal up to
   and including T. With `>=`, a user who closed the modal in the same second the
   owner clicked Re-notify would never be shown it again — silently, and only for
   them.

**Rule 2 is a load-bearing design decision, not an optimisation.** Without it, an
agent who joins next March opens CoreX for the first time and is met with forty
historical notes about changes that, to them, are simply how CoreX has always
worked. Their first minute in the system would be spent clicking Next. New users get
a clean slate; the archive (§7.5) is there if they're curious.

### 8.2 Trigger

The pop-up fires on the **first authenticated page load after publish** — whatever
CoreX page the user lands on. Rendered by a partial included once in
`layouts/corex-app.blade.php`, exactly like the existing tour engine and reminder
toast. No per-page wiring, no controller changes anywhere else.

### 8.3 Multiple pending updates

One modal, paged. "What's new in CoreX — 1 of 3", with Back / Next, and a **Close**
that becomes the primary action only on the last card (Next is primary before that).
Closing marks **every update it displayed** as dismissed in one request.

**Cap: 5** — the 5 most recent. If more are pending, the footer reads "+ 4 more
updates — see all" linking to the archive, and the overflow stays pending (it
surfaces on the next login, or the user clears it from the archive). Bounded
interruption, nothing silently swallowed.

---

## 9. Robustness — input space and prevent-or-absorb (BUILD_STANDARD §2, §3)

Decided here, at spec time, before any code.

### 9.1 Required fields
`title` and `body` are required; `type` is required with a default already selected
in the form, so it can never arrive empty by accident. Empty
title/body → **prevented** at validation in plain language ("Give the update a title
so users know what changed."). Never reaches the DB. Both trimmed before validation;
a whitespace-only title is empty. `title` max 160, `body` max 5000 — enforced in
validation *and* by column width, so the DB contract cannot be violated by any input
path. `type` is validated with `Rule::in()` against the config allow-list — a
tampered form value is rejected, never stored.

### 9.2 Optional fields — every one, empty, individually
- **No link, no label, no image** → the lazy-but-valid shortcut (type + title +
  body, publish). First-class path. Modal renders with no button and no image,
  correctly laid out — not a broken frame with an empty box.
- **`link_label` set, `link_url` empty** → **absorbed**: no button renders.
- **`link_url` set, `link_label` empty** → **absorbed**: button reads "Take me
  there".
- **Malformed `link_url`** → **prevented**: must be an internal path starting `/` or
  an `http(s)://` absolute URL. `javascript:` and `data:` URIs rejected outright —
  this is a link shown to every user in CoreX, and it is an XSS vector if
  unvalidated.
- **Image**: `jpg|jpeg|png|webp|gif`, max 4 MB, mime-validated not just
  extension-validated. Oversize/wrong type → **prevented** with a clear message.
  Replacing an image deletes the old file from the `public` disk. Missing file on
  disk at render time → **absorbed**: the modal renders without the image
  (BUILD_STANDARD §4 — a broken `<img>` is not acceptable).

### 9.3 Body rendering — escaped, always
`body` is stored and rendered as **escaped text** with newlines converted to
paragraph breaks. Never rendered as raw HTML. The author is a System Owner and
therefore trusted, but this modal renders inside every authenticated session in
CoreX; an injected `<script>` there is the highest-value XSS target in the product.
Trusted author is not a reason to skip escaping. Rich formatting, if ever wanted,
comes later as an allow-listed subset, specced separately.

### 9.4 Deleted-related-record paths
- **Author deleted** → `created_by_user_id` is `nullOnDelete`; UI shows "System".
- **Update archived while a modal is open** → the dismiss POST ignores the unknown
  id and returns ok. No error reaches the user.
- **User deleted** → view rows cascade.
- **Unknown `type` in a row** (config entry removed later) → **absorbed**: the chip
  falls back to a neutral "Update" label rather than throwing on a missing array
  key.

### 9.5 Idempotency and interruption
- Dismiss uses `updateOrCreate` on `(update, user)` — double-submits, double-clicks
  and retries are harmless.
- Closing the browser mid-modal writes nothing → still pending next load. Correct:
  it was not acknowledged.
- A POST that fails (offline, 419) **closes the modal in the UI anyway** and lets it
  reappear next load, rather than trapping the user behind a modal they cannot
  dismiss. Never trade a user's ability to work for our bookkeeping.
- Publishing an already-published update is a no-op, not an error.

### 9.6 Performance — every page load, every user
The eligible-published list (`id`, `published_at`) is cached under
`system_updates.published`, busted on publish / unpublish / update / renotify /
archive / restore. Per request:

1. Read the cached list and filter in PHP by `user.created_at`. **Nothing left →
   zero DB queries.** This is the case for essentially every page load in normal
   operation.
2. Only when candidates remain: one indexed query against `system_update_views` for
   this user.

A feature that added a query to every page load in CoreX for the 99.99% case where
there is nothing to say would be a bad trade, and we're not making it.

---

## 10. Permissions

**Admin surface:** `owner_only` middleware on the whole route group, plus
`abort_unless($request->user()?->isOwnerRole(), 403)` in every action — the same
belt-and-braces pattern as Demo Access Control.

**Deliberately NO grantable permission key in `config/corex-permissions.php`.**

This satisfies non-negotiable #5 rather than skipping it, on the reasoning recorded
in `demo-access-control.md` §8. A permission key is **grantable via the Role
Manager**. This page broadcasts a full-screen modal, with an arbitrary link, to
*every user of every agency in CoreX*. One mis-click in the Role Manager and an
agency admin can interrupt every one of our other tenants' agents. `owner_only` has
no delegation path; a permission key does. The stronger gate is the correct gate
here, and the rationale is recorded in the route file so a future reader doesn't
"fix" it by adding a key.

**User surface:** the modal, the dismiss endpoint, and the archive require
authentication only. Writes are self-scoped from `auth()->id()` and take no user id
as input — there is no cross-user surface to protect, identical to
`TourProgressController`. Eligibility (§8.1) is applied server-side when the modal
is rendered and again on the archive — never client-side.

---

## 11. Routes and endpoints

### 11.1 Admin (web, `owner_only`)

`routes/web.php`, prefix `admin/system-updates`, name `admin.system-updates.`,
placed beside the Dev Settings group:

```
GET    /                          index
GET    /create                    create
POST   /                          store
GET    /{update}                  show          (adoption)
GET    /{update}/edit             edit
PUT    /{update}                  update
GET    /{update}/preview          preview
POST   /{update}/publish          publish
POST   /{update}/unpublish        unpublish
POST   /{update}/renotify         renotify
DELETE /{update}                  destroy       (soft — archives)
POST   /{update}/restore          restore
```

All `whereNumber('update')`. Full CRUD + archive + restore per BUILD_STANDARD §1 and
STANDARDS Rules 13/14.

Controller: `App\Http\Controllers\Admin\SystemUpdateController`.

### 11.2 User API — non-negotiable #7

Inside the existing session-authenticated `api/v1` group in `routes/web.php` (the
same group the tour endpoints live in — browser-XHR, session-auth, and appears in
the `/admin/api` catalog automatically):

```
POST /api/v1/system-updates/dismiss      api.v1.system-updates.dismiss
```

Body: `{ "ids": [12, 13, 14] }`. Marks each dismissed for the authenticated user.
Unknown / archived / not-yet-eligible ids are ignored, not errors (idempotent by
design — §9.5). Consumed through `window.CoreX.api`.

Controller: `App\Http\Controllers\Api\V1\SystemUpdateDismissController`.

**No `GET /pending` endpoint in v1.** The modal is server-rendered from the layout
partial, so a fetch would be a wasted round-trip on every page load. If the mobile
app later needs the list, it gets a properly specced `GET /api/v1/system-updates`
then — we don't ship unused endpoints.

### 11.3 Archive (web)

```
GET /corex/whats-new                     corex.whats-new.index
```
Auth only.

---

## 12. Design system compliance

Every new Blade view carries the header
`DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20`, uses the
`var(--token, #fallback)` pattern for all colours, introduces no new tokens, and
passes `scripts/check-design-tokens.ps1`.

Modal specifics: centred card on a scrim, 6px radius, existing surface/border/text
tokens, type chip per §5, dark **and** light theme correct, ESC and scrim-click both
close (and both dismiss), keyboard focus trapped while open, usable at 360px wide
(STANDARDS — CoreX is used in the field). Alpine.js only; no jQuery.

Files:
- `resources/views/layouts/partials/system-update-modal.blade.php` — the global
  partial
- `resources/views/layouts/partials/_system-update-card.blade.php` — one card,
  shared by the modal, the admin preview and the archive so the three cannot drift
- `resources/views/admin/system-updates/{index,create,edit,show,preview,_form}.blade.php`
- `resources/views/corex/whats-new/index.blade.php`

**Both layouts carry it.** CoreX has TWO app layouts that render the global
partials — `layouts/corex.blade.php` (254 views) and `layouts/corex-app.blade.php`
(164 views) — exactly as the tour engine is included in both. Adding the modal to
only one would leave more than a third of CoreX's pages silently never showing an
update, with nothing to indicate it.

---

## 13. Domain events (non-negotiable #9)

**No domain event in v1.** Publishing an update produces no cross-pillar reactivity —
nothing in Property, Contact, Deal or Compliance changes or needs to recompute.
Emitting an event with no subscriber would be speculative wiring, which the
catalogue exists to prevent, not to encourage.

**Binding note for future work:** if a later prompt adds a push notification, an
email digest, or a WhatsApp broadcast on publish, that prompt MUST read
`.ai/specs/corex-domain-events-spec.md` and introduce `SystemUpdatePublished`
through the catalogue — never an ad-hoc call from the controller.

---

## 14. Setup Wizard (non-negotiable #10a)

**This feature adds no agency setting, so nothing goes in the Setup Wizard.**
Confirmed by Johan, 2026-07-26.

Checked explicitly: no new column on `agencies`, no `PerformanceSetting` key, no
`commission_settings` field, no toggle on `/corex/settings`. The content is
system-owner product communication, not agency configuration, and §6.3 establishes
that there is deliberately no per-agency toggle to surface.

Recorded here as the "Deliberately NOT in the wizard" decision #10a requires.

---

## 15. Acceptance criteria

Done when all of these are demonstrated, not asserted:

1. Owner creates a draft → no user sees anything.
2. Owner publishes → the next page load for every eligible user shows the modal, on
   whatever page they were headed to.
3. User closes it → never appears again, on any page, after any number of reloads.
4. Three published updates → **one** modal, "1 of 3", Back/Next work, one Close
   clears all three.
5. Six pending → 5 shown, footer says "+ 1 more", the 6th is still pending after.
6. A user created *after* publish never sees that update.
7. "Take me there" navigates to the feature **and** dismisses.
8. Type chip renders correctly for all three types; an unknown stored type falls
   back to a neutral chip rather than erroring.
9. Type + title + body only (no link, no image) renders and dismisses correctly.
10. `javascript:` link URL rejected at validation with a clear message.
11. A `<script>` tag typed into the body renders as visible text, never executes.
12. A tampered or missing `type` posted from a modified form is rejected.
13. Editing a published update does not re-show it; **Re-notify everyone** does, and
    the original view rows still exist afterwards.
14. Archive (soft delete) removes it from every user's pending set immediately;
    Restore brings it back; no row is ever hard-deleted.
15. A non-owner hitting any `/admin/system-updates` URL directly gets 403 —
    including an agency admin.
16. A page load with nothing pending issues **zero** update DB queries; a real
    candidate costs exactly one.
17. Modal usable and readable at 360px wide, in both light and dark theme.
18. Adoption count matches actual view rows.
19. Archive lists only what the user is eligible for, filters by type, and marks
    still-pending items "New".

---

## 16. Test matrix (BUILD_STANDARD §5)

`tests/Feature/SystemUpdates/` — real-world data (real CoreX feature names and copy,
not "Test / Test"):

| File | Covers |
|------|--------|
| `SystemUpdateCrudTest` | create, edit, publish, unpublish, archive, restore; owner-only 403s for agent / admin / assistant roles |
| `SystemUpdateValidationTest` | required-empty rejects; each optional field omitted individually; whitespace-only title; over-length title/body; malformed + `javascript:` / `data:` / protocol-relative URL rejected; bad mime / oversize image rejected; tampered + missing `type` rejected; **the lazy shortcut (type + title + body) succeeds end to end** |
| `SystemUpdateVisibilityTest` | eligibility rule §8.1 in full: draft hidden, published shown, future-dated hidden, user-created-after excluded, dismissed excluded, re-notify re-includes, archived excluded, restore re-includes, 5-cap + overflow, per-user isolation |
| `SystemUpdateDismissTest` | multi-id dismiss; idempotent double-submit; unknown / archived / not-yet-eligible ids ignored; another user's state untouched; self-scoping; guest 401 |
| `SystemUpdateModalRenderTest` | renders on an arbitrary authenticated page; absent when nothing pending; 5-cap + overflow line; body escaped (`<script>` appears as text); missing image file renders without an `<img>`; deleted author renders "System"; unknown type falls back |
| `SystemUpdateQueryBudgetTest` | zero update queries with nothing pending; zero for a non-admin when only admin-only updates exist; one query when candidates exist |

Per non-negotiable #13, during the build only the single most relevant file runs per
change. No broad suite without Johan's explicit go-ahead.

---

## 17. Files to create / modify

**Create**
```
config/system-updates.php                            (type vocabulary — §5)
database/migrations/2026_07_26_000001_create_system_updates_table.php
database/migrations/2026_07_26_000002_create_system_update_views_table.php
app/Models/SystemUpdate.php
app/Models/SystemUpdateView.php
app/Services/SystemUpdateService.php                 (eligibility + cache + dismiss + adoption)
app/Http/Controllers/Admin/SystemUpdateController.php
app/Http/Controllers/Api/V1/SystemUpdateDismissController.php
app/Http/Controllers/CoreX/WhatsNewController.php
resources/views/layouts/partials/system-update-modal.blade.php
resources/views/admin/system-updates/index.blade.php
resources/views/admin/system-updates/create.blade.php
resources/views/admin/system-updates/edit.blade.php
resources/views/admin/system-updates/show.blade.php
resources/views/admin/system-updates/preview.blade.php
resources/views/corex/whats-new/index.blade.php
tests/Feature/SystemUpdates/*.php                     (7 files, §16)
```

**Modify**
```
routes/web.php                                   admin group + api/v1 dismiss + archive route
resources/views/layouts/corex.blade.php          @include the modal partial
resources/views/layouts/corex-app.blade.php      @include the modal partial
resources/views/layouts/corex-sidebar.blade.php  System Developer → System Updates; What's New
database/schema/mysql-schema.sql                 re-dumped from the TEST db (#12a)
.ai/CHAT_STARTER.md                              status move on landing
```

### Schema snapshot — repaired in this ticket, not authored by it

The committed `database/schema/mysql-schema.sql` was **corrupt on arrival** (it came
in with the `origin/Staging` merge, commit `3b3879d2`) and blocked every
`RefreshDatabase` test in the repo, not just this feature's:

1. `communications.body_display` was declared **twice** in one `CREATE TABLE` — a
   merge artifact of two dumps taken from different MySQL versions (one line
   carried `CHARACTER SET utf8mb4`, the other did not). `mysql` refused the file
   with `ERROR 1060 Duplicate column name`.
2. The snapshot's `migrations` INSERT recorded **138** of the **163** July 2026
   migrations while its schema already contained those migrations' columns, so
   Laravel replayed the 25 unrecorded ones on top and died on
   `Duplicate column name 'is_assistant'`.

Fixed by regenerating the snapshot from a clean `migrate:fresh` on the **test**
database (`DB_DATABASE=hfc_dash_test`, per the standing rule that `schema:dump` must
never read the stale dev DB). Verified afterwards: zero duplicate columns across all
455 tables, and 163 recorded migration rows matching 163 migration files.

**Not modified, deliberately:** `config/corex-permissions.php` (§10),
`config/corex-features.php` (§6.3), `config/agency-onboarding-copy.php` (§14).

---

## 18. Decisions on the record

| # | Decision | Made by | Date |
|---|----------|---------|------|
| 1 | **No audience targeting at all — every update goes to every user.** An Everyone / Admins-only switch was drafted and then removed before promotion: anything that can address a subset can silently address nobody, which is the failure this feature exists to prevent | Johan | 2026-07-26 |
| 2 | Module named **System Updates**, not "New Feature" — it also carries fixes and improvements | Johan | 2026-07-26 |
| 3 | Three fixed types: New Feature / Improvement / Fixed | Johan | 2026-07-26 |
| 4 | Modal cap of 5 per sitting, overflow to the archive | Johan | 2026-07-26 |
| 5 | User-facing **What's New** archive page is in scope | Johan | 2026-07-26 |
| 6 | No Setup Wizard entry — feature adds no agency setting | Johan | 2026-07-26 |
| 7 | Users never see updates published before their own account existed | Andre (spec) | 2026-07-26 |
| 8 | No grantable permission key — `owner_only` only | Andre (spec) | 2026-07-26 |
| 9 | No `agency_id` — global product data, documented exception to #7 | Andre (spec) | 2026-07-26 |
| 10 | Type vocabulary hardcoded in `config/system-updates.php` — never a settings table, never agency-editable | Johan | 2026-07-26 |

### Still open

Nothing. Spec is signed off and buildable.
