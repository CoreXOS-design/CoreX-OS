# Atlas — Syndication (Property24 + Private Property)

> **Status: DONE (description only)** · Last verified: 2026-06-22
> **⚠ ANDRE'S DOMAIN — this is an atlas description for cross-reference only. Touch NO syndication code.**
> Reads property data; documented here so other features' reads/writes of `p24_*`/`pp_*` columns and the
> location tables resolve. Cited: AT-81 §2 (taxonomy / feature data).

---

## 1. WHAT IT DOES

Syndicates CoreX `properties` to two external portals — **Property24 (P24)** via an ExDev **REST** API, and
**Private Property (PP)** via a **SOAP** API. The two integrations are deliberately separate code trees
(`App\Services\Syndication\Property24\*` vs `App\Services\PrivateProperty\*`). Syndication is **manual and
per-portal** (toggle + submit per property), independent of the in-house website "publish".

---

## 2. ENTRY POINTS

- **P24 per-property** `routes/web.php:2311-2316` → `Property24\P24SyndicationController` (toggle/submit/
  deactivate/reactivate/status/readiness). **PP per-property** `:2294-2306` → `PrivateProperty\SyndicationController`.
- **Ad Manager** `tools.ad-manager` `:721-723` → `Tools\AdManagerController` (`index:55`).
- **Location import** (owner-only): `admin.importer.p24-locations` `:480`, `admin.importer.pp-locations`
  `:485` → `Admin\ImporterController`. **P24 suburb mappings** `admin.p24-suburbs.*` `:947-955`.
- **Admin Integrations** `:2040` is a Meta/Facebook hub; agency P24 connection test/refresh at `:2076-2077`.
- Views: property syndication panels in `corex/properties/show.blade.php` (PP `:952-982`, P24 `:1156-1184`);
  `admin/importer/{p24,pp}-locations.blade.php`; `tools/ad-manager.blade.php`. Nav: Ad Manager
  `corex-sidebar.blade.php:1196`, P24/PP Locations `:1595-1596`.

---

## 3. THE FLOW — trigger / feed / payload

- **Trigger:** per-property toggle then submit. P24 `P24SyndicationController::toggle:26` flips
  `p24_syndication_enabled`, `submit:53` calls `submitListing`. PP equivalent `:108`. Toggle-on → status
  `pending`; toggle-off deactivates on the portal if previously active.
- **Publish ≠ syndication:** `PropertyController::publishToggle:1009-1046` sets only `published_at` for the
  in-house website — it does NOT invoke P24/PP. Cloning resets syndication flags (`:996-997`).
- **Payload build:** the mappers (§4/§5). **State sync:** polling/event-feed jobs
  (`PollPrivatePropertyActivation`, `SyncPrivatePropertyActivations`, `ProcessPrivatePropertyEventFeed`, P24
  `syncAllActivations`); `DesyndicatePropertyFromPortalsJob` on mandate expiry; P24 lead-pull jobs.
- **Logs:** every P24 API call with a propertyId → `P24SyndicationLog` (`Property24ApiClient::logToDb:325`).
  PP logs to `Log::channel('private_property')` (no DB log table).

---

## 4. THE P24 INTEGRATION (ExDev REST)

- **Client** `Property24ApiClient.php`: REST + Basic Auth `:227`, base `/listing/v53`. `saveListing` POST
  `:66`, `setListingStatus` PUT `:74`, `findSuburb` `:108`, `getLeads` `:190`, `smokeTest` `:199`.
  Credentials: per-agency `p24_*` else `config('services.property24_syndication')` `:20-39`.
- **Mapper** `Property24ListingMapper::map:20-104` builds the v53 payload. **`buildPropertyFeatures:128-254`
  reads `features_json` + `spaces_json`** `:130-132` and matches them against **hardcoded English string
  literals inline** via `$hasFeature(...)` (case-insensitive `array_intersect`) `:133` and
  `$countSpaces($type)` `:134` — e.g. Garden `'Garden','Landscaped'…` `:138`, Pool `:139`, pets `:141`,
  parking `:148-157`, studies `:167`. Scalars read directly (garages `:137`, beds `:145`, baths `:146`).
  **This vocabulary is a private copy inside the mapper — the AT-81 §2 hand-sync risk.**

---

## 5. THE PP INTEGRATION (SOAP)

- **Client** `PrivatePropertySoapClient.php`: native `\SoapClient` over WSDL `:45-69` (SSL verify disabled
  `:57-59`), retry-on-timeout. Ops: `UpdateListing` (submit) `:159`, `ListingStatusUpdate` (deactivate/
  reactivate) `:184/:229`, `GetListingEventFeedByBranch` `:199`. Every call needs a SHA1-digest `Token`
  (`PrivatePropertyTokenService::Digest = Base64(SHA1(UID+StampTime+Password+Expires))` `:30`); `BranchId`
  from agency config. Config precedence per-agency `pp_*` → env (`PrivatePropertyConfig:21-72`).
