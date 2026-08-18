# MIC — Property Row Comments

**Spec ID:** `mic-property-row-comments`
**Date:** 2026-08-18
**Status:** DRAFT — awaiting conductor approval. No code written against this spec yet.
**Related:** `.ai/specs/mic-complete-spec.md` (§2.1 Tracked Property spine, §2.4 domain events, §12 permissions)
**Branch:** `qa1-deal-external-guard` (QA1 only)

---

## 0. Business requirement (Johan's own words)

> "on MIC I specced that a user can give comments on the properties there — it
> was somewhere in the claim screen, which I did not like. On the actual
> property line on the screen the comment button can sit there, which opens a
> modal where comments can be made, and if someone made a comment it shows the
> comments icon with a numeric value on how many comments are in there — so
> users can see what other users said about that specific property."

A comment button on the MIC property row. A badge with the comment count when
there are any. Click opens a modal listing existing comments (author, when,
what) and lets the user add one. Purpose: cross-agent visibility on a specific
property.

---

## 1. What already exists (Phase 1 investigation findings)

There is **no existing normalized "comments" feature** anywhere in the
codebase (grepped `app/Models`, `app/Http/Controllers`, `database/migrations`,
all `.ai/specs/*.md` — the only "comment" hits are `deal_step_comments`
(DR2 deal pipeline — a different pillar, per-deal-step, not property-related)
and code comments).

What Johan is remembering **does** exist, and is exactly what he described —
"somewhere in the claim screen":

