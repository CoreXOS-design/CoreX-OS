# Atlas — Contacts (the Contact pillar)

> **Status: DONE** · Last verified: 2026-06-22
> Pillar: **Contact** — the other half of the buyer/property matching equation. A `Contact` with
> `is_buyer` + a countable `ContactMatch` (wishlist) feeds the MIC engines and the Buyer Pipeline.
> Companion specs: `.ai/specs/contacts.md`, `.ai/specs/contact-communication-status.md`,
> `.ai/specs/contact-consent.md`, `.ai/specs/client-auth.md`. Cited: AT-59 (comms tiles), AT-60/61
> (structured address), AT-50 (comm status), AT-79 (display_email — actually on User, see §9).

---

## 1. WHAT IT DOES

A `Contact` is any person CoreX tracks — buyer, seller, owner, tenant, landlord. It is the per-agency CRM
record (multi-tenant via `ContactScope` + `BelongsToAgency`). Contacts carry identity, residential and
**structured property** addresses, FICA/consent state, a derived POPIA communication status, and links to
properties (pivot with role), wishlists (`ContactMatch`), deals, documents, and communications. A buyer
contact is the input to all matching; a seller/owner contact links to a property and presentation.

---

## 2. ENTRY POINTS

### Routes (`routes/web.php`) — group `corex.contacts.*` at `:2372` (mw `permission:access_contacts`, `agency.required`)
| Route | Method | Handler | Notes |
|-------|--------|---------|-------|
| `/` `:2373` | GET | `index` | single-page list (create/edit via inline modals) |
| `/` `:2374` | POST | `store` | create |
| `/check-duplicate` `:2375` | POST | `checkDuplicate` | dedup guard |
| `/{contact}` `:2379` | GET | `show` | detail (LogsContactAccess:view) |
| `/{contact}` `:2380` | PUT | `update` | edit |
| `/{contact}/property-address` `:2381` | PUT | `updatePropertyAddress` | **AT-60 structured address** |
| `/{contact}/property-address` `:2382` | DELETE | `clearPropertyAddress` | AT-61 clear |
| `/{contact}` `:2383` | DELETE | `destroy` | soft delete |
| consent record/revoke `:2387-2388` | — | `recordConsent`/`revokeConsent` | per-channel consent |
| properties search/link/unlink `:2406-2408` | — | `ContactPropertyController` | pivot with role |
| core matches (wishlist) `:2410-2417` | — | `ContactMatchController` | store/update/setStatus/convertToDeal |
| client app login `:2420-2423` | — | `Contacts\ClientLoginController` | portal credential (see §5) |

*(No GET `create`/`edit` form route — UI is `index` + `show` with inline modals.)* Controller
`app/Http/Controllers/CoreX/ContactController.php`: `index` `:19`, `show` `:100` (eager-loads
`properties,matches.createdBy,tags,communications` `:114`), `checkDuplicate` `:318`, `store` `:371`,
`update` `:493`, `updatePropertyAddress` `:584`, `clearPropertyAddress` `:660`, `destroy` `:741`,
`recordConsent` `:748`, `revokeConsent` `:767`, `syncTags` `:870`.

### Blade (`resources/views/corex/contacts/show.blade.php`)
Tabs (bar `:185-187`): info `:233`, **"Properties & Core Matches"** `:204` (panel `:708`), notes `:996`,
drive `:1190`, fica `:1274`, consent `:1461`, matches (merged under properties `:1553`), viewings `:1703`,
**communications (AT-59) `:1817`**, outreach `:1870`.
- **Comms tiles (AT-59):** WhatsApp tile `:331` (`waCount` `:341`), Email tile `:348` (`emailCount` `:358`,
  "N in archive →" link `:360-363`), backed by `Contact::outboundCommCount()`.
- **Structured-address form (AT-60):** "Start a Property from an Address" `:823-871`; Alpine
  `contactAddress()` seeded `:824-834`; POSTs to `corex.contacts.property-address.update` `:838`;
  "Use for property" link `:864` shown only when `hasStructuredAddress()` `:863`.

---

## 3. THE FLOW — create / edit / structured address

`store()` (`ContactController.php:371`) and `update()` (`:493`) persist identity + structured address +
buyer flags. The **structured property address** (AT-60) is edited separately via `updatePropertyAddress`
(`:584`) and is **completely independent of the residential `address`** column (`Contact.php:38-39`,
`263-268`). When a property is created with `?contact_id=`, the Property `create()` GET copies the
contact's structured columns onto the new property field-for-field (`PropertyController.php:393-412`) —
**one-way contact → property only** (see §9, and `properties.md` §3).

Wishlist creation (`ContactMatchController::store`) inserts a `ContactMatch`; if countable, the
`ContactMatchObserver::created()` auto-lands the buyer on the pipeline (see `buyer-pipeline.md`).

---

## 4. DATA IT READS / WRITES — `app/Models/Contact.php`

Traits: `SoftDeletes, BelongsToAgency, BelongsToBranch` `:19`; global `ContactScope` `:23`. Fillable `:26-55`.

