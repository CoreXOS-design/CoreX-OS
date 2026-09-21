# Rental Details — Agency-Definable Fields & the Advert Block

**Status: SPEC ONLY. No code, no branch, no migration.** Written per the
conductor's explicit instruction, following her rulings below. This
supersedes the "parked" status this file carried since 2026-09-13 — Johan
has now picked the idea back up and expanded it. The original parked
capture is preserved verbatim in §0 as the historical record; everything
from §1 onward is the actual spec.

**Multi-agency throughout. Every rule an agency setting with a sensible
default. Nothing outside rentals. Nothing on Staging, nothing on live.**

Related specs: `.ai/specs/rentals-shared-screens.md` §11 (the Rental tab
as it exists today — COMPLETE, Parts 1-5, landed on QA1 2026-09-10; this
spec builds on top of that tab, does not re-open it), `.ai/specs/
rental-application-field-config.md` / the `RentalApplicationCustomField`
model (the pattern this spec mirrors), `.ai/specs/rental-work-orders.md`
(the staged-build-plan precedent §8 follows).

---

## 0. History — the original parked capture (2026-09-13), verbatim

*Preserved exactly as first written, for provenance. Everything Johan
says below is still true and still governs; §1 onward turns it into a
buildable spec and resolves the open questions he explicitly left
unanswered at the time.*

> Theres a couple of bits of info from the rental screen that I want to
> specifically use to build the portal ads but we can look at that later
> as we essentially going to use some of the info from rental screen to
> include in the ads - things like - deposit / lets assist / admin fee /
> utilities (included / excluded), etc. the idea Im playing with is if
> the agent completes these on the rental tab we build ... this is the
> information agents add manually to their ads. so if we hold all of
> this on the rental tab then we can have a tick on each on the rental
> tab that says - include in advertising. Now its as simple as building
> a text block at the end of the description field on properties where
> this information is displayed neat and tidy - and the massive plus
> side is that the agent dont have to capture it manually on advertising
> and cant forget the information that needs to be included

**The worked example** (real HFC advert):
<https://hfcoastal.co.za/property/stunning-little-shop-to-rent-in-port-edward-6135>

> Upfront costs: R4710 Deposit R4710 One Month's rental R1500 Once off
> admin fee R1500 Utility deposit. Exclude utilities which will be
> billed separately from the rental per month.

Three open questions were left unanswered on purpose in the original
capture — resolved now in §5:
1. Is the block written into `description` once, or generated fresh
   every time the description is displayed/exported? (§5.2)
2. What happens to existing hand-typed adverts that already contain this
   information, once a generated block also starts appearing? (§5.5)
3. Do portal feeds take `description` verbatim, such that a render-only
   (never-written-in) block would never actually reach the portal ad?
   Confirmed at the time: yes, P24 reads the raw stored field — this
   remains true (§5.1) and is the deciding fact behind §5.2's answer.

---

## 1. The two-tier field model, and why

Johan's ruling, verbatim: *"under settings rentals agencies can add
anything to this screen they desire... can we have free text, yes / no,
qty, and value inputs on a description and this builds the rental
screen?"* — read together with: *"we have a company called lets assist
that we add to our rentals. not all agencies will have this... for the
next agency it would mean nothing."*

**This is not pure free-form.** Two tiers:

- **CORE FIELDS — CoreX-owned, guaranteed on every agency, fixed.**
  Monthly rental, deposit, admin fee, available date, lease term. These
  already exist as real columns on `properties`
  (`rental_amount`, `deposit_amount`, `admin_fee`, `occupation_date`,
  `lease_period`/`lease_start_date`/`lease_end_date` — all built and
  landed, `.ai/specs/rentals-shared-screens.md` §11.2-11.5). **Nothing
  in this spec changes the core fields themselves** — they are the
  fixed floor every later feature stands on.
- **AGENCY-DEFINED FIELDS — on top, per Johan's four types.** Anything
  else an agency wants: Lets Assist (yes/no) for HFC, meaningless
  elsewhere; a "key deposit" line for one agency; a "parking bays"
  quantity for another. Each has a label/description, a type, and (per
  §4) a tick for whether it appears on the advert.

**Why the split, in Johan's own reasoning, restated precisely because it
is the load-bearing decision of this whole spec:** rental financials and
lease-agreement generation are coming. A later feature that computes
"total upfront cost" or drafts a lease agreement needs to know, with
certainty, that `rental_amount` and `deposit_amount` exist and mean the
same thing on every agency in the system. If everything were
agency-defined, that certainty doesn't exist — a feature built against
"whatever fields happen to be defined this week" has nothing reliable to
read, and the moment it needs a guaranteed field, that field has to be
invented retroactively and backfilled everywhere, which is exactly the
kind of rebuild this two-tier split exists to prevent. Lets Assist
cannot be a core field (HFC-only, meaningless elsewhere) and monthly
rental cannot be agency-defined (every future feature needs it to always
exist) — the two tiers are not a compromise, they are two genuinely
different kinds of fact about a rental.

