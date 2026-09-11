# Rentals — Shared-Screen Entry Points, Tenant "Won" Trigger

Status: **SPEC ONLY — no code, no branch, no migration.** Written per Johan's explicit
STEP 2 authorization. Supersedes any earlier draft of this idea — this version reflects
Johan's correction that the three "mirror" screens must NOT be forked code.

**Scope note:** this document originally also covered a consolidated "Rental" tab on
the property detail screen. Per Johan's scope split, that work now belongs to a
separate lane (cc5) and has been removed from this document — see the report delivered
alongside this spec for the full inventory handed over. This document covers only the
three Rentals entry points (Properties, Core Matches, Rental Pipeline), the tenant
"won" trigger, and Role Manager scoping standardisation.

Related specs: `.ai/specs/rental-applications.md` (Rental Applications module — separate
feature, not touched by this spec). `.ai/specs/corex-domain-events-spec.md` (event
catalogue referenced in §5).

---

## 0. The one rule that governs this entire spec

Johan, verbatim: *"we went for same screen, well not different code so the properties
under real estate and properties under rentals work exactly the same, but the rental
screen just have a filter to only show rental side... if any changes to properties are
made then it should essentially automatically be made to the rental screen."*

**There is one Properties screen, one Core Matches screen, one Buyer Pipeline board —
today and after this work.** The three new "Rentals" menu entries are not new screens.
They are **routes that open the existing screen with the rental lens forced on**. Same
controller, same Blade view, same model, same query builder. A feature added to
Properties exists on the Rentals entry point the moment it ships, with no second commit,
because it is the same code. Nowhere in this spec is a second controller, a duplicated
Blade view, or a parallel query path created. Any implementation that produces a second
copy of anything is a defect against this spec, not a valid interpretation of it.

---

## 1. Correction on record: Buyer Pipeline states

Johan's belief: `new / hot / cold / lost / won`.

The actual states, confirmed from three independent sources, are:

**`new / warm / cold / lost`, plus a terminal `won`. There is no `hot` state anywhere
in the codebase or the data.**

Sources checked:
- `app/Services/BuyerStateService.php` `resolveState()` (lines 18–39) — returns only
  `new`/`warm`/`cold`/`lost`; `WON` is a separate protected terminal constant reached via
  `markWon()`, not part of the decay ladder.
- `resources/views/command-center/buyers/pipeline.blade.php` line 91 — the kanban column
  labels are hardcoded `['new' => 'New', 'warm' => 'Warm', 'cold' => 'Cold', 'lost' => 'Lost']`.
- Live database — `distinct()` on `contacts.buyer_state` returns exactly
  `['lost','warm','cold','new']` (plus `won` on won records).

This spec uses `new/warm/cold/lost/won` throughout. Screen C (§4) is this exact
five-state board with the rental lens applied — no new states are introduced for rentals.

---

## 2. Screen A — Rentals → Properties

**BUILT AND LANDED ON QA1, 2026-09-10.** Exactly as specced below, with one
implementation refinement: the lock is detected by **route name**
(`$request->route()->getName() === 'corex.rentals.properties.index'`), not a route
default/closure — simpler, equally uneditable by the client (a route name is never
client-supplied). Every self-referencing `route('corex.properties.index', ...)` call in
`index.blade.php` (filter form action, Clear links, pagination/chip URLs — 7 call
sites) was changed to `route($indexRouteName ?? 'corex.properties.index', ...)`, where
`$indexRouteName` is the controller's own route name, so "Clear filters" and every
other self-link on the Rentals entry point stays on the Rentals entry point instead of
bouncing to the unlocked screen. Session-persisted filters use a separate key
(`corex.rentals.properties.filters` vs `corex.properties.filters`) so a saved filter
set never leaks between the two entry points. No new permission — reuses
`properties.view`. Nav entry added to the existing Rentals panel in
`corex-sidebar.blade.php`, directly under Real Estate (placement unchanged).

Verified in a real browser on QA1: the entry shows only `For Rent` listings, editing
the URL to `?listing_type=sale` does not escape the lock (still only rentals), and
`/corex/properties` (Real Estate → Properties) is unchanged — still has the editable
Sale/Rental toggle.


**Entry point, not a screen.** Same route family, same controller
(`App\Http\Controllers\CoreX\PropertyController`), same view
(`resources/views/corex/properties/index.blade.php` / `show.blade.php`), same model
(`App\Models\Property`).

**What already exists and is reused as-is:**
`PropertyController::index()` (`app/Http/Controllers/CoreX/PropertyController.php:31-434`)
already has a working `listing_type` filter (line 88:
`$request->query('listing_type', '')`, values `''|sale|rental`), already scoped via
`PermissionService::getDataScope($user, 'properties')` (line 36) +
`applyRoleScope()`. Search, sort (`newest|oldest|price_asc|price_desc|title|status_priority`,
agency-configurable default via `properties_sort_mode`), the full filter set (`status`,
`search`, `listing_type`, `property_type`, `category`, `mandate_type`, `branch_id`,
`price_min`, `price_max`, `beds_min`, `baths_min`), pagination, session-persisted filter
state, empty state, and full CRUD (create/edit/`destroy()` soft-delete/`restore()`
line 3734) are all already built and already correct. Nothing here changes.

**What's new — the Rentals entry point and the lock:**

- New route `GET /corex/rentals/properties`, name `corex.rentals.properties.index`,
  pointing at the **same** `PropertyController::index()` method (no new controller
  method — the route is a second URI to the same action, mirroring how
  `corex.properties.index` is registered).
- The route passes a route-level flag (e.g. a route default, `->defaults('lockedListingType', 'rental')`,
  or equivalently a small closure wrapper that injects `listing_type=rental` into the
  request before calling `index()`) that `PropertyController::index()` reads to override
  whatever `listing_type` query param arrived on the URL.
- **The lock is enforced in the controller, not the URL.** A query-string `listing_type`
  is not a lock — a user can retype the URL. The controller must check the locked flag
  *before* reading `$request->query('listing_type', '')` and, if locked, force the value
  to `'rental'` regardless of what the query string says, and drop the sale/rental
  toggle control from the rendered filter bar for that request (the view already
  receives `$listingType` as a variable — when locked, the Sale/Rental `<select>` in
  `index.blade.php` around line 356–358 is replaced with a static "Rentals" label, not a
  disabled dropdown a user could still tamper with via devtools and resubmit — resubmission
  is defeated because the controller re-forces the value server-side on every request,
  not just on initial render).
- Because the lock lives in the controller method itself, it also applies to the
  session-persisted filter restore path (line ~68–72) and to any AJAX/pagination
  request that re-hits the same route — there is no code path on this route that can
  return a sale listing.
- `show.blade.php`/`create`/`edit`/`destroy`/`restore` for a property opened from this
  entry point are the same routes as today (`corex.properties.{show,edit,destroy,restore}`)
  — no separate rentals property-detail route. A rental property's edit screen is
  reached identically whether the agent arrived via Real Estate → Properties or
  Rentals → Properties.

**CRUD:** identical to today's Properties screen — soft delete only
(`corex.properties.destroy` → `SoftDeletes`, never a hard delete), restore already wired
(`corex.properties.restore`, `withTrashed()`).

**Scoping:** see §7 — Properties already uses `PermissionService::getDataScope()`, no
change needed for this screen specifically.

---

## 3. Screen B — Rentals → Core Matches