- **Where:** `resources/views/corex/market-intelligence/_slideover-header.blade.php:154-231`
  — an "Add note" button, visible only when `$claim && ($claimedByMe ||
  $isManager)`, i.e. only inside the **claim detail slide-over** (you have to
  open a listing's detail panel, not the row) and only if you own the active
  claim or are a manager.
- **Storage:** `POST /corex/market-intelligence/{listing}/note` →
  `MarketIntelligenceController::addNote()` (line 2690) → appends a
  timestamped line into `ProspectingClaim::notes` — a **single free-text
  column**, not a comments table — via
  `ProspectingClaimService::recordActionOnClaim()`. Every note for that claim
  lives concatenated inside one string field.
- **Scope:** keyed to `prospecting_claims.prospecting_listing_id` (via
  `loadClaimNotesTimeline()` in `PropertyIntelligencePanelService.php:321`,
  which does pull every past claim's notes for that *listing*, not just the
  active one) — **not** to `tracked_property_id`. If the same real-world
  property gets re-captured under a new portal ref (a new
  `prospecting_listings` row, same `tracked_property_id`), this history does
  not follow it.
- **Display:** merged into the Activity tab's chronological timeline
  alongside pitch history (`_slideover-tab-activity.blade.php`) — a log, not
  a comment list. No author/comment identity beyond a name string baked into
  the text. No badge/count anywhere on the row.
- **Permission:** claim owner OR `prospecting_setup.manage`. Not "every user
  who can see MIC."

This is a plausible match for "it was somewhere in the claim screen, which I
did not like" — it's buried behind opening a listing's detail slide-over,
gated to whoever owns the claim, and structurally a log entry, not a comment.

**Per the task instructions, this surface is left exactly as-is.** Nothing
changes in `_slideover-header.blade.php`, `addNote()`, or
`ProspectingClaimService`. The new feature is additive.

### 1.1 `property_notes` — wrong home, and why

`app/Models/PropertyNote.php` / `property_notes` table exists and is used by
`SellerOutreach\EntryPointController` and `CoreX\DeedsCaptureController`. It
is keyed to `properties.id` — the **agency's own stock** pillar table. The
overwhelming majority of MIC canvass-pool rows are prospects that have never
been promoted to `properties` (they're `TrackedProperty` /
`ProspectingListing` rows). Keying comments to `properties.id` would make the
comment button non-functional for almost every row in MIC. Wrong entity.

### 1.2 The right entity: `tracked_property_id`

`tracked_properties` is the enduring spine (CLAUDE.md Non-negotiable #10,
mic-complete-spec §2.1) — one row per real-world property regardless of how
many portal listings, claims, or relistings have touched it over time.
`prospecting_listings.tracked_property_id` links every canvass-pool row to
one. Keying the new comment table to `tracked_property_id` (not
`prospecting_listing_id`, not `properties.id`) is what makes "so users can
see what other users said about that specific property" literally true —
comments survive relisting, claim churn, and portal-ref rotation, exactly
like the address history in `tracked_property_addresses` already does.

---

## 2. Data model

### 2.1 New table `tracked_property_comments`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `agency_id` | FK → agencies, cascade | `BelongsToAgency` — multi-tenancy, absolute isolation |
| `tracked_property_id` | FK → tracked_properties, cascade | |
| `user_id` | FK → users | Author |
| `body` | text | 3–1000 chars (matches the existing claim-note bounds for consistency) |
| `edited_at` | timestamp nullable | Set when the author edits; null if never edited |
| `created_at` / `updated_at` / `deleted_at` | | Soft delete only — CLAUDE.md Non-negotiable #1 |

Indexes:
- `(agency_id, tracked_property_id, deleted_at)` — the badge-count and
  modal-list query
- `(agency_id, user_id)` — "my comments" / ownership checks

### 2.2 Model

`App\Models\Prospecting\TrackedPropertyComment` (same namespace as
`TrackedProperty`) — `use BelongsToAgency, SoftDeletes;`, `belongsTo(User)`,
`belongsTo(TrackedProperty)`.

### 2.3 Relation on `TrackedProperty`

```php
public function comments(): HasMany
{
    return $this->hasMany(TrackedPropertyComment::class)->latest();
}
```

---

## 3. Zero-N+1 comment count (row badge)

The row list (Work tab, `ProspectingListing` rows keyed by
`tracked_property_id`) already has a precedent for exactly this shape of
problem: `MarketIntelligenceController::work()` builds `$companyStockMap`
(listing_id → property_id) as **one batch query for the visible page**,
passed into the view (`companyStockMap` in the `compact()` list, line 1107),
and `_listing-row.blade.php` reads it as a plain array lookup — no
per-row query.

The comment badge follows the identical pattern:

```php
$tpIds = collect($listings->items())->pluck('tracked_property_id')->filter()->unique()->values();
$commentCounts = \App\Models\Prospecting\TrackedPropertyComment::query()
    ->whereIn('tracked_property_id', $tpIds)
    ->where('agency_id', $agencyId)
    ->whereNull('deleted_at')
    ->select('tracked_property_id', DB::raw('count(*) as cnt'))
    ->groupBy('tracked_property_id')
    ->pluck('cnt', 'tracked_property_id');
```

One extra query per page load, regardless of row count. Passed into
`_listings.blade.php` → `_listing-row.blade.php` as `$commentCounts`,
read as `($commentCounts ?? [])[$listing->tracked_property_id] ?? 0`.

The Opportunities tab (`MarketIntelligenceController::opportunities()`)
already uses `->withCount([...])` directly on the `TrackedProperty` query
(e.g. `strong_match_count`, line 1219) — see §6 for why that surface is
scoped OUT of V1 rather than extended the same way.

---

## 4. UI — INVESTIGATE → COPY → ADAPT

No new UI convention is invented. Two existing local patterns are copied:

1. **The row's existing outline-chip buttons** (`_listing-row.blade.php`,
   e.g. "Property intel →" at line 241-249, `presented` at line 229-236) —
   `$tagOutline` style, icon optional, `onclick="event.stopPropagation()"` so
   it doesn't trigger the row's own slide-over click handler. The new comment
   control is one more chip in that same row (line-3 tag area, which is
   NOT hidden for company-stock rows, unlike the icon-button cluster on the
   far right) — a speech-bubble icon, with the count appended only when > 0
   (`💬` replaced by inline SVG per the row's existing "no emoji, line icons"
   convention — see `_slideover-activity-entry.blade.php` header comment).
   Rendered only when `$listing->tracked_property_id` is set (same guard the
   Property-intel chip already uses) and only when the viewer holds
   `mic.comments.view`.

