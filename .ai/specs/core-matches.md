# Core Matches — Board Rebuild (2026-09-14)

Johan set a design standard specifically so he would stop having to ask
for it: full search, sort, filter, pagination, a real empty state, and
OWN / BRANCH / AGENCY scoping enforced at the query layer, on every list
screen (`.ai/BUILD_STANDARD.md` §1). Core Matches predates that standard
and never got it — this is that rebuild.

Two lanes, one spec, divided by section so neither rewrites the other's
work: cc3 owns the screen (controller query + Blade views) — the
sections below. cc4 owns the data layer (new columns, lead attribution,
reassignment mechanism, settings, removal event) — their own sections
follow, appended separately.

---

## Screen & scoping

### The permission-gate mismatch, reported not fixed

Four routes reach this screen (`routes/web.php:4016-4042`) —
`corex.core-matches.index`, `corex.core-matches.all`,
`corex.rentals.core-matches.index`, `corex.rentals.core-matches.all` —
and they carry **three different permission keys**, not the two
originally described: `access_contacts` (borrowed from the Contacts
module, gating the sales-side index route), `core_matches.view` (gating
the rentals-side index route), and `access_core_matches` (Core Matches'
own dedicated module-access key — defined, but until now used only
inside the view to gate the Edit button, never to gate either entry
route at all).

Checked how the Contacts module handles the identical shape of problem
(a sale-side and a rentals-side entry point into the same screen):
`corex.contacts.index` and `corex.rentals.contacts.index` both gate on
`access_contacts`, consistently. **Recommendation: both Core Matches
index routes should gate on `access_core_matches`**, matching that
established convention, rather than the current mismatched pair. NOT
changed here — route middleware is left exactly as it was, pending the
conductor's decision.

### One screen, not two — the consolidation

`ContactMatchController::index()` and `allView()` are now both thin
wrappers around one private `renderBoard()` method, which builds one
query and returns one view (`corex.core-matches.index` — `all.blade.php`
deleted, nothing referenced it once the two paths merged). This is not a
new idea: `BUILD_STANDARD.md` §1c already states visibility level is
"permission-driven, decided at spec time per screen" — a single screen
with a scope control, not two hand-maintained ones.

Both existing URLs (`/core-matches`, `/core-matches/all`) keep working
as bookmarks — they hit the same code, differing only in which scope
they default to (`own` vs the widest scope the viewer actually holds).
Route middleware is unchanged (see above), so today's permission
boundary at the HTTP layer still applies exactly as before; what changed
is that **everything downstream of "the request reached this method" is
now driven by an explicitly resolved `$scope`, not by which URL got you
there.**

### Scope resolution — enforced at the query layer, not trusted from the request

```
$availableScopes = ['own'];
if ($canSeeAll)      { if ($splitOn && $branchId) $availableScopes[] = 'branch'; $availableScopes[] = 'agency'; }
$requestedScope = $request->query('scope', ...);
$scope = in_array($requestedScope, $availableScopes, true) ? $requestedScope : 'own';
```

A user without `core_matches.all_view` requesting `scope=branch` or
`scope=agency` — by URL, by hand, however it arrives — silently narrows
to `own`. This is a **visibility floor, not an authorisation wall**: the
same precedent already used one line away for the agent-id filter
("ignored if outside the viewer's scope"), not a 403. The `/all` routes'
own middleware (`core_matches.all_view`, unchanged) is the actual 403
backstop for someone hitting that URL directly with no permission at
all — confirmed in verification below.

**`branch` is only ever offered when the agency has branch-split
enabled AND the viewer has a branch** — for a single-branch agency,
`branch` and `agency` are the same thing, and offering both would be a
distinction with no difference.

### The ContactScope trap, and the deeper BranchScope collision it led to

