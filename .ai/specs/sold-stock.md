# Spec: Sold Stock snapshots + listing cycles

**Status:** In build on QA1 — 2026-10-27 (AT-512). Owner: Johan. Website endpoint consumer: Andre.
**Pillars:** Property (primary), Deal (sale facts), Contact (seller/buyer history), Agent.
**Trigger:** LIVE bug — a property shown as Sold (agency website + sold map) was re-prospected by another
agent; its status left Sold, its website syndication stayed on, and the website showed it **For Sale**.

---

## 1. Root cause (investigated 27 Oct 2026)

| Finding | Where |
|---|---|
| Sold is set by: web `POST /mark-sold` (`routes/web.php` ~5981), the opt-in DR2 milestone listener (`MarkPropertySoldOnDealMilestone`, off unless `agency_deal_sync_settings.sold_milestone` is set), `SoldPropertyImporter`, the auction lot path, and the generic edit form / mobile `PUT` (any status). There is **no** `PropertySold` event, **no** property sold-price/sold-date column, and **no** property status-history table (only `property_audit_log` `status_changed` rows). | `PropertyController::update`, `MobilePropertyController::update`, `AuctionLotStatusService` |
| Deeds capture "same property" calls `PropertyDuplicateTakeService::reassign()` which sets `status = prospecting` + `agent_id` on an **existing** property with no check on its old status (a Sold property with no future expiry is auto-takeable). New deed owners are linked as `owner`; the prior owner/buyer links are never removed and nothing records them as history. | `PropertyDuplicateTakeService.php:27-60`, `DeedsCaptureController` ~1080-1134 |
| `GET /api/v1/website/listings` selects **only** "website pivot row enabled for this key" + `status NOT IN (expired, withdrawn, draft, sold_by_3rd_party)`. It never consults on-market. `sold` is deliberately allowed (sold showcase). | `Api/V1/Website/ListingsController::publicListingsQuery` |
| The observer removes the website pivot only for withdrawn/expired/cancelled/archived/unavailable/sold-by-3rd-party (`isWebsiteRemovalStatus`). **`prospecting`, `not_selling`, `transferred`, `sold` are not in it**, so Sold → Prospecting leaves the pivot enabled and `/listings` keeps emitting the property with `status: prospecting`; a site that treats "not sold" as For Sale shows it For Sale. | `PropertyObserver::isWebsiteRemovalStatus` |
| `Property24ListingMapper::getP24Status` has no arm for `prospecting`/`not_selling`: it falls through to `Active`/`NewListing`, so a P24-enabled property moving Sold → Prospecting is pushed **Active** to P24. | `Property24ListingMapper.php` ~1663-1731 |
| `property_sold_records` already exists (price/date/source) but only the web mark-sold and the importer write it; the DR2 listener and auction path do not, and deal facts live on `deals` (`sale_price`, `sale_date`, `registration_date`, `deal_properties`, `deal_user`, `deal_contacts`). | |
| Nothing promotes the buyer to `owner` / retires the seller when a deal registers. | |

## 2. What this feature does

**A. Sold-stock snapshots (`sold_stock`).** Written automatically the first time either happens for a
sale: the property is marked Sold, or its deal registers. Idempotent per *property + listing cycle*
(a second trigger for the same sale only fills fields the first one could not know — `deal_id`, sale
price/date, buyers — it never creates a second row and never overwrites a filled field). Freezes the
advert: title, description, features, beds/baths/size, address/suburb/city/type, photo **references**
(paths/URLs, nothing re-uploaded), asking price; plus sale price, sale date, days on market, listing
agent(s), seller/buyer contact ids, property/deal/agency/branch. Read-only to users; soft delete only
(Restore exists); a property that sells again later has one snapshot **per cycle** — the full sale history.

**B. Sold turns syndication off.** On Sold: the website pivot rows are disabled (website gets
`listing.removed`), PP is already de-listed by the existing job, P24 keeps the listing as **Sold** until
`portal_sold_until` = sale date + the agency setting **"Keep showing on portals as Sold for X days"**
(`PerformanceSetting sold_portal_show_days`, default **14**, 0 = take down at once), then a daily command
(`sold-stock:expire-portals`) withdraws it and clears `p24_syndication_enabled`. The website/map **Sold
showcase reads `sold_stock` only** — a status change on the property can never make a sold sale vanish
or reappear as For Sale.