- **Mapper** `PrivatePropertyListingMapper`: top-level `:40-76` + `buildAttributes:351-391` read **scalar
  columns only** — Bedrooms←beds, Bathrooms←baths, Garages←garages, FloorArea←size_m2, LandArea←erf_size_m2
  `:356-361`, Rates←rates_taxes, Levies←levy `:377-382`. **`features_json`/`spaces_json` are never
  referenced** (grep: zero hits) — PP loses all granular feature/room data that P24 derives from the JSON.

---

## 6. LOCATION LOOKUPS

`p24_suburbs` (`P24Suburb`, cols `p24_id`/`p24_city_id`/`name`) and `pp_suburbs` (`PpSuburb`, `pp_suburb_id`
/`normalised_name`), plus province/city/country tables. Populated by `Admin\ImporterController` location
methods dispatching `SyncP24Locations` (REST walk) / `SyncPpLocations` (SOAP walk). Property suburb → portal
id: P24 `Property24ListingMapper::resolveSuburbId:374-393` (checks `pp_suburb_id` first `:376`, then
`P24Suburb::lookup`, fuzzy LIKE, live API fallback); PP `resolvePpSuburbId:118-149` (matches `suburb` name to
cached `pp_suburbs` by `normalised_name`, disambiguates by province, persists `pp_suburb_id`).

---

## 7. DATA READ / WRITTEN

**Reads `properties`:** P24 reads `features_json`/`spaces_json` (own inline vocab) + scalars + photos; PP
reads scalar columns only (AT-81 §2 confirmed at the data level). **Writes `properties`:** P24 →
`p24_ref`, `p24_syndication_enabled/status`, `p24_last_error`, `p24_activated_at`, `p24_last_submitted_at`,
sync timestamps, `p24_suburb_id` (`Property24SyndicationService::submitListing:137-162`); PP → `pp_ref`,
`pp_syndication_enabled/status`, `pp_last_error`, `pp_last_submitted_at`, sync timestamps, `pp_suburb_id`,
`pp_hide_*` (`PrivatePropertySyndicationService::submitListing:86-124`). **Location tables** + **`p24_syndication_logs`**.
**Agency credentials** (`Agency.php`): P24 `p24_username`/`p24_password` (encrypted `:223`) etc. `:83-90`;
PP `pp_username`/`pp_password` (encrypted `:228`)/`pp_branch_guid`/`pp_wsdl` `:91-94`.

---

## 8. AGENCY SETTINGS / CONFIG

Per-agency P24/PP credentials + `p24_enabled`/`pp_enabled` flags (above), with env defaults
(`config/services.property24_syndication`, `config/services.private_property`). Manual P24 suburb mappings
(`admin.p24-suburbs.*`). Marketing-readiness gating before submit (`enforceMarketingReadiness` trait + per-
portal `checkReadiness`).

---

## 9. KNOWN FRAGILITIES (descriptive — Andre's domain)

1. **Two divergent integration styles** — P24 REST/JSON/Basic-Auth vs PP SOAP/WSDL/SHA1-digest; no shared
   transport, validation, or error model; PP SSL verification disabled (`PrivatePropertySoapClient.php:57`).
2. **Taxonomy hand-sync risk (AT-81 §2)** — P24's feature vocabulary is a private inline list of English
   literals in `buildPropertyFeatures:136-254`, kept in lockstep by hand with whatever populates
   `features_json`/`spaces_json` at property-edit time. PP ignores those blobs entirely, so the two portals
   can present materially different feature sets for the same property; a new feature string maps to nothing
   on PP and to nothing on P24 unless added to the inline list.
3. **Suburb-id cross-wiring** — `Property24ListingMapper::resolveSuburbId` reads `pp_suburb_id` to resolve a
   `P24Suburb` (`:376-378`) — a column coupling between the two portals' location systems; resolution depends
   on cached tables that only populate after a manual location import.
4. **Credential config** — dual precedence (per-agency DB → env); CLI/queue contexts auto-pick the first
   enabled agency (`PrivatePropertyConfig:40-48`), which can route scheduled jobs to an unexpected branch if
   multiple agencies are PP-enabled.
5. **Listing-state sync** — state reconciled by polling/event-feed jobs rather than a single source of truth;
   `*_syndication_status` on `properties` is a per-portal local mirror. Website publish and portal
   syndication are fully decoupled.

---

## Key file:line index (read-only reference)
- `app/Services/Syndication/Property24/{Property24ApiClient.php,Property24ListingMapper.php,Property24SyndicationService.php}`.
- `app/Services/PrivateProperty/{PrivatePropertySoapClient.php,PrivatePropertyListingMapper.php,PrivatePropertySyndicationService.php,PrivatePropertyConfig.php}`.
- `app/Http/Controllers/{Property24/P24SyndicationController.php,PrivateProperty/SyndicationController.php,Admin/ImporterController.php,Tools/AdManagerController.php}`.
- `app/Models/{P24Suburb.php,PpSuburb.php,P24SyndicationLog.php}`; `app/Models/Property.php` (`p24_*`/`pp_*` columns), `app/Models/Agency.php` (credentials).