`Contact` carries its own, unrelated visibility scope (`ContactScope` —
the Contacts module's own role-based own/branch/all, driven by a
DIFFERENT permission, `contacts` data-scope, not `core_matches.all_view`).
Building the contact-level query naively (`Contact::whereHas('matches',
...)`, or eager-loading `contact` on a `ContactMatch` query) would
silently re-apply that unrelated scope on top of the Core Matches one —
meaning a manager granted `core_matches.all_view` could still only see
whatever their PERSONAL Contacts-module visibility happened to allow,
defeating the oversight permission entirely and unpredictably (it would
"work" for a manager whose contacts-scope happens to be `all`, and
silently under-report for one whose isn't).

First fix attempt bypassed `ContactScope` on `Contact` and, once the
conductor's "hunt the same class" follow-up surfaced that `ContactMatch`
ALSO carries `BranchScope` (via `BelongsToBranch` — the code's own
original comment claiming "no extra constraint needed" was wrong), added
a `withoutGlobalScope(BranchScope::class)` call inside the same
`whereHas()`/match-constraints closures. That looked right and passed
`removedScopes()` inspection on the closure's own builder — and still
failed: against an isolated two-branch test agency built specifically
because HFC's real data has `split_branches_enabled=false` and can't
exercise this path, `scope=agency` still excluded a contact whose only
qualifying match lived on the other branch.

Root cause, confirmed empirically (a controlled A/B query, not a guess):
**`withoutGlobalScope()` called inside a `whereHas()` / `withExists()` /
`withMax()` closure registers on that closure's own builder instance but
does not reliably propagate to the final compiled SQL** — the scope's
filter still applies in the query Laravel actually runs. The identical
bypass called directly on a fresh, top-level query works every time. This
is a general Eloquent-internals limitation, not specific to this screen,
and worth carrying forward: never bypass a global scope from inside a
relation-constraint closure; only ever on a top-level `Model::query()`.

`Contact` turned out to carry `BranchScope` too (via the same
`BelongsToBranch` trait), a second instance of the identical collision on
the identical model — caught by the same isolated-agency test still
failing after the first patch.

**The actual fix** replaced every nested scope-bypass attempt with a
two-step, all-top-level query shape:

1. `$qualifyingContactIds = ContactMatch::query()->tap($matchConstraints)
   ->pluck('contact_id')->unique()->values();` — `$matchConstraints`
   bypasses `BranchScope` on this fresh top-level `ContactMatch` query
   when `$scope !== 'own'`.
2. `Contact::query()->withoutGlobalScope(ContactScope::class)
   ->withoutGlobalScope(BranchScope::class)->whereIn('id',
   $qualifyingContactIds)` — both bypasses on a fresh top-level `Contact`
   query, never inside a closure.
3. The status-priority and most-recently-saved sorts, which previously
   used `withExists()`/`withMax()` (the exact closure shape that doesn't
   propagate a nested bypass), were rewritten as `addSelect()` correlated
   subqueries — each one a fresh top-level `ContactMatch::query()
   ->tap($matchConstraints)->whereColumn('contact_id', 'contacts.id')`
   rather than a relation-closure method.

Re-verified against the isolated cross-branch agency (agency scope now
correctly shows the cross-branch contact, branch scope correctly excludes
it) and re-ran the full HFC verification battery below with no
regression. An ordinary agent's own board (`scope === 'own'`) is left
entirely under Contacts' and Branch's normal visibility rules, unchanged.

### Search / sort / filter / pagination

- **Search**: contact name, phone, email — the fields an agent actually
  recognises a buyer by. Criteria fields (price, suburb, beds) are
  filters, not search.
- **Filters**: listing type (existing lock/toggle, unchanged), status
  (new — the field already existed in data but was never exposed),
  saved-date range, agent (manager scopes only, existing).
- **Sort**: default is the existing deliberate status-priority order
  (active > paused > fulfilled > expired), now expressed at the
  CONTACT level via `withExists()` on each status tier in turn (a
  contact with at least one currently-visible active match outranks one
  with only paused matches, etc.) — reusing the SAME filter closure the
  contact query and the match list use, so a contact can never rank as
  "has an active match" because of a match the current filters have
  hidden. Two selectable alternates: most-recently-saved
  (`withMax` on `created_at`), and longest-since-contact (`Contact.
  last_contacted_at` ascending, nulls — never contacted — floating to
  the top, since that's the manager's actual use case: finding
  neglected buyers).
- **Pagination**: 25 per page, paginated at the CONTACT level (not the
  raw match list) — a contact's matches are never split across a page
  boundary, and the page size genuinely bounds something on a 150+-row
  agency (Johan's own figure). Matches are then loaded only for the
  current page's contacts, not the whole board.
- **Empty states**: two distinct messages, verified as genuinely
  distinct in testing — "nothing saved yet" (with a call to action) only
  when there are zero matches AND zero filters applied; "nothing matches
  this filter" (no call to action, since matches exist, the filter is
  just narrow) whenever a filter is active and returns nothing.

---

## Row layout — the tension with Johan's screen rule, resolved deliberately

Johan's own words, carried into every decision below: *"you keep
building screens where the real estate is lost to nice parts instead of
functional parts."* Every element below was placed in one of three
buckets on purpose, not stacked in because Task 2 asked for it.

**Permanent, on every match row (the reason the board exists):** the
criteria chips (price/suburb/type/beds/baths/garages), match counts
(total/visible/hidden — already correctly followed the "only appears
when unusual" rule before this rebuild: hidden only shows when >0, kept
exactly as-is).

**Moved from per-match-row to the contact header, shown ONCE per
contact, never once per search:** last-contact and notes. Both are
facts about the CONTACT, not the search — a contact with three saved
searches would otherwise show the identical `last_contacted_at` three
times, which is literally Johan's own named failure mode ("never the
same fact twice"). Last-contact renders as ONE relative value
("Contacted 3d ago"), with the absolute timestamp only in the hover
title — not both visibly at once. Notes render as a small, count-gated
link straight to the contact's notes tab (absent entirely at zero
notes) — reachable in one click, never inline-dumped text bloating the
row.