**BUILT AND LANDED ON QA1, 2026-09-10 — the third and final entry (Properties,
then Pipeline, then Core Matches, per Johan's ordering).** The listing_type gap
identified below was closed on the ONE shared screen first: `index()` and
`allView()` now both accept `?listing_type=sale|rental` (default `''` = today's
mixed behaviour, unchanged for the plain screens), backed by a Sale/Rental
toggle in both `index.blade.php` (link pills, matching Rental Pipeline's
pattern — this screen had no existing filter form to hang a `<select>` on) and
`all.blade.php` (a `<select>` in the existing agent-filter form, matching
Properties' pattern).

The Rentals entry point locks it with the same route-name mechanism as §2/§4:
new routes `corex.rentals.core-matches.index` and `corex.rentals.core-matches.all`,
forcing `listing_type = 'rental'` after the query string is read. One
deliberate refinement beyond the original table in §6.1: `.index` reuses
`core_matches.view`, but `.all` reuses `core_matches.all_view` specifically
(not `core_matches.view`) — gating it on the weaker permission would have let
anyone with base view access see every agent's rental matches through the
rentals entry, an escalation the sales-side `.all` route does not allow.
Self-links (filter form action, Clear filter) and the My/All cross-links use
`$indexRouteName` / `$counterpartRouteName` so navigating within a locked
entry point never bounces to the unlocked screen. No new permission key for
either route. Nav entry mirrors the sales-side Core Matches item's own
feature-flag (`core-matches`) and `matches_enabled` setting guards.

Verified in a real browser: agency-wide there are 595 Core Matches (383 sale /
212 rental) — both `/corex/rentals/core-matches` and
`/corex/rentals/core-matches/all` show only rentals ("212 searches" exactly on
the All variant, confirming genuine subset filtering, not a no-op),
`?listing_type=sale` does not escape the lock on either route, and
`/corex/core-matches` and `/corex/core-matches/all` are unchanged — both still
have their editable Sale/Rental controls.

**Entry point, not a screen.** Same controller
(`App\Http\Controllers\CoreX\ContactMatchController`), same views.

**Gap that must be closed on the shared screen first:** Core Matches has **no
listing_type lens today at all** — confirmed: `index()`
(`app/Http/Controllers/CoreX/ContactMatchController.php:60`) and `allView()` (line 93)
carry no `listing_type` filter or query condition anywhere in the method bodies.

**What this spec adds — to the ONE Core Matches screen, the same way Properties
already has its filter:**
- A `listing_type` query filter added to `ContactMatchController::index()` and
  `allView()`, `''|sale|rental`, following the exact pattern already proven on
  `PropertyController::index()` line 88 (default `''` = no filter, i.e. today's
  behaviour is unchanged for anyone who doesn't pass the param).
- The underlying match records already carry `listing_type`
  (`contact_matches.listing_type` — confirmed live via
  `BuyerPipelineController::applyLeadTypeFilter()`, which already reads this exact
  column) — the new filter is `where('listing_type', $type)` against a column that
  already exists and is already populated. No migration needed for this filter itself.
- A Sale/Rental toggle added to `resources/views/corex/core-matches/index.blade.php`
  (and the `allView` equivalent), matching the visual pattern of the Properties
  Sale/Rental toggle.

**Then, the Rentals entry point locks it — same mechanism as §2:**
- New routes `GET /corex/rentals/core-matches` and `GET /corex/rentals/core-matches/all`,
  named `corex.rentals.core-matches.index` / `.all`, pointing at the same
  `ContactMatchController::index()` / `allView()` methods.
- Both methods force `listing_type = 'rental'` server-side when the locked flag is
  present on the request, identically to §2's lock — the query string is never trusted,
  the toggle control is not rendered as an editable control on this entry point.

**CRUD:** `convertToDeal()` is already listing-type-aware
(`$deal->deal_type = $match->listing_type === 'rental' ? 'rental' : 'sale';`) — no
change needed. Archive/delete on Core Matches already exists behind
`core_matches.delete` / `core_matches.manage` — unchanged, soft delete only.

---

## 4. Screen C — Rentals → Rental Pipeline

**BUILT AND LANDED ON QA1, 2026-09-10 — before Core Matches, per Johan's re-priority
(cheapest-first: Properties, then Pipeline, then Core Matches).** Same route-name lock
mechanism as Screen A: `corex.rentals.pipeline.index` detected by
`$request->route()->getName()`, forcing `lead_type = 'rental'` after the query string
is read. All 9 self-referencing `route('command-center.buyers.pipeline', ...)` calls in
`pipeline.blade.php` now resolve via `$indexRouteName`. New permission
`buyer_pipeline.view` (§6.2 below) gates only this entry point — the sales-side board
keeps its current no-permission-key access, unchanged, per Johan's explicit "do not
build the sales-side standardisation right now."

Verified in a real browser: the entry shows a genuine subset (174 rental leads vs 469
total on the unfiltered board — confirmed the filter actually narrows, not a no-op),
`?lead_type=sale` on the URL does not escape the lock, and the sales-side board is
unchanged.

**Entry point, not a screen.** Same controller
(`App\Http\Controllers\CommandCenter\BuyerPipelineController`), same view
(`resources/views/command-center/buyers/pipeline.blade.php`), same
`BuyerStateService`, same `BuyerIntelligenceService` risk scoring — all of it already
generic/listing-type-safe (price lines already use `Property::effectivePrice()`; card
markup, activity recency, lost-risk indicator, "View Matches"/"Schedule Viewing" actions
are all already rental-safe — confirmed in the STEP 1 investigation).

**What already exists and is reused as-is:** the Sale/Rentals toggle is **already live**
in the UI (`pipeline.blade.php` lines 29–38) and the filter is already implemented
server-side — `applyLeadTypeFilter()`
(`app/Http/Controllers/CommandCenter/BuyerPipelineController.php:251-264`) partitions
the board on `contact_matches.listing_type` exactly as needed. This screen needs no new
filter built — it needs the existing one **locked** the same way as §2 and §3.

**The lock:**
- New route `GET /corex/rentals/pipeline`, name `corex.rentals.pipeline.index`, pointing
  at the same `BuyerPipelineController::index()`.
- `index()` forces `leadType = 'rental'` into `applyLeadTypeFilter()` when the locked
  flag is present, overriding any `?lead_type=` on the query string — same
  controller-side enforcement as §2/§3, and for the same reason: a `?lead_type=sale`
  typed into the address bar of this entry point must not surface a sale-side buyer.
- The Sale/Rentals toggle in `pipeline.blade.php` is not rendered on this entry point
  (the view already receives the active lead type as a variable it uses to highlight
  the toggle — when locked, it renders the "Rentals" state as static text instead of a
  clickable pair).

**Flagged, not a blocker:** the prospecting-listing drill-down banner
(`pipeline.blade.php` lines 55–83, `$contextListing`) is sale-only in its current form
and has no confirmed rental equivalent. Because it is conditional
(`@if($contextListing)`) and simply does not render when its precondition isn't met, it
does not block or break the rentals entry point — it just never appears there today.
Whether a rental equivalent should exist is a business question for Johan, not decided
here (see §10).

**CRUD:** drag-drop state transitions (`updateState()`, line 185–195) are unchanged —
same endpoint, same `BuyerStateService::transitionTo()`. No forking of the drag-drop
logic for rentals.

---

## 5. Tenant "Won" trigger

### 5.1 Johan's description, verbatim

*"sales is won when buyer is linked to a dr2 deal. rentals would be if a tenant is
linked to a property and the property gets marked leased out - maybe at this stage we
ask who leased it. and if tenant on pipeline that marks them as won?"*

### 5.2 Investigation — does a "leased out" status already exist?

**Yes.** Confirmed via a direct query against `PropertySettingItem`
(`group = 'property_status'`, the real, agency-configurable status list — not a
hardcoded enum) that **"Let Out"** is a real, currently-live status option, appearing
once per agency in the flat dump, alongside "To Let" (the on-market rental equivalent
of "For Sale"). Its slug, `let_out`, is already present in
`Property::OFF_MARKET_STATUSES` (`app/Models/Property.php:57-61`) and
`CONCLUDED_STATUSES` (line 1316).

**Property Status is settled doctrine in this project — this spec proposes no change
to it.** The trigger below is built entirely against the existing `let_out` status. No
new status is introduced, no status is renamed, no status list is reordered.

### 5.3 The trigger mechanism — a new, separate listener

**`MarkBuyerWonOnPropertyLink` (`app/Listeners/Contact/MarkBuyerWonOnPropertyLink.php`)
is NOT widened.** Its `BUYER_ROLES = ['buyer', 'purchaser']` stays exactly as-is. Its
docblock already states tenant/lessee is deliberately excluded from sale-side "won"
logic — that exclusion is correct and must remain. **The rental trigger is a brand-new,
separate listener. Nobody may collapse the two into one broadened listener now or
later — the two "won" conditions (buyer↔deal for sales, tenant↔let-out-property for
rentals) are different facts about different pillars and must stay two separate,
independently-readable pieces of logic.**

**Existing mechanism this reuses, not rebuilds:** linking a Contact to a Property with a
role already exists — `PropertyContactController::link()`
(`app/Http/Controllers/CoreX/PropertyContactController.php:103`) validates against
`LINK_ROLES = ['seller', 'buyer', 'owner', 'landlord', 'tenant', 'lessor']` (line 86,
`tenant` already valid) and fires `App\Events\Contact\ContactLinkedToProperty` with
`(Contact $contact, Property $property, string $role, ...)`. No new linking mechanism
is built — a tenant link is exactly today's existing link, with `role = 'tenant'`.

**Domain-event gap found and closed as part of this feature:** the events catalogue
(`.ai/specs/corex-domain-events-spec.md` line 305) already documents
`Property\PropertyStatusChanged` as the correct hook —
*"Property's lifecycle status transitions... fired from `PropertyObserver::updated()`
when status field is dirty"* — and the catalogue's summary table (line 74) lists it
among events described as done. **It is not built.** Confirmed by direct inspection:
`app/Events/Property/` contains no `PropertyStatusChanged.php` (only `PropertyCaptured`,
`PropertyCompliancePassed`, `PropertyPublished`, `PropertyAuditWriteFailed`,
`PropertySoldByThirdParty`, `PropertySuburbLinked`, `PropertySgDocumentSaved` exist), and
`PropertyObserver.php` dispatches none of those four events under that name anywhere in
the file. This is a pre-existing gap in the codebase's own catalogue, not something this
feature invents — and per non-negotiable #9 (cross-pillar reactivity uses domain
events, no ad-hoc observer hooks), **this spec builds `Property\PropertyStatusChanged`
now**, matching the catalogue's own already-agreed shape (`property`, `oldStatus`,
`newStatus`, `agencyId`), fired from `PropertyObserver::updated()` when `status` is
dirty — additive only, no change to the four events already firing from that observer.

**The new listener — `App\Listeners\Property\MarkTenantWonOnPropertyLetOut` (name
illustrative, final name at build time):**

Subscribes to `Property\PropertyStatusChanged`. On firing, if `newStatus === 'let_out'`
(matched against the same `OFF_MARKET_STATUSES` constant the rest of the codebase
already uses, not a second hardcoded string):
1. Look up Contacts linked to this Property with `role = 'tenant'`
   (`$property->contacts()->wherePivot('role', 'tenant')`).
2. For each such Contact that has an active rental-side pipeline record
   (`is_buyer = true` and a `contact_matches` row with `listing_type = 'rental'` — the
   same partition `BuyerPipelineController::applyLeadTypeFilter()` already uses to
   decide who is a "rental lead" on the board), call the rental-side equivalent of
   `BuyerStateService::markWon()` for that contact.
3. If **no** Contact is linked with `role = 'tenant'` at the moment the property is
   marked `let_out`, nothing fires — there is no tenant to mark won. This is not an
   error state; it is the expected case for a property let out by any means other than
   through a rental-pipeline lead already in CoreX (e.g. let via a portal enquiry never
   captured as a Contact, or let by a mechanism outside CoreX).

### 5.4 "Who leased it" — where it appears, and why it is never a dead end

Johan: *"maybe at this stage we ask who leased it."* Read together with the trigger
description, the prompt fires **at the moment an agent changes a property's status to
"Let Out"** (in the Info tab's status control, wherever the status change is submitted
today) — the same moment `PropertyStatusChanged` fires. The prompt is a modal/inline
step in that same status-change flow: *"Who leased this property?"*, offering:
- **Search an existing Contact** and link them with `role = 'tenant'` (reusing
  `PropertyContactController::link()` exactly as it works from the Contacts tab today).
- **Create a new Contact inline** (matching the existing search-or-create-inline pattern
  already used elsewhere in CoreX's contact-linking modals) if the tenant isn't in the
  system yet, then link them the same way.
- **Skip.** The property's status change to "Let Out" **must succeed regardless of
  whether this prompt is answered** — the prompt is a courtesy captured at a convenient
  moment, never a gate on the status change itself. Skipping simply means step 3 in
  §5.3 finds no linked tenant and nothing is marked won; the agent (or anyone with
  Contacts access) can link a tenant to the property later via the existing Contacts
  tab at any time, and if that later link happens to be to a Contact who is also on the
  rental pipeline, that is a separate manual action, not this trigger (this trigger
  only fires at the status-change moment, per Johan's description — see §10 for whether
  a later manual tenant link should also be able to trigger "won" retroactively, which
  is a real open question this spec does not decide).

---

## 6. Scoping — Role Manager standardisation

Johan's ruling, verbatim: *"all should be in role manager - so we can standardize and
build same for rentals."*

### 6.1 The three new entry points (§2, §3, §4)

All three use `PermissionService::getDataScope($user, $module)` from day one — same
mechanism as the existing Properties screen, same Role Manager UI pattern already built
for e.g. `properties.view` / `core_matches.view`. Each needs its own module/permission
keys (see §9 for why none of these may reuse the existing `rentals` module key):

| Entry point | Permission key(s) | Module key for `getDataScope()` |
|---|---|---|
| Rentals → Properties | reuses `properties.view` (same screen, same permission — a user who can see Properties at all sees it through whichever entry point their nav shows) | `properties` (already exists, unchanged) |
| Rentals → Core Matches | reuses `core_matches.view` | `core_matches` (already exists, unchanged) |
| Rentals → Rental Pipeline | **new** `buyer_pipeline.view` (does not exist today — see §6.2) | **new** `buyer_pipeline` |

Because Rentals → Properties and Rentals → Core Matches are locked entry points into
screens that already carry their own permission, **no new permission key is needed for
those two** — access to the shared screen already gates access to the rentals view of
it. Their Role Manager scope selector (own/branch/agency) is likewise already the
existing `properties`/`core_matches` selector — nothing new to add to Role Manager for
these two.

### 6.2 The real gap: Buyer Pipeline has no permission key at all today

Confirmed: `command-center.buyers.pipeline` route
(`routes/web.php:2005`) carries **no `permission:` middleware whatsoever** — access is
gated only by the surrounding group's `auth`/`agency.required` middleware. There is
no `buyer_pipeline.*` entry in `config/corex-permissions.php` and therefore no Role
Manager scope selector for it — today, every authenticated user with any access to
Command Center sees the same board, scoped only by
`BuyerPipelineScope`/`AgencyContactSettings::buyer_pipeline_default_scope` (an
agency-wide setting, not a per-role one).

**This spec adds the permission key `buyer_pipeline.view` (module `buyer_pipeline`)** —
required regardless of the rentals work, because a locked rentals entry point cannot be
scoped by role without a permission to hang the scope selector on. This is the one
place this spec's "new screens" work and the "standardise the sales originals" work
(§6.3) touch the same code, and it is unavoidable: the Rental Pipeline entry point
cannot exist with proper own/branch/agency control until Buyer Pipeline has a
`PermissionService`-compatible permission key. Building `buyer_pipeline.view` is
therefore in scope for the three-new-screens work, not deferred to §6.3.

### 6.3 The two sales originals — flagged as its own, separately-gated work item

Johan's ruling says "standardise", which necessarily includes the two existing sales
screens that don't yet use `PermissionService::getDataScope()`. **This spec documents
what that would mean and lists it as its own approval-gated item — it is not bundled
into building the three new screens, and must not be built silently alongside them.**

**Core Matches — today's mechanism:**
`ContactMatchController::index()` (line 60) is **hardcoded own-only** — every user, at
every role, sees only their own matches on the default view, with no scope selector at
all. `allView()` (line 93) is gated behind a **boolean** permission
(`split_branches_enabled`-style toggle) that is on/off, not a three-way own/branch/agency
choice.

*Exactly what changes for a real user today if this is standardised:* a Branch Manager
or Admin who currently gets the boolean "all matches" toggle would instead get a Role
Manager-configured scope (own/branch/agency) — if their role's default scope resolves to
`branch` rather than `all`, **they would see fewer matches than they see today** (branch
instead of agency-wide) until Johan or an admin explicitly sets their role's Core
Matches scope to `agency` in Role Manager. Conversely, an ordinary Agent role — currently
hard-locked to own-only with no way to widen it — would gain the *ability* to be
widened to branch/agency by an admin, which is not possible today at all.

**Buyer Pipeline — today's mechanism:**
`BuyerPipelineScope::apply()` (`app/Services/CommandCenter/BuyerPipelineScope.php`)
reads a single agency-wide setting, `AgencyContactSettings::buyer_pipeline_default_scope`
— the same scope applies to every role in the agency, with a user-facing `?scope=`
toggle layered on top. There is no per-role control today at all.

*Exactly what changes for a real user today if this is standardised:* today, changing
`buyer_pipeline_default_scope` changes what *every* role sees at once. After
standardisation, an Admin could set Agents to `own` while Branch Managers see `branch`
and Admins see `agency` — a real capability gain — but it also means the *current*
single agency-wide setting stops existing, so whatever value it holds today must be
migrated into per-role Role Manager defaults (see §6.2 — `buyer_pipeline.view` is being
created anyway; this migration is folded into that same work if/when Johan approves
§6.3, not duplicated).

**This is presented for Johan's separate go-ahead. Nothing in §2/§3/§4/§6.1/§6.2 depends
on §6.3 being approved — the three new entry points work correctly with Core
Matches/Buyer Pipeline exactly as they are today; §6.3 is a live-behaviour change to the
sales side that stands on its own.**

---

## 7. Navigation entries

**Placement, stated as fact (AT-401, 2026-09-10):** the Rentals section sits directly
under Real Estate on the main menu, per Johan's explicit instruction. This sidebar has
no ordering array — each top-level group's position is literally where its markup
appears in `resources/views/layouts/corex-sidebar.blade.php`, so the Rentals block
(comment + `@if(...)` + panel, now at the point immediately after Real Estate's closing
`</div>` and before Communication's opening comment) *is* the ordering, not an accident
of it. Confirmed not agency-configurable — nothing in this file's top-level group order
varies per agency, so every agency sees this same sequence; this placement is
therefore the default and only ordering, not something set per agency.

All three new entry points are added to the existing Rentals nav panel (same file, the
`@if($user && ...)` block immediately after Real Estate, Alpine group key
`rental-applications`) as new `@permission(...)`-gated `<a>` links alongside the
existing Rental Applications / Returned Applications / Rental Application Authorisation
links — same panel, same visual pattern, no new sidebar section:

```
@permission('properties.view')
<a href="{{ route('corex.rentals.properties.index') }}" ...>Properties</a>
@endpermission

@permission('core_matches.view')
<a href="{{ route('corex.rentals.core-matches.index') }}" ...>Core Matches</a>
@endpermission

@permission('buyer_pipeline.view')
<a href="{{ route('corex.rentals.pipeline.index') }}" ...>Rental Pipeline</a>
@endpermission
```

(Exact label text/ordering is a build-time detail; the requirement is that all three
appear in the existing Rentals panel the same day they ship, per non-negotiable #2.)

---

## 8. Naming collision — the `rentals` module key is already taken

Flagged clearly so nobody reaches for the obvious name during build: `module =>
'rentals'` and permission keys `rentals.view`/`.create`/`.edit`/`.archive`
(`config/corex-permissions.php` lines 52, 82-83, 103-106) already belong to the
pre-existing lease/tenancy-management module (`App\Models\Rental`, table `rentals`,
`RentalsController`, §5.1's landmine). **None of this spec's new permission or module
keys may be named `rentals`.** The new keys introduced by this spec are
`buyer_pipeline.view` (module `buyer_pipeline`, §6.2) — `properties` and `core_matches`
are reused unchanged, not renamed.

---

## 9. Items flagged for Johan's decision — not decided in this spec

1. **Rental tab field list (§5.2, §5.3)** — the full inventory's move/stay calls and
   the three genuinely new fields (furnished status, availability date, utilities
   included). Approve, cut, or add before any migration is written.
2. **Rental Images fold-in (§5.2 item 1, §5.3)** — recommendation, reaffirmed after the
   full inventory, is to keep it a separate tab from the new Rental tab. Needs Johan's
   confirm or override.
3. **`commission_percent`/`admin_fee`/`marketing_fee` desktop inputs (§5.2 item 6,
   §5.5)** — presented as completing existing rental-exclusive fields that today have no
   desktop UI (only mobile). Confirm these should get their first desktop inputs at all,
   and that the Rental tab (rental-only-visible) is the right place for them.
4. **Post-ship "fields moved" pointer (§5.6)** — a one-time dismissible note pointing
   agents to the new tab is proposed as optional UX, not required. Decide whether to
   build it.
5. **Prospecting drill-down banner on the rentals pipeline entry point (§4)** — no
   rental equivalent exists; does one need to be built, or is it correctly absent?
6. **Retroactive tenant-won linking (§5.4)** — the trigger as specced fires only at the
   moment a property's status changes to "Let Out". If a tenant is linked to an
   already-let-out property *after* the fact (via the ordinary Contacts tab, not through
   the "who leased it" prompt), should that also mark them won? Johan's description
   describes the status-change moment specifically; this spec does not extend the
   trigger beyond that without being told to.
7. **§6.3 (standardising Core Matches and Buyer Pipeline scoping onto Role Manager)** —
   presented with its exact live-behaviour consequences; needs its own explicit
   go-ahead, separate from the three new screens.
8. **Exact final class/listener names** (`MarkTenantWonOnPropertyLetOut` etc.) are
   illustrative — normal build-time naming, not a decision Johan needs to make.

---

## 10. Out of scope (explicitly, so it is never assumed later)

- No change to Property Status governance, the status list, or any existing status
  slug — `let_out` is used exactly as it exists today.
- No widening of `MarkBuyerWonOnPropertyLink`'s `BUYER_ROLES` — sale-side "won" logic is
  untouched.
- No forked controller, view, or query path for any of the three screens — see §0.
- No change to the pre-existing `Rental`/`RentalsController`/`rentals` lease-management
  module — it is unrelated and untouched by this work.
- §6.3 (sales-side scoping retrofit) does not ship as part of this spec's build unless
  and until Johan separately approves it.
- No change to the property creation **wizard** (`wizard.blade.php`'s own "Rental-only
  fields" block, and its shared sale/rental price field at step 1) — they stay exactly
  as they are across all five Rental tab landings; the tab's consolidation is scoped to
  the tabbed edit screen (`show.blade.php`) only.
- No fix to the pre-existing gap where `rental_price_type`/`lease_period`/`lease_type`
  aren't cleared by the sale/rental type-switch clone logic (`PropertyController::
  makeClone()`) — flagged for the record across every landing, not remediated in any
  of them.

---

## 11. Property Rental tab (AT-402)

**Status: COMPLETE — Parts 1-5 all built and landed on QA1, 2026-09-10.** The
Rental tab now holds every field in the inventory below marked DONE — 17 fields
across five landings, one dedicated save action, one new agency-configurable
setting, one new agency-managed settings list.

Johan, verbatim: *"the part that was discussed was having seperate screens for rentals
and sales. we went for same screen, well not different code so the properties under
real estate and properties under rentals work exactly the same, but the rental screen
just have a filter to only show rental side... With this said we can have a tab on
properties thats only visible to rental properties, exists on properties but its
hidden. The rental tab is where we hold all the extra rental info a normal property
will not need."* And, immediately after: *"once we have a proper rental section on a
property it would make sense to move the rest of the rental on properties to the
rental tab."*

This section was originally drafted as part of this spec (commit `b9183a73d`), then
split off to a separate lane's work item and stripped from this document. That lane
never built it. This section restores that inventory — re-verified line-for-line
against the current codebase, not re-derived blind — and records what Part 1 actually
built.

### 11.1 Exhaustive inventory — every rental-specific thing on the property screen

**Authoritative source for what counts as "rental-only," not a guess:** the type-switch
clone logic (`PropertyController::makeClone()`, `app/Http/Controllers/CoreX/
PropertyController.php:1699-1704`) nulls out exactly this set when a rental draft is
switched to sale: `rental_amount, deposit_amount, commission_percent, admin_fee,
marketing_fee, lease_start_date, lease_end_date, rental_images_json`. This inventory is
built against that list plus everything else found by direct inspection of the Blade
views — the two do not fully overlap, and the gap between them is part of the
"scattered" problem.

| # | Item | Location (pre-Part-1) | Move / Stay | Status |
|---|---|---|---|---|
| 1 | **Rental Images** tab | `show.blade.php` tab def + body, `rental_images_json`/`rental_upload_keys` | **STAYS its own tab** — media-management concern, not a data-form field | Unchanged |
| 2 | "Rental Details" section — `rental_amount`, `deposit_amount`, `rental_price_type`, `lease_start_date`, `lease_end_date` | Was buried inside the Info tab | **MOVED** into the new Rental tab | **DONE (Part 1)** |
| 3 | `lease_period` input | Orphaned elsewhere in the Info tab's "Pricing Details" popup | **MOVED** into the Rental tab | **DONE (Part 2)** |
| 4 | `lease_type` input | Same popup | **MOVED** | **DONE (Part 2)** |
| 5 | `has_deposit` toggle | Same popup | **MOVED** | **DONE (Part 2)** |
| 6 | `price_per_day`/`price_per_week`/`price_per_year` | Same popup | **MOVED** | **DONE (Part 2)** |
| 7 | `pet_friendly` | Column exists, no `<input>` anywhere today | First-ever input | **Deliberately not built** — Johan's answer covered items 8 and the three new fields (§11.5) but did not name `pet_friendly`; not assumed |
| 8 | `commission_percent`, `admin_fee`, `marketing_fee` | Columns exist, validated, mobile-app-only (`Api/MobilePropertyController.php`) | First-ever desktop input, in the Rental tab | **DONE (Part 3)** |
| 9 | Page-header / list / portal price display (`formattedPrice()`) | Everywhere a property is glanced at | **STAYS exactly where each is** | Confirmed unaffected — accessor-based, never reads tab position |
| 10 | "Change listing type" toggle/action + `listing_type` field itself | Info tab | **STAYS in Info tab** | Unchanged — controls whether the Rental tab even renders; can't live inside the tab it controls |
| 11 | Tenant link (who currently leases it) | `PropertyContactController` (`LINK_ROLES` includes `tenant`), via the Contacts tab | Read-only summary could surface in the Rental tab; linking mechanism stays on Contacts tab | **Not built** — never in scope for any of Parts 1-5; would need its own approval |
| 12 | Furnished Status | Did not exist | New column + new agency-managed `PropertySettingItem` list | **DONE (Part 5)** |
| 13 | Move-in Availability date | `occupation_date` already existed (P24-import-populated, doc-generation-read, no UI) | Reused the existing column — no new column | **DONE (Part 5)** |
| 14 | Utilities Included | Did not exist | Three new itemised boolean columns (Water/Electricity/Levies) — not a single yes/no | **DONE (Part 5)** |

`pet_friendly` (item 7) remains genuinely open — flagged again here so it is not
mistaken for a decision either way.

### 11.2 Part 1 — what was built

- New **"Rental"** tab, `resources/views/corex/properties/show.blade.php`, positioned
  next to the existing Rental Images tab. Visible only for a rental listing: reactively
  (live `listing_type` change listener, mirroring the pattern the old embedded section
  already used) for a brand-new property or a `listing_type_pending` type-change draft
  — since `listing_type` is still editable there — and statically for a settled
  property, since its type can't change without going through the separate
  duplicate/change-type flow.
- The five already-rental-gated fields (item 2 above) were **moved, not duplicated**,
  out of the Info tab's buried section into the new tab.
- New dedicated action `PropertyController::updateRentalDetails()` +
  `PUT /corex/properties/{property}/rental-details`, used only for a **settled** rental
  property: its own validation (adding the `lease_end_date >= lease_start_date` check
  the old shared form never had), its own `DB::transaction()`, the same
  `authorizeProperty()` OWN/BRANCH/AGENCY scoping every other property write already
  uses, and a server-side re-check that the property is an active, non-pending rental
  listing — a 403, never a silent write, for a direct POST at a sale property. A
  brand-new property or a type-change draft still rides the main create/update form via
  `form="prop-update-form"` (the same cross-form-binding the Gallery tab's file input
  already relies on) — the dedicated action can't run yet because the property may not
  have an id.
- "Archive/restore": no separate lifecycle — the fields live on the Property row, so
  archiving/restoring the property (already soft-deleted via `destroy()`) already
  carries them with it.

**Verified live on QA1:** a real rental property's Rental tab shows and saves real
values; an invalid lease-date-range save was rejected by validation and did not touch
the stored data; a sale property's tab bar and Pricing section are byte-for-byte
unchanged; a direct POST of rental fields at a sale property's new route 403s; the tab
appears live, mid-creation, the instant Rental is picked as the listing type, with no
page reload.

### 11.3 Part 2 — Lease Period, Lease Type, Has Deposit, Price per Day/Week/Year

Moved items 3-6 out of the still-live "Pricing Details" popup (which showed them to
every property, sale included) into the Rental tab, alongside the fields already there.

Checked every consumer of the six fields before moving anything — full-repo search,
not a guess: `lease_period` is the only one read by a portal mapper
(`Property24ListingMapper.php:108`, the P24 `rentalInfo.leasePeriod` payload) and the
only one in `PropertyObserver`'s P24 re-submit trigger list; both read the model
attribute directly, unaffected by where the `<input>` renders. All six are serialised
in the public Website API (`ListingResource.php`), also attribute-based. `has_deposit`
and the three price-per-X fields have no consumer beyond this screen's own validation
and the Website API. `lease_type` has zero programmatic consumers at all — a
pre-existing, already-documented gap in the syndication audits, not created or
worsened by this move.

Found and fixed one real gap surfaced by re-checking Part 1 byte-for-byte, not by
these six fields specifically: the Rental tab's whole content panel — including Part
1's five fields and its own dedicated `<form action="...rental-details...">` — was
rendering into **every** property's raw HTML, sale included; `x-show` only hides it
with CSS, it does not remove it from the page source. Fixed by wrapping the whole
panel (and the tab button) in a PHP-level `@if`, mirroring the Rental Images tab's own
`@if` — a settled sale property now ships zero bytes of rental-only markup. Confirmed
via raw HTML fetch, not computed styles.

**Verified live:** a real, pre-existing rental property's `lease_period` value
("3 months and a mo...", never touched by this work) reads back identically in its
new home; the sale property's raw HTML has zero occurrences of any of the six fields;
a direct POST at the sale property's route still 403s.

### 11.4 Part 3 — Commission %, Admin Fee, Marketing Fee

Johan approved bringing these three onto the desktop Rental tab — today only the
mobile app can set them.

**Mobile-app cross-check, done before building anything (per Johan's explicit
instruction):** the mobile app stores/sends `commission_percent` as a plain percent
number (7.5 = 7.5%, bounded 0-100) and `admin_fee`/`marketing_fee` as plain Rand
amounts, no `*100`/`/100` conversion anywhere. The web-side `PropertyController::
store()`/`update()` already carry byte-for-byte identical validation rules for all
three — they've been sitting unused since these fields never had a desktop input.
Every other consumer found (`WebTemplateDataService`'s commission-amount
calculations, the eSign wizard) treats `commission_percent` the same way. **No
format/unit disagreement found** between mobile and any other consumer.

**One real gap, pre-existing:** `admin_fee`/`marketing_fee` have a floor (`min:0`) but
no ceiling anywhere in the codebase, mobile or web, and no DB constraint. Not
retrofitted into the old mobile/web validation (outside this task) — closed instead
in the new dedicated action:

- `updateRentalDetails()` validates `commission_percent` 0-100 (matching mobile
  exactly) and `admin_fee`/`marketing_fee` against a new agency-configurable ceiling —
  `PerformanceSetting` key `rental_fee_max_amount`, default R50,000 — reusing the
  existing generic agency-settings mechanism, not a new table. A value outside these
  bounds fails validation outright and is never saved; no silent clamping.
- New `SettingsController::updateRentalFeeCeiling()` saver + a control on
  Settings → Properties & Listings + a Setup Wizard entry (`config/
  agency-onboarding-copy.php`, `properties` step) — non-negotiable #10a.

**Verified live:** saved 7.5% / R850 / R1200 on a real rental property, confirmed in
the database directly; `commission_percent=150` and `admin_fee=999999` (above the
configured ceiling) were both rejected and never touched stored data; the sale
property's raw HTML has zero occurrences of any of the three fields; a direct POST at
the sale property's route still 403s.

### 11.5 Parts 4-5 — Furnished Status, Availability Date, Utilities Included

Johan approved: additive migration, agency-configurable, no hardcoded business rule.

**Migration is two new columns' worth, not three.** "Move-in Availability date"
reuses the existing `occupation_date` column (already populated by the P24 CSV
importer, already read by document generation, never had a desktop input) rather than
adding a second, differently-named column for the same concept.

**Furnished Status reasoning:** neither portal can represent more than a binary today
(P24 has a third `Optional` enum value that isn't really "part-furnished"; Private
Property is pure yes/no) — this is a CoreX-only capability for now, portal wiring is
explicitly future work, not built here. Per Johan's own framing ("a small controlled
set an agency can work with"), built as an agency-managed `PropertySettingItem` list
(`GROUP_FURNISHED_STATUS`), not a hardcoded enum — same pattern as `property_type`/
`category`/`mandate_type`. Seeded via the exact AT-352 `provisionDefaultsFor()`
pattern: a companion migration backfills every existing agency (24 rows across 8
agencies on QA1), and `AgencyObserver` already calls `provisionDefaultsFor()` with no
group filter, so every agency created from now on receives it automatically — zero
observer changes needed. Manageable via the existing generic property-setting-item
CRUD on Settings → Properties & Listings (add/edit/reorder/soft-delete/batch-toggle).

**Utilities Included reasoning — itemised, not a single yes/no, delivered to Johan
before building:** Private Property's own API already has separate `WaterIncluded`
and `ElectricityIncluded` attributes, and CoreX's syndication mapper already has the
code wired to send them — permanently `false` today only because no screen ever sets
the underlying feature flags. A single boolean would be a worse fit than what's
half-built already, and can't express the ordinary case of "water's included,
electricity isn't." Property24 has no equivalent concept at all. Built as three
separate boolean columns — `water_included`, `electricity_included`,
`levies_included` — matching exactly the three things Johan named, consistent with
every other rental flag on this table (e.g. `has_deposit`).

None of this is wired to either portal — confirmed not doing that, per instruction.

**Verified live:** ran both migrations against the shared database directly (migration
state lives in the DB, not per-checkout); confirmed the three new columns exist and
24 default Furnished Status items seeded across 8 real agencies; saved Furnished /
2026-11-01 / water+levies-included-but-not-electricity on a real rental property,
confirmed in the database directly; the sale property's raw HTML has zero occurrences
of any of the three fields; a direct POST at the sale property's route still 403s.

---

## Rentals → Properties list — design-standard audit (AT-392, 2026-09-10, cc5)

Pre-launch audit of the rental Properties list (`corex.rentals.properties.index`, `PropertyController::index()`, same action as the sale-side list, distinguished by route name and locked to `listing_type='rental'` after the query string is read). Same treatment as the authorisation-screen audit — findings listed, only the genuinely straightforward fix landed here.

### Search/sort/pagination/empty state/CRUD floor — all PASS, checked not assumed

- **Search**: `Property::searchAddress()` — real column, no ambiguous-JOIN risk. A search deliberately widens past the user's own scope (documented AT-394 behaviour) but flags out-of-scope rows and disables their links, backed by the same real per-record guard as everything else, not a UI-only hint.
- **Sort**: whitelisted columns, default from a real agency setting (`properties_sort_mode`) falling back to newest-first — not arbitrary.
- **Pagination**: real, agency-configurable per-page (`PerformanceSetting::get('properties_per_page', 20)`), clamped.
- **Empty state**: two real messages — "No properties match these filters" vs. "No properties yet."
- **CRUD floor**: Create/Read/Update/Archive(soft-delete via `destroy()`, confirmed genuine `SoftDeletes`, no `forceDelete()` anywhere)/Restore all present as real routes.

### OWN/BRANCH/AGENCY scoping — correctly wired, but a load-bearing nuance to understand before judging "everyone sees everything" as a bug

`applyRoleScope()` and the `show()`/mutation guards use the same `PermissionService::getDataScope()` mechanism already proven correct on the authorisation screens. But `properties`/`contacts` specifically layer a SECOND, agency-wide toggle on top: a role's stored scope is only 'own' or "wider," and when "wider," the *effective* breadth is `agencies.split_branches_enabled` — branch-only when true, agency-wide when false. **Every real agency in this database currently has `split_branches_enabled = false`**, including agency 1 — so today, for every role except a pure-'own' one, the properties list is legitimately agency-wide by an existing, deliberate setting, not a missing scoping wire like the authorisation screens had.

Proved this precisely rather than assumed: constructed a throwaway `viewer`-role test user (a real, already-configured role with `scope='branch'` stored in `role_permissions` — not invented) and confirmed `PermissionService::getDataScope()` resolves to `'all'`, exactly as the code's own documented logic predicts given `split_branches_enabled=false`. **Deliberately did not flip that live, agency-wide setting to force a "blocked" result** — doing so would have changed real data visibility for any real concurrent user for the duration of the test, which is a worse risk than leaving one code path statically-verified rather than click-through-proved. The mechanism itself is the same one already live-proven on the authorisation screens tonight; what's untested is specifically "does `split_branches_enabled=true` behave correctly," which no real agency currently exercises.

### FOUND AND FIXED — a real, currently-dormant scoping gap on property document actions

`PropertySgController::saveDocument()`/`download()` (SG = Surveyor-General document integration) checked cross-agency access only (`guardAgency()` — a hardcoded agency-id comparison) and never consulted the same own/branch/agency scope the property record itself uses. Not exploitable *today* — same reason as above, `split_branches_enabled` is off everywhere, so 'branch' scope resolves to 'all' regardless — but wired to become a real gap the moment any agency turns branch-splitting on, since this route would keep ignoring it while the rest of the module correctly respected it. Fixed by wiring in the same `AuthorizesPropertyAccess` trait (`authorizeProperty()`) every other property-mutating/viewing action already uses — `saveDocument()` (a write) gets mutation scope, `download()` (a read) gets view scope, matching the show() page's own breadth exactly. Confirmed a no-op for every real user today (`getDataScope()`/`mutationScope()` both resolve to `'all'` for a real agent right now) — this only changes behaviour once an agency's Data Isolation setting is actually turned on.

### FOUND — real, not invented: the "expired" status has no way to be isolated on this screen

Real agency-1 rental status counts: `withdrawn` 357/359 (two counts taken minutes apart, live data), `expired` 104, `let_out` 70, `to_let` 18-20, `active` 6, `draft` 2-5, `archived` 1, `prospecting` 1. Cross-referenced independently by two separate research passes, same result both times: **`expired` — the SECOND-LARGEST bucket at 104 real properties — has no KPI tile and no filter-dropdown option.** It's excluded from "Available" (`Property::OFF_MARKET_STATUSES`) and only reachable by selecting "All Statuses" and scanning manually. `withdrawn` (the largest bucket) has a filter option but no KPI tile — findable, just not surfaced as prominently. `archived` (1 record) has neither, negligible volume. Every OTHER real status (`to_let`, `active`, `draft`, `prospecting`, `let_out`) is correctly represented — nothing was invented, this is specifically the one real gap.

**Not fixed here, deliberately** — unlike the SG-document guard, this isn't a pure "wire in an existing mechanism" fix: it's a visible addition to a screen agents use daily (a new filter option, possibly a new KPI tile), which is a UI/information-architecture call, not a mechanical completeness fix. Flagging rather than building it unasked.

### Sale vocabulary, navigation, dead controls — confirmed clean

Grepped the full view for sale-only language (Buyer/Seller/Offer/Purchase/Mandate) — the KPI tiles, status set, and listing-type control all correctly relabel or drop for the rentals lens (e.g. "On Market"→"Available", "Sold"→"Rented Out", sale-only statuses dropped entirely rather than mislabelled). The listing-type dropdown becomes a static "Rentals only" label on this entry point — server-enforced, not decorative, initially misread as dead but the gating condition confirmed it's correct. Navigation correctly keeps the Rentals lens via the same route-name + session pattern used elsewhere. No half-wired controls found. The legacy "Hidden→Rentals panel" (`Rental\RentalPropertyController`) still exists in routes but isn't linked from anywhere current — a previously-recorded PARKED decision, not a fresh finding.

### Files changed

- `app/Http/Controllers/CoreX/PropertySgController.php` — `authorizeProperty()` wired into `saveDocument()`/`download()`, `AuthorizesPropertyAccess` trait added

## 12. Rentals → Core Matches / Rental Pipeline — design-standard audit + fixes (AT-402, 2026-09-10, cc4)

Full design-standard audit (Johan's own bar: "proper crud? search / sort / own / branch / agency levels... not me asking for it once we get to that stage") of Core Matches and the Rental Pipeline, reported to Johan/conductor before any substantial fix, per explicit instruction. Findings list delivered separately; this section records only what was subsequently approved and fixed. **Not decided by cc4**: the "no manual add-to-pipeline" behaviour (Johan confirmed this is the intended design, not a gap) and the Viewing Pack download's own/branch/agency check (already correct — the reference pattern the detail page should have matched and didn't; recorded as still-open, not fixed in this pass).

### 12.1 SECURITY CHECK — cross-agency access, proved live before anything else was touched

Per explicit instruction ("prove it LIVE... assume nothing", citing cc1's same-night `exists:properties,id` multi-tenancy bypass on rental-application property-linking). Tested as a real restricted agent (Kym Pollard, agency 1) against the live QA1 site: a contact belonging to a DIFFERENT agency (id 18673, agency 20) hit at `/corex/command-center/buyers/18673` returned a clean **404** — `AgencyScope` on `Contact`'s route-model binding holds. Grepped both `ContactMatchController` and `BuyerDetailController` for the specific cc1 pattern (a validation `exists:table,id` rule, or a client-supplied `property_id`/`contact_id` written without a scope check) — neither controller has one; the only `exists:` rules present target the global `p24_suburbs` reference table, not tenant data. **No multi-tenancy breach found on these two screens.** The previously-reported gap (an agent reading another agent's SAME-agency match/tenant record) is real but is an intra-agency own/branch scoping gap, not the cross-agency class cc1 found — the two are not the same finding and should not be conflated.

### 12.2 Fixed — dead `$match->suburb` (dropped column) silently rendering blank, forever

`ContactMatch::suburbList()` (JSON `suburbs`/`p24_suburb_ids`, actively synced on every save) is the canonical, live source — the model's own docblock says so explicitly ("never the legacy `suburb` (dropped) or derived `suburbs` shadow" refers to avoiding an *even older* raw column, not this one). Four views still referenced the dropped singular `suburb` column directly, always null: `core-matches/all.blade.php`, `core-matches/index.blade.php` (badge + empty-state check), `contacts/show.blade.php` (Saved Matches badge), `contacts/match-results.blade.php` (empty-state check only — its own badge already used `suburbList()` correctly). Beyond the cosmetic blank badge, the empty-state checks had a real behavioural bug: a wishlist with ONLY a suburb set (no other criteria) was wrongly shown as "Any property." Fixed by restoring the correct source (`suburbList()` / `implode(', ', ...)`) in all four, not by removing the field. A fifth false-positive grep hit (`viewing-packs/show.blade.php`'s `$cm->suburb`) was checked and confirmed to be a `Property` model (`coreMatchesFor()` returns `Collection<Property>`), which still has a real `suburb` column — left untouched.

### 12.3 Fixed — "This buyer won't be counted..." showing on a rental wishlist

`resources/views/corex/contacts/_match-form.blade.php` (shared by every entry point — Contact page, Core Matches, Buyer Pipeline drawer) hardcoded "buyer" in the AT-71 uncountable-wishlist warning regardless of `$match->listing_type`. Fixed at the shared source, both sentences, using the same `$match->listing_type === 'rental' ? 'tenant' : 'buyer'` pattern already established on the Buyer/Rental Pipeline detail page (`buyers/detail.blade.php`'s `$isRentalContact`). Verified live against a genuine uncountable rental wishlist (match id 127): now reads "This tenant won't be counted in matches yet... for this tenant to appear in match counts and lists."

### 12.4 Fixed — calendar attendee chip labelling a tenant "Buyer"

The Attendees multi-select on the Schedule/Edit Event modals (`command-center/calendar/index.blade.php`) hardcoded "Buyer" as the chip label for anyone in the `buyer_contact` role UNLESS a server-supplied `role_label` was present. The property-pivot autofill path (`CalendarController`'s owner-lookup endpoint) already carries a real pivot-role label (e.g. literally "Tenant" when the `contact_property.role` pivot says so) — that path was already correct. The gap was the **search-added** path: `searchAttendees()` never told the client anything about the contact beyond name/phone/email, so `contactSearch().add()`'s client-side role-guessing (driven by the *event class*, e.g. "this class's actor is a buyer_action") always fell back to the literal string "Buyer," regardless of what the contact actually is. Fixed by driving the label off the contact, not the screen: `searchAttendees()` now returns `is_rental` per contact (same lens as `buyers/detail.blade.php`'s `$isRentalContact` — primary-or-first wishlist's `listing_type`), `add()` sets `role_label = is_rental ? 'Tenant' : 'Buyer'` when auto-assigning a buyer_contact role, and both chip templates prefer `role_label` before falling back to the hardcoded string. Verified live: `GET .../calendar/search/attendees?q=Edith` (a known tenant) now returns `"is_rental":true` in the JSON payload.

### 12.5 Fixed — Rental Pipeline: search, sort, filter doors added

All three were already real, working query-layer capability with no UI door — the fix in every case was to expose what already worked, not build new capability:

- **Search** (`?q=`): wired to `Contact::scopeSearch()` — the same canonical name/phone/email/id_number search every other contact picker in CoreX uses (including the attendee search above), so this board searches on exactly the fields an agent already reaches for elsewhere. Applied to both the active board and the Won/Success section so a searched-for name never silently vanishes from both.
- **Sort**: the controller already accepted an arbitrary `?sort=`/`?dir=` via a raw `orderBy()` with zero UI door and zero whitelist. Whitelisted to three real, meaningful columns (`name` — two columns, first+last, under one door; `buyer_state`; `last_activity_at`) and wired clickable arrows onto the matching List-view headers.
- **Filter**: `state`/`agent_id` already worked server-side via hand-typed URL params; added real `<select>` dropdowns (auto-submitting on change) to a new search/filter toolbar shared by both Kanban and List views. The agent dropdown's options come from a copy of the same scope+lead-type query with state/agent/search NOT yet applied, so the list of agents never shrinks to nothing once a filter narrows the board.
- Every existing scope/lead-type/view toggle link on the page was extended to also preserve `agent_id`, `q`, `sort`, `dir` — previously several of these silently dropped `agent_id` on toggle (a pre-existing, related gap fixed as part of wiring the same class of control).

### 12.6 Fixed — dates on entries (Johan's own standing request)

- **Core Matches** (all four screens: contact-level tab, the `Core Matches` index, `All View`, the match-results page, the match-edit page): a "Saved {date}" tag added next to each match's type pill, reading `created_at` — every screen was completely missing a date before this.
- **Rental Pipeline**: a "Since {date}" reading `contacts.buyer_pipeline_entered_at` (the same column the buyer/tenant detail page's own "Since" line already uses) added to both the Kanban card (next to the assigned agent) and the List view (new dedicated column, between Agent and Last Activity).

### 12.7 Fixed — Kanban pagination (agency-configurable, per Johan's explicit instruction not to hardcode)

The Kanban board ran one unbounded query per load — `Contact::buyers()...get()` with every buyer/tenant in scope, in every state, grouped client-side, with only a CSS `max-h-[60vh] overflow-y-auto` standing in for pagination. Fine at QA1's volume; at a real agency's volume this loads the entire pipeline onto one page on every visit. Fixed by capping each of the four columns independently (`New`/`Warm`/`Cold`/`Lost`), each queried and limited separately rather than one big fetch sliced afterwards. The cap is a new agency setting, `agency_contact_settings.buyer_kanban_column_limit` (default 50, clamped 10–500 via `AgencyContactSettings::buyerKanbanColumnLimit()`, editable on the Contact Governance settings page under "Buyer Pipeline Default View"), following the exact same pattern as the existing `calendar_max_occurrences`/`mic_match_threshold` agency knobs on the same model. A column that's truncated shows a real "+N more · View all in List" link at the bottom, going to the fully-paginated List view pre-filtered to that state — Kanban stays a lightweight triage view, List stays the tool for volume, nothing is silently dropped. The truncation count itself is computed from the SAME filtered query (scope + lead type + agent + search) so it reconciles with what's actually on screen, not a stale unfiltered total.

**Deliberately NOT added to the Setup Wizard** (non-negotiable #10a normally requires this for every new setting) — this is a display/pagination threshold, not a business policy an agency configures once when onboarding; it sits alongside `buyer_warm_days`/`buyer_cold_days`/`duplicate_mode` and the rest of the Contact Governance settings group, none of which are in the wizard today either (a pre-existing gap for that whole settings group, out of scope to retrofit here). Flagging the omission explicitly rather than silently deciding it, per the rule's own escape valve — Johan's call if this whole settings group should eventually reach the wizard.

### Files changed (§12)

- `app/Http/Controllers/CommandCenter/BuyerPipelineController.php` — search/sort-whitelist/agent-options/kanban column cap
- `app/Http/Controllers/CommandCenter/CalendarController.php` — `is_rental` on `searchAttendees()`
- `app/Http/Controllers/CommandCenter/ContactGovernanceController.php` — `buyer_kanban_column_limit` validation + save
- `app/Models/AgencyContactSettings.php` — `buyer_kanban_column_limit` column, default, resolver
- `database/migrations/2026_09_10_230000_add_buyer_kanban_column_limit_to_agency_contact_settings.php`
- `resources/views/command-center/buyers/pipeline.blade.php` — search/filter toolbar, sortable headers, Since column/card date, kanban "N more"
- `resources/views/command-center/calendar/index.blade.php` — attendee chip `role_label` preference, `add()` sets it from `is_rental`
- `resources/views/command-center/settings/contact-governance.blade.php` — kanban column limit input
- `resources/views/corex/contacts/_match-form.blade.php` — tenant/buyer wording on the uncountable-wishlist warning
- `resources/views/corex/contacts/match-edit.blade.php` — Saved date
- `resources/views/corex/contacts/match-results.blade.php` — Saved date, suburb empty-state fix
- `resources/views/corex/contacts/show.blade.php` — Saved date, suburb fix
- `resources/views/corex/core-matches/all.blade.php` — Saved date, suburb fix
- `resources/views/corex/core-matches/index.blade.php` — Saved date, suburb fix (badge + empty-state)

## 13. Screen D — Rentals → Contacts (AT-403, 2026-09-11, cc4)

Johan, verbatim, raised mid-way through the AT-402 control-centre round: "rental menu - wheres my rental contacts?" Confirmed: Rentals had Core Matches, Properties, Rental Applications, Rental Application Authorisation, and Rental Pipeline — no way to find tenants/landlords without leaving the module. Explicitly NOT a second contact store — one more entry point into the SAME `ContactController`/`corex_contacts` table, following the identical §2/§3/§4 pattern (route-name lock, session lens flag) rather than inventing a new one.

### Menu entry vs. tile — decided: separate menu entry

Johan's own instinct, confirmed correct by the code: contacts are people, not application states, and the FICA/e-sign tile pattern (built for AT-402) exists to filter ONE table's WORKFLOW STATUS, not to browse a different table's records. `ContactController::index()` is a full existing CRUD screen (search/sort/filter/pagination/own-branch-agency all already built) being reused with a locked lens — structurally identical to §2 (Properties) and §3 (Core Matches), not to FICA. Built as `corex.rentals.contacts.index`, same pattern, same file.

### Which contacts count — INCLUSIVE, established from data and code, not guessed

Investigated before writing any filter: `Contact::parentTypes()` (`belongsToMany` via the `contact_contact_type` pivot, AT-79) is the real multi-type store — a contact can hold several types at once today, and `Contact::syncTypeAssignments()` is the one write path (re-derives `contacts.contact_type_id`, the single-value mirror, as the lowest-`sort_order` assigned parent). **The mirror column is therefore NOT a safe filter basis** — a contact whose primary mirror shows "Seller" (lower sort_order) can still genuinely hold "Tenant" as a secondary type via the pivot, and Johan's own scenario (a seller who starts renting in the meantime) is exactly that case. `Contact::scopeRentalRelevant()` filters on the pivot instead, matched on `esign_role` — the same semantic key `ContactController::index()`'s own existing `?type=` filter already uses for its Seller/Buyer/Lessor branches:
```
esign_role IN ('lessor','lessee') via parentTypes()
  OR contact_property.role IN ('landlord','lessor') via properties()
```
The second half is required, not optional: `esign_role='lessor'` contacts are undercounted via the type pivot alone (13 vs 66 real matches, measured live) because most landlords are linked via the property pivot, never actually assigned the Lessor/Landlord type — the exact same gap the existing Seller/Lessor `?type=` filter branches already document and correct for. Real taxonomy confirmed live on QA1: `esign_role='lessor'` → id 6 "Lessor", id 43 "Landlord"; `esign_role='lessee'` → id 10 "Lessee", id 11 "Tenant", id 12 "Prospective Tenant", id 44 "Lead, Tenant" (legacy dup). The filter is INCLUSIVE by construction (an `orWhere`, never a replacement of the sale-side view) — a contact matching it never disappears from the plain Contacts screen.

**Coordination note, on the record**: cc6 is reportedly building "rental approval adds Tenant, doesn't replace" on the approval path. Checked (not assumed) before building: no trace of that work exists anywhere in this checkout at build time — not in `/corex-qa1`'s git status/diff, not in any recent commit or merged branch, not in the currently-merged approval flow. Either not yet started or not yet pushed. `scopeRentalRelevant()`'s basis (the `contact_contact_type` pivot, `esign_role='lessee'`) is the best-evidenced answer available — it's the SAME pivot `Contact::syncTypeAssignments()` (the one write path for type assignment) already writes to, not an invented parallel signal — but it has not been cross-checked against cc6's actual code, because that code was not visible. Flagging this explicitly rather than silently assuming agreement; reconcile if cc6's real write path differs once it lands.

### The lock

`corex.rentals.contacts.index` → `ContactController::index()` (same action as `corex.contacts.index`), detected by route name exactly like §2/§3/§4, `session(['corex.lens.contacts' => $isRentalEntry])` set on every visit. Applied AFTER the query string is read (`$query->rentalRelevant()`), so a hand-edited `?type=<buyer-id>` on this entry point narrows WITHIN the rental set (ANDs with it) rather than escaping it — proved live: `?type=4` (Buyer) on the rentals entry returned exactly the 15 contacts who are BOTH a buyer AND rental-relevant (verified independently via `Contact::where('is_buyer',1)->rentalRelevant()->count()` = 15), never the full 469 plain buyers. The top filter `<select>` is separately narrowed to just Lessor/Lessee (`$typeFilterOptions`) so the dropdown never offers a type the lock would silently reject anyway — verified live, exactly two options render on this entry point.

### Scoping — proven live, all four surfaces Johan named

- **List**: Kym Pollard (agent, ceiling 'all' on contacts, but the screen's own default narrows to "mine" first) — default view on the rentals lens shows exactly her one rental-relevant contact (id 16414); widening to "All" reveals the full agency set, still rental-relevant only.
- **Detail**: unchanged — `Contact`'s existing global `ContactScope` already gates route-model binding on `show()`/`update()`/`destroy()`; this build added no new route parameter and no bypass, so the existing guarantee is untouched, not re-proven from scratch.
- **Export**: `ContactExportController` is a SEPARATE route with no shared name to detect the lens by, so the lens travels as an explicit `?rental=1` the export link itself sets only when rendered from the Rentals lens; applied unconditionally in `buildQuery()`, including under `?all=1` ("export everything in my scope" must still mean "everything rental-relevant"). Verified via direct query: `?rental=1` for Kym (own-narrowed) returns exactly 3 — the real count of contacts she created that are rental-relevant, out of 804 she's created in total.
- **Document download**: `ContactDocumentController::download()` was not modified — it already 404s unless the requested `Document` is actually linked to the requested `Contact`, on top of `Contact`'s own global scope on route-model binding. Nothing in this build touches that path or weakens it.

### What was surfaced, per Johan's ask

- **"What the contact IS in rental terms"**: `Contact::rentalRoleLabels()` reads both signals `scopeRentalRelevant()` filters on (from already-eager-loaded `parentTypes`/`properties`, no N+1) and renders Tenant/Landlord badges per row on this entry point — both, if a contact holds both, same inclusive rule as the list filter itself.
- **Rental history**: the Contact page's existing Rental History tab (`_rental-applications-tab-body.blade.php`, built under AT-392) already exists and needed no change — confirmed present and reachable, not rebuilt.
- **Real empty states**: distinct copy for "no rental contacts yet" vs. "none match this filter," on this entry point only — the plain Contacts screen's own empty state is untouched.
- **Nav context**: sidebar collapses to one "Contacts" subitem inside Rentals (same permission, `access_contacts`, no new grant), highlighted via `session('corex.lens.contacts')` exactly like Properties/Core Matches/Pipeline, with a matching exclusion added to the MAIN "Contacts" nav item so only one "Contacts" link is ever active at a time. Proved live: visiting contact 16414 after entering via the Rentals lens keeps the Rentals → Contacts sidebar item (not the main Contacts item) highlighted `active`.

### Files changed

- `routes/web.php` — `corex.rentals.contacts.index` route, same pattern as §2/§3/§4
- `app/Models/Contact.php` — `scopeRentalRelevant()`, `rentalRoleLabels()`
- `app/Http/Controllers/CoreX/ContactController.php` — `$isRentalEntry` detection + session lens flag, lock applied post-query-string, `$typeFilterOptions` (dropdown narrowed on this entry point), conditional `properties` eager-load
- `app/Http/Controllers/CoreX/ContactExportController.php` — `?rental=1` lock in `buildQuery()`
- `resources/views/corex/contacts/index.blade.php` — lens-aware title/subtitle, narrowed type dropdown, rental-role badges per row, `?rental=1` on the export links, lens-aware empty state
- `resources/views/layouts/corex-sidebar.blade.php` — new Contacts subitem inside Rentals, top-level Rentals group active-check extended, main Contacts nav item's mutual-exclusion extended