**C. For-sale feeds can only emit on-market stock.** `GET /api/v1/website/listings` (and `{id}`) adds a
query-layer guard `onMarket()` + not Other Agency Stock, on top of the existing pivot + never-public
list, so Prospecting/Sold/Not selling/Withdrawn/anything off-market can never leak whatever the switches
say. The P24 mapper maps `prospecting`/`not_selling` to `Withdrawn`, and the observer's website-removal
rule now includes `sold`, `prospecting`, `not_selling`, `transferred`.

**D. Listing cycles.** `properties.listing_cycle` (default 1). When a Sold property goes to
**Prospecting** (deeds capture take, promote, or manual edit), a new cycle starts on the **same record**:
`listing_cycle`+1, status Prospecting, all syndication off immediately (P24 withdrawn, website pivot
disabled, grace window cleared), and the previous cycle is closed into `property_listing_cycles`
(who/when/why it started and ended, prior owners/buyers/agent, link to the snapshot). The contact links
of the finished cycle are frozen into `property_contact_role_history` (contact, role, cycle); the prior
**seller-side** links (owner/seller/co_seller/landlord) are unlinked from the live property (history
kept); the prior **buyer** stays linked and is **suggested as the new seller** — shown on the property
page as a banner the agent can **Confirm** (buyer → `owner`, role changes, history kept) or **Not them**
(dismiss; the agent or a deeds capture links the real owner). The one-role-per-contact-per-property
rule is unchanged (`ContactPropertyLinker` re-roles the single row).

**E. Sold stock screen** (Real Estate ▸ Properties ▸ **Sold stock** tab, `/corex/sold-stock`).
**F. Backfill** `sold-stock:backfill`.

## 3. Data model (migration `2026_12_27_100000_create_sold_stock_tables`)

* `sold_stock` — `id`, `agency_id`, `branch_id`, `property_id`, `deal_id?`, `cycle_no`, `title`,
  `description`, `features` json, `beds`, `baths`, `size_m2`, `address`, `suburb`, `city`,
  `property_type`, `listing_type`, `asking_price`, `sale_price?`, `sale_date?`, `listed_date?`,
  `days_on_market?`, `agent_id?`, `second_agent_id?`, `agent_ids` json, `seller_contact_ids` json,
  `buyer_contact_ids` json, `photos` json, `source` (`status`|`deal`|`backfill`|`import`),
  `snapshotted_by_user_id?`, timestamps, **soft deletes**. `UNIQUE(property_id, cycle_no)`; indexes
  `(agency_id, sale_date)`, `(agency_id, suburb)`, `(agency_id, branch_id)`, `deal_id`.
* `property_listing_cycles` — `id`, `agency_id`, `property_id`, `cycle_no`, `started_at`,
  `start_reason` (`initial`|`deeds`|`promote`|`manual`|`backfill`), `started_by_user_id?`,
  `status_at_start`, `ended_at?`, `end_reason?`, `sold_stock_id?`, `previous_owner_contact_ids` json,
  `previous_buyer_contact_ids` json, `previous_agent_id?`, `suggested_seller_contact_id?`,
  `suggestion_state?` (`pending`|`confirmed`|`dismissed`), timestamps. `UNIQUE(property_id, cycle_no)`.
  This is the **status history by cycle**.
* `property_contact_role_history` — `id`, `agency_id`, `property_id`, `contact_id`, `role`, `cycle_no`,
  `ended_reason`, `ended_at`, timestamps (soft-delete free: append-only).
* `properties`: `listing_cycle` smallint default 1, `portal_sold_until` date null.

## 4. Settings, Role Manager, navigation

* **Setting:** `sold_portal_show_days` (PerformanceSetting, per agency, default 14, 0–365). Settings page
  ▸ Features ▸ Syndication portals form (same form/saver as `pp_exclusive_days_max`, guarded by
  `$request->has()`), and the **Setup Wizard** Capabilities step (`config/agency-onboarding-copy.php`,
  non-negotiable 10a). The settings page also carries a link to the Sold stock screen.
* **Role Manager:** `access_sold_stock` (access, section `properties`) — default-granted wherever
  `access_properties` is granted. Data scope reuses the Properties data scope (own / branch / agency).