| Group | Columns | Line |
|-------|---------|------|
| Identity | `first_name`, `last_name`, `phone`, `email`, free-text `address` | `:36-37` |
| SA-ID | `id_number`, `id_number_captured_at`, `id_number_source` | `:37` |
| **Structured property address (AT-60)** | `unit_number`, `floor_number`, `unit_section_block`, `complex_name`, `street_number`, `street_name`, `suburb`, `city`, `province` + `p24_province_id`/`p24_city_id`/`p24_suburb_id` | `:40-42` |
| Buyer | `is_buyer` (bool `:64`), `buyer_state`, `last_activity_at`, `buyer_pipeline_entered_at`, `buyer_pipeline_notes` | `:49-50` |
| Preapproval | `preapproval_amount` (decimal:2 `:67`), `preapproval_expires_at`, `preapproval_institution` | `:51` |
| Per-channel opt-out | `opt_out_email/sms/whatsapp/call`, `last_consent_check_at` | `:47-48` |
| **POPIA messaging triplets** | opt-out: `messaging_opt_out_at/_reason/_recorded_by_user_id/_source`, `messaging_all_blocked`; opt-in: `messaging_opted_in_at/_reason/_recorded_by_user_id` | `:52-54` |
| Comms counters | `whatsapp_count`, `email_count`, `last_contacted_at` | `:43-44` |

**No `is_seller` column** — `is_buyer` is the only role boolean; seller/owner/tenant roles come from
`contact_type_id` (`ContactType`, `type()` `:91`) and the per-property `contact_property.role` pivot.

**Relationships:** `tags` `:101`, `agent`/`secondAgent`/`createdBy` `:107-122`, `clientUser` (belongsTo
`client_user_id` `:124`, `hasClientLogin()` `:129`), `documents` (withPivot party_role `:150`),
`signedDocuments`/`ficaDocuments` `:161/175`, `ficaSubmissions` `:185`, **`matches` (hasMany ContactMatch =
wishlist) `:227`**, **`properties` (belongsToMany `contact_property`, withPivot `role`) `:246`**,
`consentRecords` `:350`, `communications` (morphToMany `communication_links`, AT-59 `:700`).
Helpers: `hasStructuredAddress()` `:274`, `composeStructuredAddress()` `:294` (never writes residential
`address`), `hasCountableWishlist()` `:241` (AT-74), `outboundCommCount()` `:721`, `hasValidPreapproval()` `:79`.

### Wishlist — `app/Models/ContactMatch.php`
`SoftDeletes, BelongsToAgency` `:19`; statuses active/paused/fulfilled/expired `:21-24`. Fillable `:26-62`:
`price_min/max`, `beds_min`/`bedrooms_max`, `baths_min`, `garages_min`/`parking_min`,
`floor_size_min/max`, `erf_size_min/max`, `p24_suburb_ids[]`+derived `suburbs[]`, **`must_have_features`/
`nice_to_have_features`/`deal_breakers` (arrays)**, `listing_type`, `category`,
`property_type`/`property_types[]`, `share_token`/`share_slug`, `is_primary`. Countability (AT-71):
`presentCriteriaGroups()` `:354`, `isCountable()` `:377`, `scopeCountable()` `:398`, `countableGroupSql()`
`:442` (PHP + SQL mirror — must stay in lock-step). Bar from `AgencyContactSettings::minCountableFor()`.

### Communication status (POPIA three-state) — DERIVED, never stored
`Contact.php:561-633`. Constants `:563-566`. `communicationStatus()` `:583`:
- `opted_in` when `messaging_opt_out_at === null`;
- `transaction_only` when opted-out AND `TransactionStateService::isInLiveTransaction()` `:590-593` (the
  live-sale lock outranks stop-all);
- `all_blocked` when opted-out, no live sale, `messaging_all_blocked` `:596`;
- `marketing_opted_out` otherwise `:600`.
Badge `communicationStatusMeta()` `:609`. Per-channel `canSendVia()` `:487`, denormalised via
`recomputeChannelConsent()` `:516`. (Full consent engine in `compliance.md`.)

---

## 5. CLIENT PORTAL LOGIN vs CONTACT

`app/Models/ClientUser.php` is a separate `Authenticatable` (Sanctum) `:20` keyed by globally-unique email;
`contacts()` hasMany `:52` (one ClientUser ↔ many Contacts across agencies). Contact = per-agency CRM
record; ClientUser = cross-agency portal credential, linked via `Contact::clientUser()`
(`client_user_id` `:124`). `Contacts\ClientLoginController::create` `:22` reuses an existing ClientUser by
email (`:34-36`) or creates one (`:42`) and stamps `contacts.client_user_id` (`:50`). Spec
`.ai/specs/client-auth.md`.

---

## 6. AFFECTS DOWNSTREAM (what reads Contacts)

- **MIC scoring** — `PropertyMatchScoringService` reads ContactMatch + `is_buyer` + countable gate
  (`getBuyerDemandForProperty()` joins `c.is_buyer=1` `:238,252,276`); `MatchingService` scores
  ContactMatches (`market-intelligence.md`).