**Only appears when it's actually informative, never as a
constant-state badge:** "Assigned to" is suppressed entirely on the
`own` scope — on that scope it's always the viewer, on every row, which
is Johan's exact "badge that's always lit carries no information" case.
It only earns space once Branch/Agency scope is selected, where it
genuinely varies row to row. "Who received it first" (once cc4's column
exists) is shown ONLY when it differs from who the match is currently
assigned to — i.e., only when a reassignment has actually happened.
When they're the same person, showing both is the same fact twice with
extra steps.

**Type pill**: kept, but suppressed entirely when the whole board is
already locked to one listing type (the Rentals entry point, or the
sale/rental toggle) — a pill reading "Rental" on every single row when
the board is already all-rentals is the same always-lit-badge problem
applied to a different field.

**New, permanent, compact — Johan's own addition, and correctly weighted
as such:** the "properties the lead(s) came in on" line, once per
contact (it's a fact about the buyer's whole enquiry history, not any
one saved search), styled to match the EXISTING suburb-pin convention
already in the row (small icon + short compact list + a "+N more"
overflow) rather than a new visual pattern, full addresses, or embedded
photos. Sourced directly from `PortalLead::listing()` — the actual
relation name confirmed against the model (NOT `property()`, which was
tried first, threw `RelationNotFoundException`, and was caught by
verification below before it shipped) — grouped and deduplicated per
contact, not gated on cc4's work at all.

**Held pending cc4's columns, guarded so they activate automatically
the moment those columns land (`Schema::hasColumn()`/`Schema::hasTable()`
checks — the SAME idiom already used elsewhere in this controller for
`convertToDeal()`'s optional `deals` columns), never displayed before
then:** the assigned-agent name (`contact_matches.agent_id`), the
first-received fact (`portal_leads.received_by_user_id` /
`received_at`), and the working-window figure
(`core_match_settings.working_window_days`). None of these required
waiting idle — the guard means this ships now and switches on by
itself later, with no follow-up change needed here.

**Deliberately not resolved yet, flagged for whoever finishes this:**
the existing per-row "Saved" date may become redundant once "received
first, with time" exists (arguably the same event, two labels) — held
open rather than guessed, since removing it now would be guessing at a
column that doesn't exist yet.

---

## Verification

Real, not assumed — every check below run against a genuine local HTTP
server (`php artisan serve`), a real minted session cookie, and a
headless-but-real Puppeteer fetch of the actual rendered response body
(never an in-process controller call).

**A real bug caught by this process, not by inspection:** the
"properties the lead came in on" feature initially called
`PortalLead::property()`, which doesn't exist (the real relation is
`listing()`) — this produced a clean `php -l` pass and a 500 in the
actual rendered page, caught only because the positive-case scoping
proof below was run as real HTTP against a real logged-in manager, not
skipped as "obviously fine" once the negative case passed.

