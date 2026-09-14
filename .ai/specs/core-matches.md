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