* **Navigation:** new tab **Sold stock** in `PropertiesTabs` (hub tab = sidebar entry, same day).

## 5. Sold stock screen (list + detail)

* **List columns:** address, suburb, sold price, sale date, agent, days on market, branch (when the
  agency splits branches), cycle.
* **Search (named fields):** address, suburb, title, agent name. **Sort:** sale date (default **desc**),
  sold price, suburb, days on market, address. **Filters:** period (from/to sale date, with presets
  Last 30 / 90 days / This year), agent, suburb, branch. **Pagination** 25. Real empty state.
* **Scoping at the query layer:** `SoldStock::scopeVisibleTo($user)` — own = the user's listing /
  second agent (data identities), branch = user's branch, agency = all of the agency; `AgencyScope`
  always. Direct-URL access to another agency's/branch's/agent's snapshot is a 404/403.
* **Detail:** the frozen advert read-only (photos, description, features, sale facts, history of earlier
  sales of the same property). **Archive** (soft delete) and **Restore** (`access_sold_stock` +
  `properties.archive`); no edit.

## 6. Website API for Andre (same auth as every `/api/v1/website/*` route)

`GET /api/v1/website/sold` — `auth:agency-api`, `website.live`, `website.scope:listings:read`,
`throttle:website-api`; route name `v1.website.sold.index`. Query: `per_page` (1-50, default 20),
`page`, `suburb`, `since` (sale date >=), `until`, `within_days` (sale date within N days), `sort`
(`-sale_date` default, `sale_date`, `-sale_price`, `sale_price`). Response (`data[]`): `id`, `property_id`,
`property_ref` (slug/ref of the property), `cycle`, `title`, `description`, `features`, `property_type`,
`address` (street hidden per the property's address-privacy flags — suburb + city only when hidden),
`suburb`, `city`, `beds`, `baths`, `size_m2`, `asking_price`, `sale_price`, `sold_at` (date),
`days_on_market`, `images[]` (the frozen references), `agents[]` (`name`, `phone`, `photo`),
plus Laravel `links`/`meta`. Read-only, from `sold_stock` only; it never reads `properties.status`.
**`GET /listings` no longer returns Sold stock** — the sold showcase moves to `/sold`. Webhooks:
`listing.removed` fires when a sold sale leaves the for-sale feed; refresh `/sold` then.

## 7. Backfill

`php artisan sold-stock:backfill {--agency=} {--dry-run}` — snapshots every property currently
`sold` (excluding `sold_by_3rd_party`) and every registered (R) DR2 deal whose property has no snapshot,
switches the website pivot off on Sold properties **in the database** (no external call), prints counts
(snapshots created / already present / deals / website switched off / portal listings still to take down /
skipped). **Portals:** the Property24 / Private Property take-down jobs and the website `removed` webhooks are
dispatched **only with `--portals`** — the production run (Andre). Without it the report says how many portal
listings are still to take down. Reason: a non-production copy of live data (QA1) carries the real portal
credentials, and a mass take-down from there would hit the real portals. P24 stays up as Sold until the grace date
(sale date + the agency setting); `sold-stock:expire-portals` (daily, production scheduler only) then withdraws it.
A historic sale with no deal or sold record is dated from the audit-log moment it became Sold (else the property's last update), never "today". Re-prospected / Not-selling properties whose website switch is still on are switched off too (the bug's victims). Idempotent — safe to re-run.

## 8. Not in scope / follow-ups

* The internal Map "our sold stock" layer still reads `properties.status = 'sold'` + `property_sold_records`.
* `WebsiteSyndicationService::bulkActivateSold()` ("Push all Sold listings") is now inert for the feed
  (sold is not emitted by `/listings`); it should be retired when Andre's site switches to `/sold`.
* Retiring `DealRegistered`→`CloseProperty` gap (no such event exists; the milestone listener is the live mechanism).
* **AT-509 note:** this work does not touch `MarketingReadinessService::statusFor()`.

## 9. Acceptance

Snapshot on Sold; snapshot on deal registration without double rows; Sold → Prospecting starts cycle 2 and
nothing about it reaches `/listings` or P24 Active; sold sale still on `/sold` afterwards; grace window
respected; backfill idempotent; list scoping own/branch/agency; wizard + settings carry the setting.
