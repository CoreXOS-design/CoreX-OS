# Rentals — Shared-Screen Entry Points, Rental Tab, Tenant "Won" Trigger

Status: **SPEC ONLY — no code, no branch, no migration.** Written per Johan's explicit
STEP 2 authorization. Supersedes any earlier draft of this idea — this version reflects
Johan's correction that the three "mirror" screens must NOT be forked code.

Related specs: `.ai/specs/rental-applications.md` (Rental Applications module — separate
feature, not touched by this spec). `.ai/specs/corex-domain-events-spec.md` (event
catalogue referenced in §7).

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

This spec uses `new/warm/cold/lost/won` throughout. Screen C (§5) is this exact
five-state board with the rental lens applied — no new states are introduced for rentals.

---

## 2. Screen A — Rentals → Properties

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

**Scoping:** see §8 — Properties already uses `PermissionService::getDataScope()`, no
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
here (see §11).

**CRUD:** drag-drop state transitions (`updateState()`, line 185–195) are unchanged —
same endpoint, same `BuyerStateService::transitionTo()`. No forking of the drag-drop
logic for rentals.

---

## 5. Property detail screen — the Rental tab

### 5.1 Investigation findings (facts, not proposal)

The property detail screen (`resources/views/corex/properties/show.blade.php`) has ONE
existing rental-conditional **tab**: **"Rental Images"** (`$tab['key'] === 'rental-images'`,
defined at line 1210, gated at line 1221 to render only when
`strtolower($property->listing_type) === 'rental'`). This is what Johan saw on property
2517. Its body (from line 4106) is a photo-gallery manager
(`x-data="rentalImages(...)"`) with its own upload/save/delete routes
(`corex.properties.rental-images.upload/save/delete`) — it is purely a media/gallery
concern, parallel to the existing (sale-and-rental) "Gallery" tab, not a data-form tab.

**Rental-specific data fields do NOT live in their own tab today.** They live inside a
collapsible **section** called "Rental Details" (`<section id="sec-rental">`, line
3421–3450) nested inside the **Info** tab's main edit form, visible only when
`listing_type === 'rental'`. That section currently holds five fields:
`rental_amount`, `deposit_amount`, `rental_price_type`
(per month/per sqm/per day/per week/per year), `lease_start_date`, `lease_end_date`.

Two more rental-shaped fields exist on the `Property` model as fillable/cast columns
but are rendered **elsewhere in the Info tab, not inside the Rental Details section**:
`lease_period` (free-text, line ~1995) and `lease_type` (select, line ~2038) — both
currently sit in a different part of the Info tab body, disconnected from the "Rental
Details" section above. `pet_friendly` (boolean, cast at line 575) exists as a column
but no `<input>` for it was found anywhere in `show.blade.php` or `wizard.blade.php` —
it is captured nowhere in the UI today; it is a genuinely dead/unused field as far as
data entry goes.

**Landmine, not part of this feature — flagged so it is never confused with it:** this
codebase also has a **completely separate, pre-existing "Rentals" module** —
`App\Models\Rental` (table `rentals`), `App\Http\Controllers\RentalsController`
(`/rentals`, `/rentals/create`, routes/web.php:1666-1684), `RentalAmountVersion`
(rent-escalation history), tracking **active managed lease/tenancy agreements**
(lease address, lease dates, month-to-month flag, agents pivot) — this is property
*management* of leases HFC administers, not a marketing listing's rental attributes.
Its permission module key is already `rentals` (`config/corex-permissions.php` lines
52, 82-83, 103-106 — `rentals.view`/`.create`/`.edit`/`.archive`). **This existing
`rentals` module key is therefore NOT available for anything specced in this document**
— see §10.

### 5.2 Exhaustive inventory — every rental-specific thing on the property screen today

Johan's addition: *"once we have a proper rental section on a property it would make
sense to move the rest of the rental on properties to the rental tab."* This is the
full inventory that instruction requires, gathered by reading the property show view,
the property edit form, the creation wizard, the model, the controller, and every
consumer (portals, matching, documents, mobile) that reads a rental-classified field —
not just the tab strip.

