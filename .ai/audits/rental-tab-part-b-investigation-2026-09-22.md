# Rental tab — Part B investigation (2026-09-22)

**Investigate-and-report only, per Johan's explicit instruction. Nothing in
this file was built.** Companion to Part A
(`cc3-rental-tab-lease-fixes-2026-09-22`, commit `06920e52a`) — hiding Lease
Type, read-only rent on the lease edit screen, and property-picker
rent/deposit population. This file covers the five investigate-only items
from the same instruction (B1–B5). Yesterday's spec is
`.ai/specs/rental-property-tab.md` (2026-09-21 rulings + Parts 1–4 build
record); each item below says explicitly whether that spec already covers
it or is silent.

---

## B1 — Rental amount vs. rental price type: every per-period price field, its write/read sites, and the blast radius of collapsing them

**Johan's ask, verbatim:** *"the rental amount should just be rental
amount. the rental price type is the selection if its per month, week etc.
that means the price per day, price per week, price per year all becomes
redundant. you select the rental price type, and then enter an amount.
done."*

**Spec coverage: largely already there.** `.ai/specs/rental-property-tab.md`
§3.3 (Part 3, landed 2026-09-21) already did almost exactly this: the
Rental Details tab now has **one** dropdown (`rental_price_type`, agency-
editable list) and **one** value input (`rental_amount`, labelled "Rental
Price (R)") — the three `price_per_day`/`price_per_week`/`price_per_year`
inputs and their controller validation were **already removed from both
Rental Details tab blocks** in Part 3. What Johan is describing as still
outstanding is the state of the **raw DB columns and their other
consumers**, not the tab UI — the spec is silent on that blast radius,
which is what follows.

### Every per-period price field found, and who touches it

| Field | Column exists? | Rental Details tab (today) | Other consumers |
|---|---|---|---|
| `rental_amount` | Yes (`properties.rental_amount`) | **Written** — the one surviving price input | Portal mappers (both, as "the price"), `ListingResource` (website API), `MobilePropertyController` (read+write), DocuPerfect merge field `monthly_rental`/`rental_amount` (`WebTemplateDataService.php:116,293-305,637,907,1176-1177,1217-1218,1263,1489,1570,1654`), `RentalApplicationController::linkTenantProperty()` (creates the `Lease` row) |
| `rental_price_type` | Yes (`properties.rental_price_type`, plain varchar) | **Written** — the one surviving dropdown | `Property24ListingMapper::mapRentalRate()` (`Property24ListingMapper.php:109-113,193`), `PrivatePropertyListingMapper::mapRentalPriceType()` (`PrivatePropertyListingMapper.php:941`) — **cadence only**, never the per-period value fields |
| `price_per_day` | Yes (`properties.price_per_day`, decimal) | **Removed from the form in Part 3** — no input renders it, no controller writes it any more | `ListingResource.php:65` (website API still echoes whatever raw value the column holds) |
| `price_per_week` | Yes (`properties.price_per_week`) | Removed, same as above | `ListingResource.php:66` |
| `price_per_year` | Yes (`properties.price_per_year`) | Removed, same as above | `ListingResource.php:67` |
| `price_per_sqm` | **Does not exist.** Confirmed via `Schema::hasColumn('properties','price_per_sqm')` → false. Every `price_per_sqm` hit in the codebase (`MarketAnalyticsService.php`, `PricePerSqmDeviationMetric.php`, `PresentationGeneratorService.php`) is an unrelated **CMA/valuation computed metric**, not a property column — the spec's own §7 data-model summary calling for a new `price_per_sqm` column is stale text superseded by §3.3's later "as actually built" paragraph in the same file, which explicitly says the column turned out not to be needed. No blast radius here — it was never built. | n/a | n/a |

**Confirmed NOT touching the three per-period columns at all** (grepped, zero
hits): `app/Services/Matching/` (no core-match logic reads them),
`WebTemplateDataService.php`/`WebTemplateFieldPartyMap.php` (no DocuPerfect
merge field for any of the three), any report/export controller.

**The actual blast radius of "collapse them," concretely:**
1. **UI/controller: already zero risk.** Part 3 already stopped writing to
   all three; a migration to drop the columns touches no live write path.
2. **`ListingResource.php:65-67` is the one real remaining consumer** — the
   agency's own public website API still receives `price_per_day`/
   `price_per_week`/`price_per_year` as raw, **frozen-at-whatever-they-were-
   before-Part-3** values (`null` for anything created/edited since Part 3
   shipped, since nothing writes them any more). If any agency's own website
   template renders these three fields, that template will start silently
   getting `null` for every rental touched after 2026-09-21, with no error
   anywhere — worth telling Andre before dropping the columns, since
   `.ai/specs/agency-public-api.md` already establishes that agency websites
   are separate, bespoke systems this codebase can't see into (same
   caveat the spec's §6 bond-calculator finding already relies on).
3. **Dropping the columns is safe from CoreX's own side** once (2) is
   confirmed dealt with — no portal mapper, no matching logic, no document
   template, no report reads them.

---

## B2 — Rental tab field order, and what "Has Deposit" actually controls

**Johan's ask, verbatim:** *"we have deposit sitting between monthly rental
and rental price type, yet the tick for has deposit (again not sure why
this is there) sitting 3 rows down."*

**Spec coverage: silent.** Neither field order nor `has_deposit`'s
behaviour is discussed anywhere in `.ai/specs/rental-property-tab.md`.

### Current rendered order (settled property, `properties/show.blade.php:4381-4471`, 3-column grid `grid-cols-1 sm:grid-cols-2 lg:grid-cols-3`)

1. Rental Price (R) — `:4381`
2. Deposit (R) — `:4385`
3. Rental Price Type — `:4394`
4. Lease Start Date — `:4402`
5. Lease End Date — `:4406`
6. Lease Period — `:4412`
7. **Lease Type** — `:4416` (now hidden by default per Part A/Part 7 —
   see below)
8. **Has Deposit** (checkbox) — `:4428`
9. Commission (%) — `:4437`
10. Admin Fee (R) — `:4441`
11. Marketing Fee (R) — `:4445`
12. Furnished Status — `:4452`
13. Availability Date — `:4461`
14. (Water/Electricity/Levies-included checkboxes, then agency custom
    fields)

Confirmed identically ordered on the new/draft-property block
(`:4172-4237`), same field sequence, `:4213-4223` for what was Lease Type.

**Note on today's actual on-screen distance:** Johan's "3 rows down" was
measured before Part A shipped hiding Lease Type by default. With Lease
Type now hidden for any agency that hasn't explicitly turned it back on
(Settings → Leases), the raw field count between Deposit and Has Deposit is
now 5 instead of 6 (Rental Price Type, Lease Start, Lease End, Lease
Period, then Has Deposit) — still a real gap, just one field shorter than
what Johan saw. This did not fix the layout complaint; it's an
observation, not a claim that B2 is resolved.

### What "Has Deposit" controls in code

**Confirmed: nothing.** `has_deposit` is a plain boolean column
(`properties.has_deposit`, migration
`2026_03_24_094815_add_pricing_details_to_properties_table.php:13`). Full-
repo grep for every reference:
- `app/Models/Property.php:551,738` — fillable + cast, nothing else.
- `app/Http/Controllers/CoreX/PropertyController.php:954,1353,2602` —
  validated as a plain boolean and written straight through on save. No
  conditional logic anywhere keys off its value.
- `app/Http/Resources/WebsiteApi/ListingResource.php:60` — echoed to the
  website API as a bare boolean.
- The two Blade checkboxes themselves (`:4229-4230`, `:4438-4439`).

It does not gate whether `deposit_amount` is required, does not hide/show
any other field, does not affect portal syndication (`Deposit` reaches P24
via `deposit_amount` directly — `Property24ListingMapper.php:116-117` —
regardless of `has_deposit`'s value), and no test or service branches on
it. **It is exactly what Johan suspected: a tick that currently means
nothing downstream.** Whether it should be removed, made to actually gate
`deposit_amount`'s display, or left as a pure "yes there is one, ask the
landlord for the amount separately" marker for agents who haven't captured
the number yet is a product call, not investigated further here.

---

## B3 — Property-level lease start/end date fields once a real Lease exists

**Johan's ask, verbatim (re: `/corex/properties/5792`):** *"this property
has a lease on, yet the rental screen shows the active lease at the top,
but for what reason will we have the lease start date lease end date
fields still there?"*

**Spec coverage: adjacent, not direct.** `.ai/specs/rentals-shared-screens.md`
§10 (referenced in this spec's own §10 "Out of scope" list) already flags
that `rental_price_type`/`lease_period`/`lease_type` aren't cleared by the
sale/rental type-switch clone logic — a related but different complaint
about the same general area (stale property-level rental fields). Nothing
in either spec discusses `properties.lease_start_date`/`lease_end_date`
specifically duplicating a real `Lease` record's own dates.

### What these two fields are

`properties.lease_start_date`/`lease_end_date` — real columns
(`app/Models/Property.php:622-623,762-763`), editable on both Rental
Details tab blocks (`:4199-4204` new, `:4403-4408` settled), validated
`nullable|date` in three controller call sites
(`PropertyController.php:1015-1016,1414-1415`, plus the rental-details
update).

### The active-lease banner at the top of the same tab

`properties/show.blade.php:4292-4303` queries the REAL `Lease` model
directly: `Lease::where('property_id', $property->id)->where('status',
'active')->first()`, and displays ITS `start_date`/`end_date` — a
completely separate pair of columns on a completely separate table
(`leases.start_date`/`end_date`).

### Confirmed: nothing syncs the two

Grepped `app/Services/Rentals/` and `app/Models/Lease.php` for any write to
`properties.lease_start_date`/`lease_end_date` when a `Lease` is created or
activated — **none exists.** `RentalApplicationController::
linkTenantProperty()` (`:1326-1338`) creates a real `Lease` row with its own
`start_date`/`end_date` and never touches the property's own
`lease_start_date`/`lease_end_date` columns. Same for `LeaseController::
store()`. **This confirms Johan's exact suspicion: once a real Lease
exists, the property-level date fields are an independent, never-
synchronised second copy of the same fact** — exactly the same
architectural pattern the spec already documented and fixed for Lease Type
in Part 4 (two divergent hardcoded lists, one now agency-editable list),
except here it's two divergent DATES with no reconciliation mechanism at
all, not even a shared source list.

### They are not dead, though — real consumers exist

Unlike the pre-Part-4 `lease_type` (confirmed fully dead by two independent
investigations), `properties.lease_start_date`/`lease_end_date` DO have
live consumers, so removing them outright is not a no-op the way hiding
Lease Type was:
- **Property24 syndication** — `Property24ListingMapper.php:133,157-158`
  sends `lease_start_date` as both `commercial['availabilityDate']` and
  `listing['occupationDate']`.
- **Website API** — `ListingResource.php:62-63` exposes both raw.
- **Mobile app** — `MobilePropertyController.php:1934-1935` (read),
  `:309-310` (write) — the mobile app's own property-edit screen still
  captures these independently of any Lease record.
- **Calendar** — `PropertyCalendarSource.php:72-93` — a "lease expiry
  fallback" calendar event for properties with `lease_end_date` set but
  (per its own comment) no real Lease record tracked yet — this is
  actually a legitimate, deliberate use of the property-level field as a
  placeholder BEFORE a real Lease exists, not a duplicate of one.

**Net finding:** the property-level fields serve two genuinely different
moments — (a) an advertised/expected lease term before any real Lease
record exists (where the calendar fallback and mobile capture make sense),
and (b) once a real Lease exists, a second, unsynced copy of the same two
dates that can drift from the real record and has no reason to still be
editable on the tab. A `Lease::where('property_id', ...)->where('status',
'active')->exists()` check (the same query the banner above already
performs) is the natural gate if Johan wants these fields hidden/read-only
once a real lease is active — not investigated as a design past that
observation, since Part B is report-only.

---

## B4 — Agency custom fields on the Rental tab: what exists vs. what Johan described

**Johan's ask, verbatim:** *"there was also a discussion around having
settings where agencies can add their own fields to this screen via
settings - we going to need it as every agency will want their own fields
to use based on how they operate."*

**Spec coverage: fully covers this — already built.** `.ai/specs/
rental-property-tab.md` §1/§2/§8 Part 1 (landed 2026-09-21) and Part 2
(pushed, awaiting landing) describe and build exactly this. Confirmed live
in code, not just per the spec's own claim:

- **Model:** `App\Models\PropertyRentalDetailsCustomField` — agency-scoped
  (`agency_id`), soft-deletable, `sort_order` for reordering.
- **Field types — all four Johan asked for, confirmed via the model's own
  constants** (`app/Models/PropertyRentalDetailsCustomField.php:39-42`):
  `TYPE_TEXT` (free text), `TYPE_NUMBER` (quantity), `TYPE_YES_NO`,
  `TYPE_CURRENCY` (value, in Rand).
- **Settings screen:** `resources/views/corex/settings/rental-details.blade.php`
  at `/corex/settings/rental-details` (route
  `corex.settings.rental-details.edit`, `routes/web.php:2882-2891`),
  permission-gated (`rental_details.manage_settings`).
- **Full CRUD, confirmed by controller method, not assumed:**
  `PropertyRentalDetailsCustomFieldController::store()/update()/archive()/
  restore()/reorder()` (`:19,45,65,75,86`) — create, edit, soft-delete,
  un-delete, and drag-reorder all present and routed.
- **Renders and saves on the real property Rental tab** — Part 2
  (`show.blade.php`'s settled-property block, per the spec's own Part 2
  build record), values stored in
  `properties.rental_details_custom_field_values` (JSON, merged not
  replaced on save, per Part 2's own documented behaviour).

**What's missing against what Johan described:**
1. **Never surfaced in the Agency Onboarding Setup Wizard.** Grepped
   `config/agency-onboarding-copy.php` and
   `AgencySetupWizardController.php` for any `rental-details`/
   `rentalDetails`/`PropertyRentalDetailsCustomField` reference — none
   found. Per CLAUDE.md non-negotiable #10a and `BUILD_STANDARD.md` §8,
   every new setting should reach the wizard in the same prompt it ships
   in; this one (Part 1, landed 2026-09-21) did not, and nothing in the
   spec records it as a deliberate exclusion either — it reads as a
   genuine miss, not a decision on the record.
2. **The new/draft-property creation path doesn't render custom fields
   yet** — the spec's own Part 2 section names this explicitly as a
   deliberate deferral, not an oversight: only the SETTLED property's
   Rental tab renders them today.
3. **`date`/`choice_list`/`file` types exist on the underlying model
   (inherited from mirroring `RentalApplicationCustomField`) but are
   deliberately not offered on this feature's definition form** — already
   named as an open question in the spec's own §9 item 1, not a gap this
   investigation is newly surfacing.

Nothing here needs rebuilding — the mechanism Johan is asking for exists
and matches his four-type description closely; the gap is the wizard entry
(item 1 above), which is a small, contained addition, not a redesign.

---

## B5 — Which rental tab fields feed advertising/syndication today, and is that visible to an agent

**Johan's ask, verbatim:** *"As well as given was which of the rental tab
fields are used for advertising, and which not."*

**Spec coverage: directly covers the mechanism (§4), but the mechanism
itself is not built yet.** §4/§4.1/§4.4/Part 5 describe the advert-block
opt-in tick and per-field `advertise` flag in detail — Part 5 is listed as
"not built" in the spec's own §8 staged plan. Confirmed against the actual
code, not assumed from the spec text:

### What's actually wired to a real portal/advert output today

| Field | Feeds P24? | Feeds Private Property? | Feeds website API (`ListingResource`)? | Feeds the (not-yet-built) advert-block text? |
|---|---|---|---|---|
| `rental_amount` | Yes — the price, always (`mapRentalRate` uses `effectivePrice()`) | Yes — same | Yes | n/a (native slot on both portals) |
| `rental_price_type` | Yes — cadence enum | Yes — cadence enum | Yes | n/a |
| `deposit_amount` | Yes — native structured field, `Property24ListingMapper.php:116-117` (`rentalInfo.depositRequirementsComments`) | Yes — native numeric field | Yes | n/a (native slot on both — spec §4.4 explicitly says this must NOT also be duplicated into a text block once Part 5 ships) |
| `admin_fee` | **No** | **No** | **No** — confirmed absent from `ListingResource.php` entirely | Not yet (Part 5 not built) |
| `marketing_fee` | **No** | **No** | **No** — same | Not yet |
| `lease_type` | Yes, as of Part 4 (`mapLeaseType()`, new) | Not checked — PP mapper has no lease-type concept in this codebase | Not checked | n/a |
| `price_per_day`/`week`/`year` | **No, never was** (confirmed §B1) | **No, never was** | Yes — raw, now-frozen values | n/a |
| Agency-defined custom fields (§2/§4.1) | No | No | Not checked | **Column exists (`advertise` boolean, `PropertyRentalDetailsCustomField`), but no consumer reads it yet** — see below |

**`admin_fee`/`marketing_fee` do have ONE real downstream consumer already**
— worth correcting one nuance in the spec's own §4.4 framing ("reach zero
syndication targets," true only for portals/website): both are already
live **DocuPerfect merge fields**, confirmed in
`app/Services/WebTemplateDataService.php` and
`app/Services/WebTemplateFieldPartyMap.php`, and referenced in
`ESignWizardController.php` — so a lease-agreement/document template CAN
already pull them in today, just not a portal advert or the website API.

### Is the `advertise` flag visible to an agent anywhere?

**Only at field-definition time, on the SETTINGS page — never on the
property screen itself.** Confirmed: `resources/views/corex/settings/
rental-details.blade.php:88,149` renders an "advertise" checkbox when an
agency DEFINES a custom field (i.e. decided once, agency-wide, when the
field is created — "will this field, if shown on a property, ever be
eligible for the advert block"). Grepped
`resources/views/corex/properties/show.blade.php` for any `advertise`
reference — **none** — so an agent filling in a specific property's Rental
tab has no way to see, on that screen, which fields are flagged for
advertising. And since Part 5 (the actual assembly service that would READ
this flag and do something with it) is not built, **ticking "advertise" on
a custom field definition today has zero visible effect anywhere** — it's
a real column, saved correctly, silently inert.

**Aside, not asked for, flagged for completeness:** the committed schema
snapshot (`database/schema/mysql-schema.sql:10751-10752`, committed in
`6c6297254`, one of this lane's own prior commits from 2026-09-21) already
contains `properties.rental_advert_block_enabled` and
`properties.advertise_core_fields` columns — **but no migration file in
this branch's `database/migrations/` creates either column.** This means
`RefreshDatabase`'s schema-snapshot bootstrap (`BUILD_STANDARD.md` §12a)
silently hands every test suite these two columns today even though
`php artisan migrate` on a real environment would not create them — a
latent drift between the committed snapshot and the actual migration
history for Part 5's own not-yet-built columns. Not investigated further
(out of scope for B5), but worth a look before Part 5 actually starts, so
its real migration doesn't collide with columns the snapshot already
silently assumes exist.

### Answering Johan's question directly

**None of the rental tab's core fields (rental_amount aside, which feeds
price natively) are marked for advertising today in any agent-visible
way**, because the per-field advertise mechanism for CORE fields doesn't
exist yet (§4.1/§4.4/§7 all describe it as new work, not yet built) — only
agency-DEFINED custom fields have the column, and even that is set once at
definition time, not per-property, and currently does nothing. If Johan
wants to see, per property, which fields will show up in a future advert
block, that is Part 5 plus its own inline preview (Part 6) — both already
fully specced, neither built yet.