**Scoping — the crafted-request proof the task explicitly required, not
assumed from the UI.** A disposable `viewer`-role user (this test
agency's one role that genuinely lacks `core_matches.all_view`,
confirmed against `role_permissions` directly rather than assumed —
every other role in this agency's live data happens to hold it, which
would have made the negative case untestable against an existing user)
was created, used, and soft-deleted after:

| Request (as the low-privilege user) | Result |
|---|---|
| `/corex/core-matches` (baseline) | 200, own matches only |
| `/corex/core-matches?scope=agency` (crafted) | 200, silently narrowed — no other agent's data in the raw response |
| `/corex/core-matches?scope=branch` (crafted) | 200, same |
| `/corex/core-matches/all?scope=agency` (crafted, hitting the manager-only URL directly) | **403** — the existing, untouched route middleware still holds as the hard backstop |
| `/corex/core-matches?agent_id=<another agent>` (crafted, while own-scoped) | 200, ignored — agent filter only applies once scope leaves `own` |
| `/corex/core-matches/all?scope=agency&q=<a contact only visible under agency scope>` as a REAL manager | 200, contact present — confirms the positive case actually works, not just that the negative case is locked down |
| The identical crafted URL as the low-privilege user | 200, contact absent |

Checked the raw response body for the other agent's contact name in
every case (Standard −1n — raw HTML, not what renders visually), not
just whether the page "looked" restricted.

**Pagination — proven real, not assumed from a page-size number.**
Fetched page 1 and page 2 of the agency-wide board, extracted every
contact name from each, and confirmed zero overlap (25 distinct
contacts per page, none repeated) — proving the page boundary actually
partitions the result set rather than silently re-showing the same data
or dumping everything regardless of the `page` parameter.

**Empty states — both variants confirmed distinct, not just present.**
A filter guaranteed to return nothing showed "no results for this
filter" specifically, with the "nothing saved yet" call-to-action
copy confirmed ABSENT in that case (the two states share a card but
must never share wording).

**`php -l`**: clean on the controller and the view. **`php artisan
view:clear`**: run before every fetch, so no stale compiled view could
mask or fake a result. **The render gate
(`fetch-authenticated-page.php` + `verify-alpine-render.mjs`)**: this
view has no Alpine directives at all, so that specific gate's scope
does not apply — verified instead via the real-HTTP-fetch process
above, which is the broader principle that gate exists to enforce
(never trust an HTTP 200 alone).

---

## Data layer, settings, reassignment (cc4)

*(cc4's sections — appended separately, not duplicated here.)*
# Core Matches expansion — AT-Core-Matches

> Split build: cc3 owns the screen (controller rendering, query, row layout —
> see cc3's sections once landed), cc4 owns the data layer (this document's
> sections below). Both land through cc1 as one combined piece.

## Why

Johan found a real agent-poaching risk in Core Matches: a buyer could sit on
more than one agent's board at once with no record of who received the lead
first, no server-enforced rule about who may move a buyer between agents,
and no distinction between a buyer who's gone quiet and one who's actually
being worked. Six rulings, all now the spec:

1. Only a branch manager or admin can move a buyer between agents. Ever. An
   agent can never reassign a buyer, including to themselves, by any route.
2. A note added, the Last Contacted button, a message sent, and a live link
   shared all reset the working clock.
3. The board is OPEN — assigned agent, first-lead time, last contact, and
   contact notes are visible to everyone.
4. Live links stay LIVE for the buyer — one permanent link, always current
   stock. All share history is INTERNAL.
5. The working window is an agency SETTING, default 7 days, never hardcoded.
6. The buyer stays on the board of every agent who received the lead, and
   the board shows WHO GOT IT FIRST.

## Current-state audit (before this build)

Full findings from the pre-build investigation — six independent forks,
verified against `origin/QA1`, not the spec's own claims:

- `/corex/core-matches` and `/corex/rentals/core-matches` are ONE component
  (same `ContactMatchController::index()`/`allView()`), not two drifted
  builds — distinguished only by route name.
- `ContactMatch` had no `agent_id` — ownership inferred only from
  `created_by_user_id`, no duplicate check against `contact_id`, no
  uniqueness constraint. Two agents could independently hold a match on the
  same buyer with nothing to notice.
- `portal_leads.received_at` already held the portal's own reported time on
  the two pull paths (P24, PP pull); the PP real-time webhook path had no
  queryable timestamp or agent at all — PP's own `leadDateTime` only ever
  landed inside a free-text Contact note.
- No agent_id existed on `portal_leads` at all — per-agent attribution was a
  live join to the property's CURRENT agent (silently rewritable if the
  listing changes hands) for a new contact, or a frozen
  `existing_contact_agent_id` for a returning one.