**Authoritative source used to scope this list, not a guess:** `PropertyController`
already has its own canonical definition of "the rental fields" — the type-switch clone
logic (`app/Http/Controllers/CoreX/PropertyController.php:1653-1654`, inside
`makeClone()`) nulls out exactly this set when a rental draft is switched to sale:
`rental_amount, deposit_amount, commission_percent, admin_fee, marketing_fee,
lease_start_date, lease_end_date, rental_images_json`. This spec's inventory is built
against that list plus everything else found by direct inspection of the Blade views —
the two do not fully overlap, and the gaps between them are exactly the "scattered"
problem Johan is describing.

| # | Item | Location | What it is | Move / Stay | Reason |
|---|---|---|---|---|---|
| 1 | **Rental Images** tab | `show.blade.php:1210` (tab def), `:1221` (visibility gate), `:4106-4114+` (body); backed by `rental_images_json` (Property.php:554/572) and `rental_upload_keys` (Property.php:463/567, written by `App\Http\Controllers\Api\MobileRentalImagesController`) | Photo/inspection-gallery manager, own upload/save/delete routes | **STAYS its own tab** | Media-management concern, parallel to the existing Gallery tab's job, not a data-form field. Merging a photo manager into a data-form tab would make both worse. See §5.3 for the fold-in question, still open for Johan. |
| 2 | "Rental Details" **section** — `rental_amount`, `deposit_amount`, `rental_price_type`, `lease_start_date`, `lease_end_date` | `show.blade.php:3421-3450`, inside the Info tab's edit form | The only grouped rental data-entry block that exists today | **MOVES** (the `<input>`s relocate into the new Rental tab) | Exactly the "scattered rental info" Johan means — five fields buried inside a collapsible section of a tab that is mostly about non-rental info. |
| 3 | `lease_period` input | `show.blade.php:~1995`, elsewhere in the Info tab, disconnected from item 2's section | Free-text lease period | **MOVES** | Same field class as item 2, currently orphaned even further from its siblings than item 2 is — the clearest case of "scattered." |
| 4 | `lease_type` input | `show.blade.php:~2038`, elsewhere in the Info tab, disconnected from item 2's section | Lease type select | **MOVES** | Same as item 3. |
| 5 | `pet_friendly` | Column exists (`Property.php:452` fillable, `575` cast) — **no `<input>` anywhere in `show.blade.php` or `wizard.blade.php`** | A rental attribute with no UI home at all today | **MOVES** (i.e. gets its first-ever input, placed directly in the new Rental tab) | Not currently "scattered" so much as unreachable — the Rental tab is the fix, not a relocation. No migration; column already exists. |
| 6 | `commission_percent`, `admin_fee`, `marketing_fee` | Columns exist (`Property.php:421-423` fillable, `606-608` cast), validated in `PropertyController::store()`/`update()` (lines 893-895, 1276-1278), included in item 2's authoritative "rental fields" clone-clear list (line 1653-1654), consumed by Docuperfect document generation (`WebTemplateDataService.php`, `ESignWizardController.php` commission/marketing-fee defaults) and captured today only via the **mobile app** (`Api/MobilePropertyController.php:292-294` validation, `:1718-1720` read) | Rental commission/fee figures the system already treats as rental-only (see the clone-clear list) but has never surfaced on the desktop screen | **MOVE** (first-ever desktop inputs, placed in the new Rental tab) | Same situation as `pet_friendly` — three more fields the codebase already classifies as rental-exclusive (via its own clone logic) that simply have no desktop UI. Completing this is squarely inside "move the rest of the rental info to the rental tab," not new scope. See the stop-flag in §5.5 before building these three specifically. |
| 7 | `rental_price_type` labels | Also read directly (raw column, not an accessor) by both portal mappers — `PrivatePropertyListingMapper.php:941`, `Property24ListingMapper.php:113` | Feeds the rental-rate-cadence field on both portal syndication payloads | **MOVES with item 2** | See §5.4 — a raw-column server-side read is unaffected by where its `<select>` renders on screen, since the column name and form target are unchanged. |
| 8 | **Page-header / list / portal price display** — `$property->formattedPrice()` | `show.blade.php:1064` (mobile header strip, persistent across all tabs), `:1313` (Overview tab summary card); `index.blade.php:771,839,1002,1044` (list rows); `live-preview.blade.php:223,389` (public portal-facing page) | The headline price shown everywhere a property is glanced at | **STAYS exactly where each is — never moves** | This is Johan's own flagged concern, and it checks out: burying this would be a real regression. See §5.4 for why moving item 2's input has zero effect on any of these. |
| 9 | `effectivePrice()` / `formattedPrice()` reads in syndication & matching | `Property24ListingMapper.php:45`, `PrivatePropertyListingMapper.php:76,351`, `MatchingService.php:375`, `PropertyMatchScoringService.php:239` | Portal feed price + buyer-match scoring, all already listing-type-safe | **STAYS — not UI, not moved** | Confirmed these all call the `effectivePrice()`/`formattedPrice()` accessors, never `$property->rental_amount` directly for price. Independent of any tab-relocation. |
| 10 | "Change listing type" toggle/action | `show.blade.php:743, 1719-1871`; handled by `PropertyController::makeClone()`/`changeType()` | Switches a draft between sale and rental (archives current, opens a new draft of the other type) | **STAYS in Info tab**, next to the `listing_type` control | Applies to sale properties too (converts sale→rental); it is a type-classification action, not rental-only content, and must sit next to the field it operates on. |
| 11 | `listing_type` field itself (select/hidden) | `show.blade.php:1844-1865` | The switch that determines whether the new Rental tab is visible at all | **STAYS in Info tab** | Cannot live inside the tab it controls — an agent switching a draft *to* rental would need the Rental tab already visible to find the switch that reveals it. Circular if moved. |
| 12 | `listing_type_pending` banner | `show.blade.php:71-78` | Post-type-change confirmation banner | **STAYS** | General to either direction of a type change, not rental-content storage. |
| 13 | "Not selling" / Prospecting banner | `show.blade.php:~1027-1042`, `PropertyController::markNotSelling()` | Prospecting-stage action | **STAYS** | Applies identically to sale and rental prospecting stock; not rental-specific. |
| 14 | Sale/Rental filter on the Properties **list** screen | `index.blade.php:356-358` | List-level filter, not property-detail content | **STAYS** (out of scope for this section — this is §2's shared-screen filter, already specced) | Different screen, already covered. |
| 15 | Wizard (creation-time) rental fields — deposit, lease start/end, `rental_price_type` | `wizard.blade.php:517-524+` ("Rental-only fields" block), step-3 Alpine data | Creation-time capture of a subset of item 2's fields | **STAYS in the wizard** | See §5.5 — the wizard is a separate, sequential creation flow; this spec's consolidation is about the tabbed **edit** screen, not the wizard's steps. |
| 16 | Wizard step-1 shared price field (`s1.price`) | `wizard.blade.php:180-190` | The **same control** used for both "Asking price" (sale) and "Monthly rental" (rental), relabelled by `x-show` on `s1.listing_type` | **STAYS in the wizard, unmodified** | Structurally identical to item 8's concern — this is the headline price at creation time, sharing one input with sale. It is not a rental-only field the way items 2-6 are; it is the same slot sale uses. |
| 17 | Tenant link (who currently leases it) | Reuses `PropertyContactController::link()` (`app/Http/Controllers/CoreX/PropertyContactController.php:103`, `LINK_ROLES` includes `tenant` at line 86) via the existing Contacts tab | Contact↔Property linking, role-based | **Read-only summary surfaces in the new Rental tab; the linking mechanism itself stays on the Contacts tab** | No second linking UI is built — see §6 for how this feeds the tenant-won trigger. |

### 5.3 Recommendation (proposal — Johan approves before anything is built)

Add one new tab, labelled **"Rental"**, to the same `$tabs` array
(`show.blade.php` line ~1206) as a sibling of `overview`/`info`/`gallery`/`rental-images`
— same conditional-visibility pattern already proven for `rental-images` (line 1221):
render the tab button only when `listing_type === 'rental'` (or `$isNew` before a type
is chosen), same property, same screen, no new route. It holds items 2-6 and 17 from
the table above (everything marked MOVE), plus the three new fields proposed below.

**Rental Images (item 1) stays a separate tab, not folded in** — reaffirmed after the
full inventory, not just the earlier surface read. Still flagged for Johan's
confirm/override, not decided.

**New fields proposed** — genuinely new, not found anywhere in the inventory above:

| Field | Why |
|---|---|
| Furnished status | A sale property has no furnishing state; furnished/unfurnished/part-furnished is standard rental-listing information a prospective tenant needs and CoreX does not currently capture anywhere |
| Availability date | When the property is available to move in — distinct from `lease_start_date` (a signed lease's start), needed even before a lease exists so the listing can advertise "Available from" |
| Utilities included | Whether water/electricity/levies are included in the monthly rental — directly affects what a tenant is comparing between listings |

These three require three new nullable columns on `properties` — a small, additive
migration, no change to any existing column or status governance. No other new fields
are proposed; anything beyond this list needs Johan to name it specifically.

### 5.4 Why the move is mechanically safe — no forked path, no broken display

Every display consumer found in §5.2 (items 8, 9) reads price through
`formattedPrice()`/`effectivePrice()`, and every portal/matching consumer (items 6, 7,
9) reads its field as a plain model attribute (`$property->rental_amount`,
`$property->rental_price_type`, etc.) — **none of them care which Blade tab an
`<input>` sits in.** Relocating an `<input>`'s position on screen has zero effect on
either kind of consumer, provided the column name is never renamed (it isn't, anywhere
in this spec) and the input still submits to the same save path.

**On the save path specifically:** today, items 2-4's inputs sit inside the single
`<form id="prop-update-form">` that wraps the Info tab's content, and that form closes
immediately after item 2's section (confirmed: `</form>{{-- /prop-update-form --}}`
follows the Rental Details section directly). The codebase already has the pattern for
"an input that visually lives in a different tab pane but still submits with the main
form" — the Gallery tab's create-mode file input already uses
`form="prop-update-form"` (an HTML5 attribute that binds an input to a form by id
regardless of DOM nesting) to submit into the same form from outside it. **The new
Rental tab uses the identical `form="prop-update-form"` pattern for every relocated and
new input** — so `PropertyController::update()` receives the exact same POST fields it
receives today, under the exact same names, validated by the exact same rules. No
controller change, no validation-path change, no new route. This is a pure UI
relocation, not a data or save-path change.

### 5.5 Stops and flags found during this investigation

**No stop was found against the move itself.** Every consumer of every relocated field
(portal syndication, matching, document generation, mobile) was traced and confirmed to
read either an accessor (`formattedPrice()`/`effectivePrice()`) or a plain model
attribute — never the Blade markup's position — so nothing breaks by moving where the
`<input>` renders. This was the actual risk Johan asked to be checked for, and it does
not materialise.

**One flag, informational only, not created by this spec and not fixed by it:** the
type-switch clone logic's canonical "rental fields" list (§5.2's authoritative source,
`PropertyController.php:1653-1654`) clears `rental_amount`, `deposit_amount`,
`commission_percent`, `admin_fee`, `marketing_fee`, `lease_start_date`,
`lease_end_date`, `rental_images_json` when a rental draft is switched to sale — but
does **not** clear `rental_price_type`, `lease_period`, or `lease_type`. This is a
pre-existing gap in that list, unrelated to where any input renders on screen — it
exists today regardless of this spec and is not worsened by moving the UI. Not proposed
for a fix here (out of scope, per non-negotiable #1 — a fix would be a separate,
explicitly-scoped item); noted so it's on the record rather than discovered later and
mistaken for something this move introduced.