---

## 2. Reuse verdict — mirror `RentalApplicationCustomField`, don't share its table

**Investigated directly, not assumed.** `RentalApplicationCustomField`
(`app/Models/RentalApplicationCustomField.php`, migration
`2026_09_20_100000_create_rental_application_custom_fields_table.php`,
controller `app/Http/Controllers/CoreX/
RentalApplicationCustomFieldController.php`) already implements exactly
the shape Johan is describing, one entity over: `agency_id`, `key`,
`label`, `help_text`, `field_type` (a plain string column, not a MySQL
enum — deliberately, so a future type never needs a migration),
`options` (json, for a choice-list type), `required`, `shown`,
`sort_order`, `created_by`, soft-deletes. Values are stored as a single
JSON column on the host row (`rental_applications.custom_field_values`,
keyed by the field's own `key`) — not a separate per-value table. Admin
CRUD (create/update/archive/restore/reorder) lives in its own
controller, its own settings-page section, gated by its own permission.

**Verdict: build a new, parallel table with the identical column shape —
do not add property-rental-details rows into the existing
`rental_application_custom_fields` table.** Reasoning:

1. **The value side is already hard-wired to the wrong host.**
   `custom_field_values` lives on `rental_applications`, and the
   `TYPE_FILE` validation path hard-codes `source_type =
   'rental_application'` (`RentalApplication.php:816`) when resolving
   an uploaded document. A property's rental-details tab is a
   completely different host — its own fields are written directly onto
   `properties` columns via `PropertyController::updateRentalDetails()`
   (`app/Http/Controllers/CoreX/PropertyController.php:2461`). Sharing
   the table would mean bolting a second, unrelated host concept onto a
   table whose value-storage half is already committed to the first
   one.
2. **Sharing would require a discriminator on every existing query.**
   Every static helper on `RentalApplicationCustomField`
   (`activeFor()`, `allFor()`, `generateKey()`'s de-dup) and every
   controller/view call site queries `where('agency_id', ...)` with no
   scope filter today. Reusing the table means adding an `applies_to`
   column and auditing every one of those call sites (plus the four
   existing `RentalApplicationCustomField*Test.php` files) to make sure
   none of them accidentally pick up a property-scoped row. That is
   coupling two unrelated features for a savings that is purely
   line-count, not architecture — exactly what `CLAUDE.md`'s "fix the
   class, not the instance" standard argues against.
3. **The `key` namespace is intentionally scoped to one JSON blob's
   lifetime.** `generateKey()` dedupes against every key an agency has
   *ever* used for this table, specifically so a key can never collide
   inside `rental_applications.custom_field_values`. A property-details
   field sharing that same key-space would be dedup-safe by accident,
   not by design — two independent key lifetimes coupled for no reason.
4. **This codebase's own convention is parallel, not shared.**
   `RentalApplicationCustomField`'s own docblock says it mirrors
   `RentalApplicationHighlighter` — a third, separate table with the
   identical shape, not a shared one. A peer lane's own recent work
   (agency-editable inspection feature ticks/room-type defaults,
   `RentalInspectionSetting`) chose the same approach: a new home on the
   entity it actually concerns, not a retrofit of an existing
   definition table. That work is architecturally different from this
   need anyway — see the note below — but the "new host, same shape"
   instinct is the established pattern either way.

**Concretely: a new model `PropertyRentalDetailsCustomField`**, same
column set as `RentalApplicationCustomField` verbatim (`agency_id`,
`key`, `label`, `help_text`, `field_type`, `options` json, `required`,
`shown`, `sort_order`, `advertise` — new, see §4 — `created_by`,
timestamps, soft-deletes), same static-helper shape
(`activeFor()`/`allFor()`/`generateKey()`), same controller shape
(`store`/`update`/`archive`/`restore`/`reorder`), and a new nullable
JSON column on `properties` (`rental_details_custom_field_values`,
keyed by the field's `key`) mirroring
`rental_applications.custom_field_values` exactly.

**Note on cc2's inspection-features work, checked so this spec doesn't
misread it as the same problem:** that work (unmerged at time of
writing, `RentalInspectionSetting.inspection_feature_labels` /
`room_type_item_defaults`) stores a flat JSON array or map directly on a
per-agency *singleton* settings row — the right shape for "an agency
picks a subset of, or overrides defaults within, an already-known
catalogue." It has no per-item `id`, no `sort_order`, no per-item
soft-delete, and no `field_type`/`options` concept, because the problem
it solves ("tick which of these known features apply," "override the
default item list for this room type") is not "let an agency invent an
arbitrary new typed question." Johan's ask here — free text / yes-no /
quantity / value, each independently defined, ordered, archivable — is
squarely the `RentalApplicationCustomField` shape, not cc2's. The two
"settings surfaces should behave alike" instruction is satisfied at the
level that matters (both are agency-editable, both avoid the Setup
Wizard for the same reason — no repeater control there — both live on
an existing settings screen) without forcing two different problems
into one schema.

### 2.1 Field types — mapping Johan's four onto the existing enum

`RentalApplicationCustomField`'s real `field_type` values are `text`,
`number`, `date`, `yes_no`, `choice_list`, `file` (confirmed by reading
the model's constants, not assumed from the feature name). Johan asked
for **free text, yes/no, quantity, value** — four types map onto three
existing constants plus one genuinely new one:

| Johan's word | Maps to |
|---|---|
| Free text | `TYPE_TEXT` (existing, unchanged) |
| Yes/No | `TYPE_YES_NO` (existing, unchanged) — Lets Assist is exactly this |
| Quantity | `TYPE_NUMBER` (existing, unchanged) — e.g. "parking bays: 2" |
| Value | **`TYPE_CURRENCY` — new constant** |

**Why a new constant rather than reusing `TYPE_NUMBER` for "value" too:**
the advert block (§4) needs to render a quantity and a Rand amount
differently — "2" vs "R1,500.00" — and a generic number type has no way
to carry that distinction. Because `field_type` is a plain string column
by design ("keeps future type additions migration-free" — the existing
model's own migration comment), adding `TYPE_CURRENCY = 'currency'` is
additive: no migration to the enum itself, no change to any existing
row, no change to `RentalApplicationCustomField`'s own four working
types. `date`/`choice_list`/`file` are not requested by Johan for this
feature — recommend NOT offering them on the rental-details definition
form even though the underlying pattern supports them, so the admin UI
only ever shows the four types actually asked for. Confirm with Johan
before excluding them outright, in case a future agency genuinely wants
a document-type answer (`file`) on this tab (e.g. Lets Assist's own
signed agreement) — flagged, not decided here.

---

## 3. Rental price type — tick-multiple, not single-select

Johan's ruling, verbatim: *"the rental price type has a dropdown with
selections... Im thinking we should work this that - rental price type
can be ticked to which is applicable on this rental, then we give the
inputfields for the values thats ticked?"*

### 3.1 What exists today (confirmed by reading, not assumed)

`properties.rental_price_type` is a single `varchar`, no DB enum
(`2026_03_23_140000_add_pp_visibility_and_rental_columns_to_properties_table.php:19`).
Its five options (`per month`/`per sqm`/`per day`/`per week`/`per year`)
are a **hardcoded PHP array literal duplicated in two places** in
`resources/views/corex/properties/show.blade.php` (lines 4165 and 4369)
— not sourced from any agency-configurable list. `price_per_day`,
`price_per_week`, `price_per_year` already exist as real columns,
already validated, already rendered as **three independent,
always-visible optional inputs with no gating at all** to the dropdown
— an agent can already fill all three regardless of what the dropdown
says, which is a real, confirmed bug sitting directly underneath
Johan's complaint (there is no connection today between "which cadence
is selected" and "which value fields are shown"). There is **no**
`price_per_month` column (the "Per Month" option implicitly maps to the
existing `rental_amount` core field) and **no** `price_per_sqm` column
at all — "Per Sqm" is currently selectable with zero backing value
field anywhere.

### 3.2 The list itself becomes agency-definable — reusing `PropertySettingItem`, not a new mechanism

This is a **closed list of cadence names**, not a set of independently
typed questions — the right precedent already exists and is already
proven on this exact tab: **Furnished Status** was built exactly this
way (`.ai/specs/rentals-shared-screens.md` §11.5) — an agency-managed
`PropertySettingItem` list (same generic mechanism as `property_type`/
`category`/`mandate_type`), manageable via the existing generic
property-setting-item CRUD on Settings → Properties & Listings
(add/edit/reorder/soft-delete/batch-toggle), seeded per-agency via the
existing `provisionDefaultsFor()` pattern so every current and future
agency gets a sensible default list with zero new observer code.

**Recommendation: add `GROUP_RENTAL_PRICE_TYPE` as a new
`PropertySettingItem` group**, seeded with the current five values
(`Per Month`, `Per Sqm`, `Per Day`, `Per Week`, `Per Year`) as every
agency's starting default, editable/reorderable/archivable per-agency
from that point on exactly like Furnished Status already is. This is
**not** the `RentalApplicationCustomField`-style mechanism from §2 —
different problem (closed list of option strings vs. typed field
definitions), same reasoning as the cc2 note above: use the tool that
already fits, don't force one mechanism to do both jobs.

### 3.3 On the property — tick which apply, one input per tick

The dropdown becomes a set of checkboxes (sourced from the agency's
`PropertySettingItem` list above, not hardcoded), one per available
cadence. Ticking a box reveals its own value input (fixing §3.1's gating
bug at the same time it delivers Johan's actual ask); unticking hides
and clears it — mirroring the "only ticked fields contribute a line"
principle already established for the advert block (§4) so the two
features share one mental model, not two.

**A `price_per_sqm` column does not exist and would need to be added**
if "Per Sqm" stays a selectable option — flagged for Johan/build-time,
not assumed either way. "Per Month" continues to map onto the existing
`rental_amount` core field (§1) rather than gaining a parallel
`price_per_month` column, since monthly rental is already a guaranteed
core field and a second column for the identical fact would be exactly
the "captured twice" problem this whole initiative exists to eliminate.

### 3.4 Syndication — neither portal accepts more than one cadence, confirmed

`Property24ListingMapper::mapRentalRate()` (lines 189-200) maps to
P24's `RentalRate` enum (`Month|Week|Day|Year|SquareMetre`) — **one**
value per listing. `PrivatePropertyListingMapper::mapRentalPriceType()`
(lines 932-949, citing "PP Agency Feed Service Rev 4.6 §2.3.1") maps to
PP's own single-value enum (`PerMonth|PerWeek|PerDay|PerM2`). Both
portals send only `rental_amount` (via `Property::effectivePrice()`) as
the actual price today — `price_per_day/week/year` are never read by
either mapper.

**Recommendation, presented as a decision point, not silently assumed:**
ticking multiple cadences is a genuine, useful CoreX/advert-block
capability (a holiday letting really does have both a nightly and a
weekly rate to advertise) — but since neither portal can receive more
than one, the portal submission continues to send exactly what it sends
today (`rental_amount` under whichever single cadence the mapper already
resolves), untouched by this feature. The multi-tick values feed the
**advert block** (§4) and the public website API (already exposes all
three per-cadence columns via `ListingResource`), not the portal
payload. This keeps the existing, working portal integration completely
unchanged while giving Johan the actual multi-rate capability he
described for the surfaces that can use it. If a future ruling wants a
"primary cadence" concept to decide which ticked value becomes the
portal's single submitted rate when it differs from `rental_amount`,
that is a new, separate decision — not required for this build and not
assumed here.

---

## 4. The advert block

Johan's ruling, verbatim: *"an intelligent way to add it as a block at
the bottom of the marketing description from corex before it goes out to
the portals... this will stop agents from forgetting this when they
create the ads."*

### 4.1 The mechanism — per-field advertise tick, computed block, appended

Every core field (§1) and every agency-defined field (§2) gets an
`advertise` boolean (new column on both the fixed core-field set — see
§4.4 — and on `PropertyRentalDetailsCustomField`, per-field, per
Johan's own "a tick on each" mechanism). An agency also controls the
**order** fields appear in the block — `sort_order` already exists on
the custom-field table for exactly this; the core fields need an
agency-configurable order too (§4.4). CoreX assembles the ticked fields,
in that order, into a text block, formatted per §4.2, and appends it to
`properties.description` before the value that actually reaches a
portal or the website API.

**A field with no value and a field that isn't ticked both produce
nothing** — never a blank line, never "R0", matching Johan's own
"neat and tidy" instruction and the original parked capture's explicit
requirement (§0).

### 4.2 Generated at render time — not written into the stored field

This resolves Open Question 1 from the original parked capture (§0).
**Recommendation: compute the block fresh every time the description is
sent out — to a portal, to the website API, or shown in the preview
(§4.3) — and never persist it into the stored `properties.description`
value.** Reasoning, weighing the same trade-off the parked capture named
explicitly:

- **Written-in** means the moment a source field changes (rent goes up,
  admin fee changes), the already-saved block text silently goes stale
  — this is *exactly* the bug Johan is trying to eliminate (the whole
  point of this feature is that the block can never drift from the real
  values). Writing it in reintroduces the identical failure mode one
  level down.
- **Render-time** means the block is always correct by construction —
  there is nothing to go stale because nothing is stored. The cost is
  that an agent cannot hand-edit the block's exact wording for one
  listing (fix a typo, add a one-off caveat) — but Johan's own stated
  goal is that the agent *never has to touch this by hand at all*; a
  template an agent can quietly edit reopens the door to the exact
  "captured twice, drifts silently" problem this feature exists to
  close.

**Confirmed, not assumed, this actually works end-to-end:**
`Property24ListingMapper.php:51` and `PrivatePropertyListingMapper.php:75`
both read `$property->description` directly at submission time — there
is no intermediate step where a stored value is required. A
render-time-only approach reaches the actual portal advert exactly the
same way a stored value would, resolving Open Question 3 from the
parked capture: the earlier worry that "render-time" might mean the
block never reaches the real portal ad does not apply, because the
assembly point is the same moment the mapper reads the field, not a
separate CoreX-only display.

**Concretely:** a new accessor/service (e.g.
`Property::descriptionForSyndication()` or a dedicated
`RentalAdvertBlockService`) that both syndication mappers and the
website API resource call instead of reading `$property->description`
directly — one assembly point, reused everywhere the description is
actually sent out, so the block can never appear in one channel and not
another by omission.

### 4.3 Preview — reusing the existing live-preview page, not building a second one

Johan's requirement: *"the block must be PREVIEWABLE before it goes
out."* CoreX already has exactly this kind of surface:
`PropertyController::livePreview()` / `resources/views/corex/
properties/live-preview.blade.php` — a public, no-auth, shareable
property page (used today for WhatsApp/link-sharing) that already
renders the description and already correctly gates listing-type-only
content (§6 below). **Recommendation: extend this existing page to
render the description WITH the computed advert block appended**,
rather than building a second preview mechanism — an agent checking
"how will this look" already has a real page to look at, and it becomes
accurate for this feature with no new route, no new controller, no new
view. This does not preview the raw P24/PP payload itself (no dry-run
mode exists for either portal's submission — confirmed absent, not
found anywhere in `Property24ApiClient`/`PrivatePropertySoapClient`) —
if Johan specifically wants to see the exact bytes a portal will
receive rather than a rendered page, that is new work this spec doesn't
assume he's asking for; flagged as a decision point, not built either
way here.

### 4.4 Length limits and formatting — checked, not assumed

**Confirmed: neither Property24's nor Private Property's mapper enforces
or documents any character limit on `description` anywhere in this
codebase** (searched both mapper files and their surrounding API-client
classes for a length constant, `strlen`/`substr`/`Str::limit` — none
exists for this field; the only length checks found govern an unrelated
field, `StreetName`, and an unrelated video-id check). This spec does
not invent a limit that isn't documented anywhere — but appending a new
block to an already-written description makes the *combined* length a
new consideration that didn't exist when descriptions were free-typed
alone. **Recommendation:** since no limit is documented in this
codebase, do not hardcode one; instead, log/flag (not silently truncate
— "never silently truncate" is already this codebase's stated principle
for photo galleries, per the existing mapper comments, and should apply
here too) if either portal's actual submission is later observed to
reject or truncate an unusually long combined description, so a real
limit can be captured from the portal's own response rather than
guessed.

**Existing gate the new text passes through:** `PortalContentValidator`
blocks South African phone-number patterns in `description` before
submission (Layer 1 at capture, Layer 2 at sync). A Rand-amount advert
block ("R4,710") will not trip this regex — confirmed by reading the
pattern, which only matches numbers starting `0`/`+27` — but the spec
notes this gate exists because the new generated text lands in the
exact same field this validator already inspects; no change to that
validator is needed or proposed.

**A separate, more concrete root cause worth fixing as part of this
work, not just working around:** `admin_fee` and `marketing_fee` are
real, already-editable core fields today, and **reach zero syndication
targets** — not P24, not PP, not even the website API's
`ListingResource` (confirmed by reading all three). This is very likely
*why* HFC's agent hand-typed the admin fee into the description in the
first place: there has never been a structured path for that number to
reach an advert at all. **Recommendation: this spec's advert-block work
should be the thing that finally gives these two fields a real output
path** — via the block itself for both portals (since neither portal
has a native slot for either figure — confirmed absent from both
mappers), and via adding both fields to `ListingResource` for the
website API, so an agency's own site can render them independently of
the generated-block text if it chooses to. This isn't scope creep; it's
the same gap Johan's worked example is a symptom of.

**One existing precedent worth reusing rather than duplicating:**
`Property24ListingMapper.php:116-117` already auto-generates one line
from `deposit_amount` into P24's own native structured slot
(`rentalInfo.depositRequirementsComments`), separate from the free-text
description; PP sends `deposit_amount` as its own native numeric field
too. **Where a portal already has a native structured slot for a
core-field concept (deposit, on both portals), the advert-block service
should keep populating that native slot exactly as today — not also
duplicate the same figure into the appended text block.** Only fields
with no native portal slot (admin fee, marketing fee, and every
agency-defined field from §2) belong in the generated text appended to
`description`. This avoids the double-price bug that Open Question 2
(§4.5) already warns about, one level earlier in the pipeline.

### 4.5 Existing hand-typed adverts — a real migration risk, not solved here

Every current rental listing may already contain hand-typed versions of
exactly this information, in whatever wording each agent chose (Johan's
own worked example is one of them). The moment this feature ships,
those same listings will ALSO get a generated block appended —
**showing the same costs twice, in two different wordings, on a live
portal advert**, which is worse than the feature not existing.
**This spec does not decide how existing descriptions get identified,
cleaned, or migrated before rollout** — flagged explicitly as Johan's
call (§9), not quietly assumed away. A plausible staged approach (not a
decision) is in §8, Part 4.

---

## 5. Lease type — investigated, reported, not touched

Johan: *"lease type - makes no sense what this is. is the list definable
under rental settings? lease type not even sure where this fits in."*

### 5.1 What it does today — confirmed dead, by two independent checks

Two separate `lease_type` columns exist: `properties.lease_type`
(`varchar(100)`, migration `2026_03_24_094815_...php:18`, comment
`// e.g. "N Triple Net", "Gross"`) and `leases.lease_type`
(`varchar(40)`, migration `2026_09_17_090000_create_leases_table.php:44-46`,
its own comment noting it "reuses `properties.lease_type`'s shape... not
a foreign key, just the same free-form convention"). **Full-repo grep
confirms nothing reads either column functionally** — the only places
either value is ever referenced are the lease-detail page's own display
(`leases/show.blade.php:38`, a plain `{{ $lease->lease_type }}` with no
branching) and each field's own edit form reading its own value back
into its own `<option selected>`. No syndication mapper, no PDF/document
template, no report, no filter, no compliance rule touches it —
`Property24ListingMapper.php` never references it at all despite P24's
own API schema having a matching commercial `leaseType` field
(`storage/p24_swagger.json:3550`), a gap independently already recorded
in this codebase's own audit trail
(`.ai/audits/syndication-mapping-audit-2026-07-05.md:45`, "P24-G4 —
commercial `leaseType`... dropped"), and this exact "zero programmatic
consumers" conclusion is independently already on record in `.ai/specs/
rentals-shared-screens.md:664`, written by a different lane, reached the
same way. **Two independent investigations, one prior and one for this
spec, agree: this field is dead.**

**"Is the list definable under Rental Settings?" — confirmed no.** The
four allowed values (`N Triple Net`/`Gross`/`Modified Gross`/`Percentage`
on the property form) are a hardcoded PHP array in one Blade file; the
lease-detail form's own hardcoded list is a *different* four strings
(`Net`/`Gross`/`Modified Gross`/`Percentage` — "Net" vs. "N Triple Net"
for what's meant to be the same concept, confirmed drifted apart between
the two forms). Neither list comes from any settings table. Server-side
validation on both is a bare `nullable|string|max:N` with no whitelist
— a raw POST could set any string.

### 5.2 Recommendation — Johan's decision, not made here

Confirmed functionally dead is a finding, not a design. Two real options,
presented without a preference imposed:

1. **Remove it.** If nothing reads it and Johan doesn't recognise what
   it's for, the honest fix may be deleting the field and both dropdown
   inputs rather than dressing up a dead field as a real setting.
2. **Make it real**, following the exact `PropertySettingItem` pattern
   already proven on Furnished Status (§3.2) if there is a genuine
   commercial-lease concept worth keeping (P24's own `leaseType` field
   suggests the underlying concept — commercial lease structure — is a
   real, portal-recognised thing, just never wired through). If kept,
   the two drifted option lists get reconciled into one agency-managed
   list, and the P24 mapper gap gets closed as part of the same work.

This spec does not pick one — it is exactly the kind of "does this
matter to the business" call `CLAUDE.md` reserves for Johan, not an
engineering choice dressed as one.

---

## 6. Bond repayment calculator on a rental listing — reported, not fixed

Johan found a bond-repayment calculator rendering on a property listed
FOR RENT. Established which system it's in, per his explicit
instruction, before anything is touched.

**CoreX's own equivalent component already correctly excludes this.**
`live-preview.blade.php:308-347` wraps the bond calculator in
`@unless($isRental)`, where `$isRental` is `Property::isRental()`
(`app/Models/Property.php:2014-2021`) — a deliberately broad,
case-insensitive match against `['rental','to_let','to-let','lease']`,
hardened specifically because an earlier case-sensitivity bug once let a
rental render as a sale on this exact page (the fix is recorded in the
surrounding code comment). No other bond-calculator component exists
anywhere in CoreX's property-facing views — the only other one found is
the internal, authenticated Calculators Hub (`resources/views/
calculators/index.blade.php`), a standalone agent tool unconnected to
any specific property, not public.

**hfcoastal.co.za is confirmed to be a separate, bespoke system, not
this codebase.** `.ai/specs/agency-public-api.md:9` states plainly that
agency public websites are "separate, bespoke sites — not on the CoreX
codebase"; CoreX only serves them data via the versioned public API
(`GET /api/v1/website/listings`). The URL Johan found
(`hfcoastal.co.za/property/{slug}`, a WordPress-style path) does not
match CoreX's own public property-page route
(`/corex/properties/{property}/preview/{slug?}`).

**Conclusion: the fault is very likely in the agency's own separate
website template, not in CoreX.** CoreX's equivalent surface already
gets this right, and the live URL's structure doesn't match anything
this codebase serves directly. This cannot be stated as 100% certain
without visibility into that separate site's own code (which isn't in
this repository) — reported as the strong, evidence-based conclusion it
is, not asserted as absolute fact. Not fixed here, per instruction.

---

## 7. Data model summary

New/changed, for build-time reference — nothing here is built yet:

- **New table `property_rental_details_custom_fields`** — mirrors
  `rental_application_custom_fields` column-for-column, plus a new
  `advertise` boolean. Agency-scoped, soft-deletable, reorderable.
- **New column `properties.rental_details_custom_field_values`** — json,
  nullable, keyed by the custom field's `key` — mirrors
  `rental_applications.custom_field_values`.
- **New `field_type` constant `TYPE_CURRENCY`** on the new model (§2.1)
  — additive, no change to `RentalApplicationCustomField`'s existing
  four types.
- **New `PropertySettingItem` group, `GROUP_RENTAL_PRICE_TYPE`** (§3.2)
  — agency-editable list replacing the hardcoded 5-option dropdown,
  seeded with today's 5 values as the default for every agency via the
  existing `provisionDefaultsFor()` pattern.
- **New column(s) on `properties`** for the tick-multiple price-type
  redesign (§3.3) — a per-ticked-cadence boolean set, or a single json
  column listing which cadences are ticked (build-time choice, not
  decided here); `price_per_sqm` added only if "Per Sqm" survives as an
  option (§3.1/§3.3).
- **New `advertise` boolean on the CORE fields** (§4.1) — build-time
  decision on whether this is per-field columns on `properties`
  (`advertise_rental_amount`, `advertise_deposit_amount`, etc.) or one
  json column listing which core fields are ticked; either satisfies
  the requirement, this spec does not mandate one over the other.
- **New service/accessor** assembling the advert block at render time
  (§4.2) — e.g. `RentalAdvertBlockService` or
  `Property::descriptionForSyndication()` — consumed by both portal
  mappers, the website `ListingResource`, and the extended live-preview
  page (§4.3), so there is exactly one assembly point, never a second
  hand-copied one.
- **`admin_fee`/`marketing_fee` added to `ListingResource`** (§4.4) —
  closing the "reaches zero syndication targets" gap independent of the
  advert-block text itself.
- **No change** to `rental_amount`, `deposit_amount`, `occupation_date`,
  `lease_period`/`lease_start_date`/`lease_end_date` (the core fields,
  §1), to either portal mapper's existing submitted fields, or to
  `PortalContentValidator`.

---

## 8. Staged build plan

Same pattern as the already-landed Rental tab work (`.ai/specs/
rentals-shared-screens.md` §11: numbered Parts, each independently
verified live on QA1 before the next starts, each with its own "what
was built" + "verified live" record). Sequencing follows dependency
order — later parts read what earlier parts create.

**Part 1 — `PropertyRentalDetailsCustomField` model, migration,
controller, settings-page CRUD.** Mirrors
`RentalApplicationCustomField`'s shape exactly (§2), including the new
`TYPE_CURRENCY` type and the `advertise` column. No UI on the property
screen yet — this part only builds the agency's ability to define
fields, on its own settings page, under Settings → Rentals (per Johan's
own words, "under settings rentals"). Verify: an agency can create,
reorder, archive, and restore a field of each of the four types;
archived fields don't appear in `activeFor()`; two agencies' field lists
never leak into each other.

**Part 2 — Agency-defined fields render and save on the property Rental
tab.** The custom fields defined in Part 1 render as real inputs on the
existing Rental tab (`show.blade.php`), values save into the new
`rental_details_custom_field_values` json column via the existing
`updateRentalDetails()` action. No advert-block behaviour yet. Verify: a
Lets Assist yes/no field set up for one agency does not appear for
another; values round-trip correctly for all four types; a sale
property's raw HTML still has zero occurrences of any rental-only
markup (matching the standard every prior Rental-tab part already
proved).

**Part 3 — Rental price type redesign.** `GROUP_RENTAL_PRICE_TYPE`
`PropertySettingItem` group + seeding (§3.2), tick-multiple UI replacing
the single dropdown, per-tick value inputs (§3.3), fixing the
confirmed always-visible-regardless-of-selection bug at the same time.
No syndication change — portals keep receiving exactly what they
receive today (§3.4). Verify: an agency's custom price-type list is
independently editable; ticking/unticking shows/hides/clears the right
input; portal submission for a real rental property is byte-for-byte
unchanged before/after.

**Part 4 — The advert block itself.** The assembly service (§4.2),
wired into both portal mappers and the website `ListingResource`
in place of their direct `$property->description` reads, plus the
`admin_fee`/`marketing_fee` → `ListingResource` fix (§4.4). **Before this
part ships to any real agency, Johan's call on §4.5 (existing hand-typed
adverts) must be answered** — this part is built and verifiable on a
fresh/test listing regardless, but must not go live agency-wide until
that migration question is resolved, to avoid the double-cost-line bug
named explicitly in §4.5. Verify: a real listing with several ticked
core and agency-defined fields produces the correct block, in the
correct order, with unticked/empty fields producing no line; the block
reaches the actual P24/PP submission body (not just CoreX's own
preview); a field with a native portal slot (deposit) is not duplicated
into the text block.

**Part 5 — Live-preview page shows the computed block.** Extends
`live-preview.blade.php` to render `description` + the assembled block
exactly as a portal will receive it (§4.3). Verify: the preview page and
the actual P24 submission produce byte-identical block text for the
same property at the same moment.

**Part 6 (Johan's decision, §5.2, may not happen at all) — `lease_type`
resolution.** Either removed (both dropdowns, both columns' write paths
retired — soft, not a hard delete of historical data) or converted to a
real `PropertySettingItem` group reconciling the two drifted option
lists into one, with the P24 `leaseType` mapping gap closed as part of
the same work if kept. Does not block Parts 1-5, which do not depend on
`lease_type` in any way.

Each part gets its own "verified live on QA1" record before the next
starts, matching the discipline the existing Rental tab build already
established — nothing here proposes skipping that.

---

## 9. Items flagged for Johan's decision — not decided in this spec

1. **Lease type — remove or make real** (§5.2). Two real options
   presented; this spec does not choose.
2. **Existing hand-typed adverts vs. the new generated block** (§4.5) —
   how (or whether) existing descriptions get identified/cleaned before
   the advert block starts appending to them agency-wide. Real risk of
   visibly duplicated cost lines on a live portal ad if unresolved.
3. **Whether `date`/`choice_list`/`file` custom-field types are offered**
   on the rental-details definition form (§2.1) — Johan asked for four
   types; the underlying mechanism supports two more that weren't
   requested. Recommend excluding them from this feature's UI unless
   Johan wants them (e.g. `file` for an uploaded Lets Assist agreement).
4. **Whether a "primary cadence" is needed for portal submission**
   once multiple rental price types can be ticked (§3.4) — not required
   for this build (portals keep getting exactly what they get today),
   but flagged in case Johan wants ticked-multiple values to eventually
   influence which single rate a portal receives.
5. **Whether the live-preview page (§4.3) is sufficient as "previewable
   before it goes out"**, or whether Johan specifically wants to see the
   raw bytes of the actual P24/PP submission payload (no such mechanism
   exists today; would be new work).
6. **`price_per_sqm`** — add the missing column, or drop "Per Sqm" as an
   option, if the price-type list (§3.2/§3.3) keeps it (§3.1).

---

## 10. Out of scope (explicitly, so it is never assumed later)

- No change to the core fields themselves (`rental_amount`,
  `deposit_amount`, `admin_fee`, `occupation_date`, lease dates/period)
  — they stay exactly as built in the existing, completed Rental tab
  work.
- No change to `RentalApplicationCustomField` or its table — a
  parallel, not shared, mechanism (§2).
- No change to how either portal receives its single rental-rate value
  (§3.4) — the multi-tick capability feeds the advert block and the
  website API only.
- No fix to the pre-existing, separately-flagged gap where
  `rental_price_type`/`lease_period`/`lease_type` aren't cleared by the
  sale/rental type-switch clone logic (`.ai/specs/rentals-shared-screens.md`
  §10) — untouched by this work, already on record elsewhere.
- No change to `PortalContentValidator`'s phone-number gate.
- No fix to hfcoastal.co.za's own website (§6) — established to very
  likely be a separate codebase this spec has no visibility into; not
  touched, not assumed to be CoreX's responsibility to fix.
- No dry-run/raw-payload preview mechanism for the actual P24/PP
  submission, unless Johan asks for it specifically (§9 item 5) — the
  extended live-preview page (§4.3) is what's proposed.