2. **The row's existing inline modal** (`_slideover-header.blade.php:179-231`,
   the "Add note" modal) — Alpine `x-show` / `x-cloak` overlay, `fetch()`
   POST with the CSRF meta tag, JSON response, no page reload. The new
   Comments modal reuses this exact shell (backdrop, card, escape-to-close,
   click-outside-to-close) but is a **list + add** modal instead of an
   add-only box:
   - On open: `GET /corex/tracked-properties/{trackedProperty}/comments`
     (JSON) populates the list.
   - Each comment renders via a new partial modeled on
     `_slideover-activity-entry.blade.php` (icon + text + `actor · time`
     meta line) — same visual language as the Activity tab entries, so the
     new modal doesn't look like a bolt-on.
   - Add box at the bottom: textarea + submit, same validation bounds
     (3–1000 chars) as the existing claim-note field.
   - Author's own comment gets an inline Edit (textarea swap) and Remove
     (confirm dialog) control. Any comment gets a Remove control if the
     viewer holds `prospecting_setup.manage` (existing MIC admin-tier
     permission — reused, not duplicated; see §5).

New files:
- `resources/views/corex/market-intelligence/_comments-modal.blade.php`
- `resources/views/corex/market-intelligence/_comment-entry.blade.php`

---

## 5. Permissions

Per the task's decisions (agency-wide visibility, permissioned per
Non-negotiable #5):

| Key | Label | Section/module | Granted by default |
|---|---|---|---|
| `mic.comments.view` | View Property Comments | prospecting / mic | agent, branch_manager (admin/super_admin inherit via existing all-minus-exclude rule) |
| `mic.comments.add` | Add Property Comments | prospecting / mic | agent, branch_manager |

**Removing someone else's comment** does not get a third permission key —
it reuses the existing MIC admin-tier key `prospecting_setup.manage`
(already gates merge-duplicates and the team dashboard). Consistent with the
catalogue's existing pattern of layering `mic.*` action keys on top of the
module's existing admin gate rather than inventing a parallel one.