**Stop-flag on items 6 (`commission_percent`/`admin_fee`/`marketing_fee`) specifically:**
before building their first-ever desktop inputs, confirm with Johan that these are
rental-exclusive in practice, not just rental-exclusive in the clone-clear list. The
Docuperfect side (`WebTemplateDataService.php`) reads them generically off "property"
without checking `listing_type`, meaning a sale property's mandate/commission documents
could theoretically want a `commission_percent` too. Because the clone logic already
nulls these out on every switch to sale, the system has already made this call — adding
their inputs only inside the (rental-only-visible) Rental tab changes nothing for a
sale property that has no route to set them today. This is presented as confirmation of
existing behaviour, not a new restriction, but it is exactly the kind of "would a move
break something outside the property screen" question Johan asked to have checked
before anything is built.

### 5.6 Migration path for users — nothing changes except which tab it's under

No data changes, no column renames, no URL changes, no permission changes. An agent's
existing bookmarked property edit link opens the same property; the fields have simply
moved one tab over.

For the "an agent who knows where a field lives today will go looking for it" problem
specifically: the new Rental tab appears immediately adjacent to Info/Gallery/Rental
Images on every rental-listing property, using the same plain tab-bar pattern already
in use — no rearrangement of the surrounding tabs, so the Rental tab is simply the next
thing an agent scanning the tab bar encounters, in the same place a Contacts or Notes
tab would be found. Beyond that positional discoverability, a one-time dismissible
inline note ("Rental details have moved to the Rental tab") in the old Rental Details
section's former spot, shown for a limited period after ship, is a reasonable option —
but it's a UX add, not a requirement, and is flagged for Johan to decide he wants it
rather than assumed (see §10).

