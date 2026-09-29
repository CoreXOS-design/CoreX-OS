# Other Agency Stock

**Status:** Built on branch `other-agency-stock-2026-09-29` off `origin/QA1`. Awaiting QA1 merge + browser verification.
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

**Setup Wizard (non-negotiable #10a):** deliberately NOT surfaced in the onboarding wizard —
same documented carve-out already used in this codebase for expert/rarely-touched knobs (e.g.
the Ad Manager background-removal thresholds). This is Johan's call to make, not the lane's;
recorded here explicitly rather than silently omitted. If he wants it in the wizard, it's a
one-line `controls[]` entry in `config/agency-onboarding-copy.php` pointing at a saver on the
Settings controller.

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
  {addressLocality, addressRegion}, description}`, `image` (single string only — not the full
  gallery), `name`, `url`. No `geo` field exists on this portal at all.
- Erf size: plain HTML, `.js_sizeConversionsButton` span (not in JSON-LD).
- Floor/beds/baths: `.p24_propertyOverviewRow > .p24_propertyOverviewKey` (label) +
  `.p24_propertyOverviewResult .p24_info` (value).
- Garages: `.p24_feature`/`.p24_featureAmount` pair near `icon_garage_updated.svg`.
- Agent/agency: inline `<script>window.listingLeadFormContext = {...}</script>` — plain JS
  object literal, `agencyName`, `agentDetails[]` (id, name, imageURL, profileURL),
  `primaryAgent`.
- **Full gallery: every `images.prop24.com/\d+` URL present as plain server-rendered `<img>`
  tags on the page** — confirmed live (30 of 30 found for the sample listing). The extension's
  EXISTING P24 detail-page logic (`content-p24-detail.js`) uses a `first_image_id` +
  `image_count` sequential-ID-guessing heuristic instead — **this flow does not reuse that
  heuristic**; it collects every matching URL directly from the rendered DOM.
- Phone: not in raw HTML — requires an authenticated `/Listing/ShowContactNumbers` AJAX call
  with a per-agent token. **Never call this** (Johan) — it registers a lead with the portal.
  Capture phone/email only if already visible in the DOM (they generally aren't for P24).

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
features/spaces, all five image-gallery JSON columns, and every address/geo field.

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