**Removing/editing your own comment** is an ownership check in the
controller, not a permission gate — any user who can add a comment can edit
or remove their own (per the task's explicit decision).

Both new keys registered in `config/corex-permissions.php` (§`permissions`
array, `module => 'mic'`, `section => 'prospecting'`, next `sort_order`
57/58) and added to the `agent` and `branch_manager` `include` blocks in
`role_defaults`, mirroring exactly how `mic.edit_address` /
`mic.upload_reports` are already included for those roles.

**Setup Wizard (Non-negotiable #10a):** does not apply. This is a permission
gate, not an agency-configurable setting (no toggle/threshold/template an
agency owner would tune) — recorded here as a deliberate non-inclusion, not
an oversight.

---

## 6. Scope decision needing your confirmation

The Work tab's `_listing-row.blade.php` is a single `<article>` with a
right-hand icon-button cluster designed for exactly this kind of inline
control (`event.stopPropagation()` already solved there). Adding the comment
chip there is a contained, low-risk change.

The **Opportunities tab** row (`opportunities-list.blade.php:31-93`) is
structured differently: the **entire row is one `<a href="...">` wrapping
everything** — there is no inline button slot, and dropping a `<button>`
inside an `<a>` risks double-navigation on click. Making it work there means
restructuring that row's markup (splitting the anchor), which is a second,
independently-risky change outside "one concern only."

**Recommendation:** ship the row-level comment button + badge on the **Work
tab only** for this task (it's also the surface hosting the claim-screen
feature Johan disliked). Opportunities tab is a clean fast-follow once this
lands, using the identical `withCount()` mechanism already in
`opportunities()` (line 1219-1224) — no new pattern needed there either,
just wiring.

**I need your go-ahead on this scoping before I write code** — ship
Work-tab-only now, Opportunities as a separate follow-up task?

---

## 7. Domain event

`mic-complete-spec.md` §2.4 states as a hard MIC-wide principle: "every
action emits a domain event." Address edits already do this
(`TrackedPropertyAddressVerified` etc., §7.5). A comment add is a meaningful
MIC action under that existing, already-approved principle — so this spec
includes firing `App\Events\Prospecting\TrackedPropertyCommentAdded` on
create (no listeners yet, same as every other MIC event — lands for Phase 5
per §2.4). This is compliance with an existing architectural mandate for
this module, not a new addition to scope.

---

## 8. Files to create / modify

**Create:**
- `database/migrations/2026_08_18_*_create_tracked_property_comments_table.php`
- `app/Models/Prospecting/TrackedPropertyComment.php`
- `app/Events/Prospecting/TrackedPropertyCommentAdded.php`
- `resources/views/corex/market-intelligence/_comments-modal.blade.php`
- `resources/views/corex/market-intelligence/_comment-entry.blade.php`
- `tests/Feature/MarketIntelligence/TrackedPropertyCommentsTest.php`

**Modify:**
- `app/Models/Prospecting/TrackedProperty.php` — add `comments()` relation
- `app/Http/Controllers/CoreX/TrackedPropertyController.php` — add
  `comments()` (index, JSON), `storeComment()`, `updateComment()`,
  `destroyComment()`
- `routes/web.php` — 4 new routes under the existing
  `corex/tracked-properties/{trackedProperty}/...` group (permission-gated
  per §5)
- `app/Http/Controllers/CoreX/MarketIntelligenceController.php` — `work()`
  gains the `$commentCounts` batch query (§3), passed to the view
- `resources/views/corex/market-intelligence/_listing-row.blade.php` — new
  chip (§4)
- `resources/views/corex/market-intelligence/_listings.blade.php` — thread
  `$commentCounts` into the `_listing-row` include
- `config/corex-permissions.php` — 2 new keys + role_defaults includes (§5)
- `database/schema/mysql-schema.sql` — regenerated after the migration
  (Non-negotiable #12a)

**Explicitly NOT touched:** `_slideover-header.blade.php`, `addNote()`,
`ProspectingClaimService`, `ProspectingClaim` model/table, anything under
`opportunities-list.blade.php` (pending §6 answer), anything in
deeds-capture or calendar (other lanes' territory per this task's brief).

---

## 9. Test plan (BUILD_STANDARD §5 — input-space, not happy-path theatre)

Single most relevant file only per Non-negotiable #13:
`tests/Feature/MarketIntelligence/TrackedPropertyCommentsTest.php`.

- Happy path: add a comment, it appears in the list with author + timestamp,
  badge count increments by 1.
- Zero comments: icon renders, no numeric badge.
- Several comments: badge shows the correct count; list shows all, newest
  first.
- Edit own comment: body updates, `edited_at` set, count unchanged.
- Remove own comment: soft-deleted (`deleted_at` set, row still in DB), badge
  count decrements.
- Remove someone else's comment as a plain agent (no
  `prospecting_setup.manage`): 403, comment still visible.
- Remove someone else's comment as a `prospecting_setup.manage` holder:
  succeeds.
- Empty body / whitespace-only body: rejected with a clear message, no 500.
- Over-length body (>1000 chars): rejected, no 500.
- Agency isolation: agency B's user hitting agency A's `tracked_property_id`
  gets 404, sees zero of agency A's comments even if IDs are guessed.
- No `mic.comments.add` permission: add attempt 403s; view still works if
  `mic.comments.view` is held.
- Deleted-tracked-property-relation path: N/A (comments cascade-delete with
  their parent TP per the FK; no orphan-render case exists for this table).

---

## 10. Open question for the conductor (not Johan)

§6 above — Work-tab-only for this task, Opportunities as a follow-up? I'll
proceed on that basis unless told otherwise, since waiting doesn't change the
answer to anything else in this spec and the Work-tab implementation is
identical either way.

Everything else in this document is a decision, not a question — stated so
you can veto/redirect before I write code, per the spec-first rule.
