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
- No change to the property creation **wizard** (§5.2 items 15-16) — its rental fields
  and its shared sale/rental price field stay exactly as they are; this spec's
  consolidation is scoped to the tabbed edit screen only.
- No fix to the pre-existing gap where `rental_price_type`/`lease_period`/`lease_type`
  aren't cleared by the sale/rental type-switch clone logic (§5.5) — flagged for the
  record, not remediated here.
