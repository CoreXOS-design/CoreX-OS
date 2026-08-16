# Private Property (PP) Syndication — Spec
> Living reference for the Private Property integration. Reflects the
> ACTUAL current implementation, not an idealised target.
> Last updated: 2026-08-03

---

## 1. Pillar Connections

| Pillar    | Read | Write |
|-----------|------|-------|
| Property  | ✅ all PP-mapped fields | `pp_syndication_status`, `pp_ref`, `pp_listing_feed_ref`, `pp_last_submitted_at`, `pp_activated_at`, `pp_last_error`, `pp_images_last_synced_at`, `pp_listing_last_synced_at`, `pp_delay_until` |
| Contact   | — | New leads from PP webhook → `Contact` (contact_type "Lead") |
| Deal      | — | — |
| Agent (User) | `name`, `email`, `cell`, `agent_photo_path`, `pp_unique_agent_id` | `pp_unique_agent_id` |

---

## 2. Architecture

```
┌─────────────────────┐    SOAP    ┌──────────────────────────┐
│  CoreX (Laravel 11) │◀──────────▶│  PP Agency Feed Service  │
│                     │            │  (sandbox/production)    │
│  Token + SoapClient │            │  AgentImport.asmx        │
└─────────────────────┘            └──────────────────────────┘
        ▲                                       │
        │                                       │ (HTTPS POST + HMAC)
        │                                       ▼
┌─────────────────────┐                ┌──────────────────────┐
│  Schedulers (15min) │                │  PP Webhook (leads)  │
│  - SyncActivations  │                │  → /api/pp/webhook   │
│  - EventFeed        │                └──────────────────────┘
└─────────────────────┘
```

Code locations:
- Services — `app/Services/PrivateProperty/`
- Controllers — `app/Http/Controllers/PrivateProperty/`
- Jobs — `app/Jobs/SyncPrivatePropertyActivations.php`, `app/Jobs/PollPrivatePropertyActivation.php`, `app/Jobs/ProcessPrivatePropertyEventFeed.php`
- Commands — `app/Console/Commands/PpManage.php`, `app/Console/Commands/PpSmokeTest.php`
- Webhook — `app/Http/Controllers/PrivateProperty/PpWebhookController.php`
- Config — `config/services.php` key `private_property`
- Log channel — `private_property` (file `storage/logs/private_property.log`)

---

## 3. Configuration

`.env`:
```
PP_USERNAME=HFCoastalUser
PP_PASSWORD=***
PP_BRANCH_GUID=AF7DCE26-ED1B-4541-A88B-F35DF2B1BAB5
PP_WSDL=https://services.sandbox.pp.co.za/AgentImport/AgentImport.asmx?WSDL
PP_SANDBOX=true
PP_IMAGE_BASE_URL=https://corex.hfcoastal.co.za
PP_WEBHOOK_SECRET=                  # filled when registered in PP Admin Portal
```

`config/services.php` → `private_property` block exposes the same keys plus `webhook_secret`.

---

## 4. Token Construction

`PrivatePropertyTokenService::generate()` returns:
```
{
  Digest    = base64( sha1(UID + StampTime + Password + Expires, raw=true) )
  UserName  = PP_USERNAME
  StampTime = gmdate('Y-m-d\TH:i:s\Z')
  Expires   = StampTime + 24h
  UID       = Str::uuid()
}
```
Password is never sent to PP — only digested. Token is generated per-call.

---

## 5. SOAP Methods (PrivatePropertySoapClient)

| Method                          | WSDL op                          | Notes |
|---------------------------------|----------------------------------|-------|
| `getBranchDetails()`            | GetBranchDetails                 | Smoke-test |
| `updateAgent($agentData)`       | UpdateAgent                      | Creates **or** updates by `AgentId` (= internal CoreX user id) |
| `updateListing($listingData)`   | UpdateListing                    | Creates/updates a listing by `PropertyId` |
| `getListingStatus($id)`         | GetListingStatus                 | Polled by `SyncPrivatePropertyActivations` |
| `deactivateListing($id, $type)` | ListingStatusUpdate              | sets `PropertyStatus=Inactive` |
| `reactivateListing($id, $type)` | ListingStatusUpdate              | sets `PropertyStatus=ForSale` |
| `getListingEventFeed($key, $start)` | GetListingEventFeedByBranch  | Continuation-key paged event stream |
| `getReferenceNumber($id, $type)` | GetReferenceNumberByListing     | Diagnostic |
| `updateShowday($data)`          | ListingShowdayUpdate             | |
| `updateAgentImage($agent, $url)`| UpdateAgentImage                 | XML field is **`imgurl`** (lowercase) |
| `getAllAgentsForBranch()`       | GetAllAgentsForBranch            | |
| `getAgent($agentId)`            | GetAgent                         | Used to fetch encrypted PP agent id |
| `getListingSummary($id)`        | ListingSummary                   | Diagnostic |
| `getActiveListings()`           | GetActiveListings                | Diagnostic |
| `updateUniqueAgentId($encId,$ourId)` | UpdateUniqueAgentID         | Re-maps PP's internal agent record to our External Ref |
| `updateUniqueListingId($encId,$ourId,$type)` | UpdateUniqueListingID | Re-maps PP's internal listing record |
| `updateListingVideoOrMatterport($uuid, $type, $youtube?, $matterport?)` | UpdateListingVideoOrMatterport | **`$uuid` MUST be `pp_listing_feed_ref`**, NOT `pp_ref` |

Retry policy: `call()` retries once on timeout-style faults (`Error Fetching http headers`, `Could not connect`, `timed out`) with a 3s backoff and a fresh SoapClient.

---

## 5b. Listing status parity — PP hears under-offer / sold (AT-282)

**The gap:** `PropertyObserver` fanned a `properties.status` change out to Property24 only (inline `setListingStatus`); it held **zero** PP references. So when a property went under offer, P24 updated in seconds and PP received **nothing** — the listing kept advertising as plainly "For Sale" until an agent hit Refresh by hand. `sold` reached PP only as a `Inactive` delist (removed), never "Sold". Root cause: CoreX models status in **two tiers** — base `status` + an optional `status_label` sub-label ("Under Offer" on an on-market base) — and the P24 mapper reads the sub-label while the PP mapper read only `$property->status`.

