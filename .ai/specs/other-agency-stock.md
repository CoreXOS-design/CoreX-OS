# Other Agency Stock

**Status:** Live on QA1. 2026-10-07: P24 field audit + fixes (§5a, extension 3.8.2).
**Pillars:** Property (new status, new source-metadata table), Agent (importing agent becomes the property's agent), Contact (viewing packs / Core Matches use it downstream, unchanged).

## 1. What this is and why

Agents can use the CoreX Chrome extension to import ONE Property24 or PrivateProperty
listing that belongs to ANOTHER agency, as status `other_agency_stock`:

- never syndicated or advertised anywhere (P24, PP, website, Facebook/Instagram Ad Manager);
- included in Core Matches and viewing packs like any other property, with no "other agency" label shown to a buyer;
- the imported advert content (description, photos, price, features, sizes, address, source
  agency/agent details) is READ-ONLY in CoreX — "the stock is used exactly as the advert is."

## 2. Status + hard block on syndication/advertising

`Property::STATUS_OTHER_AGENCY_STOCK = 'other_agency_stock'` (`app/Models/Property.php`).
Deliberately **NOT** added to `OFF_MARKET_STATUSES` — it must stay on-market so it remains
eligible for Core Matches and viewing packs. It IS added to `systemStatuses()` so it's always
a valid write-side status for every agency without a per-agency Settings activation step.

Defense in depth — every portal/advertising path refuses it independently, not just once at
the controller:

| Layer | File | Guard |
|---|---|---|
| Controller trait | `app/Http/Controllers/Concerns/EnforcesMarketingReadiness.php` | `enforceListingNotDraft()` throws `DraftListingException` (custom message) for `isOtherAgencyStock()` |
| P24 service | `app/Services/Syndication/Property24/Property24SyndicationService.php` | `blockIfOtherAgencyStock()`, checked before the submit lock |
| P24 nightly sync | same file, `syncAllActivations()` | excludes the status from both the count and fetch queries (belt-and-braces; naturally unreachable since OAS never gets `p24_syndication_enabled`) |
| PP service | `app/Services/PrivateProperty/PrivatePropertySyndicationService.php` | explicit check at the top of `submitListing()` |
| PP status job | `app/Jobs/PrivateProperty/SyncPpListingStatusJob.php` | `handle()` returns early |
| Website service | `app/Services/Syndication/Website/WebsiteSyndicationService.php` | `setEnabled()` throws when turning ON (never blocks turning off); `resend()` no-ops; `bulkActivateActive()`'s query excludes it |
| P24 direct status push | `app/Observers/PropertyObserver.php` (`saved()`, the AT-369 bypass path) | returns early — this is the one P24 path that skips `Property24SyndicationService` entirely |
| Ad Manager picker | `app/Http/Controllers/Tools/AdManagerController.php` | added to `$adDeadStatuses` |
| Ad Manager publish | `app/Http/Controllers/PropertyMarketingController.php::publish()` | **was missing `enforceListingNotDraft()` entirely** — added; closes this gap for OAS and, incidentally, for every other off-market status too |

`DraftListingException::userMessage()` has an explicit `isOtherAgencyStock()` arm so the error
reads correctly ("belongs to another agency and can never be syndicated…") rather than the
generic "set it to Active" message.

## 3. Source data

**New table `property_external_sources`** (1:1 with `properties`), not new columns on
`properties`: `properties` is already 100+ columns wide and almost all of those are meaningful
for every row; these 9 fields would be null for every row except this narrow feature. Mirrors
the existing `tracked_property_external_refs` precedent already used in this codebase for
exactly this kind of "which portal + ref did this come from" metadata.

Columns: `agency_id`, `property_id` (unique), `portal` (`p24`|`pp`), `listing_ref`,
`listing_url`, `source_agency_name`, `source_agent_name`, `source_agent_phone`,
`source_agent_email`, `source_agent_profile_url`, `date_posted`, `imported_at`,
`imported_by_user_id`. Soft-deletable (non-negotiable #1).

**Dedup:** unique `(agency_id, portal, listing_ref)`. `OtherAgencyStockImportService::import()`
looks this up first — a re-import **updates** the existing `Property` + this row, never creates
a duplicate. `properties.external_id`/`p24_listing_number` (the agency's OWN-stock P24 identity
columns) are untouched by this feature.

### 3a. Consent gate (Johan)

Server-side, not just a UI checkbox: the import endpoint **rejects** any payload without
`consent: true` (`'consent' => ['required', 'accepted']` in
`OtherAgencyStockImportController::import()`).

Append-only evidence table `other_agency_stock_consents` (model `OtherAgencyStockConsent` —
`update()`/`delete()` are overridden to throw; the ONLY way rows appear is a fresh `create()`).
Every (re-)import writes a NEW row — never updates an existing one. Columns: `agency_id`,
`property_id`, `user_id`, `consented_at`, the **exact** `consent_wording` shown, a
`consent_wording_version` (bumped only if the consent *flow* itself changes, not on agency
wording edits — the exact text is already captured verbatim per row), `portal`, `listing_ref`,
`listing_url`, `source_agency_name`, `ip_address`, `user_agent`.

Wording is agency-configurable: `agencies.other_agency_stock_consent_wording` (nullable text;
`null` falls back to `OtherAgencyStockConsent::DEFAULT_WORDING`). Default wording (Johan,
2026-09-29, exact text):

> "I confirm that I have received permission from this agency to use their portal advert. I
> understand this listing is imported for sharing with my buyers only (viewings and viewing
> packs) and will not be advertised, syndicated or published by me or my agency."

**Setup Wizard (non-negotiable #10a) — IN the wizard (2026-10-07).** Both Settings (the role-visibility
checklist, §6, and this consent wording) are controls on the wizard's **Properties** step, saved by the
SAME `SettingsController::updateOtherAgencyStock` the Company Settings form uses. Roles render as a
tick-list of the agency's own roles (every role ticked = stored NULL = visible to everyone, the default —
nothing disappears on onboarding); the wording is a textarea, blank = `OtherAgencyStockConsent::DEFAULT_WORDING`
(shown as the greyed placeholder, never pre-filled). This replaces the earlier "deliberately not in the
wizard" note, which was a lane's call rather than Johan's. See `agency-onboarding-setup.md` §5.1; test
`tests/Feature/Onboarding/AgencySetupWizardPpraOasTest.php`.

Consent records are shown on the property to anyone who can see the property (who/when — see
§10 UI), and every content field write it enables is captured by the existing generic property
audit trail (§8).

### 3b. Importing agent = the property's agent (Johan)

The user who runs the import (`$request->user()` via the extension's bearer/session auth) is
set as `properties.agent_id` — the normal listing-agent field — as well as
`property_external_sources.imported_by_user_id`. This drives OWN/BRANCH/AGENCY scoping exactly
like any other property (`PermissionService::getDataScope()`/`AuthorizesPropertyAccess`) — no
special-casing anywhere else in the codebase. A re-import re-assigns `agent_id` to whoever runs
the re-import.

## 4. Import endpoint

`POST /api/v1/other-agency-stock/import` — `OtherAgencyStockImportController::import()`,
route in `routes/api.php`. **Deliberately outside** the `auth:sanctum` group the rest of
`routes/api.php`'s v1 surface uses: the extension's popup-issued token authenticates via
`AuthenticatePortalCapture` (session OR `Authorization: Bearer` checked against
`users.api_token`), the exact mechanism `/portal-captures/ingest` already uses — not a Sanctum
personal access token. Registered under `api/v1/*` with `->name()` per non-negotiable #7, so it
is discoverable in Admin → API regardless of which guard gates it.

Accepts the full payload: `portal`, `listing_ref`, `listing_url` (host-validated — see §9),
`consent`, `price`, `property_type`, `listing_type`, `beds`, `baths`, `garages`, `size_m2`,
`erf_size_m2`, `description`, address fields, `latitude`/`longitude`, `features[]`,
`photos[]` (URLs), and the `source_*` agent/agency fields.

`OtherAgencyStockImportService::import()` creates (or updates, on re-import) the `Property`
with `status = other_agency_stock`, upserts `PropertyExternalSource`, records a new
`OtherAgencyStockConsent` row, re-locks content on a re-import (§8a), and dispatches
`DownloadOtherAgencyStockPhotosJob` to pull every photo URL server-side into the SAME
disk/path/thumbnail pipeline an agent's own upload uses (`App\Services\Images\
PropertyImageStorer`'s conventions — `properties/{id}/…` on the `public` disk, thumbnails via
`PropertyThumbnailService`), queued, best-effort per photo (one failed download never fails the
import).

## 5. Extension — "Import as Other Agency Stock"

Extension source: `public/chrome-extension/portal-capture/` (the live one — see §11 for the
stale duplicate). Runs in the agent's own logged-in tab, so it reads the DOM/JSON-LD directly;
the server never scrapes the portals itself.

### Property24

- Reference: title carries `P24-<id>`; JSON-LD `@graph[@type=RealEstateListing]` confirmed live
  — **the `<script>` tag's `type` attribute is HTML-entity-encoded**
  (`type="application/ld&#x2B;json"`), so detection must match
  `<script type="application/ld[^"]*json">` case-insensitively, not the literal string.
- JSON-LD path: `offers.{priceSpecification.price, offeredBy.{name, url, worksFor.{name, url,
  logo}}}`, `about.{numberOfBedrooms, numberOfBathroomsTotal, floorSize.value, address.
  {addressLocality, addressRegion, streetAddress*}, latitude*, longitude*, description}`, `image` (single
  string only — not the full gallery), `name`, `url`. (*2026-10-07: `streetAddress` and `latitude`/`longitude`
  ARE present on listings that show an exact location — the earlier "no geo on this portal" note was
  wrong, or P24 has since added them. Listings that hide their address carry none; see §5a.)
- Erf size: plain HTML, `.js_sizeConversionsButton` span (not in JSON-LD).
- Floor/beds/baths: `.p24_propertyOverviewRow > .p24_propertyOverviewKey` (label) +
  `.p24_propertyOverviewResult .p24_info` (value).
- **2026-09-30 field audit** (Johan, property #21098, Norkem Park): the SAME
  `.p24_propertyOverviewRow` table also carries Levies, Rates and Taxes, Listing Date, Pets
  Allowed, Zoning, Parking (count), Pool (Yes/No) and multi-line feature lists for Kitchen,
  Garden and Security (one `.p24_info` block per row with real embedded newlines — P24's own
  `<br>`-per-item markup collapses to `textContent` newlines; `.split('\n')` recovers the
  list). None of these are in JSON-LD. The extension sends the raw row text as-is (currency
  string, "17 July 2026", "General Residential", etc.) — `OtherAgencyStockFieldMapper` does
  all parsing/normalising server-side (`parseCurrency()` strips everything but digits;
  `mapZoning()` maps P24's free text to CoreX's fixed zone_type dropdown options, leaving it
  null rather than storing an option the UI can't select). Levy/Rates/Zoning/Pets map to
  existing `properties` columns (`levy`, `rates_taxes`, `zone_type`, `pet_friendly`); Listing
  Date overwrites `date_posted` (already captured, previously never used to set
  `properties.listed_date`, which defaulted to "today" instead); Parking/Pool/Kitchen/Garden
  map into `spaces_json` (`buildSpacesJson()`); Security maps into
  `spaces_json.features.security[]` (a property-wide feature list, not a "space"). Wired into
  BOTH `OtherAgencyStockImportService` and `PropertyPullController` via the one shared mapper
  — own-stock pulls get the same fields OAS imports do.

  **Same-day regression + fix**: the first version of `buildSpacesJson()` deliberately left
  Bedroom/Bathroom/Garage out, reasoning those tiles read the dedicated beds/baths/garages
  columns directly. Wrong — confirmed live: once `spaces_json` is non-empty, the Spaces tiles
  show ONLY what `spaces_json` lists; there is no per-type fallback to the columns. A
  Bedroom x2/Bathroom x1 that had been showing via the empty-`spaces_json` fallback vanished
  outright the first time this ran. Fixed: `buildSpacesJson()` now also writes
  Bedroom/Bathroom/Garage (from the SAME beds/baths/garages values going into the property
  columns in the same write) and accepts the property's EXISTING `spaces_json` as a second
  argument, merged from by `type` — any space type it doesn't know about (a custom one an
  agent added, or a future addition) survives untouched; only types it has a fresh signal for
  are replaced. Also: `featuresAll` (not just `units[0].features`) is what the Spaces tiles
  actually read — both are populated now. Bathroom gets a `Shower only`-style free-text note
  (a second `.p24_info` block in the SAME overview row) into `bathroom_features`. Parking's
  count/features come from P24's own per-spot rows ("Parking 1" → "1 Carport", "Parking 2" →
  "1 open parking") when present — the single aggregate "Parking" row undercounts (confirmed
  live: shows "1" for a listing with 2 real spots) and is only a fallback when no per-spot
  rows exist.

  **Two display-only bugs found in the SAME investigation** (the underlying data was already
  correct — `properties.listed_date`/`pet_friendly` for #21098 read 2026-07-17/true in the DB
  the whole time): (1) the property edit form's read-only "Listed Date" field always fell back
  to `created_at` (when the row was saved) instead of the real `listed_date` column — never
  wrong for a normal property (usually the same day) but always wrong for OAS, which
  deliberately sets `listed_date` to the portal's own real listing date; fixed to prefer
  `listed_date`. (2) `pet_friendly` had no form field anywhere on the property page at all,
  so a correctly-imported value was simply never shown — added a Yes/No/Not-specified select
  next to Zone Type.
- Garages: **superseded 2026-10-07** — the `.p24_feature` ancestor of the garage icon no longer exists; garages come from the
  "Garage" overview row or the `.p24_listingFeatures` "Garages:" amount. See §5a.
- Agent/agency: inline `<script>window.listingLeadFormContext = {...}</script>` — plain JS
  object literal, `agencyName`, `agentDetails[]` (id, name, imageURL, profileURL),
  `primaryAgent`.
- **Full gallery**: **2026-10-07 superseded again (§5a)** — `image_ids[]`, the page's own ordered thumbnail ids. **2026-09-30 (superseded)** — this flow sent `first_image_id` +
  `image_count` (P24's own sequential image-id pattern, read the SAME way
  `content-p24-detail.js`'s Pull-flow extractor already did) and the SAME
  `DownloadPortalPropertyImages` job Pull uses downloads every photo server-side. The
  DOM-collected-URL-array approach this bullet originally described (and the
  `DownloadOtherAgencyStockGalleryJob` it fed) is now PP-only — P24 no longer sends a photo
  URL list from the client at all. See `OtherAgencyStockImportService::import()`.
- Phone: not in raw HTML — requires an authenticated `/Listing/ShowContactNumbers` AJAX call
  with a per-agent token. **Never call this** (Johan) — it registers a lead with the portal.
  Capture phone/email only if already visible in the DOM (they generally aren't for P24).

### 5a. P24 field audit — import 117621889 (2026-10-07, extension 3.8.2)

Johan imported `property24.com/for-sale/umhlali-golf-estate/ballito/kwazulu-natal/15100/117621889` on QA1 and
four fields were wrong. Cause per field, then the class fix. Evidence: the real page, fetched once and saved
(six real listings, trimmed, in `public/chrome-extension/portal-capture/tests/fixtures/p24/`).

| Field | Wrong because | Scraped wrong or stored wrong |
|---|---|---|
| Baths 25.0 (page: 2.5) | `num()` deleted every non-digit, so "2.5" became 25 | Extension. CoreX also could not hold 2.5 (endpoint validated `integer`) |
| Garages "—" (page: 2) | P24 changed its markup: the garage icon is no longer inside `.p24_feature`, so the lookup found nothing | Extension (stored the 0 it was sent) |
| Floor 1 m² (page: **287 m²**) | the overview loop matched any label *containing* "floor": "Number of floors \| 1" (and "Floor \| Tiled Floors", "Floor Number") overwrote "Floor Size \| 287 m²". On apartments the later "Floor \| Tiled Floors" wiped the real size to nothing | Extension. (The brief said P24 shows no floor figure — it does, 287 m².) |
| Street address missing | the extension never sent it and CoreX had no street-line parser; P24 carries "42 Springwood" in JSON-LD `streetAddress` and the "Street Address" row | Both: not scraped, and not stored anywhere |

**Class fixes**

- **One number reader** (`parseNumber`/`parseArea` in `p24ExtractOasFn`, copied into `ppExtractOasFn`): keeps decimals
  ("2.5", "2,5"), reads thousands ("1 375", "1,375", "1.375.000"), strips units, converts ha/acres to m², returns null
  (never 0) when there is no number. Applied to price, beds, baths, garages, parking, floor and erf size, the PP
  equivalents. (A price of "4999000.00" used to become 499 900 000.)
- **Labels matched exactly** in the overview table (`floor size`, `erf size`, `bedrooms?`, `bathrooms?`, `garages?`, `parking`,
  `kitchens?`, …) — never "contains". The size button beside the icons is an erf size only when its own title says so.
- **Garages / parking / covered parking stay separate**: Garage → `garages` (overview "Garage" row, else the "Garages:" icon-strip
  amount); open bays → Parking (per-spot rows, else icon-strip "Parking:", else the numeric "Parking" row); a "Covered
  Parking"/"Carport(s)" row adds to Parking with a "Covered parking" feature (**not seen on any of the six saved pages — unverified
  against a real one**). The icon strip's "Parking Spaces" figure is garages + parking added together and is never read.
- **Bathrooms 2.5 = `baths` 2 + `half_baths` 1**, the way every other CoreX screen stores it (property page "2 + ½", the P24
  syndication mapper's baths + 0.5 per half bath); the Bathroom space keeps the 2.5 total. `OtherAgencyStockFieldMapper::splitBathrooms()`.
  `half_baths` and `rental_amount` joined `OtherAgencyStockContentLock::LOCKED_FIELDS` (imported advert content). The endpoint now
  validates beds/baths/garages/parking as `numeric` and the service rounds the whole-number columns — an odd figure on one optional
  field never 422s the whole import.
- **Street address** → `street_number`, `street_name`, `complex_name`, `unit_number` via `OtherAgencyStockFieldMapper::parseStreetAddress()`
  (same conventions as `ProspectingListing::parseStreetNumber` / `EntryPointController::parseStreet`: street = last segment, a
  leading "12"/"12A"/"1/3"/"12-14" is the number, the suburb/city/province segment is dropped, "Unit 5" is the unit, any other earlier
  segment is the complex; no number = a street name only, nothing invented). `address` is derived from the parts by
  `PropertyObserver::saving()`. These are INTERNAL fields (§8): an existing non-empty value is never overwritten by a re-import,
  only an empty one is filled. Listings that hide their street address (e.g. the apartment sample) get none.
- **GPS** from JSON-LD `about.latitude/longitude` when present.
- **Photos: the gallery is the page's own ordered thumbnail ids** (`img.js_galleryThumbnail`, `lazy-src`), sent as `image_ids[]`; the
  job downloads exactly those, in that order. The earlier "first id + 1, +2 …" guess was wrong on five of the six saved pages
  (gaps, a later image with a lower id, a second batch uploaded weeks later) — it pulled other listings' photos and missed real
  ones. The strip's count equals P24's own "N images" on all six. `first_image_id`/`image_count` remain the fallback for an older
  extension build and for the Pull flow. `DownloadPortalPropertyImages` gained an optional 4th constructor argument `imageIds`.
- **Pool / Garden**: "Pool | Pool" and "Garden | Yes"/"Garden | Garden" are flags (yes unless "No"), not a feature called "Yes".
  Kitchen reads "Kitchens" (plural) and comma lists; Security/Kitchen/Garden lists split on commas as well as newlines.
- **Rentals**: the monthly rent is stored in `rental_amount` (the sale `price` is 0 on a rental — `Property::displayRentalPrice()` /
  `effectivePriceSql()`); it was being put in `price`, so a rental showed R 0 and priced itself out of rental matches.
- **Property type**: `property_type_label_hint` falls back to the "Type of Property" row when JSON-LD gives none.

**Checked against six listing types and found correct (no change):** property type (house / apartment / townhouse / vacant land /
commercial — a townhouse is JSON-LD `@type` "Apartment", the hint keeps it a Townhouse), price, beds, listing date, levies and rates,
pets, suburb/city via the URL's P24 suburb id, agent and agency, description.

**Mismatches found and NOT changed (report-only, none asked for):** P24 "Special Feature" / "Lifestyle" / "Description" ("Single
Storey") rows, the icon-strip tags (Pet Friendly, Fibre Internet, Furnished), "Occupation Date" / "Lease Period" / "Furnished" on
rentals, "Reception Rooms", "Office", "Study", "No Transfer Duty", "Standalone Building" are not imported. The **Pull as My Own Listing**
flow (`content-p24-detail.js`, lines ~385-396, ~232-254) still has the old digit-stripping (`parseInt("2.5")` → 2, a price "4999000.00"
→ 499 900 000) and the sequential-photo guess — own-stock, outside this task.

**Tests** — page → payload: `tests/p24-oas-extract.test.cjs` (jsdom, NOT a project dependency — see its header; golden payloads
`*.payload.json`); payload → stored property: `tests/Feature/Properties/OtherAgencyStockP24FixtureImportTest.php` (runs the golden
payloads through the real endpoint). **Re-testing on QA1:** there is no refresh path for an existing import other than importing the
listing again — re-import updates the same property in place (dedup on portal + listing ref), re-locks it and records a new consent.

### PrivateProperty

- Reference: `T\d+` in the URL.
- JSON-LD `Residence` is present but nearly useless directly: `photo[]` has only 1 image, no
  price, no floor/erf size; beds/baths/garages arrive as a generic `additionalProperty[]`
  array. It DOES carry reliable `address.{streetAddress, addressLocality, addressRegion}` and
  `geo.{latitude, longitude}` — the only geo source on either portal.
- **The real data lives in `window.serverVariables.bundleParams`** (confirmed live against a
  SECOND, agency-listed sample — `T3497323` — after the first sample, `T12292`, turned out to
  be a private-seller listing with `agencyInfo: null`): `bundleParams.galleryPhotos[]` (ALL
  photos, each with `mediumUrl`/`srcSet`), `bundleParams.priceDisplay.price` /
  `bundleParams.purchasePrice`, `bundleParams.mapCoOrdinates{lat,lng}`,
  `bundleParams.contactDetails[]` (`contactType: "Agent"`, `agentPageUrl`,
  `canShowContactNumbers`, `contactNumbers: null`), `bundleParams.agencyInfo` (`agencyName`,
  `agencyLogo`, `agencyPageUrl`, `brandColor`) — **`agencyInfo: null` means a private-seller
  listing; allow the import, store the source agency as blank.** All of this sits behind a
  page-obfuscation IIFE (`(()=>{const tokens=[...];const indices=[...];window[X]=JSON.parse(...)
  })();`) that reconstructs `window.serverVariables` — since the extension's content script runs
  AFTER the page's own script has already executed, it reads the already-parsed global directly
  via a `MAIN`-world injected script (`chrome.scripting.executeScript({world:"MAIN", ...})` in
  MV3), never re-implementing the token/index decoding itself.
- Floor/erf size: plain HTML, `.property-details__list-item > .property-details__name-value`
  (label) + `.property-details__value` (value).
- Phone: `bundleParams.contactDetails[].contactNumbers` is `null` in the page data — requires a
  separate client-side reveal action. **Never call it** (same rule as P24). Email: not present
  anywhere on either sample.

### Consent + preview (Johan)

Before import, the popup shows a **required** checkbox: *"I confirm I have received permission
from this agency to use their portal advert."* (agency-configurable wording, fetched from the
agency's Settings at popup-open time — falls back to `OtherAgencyStockConsent::DEFAULT_WORDING`
if the agency hasn't customised it). The Import button stays disabled until it's ticked. This
is UI convenience only — the server-side `consent: required|accepted` check in §3a/§4 is the
real gate; the checkbox cannot be bypassed by skipping it client-side because the request would
simply be rejected.

A preview/confirm step shows the extracted fields (address, price, beds/baths/garages, sizes,
photo count, source agency/agent) before the POST fires, so the agent can catch a bad
extraction before it becomes a property.

### 5b. Other Agency Stock can never be duplicated (2026-10-07, Johan — QA1 property 21174)

The property page's **Duplicate** action was live on an Other Agency Stock property (a copy would be a second, editable,
syndicate-able version of another agency's listing). Now:

- **Button**: disabled, with the hover reason "Other Agency Stock is another agency's listing and can't be duplicated. To use it again, import the portal listing again." (`Property::OTHER_AGENCY_STOCK_NO_DUPLICATE_REASON`).
- **Server**: `PropertyController::duplicate()` and `changeType()` (a duplicate + archive) refuse it — redirect back with the reason, or **403 JSON** for an API-style caller — and `makeClone()` itself throws, so no present or future caller can clone it. `Property::canBeDuplicated()` is the one check.
- **No other path exists** today: there is no bulk-duplicate on the properties list and no API duplicate route (the one clone route is `corex.properties.duplicate`). A route-table test fails if a second property-cloning route is ever added without extending this guard.
- Tests: `tests/Feature/Properties/OtherAgencyStockNoDuplicateTest.php` (button + route + JSON + change-type + clone builder + normal property still duplicates).

## 6. Visibility — a role setting, no hardcoding

`agencies.other_agency_stock_visible_roles` (nullable JSON `string[]`) — a direct column, not a
dedicated child table: unlike `calendar_event_class_settings` (one row PER EVENT CLASS, several
distinct visibility lists per agency), there is exactly ONE such setting per agency here, so
this follows the same pattern already used for `split_branches_enabled`/`assistants_enabled`
rather than adding a table for a single value. **NULL/empty = visible to all roles** (default —
nothing disappears from any agency's screens on rollout, per Johan's explicit requirement). An
agency narrows this from Settings by writing an explicit role list (or the literal string
`'all'`).

`App\Services\Properties\OtherAgencyStockVisibility::canSee(?User $viewer): bool` — pattern
mirrors `CalendarVisibilityResolver`. **A null/guest viewer (a buyer on a public shared match
page, or the finished viewing-pack PDF/view) is ALWAYS visible** — this setting gates which
INTERNAL CoreX roles see the stock in their own working screens, never what a buyer is shown.
This is the direct implementation of Johan's ruling: *"Buyers see it in viewing packs like any
other property; don't label the other agency."*

Applied via `Property::scopeVisibleOtherAgencyStock($query, ?User $viewer = null)` at:

- `MatchingService::propertiesForMatch()` — the ONE canonical query every staff-facing Core
  Matches surface funnels through (`ContactMatchController`, `MobileCoreMatchController`) AND
  every buyer-facing one (`SharedMatchController`, viewing-pack core-match labeling) — gated
  correctly either way because of the null-viewer rule above.
- `PropertyController::index` — the Properties list.
- `PropertyController::show` (via `AuthorizesPropertyAccess`) — property detail.
- `ViewingPackController::searchProperties` — the ad-hoc picker (previously had NO status
  filter of any kind).

### 6a. Where the settings live — navigation (2026-10-07, Johan: "cannot find other agency stock under company settings")

The two settings (who can see Other Agency Stock; the import consent wording) are one card with one Save,
`SettingsController::updateOtherAgencyStock`, reached from three places — all the same saver:

1. **Company Settings → Other Agency Stock tab** (`/admin/company-settings#other-agency-stock`). Its own
   tab in the tab bar (Company · Branding · Branches · **Other Agency Stock** · Website · Performance).
   It used to be a card at the bottom of the Branches tab, beside the Data Isolation switch, so nobody
   looking for it by name could find it. Save returns to this tab (the form posts
   `return_fragment=other-agency-stock`; the controller allow-lists that one value and adds it as the
   redirect fragment — a bare `back()` drops the fragment and used to land on the Company tab).
2. **Settings → search rail** ("Search settings…", Agency group): an "Other Agency Stock" link that opens
   the tab above. Shown only to users with `manage_performance_settings` (same gate as the page).
3. **Setup Wizard → Properties step** (see above).

Access is unchanged: route middleware + controller both require `manage_performance_settings`; the agency is
the signed-in user's own (`effectiveAgencyId()`), never a posted id. Test:
`tests/Feature/Admin/CompanySettingsOtherAgencyStockTabTest.php`.

## 7. Status-change gate

`App\Services\Properties\OtherAgencyStockStatusGate::canChange(User $user, Property $property,
string $newStatus): bool` — the one seam controlling who may change a property's status TO or
FROM `other_agency_stock`. Reads `$property->getOriginal('status')` (not the possibly
already-mutated current attribute) so it's safe to call before OR after assigning the new
status — this matters because its actual enforcement point,
`PropertyObserver::saving()` (right beside the existing AT-307 status-vocabulary guard), fires
AFTER the attribute has already been reassigned.

Backed today by a plain permission key, `other_agency_stock.change_status`, through the
existing `config/corex-permissions.php` role system (`role_defaults` grants it to
`branch_manager` on fresh install; agencies can widen via Role Manager) — no hardcoded roles.
Only enforced when there's an authenticated actor (`auth()->user()`); a system/job context
(queued reconciliation, console) is let through rather than breaking a path that has no human
to authorise.

**Docblock note (both the gate class and here):** Andre's upcoming "only authorised users can
turn syndication on" setting is expected to supersede or extend this check once it lands —
Other Agency Stock's entire reason for existing is that it must never be syndicated, so the
same authorisation question applies. Whoever wires that setting in should read
`OtherAgencyStockStatusGate` first rather than adding a second, parallel gate.

## 8. Content lock — "the stock is used exactly as the advert is" (Johan)

Once a property is (or was, in the same write) `other_agency_stock`, its imported advert
content is READ-ONLY: `App\Services\Properties\OtherAgencyStockContentLock::LOCKED_FIELDS` —
description/title/headline, price, beds/baths/garages/sizes, property/listing type,
features/spaces, all five image-gallery JSON columns, and the PORTAL'S OWN advertised
location (`suburb`, `city`, `province`, `address`, `latitude`, `longitude`).

**2026-09-30 correction**: `street_number`, `street_name` and `erf_number` are NOT locked.
Those are INTERNAL fields the agent fills in for their own records (unit/complex/erf detail
the portal ad never showed — needed for FICA/compliance/deeds work), never part of "the
advert" itself — they must stay editable on a locked OAS property, while the imported ad
content (including the portal's own suburb/city/province/address) stays read-only.
`complex_name`/`unit_number`/`property_number`/`stand_number`/`unit_section_block` were
never in `LOCKED_FIELDS` either (same reasoning) — this was a correction to an over-broad
original list, not a new exemption class.

### 8b. Contact not required to save (Johan, 2026-09-30)

`PropertyController::update()`'s existing "a contact must be linked before saving" rule
(enforced for every completed, non-draft property) is bypassed for `other_agency_stock`
specifically (`! $property->isOtherAgencyStock()` added to the guard) — the agency will
never have (and can never legitimately obtain) the other agency's seller/owner details, so
this listing can never gain a linked contact. Every other status keeps the rule unchanged.

Enforced in **`PropertyObserver::saving()`** — the one Eloquent-level chokepoint every update
path (property edit form, gallery/photo endpoints, bulk edit, API — anything that calls
`$property->save()`/`update()`) passes through, confirmed by reading every
`gallery_images_json`-touching call site in `PropertyController`/`PropertyWizardController`:
all use Eloquent writes, none bypass it with a raw query builder update. No bulk-edit feature
exists for properties today; if one is ever added, it is automatically covered as long as it
saves through Eloquent.

The only legitimate bypass is `Property::$allowOtherAgencyStockContentWrite` (a transient,
non-persisted flag, same idiom as the existing `$skipSyndicationAutomation`) — set by
`OtherAgencyStockImportService` during a (re-)import and by
`DownloadOtherAgencyStockPhotosJob` when it writes the downloaded gallery.

**Still allowed while locked:** internal notes, matching/viewing-pack use, the status change
itself (via §7's gate), and archiving (soft delete) — none of those touch a `LOCKED_FIELD`.

**Audit trail:** every content edit made while unlocked is captured by the EXISTING generic
property audit mechanism (`PropertyObserver`'s AT-321 diff logging) with zero new code — the
locked fields are ordinary, non-noise columns (not in `AUDIT_NOISE_COLUMNS`), so a write that
gets past the lock is already recorded who/when/field/old→new the same way every other property
edit is.

### 8a. Unlock via an authorised user (Johan)

`OtherAgencyStockStatusGate::canAuthoriseAdvertEdit(User $user): bool` — same permission key as
`canChange()` today (`other_agency_stock.change_status`), deliberately: Johan's own words, "it
will delegate to Andre's authorised-users setting later." Kept as its own named method (not
just an alias of `canChange()`) so the two can diverge later without a call-site sweep.

**Append-only event log** `other_agency_stock_unlocks` (model `OtherAgencyStockUnlock` —
`update()`/`delete()` throw, same pattern as the consent table). THREE event types share one
table, never a separate mutable "current state" column:

- `requested` — an agent asks to edit (`requested_by_user_id`, `reason`)
- `approved` / `declined` — an authorised user decides (`decided_by_user_id`, `request_id`
  points back at the `requested` row it resolves)
- `relocked` — an authorised user manually re-locks, OR a re-import re-locks automatically
  (`relocked_by_user_id`)

**Current state is always derived from the latest row for a property**
(`OtherAgencyStockUnlock::currentStateFor()`, `ORDER BY id DESC LIMIT 1`):
`approved` → **unlocked**; `requested` → **pending**; `declined`/`relocked`/no rows → **locked**.
Nothing to drift out of sync with the history, because there is no separate state to drift.

**Who may edit while locked, exactly** (`OtherAgencyStockContentLock::actorMayEditAdvertContent()`):

1. An authorised user (`canAuthoriseAdvertEdit()`) — always, directly, no request needed.
2. The property's own agent (`property->agent_id === actor->id`) — ONLY while the derived
   state is `unlocked`. Any OTHER agent is refused even while unlocked (the unlock is scoped to
   this property's agent specifically, not "any agent").

**Flow** (`app/Http/Controllers/CoreX/OtherAgencyStockUnlockController.php`,
`routes/web.php` under `corex.properties.other-agency-stock.*`):

- `POST .../request-edit` — agent-facing; creates a `requested` row (refused if not currently
  `locked`), sends `OtherAgencyStockUnlockRequestedNotification` (in-app/database channel ONLY
  — never mail) to every authorised user in the agency
  (`OtherAgencyStockStatusGate::authorisedUsersFor()`), and creates one `CommandTask` per
  authorised user (task/inbox item) using the existing `CommandCenter\CommandTask` model.
- `POST .../unlocks/{unlock}/decide` — authorised-user-only; creates `approved`/`declined`,
  refuses if the request is no longer the current pending one (someone else already decided,
  or it's stale), notifies the requester (`OtherAgencyStockUnlockDecidedNotification`, database
  only), marks the matching `CommandTask` done.
- `POST .../relock` — authorised-user-only; creates a `relocked` row.
- A re-import (`OtherAgencyStockImportService::import()`) creates a `relocked` row automatically
  whenever it's updating an EXISTING property (not needed on a brand-new import — no unlock
  rows exist yet, so `currentStateFor()` already resolves `locked`) — "a re-import re-locks it
  and records a new consent" (Johan), both halves implemented in the same transaction.

No outbound mail anywhere in this flow (Johan) — both notification classes declare
`via() { return ['database']; }` only, mirroring the existing
`NewPropertyMatchNotification` precedent in this codebase, which already documents the same
"in-app only, no per-item email" reasoning.

## 9. Portal link + URL validation

Show a clickable "View on Property24"/"View on Private Property" link
(`target="_blank" rel="noopener noreferrer"`) on: the property show page header, and a small
icon link on the property list row/card — both gated on `isOtherAgencyStock()` and
`externalSource->listing_url` being present.

Server-side host validation, enforced at the import endpoint (§4) — `listing_url` and
`source_agent_profile_url` must resolve to `property24.com`/`www.property24.com` or
`privateproperty.co.za`/`www.privateproperty.co.za` (`parse_url(..., PHP_URL_HOST)` compared
against an explicit allow-list) — before either is ever stored.

## 10. UI (lean, per Johan — no banners)

- Locked property: one line, `"Locked — imported from Property24"` (or Private Property), plus
  a `Request edit access` button for the agent, or the live Request/Pending/Unlocked-by-X state.
- Read-only field rendering: one short line, `"Imported from <portal> — update by re-importing"`.
- Consent record(s): who/when, shown to anyone who can see the property; included in the
  audit/history tab.
- Settings: the role-visibility checklist and the consent-wording textarea, both agency-scoped,
  both under Properties settings.

## 11. Chrome Web Store prep (no publishing)

- Public privacy-policy route + page for the extension.
- `manifest.json` gains `homepage_url` and a `privacy_policy`-equivalent reference (the store
  wants the URL in the LISTING, not the manifest itself, which has no such field — the manifest
  change is `homepage_url` pointing at the same page).
- Version bump.
- A package script producing a clean zip — explicitly EXCLUDES `chrome-extension/portal-capture/`
  (the stale, un-iconned, `<all_urls>`-requesting duplicate flagged in the read-only
  investigation) — reported, not deleted (out of this task's scope; a separate cleanup call).
- `.ai/specs/chrome-web-store-listing.md` — store description, single-purpose statement,
  per-permission/host justifications, screenshots list.
- Web Store owner account: `corexaj2026@gmail.com` (Johan) — recorded for whoever eventually
  submits; no submission happens as part of this build.

## 12. Testing / verification status

See the build report for exactly what ran and what could not run pre-merge (the
`verify-alpine-render.mjs`/real-browser QA1 gates require the change to already be live on
`/corex-qa1`, which happens only after this branch is merged — Standard −1s/−1u).