- **Buyer Pipeline** — `BuyerStateService` reads/writes `buyer_state`, flips `is_buyer` on activity
  (`buyer-pipeline.md`).
- **Presentations buyer-demand** — `AnalysisDataService` synthetic-ContactMatch adapter `:911`,
  absorption/demand `:1292,1343`.
- **e-Sign recipients** — `RoleBlockExpansionService::resolveContact()` `:1751`
  (`Contact::find($recipient->contact_id)` `:1756`); contact→party field mapping `:58-65,1465-1469`.
- **Compliance** — consent records, FICA submissions, marketing suppression all key off the contact
  (`compliance.md`).
- **Deals v2** — `deal_v2_contacts` links real buyer/seller contacts (`deals-commission.md`).

---

## 7. AFFECTED BY UPSTREAM

Contacts are mostly a source, but: residential vs structured address are separate inputs; FICA/consent
state is written by the compliance flows; `last_activity_at`/`is_buyer`/`buyer_state` are written by
`BuyerStateService` (cross-feature). The client portal credential is provisioned by `ClientLoginController`.

---

## 8. AGENCY SETTINGS / CONFIG — `app/Models/AgencyContactSettings.php`

| Setting | Default | Reader |
|---------|---------|--------|
| `buyer_warm_days` / `buyer_cold_days` / `buyer_lost_days` | 14 / 30 / 60 | `BuyerStateService` (`buyer-pipeline.md`) |
| `address_match_mode` (off/standard/strict) | `standard` | create-from-contact dup guard (`ContactAddressPropertyGuard.php:76`) |
| `duplicate_mode` / `duplicate_match_fields` | — | `checkDuplicate` |
| `min_countable_criteria` (AT-71) | `['any']` | `ContactMatch::isCountable` (`:380,414`) |
| `mic_match_threshold` / `mic_price_band_pct` | 75 / 10 | MIC (`market-intelligence.md`) |
| `contact_retention_years` / `consent_retention_years` / `access_log_retention_years` | 5 / 5 / 5 | `PurgeContactRetention` cron (`compliance.md`) |
| `buyer_pipeline_default_scope` | `own` | pipeline scope (`buyer-pipeline.md`) |

Defaults in `forAgency()` `:69-90`.

---

## 9. KNOWN FRAGILITIES

1. **AT-60 transfer is one-way contact→property prefill only.** Property create reads `?contact_id=` and
   copies the contact's structured columns onto the new Property (`PropertyController.php:393-412`).
   Nothing copies property→contact. The structured address is also entirely separate from residential
   `address` (`Contact.php:38-39,263-268`) — a historical pre-AT-60 bug read non-existent
   `$contact->suburb/city` and silently no-oped (`PropertyController.php:398-400`).
2. **Comm status is DERIVED, never stored (`Contact.php:583`).** Adding a state means editing both
   `communicationStatus()` and `communicationStatusMeta()` `:609`. `transaction_only` runs a live-sale
   query (`TransactionStateService`) **only when opted-out** `:589-594` — so its correctness depends on
   that service's live-status set (see `compliance.md` §8). Per-channel `opt_out_*` are denormalised and
   must be kept in sync via `recomputeChannelConsent()` `:516`.
3. **AT-79 `display_email` is on `User`, not Contact.** The agent's outward email
   (`User::outwardEmail()` `User.php:144-149`, `display_email ?? email`; migration
   `2026_06_21_180000`). Easy to misattribute to Contact — there is **no** `display_email` on contacts.
4. **`is_buyer` is the sole role boolean; no `is_seller`.** "Is this a seller?" is not a single column
   read — it lives in `ContactType` + `contact_property.role`.
5. **Countability bar is dual-source (PHP + SQL).** `presentCriteriaGroups()` `:354` and
   `countableGroupSql()` `:442` must stay in lock-step or web counts disagree with engine counts.
6. **Must-have-contact invariant on properties.** A property needs ≥1 linked contact at creation
   (`PropertyController.php:541-555`, 422 otherwise) — the `contact_property` pivot (with `role`) is the link.
7. **SoftDeletes everywhere** (Contact/ContactMatch/ClientUser). Consumers that use
   `withoutGlobalScopes()` (e.g. `PropertyMatchScoringService.php:386,457,520`) must re-add
   `whereNull('deleted_at')` manually (done at `:462`).

---

## Key file:line index
- `app/Models/Contact.php` — `:19,23` traits/scope, `:26-55` fillable, `:227` matches, `:246` properties,
  `:274-294` address helpers, `:487-633` consent/comm-status.
- `app/Models/ContactMatch.php` — `:26-62` fillable, `:354-462` countability.
- `app/Http/Controllers/CoreX/ContactController.php` — `:100,371,493,584,748`.
- `app/Models/ClientUser.php` `:20,52`; `app/Models/AgencyContactSettings.php` `:69-90`.
- `resources/views/corex/contacts/show.blade.php` — `:185-187` tabs, `:331-363` comms tiles, `:823-871` structured address.
