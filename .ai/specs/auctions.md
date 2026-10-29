# Auctions — Spec (REBUILD, v2)

> Status: **APPROVED by Johan 2026-10-29. All four phases BUILT on QA2 (not yet Johan-tested, not promoted). Phase 2 of the *product* (fees, deposits, invoices, Direct sales, analytics) is not started.**
> Supersedes: the AT-432 auctions spec and `auctions-advertising-mode.md` (both retired 2026-10-29; the old module was removed from QA2 in commit `0983bc5f2`).
> Drafted: 2026-10-29 · Author: Claude (conductor lane) for Johan
> Lane: QA2 first. Johan's go gates Staging; nothing reaches Live without Johan's specific order.
> Reference model: BidPro-style auction platform (Johan's brief, 2026-10-29).
> Related: `client-auth.md`, `agency-public-api.md`, `corex-domain-events-spec.md`, `multi-tenancy.md`, `agency-onboarding-setup.md`, `BUILD_STANDARD.md` §1a.

---

## 1. What this is and why

An agency can run auctions end to end inside CoreX:

1. **Stock goes on auction.** A property marked as auction gets an **Auction tab** (when bidding opens and closes, opening bid, reserve) and an **auction document pack** in its Drive.
2. **A public auction site** — like a small website for the agency's auctions — shows upcoming and open auctions and lots. Buyers **must register (sign up) and log in** before they can see the gated documents, register for an auction, or bid. Anyone can send an **enquiry**.
3. **Staff run it** from CoreX: create auctions, build lots, see every enquiry, approve bidders, watch every bid, and record the result.
4. **After the hammer**, the lot is marked sold / sold subject to confirmation / passed in, and a Deal is opened.

**Out of v1** (decided 2026-10-29): money handling after the sale (deposits, buyer's commission, fee invoices, guarantees, bidder invoices), Direct-sales format, mailing list, full turnover analytics. The data model leaves room for them (§4.9). They are phase 2.

## 2. Decisions locked (Johan, 2026-10-29)

| # | Decision |
|---|---|
| D1 | Remove the old auction module entirely; rebuild from this spec. Old data is **backed up before any table is dropped** (Live backup/drop needs Johan's separate order). |
| D2 | v1 formats: **Timed online** and **Live** (auctioneer-run, with online bidders). Direct sales later. |
| D3 | v1 stops at **sold + results**. No deposit/commission/invoice handling. |
| D4 | **Multi-agency always.** The public site, wording, branding, documents and defaults belong to the agency, never to HFC (NN #9). |

## 3. Pillars

| Pillar | Reads | Writes back |
|---|---|---|
| **Property** | address, photos, price, agent, mandate, Drive files | `sale_method='auction'`, status `on_auction`, sold status/price on hammer, auction doc flags on Drive files |
| **Contact** | buyer identity (via the client login) | enquiries and bidder registrations create/attach a **Contact** (match-or-create) so every buyer is a node in the graph; sold lot links the winning contact |
| **Deal** | — | `AuctionLotSold` opens a Deal for the listing agent (sync listener, idempotent) |
| **Agent** | listing agent, branch | enquiries route to the lot's listing agent; bidder approvals logged against the approving user |

Cross-pillar reactions use domain events only (NN #9) — §10.

## 4. Data model

> **Phase 1 build notes (2026-10-30).** Old tables were **renamed** to `legacy_*` (not dropped) by `2026_10_30_090000_retire_legacy_auction_tables`; nothing is dropped until Johan orders the Live backup. New FKs use explicit `au2_` names (constraint names are unique per schema). `Auction` also uses `BelongsToBranch` (Split Branches isolation). Phase 1 created: `auctions`, `auction_increments`, `auction_documents`, `auction_lots`, `agency_auction_settings`, `property_files.auction_visibility`. Permission keys built so far: `access_auctions`, `auctions.view|create|edit|archive|publish|lots.manage|reserve.view|reserve.edit|manage_settings`; the rest land with their phase. For adverts/feeds a lot's sale moment is the auction start (live) or the lot's closing time (timed) — `AuctionLot::advertisedDate()`.

All tables: `agency_id` + `BelongsToAgency` (AgencyScope), `branch_id` where staff scoping needs it, **SoftDeletes** (NN #1 — retract/cancel/archive, never delete). All money `decimal(14,2)`, ZAR by default but a currency column is stored on the auction. The old tables of the same names are dropped by the first migration **only after** the Live backup order (§12 step 0).

### 4.1 `auctions`
`reference` (unique per agency, auto e.g. `AUC-2026-0001` from agency prefix setting), `title`, `description` (rich text), `cover_image_path`, `format` (`timed`|`live`), `type` (default `real_estate`; agency list), `currency` (default agency currency), `status`, `starts_at`, `ends_at`, `registration_closes_at` (nullable), `venue_name`, `venue_address`, `lat`, `lng`, `stream_url` (live streaming link), `soft_close_minutes` (nullable → falls back to agency setting), `published_at`, `cancelled_at`, `cancel_reason`, `created_by`, `branch_id`.

**Status flow:** `draft → published → open → closed → completed`; `cancelled` from any pre-`completed` state. `published` = visible on the public site; `open` = bidding live (timed: automatic at `starts_at`; live: when the auctioneer opens the room); `closed` = last lot ended; `completed` = all lots have a result and staff pressed Complete.

### 4.2 `auction_increments`
`auction_id`, `from_amount`, `increment` — the bid ladder (e.g. up to R1m → R10 000; above → R25 000). Seeded from the agency default ladder on create; editable per auction.

### 4.3 `auction_documents` (auction-level)
`auction_id`, `kind` (`conditions_of_sale`|`bidder_registration_form`|`offer_to_purchase`|`commission_breakdown`|`other`), `name`, `path`, `visibility` (`public`|`registered`), `sort_order`. The Conditions of Sale and Registration Form are normally `public`; the rest default `registered`.

### 4.4 `auction_lots`
`auction_id`, `property_id` (one open lot per property at a time — enforced), `lot_number` (unique per auction), `title` (defaults to property title), `reference`, `external_ref`, `description` (defaults to property description), `opens_at`, `closes_at` (default from the auction; this is the window the **property Auction tab** edits), `opening_bid`, `reserve_price` (staff-only; never serialised to the public), `guide_price_min/max` (optional, public), `status`, `winning_bid_id`, `hammer_price`, `result_at`, `confirmation_deadline`, `result_note`.

**Lot status:** `draft → published → open → sold | sold_subject_to_confirmation | passed_in | withdrawn`.
- Highest valid bid ≥ reserve → `sold`.
- Highest valid bid < reserve → `sold_subject_to_confirmation` (seller decides: confirm → `sold`, decline → `passed_in`).
- No valid bid → `passed_in`.

### 4.5 `auction_registrations` (a buyer registered for ONE auction)
`auction_id`, `client_user_id`, `contact_id`, `bidder_type` (`individual`|`company`), `company_name`, `company_reg_no`, `bidder_number` (paddle, unique per auction, issued on approval), `status` (`pending`|`approved`|`declined`|`withdrawn`), `declined_reason`, `approved_by`, `approved_at`, `accepted_conditions_at`, `accepted_conditions_ip`.

### 4.6 Bidder documents
- `bidder_documents` — **per buyer login**, not per auction: `client_user_id`, `kind` (`id_or_passport`|`proof_of_address`|`company_resolution`|`other_fica`), `path`, `expires_at`. This is the "use existing if already on file" behaviour.
- `auction_registration_documents` — `registration_id`, `bidder_document_id`, `status` (`submitted`|`accepted`|`rejected`), `reviewed_by`, `reject_reason`. Required kinds per bidder type come from agency settings (§7).

### 4.7 `auction_bids`
`auction_id`, `lot_id`, `registration_id`, `amount`, `placed_at`, `source` (`online`|`auto_bid`|`floor`), `status` (`valid`|`retracted`), `retracted_by`, `retract_reason`, `ip`. Append-only: a wrong bid is **retracted** (status), never deleted.

### 4.8 `auction_auto_bids`, `auction_watchlist`, `auction_enquiries`
- `auction_auto_bids`: `lot_id`, `registration_id`, `max_amount`, `active` (proxy bidding up to the max).
- `auction_watchlist`: `lot_id`, `client_user_id` (+ notification prefs: outbid, closing soon).
- `auction_enquiries`: `auction_id` (nullable), `lot_id` (nullable), `client_user_id` (nullable — guests may enquire), `contact_id`, `name`, `email`, `phone`, `message`, `status` (`new`|`contacted`|`closed`), `assigned_user_id`, `source` (`public_site`), `consent_popia_at`.

### 4.9 Reserved for phase 2 (columns/tables NOT created in v1)
Fees & tax on fees, deposits, buyer's commission, guarantees, bidder invoices, mailing list, Direct-sales format, analytics rollups. Nothing in v1 may hard-code a shape that blocks them.

### 4.10 Changes to existing tables
- `property_files.auction_visibility` — nullable enum `public`|`registered` (null = not part of the auction pack). The "new fields in the Drive section".
- `document_types`: optional seed of auction-pack types (Title deed, Municipal valuation, Zoning certificate, Levy statement, Site plan, Inspection report…) as agency-editable defaults.
- `agencies`/new `agency_auction_settings` — §7.
- Existing and kept: `properties.sale_method`, `Property::isAuction()`, `STATUS_ON_AUCTION`, `contact_matches.open_to_auction`.

> **Phase 2 build notes (2026-10-31).**
> - **"Registered" means signed in.** Johan: buyers must register before they get anything. So `registered`-only documents (auction level and the property Drive pack) open for any signed-in buyer account; per-auction bidder *approval* (phase 3) gates **bidding**, not documents. (Spec §8.1's "approved/registered users" is read this way.)
> - **Drive pack fix.** The property Drive stores `Document` rows linked through `document_properties`, not the deprecated `property_files` — so the "Show on auction page" flag is the `document_properties.auction_visibility` column (per property link). The phase-1 `property_files` column was removed by `2026_10_31_090000_move_auction_visibility_to_property_documents`.
> - **Buyer accounts reuse the existing client login** (`client_users`, guard `client-web`, `ClientAuthService::issueOtp()` with an optional agency so the code mail goes out as the agency). Sign-up and reset are email-code flows; a buyer gets one Contact per agency, created (match-or-create) on first use. Contacts and enquiries from the public site are stamped into the agency of the URL — never the agency of a staff member who happens to be browsing — and filed in a branch of that agency.
> - New permission keys: `auctions.enquiries.view|manage|view_all`. New table: `auction_enquiries`. Event: `AuctionEnquiryReceived`.
> - Reserve met / not met on the public lot page needs bids, so it arrives with phase 3.

> **Phase 3 build notes (2026-11-01).**
> - **Online bidding is for timed auctions.** A live auction's bidders still register and are approved online, but bids are taken in the room (phase 4: Sale Room, floor bids, hammer).
> - **Minimum bid = current high + the increment in force** (the opening bid for the first bid). A bid need not be an exact multiple of the increment. A leading bidder cannot outbid themselves; they raise their auto-bid maximum instead.
> - **Auto-bids** are resolved directly (never bid-for-bid): every bidder has a "cap" (highest bid or auto maximum); the highest cap leads and pays one increment above the second-highest cap, never above its own. A tie on price goes to the bid actually placed at that price. Setting an auto-bid while not leading bids the minimum at once. Retracting a bid switches off that bidder's auto-bid on the lot.
> - **Soft close:** a bid in the last N minutes moves the closing time out by N (auction override, else agency setting; 0 = off). The lot, bid, auto-bid and retraction paths all serialise on a row lock on the lot.
> - **Registration deadline:** the auction's own `registration_closes_at`, else N hours (agency setting, default 24) before it starts.
> - **Bidder documents are per agency** (refines §4.6): "use existing" reuses what the buyer already sent to THIS agency; an agency never receives another agency's copy of someone's ID. Approval needs every required document kind accepted; the bidder number is 1, 2, 3… within the auction.
> - **A lot with bids is locked:** its bidding window, opening bid and reserve cannot be edited (retract the bids or withdraw the lot).
> - **Closing:** `auctions:run` (every minute) opens published timed lots when their window starts and closes ended ones: highest valid bid at/above the reserve (or no reserve) = sold; below the reserve = sold subject to confirmation; none = passed in (property released). When no lot is still running the auction becomes `closed`. A sold lot's property stays "On Auction" until phase 4 opens the Deal (the Deal milestone is what marks a property Sold today).
> - New tables: `auction_registrations`, `bidder_documents`, `auction_registration_documents`, `auction_bids`, `auction_auto_bids`, `auction_watchlist`. New permission keys: `auctions.bidders.view|view_all|approve|review_documents`, `auctions.bids.view|retract`. Not built yet from §10: `LotClosingSoon` (closing-soon emails), calendar entries, Deal on sold (phase 4).

> **Phase 4 build notes (2026-11-02).**
> - **Sale Room (S6)** — `/corex/auctions/{id}/room` (permission `auctions.room.operate`). For a LIVE auction: open a lot (one at a time), record a bid called from the floor against a **bidder number** (source `floor`: no clock, no soft close, no outbid email), **knock down** or **pass in** (`auctions.hammer`). For a timed auction it is a read-only monitor. The room refreshes itself when the lot changes (staff feed `GET /api/v1/auctions/lots/{lot}/feed`). Online bidders cannot bid on a live auction; they register online and are bid for in the room.
> - **Outcomes (same for the clock and the hammer):** highest bid at/above the reserve (or no reserve) = **sold**; below = **sold subject to confirmation** (seller decides within *confirmation_days*, agency setting, default 3; it is on the calendar); no bid, or the auctioneer passes it in = **passed in** (property released). Seller **confirms** (-> sold, Deal opens, only the winner is emailed again) or **declines** (-> passed in, property released, winner told their bid was not accepted). An auction cannot be completed while a lot awaits the seller.
> - **Deal:** `AuctionLotSold` -> `CreateDealOnLotSold` -> `AuctionDealFactory` (the Deal Register's own model, agent pivot and pipeline): cash deal, price = hammer price, seller(s) from the property, buyer = winner, listing agent. Needs a seller linked to the property; if there is none the sale still stands and the lot page shows **Open the deal** to retry. Fees are product-phase 2, so commission starts at 0 with a remark. The property becomes Sold through the Deal's existing milestone, as for any sale.
> - **Results (S7)** — `/corex/auctions/results` (`auctions.results.view`), CSV export (`auctions.results.export`; the reserve columns only for `auctions.reserve.view`). Stats: lots sold, awaiting seller, passed in, turnover, highest price, bids per lot.
> - **Calendar:** `AuctionCalendarSource` emits `auction_date`, `auction_registration_closes`, `auction_confirmation_deadline` (draft/cancelled auctions excluded).
> - **My account** lists the lots the buyer has won. New events: `AuctionPublished`, `AuctionCancelled`, `AuctionCompleted`, `AuctionLotWithdrawn`, `LotDeclinedBySeller`. New setting: `confirmation_days` (Settings -> Auctions and the Setup Wizard step). New permission keys: `auctions.room.operate`, `auctions.hammer`, `auctions.results.view`, `auctions.results.export`. Still not built: `LotClosingSoon` emails.

## 5. Screens, navigation and per-screen list standards

Sidebar group **Auctions** (feature-flagged `auctions`, per-agency, default off; permission-gated). Every screen below has a menu entry on the day it ships (NN #2). Every list ships: **search (named fields), sort (stated default), filter (status + date range minimum), pagination, a real empty state**, and enforces **OWN / BRANCH / AGENCY** scoping at the query layer (NN #8-CRUD). Direct URL by ID is blocked, not just unlinked.

| # | Screen | Menu | Purpose | Search | Default sort | Filters |
|---|---|---|---|---|---|---|
| S1 | **Auctions** (list, create/edit/archive/restore, clone, complete, cancel, send notification) | Auctions → Auctions | Auction setup: details, Location, Increments, Options (soft close), Live streaming, Documents | reference, title, venue | `starts_at` desc | status, format, date range, branch |
| S2 | **Lots** (list + builder) | Auctions → Lots | Create/edit lots; attach properties to an auction; set lot number, opening bid, reserve, window; reorder; withdraw | lot no., title, reference, property address, agent | auction `starts_at` desc, then lot no. | auction, lot status, reserve met/not, agent, date range |
| S3 | **Enquiries** | Auctions → Enquiries | Every enquiry from the public site, across all auctions/lots: assign, mark contacted/closed, open the Contact | name, email, phone, lot, message | `created_at` desc | status, auction, lot, assigned agent, date range |
| S4 | **Bids** | Auctions → Bids | Every bid on every lot: lot, property, bidder (no.), amount, time, source, valid/retracted; retract with reason | property address, lot no., bidder no./name, amount | `placed_at` desc | auction, lot, bidder, status, source, date range |
| S5 | **Bidders** | Auctions → Bidders | Registrations per auction: documents review, approve/decline, issue bidder number; tabs: Registered / Approved / Winning / Under-bidders | name, email, bidder no., ID no. | `created_at` desc | auction, status, bidder type, date range |
| S6 | **Sale Room** (live format; also a read-only monitor for timed) | from an auction | Auctioneer opens a lot, enters floor bids against a bidder no., sees online bids live, hammers: sold / passed in | — | lot order | — |
| S7 | **Results** | Auctions → Results | Concluded lots: hammer price, reserve met, winner, bids count; export CSV | property, lot no., winner | `result_at` desc | auction, outcome, date range |
| S8 | **Settings** | Settings → Auctions | Agency auction settings (§7) | — | — | — |
| P0 | **Property → Auction tab** | on the property | §6 | — | — | — |
| P1 | **Property → Drive → Auction pack** | on the property | §6 | — | — | — |

**Scoping per screen.** AGENCY: admin/owner sees all. BRANCH: branch manager sees auctions/lots/enquiries/bidders whose `branch_id` is theirs (S1) or whose property is in their branch. OWN: an agent sees lots/enquiries/bids for properties they list or are co-agent on. Bidder personal documents (S5) are visible only with `auctions.bidders.view` and never to OWN-only roles.

## 6. The property side

**Auction tab** (visible when the property is marked as auction; hidden on new/unsaved properties). Fields: *Auction* (pick an upcoming auction, or "Create a one-property auction" which auto-creates an auction named after the property with these dates), *Bidding opens* (date + time), *Bidding closes* (date + time), *Opening bid*, *Reserve price* (staff only), optional *guide price range*, *Lot number* (auto), status badge, link to the public lot page, and a read-only list of the bids so far. Saving creates or updates the property's single open **lot** — the tab is an editor of that lot, not a second source of truth. Validation: closes after opens; both inside the auction window unless the auction is a one-property auction; reserve ≥ opening bid; editing the window/opening bid/reserve is blocked once bids exist (retract bids first or withdraw the lot).

**Marking a property as auction.** The listing-type choice in the property create form/wizard gains **"On Auction"** (stored as a sale with `sale_method='auction'`); an existing sale can be switched from the Auction tab's entry point. Switching back to private treaty is blocked while the lot has valid bids.

**Drive → Auction pack.** On an auction property the Drive tab shows an *Auction documents* section. Each file gets a **Show on auction page** selector: *Hidden* (default) / *Registered bidders only* / *Everyone*. The public lot page lists the flagged files as downloads, gated accordingly. Download links are signed and logged (who, when).

## 7. Agency settings (and the Setup Wizard — NN #10a)

`agency_auction_settings` (one row per agency; neutral defaults, nothing HFC-specific):
- Public site: *site title*, *intro text*, *logo* (falls back to agency branding), *contact email/phone for enquiries*, *reference prefix*.
- Bidding: *default increment ladder*, *soft-close window* (default 5 min — a bid in the last N minutes extends the close by N), *show reserve status to the public* (`hidden` | `met_not_met`), *show bid count*, *show bidder numbers to other bidders* (default no).
- Registration: *registration requires approval* (always on in v1), *required documents for individuals*, *required documents for companies*, *terms/conditions text* shown at registration, *registration closes N hours before start* (default 24).
- Notifications: *email on new enquiry* (to listing agent / branch manager / both), *outbid emails* on/off.

**Wizard:** all of the above surface in the Agency Setup Wizard under a gated **Auctions** step (`config/agency-onboarding-copy.php`) with `explain`/`affects` copy; the saver guards every boolean with `$request->has()` (wizard spec §6.1). Deeper tables (increment ladder editor) stay on Settings → Auctions and are recorded under "Deliberately NOT in the wizard" in the wizard spec — **Johan's call to confirm**.

## 8. The public auction site and buyer accounts

### 8.1 Where it lives
`/auctions/{agency-slug}` — one branded site per agency, served by CoreX itself (not the external website API). Pages:
- **Home** — upcoming and open auctions, cover image, dates, "Register / Log in".
- **Auction page** — details, location map, schedule, public documents (Conditions of Sale, Registration Form), the lot grid.
- **Lot page** — photos, description, map, opening bid, status ("Bidding starts soon" / "Open for bids" / "Reserve met / not met" per agency setting / "Login to bid"), live bid box, bid count/history per setting, public documents for everyone, **gated documents only for approved/registered users**, "Add to watchlist", **"Make an enquiry"**.
- **Sign up / Log in / Forgot password / My account** — My account holds profile, documents on file, my registrations, my watchlist, my bids.
- **Register for this auction** — a logged-in buyer only (§8.3).
- Published only when the auction is `published`+; drafts are 404. Agency feature off → 404. Every page uses the **agency's** name, logo and wording; the CoreX name never appears (client-facing branding rule).

### 8.2 Buyer identity — reuse, don't duplicate
Buyers are **`client_users`** (the existing client-login identity from `client-auth.md`: global unique email, OTP activation, soft deletes, `created_by_agency_id`), linked to **Contact** per agency. We add a **web session guard `client`** (the existing one is Sanctum/mobile). A buyer who already has the mobile client login uses the same credentials. Self sign-up: email → OTP → set password → profile (name, phone, ID/passport no.). Sign-up creates or matches the agency's Contact (match-or-create; lead source `auction_site`). Rate-limited; POPIA consent captured and timestamped.

### 8.3 Registering for an auction
Logged-in buyer → *Register for this auction* → choose individual/company → upload required documents or tick **"use existing"** for any already on file → accept the Conditions of Sale → submit → status `pending`. Staff review in S5, accept/reject each document, approve (issues the bidder number, emails the buyer) or decline with a reason. **Only `approved` registrations may bid.** Registration closes per the auction/agency setting.

### 8.4 Bidding rules (timed and online-in-live)
A bid is accepted only if, inside one DB transaction with the lot row locked: lot is `open` and now ∈ [opens_at, closes_at]; registration is `approved` for that auction; bidder is not staff on the listing; amount ≥ current high + increment from the ladder (or ≥ opening bid for the first); amount is a valid ladder step. On accept: insert bid; run proxy (auto-bid) resolution; if within the soft-close window, extend `closes_at`; emit events. A bidder who is already the high bidder cannot outbid themselves. Floor bids (live format) are entered by staff in S6 against a bidder number and follow the same rules. Responses never include other bidders' identities or the reserve.

### 8.5 Real-time
v1 uses **polling** (3 s on an open lot page) against `GET /api/v1/auctions/lots/{lot}/feed` — returns current high bid, bid count, status, time left, whether *you* are winning. No websockets in v1.

### 8.6 After the hammer
Timed: a scheduled job (every minute) closes lots whose `closes_at` passed (honouring soft-close) and applies the §4.4 outcome rules. Live: the auctioneer hammers in S6. `sold` → property status Sold with the hammer price, winning Contact linked, `AuctionLotSold` → Deal opened. `sold_subject_to_confirmation` → seller-confirmation deadline on the calendar; confirm/decline from the lot. Winner and under-bidders are emailed; the winner sees "you have won" in My account.

## 9. Permissions (NN #5)
Module `auctions` in `config/corex-permissions.php`, section `auctions`: `access_auctions`; `auctions.view|create|edit|archive|publish`; `auctions.lots.manage`; `auctions.reserve.view|edit`; `auctions.enquiries.view|manage|view_all`; `auctions.bids.view|retract`; `auctions.bidders.view|view_all|approve|review_documents`; `auctions.room.operate`; `auctions.hammer`; `auctions.results.view|export`; `auctions.manage_settings`. Defaults: branch manager all except `manage_settings` and `bids.retract`; agent: view, create, edit, lots (own), enquiries (own), bids.view (own), bidders.view, results.view. Route middleware + controller checks + sidebar gating on every screen. Feature flag `auctions` (per agency, default **off**) in `config/corex-features.php` with a rollout-copy entry.

## 10. Domain events (catalogue additions — NN #9)
`AuctionScheduled`, `AuctionPublished`, `AuctionCancelled`, `AuctionCompleted`, `AuctionEnquiryReceived`, `BidderRegistered`, `BidderApproved`, `BidderDeclined`, `BidPlaced`, `BidRetracted`, `LotOutbid`, `LotClosingSoon`, `AuctionLotSold`, `AuctionLotSoldSubjectToConfirmation`, `AuctionLotPassedIn`, `AuctionLotWithdrawn`. Past-tense facts, uniform payload, logged to `domain_event_log`. Listeners: open Deal on sold (sync, idempotent, per E4/E5), create Contact on enquiry/registration, notify listing agent on enquiry, outbid/closing-soon/won emails, calendar entries (auction start, registration close, confirmation deadline) via a calendar source. The catalogue file is updated in the same commit as the events.

## 11. API (NN #7)
All HTTP APIs live under `/api/v1/*`, named, in the Admin → API catalogue, registered in `routes/web.php` (not `routes/api.php` — session-cookie auth; see the 2026-09-28 note in CHAT_STARTER). Staff (session `web`): lot feed, property typeahead for lots, bid list, retract. Buyers (session `client`): place bid, set auto-bid, watchlist toggle, lot feed. Public HTML pages are ordinary web routes under `/auctions/{slug}`. Throttles: bid 30/min per bidder, enquiry 10/min per IP, sign-up 5/min per IP.

## 12. Build phases (each is its own prompt, tested one file at a time, cleared lane between)

0. **Retire old data (needs Johan's order for Live).** Export old `auctions*`/`auction_*` tables to a backup file on the data volume; only then a migration drops them. QA2/QA1 first.
1. **Foundation** — schema, models, settings (+ wizard step), permissions, feature flag, S1 Auctions, S2 Lots, P0 Auction tab, P1 Drive Auction pack, "On Auction" listing-type choice, sidebar.
2. **Public site + buyer accounts + enquiries** — `client` guard, sign-up/login/password reset/My account, public home/auction/lot pages, gated documents, enquiry form, S3 Enquiries page, enquiry events/emails.
3. **Registration + bidding** — register-for-auction, bidder documents ("use existing"), S5 Bidders, bidding engine, auto-bids, soft close, S4 Bids page, watchlist, outbid emails, lot feed, scheduled closer.
4. **Live room + results** — S6 Sale Room, floor bids, hammer, S7 Results + export, sold → Deal, subject-to-confirmation flow, calendar source, basic result stats.

## 13. Acceptance criteria (summary — each phase adds its own tests)
- A second agency (non-HFC) can enable Auctions, configure the site, run an auction, and nothing shows another agency's data, name, logo or defaults.
- A buyer cannot see gated documents, register, or bid without signing up and logging in; cannot bid until approved; cannot bid below the next increment; cannot see the reserve or other bidders' identities.
- Concurrent bids on one lot never produce two winners or a lower-than-high accepted bid (tested).
- Soft close extends exactly as configured; closer job is idempotent.
- Every list: search, sort, filter, pagination, empty state, OWN/BRANCH/AGENCY scoping, direct-ID access blocked.
- A property's Auction tab and the lot builder always agree (one lot, two editors).
- Retract/cancel/archive only — no hard deletes. Old-module data survives in the backup file.
- Domain events fire and log; Deal opens once on sold even if the listener runs twice.

## 14. Files (indicative — final list fixed per phase)
Models `Auction*` (+ `BidderDocument`); `app/Services/Auctions/` (BidEngine, LotCloser, RegistrationGate, AuctionReferenceGenerator); controllers under `CoreX/Auctions/`, `Public/Auctions/`, `Api/V1/Auctions/`; views `corex/auctions/*`, `public/auctions/*`; property partials `_auction-tab`, drive auction-pack; migrations (≈10); `config/corex-permissions.php`, `corex-features.php`, `agency-onboarding-copy.php`, `feature-rollout-copy.php`; `routes/web.php`, `routes/console.php` (closer, reminders); sidebar; tests `tests/Feature/Auctions/*`.

## 15. Business questions — ANSWERED by Johan 2026-10-29 (all "yes")
1. **Public address — YES:** `/auctions/{agency-slug}` on the CoreX domain for v1; each agency's own domain is a later upgrade. (Original question: is `/auctions/{agency-name}` on the CoreX domain fine for v1, with each agency's own domain (e.g. `auctions.theiragency.co.za`) as a later upgrade?
2. **Reserve on the public page — YES:** default to showing only "Reserve met / not met" (BidPro style), or hide it entirely until the agency chooses?
3. **Bidder numbers and names — YES:** staff only while bidding is open.

---

## 16. The Preview page (added 2026-11-03, Johan: "a page called preview page where the user can [see] the auction preview page and make edits to the page")

**What and why.** Auctions → **Preview page** shows the agency's public auction site exactly as a visitor will see it — including auctions and lots that are still **drafts** — next to an editor for the wording and pictures on it. The agency sees the result before anyone else does, and changes it without touching code. Nothing is live to the public until the auction itself is published.

**Pillars.** Property (the lots shown), Agent/agency (branding), Contact (via the existing enquiry/sign-up flows, which are switched off inside the preview).

**Screen (S9).** Left: the editor. Right: the preview, in a frame, with a **Desktop / Tablet / Phone** width switch, a "Page to preview" picker (Home page, each auction, each lot), and **Open the live page** / **Copy link**. A banner inside the frame says it is a preview and shows whether the auction is a draft ("only you can see this until you publish it"). Links inside the preview move around the preview; forms (sign up, log in, enquire, bid) are switched off in it.

**What can be edited**
| On | Field | Notes |
|---|---|---|
| The page (agency-wide) | Page title, introduction, enquiries email and phone | existing settings, now edited here too |
| | **Banner picture** (upload / remove) | shown across the top of the home page; jpg/png/webp, 4 MB |
| | **Accent colour** | the header/brand colour; blank = the agency's own brand colour |
| | **"How bidding works"** text | optional section on the home page |
| | **Footer text** | optional line in the page footer |
| | **Show past auctions** on the home page | on by default |
| One auction | Title, description, **cover picture** (upload / remove) | cover shows on the home card and the auction page; editable while the auction is not completed/cancelled |

**Two buttons, on purpose.** **Update preview** re-draws the preview with the edits typed so far — nothing is saved. **Save changes** saves them (and they are live on the public page at once for anything already published). Unsaved picture uploads are held in a per-person temporary file, replaced on each preview and removed on save.

**Rules.** Plain text only (shown escaped, line breaks kept). Colour must be `#rrggbb`. All images stored on the public disk under the agency's own folder. Everything is per agency and neutral by default (no HFC wording). The preview renders through the SAME controllers and views as the public site (never a second copy), so what is previewed is what is served.

**Permissions.** Viewing the preview: `auctions.view`. Saving edits: new key `auctions.page.edit` (branch manager: yes; agent: no). Branch/own scoping applies to which auctions can be picked and edited (an agent only sees auctions they can see anywhere else).

**Data.** `agency_auction_settings`: `hero_image_path`, `accent_color`, `footer_text`, `how_it_works`, `show_past_auctions`. `auctions.cover_image_path` already exists.

**Setup Wizard (NN #10a).** Surfaced in the Auctions step: *Show past auctions*, *How bidding works* text, *Footer text*. **Deliberately NOT in the wizard (Johan to confirm):** the banner picture and the accent colour — a picture cannot be uploaded in a step and the colour is best chosen while looking at the page; both live on the Preview page, which the step links to.

**Acceptance.** A draft auction/lot previews; the public site 404s it. Unsaved edits appear in the preview and are not stored; Save stores them and the public page shows them. A visitor never sees a draft, another agency's page, or a staff-only banner. Forms in the preview do nothing. An agent cannot edit the page; another agency's auction cannot be previewed or edited by ID.