---

## 6. Tenant "Won" trigger

### 6.1 Johan's description, verbatim

*"sales is won when buyer is linked to a dr2 deal. rentals would be if a tenant is
linked to a property and the property gets marked leased out - maybe at this stage we
ask who leased it. and if tenant on pipeline that marks them as won?"*

### 6.2 Investigation — does a "leased out" status already exist?

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

### 6.3 The trigger mechanism — a new, separate listener

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

### 6.4 "Who leased it" — where it appears, and why it is never a dead end

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
  §6.3 finds no linked tenant and nothing is marked won; the agent (or anyone with
  Contacts access) can link a tenant to the property later via the existing Contacts
  tab at any time, and if that later link happens to be to a Contact who is also on the
  rental pipeline, that is a separate manual action, not this trigger (this trigger
  only fires at the status-change moment, per Johan's description — see §11 for whether
  a later manual tenant link should also be able to trigger "won" retroactively, which
  is a real open question this spec does not decide).

---

## 7. Scoping — Role Manager standardisation

Johan's ruling, verbatim: *"all should be in role manager - so we can standardize and
build same for rentals."*

### 7.1 The three new entry points (§2, §3, §4)

All three use `PermissionService::getDataScope($user, $module)` from day one — same
mechanism as the existing Properties screen, same Role Manager UI pattern already built
for e.g. `properties.view` / `core_matches.view`. Each needs its own module/permission
keys (see §10 for why none of these may reuse the existing `rentals` module key):

