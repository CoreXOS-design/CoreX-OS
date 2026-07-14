# Atlas — Properties (the Property pillar / spine)

> **Status: DONE** · Last verified: 2026-07-14
> Pillar: **Property** (`properties` = Agency Stock). The spine: nearly every other feature reads
> property data and many write back to it. Companion: `.ai/specs/listings.md`, `.ai/specs/multi-tenancy.md`.
> Cited audits: AT-78 (autofill / backfill), AT-60 (contact prefill), AT-67-era status rename,
> AT-262 (duplicate / change listing type), AT-266 (address one-truth + reconciler).

---

## 1. WHAT IT DOES

A `Property` is the physical asset record — address, type, sizes, condition, valuation, status, photos,
syndication fields, and links to contacts/deals/presentations. It is **Agency Stock**: formal listings HFC
works (distinct from the `tracked_properties` intelligence tier — see `prospecting-tracked-properties.md`).
A property is created by an agent (form or upload wizard) or promoted from a tracked property when a
mandate is signed. Its columns are the **single source of truth** that Presentations, Core Matches, the
MIC, Syndication (Andre's, doc-only here), the public website, and the mobile app all read.

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`) — group `corex.properties.*` at `:2133` (mw `permission:access_properties`, `agency.required`)
| Route | Method | Handler | Notes |
|-------|--------|---------|-------|
| `/` `:2239` | GET | `index` | list + filters (scope my/branch, status/type/category/mandate/branch/price/beds/baths) |
| `/create` `:2240` | GET | `create` | renders **show.blade** with `activeTab='info'` (create == show form) |
| `/` `:2241` | POST | `store` | persist new property |
| `/{property}` `:2260` | GET | `show` | **the live detail+edit page** |
| `/{property}/edit` `:2263` | GET | `edit` | **redirects to show** (edit form is dead) |
| `/{property}` `:2265` | PUT | `update` | persist edits |
| `/{property}` `:2266` | DELETE | `destroy` | **soft delete** |
| `/{property}/restore` `:2267` | POST | `restore` | `onlyTrashed()` recover (perm `properties.edit`) |
| `/{property}/duplicate` `:2829` | POST | `duplicate` | **AT-262** — replicate excl. syndication, status→draft; `?target_type=sale\|rental` for a cross-type copy (matching fields only); clone opens type-unlocked (`listing_type_pending=true`) |
| `/{property}/change-type` `:2831` | POST | `changeType` | **AT-262** — duplicate to the OTHER type + archive (soft-delete, de-list) the original; draft-only gate (`canChangeType()`) |
| `/{property}/publish-toggle` `:2269` | POST | `publishToggle` | HFC Premium publish gate |
| `/{property}/go-live` `:2135` | POST | `goLive` | compliance snapshot |
| image ops `:2270-2276` | POST | upload/delete/reorder/rotate | gallery |
| contact link/unlink `:2284-2288` | — | `PropertyContactController` | link is `contacts.link` `:2286` |
| upload wizard `:2252-2259` | — | `PropertyWizardController` | `createDraft` `:2253`, saveStep, finalize |
| preview `:623` | GET | `livePreview` | `corex.properties.preview` |

Controller: `app/Http/Controllers/CoreX/PropertyController.php` — `index` `:26`, `show` `:216`, `create`
`:372`, `store` `:438`, `edit` `:745` (redirect), `update` `:1005`, `destroy` `:1329`, `duplicate` `:1342`,
`changeType` `:1378`, private `makeClone` `:1421`. Private: `processSpacesJson`, `storeImages`.
*(No `createDraft`/`clone`/`link-contact` on this controller — those are on `PropertyWizardController` /
`PropertyContactController`; cloning is `duplicate()`, cross-type / archive-and-hand-off is `changeType()`.)*

### Blade (`resources/views/corex/properties/show.blade.php` — the LIVE form for create + edit + detail)
`create-edit.blade.php` exists but is **dead** (unreferenced — both `create()` `:435` and `show()` `:365`
render `corex.properties.show`).
- **Tabs** defined `show.blade.php:1381-1391`: `overview` (`:1420`), `info` (`:1731`), `gallery` (`:3394`),
  `contacts` (`:3904`), `notes` (`:4196`), `history` (`:4253`), `drive` (`:4301`), `intelligence`
  (`:4453`), `core-matches` (`:4902`). Core Matches tab hidden unless `matches_enabled` +
  `matches_show_on_properties` + `access_core_matches` (`:1392`).
- **Address modal** (Info tab): `unit_number`, `floor_number`, `unit_section_block`, `complex_name`,
  `street_number`, `street_name`, `stand_number`, `zone_type`. **AT-78 FIX 4 autofill hardening**:
  `autocomplete="section-corex-prop-addr address-line2"` + `data-1p-ignore` + `data-lpignore` on the
  unit/floor/section/complex inputs (stops Chrome/password-managers writing a person-name into an address
  field — the "Elizabeth Reichel" vector, AT-78 §A). **AT-266:** the structured parts are now the *only*
  edited surface — `address` is derived from them on save (see §3, §7), so there is no longer a hidden
  frozen `address` passthrough behind this modal.
- **Listing-type control + AT-262 banner** (Info tab): the `listing_type` select is editable only when
  `$isNew || listing_type_pending` (`show.blade.php:1579`); once the pending window closes it renders a
  disabled "For {type}" input with a "Locked after first save. To change, duplicate the listing." hint
  (`:1591-1593`). A duplicated / switched-type draft shows the amber "Listing moved to {type}" completion
  banner at `:56-62`. The **Change listing type** form (`:1454-1459`) posts to `change-type` and is gated
  behind `!$isNew && !listing_type_pending && $property->canChangeType()`.

---

## 3. THE FLOW — create / edit / save

### `store()` (`PropertyController.php:438`)
1. Validate `:443-539` — required: `title`, `price` (int), `suburb`, `beds`, `baths`, `garages`,
   `agent_id`; `condition_level_id` → `exists:property_setting_items,id` (`:482`); `listing_type` in
   `sale,rental` (`:484`).
2. **Must-have-contact invariant** `:541-555` (422 if no contact linked).
3. `processSpacesJson` `:557`, `applyP24Location` `:558`, YouTube id `:561-563`.
4. Agent/agency/branch derivation `:565-577` (`agency_id = effectiveAgencyId()` `:569`).
5. Publish handling `:579-590` — publish sets `published_at=now()`, `status='active'`; empty status
   stripped so DB default `draft` applies.
6. `DB::transaction` `:597-725`: `Property::create` `:598` → `PropertySuburbLinked` event `:600-607` →
   images `:609-620` → initial note `:628` → drive files `:636` → link existing contacts `:650-667` →
   create+dedup new contacts (SA-ID rule) `:673-722`.

### `update()` (`PropertyController.php:1005`)
Mirrors store minus create-only extras; same `condition_level_id` rule; never nulls an existing
`status`; image append/merge; force-touch `updated_at`; redirect to show `tab=info`.
**AT-262 lenient draft save** (`:1009-1032`): a **completable draft** — `isDraft() || listing_type_pending`
and not publishing — validates *partially* (contact / price / suburb / beds / baths / garages / agent are
NOT required), so "duplicate → save" and "change type → save" no longer error on a half-filled handed-over
draft. Full requirements bite only at completion / go-live (`MarketingReadinessService`). A live/active
listing keeps strict validation. **First real save closes the pending window** (`:1244-1245`): when
`listing_type_pending` was true, `update()` sets it back to `false` — the listing type locks from then on.

### Contact-link prefill (AT-60) — `PropertyController.php:390-421`
The `create()` GET, when `?contact_id=` present, copies the contact's **structured** address field-for-field
(`unit_number`/`floor_number`/`unit_section_block`/`complex_name`/`street_number`/`street_name`/
`suburb`/`city`/`province` + `p24_*_id`) at `:401-412`. Field-aligned (block→block); a duplicate guard
(`ContactAddressPropertyGuard::findLinkableProperty`) runs at `:418-419`. *(AT-78 confirmed this path is
inert in practice: no live contact carries `unit_section_block`.)*

### spaces_json → features_json derivation — `processSpacesJson()` `:1344-1380`
Decodes `spaces_json` (`:1350`), stores the rich structure, flattens `featuresAll` + per-unit + category
features into a unique flat `features_json` (`:1354-1367`), and **syncs `beds`/`baths` columns** from the
`Bedroom`/`Bathroom` space counts (`:1369-1373`). This is why `features_json` is **derived**, not directly
edited — the scorers read the flat array, the editor edits the rich `spaces_json`.

### Duplicate / Change listing type (AT-262 — Andre's design + Johan's extension)
Both routes share one private builder, `makeClone($property, $targetType)` (`PropertyController.php:1421`):
`replicate()` excluding `external_id`/`published_at`/all `p24_*`/`pp_*` syndication columns → `title` +
" (Copy)" → `status='draft'` → `listing_type=$targetType` → **`listing_type_pending=true`** (the type is
NOT locked until the draft is completed) → `price=0` (this schema's "unset"; `empty(0)` keeps the
publish-readiness gate demanding a real price) → syndication disabled. **Cross-type** copies carry only the
matching fields — when becoming a Sale it nulls the rental-only fields (`rental_amount`, `deposit_amount`,
`commission_percent`, `admin_fee`, `marketing_fee`, `lease_start_date`, `lease_end_date`,
`rental_images_json`, each `Schema::hasColumn`-guarded); becoming a Rental just resets `price=0`.
- **`duplicate()` (`:1342`):** clone + re-attach every contact link (same pivot `role`) in one transaction;
  `?target_type=` overrides the source type. Redirects to the new draft with a "complete the details" flash.
- **`changeType()` (`:1378`):** duplicate to the OTHER type, then **archive the original** — set both
  syndication flags off + status `withdrawn`, `status='archived'`, `saveQuietly()` (so the observer's
  re-syndication hooks don't fight the withdrawal), then `delete()` (soft — non-negotiable #1, history
  preserved, recoverable by admin). **Server-side gate `Property::canChangeType()`** (model `:166`) =
  `isDraft() && !wasEverAdvertised()` — change-type is only for "loaded a rental that should have been a
  sale"; any advertised/active listing must use Duplicate so its live portal history is never archived out
  from under it. `wasEverAdvertised()` (`:132`) reads `published_at`, `p24_/pp_activated_at`,
  `_last_submitted_at`, `_ref`, and the `property_website_syndication` table (incl. soft-deleted rows) — it
  answers "ever", not "now", so a withdrawn-back-to-draft listing still counts as advertised.

Column: `listing_type_pending boolean default false` (migration
`2026_08_02_100001_add_listing_type_pending_to_properties.php`; `Property.php` fillable `:266`, cast `:379`).

### Address one-truth (AT-266) — the two-source era ends
`address` is no longer an independently-edited column: `PropertyObserver` **derives** it from the structured
parts on every save (see §7). The former two-copy model (P24 import wrote both `address` and the structured
columns consistently, then the Internal Address modal edited the parts while `address` sat frozen as a
hidden passthrough) is retired. `Property::composeAddressFromParts()` (model `:855`) is the single composer:
`Unit {unit_number}` (or `unit_section_block`) → `complex_name` → `{street_number} {street_name}`, joined by
", " with adjacent-duplicate collapse. A one-off reconciler repairs rows that already drifted during the
two-source era — see §7.

---

## 4. DATA IT READS

Reads its own row via the form/controller. Settings it reads: `PropertySettingItem` groups
(`category`, `property_type`, `property_status`, `mandate_type`, `condition_level`) for the dropdowns
(`PropertyController.php:221-228`); agency toggles (§8); `config/property-spaces.php` for the spaces/feature
taxonomy. On `show()` it pulls Core Matches (`MatchingService::matchesForProperty` `:241-243`), PP/P24/HFC
readiness, audit timeline, Drive docs, and AI photo suggestions (gated).

---

## 5. DATA IT WRITES — the columns that matter downstream

All on `app/Models/Property.php` (`$fillable` `:29-164`, `$casts` `:166-242`; uses `SoftDeletes`,
`BelongsToAgency`, `BelongsToBranch` `:19`).

| Column | Line | Downstream readers (see CROSS_REFERENCE) |
|--------|------|------------------------------------------|
| `condition_level_id` (FK → `PropertySettingItem`) | fillable `:73`, rel `:293-296` | **Presentations** (live ×condition uplift) |
| `complex_name` / `unit_number` | `:68` / `:69` | Presentations display address; **also WRITTEN BY Presentations generator backfill** |
| `unit_section_block` | `:129` | display address; AT-78 autofill vector |
| `street_number` / `street_name` (+ `_normalised`) / `suburb` (+ `_normalised`) / legacy `address` | `:104`/`:102-103`/`:56-57`/`:58` | Presentations `SubjectReportResolver`, Match-or-Create dedup, display address |
| `title_type` (full_title/sectional_title/vacant_land/other) | `:71` | Presentations sectional grouping + holding cost; comp comparability |
| `erf_size_m2` / `size_m2` / `floor_number` | `:66`/`:65`/`:128` | CMA comparability, Core Matches, MIC |
| `beds`/`baths`(`decimal:1`)/`half_baths`/`garages` | `:61-64` | Core Matches, MIC (hard filters), Presentations |
| `price` (int) | `:35` | Presentations asking, Core Matches/MIC price fit |
| `status` (string, default `draft`) | `:76` | listing visibility, syndication, badges |
| `features_json` / `features_json_meta` / `spaces_json` (all `array`) | `:77`/`:78`/`:80` | **Core Matches/MIC feature scoring**, P24 mapper (advertising), public website |
| `latitude`/`longitude` (`decimal:7`) | `:107-108` | map, comp geocoding |
| `cma_gps_lat`/`cma_gps_lng` (`decimal:7`) | `:160-161` | Match-or-Create GPS strategy; **WRITTEN BY** `PropertyCmaPropagationService` |
| `municipal_valuation` (+ `_year`) | `:158-159` | **WRITTEN BY** `PropertyCmaPropagationService` |
| `erf_number` / `title_deed_number` | `:156`/`:157` | Match-or-Create erf strategy; **WRITTEN BY** propagation |
| `p24_*` / `pp_*` syndication columns | `:144-151` / `:117-135` | **Syndication (Andre — doc-only)** |

`boot()`: `creating` auto-UUID `external_id`; `saving` maintains `suburb_normalised` /
`street_name_normalised`. `buildDisplayAddress()` (`:764`): structured parts first
(`Unit {unit_number}` → `complex_name` → `{street_number} {street_name}`), legacy `address` only as
fallback, then suburb/city, then `title`; adjacent-duplicate collapse. `composeAddressFromParts()` (`:855`)
is the **AT-266 canonical composer** that `PropertyObserver` writes into the `address` column on save —
same part order, ", "-joined, adjacent-duplicate collapse. `address` is therefore a **derived** column now,
not an independently-edited one.

---

## 6. AFFECTS DOWNSTREAM

Property columns are the spine — changing them ripples everywhere:
- **Presentations** read condition_level_id, complex_name/unit_number, address, sizes, title_type
  (`presentations.md` §7). A condition change revalues any unpublished draft.
- **Core Matches / MIC** score buyers against price/suburb/beds/baths/garages/size/erf/features
  (`market-intelligence.md`). Editing those re-ranks matches (recompute jobs run nightly + on observer).
- **Syndication (Andre — DOC-ONLY)** reads `features_json`/`spaces_json` (P24 mapper) and scalar columns
  (PP mapper) + `p24_*`/`pp_*`. **Do not change the `spaces_json`/`features_json` shape** — the P24 mapper
  and public website parse the exact keys (AT-81 §2 guard).
- **Public website / mobile API** read `features_json`/`spaces_json` + `config/property-spaces.php`.
- `PropertySuburbLinked` event fires on store/update (`:600-607`, `:941-950`).

---

## 7. AFFECTED BY UPSTREAM

The property row is **written back to by other features** — the surprising direction:
- **Presentations generator backfill** writes `complex_name`/`unit_number` during a presentation generate
  (`PresentationGeneratorService.php:428/432`, audited `:443-461`) — see `presentations.md` §9.1.
- **`PropertyCmaPropagationService::propagateFromPresentation`** writes `erf_number`,
  `municipal_valuation`/`_year`, `cma_gps_lat/lng`, `title_deed_number`, `last_cma_at` via
  `DB::table('properties')->update()` (`app/Services/Presentation/PropertyCmaPropagationService.php:103`,
  `buildUpdates` `:374-424`). **This bypasses Eloquent events/observers** → no audit row.
- **GPS backfill** (`PropertyGeoBackfillService`) writes `latitude`/`longitude` during generate.
- **Match-or-Create `promoteToStock()`** mints a new Property (`status='draft'`) from a tracked property on
  mandate signing (`prospecting-tracked-properties.md`).
- **`PropertyObserver` derives `address` (AT-266).** On `creating`/`updating`, when any structured address
  part (`street_number`, `street_name`, `complex_name`, `unit_number`, `unit_section_block`) is dirty (or on
  insert), the observer sets `address = composeAddressFromParts()` (`app/Observers/PropertyObserver.php:84-109`)
  — a non-empty composition only, so a price/status-only save never rewrites the address of a row nobody
  asked to touch. This is a *self*-write (same row, same save), not a cross-feature one; it is what makes
  `address` a derived column. **Sequencing caveat:** on a row whose parts are still polluted, this
  derivation would push the pollution *into* `address` — so the reconciler below must clean the parts first.
- **`corex:reconcile-property-addresses` (AT-266 one-off reconciler).**
  `app/Console/Commands/Properties/ReconcilePropertyAddresses.php` + service
  `app/Services/Properties/PropertyAddressReconciler.php`. Repairs rows whose `address` string and structured
  columns drifted apart during the two-source era. **Report-first by default** (writes nothing); `--apply`
  writes only rows the reconciler is confident about (status `HIGH`) after snapshotting every before-value to
  `storage/app/private/at266/reconcile-<stamp>.json` (reversible via `--rollback=<file>`). Three repair
  rules: `newline-glue` (a single-line input deleted an imported line break → "Umzimkhulu Court40 Bulwer
  Street"), `scheme-in-street` (the complex/scheme name sat in the `street_name` box, complex column empty
  or duplicated), `unit-as-number` (`street_number` held the unit or a non-number like "The"/"Farm
  Estates"); plus `recompose` (parts sound, display string merely stale — enrichment, not repair). **Safety
  invariant:** a proposal may never lose a token — every alphanumeric token in the original address must
  survive into the proposal, else the row is downgraded to `REVIEW` and left for a human (never machine-
  touched). Filters: `--agency=`, `--ids=`, `--limit=`. Runs under `withoutGlobalScopes()`.

---

## 8. AGENCY SETTINGS / CONFIG

| Setting | Default | Where | Notes |
|---------|---------|-------|-------|
| `config/property-spaces.php` (`all_space_types` `:25-37`, `space_features` `:48+`, `default_space_features` `:40-45`, `half_unit_spaces` `:22`) | — | spaces/features taxonomy | also read by mobile API, public website `ListingResource`, AI suggestor; hand-synced copies in `show.blade.php` JS + `VisionRecognitionService` (AT-81 §2) |
| `ai_image_recognition_enabled` | bool | `Agency.php:66` cast `:218` | gates AI photo suggestions (`PropertyController.php:360`) |
| `address_match_mode` | `'standard'` | `AgencyContactSettings` (read `ContactAddressPropertyGuard.php:76`) | create-from-contact duplicate aggressiveness |
| `matches_enabled` / `matches_show_on_properties` | `PerformanceSetting` | read `show.blade.php:1392` | Core Matches tab visibility |
| `properties_per_page` | setting | `corex.settings.properties-per-page` `web.php:1874` | list pagination |
| **Condition levels** (per-agency `PropertySettingItem` `condition_level` group) | To Remodel −30%, To Renovate −15%, **Average 0% (baseline, undeletable)**, Good +3%, Very Good +12%, Excellent +20%, Exceptional +38% | seeded `2026_06_17_120000_add_condition_levels_to_presentations.php:86-94`; `adjustment_pct` col `:39-41`; `properties.condition_level_id` FK `:46-58` | drives Presentations condition uplift |
| **title_type** options | full_title/sectional_title/vacant_land/other | `PropertySettingItem.php:38-52`; col added `2026_06_17_150000_add_title_type_to_properties.php` | comparability + holding cost |

`PropertySettingItem` (`app/Models/PropertySettingItem.php`) is the agency-isolated settings table
(`BelongsToAgency` + `SoftDeletes`, groups `:26-32`, `scopeGroup` `:54`); `property_status` items seeded
`2026_03_05_300003_seed_default_setting_items.php:14-18`; the AT-67-era status rename
(`For Sale • Reduced Price` → `Reduced Price`) is `2026_03_30_100001_rename_property_status_items.php:10-37`.

---

## 9. KNOWN FRAGILITIES

1. **Browser-autofill into address fields (AT-78 §A — MITIGATED; structural field-split still open).**
   Chrome / password-managers write a saved profile name (e.g. "Elizabeth Reichel") into the name-baited
   "Name of Unit, Section or Block" (`unit_section_block`) text input. No server path does this — it is
   pure client-side autofill. Mitigated by the FIX 4 `autocomplete`/`data-1p-ignore`/`data-lpignore`
   tokens on the modal inputs, but the field is still a plain text input; the durable fix (split field so a
   freehold has no sectional inputs) is open. **AT-266 raises the stakes of a leak:** now that `address` is
   *derived* from the parts on save (§7), a polluted part propagates straight into the display `address` on
   the next save — so those autofill-suppression tokens are load-bearing, and the reconciler
   (`corex:reconcile-property-addresses`) is the cleanup path if pollution does land. See AT-78, AT-266.

2. **Generator backfill silently mutates the property (AT-78 §0 — FIXED, now audited).** Generating a
   presentation can write `complex_name`/`unit_number` onto the property. Now match-gated + audited, but
   the cross-feature write direction surprises people. Any "address changed and nobody edited it" report
   = suspect a presentation generate. See `presentations.md` §9.

3. **`PropertyCmaPropagationService` bypasses the audit observer.** It writes via
   `DB::table('properties')->update()` (`:103`), so erf/municipal-valuation/GPS/title-deed changes leave
   **no `property_audit_log` row** (unlike the AT-78-audited backfill). A gap to know about.

4. **`status` is a free string, not an enum.** Default `draft` (migration
   `2026_02_25_201319_create_properties_table.php:36`). Programmatic values: `for_sale` (create default
   `:378`), `active` (publish), `draft` (duplicate), `sold` (mark-sold `web.php:2190`). The settings list
   (`property_status` items) and the literal column values are kept in sync by the rename migration — a
   drift hazard if new statuses are added in one place only.

5. **No hard deletes (enforced).** `destroy()` (`:969-975`) soft-deletes only; recovery via `restore()`
   (`:1599`). The `Average` condition baseline is undeletable (`PropertySettingItem::CONDITION_BASELINE_NAME`).

6. **Two-source `address` drift (AT-266 — RESOLVED for the going-forward model; historic backlog cleared by
   the reconciler).** Historically `address` and the structured `street_*`/`complex`/`unit` columns were two
   independent copies of one fact: the P24 import wrote both, then the Internal Address modal edited the
   parts while `address` sat frozen — producing NULL/stale/corrupted display strings ("Umzimkhulu
   Court40 Bulwer Street", scheme-in-street, unit-as-number). **Fixed by AT-266:** `address` is now *derived*
   from the parts by `PropertyObserver` on every save (§7), so the two can no longer diverge going forward,
   and `corex:reconcile-property-addresses --apply` repairs the historic drift (confident rows auto, ambiguous
   rows → human review, token-loss-safe, reversible). **What remains:** a row that has *never* been re-saved
   since AT-266 and was *not* swept by the reconciler still carries its old frozen `address` until its next
   save re-derives it — so keep reading through `buildDisplayAddress()` / the structured fields, never the raw
   `address` column, for anything match- or display-critical.

---

## Key file:line index
- `app/Http/Controllers/CoreX/PropertyController.php` — `:216` show, `:372` create, `:438` store, `:1005`
  update (`:1009-1032` AT-262 lenient draft save, `:1244-1245` clears `listing_type_pending`), `:1329`
  destroy, `:1342` duplicate (AT-262), `:1378` changeType (AT-262), `:1421` makeClone, `:390-421` contact
  prefill, processSpacesJson, restore.
- `app/Models/Property.php` — `:19` traits, fillable (`:266` `listing_type_pending`), casts
  (`:379` `listing_type_pending`), boot, `:119` isDraft, `:132` wasEverAdvertised, `:166` canChangeType (AT-262),
  `:764` buildDisplayAddress, `:855` composeAddressFromParts (AT-266 composer).
- `app/Observers/PropertyObserver.php` — `:84-109` AT-266 address derivation from structured parts.
- `app/Console/Commands/Properties/ReconcilePropertyAddresses.php` — `corex:reconcile-property-addresses`
  (report-first / `--apply` / `--rollback`, AT-266). Service: `app/Services/Properties/PropertyAddressReconciler.php`
  (`OK`/`HIGH`/`REVIEW`; `newline-glue`/`scheme-in-street`/`unit-as-number`/`recompose`; token-loss invariant).
- `app/Models/PropertySettingItem.php` — `:26-32` groups, `:38-52` title_type, `:54` scopeGroup.
- `resources/views/corex/properties/show.blade.php` — tabs, address modal + AT-78 autofill hardening,
  `:56-62` AT-262 "listing moved" banner, `:1454-1459` change-type form, `:1579-1593` listing-type select / lock hint.
- `config/property-spaces.php`, migrations `2026_06_17_120000` (conditions), `2026_06_17_150000` (title_type),
  `2026_03_30_100001` (status rename), `2026_08_02_100001` (`listing_type_pending`, AT-262).
- Audits: `.ai/audits/AT-78-presentation-comp-wrong-address-2026-06-21.md`.