**The fix (one portal-neutral resolver, both portals translate):**
- **`App\Services\Syndication\ListingLifecycle::resolve($status, $statusLabel)`** — the ONE answer to "what lifecycle state is this listing in?" (sub-label authoritative; normalises `Under_Offer` / `• Under Offer` / `under offer` alike). A portal mapper only *translates* the state into its own enum.
- **`PrivatePropertyListingMapper::statusFor(Property, $listingType)`** → the PP `ListingStatusUpdate` `PropertyStatus`: `under_offer → PendingOffer` (stays live, flagged), off-market (incl. `sold`) → `Inactive`, else `ForSale`/`ToLet`. This is the ONLY PP path that may carry `PendingOffer`; the full-submit `mapPropertyStatus()` stays `ForSale`/`ToLet`/`Inactive` (the UpdateListing submit contract does not accept `PendingOffer`).
- **`PrivatePropertySoapClient::setListingStatus($id, $listingType, $status)`** — a generic `ListingStatusUpdate` (generalises deactivate/reactivate).
- **`PrivatePropertySyndicationService::syncStatus(Property)`** — pushes, then **reads the status back and only records success when PP's own answer matches** (`verifyStatus()`; space-insensitive compare, since PP writes `PendingOffer` but reads back `Pending Offer`). PP's "Successful" means "received", not "applied" — same class as AT-221 (P24 200-but-not-on-portal). An unverified push → `pp_syndication_status='error'` + `pp_last_error`, not "done". `PendingOffer` is recorded `active` (still on portal); only `Inactive`/`Archived` write `PORTAL_OFF_STATUS` so the next delist guard reads it correctly.
- **Wire:** `PropertyObserver` dispatches `App\Jobs\PrivateProperty\SyncPpListingStatusJob` on a `status` **or** `status_label` change when `pp_syndication_enabled && pp_ref` — placed **above** the P24 guard (which early-returns for non-P24 listings, else PP-only listings would be skipped). Queued (SOAP over the internet; a save must never wait on a portal); the job re-checks the guards at run time.

**Declared decision (Johan/Andre to confirm at staging):** `sold → Inactive` (remove) is the cautious mapping AT-68's live probe left in place — it could not confirm PP keeps a Sold listing ON the portal the way P24 does. PP's read-back **does** model `'Sold'`, so `sold → Sold` (true P24 parity, keep-on-portal-as-sold) is likely achievable; **qa1 blanks PP outbound**, so the real round-trip verifies at Staging. Related: **AT-271** (Andre — the refresh *trigger* in the same files); this ticket is the PP *mapping* half.

---

## 6. Listing Mapper (PrivatePropertyListingMapper)

`map(Property $p): array` builds the WSDL `Listing` struct. All fields below are sent on every submission.