| Entry point | Permission key(s) | Module key for `getDataScope()` |
|---|---|---|
| Rentals → Properties | reuses `properties.view` (same screen, same permission — a user who can see Properties at all sees it through whichever entry point their nav shows) | `properties` (already exists, unchanged) |
| Rentals → Core Matches | reuses `core_matches.view` | `core_matches` (already exists, unchanged) |
| Rentals → Rental Pipeline | **new** `buyer_pipeline.view` (does not exist today — see §7.2) | **new** `buyer_pipeline` |

Because Rentals → Properties and Rentals → Core Matches are locked entry points into
screens that already carry their own permission, **no new permission key is needed for
those two** — access to the shared screen already gates access to the rentals view of
it. Their Role Manager scope selector (own/branch/agency) is likewise already the
existing `properties`/`core_matches` selector — nothing new to add to Role Manager for
these two.

### 7.2 The real gap: Buyer Pipeline has no permission key at all today

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
(§7.3) touch the same code, and it is unavoidable: the Rental Pipeline entry point
cannot exist with proper own/branch/agency control until Buyer Pipeline has a
`PermissionService`-compatible permission key. Building `buyer_pipeline.view` is
therefore in scope for the three-new-screens work, not deferred to §7.3.

### 7.3 The two sales originals — flagged as its own, separately-gated work item

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
migrated into per-role Role Manager defaults (see §7.2 — `buyer_pipeline.view` is being
created anyway; this migration is folded into that same work if/when Johan approves
§7.3, not duplicated).

