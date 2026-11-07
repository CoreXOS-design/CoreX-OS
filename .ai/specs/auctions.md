# Auctions — Spec (REBUILD, v2)

> Status: **APPROVED by Johan 2026-10-29. All four phases, the Preview page and the fees/invoices phase (§17) BUILT on QA2 (not yet Johan-tested, not promoted). Not started: online card payments, seller's commission, Direct sales, deeper analytics.**
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
All HTTP APIs live under `/api/v1/*`, named, in the Admin → API catalogue, registered in `routes/web.php` (not `routes/api.php` — session-cookie auth; see the 2026-09-28 note in CHAT_STARTER). Staff (session `web`): lot feed, property typeahead for lots, bid list, retract. Buyers (session `client`): place bid, set auto-bid, watchlist toggle, lot feed. Public HTML pages are ordinary web routes under `/auctions/{slug}`. Throttles: bid 30/min per bidder, enquiry 10/min per IP, sign-up 6/min per IP. **Every public limit has its own counter name** (3rd `throttle:` argument) — the stock `throttle:N,1` shares one per-address counter across all routes, so page views used to eat the sign-up allowance and a first sign-up got "Too many tries" (fixed 2026-11-01; test `test_browsing_the_auction_site_never_uses_up_the_sign_up_allowance`).

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

---

## 17. Fees, deposits and bidder invoices (product phase 2 — added 2026-11-04)

**Decisions (Johan, 2026-11-04).** (1) The agency charges a **buyer's commission**: a percentage of the hammer price added on top for the buyer, plus VAT, **set per auction** with an agency default. (2) That commission **counts as normal commission on the deal** and splits with the listing agent the way every other sale does. (3) Money is handled by **invoice + manual recording**: CoreX produces the bidder's invoice and staff record the deposit and balance when the money arrives; no online card payments in this phase.

**Pillars.** Deal (its `total_commission` = buyer's commission **incl. VAT**, the Deal Register's own convention), Contact (the invoice is for the winning bidder's contact), Property, Agent (listing agent's share via the deal).

**The sum.** `commission = max(minimum, hammer × percent)` (ex VAT); `VAT = commission × VAT rate` (the agency's `vat_rate` setting, default 15%); `total payable = hammer + commission + VAT`; `deposit = hammer × deposit %`, due within *deposit_due_days* of the sale; `balance = total − deposit`, due within *balance_due_days*. All rounded to cents. The seller's side is untouched.

**Settings (Settings → Auctions, also in the Setup Wizard step).** Defaults for the agency: *Buyer's commission %* (neutral default **0 = no commission**), *minimum commission*, *Deposit %* (default 10), *Deposit due (days)* (default 3), *Balance due (days)* (default 30), *Payment details* (bank details printed on the invoice; blank = invoice shows none). Per auction (Auction form): *Buyer's commission %* and *Deposit %* overrides (blank = agency default).

**The invoice (S10 "Invoices").** One per sold lot, created as a **draft** the moment the lot is sold outright (or the seller confirms a below-reserve sale), after the Deal. The figures are a **snapshot** taken then (later settings changes never rewrite an invoice). Staff open it, can **Issue** it (assigns the number `{PREFIX}-INV-{year}-{0001}` per agency, emails the buyer as the agency with the PDF attached), **record payments** (deposit / balance / other; amount, date, reference — voidable with a reason), tick **bank guarantee received**, **cancel** (reason) and **restore**, and download the **PDF**. Status: draft → issued → part paid → paid, or cancelled; **overdue** is shown when a deposit or balance date has passed unpaid. Cannot be issued with a zero total. A cancelled invoice frees the lot to be invoiced again.

**Screen S10 Invoices.** Search: invoice no., bidder, lot, property; sort (default newest); filters status, auction, outstanding-only, overdue-only, date range; OWN/BRANCH/AGENCY by the lot's property (same as Bids); archive = cancel, with restore. Permissions: `auctions.invoices.view`, `auctions.invoices.manage` (issue, payments, guarantee, cancel/restore). Branch manager: both; agent: view (own).

**Buyer side.** Fees are disclosed before anyone bids: the auction and lot pages and the registration page state the buyer's commission %, "plus VAT", and the deposit % and due days. My account lists the buyer's issued invoices with a PDF download (only their own).

**Events.** `AuctionInvoiceIssued`, `AuctionPaymentRecorded`. Results gets a "Commission earned" figure.

**Not in this phase:** online card payments, seller's commission, refunds of deposits, bidder registration deposits, guarantees beyond a received/not-received tick with a note, calendar entries for deposit/balance dates (the list's Overdue filter covers it).