| WSDL field | CoreX source | Notes |
|---|---|---|
| `PropertyId` | `(string) $p->id` | Our External Ref |
| `BranchId`   | `config('services.private_property.branch_guid')` | |
| `Category`   | `mapCategory($p->category)` → `Residential\|Land\|Farms\|Commercial` | |
| `MandateType`| `mapMandateType($p->mandate_type)` → `FullMandate\|OpenMandate\|Rental\|HouseShare\|AuctionOnly` | sole→Full, open/dual→Open |
| `StreetName` | `$p->street_name` (fallback parse) | ≤100 chars; suspicious-keyword guard |
| `StreetNumber` | `$p->street_number` (fallback parse) | required |
| `FloorNumber`/`ComplexName`/`UnitNumber` | direct | |
| `Suburb` / `Town` | `$p->suburb` / `$p->town ?? city` | must NOT be identical |
| `SuburbId` | `$p->pp_suburb_id` | when set, `Suburb`/`Town` cleared (PP106) |
| `Province`  | `mapProvince($p->province)` | enum: `KwaZuluNatal\|Gauteng\|WesternCape\|EasternCape\|FreeState\|Limpopo\|Mpumalanga\|NorthWest\|NorthernCape` |
| `Headline`  | `$p->headline ?? $p->title` | required |
| `Description` | `$p->description` | required |
| `Price`     | `(float) $p->price` | > 0 |
| `Deposit`   | rental: `$p->deposit_amount`; sale: `0.0` | |
| `ListingDate`/`ExpiryDate`/`AvailableFrom` | timestamps in `Y-m-d\TH:i:s` | |
| `AgentId`   | `(string) $agent_id [+ ',' + $pp_second_agent_id]` | Multi-agent comma-join |
| `PhotoUrls` | `{ string: [https://… , …] }` | min 3 (sale) / 1 (rental); first 20; force HTTPS via `image_base_url` |
| `XCoordinate`/`YCoordinate` | `$p->latitude` / `$p->longitude` | |
| `ListingType` | `Sale\|Rental` | |
| `PropertyStatus` | `ForSale\|ToLet` (derived from listing type) | |
| `ShowdayEvents` | from `$p->activeShowdays` | ArrayOfShowdayEvent |
| `Attributes` | structural: `Bedrooms,Bathrooms,Garages,FloorArea,LandArea,HomeType\|BusinessType\|FarmType\|LandType,Rates,Levies` **+ feature attributes** (see §Feature Attributes) | category-specific type attribute; features from `features_json`/`spaces_json` |
| `HideStreetName/No/ComplexName/UnitNumber` | bool, `pp_hide_*` columns | |
| `RentalPriceType` | `mapRentalPriceType()` → `PerMonth\|PerWeek\|PerDay\|PerM2` | legacy "PerSquareMeter" mapped to `PerM2` |
| `SoleMandateExclusiveDays` | `$p->pp_exclusive_days` (agent opt-in ONLY, AT-369) | sent only when `pp_exclusive_days >= 1` AND `MandateType === FullMandate` AND `ListingType === Sale`; capped at 92; else omitted (field stays `0`, PP's own "not requested" value) |

`validate($payload): array` enforces all of the above. `checkReadiness(Property $p): array` returns user-facing missing-field list before submission is even attempted.

### 6a. Agent opt-in PP exclusivity (AT-369, 2026-08-04)

**Design (Johan's ruling):** sole mandates syndicate normally to every selected portal by
default. PP exclusivity is an **opt-in tick per listing** — never derived from dates,
never assumed from mandate type alone, never an agency-mandated blanket mode. While a
listing's exclusive window is open, it must **not** reach Property24 or any other portal.

**History:** before this fix, `PrivatePropertyListingMapper::map()` auto-calculated
`SoleMandateExclusiveDays` from `listed_date`↔`expiry_date` on every sole-mandate Sale
submit — no agent ever chose it, and nothing gated Property24 on the result. See
§6/§17 history and `PpRemediateLegacyExclusiveDays` below for the cleanup.

**Flow:**
1. Agent ticks "Make this listing exclusive to Private Property" on the syndication
   panel (`resources/views/corex/properties/partials/syndication-panel.blade.php`,
   visible only for sole-mandate Sale listings). Ticking opens an info modal
   (the shared `<x-modal>` component — `resources/views/components/modal.blade.php`)
   explaining: PP-only publish while other portals are blocked; the "Only on Private
   Property" label runs the full period while featured placement caps at 7 days; the
   listing must be a newly signed sole mandate not already advertised elsewhere; and
   exclusivity cannot be cancelled within 24 hours of PP creation (PP rejects it).
2. Agent picks 1..agency-max days in the modal and confirms — nothing is sent to the
   server yet. Cancel leaves the tick off; nothing saved.
3. The chosen day count travels to the server on the next Submit/Refresh
   (`syndication-scripts.blade.php` `submitListing()`/`refreshListing()` — both POST
   `pp_exclusive_days` to `/syndication/submit`).
4. `SyndicationController::submit()` validates server-side (never trusts the client
   alone): a value of `0` always clears it; a positive value must be an integer within
   `1..pp_exclusive_days_max` (agency setting, below) AND the listing must be a sole
   mandate Sale — anything else is rejected with a 422, never silently dropped.
5. `PrivatePropertyListingMapper::map()` sends `SoleMandateExclusiveDays` from
   `pp_exclusive_days` under the same FullMandate+Sale condition (§6 table above).
6. PP's response `DelayListingOnOtherWebsitesUntil` is parsed and stored in
   `pp_delay_until` exactly as before (`PrivatePropertySyndicationService.php:156-168` —
   unchanged by AT-369).

**Agency master switch — `pp_exclusivity_enabled` (PerformanceSetting, agency-scoped,
follow-up 2026-08-05):**
- Default enabled (`1`) — existing agencies already using AT-369 (shipped 2026-08-04) see
  no behaviour change; an agency switches this off to remove the "Make this listing
  exclusive to Private Property" tick from every sole-mandate Sale listing's syndication
  panel entirely (the whole section — tick, day-picker modal, P24-blocked-warning modal and
  one-time explainer modal — is gated on it in `syndication-panel.blade.php`).
- **Real gate is server-side**, checked FIRST in `SyndicationController::
  validateAndSaveExclusiveDays()` — ahead of the P24-must-be-off precheck (§6a-i) and the
  sole-mandate-Sale check — because the panel-hiding is not the enforcement, same
  never-trust-the-client doctrine as everywhere else in this feature.
- Turning it off is **not a delist**: a listing already exclusive on PP
  (`pp_delay_until` in the future) keeps gating Property24 exactly as before until the
  window lapses naturally — the switch only stops NEW opt-ins.
- UI: Company Settings → Feature Settings → Properties → "Syndication Portals" card
  (same card as `pp_exclusive_days_max`, same saver — `updateSyndicationPortals()`).
- Onboarding wizard: `config/agency-onboarding-copy.php` `capabilities` step, between
  `syndication_pp_enabled` and `pp_exclusive_days_max` (non-negotiable #10a — this one IS a
  feature on/off switch, unlike the numeric cap below it).
- `tests/Feature/Syndication/PpExclusivityMasterSwitchTest.php`.

**Agency cap — `pp_exclusive_days_max` (PerformanceSetting, agency-scoped):**
- Default 92 (PP's own hard maximum), agency-configurable downward, never below 1 or
  above 92 — enforced server-side in `SettingsController::updateSyndicationPortals()`
  regardless of what the form sends.
- UI: Company Settings → Feature Settings → Properties → "Syndication Portals" card.
- Onboarding wizard: `config/agency-onboarding-copy.php` `capabilities` step, alongside
  `syndication_pp_enabled`.
- **Not** registered in `AgencyFeatureService::SWITCHBOARD_STORES` — that map is
  boolean-only (`enabled()` casts every entry through `(bool) PerformanceSetting::get(...)`),
  and this is a numeric cap on an already-gated feature, not a feature to gate on/off.

**The real P24 gate (server-side, not cosmetic):** `Property::isPpExclusiveActive()`
(`pp_delay_until` set and in the future) is the single source of truth, enforced at
every Property24 entry point. Full detail: `.ai/specs/p24-syndication.md` §"AT-369 — PP
exclusivity gate".

### 6a-i. P24-must-be-off precheck + one-time forced-read explainer (2026-08-05)

**Problem:** agents were ticking "Make this listing exclusive to Private Property" without
reading the existing info modal's bullet list, then discovering only on submit (a PP
rejection, or the existing P24-side `isPpExclusiveLocked()` lock) that Property24 was still
on. The info modal's explanation was easy to click past.

**Fix — two additions, both client-side UX backed by a server-side gate:**

1. **P24-must-be-off precheck.** `onExclusiveToggleClick()` (`syndication-scripts.blade.php`)
   now checks `p24Enabled` (seeded from `$property->p24_syndication_enabled`) BEFORE opening
   any exclusivity modal. If Property24 is switched on for this listing, it opens a dedicated
   warning modal (`pp-exclusive-p24-blocked-{id}`) instead — "Property24 is still switched
   on... turn it off first" — and the tick never proceeds to the day picker. **Real gate is
   server-side**, mirroring the existing P24→PP direction: `SyndicationController::
   validateAndSaveExclusiveDays()` rejects any `pp_exclusive_days > 0` with a 422 when
   `$property->p24_syndication_enabled` is true, checked before the sole-mandate-Sale check.
   Client state is seeded once at page load (not live cross-component reactive) — a stale
   client can still request it, but the server rejects the same way the sole-mandate-Sale and
   agency-max checks already did; this is not a new class of trust boundary.
2. **One-time forced-read explainer.** The first time an agent EVER ticks the exclusivity
   switch (tracked per-user via `users.pp_exclusivity_explainer_seen_at`, never shown again
   once set), a non-dismissible modal (`pp-exclusive-explainer-{id}`, `<x-modal :dismissible="false">`
   — no backdrop-click or Escape close) opens with the same core rules, gated by a **10-second
   minimum read** before "I understand" unlocks (`explainerSecondsLeft` countdown in
   `ppSyndication()`). Clicking it POSTs to `POST /corex/properties/syndication/
   exclusivity-explainer/ack` (self-scoped, mirrors `TourProgressController`'s pattern — a
   user can only ever mark their own acknowledgement) and hands straight on to the normal
   day-picker modal, which still has its own Cancel — acknowledging the explainer commits
   nothing by itself.

**Deliberately NOT the `TourRegistry`/`UserTourProgress` system** (`app/Support/Tours/`):
investigated first per the INVESTIGATE → COPY → ADAPT rule, but that catalogue requires a
`route`+`steps` spotlight-tour shape and is auto-listed on the Guided Tours directory
(`GuidedToursController::index`) — a route-less, steps-less entry would render there as a
broken "0 steps" card with a dead link. A dedicated `users` column + a single-purpose ack
endpoint on the existing `PrivateProperty\SyndicationController` was the correctly-scoped
fit, not a forced reuse of an unrelated subsystem.

**`<x-modal>` change:** added an optional `dismissible` prop (default `true`, so every one of
the shared component's ~40 other usages is unaffected) — `false` disables backdrop-click and
Escape-key close, leaving `close-modal` (the explicit programmatic event) as the only way out.

**Files:** `database/migrations/2026_08_05_090000_add_pp_exclusivity_explainer_seen_at_to_users_table.php`,
`app/Models/User.php`, `app/Http/Controllers/PrivateProperty/SyndicationController.php`,
`routes/web.php`, `resources/views/components/modal.blade.php`,
`resources/views/corex/properties/partials/syndication-panel.blade.php`,
`resources/views/corex/properties/partials/syndication-scripts.blade.php`,
`tests/Feature/Syndication/PpExclusivityBlocksOnP24Test.php`.

**Remediation:** `php artisan pp:remediate-legacy-exclusivity` (`--dry-run` default,
`--live` to act) finds every property with `pp_delay_until` set (PP's own ground truth
for "exclusivity was granted") and, for any still inside the window, clears
`pp_exclusive_days` and resubmits so PP releases it. Skips anything activated on PP
within the last 24 hours (PP rejects the reduction) and reports those separately.
Transaction-per-listing, rolls back the local clear if the resubmit fails. No hard
deletes — the only write is the ordinary `pp_exclusive_days` update + the standard
`submitListing()` path "Refresh" already uses.

### Feature Attributes (added 2026-07-01 — property 6049 fix)

PP's `AttributeType` is a **strict enum of 70 values** (confirmed from the live
production WSDL — full list in `storage/pp-attributetype-enum.txt`, WSDL cached
at `storage/pp-agentimport.wsdl`). Before this fix `buildAttributes()` sent only
the ~8 structural attributes, so **no amenity feature reached PP** — the bug on
property 6049 (features present in CoreX, absent on the PP listing).

`buildAttributes()` now also maps CoreX features to the enum:

- **Room counts** from the structured spaces list (`spaces_json`): `Lounges`,
  `DiningAreas`, `Family_TV_Room`, `Study` (Study+Office), `Parking`, `Carports`,
  `StaffQuarters` (Domestic Room), `Kitchen`, `Entrance_hall`. Emitted only when > 0.
- **Presence flags** from the **global** feature set (`ResolvesPropertyFeatures::globalFeatures()`
  — room-only features never flip a property-level flag) plus matching-space
  presence: `Pool, Garden, Flatlet, Patio, Balcony, Lapa, Scullery, Pantry,
  Guest_Toilet, Laundry, Garden_Cottage, Fireplace, Built_in_Braai, Deck, Storage,
  Borehole, IrrigationSystem, PetsAllowed, Furnished, Aircon, Alarm, Intercom,
  Satelite, TV, SeaView, ScenicView, WalkInCloset, BuiltInCupboards,
  HandicapAvailable, AccessGate, Electric_Fencing, Fence, SecurityPost, TennisCourt,
  SquashCourt, Clubhouse, Gym, Golf, Jaccuzzi, Jetty_Berth, WaterIncluded,
  ElectrictyIncluded`.

Rules (mirror the P24 mapper discipline):

- **Enum spelling is verbatim, incl. PP's own misspellings** — `Satelite`,
  `Jaccuzzi`, `ElectrictyIncluded`. An unrecognised type is rejected by the feed.
- **No guessing.** A CoreX feature with no clean PP attribute (e.g. Armed Response,
  Safe, ADSL/Fibre, 24 Hour Access) is skipped, not mapped to a near-miss.
- **Present-only.** A flag is emitted only when the feature is present; absent
  features send no attribute (so only the "yes" value is ever transmitted).
- **Boolean value = `PrivatePropertyListingMapper::ATTR_PRESENT` (`"Yes"`)** —
  the WSDL types `Value` as a plain string, but PP stores/displays boolean
  amenities as `"Yes"`. CORRECTION (2026-07-02): `"true"` is ACCEPTED by
  UpdateListing (`UpdateListingResult: "Successful"`) but SILENTLY DROPPED — the
  feature never appears on the portal. Confirmed via
  `GetFullDetailsOfAllListingsByBranch`: property 6049 pushed with `"true"` had
  zero amenities stored; re-pushed with `"Yes"`, every amenity (Electric_Fencing,
  Alarm, Fence, Satelite, TV, …) appeared. This was the root cause of "almost no
  features show on PP". Count-type attributes (Bedrooms, EnSuite, Lounges, …) use
  the integer value, NOT `"Yes"`. This constant is the single source of truth.

Feature resolution is shared with the portal layer via the
`App\Services\Syndication\Concerns\ResolvesPropertyFeatures` trait
(`globalFeatures()` / `countSpaces()`), so PP and P24 derive the same feature set.
**Follow-up:** the P24 mapper still keeps its own private copy of this logic and
should adopt the trait (no behaviour change — the trait is a verbatim extraction).

---

## 7. Agent Registration Flow

1. Sidebar / admin trigger → `AgentPpController::sync(User)` or auto on first listing submit (`ensureAgentRegistered`).
2. `PrivatePropertyListingMapper::buildAgentData($user)` emits:
   ```
   AgentId               = (string) $user->id     # OUR external ref
   FirstName/LastName    = split($user->name)
   Email/TelCell/TelWork/TelHome
   Active                = true
   BranchId              = config branch_guid
   PrivatePropertyAgentId = ''   # left blank — PP fills on first call
   ```
3. `SoapClient::updateAgent()` creates-or-updates by `AgentId`.
4. **Quirk:** `UpdateAgent` will *create a new PP profile* if `AgentId` doesn't already exist — this is how the Elize duplicate (AgentId=100, encrypted `lW2pKs8th84=`) was created. To re-map an existing PP profile to a different External Ref use `UpdateUniqueAgentID` (`AgentPpController::updateExternalRef`).

### 8b. Admin UI — Private Property → Agents tab
External Ref (Agent ID) management lives in the PP admin area, **not** on the agent
edit page. Sidebar → System Developer → **PP Agents** opens a three-tab page
(link-based tabs share `admin/pp/_tabs.blade.php`):

| Tab | Route | Content |
|---|---|---|
| **Agents** (default) | `admin.pp.agent-mapping` | Every CoreX agent in the agency (DB read, agency-scoped) with a per-row expandable editor: External Ref, PP Encrypted Agent ID, **Update PP Agent ID**, **Sync Agent to PP**, **Deactivate Agent on PP**. Reuses the per-user endpoints (`admin.users.pp.update-external-ref`, `admin.users.pp.sync`, `corex.properties.syndication.agent.deactivate`). |
| **PP Branch Profiles** | `admin.pp.agents` | The live `GetAllAgentsForBranch` SOAP list (duplicate-profile cleanup). Fires SOAP only when opened. |
| **Mapping Email** | `admin.pp.mapping-email` | Tab-separated copy-paste block for PP's stock-file mapping request. |

The old per-agent Private Property card on `admin/users/{user}/edit` was removed — that
page is now a tabbed Profile / Role & Access / Finance / Compliance / Actions layout.
5. Image upload — `submitAgentImages()` builds `PP_IMAGE_BASE_URL/storage/<path>` from the agent's **JPEG rendition** (see §7a — not `agent_photo_path`, which is WebP), enforces HTTPS + ≤1MB, calls `UpdateAgentImage` with field name **`imgurl`** (lowercase).

PP image spec: minimum 160×120px, max 1MB, **JPG only**. The 1MB check is enforced server-side; the dimension minimum is documented but not validated server-side (would require GD/Imagick) — agents must comply when uploading. `UpdateAgentImage`'s response is a plain SOAP 200 even when PP rejects the image — the real verdict is the `UpdateAgentImageResult` string (`"Successful"` vs. e.g. `"only jpg images are supported"`); `uploadAgentImage()` inspects it explicitly rather than trusting the absence of a `SoapFault` (see §7a).

### 7a. Agent photo format & the JPG-only fix (2026-08-03)

**Root cause found investigating agent #47 (Shalan)**: CoreX normalises every agent photo to WebP (`App\Services\Images\AgentPhotoNormalizer`, `agents/{id}/photo.webp` — see `.ai/specs/agent-photo.md`). PP's `UpdateAgentImage` only accepts JPG and silently rejects everything else with `UpdateAgentImageResult: "only jpg images are supported"` — confirmed on **145/145** historical calls across every agent. `uploadAgentImage()` previously only checked the SOAP transport-level `error` flag (set on a `SoapFault`), never the response body, so every rejection was logged and returned as a success. Established agents still showed a photo only because PP had an old JPG on file from before this became an issue (Feb 2026 agent registrations); Shalan's PP profile was created fresh on 2026-08-03 with nothing to fall back on.

**Fix:**
- `AgentPhotoNormalizer::store()` now also writes a JPEG rendition at `agents/{id}/photo.jpg` (flattened onto white — JPEG has no alpha) alongside the canonical WebP, from the same 1200×1200 square canvas. `ensureJpeg(int $userId)` lazily regenerates it on demand for agents whose photo predates this change (absorb, not a one-off backfill migration) — called from `submitAgentImages()` before every push.
- `submitAgentImages()` builds `imgurl` from the JPEG rendition, not `agent_photo_path`. A legacy `.jpg`/`.jpeg` `agent_photo_path` (pre-normalizer agents) is used as-is.
- `uploadAgentImage()` now extracts `UpdateAgentImageResult` via `extractFromSoapResponse()` and only treats the literal string `"Successful"` as success — anything else (PP's real rejection reason) is returned as a failure with PP's own message.
- `AgentProfilePhotoService::clear()` deletes the JPEG rendition alongside the WebP and its `user_documents` row.

### 7b. Agent identity persistence after registration (2026-08-03)

**Root cause**: neither `registerAgent()` (admin "Sync Agent to PP" button) nor the private `ensureAgentRegistered()` (auto-register on first listing submit — the path Shalan's registration actually took) ever persisted `pp_unique_agent_id`/`pp_external_ref` back onto the `users` row after a successful `UpdateAgent` — `UpdateAgent`'s own response never returns the encrypted id, only `"Successful"`. Only the admin "Update PP Agent ID" flow (`AgentPpController::updateExternalRef`) wrote these columns. An agent registered only via auto-register therefore looked permanently "never synced" to CoreX, risking a duplicate PP profile on the next `ensureNoDuplicateBeforeUpdateAgent` check or manual remap.

**Fix:** both `registerAgent()` and `ensureAgentRegistered()` call a new private `persistPpAgentIdentity(User $user)` after a successful `UpdateAgent` — looks up the encrypted id via `GetAgent` (mirrors `AgentPpController::fetchEncryptedAgentIdFromPp`'s proven candidate extraction) and writes `pp_unique_agent_id`/`pp_external_ref`. Guarded to fire the extra SOAP call only once per agent (skipped once both columns are already correct), so this does not add a `GetAgent` call to every listing submit.

---

## 8. Listing Submission & Activation Flow

```
User clicks Submit
  → SyndicationController::submit
  → PrivatePropertySyndicationService::submitListing
      ├─ mapper->map() + validate()
      ├─ ensureAgentRegistered(primary) + registerAgent(secondary)
      ├─ SoapClient::updateListing
      ├─ on success: pp_syndication_status='submitted',
      │              pp_last_submitted_at=now(),
      │              capture ListingFeedRef → pp_listing_feed_ref,
      │              capture PPRef → pp_ref (+ status='active')
      └─ submitAgentImages() (best-effort)

After success → SyndicationController dispatches PollPrivatePropertyActivation
  with backoff 30/90/300/900/1800s — fills pp_ref via GetListingStatus when PP activates.

In parallel:
  - Schedule (every 15min) → SyncPrivatePropertyActivations (status polling fallback)
  - Schedule (every 15min) → ProcessPrivatePropertyEventFeed (event-driven path)
```

PP returns `ListingFeedRef` (UUID) on the synchronous `UpdateListing` response **only sometimes**. The Event Feed (§10) is the authoritative source.

---

## 9. Video / Matterport Flow

1. Property must be **active** on PP (`pp_listing_feed_ref` populated).
2. `PropertyPpController::video(Property)` validates input, extracts 11-char YouTube id from any URL form.
3. `PrivatePropertySyndicationService::pushVideoOrMatterport()`:
   - Hard guard: returns error if `pp_listing_feed_ref` is empty.
   - Calls `SoapClient::updateListingVideoOrMatterport($pp_listing_feed_ref, $type, $youtube, $matterport)`.
4. **Critical:** `UniqueListingId` = `pp_listing_feed_ref`, never `pp_ref` (T-number).

> **CORRECTION (2026-05-18, verified against live sandbox feed):** `ListingFeedRef`/`pp_listing_feed_ref` is **NOT a UUID/GUID**. PP echoes back the listing reference *we submitted* — our CoreX property id (e.g. `"16"`). The earlier "UUID" claim here and in §10/§15 was wrong and caused the video sync to be wrongly diagnosed as blocked-on-PP. `pp_listing_feed_ref` is populated by the Event Feed `Activated` handler from `ListingFeedRef`.

Manual entry (rarely needed now the feed parser is fixed): `php artisan pp:manage set-listing-uuid --property=ID --uuid=<our-property-id>` writes `pp_listing_feed_ref`.

---

## 10. Listing Event Feed Flow

PP exposes `GetListingEventFeedByBranch(branchId, token, continuationKey, startDateTime)`.

> **CORRECTION (2026-05-18, verified against live sandbox feed):** The real response envelope is `GetListingEventFeedByBranchResult.{ContinuationKey, FeedData}`, and the event list is nested under a **mis-spelled** child element `FeedData.LisitngEventFeedData` ("Lisitng", not "Listing"). Per event: `ListingFeedRef` = the listing ref WE submitted (our CoreX property id, e.g. `"16"`); `OfficeFeedRef` = the **PP branch GUID** (NOT our id). The old pseudocode below (top-level `ContinuationKey`/`FeedData`, and "OfficeFeedRef = our PropertyId") was wrong on all three points and is why the consumer was a silent no-op.

Implementation: `App\Jobs\ProcessPrivatePropertyEventFeed` (scheduled every 15 min, `withoutOverlapping`).

```
loop while moreToProcess:
  $key = PpEventFeedSetting::getValue('continuation_key')
  $start = null
  if empty($key):
      $key = '0'
      $start = now()->subDays(2)->format('Y-m-d\TH:i:s\Z')

  $resp = soapClient->getListingEventFeed($key, $start)
  $newKey = $resp['ContinuationKey']
  if $newKey && $newKey !== $key:
      PpEventFeedSetting::setValue('continuation_key', $newKey)
      processEvents($resp['FeedData'])
  if count(FeedData) < 100: break
```

Event handlers (`processEvents`):
- `Activated` → property matched via **`ListingFeedRef` = our CoreX property id** (`Property::find((int) $feedRef)`): write `pp_ref = EventDescription` (T-number), `pp_listing_feed_ref = ListingFeedRef`, `pp_syndication_status='active'`, `pp_activated_at=now()`.
- `Deactivated` → `pp_syndication_status='deactivated'`.
- `ErrorDownloadingImages` → `pp_syndication_status='error'`, `pp_last_error=EventDescription`, **create a `command_tasks` row assigned to the listing's primary agent** (Command Center pillar).
- `ImagesDownloading`, `ImagesDownloaded` → log only.

State storage: `pp_event_feed_settings` (key/value, single global row keyed `continuation_key`). No `agency_id` — global integration state.

---

## 11. Webhook (Inbound Leads)

Endpoint: `POST /api/pp/webhook` (no auth, no CSRF — Laravel 11 `routes/api.php` ships without CSRF). Handler: `PpWebhookController::receive`.

Flow:
1. **HMAC verify** — `X-Signature` header must equal `base64(hash_hmac('sha256', body, PP_WEBHOOK_SECRET, raw=true))`. Constant-time compare. 401 on mismatch.
2. Decode JSON. Skip unless `messageType === 'Lead'` (PP sends other notifications too).
3. Match property: `Property::find($payload['listingExternalReference'])` (CoreX id we sent on submit).
4. **Lead model:** existing `Contact` model with `contact_type_id` of "Lead" (id=11). Fields:
   - `first_name` / `last_name` ← split `leadName`
   - `phone` ← `leadPhoneNumber`, `email` ← `leadEmail`
   - `notes` ← `leadMessage` plus listing reference
   - `contact_source_id` ← if a "Private Property" source exists, otherwise null
   - `created_by_user_id` ← property's `agent_id` (so it shows in their feed)
5. Link Contact → Property via `contact_property` pivot with `role='lead'`.
6. Create a `command_tasks` row assigned to the property's primary agent — title "New PP lead — {leadName}".
7. Return `200 OK` always (PP retries on non-2xx).
8. Log full payload to `private_property` channel.

Always return 200 even when no matching property — PP must never see a 4xx/5xx for non-signature failures.

PP Admin Portal registration URL: `https://corex.hfcoastal.co.za/api/pp/webhook` (BLOCKED until registered manually).

---

## 12. Routes

| Method | Path | Controller |
|---|---|---|
| GET  | `/admin/pp/agent-mapping` | AgentPpController@agentMapping |
| GET  | `/admin/pp/agents` | AgentPpController@index |
| GET  | `/admin/pp/mapping-email` | AgentPpController@mappingEmail |
| POST | `/admin/users/{user}/pp/sync` | AgentPpController@sync |
| POST | `/admin/users/{user}/pp/update-id` | AgentPpController@updateId |
| POST | `/admin/users/{user}/pp/update-external-ref` | AgentPpController@updateExternalRef |
| POST | `/properties/{property}/syndication/toggle` | SyndicationController@toggle |
| POST | `/properties/{property}/syndication/submit` | SyndicationController@submit |
| POST | `/properties/{property}/syndication/deactivate` | SyndicationController@deactivate |
| POST | `/properties/{property}/syndication/reactivate` | SyndicationController@reactivate |
| POST | `/properties/{property}/syndication/showday` | SyndicationController@showday |
| DELETE | `/properties/{property}/syndication/showday/{showday}` | SyndicationController@deleteShowday |
| POST | `/properties/{property}/syndication/visibility` | SyndicationController@updateVisibility |
| GET  | `/properties/{property}/syndication/status` | SyndicationController@status |
| GET  | `/properties/{property}/syndication/readiness` | SyndicationController@readiness |
| POST | `/properties/syndication/agent/register` | SyndicationController@registerAgent |
| POST | `/properties/syndication/agent/deactivate` | SyndicationController@deactivateAgent |
| POST | `/properties/syndication/agent/image` | SyndicationController@uploadAgentImage |
| POST | `/properties/{property}/syndication/video` | PropertyPpController@video |
| POST | `/properties/{property}/syndication/update-id` | PropertyPpController@updateId |
| POST | `/api/pp/webhook` | PpWebhookController@receive |

---

## 13. Schedules (`routes/console.php`)

| Job | Frequency | Purpose |
|---|---|---|
| `SyncPrivatePropertyActivations` | every 15 min, `withoutOverlapping` | Status-poll fallback (pp_ref backfill) |
| `ProcessPrivatePropertyEventFeed` | every 15 min, `withoutOverlapping`, name `pp-event-feed` | Authoritative event consumer |
| `PollPrivatePropertyActivation` | dispatched per-property after submit; 30/90/300/900/1800s backoff | First-hour fast-path |

---

## 14. CLI — `php artisan pp:manage <action>`

`submit, reactivate, deactivate, status, summary, showday, register-agent, deactivate-agent, agent-image, submit-agent-images, list-agents, list-active, update-agent-id, update-listing-id, add-video, set-listing-uuid, test-webhook`

Plus `php artisan pp:smoke-test` → `GetBranchDetails`.

---

## 15. Known PP Quirks

- **T-number vs listing ref** — PP exposes two listing identifiers: a friendly T-number (e.g. `T2870133`, stored in `pp_ref`) and the listing reference we submitted, which PP echoes back as `ListingFeedRef` = **our CoreX property id** (e.g. `"16"`), stored in `pp_listing_feed_ref`. `UpdateListingVideoOrMatterport` requires the latter (`UniqueListingId` = `pp_listing_feed_ref`) — passing the T-number silently fails / returns no-op. (`ListingFeedRef` is NOT a GUID — earlier spec text was wrong.)
- **Sandbox auto-activation** — PP sandbox does **not** always auto-activate; sometimes `pp_ref` is returned synchronously, sometimes only via the Event Feed.
- **`UpdateAgent` creates duplicates** — calling `UpdateAgent` with an `AgentId` that PP doesn't already have creates a fresh PP profile. To re-point an existing PP profile to a new External Ref use `UpdateUniqueAgentID`.
- **Suburb hierarchy** — `Suburb` must be more specific than `Town` and the two strings must not be identical (case-insensitive). Province is a fixed enum.
- **PhotoUrl must be HTTPS** — localhost / http:// URLs are rejected by PP. Override via `PP_IMAGE_BASE_URL`.
- **Agent image** — field name in WSDL is `imgurl` lowercase. Min 160×120, max 1MB.
- **`SoleMandateExclusiveDays`** — only valid for `FullMandate Sale`, range 1-92. Anything else must be 0. **AT-369 (2026-08-04):** sent from agent opt-in (`pp_exclusive_days`) only — see §6a. Reducing it below 1 within 24 hours of the listing's PP creation is a PP error; `pp:remediate-legacy-exclusivity` skips candidates inside that window for manual handling.

---

## 16. PP Error Codes Handled

The integration treats PP errors as opaque strings stored in `pp_last_error`. Codes encountered during build-out:

| Code | Cause | Mitigation |
|---|---|---|
| PP50  | Auth / digest invalid | Token rebuilt per call; password is digested only |
| PP100 | Required field missing | `validate()` blocks pre-submission |
| PP106 | Suburb/SuburbId conflict | When `pp_suburb_id` is set, `Suburb`/`Town` cleared |
| PP107 | Agent phone missing | `ensureAgentRegistered` blocks pre-submission |
| PP119 | StreetName/StreetNumber invalid | Dedicated `street_name`/`street_number` columns; suspicious-keyword guard |
| PP120 | Image URL not HTTPS / unreachable | `PP_IMAGE_BASE_URL`, http→https rewrite |
| PP121 | Province enum invalid | `mapProvince()` + validate() against fixed set |

---

## 17. Outstanding (BLOCKED on PP)

- **Elize duplicate** — AgentId=100, encrypted `lW2pKs8th84=`. Listings 16 and 34 currently assigned to it on PP. Cannot be deactivated until PP support reassigns. Track at `app/Services/PrivateProperty/PrivatePropertySyndicationService.php` agent-flow.
- ~~**`pp_listing_feed_ref` for T2870133** — null. Video push blocked.~~ **RESOLVED 2026-05-18.** Was NOT blocked on PP — the Event Feed parser was broken (wrong envelope path, mis-spelled `LisitngEventFeedData` child, inverted `ListingFeedRef`/`OfficeFeedRef` roles). Fixed in `ProcessPrivatePropertyEventFeed`. PP has emitted multiple `Activated` events for property 16 (`ListingFeedRef="16"`); the corrected job populates `pp_listing_feed_ref="16"` on the next run for any Active listing.
- **`PP_WEBHOOK_SECRET`** — must be obtained by registering `https://corex.hfcoastal.co.za/api/pp/webhook` in the PP Admin Portal.
- ~~**Sole-mandate exclusive listing test** — outstanding test case (FullMandate Sale, `pp_exclusive_days > 0`).~~ **RESOLVED 2026-08-04 (AT-369).** `pp_exclusive_days` is no longer a dormant column — it is read by the mapper, set via the syndication panel's opt-in + info modal, and validated server-side. See §6a. Live remediation of pre-fix listings (`pp:remediate-legacy-exclusivity --live`) is still outstanding — Johan gives a separate explicit order for that run; the dry-run was proven on QA1 only.

---

## 18. Undocumented subsystems (added 2026-08-01 — Rev 4.6→4.7 investigation)

Two services and their scheduled jobs existed in the codebase but were missing from this
spec until now:

- **`App\Services\PrivateProperty\PpLeadService`** — buyer-enquiry lead ingestion via
  `ListingLeadDetailsFeed`, mirrors `P24LeadService` one-for-one into `portal_leads`
  (`portal='pp'`). Scheduled every 5 min via `App\Jobs\PrivateProperty\PullPpLeadsJob`
  (`routes/console.php`, name `pp-leads-pull`). **Gated per-agency** by
  `agencies.pp_lead_pull_enabled` (default OFF — AT-199). Cursor: `Cache` key
  `pp.leads.cursor.agency.{id}`, 7-day default lookback on first run. Dedup: PP `LeadId`,
  strict.
- **`App\Services\PrivateProperty\PpStatsService`** — nightly per-listing engagement
  snapshot via `ListingPerformanceStats` (Views, Messages, TelLeads, Alerts), upserted into
  `property_portal_metrics` (`portal='pp'`) — the same table P24 writes to,
  portal-discriminated. Scheduled daily 04:30 via
  `App\Jobs\PrivateProperty\PullPpStatsJob` (name `pp-stats-pull`). **Gated per-agency** by
  `agencies.pp_stats_pull_enabled` (default OFF, seeded ON for agency 1/HFC — AT-201). PP
  gives no historical backfill; the series accumulates from switch-on. Read by
  `PropertyIntelligenceService::getPortalPerformance()` /
  `::getPortalEngagementSeries()`, surfaced via `SellerLinkController` (seller-facing link)
  and `Api\V1\ClientSellerInsightsController` (mobile), and the property Intelligence tab
  (`resources/views/corex/properties/intelligence/_portal-leads.blade.php`).

Also present, unaffected by the changes below: `App\Console\Commands\SyncPpLocations`
(`GetCountries/Provinces/Cities/Suburbs` → `pp_provinces`/`pp_cities`/`pp_suburbs`) and
`App\Console\Commands\BulkSyndicatePP` (`pp:bulk-syndicate`, sequential bulk `UpdateListing`
push, mirrors `p24:bulk-syndicate`).

## 19. PP Agency Feed Service Rev 4.6 → 4.7 (SOAP shim reimplementation)

Private Property is re-implementing the Agency Feed Service behind a shim (WSDL contract
unchanged for core flows). Full investigation:
`.ai/investigations/pp-rev47-shim-investigation-2026-08-01.md` (if not present, see chat
history 2026-08-01 — file was not committed as part of the investigation-only pass).
Sandbox not yet available as of this writing; production targeted August 2026.

Full changelog and per-change risk assessment live in the investigation above. Two items
were approved by Johan for immediate, PP-timeline-independent hardening (both are gaps in
**our own** fault-tolerance, not reactions to PP's change, so they don't need to wait on
the sandbox):

### 19.1 `is_whatsapp` derived from `LeadType` (was hardcoded `false`)

**Problem:** `PpLeadService::processLead()` stored PP's `LeadType` string verbatim into
`portal_leads.lead_type` but always wrote `is_whatsapp = false`, unlike
`P24LeadService::processLead()` which derives the flag from the payload. Under Rev 4.7,
`LeadType` explicitly carries `EmailLead` / `WhatsAppLead` — once `pp_lead_pull_enabled`
is on for an agency and PP starts sending `WhatsAppLead`, those leads would be permanently
mis-flagged (missed by any `is_whatsapp=true` filter, missing the "/ WhatsApp" UI suffix in
`resources/views/corex/portal-leads/index.blade.php`).

**Fix:** derive `is_whatsapp` via a case-insensitive `str_contains` check on the resolved
lead-type string (`stripos($leadType, 'whatsapp') !== false`) rather than an exact-match
against `'WhatsAppLead'` — tolerant of PP's exact casing/spelling either side of the
cutover, consistent with the tolerant-mapping posture used everywhere else in this
integration (§C1 of the investigation).

**Pillar:** Contact (no new column — reuses existing `portal_leads.is_whatsapp`, already
present for the P24/website paths).

**Files:** `app/Services/PrivateProperty/PpLeadService.php`,
`tests/Feature/Leads/PpLeadServiceTest.php`.

### 19.2 Event-feed consecutive-failure escalation

**Problem:** `ProcessPrivatePropertyEventFeed::drainFeed()` treats any SOAP fault on
`GetListingEventFeedByBranch` identically: log to the `private_property` channel and
return. No retry within the run, no reset, no distinction between a transient blip and a
persistent failure, and no escalation path — a stuck cursor (exactly what Rev 4.7's
continuationKey invalidation will cause on every branch, once) fails the same way silently
every 15 minutes forever. This job is the **authoritative** source for listing
activations/deactivations/image-error detection (§10) — a silent multi-hour outage here is
a real business impact (agents' listings stop activating on PP, nobody told).

**Fix:** track a per-cursor-key consecutive-failure streak in the existing
`pp_event_feed_settings` key/value store (`PpEventFeedSetting`) — `{cursorKey}:fail_streak`
(count) and `{cursorKey}:fail_since` (ISO timestamp of the first failure in the current
streak). Reset to zero on the next successful call. Once the streak reaches
`FAIL_STREAK_ALERT_THRESHOLD = 3` (≈45 min at the 15-min schedule — long enough to absorb a
transient network blip, short enough that an agent doesn't lose most of a working day
before anyone is told), escalate via the default `Log::critical()` channel (unscoped, not
`private_property` — mirrors `App\Console\Commands\QueueHealthcheck`'s established
"loud detector, no fixer" pattern, so it reaches whatever log-based monitoring already
watches for CRITICAL-level lines) in addition to the existing per-failure
`private_property`-channel error log. This is deliberately the same lightweight
detector shape already used for the queue-worker healthcheck, not the richer
`NotificationDispatcher`/owner-alert pattern from `PermissionLockdownAlarm` (AT-265) — that
richer pattern is scoped to platform-wide security lockdowns; a single-integration
scheduled-job stall is proportionate to the simpler, already-established convention. A
future enhancement to page an owner directly (AT-265-style) is a deliberate deferral, not
an oversight — raise with Johan if the log-only signal proves insufficient in practice.

**Does NOT attempt** to distinguish "stale continuationKey" from any other SOAP fault type
— that requires knowing PP's actual fault text/code for an invalidated key post-Rev-4.7,
which is one of the open questions sent to PP (see the investigation §F.1). This fix only
ensures a persistent fault of *any* kind is no longer silent.

**Pillar:** Property (no new column — reuses `pp_event_feed_settings`, already the
integration's dedicated state store).

**Files:** `app/Jobs/ProcessPrivatePropertyEventFeed.php`,
`tests/Feature/Syndication/PrivatePropertyEventFeedTest.php`.