- A shared Core Matches live link stores only a token + the match's
  criteria, never a property list — every open re-runs a live query. No
  record exists anywhere of what a buyer was actually shown at share-time.
- Soft-deleting the match was the only way it left either screen. The
  existing `status` enum (`active`/`paused`/`fulfilled`/`expired`) is a sort
  key only — nothing filters on it.
- Buyer Pipeline's "Lost" transition fired no event at all — a quiet
  `updateQuietly()` column update plus two audit-table inserts.
  `corex-domain-events-spec.md` documented a `ContactBuyerStatusChanged`
  event that was never built, watching a column (`buyer_status`) that isn't
  the real one (`buyer_state`) — corrected in this build, see below.
- No settings surface existed for Core Matches at all.

## Data model (cc4)

### `contact_matches` — two new columns

- **`agent_id`** (nullable FK → `users`, `nullOnDelete`) — the OWNING agent.
  The one field `ContactMatch::reassignTo()` moves. Distinct from
  `created_by_user_id` (untouched — "who clicked create", historical).
  Backfilled for existing rows as `agent_id = created_by_user_id` — not a
  reconstruction, `created_by_user_id` is already a directly-recorded fact
  for every existing row, just not previously labelled as the owning agent.
- **`set_aside_at`** (nullable timestamp) — set when the buyer's Buyer
  Pipeline state moves to `lost`, cleared automatically when it moves off
  `lost` (ruling 6, Task 6: "set aside, never deleted, comes back if the
  buyer does"). Not a new `status` value — that enum is a sort key only,
  untouched. Screen query is expected to exclude `WHERE set_aside_at IS NOT
  NULL` by default; `scopeNotSetAside()`/`scopeSetAside()` are provided.

New relations: `agent()` (the owning agent), `reassignments()` (latest
first), `shares()` (latest first).

### `portal_leads` — one new column, populated on all three ingestion paths

- **`received_by_user_id`** (nullable FK → `users`, `nullOnDelete`) — the
  agent who received THIS lead, frozen at arrival. Same resolution on all
  three paths: the existing contact's own agent for a returning enquirer
  (identical fact to `existing_contact_agent_id`), the listing's agent at
  that exact moment for a brand-new one. No backfill on existing rows —
  there is no honest way to reconstruct who received an old lead; existing
  rows keep answering the old way.
- To find "who got it first" for a contact:
  `PortalLead::where('contact_id', $id)->orderBy('received_at')->first()`.
- **The PP real-time webhook path** did not create a `portal_leads` row at
  all — it's mirrored into one by `CommandTaskPortalLeadObserver` (triggered
  off `CommandTask::created`, filtered to
  `source_type='private_property_webhook'`), a mechanism the original
  investigation missed entirely (it only checked `PpWebhookController.php`
  itself for `PortalLead` references, found none, and wrongly concluded no
  row was ever created). `received_by_user_id` is now populated there too.
  **Known limitation, not fixed here:** that observer's `received_at`
  remains the CommandTask's `created_at` (our processing time), not PP's own
  `leadDateTime` — the observer only ever sees the reconstructed
  `CommandTask` + `Contact`, never the raw webhook payload, and getting the
  real portal timestamp through would mean either a new column on the
  generic `command_tasks` table or restructuring the controller/observer
  relationship to create the `PortalLead` directly there instead (which
  would also mean retiring the observer's own duplicate-creation logic and
  its buyer-cascade seeding call — a bigger, riskier change than Task 1's
  stated scope). Flagged rather than forced.

### New table: `contact_match_reassignments`

One immutable row per reassignment (ruling 1, Task 3). SoftDeletes from the
first migration per the standing no-hard-deletes rule, though never expected
to need deleting.

| Column | Notes |
|---|---|
| `agency_id` | direct column, required by `BelongsToAgency` |
| `contact_match_id` | FK, cascade on delete |
| `from_agent_id` | nullable FK |
| `to_agent_id` | required FK |
| `moved_by_user_id` | required FK — always a branch_manager/admin, enforced in code, not DB |
| `reason` | required text — Johan's model is the manager has the conversation first, so this is never optional |