**This is presented for Johan's separate go-ahead. Nothing in §2/§3/§4/§7.1/§7.2 depends
on §7.3 being approved — the three new entry points work correctly with Core
Matches/Buyer Pipeline exactly as they are today; §7.3 is a live-behaviour change to the
sales side that stands on its own.**

---

## 8. Navigation entries

All three new entry points are added to the existing Rentals nav panel
(`resources/views/layouts/corex-sidebar.blade.php`, the `@if($user && ...)` block at
lines 1806-1834, Alpine group key `rental-applications`) as new `@permission(...)`-gated
`<a>` links alongside the existing Rental Applications / Returned Applications / Rental
Application Authorisation links — same panel, same visual pattern, no new sidebar
section:

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

## 9. Naming collision — the `rentals` module key is already taken

Flagged clearly so nobody reaches for the obvious name during build: `module =>
'rentals'` and permission keys `rentals.view`/`.create`/`.edit`/`.archive`
(`config/corex-permissions.php` lines 52, 82-83, 103-106) already belong to the
pre-existing lease/tenancy-management module (`App\Models\Rental`, table `rentals`,
`RentalsController`, §5.1's landmine). **None of this spec's new permission or module
keys may be named `rentals`.** The new keys introduced by this spec are
`buyer_pipeline.view` (module `buyer_pipeline`, §7.2) — `properties` and `core_matches`
are reused unchanged, not renamed.

---

## 10. Items flagged for Johan's decision — not decided in this spec

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
6. **Retroactive tenant-won linking (§6.4)** — the trigger as specced fires only at the
   moment a property's status changes to "Let Out". If a tenant is linked to an
   already-let-out property *after* the fact (via the ordinary Contacts tab, not through
   the "who leased it" prompt), should that also mark them won? Johan's description
   describes the status-change moment specifically; this spec does not extend the
   trigger beyond that without being told to.
7. **§7.3 (standardising Core Matches and Buyer Pipeline scoping onto Role Manager)** —
   presented with its exact live-behaviour consequences; needs its own explicit
   go-ahead, separate from the three new screens.
8. **Exact final class/listener names** (`MarkTenantWonOnPropertyLetOut` etc.) are
   illustrative — normal build-time naming, not a decision Johan needs to make.

---

## 11. Out of scope (explicitly, so it is never assumed later)

- No change to Property Status governance, the status list, or any existing status
  slug — `let_out` is used exactly as it exists today.
- No widening of `MarkBuyerWonOnPropertyLink`'s `BUYER_ROLES` — sale-side "won" logic is
  untouched.
- No forked controller, view, or query path for any of the three screens — see §0.
- No change to the pre-existing `Rental`/`RentalsController`/`rentals` lease-management
  module — it is unrelated and untouched by this work.
- §7.3 (sales-side scoping retrofit) does not ship as part of this spec's build unless
  and until Johan separately approves it.
- No change to the property creation **wizard** (§5.2 items 15-16) — its rental fields
  and its shared sale/rental price field stay exactly as they are; this spec's
  consolidation is scoped to the tabbed edit screen only.
- No fix to the pre-existing gap where `rental_price_type`/`lease_period`/`lease_type`
  aren't cleared by the sale/rental type-switch clone logic (§5.5) — flagged for the
  record, not remediated here.
