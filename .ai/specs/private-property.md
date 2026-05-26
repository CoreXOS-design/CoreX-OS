# Private Property (PP) Syndication — Spec
> Living reference for the Private Property integration. Reflects the
> ACTUAL current implementation, not an idealised target.
> Last updated: 2026-04-28

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
| `Attributes` | `Bedrooms,Bathrooms,Garages,FloorArea,LandArea,HomeType\|BusinessType\|FarmType\|LandType,Rates,Levies` | category-specific type attribute |
| `HideStreetName/No/ComplexName/UnitNumber` | bool, `pp_hide_*` columns | |
| `RentalPriceType` | `mapRentalPriceType()` → `PerMonth\|PerWeek\|PerDay\|PerM2` | legacy "PerSquareMeter" mapped to `PerM2` |
| `SoleMandateExclusiveDays` | derived from listed_date↔expiry_date for FullMandate Sale | 1-92 only; else 0 |

`validate($payload): array` enforces all of the above. `checkReadiness(Property $p): array` returns user-facing missing-field list before submission is even attempted.

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
5. Image upload — `submitAgentImages()` reads `User::agent_photo_path`, builds `PP_IMAGE_BASE_URL/storage/<path>`, enforces HTTPS + ≤1MB, calls `UpdateAgentImage` with field name **`imgurl`** (lowercase).

PP image spec: minimum 160×120px, max 1MB. The 1MB check is enforced server-side; the dimension minimum is documented but not validated server-side (would require GD/Imagick) — agents must comply when uploading.

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
- **`SoleMandateExclusiveDays`** — only valid for `FullMandate Sale`, range 1-92. Anything else must be 0.

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
- **Sole-mandate exclusive listing test** — outstanding test case (FullMandate Sale, `pp_exclusive_days > 0`).