Written only through `ContactMatchReassignment::record()`, mirroring
`RentalApplicationStatusHistory`'s append-only pattern.

### New table: `contact_match_shares`

Append-only, INTERNAL-only log (ruling 4: "all share history is internal" —
plural, a log, not a single last-shared timestamp).

| Column | Notes |
|---|---|
| `agency_id` | direct column |
| `contact_match_id` | FK, cascade on delete |
| `shared_by_user_id` | required FK |
| `channel` | nullable enum: copy_link / whatsapp / email / other |
| `shared_at` | timestamp |

Written only through `ContactMatchShare::record()`, which ALSO resets the
buyer's working clock in the same call (see below) — the two are the same
fact from two angles, never recorded separately.

### The reassignment mechanism (ruling 1, Task 3)

`ContactMatch::reassignTo(User $toAgent, User $movedBy, string $reason)`:

1. Throws `ReassignmentNotAuthorizedException` unless
   `$movedBy->hasPermission('core_matches.reassign')` — a NEW permission,
   granted to `branch_manager` + `admin` only (mirrors `contacts
   .reassign_agent`'s existing role placement exactly), never `agent`.
2. Throws `InvalidArgumentException` on an empty reason.
3. Writes the audit row, then updates `agent_id`, inside a transaction.

Server-enforced twice over (BUILD_STANDARD §1c — direct-URL access must be
blocked, not just absent from a menu): the route middleware
(`permission:core_matches.reassign`) AND the model method's own check.

**Route:** `POST /corex/core-matches/{match}/reassign` →
`ContactMatchReassignmentController::reassign`, name
`corex.core-matches.reassign`. One route serves both the sale and rental
screens — the match carries its own `listing_type`.

Deliberately its own controller, not a new method on `ContactMatchController`
(cc3's file, edited in parallel).

### The share-recording action (ruling 4)

`POST /corex/core-matches/{match}/record-share` →
`ContactMatchShareController::record`, name `corex.core-matches.record-share`.
Gated on `core_matches.view` (the same gate as reading the match — sharing
isn't the privileged action, reassignment is). Body: optional `channel`.
Calls `ContactMatchShare::record()`.

### The working clock (ruling 2, Task 4)

No new clock column. Reuses `Contact::last_contacted_at` exactly as found —
it already unifies email + WhatsApp (one `communications` table/model) with
the manual "Last Contacted" button's own `contacted_marked_at`, via
`Contact::recomputeLastContacted()`.

Two NEW triggers added, both calling the existing
`Contact::touchLastContacted()`:

- **A live link shared** — `ContactMatchShare::record()` (new).
- **A note added** — hooked via observer on `ContactNote`'s creation
  (applies to notes generally, not scoped to notes added specifically from
  a future Core Matches screen — narrower scoping would need new
  context-tagging plumbing that's out of scope for this build; flagged as
  an interpretation, not a silent decision).

**"A message sent"** needed no new hook — an outbound, sent communication
already triggers `recomputeLastContacted()` via
`CommunicationSendStatusService`.

**Two caveats carried forward from the original investigation, still true:**
the link from a `communications` row to a `Contact` is a polymorphic pivot
(`communication_links`), not a guaranteed direct foreign key — correctness
depends on identifier-matching having worked, not a guaranteed join.
`PresentationDelivery` and `SellerOutreach\SellerOutreachSend` are separate
communication logs that sit outside `last_contacted_at` entirely — not
folded in here, out of scope for this build.

### The working-window setting (ruling 5, Task 5)

**No new settings table.** Added to the EXISTING `agency_contact_settings`
table/model — it already governs the adjacent Buyer Pipeline day-thresholds
(`buyer_warm_days`/`buyer_cold_days`/`buyer_lost_days`); a second table for
the same kind of fact would be a second source of truth.

- New column: `core_matches_working_window_days` (nullable smallint).
- New constant: `AgencyContactSettings::DEFAULT_CORE_MATCHES_WORKING_WINDOW_DAYS = 7`.
- New resolved-value method: `AgencyContactSettings::coreMatchesWorkingWindowDays(): int`
  (null-safe, clamped 1–90 — same pattern as `buyerKanbanColumnLimit()`).
- Baked into `AgencyContactSettings::forAgency()`'s defaults array for any
  newly-created agency row; an existing row that never re-saves this field
  resolves via the null-safe method instead.
- **Settings screen:** added to the EXISTING Contact Governance settings
  page (`ContactGovernanceController` + `command-center/settings/contact
  -governance.blade.php`) as its own "Core Matches" panel — this page
  already owns the sibling buyer-freshness day-thresholds; a brand-new
  settings page for one field would be exactly the drift this rule exists
  to prevent.
- **Setup Wizard:** NOT added. Its siblings on the same table
  (`buyer_warm_days`/`buyer_cold_days`/`buyer_lost_days`) aren't in the
  wizard either. Adding only the new field while its siblings stay excluded
  would be inconsistent, and CLAUDE.md #10a makes wizard-inclusion
  explicitly Johan's call, not the lane's, when a setting arguably doesn't
  belong in onboarding. **Flagged for a ruling, not decided here** — if he
  wants it in, the sibling three probably should join it in the same pass.

### The removal trigger (Task 6)

Two new domain events, fired explicitly inside
`BuyerStateService::transitionTo()` (its `updateQuietly()` call suppresses
Eloquent model events, so an observer would never fire):

- `Contact\ContactMarkedLostInBuyerPipeline` — fires on transition TO `lost`
  (auto-recompute or manual override). Listener:
  `SetAsideCoreMatchesOnBuyerLost` sets `set_aside_at = now()` on every
  not-yet-set-aside match for that contact.
- `Contact\ContactRestoredFromLostInBuyerPipeline` — fires on transition
  AWAY FROM `lost`. Listener: `RestoreCoreMatchesOnBuyerRestored` clears
  `set_aside_at` on every set-aside match for that contact.

Both registered explicitly in `AppServiceProvider::boot()` (matching the
FICA event pattern — this codebase's dominant convention, not relying on
listener auto-discovery). `corex-domain-events-spec.md`'s stale
`ContactBuyerStatusChanged` entry (never built, wrong column name) is
replaced with these two real ones in the same commit.

### New permission

`core_matches.reassign` — action-type, `core-matches` section, granted to
`branch_manager` + `admin` only (admin via the existing all-minus-exclude
default; `core_matches.reassign` is not on the exclude list). Never granted
to `agent`, `viewer`, `office_admin`, or `assistant`.

## Live link share history (cc2)

Third piece of this expansion, on top of cc4's `contact_match_shares`
skeleton (the share event log + working-clock reset). Johan's ruling,
verbatim: **"the history is nice to have and yes we can track it. but
keeping in mind the link should actually operate live. so what the buyer
sees can be 1 link that updates on live stock. otherwise the link shows old
stock in a month."** And: **"buyers links are live. so any tracking happens
internally."**

**The buyer's experience does not change at all** — still one permanent
link per buyer/wishlist, always resolving against current stock via
`ClientMatchResolver`, nothing frozen, nothing versioned, nothing the buyer
can see. Everything below is purely internal.

**What's built, on top of cc4's `contact_match_shares` table** (never a
second, competing table for the same event):

- **`ContactMatchShare::record()` extended** (same method, same call sites,
  same clock-reset behaviour — untouched) to ALSO snapshot, in the same
  transaction, exactly which properties `ClientMatchResolver::resolve($match,
  false)` returned at that instant — the EXACT query the live link itself
  runs, never a second, looser comparison. One row per property in a new
  child table, `contact_match_share_properties` (`contact_match_id`
  denormalised alongside the `contact_match_share_id` FK, matching this
  codebase's existing `agency_id`-everywhere convention, for the one query
  this whole feature exists to answer — see below).
- **`CoreMatchShareHistoryService::neverSentProperties()`** — THE
  deliverable Johan actually asked for: "four properties this buyer has not
  seen since you last sent it." Today's live matches (same
  `ClientMatchResolver` call) minus every property_id that has EVER
  appeared in any non-deleted share for this match.
- **`GET /corex/core-matches/{match}/share-history`** —
  `ContactMatchShareHistoryController::show()`, gated on `core_matches.view`
  (same gate as `record-share`; reading isn't the privileged action here).
  Returns the share log (who/when/channel), the open summary (below), and
  `new_since_last_share` (via `Property::toSearchResult()`, the same shape
  DR2's eligibility dropdown already uses). This is the query surface for
  cc3's screen to render "N new since last sent" — the row layout/UI itself
  is cc3's, not built here.
- **`contact_match_shares` gained `SoftDeletes`** (cc4's original migration
  didn't have it — added via a follow-up migration, not an edit to their
  file) — Johan's ruling: the share record is evidence and must be
  soft-delete-only like everything else in CoreX (non-negotiable #1). It
  already structurally survives a buyer being set aside, since set-aside
  only ever touches `contact_matches.set_aside_at`, never the shares table.

**Four things reasoned through before building, per the conductor's brief:**

1. **Does "the buyer opened the link" count as a share?** No — built as a
   genuinely separate event, `ContactMatchLinkOpen` (new table,
   `contact_match_link_opens`), recorded from the public
   `SharedMatchController::show()`/`showViaBuyerLink()` (no auth on that
   route, so every hit counts — there's no way to tell an agent's own
   preview from the buyer actually opening it, and building that
   distinction would need session/auth plumbing that doesn't exist there
   and wasn't asked for). `CoreMatchShareHistoryService::openSummary()`
   surfaces count + last-opened-at as its own field, never merged into the
   share count — "sent 3 times, never opened" needs both numbers kept
   apart, which was the whole point of asking the question.
2. **Volume.** Real QA1 numbers, not a guess: 643 `contact_matches` rows
   (641 active) today, ~8,494 `communications` rows total, ~3,392 in the
   last 30 days. Even a generous estimate — every active match shared
   twice a month, ~15 properties per snapshot — is on the order of
   100–200k `contact_match_share_properties` rows a year, trivial for an
   indexed MySQL table at HFC's scale. No retention or roll-up strategy
   built or needed; revisit only if CoreX's tenant scale changes by orders
   of magnitude, not speculatively now.
3. **"Not seen since" when a property was sent, withdrawn, and relisted.**
   Decided on property IDENTITY, not current listing status: once a
   property_id has appeared in any share for this match, it never counts
   as "new" again, even after a withdraw/relist cycle — relisting never
   creates a new property row (MatchOrCreate + no-hard-delete mean the same
   row persists throughout), and the buyer already has this property in
   memory from before. Re-surfacing it as "new" the moment it comes back on
   the market would be wrong, not helpful. A genuinely newsworthy relist
   (price drop, back on the market after a failed deal) is a deliberate
   re-engagement decision for the agent to make, not something share-history
   should paper over by silently calling it new.
4. **Do other exclusion reasons deserve the same disabled-with-reason
   treatment DR2's eligibility dropdown gives the owner-set gap?** N/A here
   — there is no dropdown/exclusion concept in share-history; every
   property `ClientMatchResolver` returns live is either already-seen or
   new, nothing is hidden from the agent. (This question was actually
   answered on the unrelated DR2 piece, not this one — noted here only so
   a future reader doesn't confuse the two.)

**Tests**: `tests/Feature/CoreMatches/ContactMatchShareHistoryTest.php` — 9
tests: a share snapshots exactly the live-matched properties at that moment
(and nothing outside the match's own criteria); "never sent" is today's
live matches minus everything ever shared; a withdrawn-then-relisted
property still counts as already-seen; a trashed share stops counting its
properties as seen (soft-delete proven, not just declared); share evidence
survives the buyer being set aside; opening the link records a separate
event and never creates a share row; repeated opens accumulate a count and
last-opened-at; the read endpoint returns all three pieces in one response;
the endpoint is gated the same as the rest of Core Matches.

## Not built here, reported not fixed

- The master on/off toggle for the whole expansion that cc3 says they were
  told about as its own numbered task — not in the six rulings given to
  cc4. Two independent reads landing on the same gap; needs a ruling, not a
  guess from either lane.
- `PpWebhookController`'s `received_at` (via the observer) stays our
  processing time, not PP's own reported time — see the data-model note
  above.
- `AgencyContactSettings`'s existing buyer-freshness settings
  (`buyer_warm_days`/`buyer_cold_days`/`buyer_lost_days`) are themselves
  absent from the Setup Wizard — pre-existing gap, not introduced or
  widened here, not fixed here either (out of scope).