**Acceptance.** A sale with a 7.5% commission on R1 000 000: commission R75 000, VAT R11 250, total R1 086 250; deposit 10% = R100 000, balance R986 250. The deal's commission is R86 250 (incl. VAT). Only the winner can see their invoice; another agency's invoice is unreachable by id; payments beyond the amount owing are refused; settings changes never alter an issued invoice.

---

## 18. "Add to auction" on a property (added 2026-11-05, Johan)

**What he asked for.** When Auctions is on and someone clicks **Add to auction** on a property, it must first **ask which auction** it is going into and **what its lot number is**, **fill the auction's date in by itself**, and have them **enter the time**; then show all the auction details on the property — and the property must also **show under Lots**.

**The dialog** (property header; replaces the old "Put on Auction" button). Fields: *Which auction?* (the user's upcoming auctions, each shown with its date and whether it is live or online; plus "A new one-property auction for just this property"); *Lot number* (filled with the next free number, editable; a number already used in that auction is refused); then the date(s) and time(s):
- **Live auction:** *Auction date* (filled from the auction) + *Time* (typed in). The lot's start is that date and time.
- **Timed auction:** *Bidding opens* date (filled from the auction's start) + time, and *Bidding closes* date (filled from the auction's end) + time. Times are always typed in, never pre-filled.
- **New one-property auction:** both dates and times typed in.
Dates can be changed but are limited to the auction's own dates. Rentals cannot be added; a property already in a live lot is told which lot it is in.

**What happens.** The lot is created straight away (as a draft until the auction is published), the property is marked as an auction property, the user lands on the property's **Auction tab** (opening bid, reserve, guide price, bids), and the lot appears on **Auctions → Lots** and in the auction's own lot list. If the time falls outside the auction's window, or any rule is broken, the dialog reopens with the reason. Afterwards the header shows "On auction — Lot N (reference)" instead of the button.

**Rules unchanged.** One live lot per property; a finished/cancelled auction takes no lots; a lot's window must sit inside its auction; permission `auctions.lots.manage`.

**The public auction site and the Preview are always light (2026-11-01, Johan).** The public layout has no dark-device colour scheme and declares `color-scheme: light`, so every visitor — and the Preview frame — sees the agency's own header/button colours on a light page whatever their device is set to. Test: `test_the_preview_and_the_public_site_are_always_light`.

**Every auction email is sent from `mail@corexos.co.za` (2026-11-01, Johan: "all emails that come from the auction should be mail@corexos and not the info@").** One setting, `config('mail.auction_from_address')` (env `MAIL_AUCTION_FROM_ADDRESS`; blank = the general sender `MAIL_FROM_ADDRESS`), used by the sign-up / reset code, the approval / result / invoice emails and the new-registration / enquiry emails; the sender NAME is always the agency's own, replies go to the agency, no CoreX wording. The code email is delivered through the `corex` mailer (the account that logs in as that address) so the From matches the sending account. Subject `Your <Agency> auction sign up code: 123456` (reset: `...auction password reset code...`). The rental/tenant portal code email is unchanged. Test: `test_the_sign_up_code_email_says_auction_sign_up_code_and_comes_from_the_configured_address`.

---

## 19. Signed up tab, new-activity emails, sidebar numbers and alerts (added 2026-11-01, Johan)

Johan, verbatim: *"I need a page/section/tab inside the bidders page called Sign uped where the agency can see all users that have signed up. Also for the bidders and enquiries when someone Regiters to bid or sends en enqeuiry a email should be sent to the admin, but add a settings in the settings page for auctions where the agency can set who should reciebe these emails or they can turn the emails off. and when a bid is made or there is an equiry there should be a number that appears on the sidebar link and a notifcation should be sent to the admir or assinged agent"*

**Pillars.** Contact (every sign-up is a Contact in the agency), Agent (who is told), Property (the lot's listing agent), Deal-side only indirectly. Reads existing data; writes only the settings, the "last looked" marker and notifications.

### 19.1 Signed up (Auctions → Bidders → "Signed up" tab)
- **Who is listed:** every buyer who signed up on, or logged into, THIS agency's public auction site — whether or not they have registered for an auction yet (client access log events `auction_signup` / `auction_login` for this agency). Per agency: another agency's sign-ups never appear; a buyer who has accounts with two agencies is listed in each.
- **Columns:** Name, Email, Phone, Signed up (first sign-up or first login here), Last seen, Registered for (number of auctions; "Not registered yet"), and a link to their Contact.
- **Search:** name, email, phone. **Sort:** Name, Signed up (default, newest first), Last seen, Registered for. **Filter:** registered / not registered yet, signed-up date range. Pagination 25; real empty state ("No one has signed up on your auction page yet…").
- **Scoping:** agency level only — needs `auctions.bidders.view_all`; the tab is hidden otherwise and the URL returns 403. Contacts are read under the agency scope; direct access by id is not offered (no detail page).
- **No archive/restore here:** the tab is a read view of buyer accounts, which are not owned by this screen (Contacts own that).

### 19.2 Emails to the agency when someone registers to bid or enquires
- Settings → Auctions gets **"Emails about new registrations and enquiries"**: a switch (default ON) and **"Send them to"** — email addresses, separated by commas or new lines. **Blank = ONE mailbox: the agency's auction enquiries email, else its main email; only if it has neither, every administrator** (2026-11-01: a test enquiry sent eight copies to eight administrators). The switch OFF stops these two emails entirely.
- Triggers (domain events, §10): `BidderRegistered` → "New bidder registration" email; `AuctionEnquiryReceived` → the existing "New auction enquiry" email. Both go to the addresses above. The older "Who is told about a new enquiry" (listing agent / branch manager) is kept and its people are ALSO emailed, de-duplicated against the list; the switch OFF silences it too.
- Sent as the agency, never as CoreX (14 Oct rule). A failed send is logged and never blocks the registration or the enquiry.
- **Setup Wizard (NN #10a):** both controls are in the Auctions step; the saver is `has()`-guarded.

### 19.3 Numbers on the sidebar links
- **Enquiries** shows the number of enquiries still **New** that the person is allowed to see (counts what the screen is for; it goes down as enquiries are marked contacted/closed or archived).
- **Bids** shows the number of **new bids since the person last opened the Bids screen** that they are allowed to see (online bids and auto-bids; floor bids typed in by staff are not counted). Opening the Bids screen clears it. First time ever: starts from zero.
- **Bidders** shows the number of registrations still **awaiting approval** that the person is allowed to see (what the "Awaiting approval" tab lists; added 2026-11-01 after Johan noticed registering to bid gave no number). It goes down as each is approved or declined.
- Hidden at 0, "99+" above 99. Same OWN / BRANCH / AGENCY visibility as the screens (`visibleTo`).

### 19.4 Alerts (in-app notifications)
- Two new notification types through the notification gateway (so each person can switch them off in their own notification preferences): `auction.bid_placed` ("New bid on your lot") and `auction.enquiry_received` ("New auction enquiry").
- **Who:** the person the item belongs to — the lot's **listing agent** (a bid) or the enquiry's **assigned agent**; when there is none, every **administrator** of the agency.
- Bids: one alert per online bid; auto-bids and floor bids do not alert (an auto-bid is the system answering a bid; a floor bid was typed by staff). The alert links to the Bids screen filtered to that lot / the Enquiries screen.

### 19.5 Data and files
- `agency_auction_settings`: `notify_emails_enabled` (bool, null = on), `notify_emails_to` (text, null = administrators).
- New table `auction_screen_visits` (user_id, screen, seen_at; unique on the pair) — the "last looked" marker for the Bids number.
- Two `notification_event_types` rows (migration + seeder).
- Code: `BidderController::signedUp` + `bidders/signed-up.blade.php`; `NotifyOfBidderRegistration`, extended `NotifyOfAuctionEnquiry`, `NotifyStaffOfAuctionActivity` (sends the gateway's standard alert, no new notification class); `AuctionActivityRecipients`; `AuctionNavCounts`; `AuctionRegistrationMail`; settings screen + wizard copy.

### 19.6 Acceptance
Signed up lists only this agency's buyers incl. never-registered ones, searches/sorts/filters, and is blocked without `view_all`. Registering / enquiring emails the configured addresses (or admins), nothing when off; settings round-trip incl. the wizard. Sidebar numbers appear, respect scope, and the Bids number clears on opening Bids. An online bid alerts the listing agent (else admins); floor/auto bids do not; an enquiry alerts the assigned agent (else admins).

**Bidder documents open in the browser (2026-11-01, Johan).** On a registration, "View" opens the uploaded PDF or picture in a new tab instead of downloading it (inline, nosniff, only the allowed upload types - anything else still downloads).

### 19.7 "Live now" label and the start-of-auction email (added 2026-11-01, Johan)
Johan, verbatim: *"issue on the live preview it says upcoming even though its live their should also go out a email when the auction starts to eveyone that has signed up"*

- **Label.** The public auction cards (home page, Preview) say **Live now** from the moment the auction has started until it finishes, **Upcoming** before, **Finished** once closed/completed. It is worked out from the start time, NOT from the stored status (a timed auction's stored status only flips when the every-minute job runs, so it lagged behind the clock). `Auction::publicPhase()`.
- **Email.** When a published auction's start time arrives, everyone who has **signed up on this agency's auction page** (the same people as Bidders -> Signed up, §19.1) gets "<Auction title> is now live" with the dates and a link — sent as the agency from the auction sender (§19 mail rule), once per auction (`auctions.start_notified_at`, claimed before sending so two runs can never send twice). People who signed up of their own accord and agreed at sign-up that the agency may contact them about its auctions (the sign-up tick-box).
- **Only fresh starts.** An auction whose start time is more than 2 hours old when the job first sees it is marked done without sending (so switching this on, or a job that was down for a day, never mass-mails about an old auction).
- **Setting.** Settings -> Auctions -> "Email everyone who signed up when an auction starts" (`start_emails_enabled`, default ON), also in the Setup Wizard. Off = nothing is sent (and the auction is still marked done, so turning it on later does not mail about an auction already running).
- **Runs from** `auctions:run` (every minute). On QA1/QA2/Staging no Laravel scheduler runs (box rule), so QA2 gets a one-line system cron entry running ONLY `auctions:run` (`/etc/cron.d/corex-qa2-auctions`) — without it QA2 lots never open or close.

### 19.8 The bid box: current bid, minimum, quick "+" buttons (added 2026-11-01, Johan)
Johan, verbatim: *"please add to the bidden it should show the current bid and a user cannot type lower than the current bid, and also add action buttons like +10,000 or something like that"*
- The lot page's bid panel keeps showing the **current bid** (or the opening bid before the first bid) and now says **"Your bid must be at least R X."**
- The bid box **starts filled in with the lowest allowed bid** (current bid + the step in force, or the opening bid) and keeps following it as other people bid, until the bidder types or taps something.
- **Typing a lower amount is refused on the page:** the message turns red ("That is too low…") and **Place bid is switched off** until the amount is at least the minimum. The server still refuses a lower bid regardless (the page is a convenience, not the rule).
- **Quick buttons** add to the amount in the box: **+1, +2, +5 and +10 times the bid step in force** (e.g. + 10 000 / + 20 000 / + 50 000 / + 100 000 on a R10 000 step), plus **Minimum** to go back. The step comes from the AUCTION'S OWN increment ladder (`state.increment`), so another agency with other steps gets its own buttons; nothing is hard-coded. The amount is shown with thousands spaces as it is typed.

## 20. The new public lot page (added 2026-11-01, Johan: "yes I like that do the 6")
Johan asked for the public lot page to look better ("at the moment it looks bad and plain"). Five designs were mocked up in a design canvas; he chose **design 6 = the layout of the editorial design + the Manrope font and the bidding activity of the "live arena" design**. Built on the real page:
- **Look.** Light, in the AGENCY'S OWN colours (header text, price, buttons, accents all come from the agency's brand/button colours — nothing HFC-specific; the button text colour is chosen to stay readable on whatever button colour the agency set). Font **Manrope** across the public auction site. White top bar with the agency's logo/name (the old solid-colour bar is gone on every public auction page).
- **Layout.** A full-width hero (the lot's first photo, or the agency colour when it has none) with the "Live now / Upcoming / Ended" pill, "Lot N · auction", the title and the address over it; below it two columns — left: about this property, the bedroom/bathroom/garage/floor-size figures (only those the property has), **Bidding activity**, guide price and documents, the other photos, the enquiry form; right: the bid card overlapping the hero (current bid, bids, reserve met/not met, "Closes in", your bidder number, the bid box with the minimum and the + step buttons of §19.8, auto-bid, watch). On a phone the columns stack and the card no longer overlaps.
- **Bidding activity** (new): the lot's latest valid bids, newest first — amount, "Bidder #N" (or "A bidder" when the agency hides bidder numbers), how long ago, "Highest" on the top one and "You" on your own. It rides on the existing 3-second feed (`state.recent_bids`, last 8), so it updates live, and contains no names or contact details.
- **Setting.** Settings → Auctions → "Show the latest bids on a lot" (`show_bid_history`, default ON; also in the Setup Wizard). Off = the list is not shown and the feed does not carry it. Bidder numbers inside it still follow "Show bidder numbers to other bidders".
- **Not changed:** how bidding, registration, fees and emails work. Only the page.

## 21. The new public home page + footer fix (added 2026-11-01, Johan: "it still looks very bland and also the © 2026 … always needs to be at the bottom")
- **Footer.** Every public auction page is a full-height column: the footer ("© year Agency · contact details") is always pushed to the bottom of the screen, however short the page.
- **Home page** in the same look as the lot page (§20): a full-width hero (the agency's banner picture if it has one, else the photo of its live auction, else the agency colour) with the site title, introduction, a "Live now" pill and the "Sign up" / "Log in" buttons (signed-in buyers get "My account"); a one-line count of open auctions and lots; **Live now** and **Coming up** as photo cards (the auction's cover picture, else the first lot's photo, else the agency colour) showing the Live now / Upcoming label, dates, format, lot count and a "View lots" link; **How bidding works** as three neutral steps (Create an account → Register and get approved → Bid) unless the agency wrote its own text in the Preview page / settings, in which case that text is shown instead; **Past auctions** when the agency shows them. All colours the agency's own; nothing agency-specific in the default wording.
- No new settings and no change to how anything works - looks only.

**Photo gallery on the lot page (2026-11-01, Johan: "the more photos needs to be open like a gallery ... so that users can click and go through the properties").** Every photo of the lot opens in a full-screen gallery: click the banner photo, the "View all N photos" button or any thumbnail; previous / next arrows, the left / right arrow keys, a swipe on a phone, a thumbnail strip and an "n / N" counter; Esc, the close button or a click outside closes it. Keyboard focus is kept inside while it is open and returned afterwards. The photos no longer open one by one in new tabs. A lot with no photos has no gallery.

**Top bar (2026-11-01, Johan: "it shows the logo and the agency name it should only show the logo").** When the agency has a logo the top bar shows ONLY the logo (the agency name stays as its alt text for screen readers); with no logo it shows the name as text.

**Home page banner and counts (2026-11-01, Johan).** The banner NEVER uses an auction/property photo (it may be a poor photo, or there may be no running auction): it is the agency's own banner picture (Preview page) when it has one, else a designed background in the agency's colours (soft glow in its button colour + a faint skyline of rooftops, `.brand-bg`); with no picture the banner text takes the colour that reads best on the agency colour. The same background is used on an auction card or lot banner that has no photo. "N auctions open now" and "N lots to bid on" count ONLY auctions that are live now; upcoming ones are shown separately as "N coming up". The "An auction is live now" pill stays.

---

## 22. "Sell at auction" - the seller questionnaire on the public page (added 2026-11-01, Johan)
Johan, 2026-11-01: *"on the live preview add a section where a user can sell on auction"*, with a full brief for a 7-step "Seller Questionnaire" (written for another auction house, Caprivi Auctions). **What was built from that brief:** the same form, fields, rules, steps, live "Catalogue preview" card and confirmation - **but in the AGENCY'S OWN colours, font and name** (non-negotiable #9: nothing says Caprivi or HFC; the agency name is whoever's site it is). The brief's navy/brass/sand/Fraunces are replaced by the agency's brand colour, button colour and the public site's Manrope; "sand" tints are tints of the agency colour.

### 22.1 On the public page
- A **"Sell at auction" section** at the bottom of the agency's auction home page (anchor `#questionnaire`), eyebrow "Sell at auction", heading "Seller questionnaire", the intro, and "Takes about 8 minutes · No obligation to sell". The home banner gets a second button **"Sell your property at auction"** that jumps to it, and the top bar gets a "Sell at auction" link.
- **Seven steps** (About you, The property, Occupancy, Bond & finances, Auction options, Documents, Terms & consent) with the progress header, jump-anywhere tabs, per-step "complete" ticks, Back / Continue / Submit, live Catalogue preview card (sticky on desktop, stacked on mobile), pills with `aria-pressed`, inline errors, number fields stripped to digits, honeypot input `website`. Field names, options, defaults, required-if rules and consent wording exactly as in the brief.
- **Submitting** posts JSON to `POST /api/v1/auctions/public/sell/{agency-slug}` (named, in the Admin -> API catalogue, throttled **6 per minute per address on its own counter** so browsing never uses it up). Server-side validation mirrors the form; errors come back per field and the form jumps to the first step with a problem. On success the form is replaced by "Questionnaire received - Thank you. An auctioneer will contact you **<agency's response time>**." with a "Submit another property" link. In the **Preview** page the form can be explored but submitting is switched off.
- **Settings -> Auctions (and the Setup Wizard, NN #10a):** "Show the 'Sell at auction' section" (`seller_form_enabled`, default ON; off = no section, no button, no link, and the endpoint answers 404) and "How quickly do you reply to sellers?" (`seller_response_text`, default "within one business day" - the promise is the agency's to make, not ours).

### 22.2 What happens to a submission (pillars + events, NN #9 / #10)
1. Stored in `auction_seller_submissions` (key columns + the full answers as JSON, the IP, never the honeypot).
2. **Contact** (pillar): the person is match-or-created in the agency's contacts (existing duplicate rules), as a Seller-type contact where the agency has that type.
3. **Tracked property** (pillar, NN #10 universal match-or-create): `TrackedPropertyMatchOrCreateService::matchOrCreate()` with source `auction_seller_form` - the address, suburb, type, bedrooms, bathrooms, erf and floor size, price hoped for as the asking price - so the property is never orphaned and appends to the source chain.
4. Domain event **`AuctionSellerSubmitted`** (catalogue entry added). Listeners: **email the team** (the "emails about new registrations and enquiries" switch and recipients of §19.2 apply; blank = one mailbox) and an **in-app alert** to the agency's administrators (`auction.seller_submission`).

### 22.3 Staff screen - Auctions -> "Seller leads" (list standard, NN #8)
- **List:** Name (with email/phone), Property (type, address, suburb), Price hoped for, Format, When, Status, Assigned to.
- **Search:** name, email, phone, address, suburb, answers' free text (reason for selling / defects). **Sort:** When (default, newest first), Name, Suburb, Price, Status. **Filter:** status (New / Contacted / Closed), property type, auction format, date range, assigned (anyone / me / unassigned), Archived toggle. Pagination 25. Real empty state.
- **Detail:** every answer grouped by the seven steps, links to the Contact and the Tracked property, **assign to an agent**, **mark contacted / closed**, **archive** (soft delete only) and **restore**.
- **Scoping (query layer, direct URL by id blocked):** AGENCY tier (`auctions.sellers.view_all`) sees all; otherwise a person sees the leads assigned to them (OWN) or to anyone in their branch (BRANCH); unassigned leads are visible to the agency tier only. Other agencies' leads are unreachable.
- **Permissions (NN #5):** `auctions.sellers.view`, `auctions.sellers.view_all`, `auctions.sellers.manage` (assign / status / archive / restore). Branch manager: view + manage; agent: none by default; administrators via the admin role.
- **Sidebar (NN #2):** Auctions -> "Seller leads" with a number = leads still **New** the person may see (also in the Auctions group total).

### 22.4 Not built here
Emailing the seller a copy of their answers; uploading the documents themselves (the questionnaire only records which ones they have); turning a lead into a Property/Deal in one click. Say so and they are separate prompts.

---

## 23. People go to an admin, nobody is duplicated, and a seller's property lands under "Auction enquiries" (added 2026-11-01, Johan)
Johan, verbatim: *"when there is a new sign up, or someone that submits this form it should create a new contact for the user and assing them to admin and check if they already excist, also if the user already excists within corex but they have never signed up for auctions it shouldn't create a new contact for them. And lastly if a user fills in the seller questionnaire it should create a new property from the details and put it under a new tag/page called Auction enquiries and this should only show if auctions are turned on"*

### 23.1 Contacts
- Every NEW person the auction site brings in - a buyer's **sign-up**, an **enquiry**, a **seller questionnaire** - becomes a Contact in the agency **assigned to the agency's administrator** (`contacts.agent_id`; the first active administrator, `AuctionActivityRecipients::defaultOwner`). An enquiry about a lot keeps going to that lot's listing agent, as before. The contact's branch follows its owner's.
- **Match first.** Before creating anything the agency's own duplicate rules are applied (email / phone / ID per the agency's contact settings, archived contacts ignored). Someone CoreX already knows - an agent's buyer lead, an old client, anyone - who has **never signed up for auctions** is **not duplicated**: their sign-up / form is linked to the contact they already are. If that contact has no owner they are given to the administrator; if they already have an agent, that agent stays.
- The same person signing up and then sending the seller form is one contact, not two.

### 23.2 The property from the seller questionnaire
- Besides the tracked property (§22.2), the questionnaire now creates a real **Property** from the answers: title ("3-bedroom house in Ramsgate"), address, suburb, type, bedrooms, bathrooms, floor and erf size, price hoped for, sale method **auction**, agent = the administrator, the seller as its **owner**. Status **"Auction enquiry"** (`auction_enquiry`): off-market like Prospecting - never On Market, never syndicated, never counted as our stock.
- The same address sent again finds the property already made; it does not make a second one.
- It appears on the **Properties** page under a new **"Auction enquiries" tile and filter** (status dropdown too). It is **not** in the normal list (not in Total, On Market or the default view) and it is **only offered while the agency has Auctions switched on** - with Auctions off the tile, the filter and the rows are unreachable from the Properties page.
- The Seller lead shows "Open the property" and "Open the contact".
- Converting it into a live listing is the normal property flow (change its status and add the mandate); nothing here publishes anything.

---

## 24. The Auction Website API - connect an external auction website to CoreX (added 2026-11-01, Johan)
Johan, verbatim: *"there is a website that is being built along side all this that should act like the live preview page, where it shows the auctions where users can bid etc it should be updated and work along side corex, if a user signs up on the website it should pull through to corex and the login should work in corex as well, the same questionnaire is on this website so it should work the same with the property creation etc, I need you to add api in for everything that the live preview serves so that I can connect this website up to do the same, also I need a new option under agencies ... to create more than 1 [key] to connect multiple websites to 1 agency"*

**The contract for the website's developer is `.ai/specs/auctions-website-api.md`** (every endpoint, field, error and the login flow). Design decisions:
- **One API, same rules.** Everything the public auction page serves and does is available under `/api/v1/website/auctions/*`, and each endpoint calls the SAME services the page uses (sign-up, registration, bidding engine, enquiries, the seller questionnaire), so the page and a website can never behave differently: same validation, same fees, same emails, same contacts-to-admin and property creation, same notifications to the team.
- **Keys, not sessions.** The website's SERVER calls CoreX with its agency API key (`Authorization: Bearer cx_live_...`, the existing per-website key system). Three new key permissions: `auctions:read` (everything public: site, auctions, lots, live feed, documents), `auctions:buyers` (buyer accounts, registering to bid, bidding, watching) and `auctions:leads` (enquiries and the seller questionnaire). A key sees only ITS agency.
- **Buyer login = the same account.** A buyer is the existing client login (`client_users`). Sign-up / login / password reset through the API give the website a **buyer token** (separate table `auction_buyer_tokens`, hashed, 30 days, revocable, tied to one agency and never accepted by any other API - so a leaked website token cannot open the rental portal or the phone app). The website sends it as `X-Buyer-Token`. The same email + password also works on the CoreX-hosted auction page, because it is the same account; a sign-up on either side creates the same Contact (assigned to the admin, matched not duplicated, §23).
- **Visitor address.** The website's server may send `X-Visitor-IP` (trusted only because the key is valid) so rate limits, audit logs and the bidder's recorded address are the visitor's, not the website server's.
- **Many websites, one agency.** Admin -> Agencies -> API Access already creates any number of keys per agency (one per website, each with its own name, permissions, webhook and rate limit, revocable on its own). This adds an **"Auction website" preset** and the three permissions to that screen, and the screen shows the auction API address.
- **Not here:** pushing live bid events to the website (it polls the lot feed, as the page does) and the website's own front end.
