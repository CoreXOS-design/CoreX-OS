# Spec: Auctions — the third side of Properties

> **Status: SPEC ONLY — no code, no migration, no branch work.**
> Written 2026-09-27 on branch `AT-432-Auction-side-of-corex` (QA2 lane, Andre).
> Nothing in this document is built. Per CLAUDE.md Spec-First Rule this file must be
> reviewed and approved before the first line of code is written.
>
> **Pillars:** Property (the lot), Contact (seller / bidder / buyer), Deal (the sale
> struck at the fall of the hammer), Agent (auctioneer, listing agent, commission).
> All four. Auctions is not an island — it is a third route through the existing
> lifecycle, not a parallel system.
>
> **Related specs:** `.ai/specs/rentals-shared-screens.md` (the lens pattern this copies),
> `.ai/specs/listings.md`, `.ai/specs/deals.md`, `.ai/specs/dr2-pipeline-spec.md`,
> `.ai/specs/compliance.md`, `.ai/specs/corex-domain-events-spec.md`,
> `.ai/specs/p24-syndication.md`, `.ai/specs/agency-public-api.md`,
> `.ai/specs/agency-onboarding-setup.md`, `.ai/specs/multi-tenancy.md`,
> `.ai/specs/SPEC_calendar_event_classes.md`, `.ai/specs/esignature.md`.

---

## 0. Decisions on record

Andre, 2026-09-27, on the three shape-defining questions:

| Question | Answer |
|----------|--------|
| Who runs the auction — us, or a third-party auction house? | **"Both — build that into settings where the company can choose which one or both."** |
| Where do people bid — in the room, online, or hybrid? | **"Same, build both and have it live in settings under a new section called Auctions."** |
| Where does the agency's money come from — buyer's premium, seller commission, or both? | **"Build that into the settings for agencies to fill in."** |

**The governing consequence:** there is no hardcoded auction model in CoreX. Every
structural choice an auction house makes — who calls the bids, where bidding happens,
how the agency is paid, what a bidder must produce before they get a paddle — is an
**agency setting**, configured once in a new **Settings → Auctions** section and then
obeyed everywhere. This is SYSTEM.md §3 ("No Hardcoding — Ever") applied to a whole
module, and it is also what makes the module licensable to an agency that auctions
differently from Home Finders Coastal.

**Reference listing supplied by Andre** (the standard this must beat):
<https://www.capriviproperties.co.za/2-bedroom-townhouse-for-sale-in-nimrod-park-117195830>
— a Caprivi Auctions & Bids lot. Everything that page carries is captured in §14.4 as the
minimum public output. What that page *does not* carry is most of this spec: no reserve or
guide disclosure, no online registration, no rules-of-auction download, no bid history, no
result. Matching it is not the goal; the CoreX Operating Principle is to exceed it.

---

## 1. What this is, and why

An estate agency on the KZN South Coast sells property three ways: **private treaty**
(the normal "For Sale" listing), **lease** (the "To Let" listing), and **auction**. CoreX
today models the first two and has no concept of the third beyond a cosmetic "On Auction"
status label in the property-status list.

An auction is not a listing with a different price field. It is a different *process*
with its own clock, its own legal obligations, and its own money:

- It has a **fixed date and time** the whole marketing campaign counts down to.
- Buyers do not make offers — they **register as bidders** first, produce FICA, often lodge
  a refundable registration deposit, and receive a **paddle number**.
- The sale is struck by the **fall of the hammer** (CPA s45(4)), not by a signed offer
  that the seller later accepts. The document is signed on the spot.
- There is usually a **reserve** the seller sets privately, and often a published **guide
  price**; if the hammer falls below reserve the sale goes to a **seller confirmation
  period** instead of concluding.
- The agency is frequently paid by a **buyer's premium** — a percentage added on top of
  the hammer price, plus VAT — instead of, or as well as, seller's commission.
- It is regulated separately: **Consumer Protection Act 68 of 2008 s45 and the CPA
  Regulations, Chapter 2 (Auctions)** sit on top of the Property Practitioners Act, FICA
  and POPIA obligations that already apply.

None of that fits in the existing listing screens, and none of it can be faked with a
status label. The agency currently runs auctions outside CoreX — which means the auction
register, the bidder FICA, the result and the commission all live outside the system of
record. That is precisely the gap this module closes.

**The business outcome, in one sentence:** an agent sets an auction date on a property and
CoreX runs the whole campaign — markets the lot, registers and vets the bidders, produces
the legal pack, records every bid, closes the sale on the fall of the hammer, opens the
deal, and calculates the premium and the commission — without the agent leaving CoreX or
re-typing anything.

---

## 2. The one architectural rule that governs this entire spec

**An auction is a *method of sale* on a Property. It is NOT a third `listing_type`.**

`properties.listing_type` is a deliberately two-value canon — `Property::LISTING_TYPES =
['sale', 'rental']` — and the entire codebase branches on the binary `Property::isRental()`.
Roughly two hundred call sites read that binary and treat "not rental" as "sale":
`effectivePrice()`, `effectivePriceSql()`, `formattedPrice()`, the P24 and PrivateProperty
mappers, the website `ListingResource`, the matching engine, the deal register, every
price label and every portal payload.

Adding `'auction'` as a third value would make every one of those sites silently
mis-handle an auction lot, because none of them ask "is it an auction?" — they ask "is it
a rental?" and fall through to sale. That is the exact bug class `Property::isRental()`'s
own docblock was written to document: a rental that got advertised "For Sale" on the live
preview page because a case-sensitive check missed it. We will not manufacture a second
instance of it.

**Therefore:**

| Fact | Where it lives |
|------|----------------|
| Is this a sale or a lease? | `properties.listing_type` — unchanged, still `sale` / `rental` |
| Is it going to auction? | `properties.sale_method` — new, `private_treaty` (default) / `auction` |
| The auction event itself | New `auctions` table |
| This property's place in that auction | New `auction_lots` table |

`Property::isAuction()` becomes the single read-side source of truth, mirroring
`isRental()` exactly — case-insensitive, tolerant of inbound spellings, and the *only*
thing any surface is allowed to ask. No caller writes `sale_method === 'auction'` inline.

### 2.1 What the agent sees is still three choices

The data model has two columns; the **agent sees one picker with three options** —
`For Sale`, `To Let`, `On Auction` — exactly as Andre asked ("in the properties like rental
and for sale"). Choosing `On Auction` writes `listing_type = 'sale'` **and**
`sale_method = 'auction'` in one action, and reveals the auction panel. Choosing
`To Let` + the auction toggle (a commercial lease auction — rare but real) writes
`listing_type = 'rental'`, `sale_method = 'auction'`.

This is the Golden Rule in practice: we do complicated so the user can do simple. One
control on screen, two safe columns underneath, and not one of the existing 200 call sites
has to change its behaviour.

### 2.2 The Auctions menu leg is a lens, not a second screen

Andre's standing rule from `rentals-shared-screens.md` §0 applies unchanged, and is
restated here because it is the most likely thing for a build prompt to get wrong:

> There is **one** Properties screen, **one** Core Matches screen, **one** Buyer Pipeline
> board — today and after this work. The new menu entries are **routes that open the
> existing screen with a lens forced on**. Same controller, same Blade view, same model,
> same query builder.

`Auctions → Properties` is `PropertyController@index` reached by the route name
`corex.auctions.properties.index`. The controller detects that route **by name** — never
by a query-string parameter, never by a route default, because a route name is not
client-supplied — and forces `sale_method = 'auction'` *after* the query string is read,
so editing the URL cannot escape the lock. Session-persisted filters use their own key
(`corex.auctions.properties.filters`) so a saved filter set never leaks between entry
points. Self-referencing links in the Blade already go through `$indexRouteName` (done for
AT-401); the auction lens inherits that for free.

**Any implementation that produces a second PropertyController, a duplicated
`index.blade.php`, or a parallel query path is a defect against this spec, not a valid
interpretation of it.**

The pages that genuinely do not exist yet — the Auction Diary, the Sale Room, the Bidder
Register, the Result screen — are new screens, because they have no existing equivalent to
lens onto. They are listed in §8.

---

## 3. Pillar connections

| Pillar | Reads | Writes back |
|--------|-------|-------------|
| **Property** | address, type, beds/baths, erf, images, features, municipal valuation, compliance snapshot | `sale_method`, `pre_auction_status`, status → "On Auction" / "Sold", `first_marketed_at`, sold price, audit trail entry |
| **Contact** | seller (existing `contact_property` role), buyer pipeline, FICA records, consent | new `auction_bidders` rows; winning bidder tagged as buyer; `is_buyer`/buyer pipeline entry; bidder activity on the contact timeline |
| **Deal** | — | a Deal is opened on a confirmed sale, carrying hammer price, buyer's premium, deposit, confirmation deadline, attorney |
| **Agent** | FFC validity, commission split, branch | auctioneer attribution, listing-agent attribution, commission and premium share, daily-activity counters |

**Universal Match-or-Create (CLAUDE.md §10) applies.** Any auction lot ingested from an
outside source — a third-party auction house's catalogue, a scraped auction listing, a CSV
of a sale roll — goes through
`App\Services\Prospecting\TrackedPropertyMatchOrCreateService::matchOrCreate()` before any
property row is written, and appends to `source_chain`. Auctions never creates orphan
property data.

---

## 4. Settings → Auctions (the switchboard)

This is where Andre's three answers land. A new top-level **Auctions** section in
`/corex/settings`, gated on `auctions.manage_settings`, backed by one row per agency in a
new `agency_auction_settings` table with a `AgencyAuctionSettings` model exposing static
`...For($agencyId)` accessors — the same shape as `RentalApplicationQualifyingSetting` and
`AgencyContactSettings`, which is the established pattern and must be copied rather than
reinvented.

### 4.1 Who runs the auction

| Setting | Type | Default | What it changes |
|---------|------|---------|-----------------|
| `auctioneer_mode` | `internal` / `external` / `both` | `both` | Whether the Auction form offers "Our auctioneer", "Outside auction house", or asks per auction |
| `internal_auctioneers` | many-to-many `users` | empty | Which staff may be named as auctioneer; each must hold a valid FFC (checked at publish, §18) |
| `default_auctioneer_user_id` | FK `users` | null | Pre-selected on a new internal auction |
| `external_auctioneer_required_fields` | json | name, company, contact, licence no. | What must be captured about an outside auction house before the lot can publish |

When `auctioneer_mode = internal`, the "outside auction house" fields never render. When
`external`, the internal auctioneer picker never renders. When `both`, the auction form
asks once, at the top, and reveals the matching block. No auction can be published without
a resolved auctioneer.

### 4.2 Where bidding happens

| Setting | Type | Default | What it changes |
|---------|------|---------|-----------------|
| `bidding_modes_enabled` | multi-select: `in_room`, `online`, `hybrid` | `in_room` | Which bidding modes an auction may be created in |
| `default_bidding_mode` | one of the above | `in_room` | Pre-selected on a new auction |
| `online_auto_extend_enabled` | bool | true | Anti-sniping: a bid inside the window extends the close |
| `online_auto_extend_minutes` | int 1–30 | 5 | Length of that extension |
| `online_bid_increment_mode` | `fixed` / `banded` | `banded` | One increment, or an increment that steps with price |
| `online_bid_increment_bands` | json | see §11.3 | The band table |
| `proxy_bidding_enabled` | bool | true | Whether a bidder may lodge a maximum and let CoreX bid for them |
| `absentee_bids_enabled` | bool | true | Whether the auctioneer may enter a written absentee bid on the floor |
| `phone_bidding_enabled` | bool | true | Whether a bid may be recorded with channel `phone` |
| `bid_retraction_allowed` | bool | false | Whether the auctioneer may retract a recorded bid (always audited, never deleted) |

### 4.3 How the agency is paid

| Setting | Type | Default | What it changes |
|---------|------|---------|-----------------|
| `fee_model` | `buyers_premium` / `sellers_commission` / `both` | `buyers_premium` | Which fee blocks appear on the auction and feed the Deal |
| `buyers_premium_percent` | decimal 5,2 | 10.00 | Default premium % of hammer price |
| `buyers_premium_vat_inclusive` | bool | false | Whether the stored % already includes VAT |
| `buyers_premium_minimum` | decimal 12,2 | null | Floor in rand, applied when the % yields less |
| `sellers_commission_percent` | decimal 5,2 | null | Default seller commission % when `fee_model` includes it |
| `vat_rate_source` | `system` / `override` | `system` | Use the system 15%, or an agency override |
| `premium_payable_on` | `fall_of_hammer` / `confirmation` / `registration` | `fall_of_hammer` | When the premium becomes due — drives the Deal's payment schedule |

Every percentage is a **default**, overridable per auction and per lot, with the override
recorded and audited. An agency that charges 8% on residential and 6% on commercial sets
the default and overrides; it does not get told CoreX only does one number.

### 4.4 Bidder registration requirements

| Setting | Type | Default | What it changes |
|---------|------|---------|-----------------|
| `registration_required` | bool | true | Whether a paddle can be issued without registration at all |
| `registration_opens_days_before` | int | 14 | When online registration opens |
| `registration_closes` | `at_start` / `hours_before` | `at_start` | Whether late registration at the door is allowed |
| `registration_closes_hours_before` | int | 24 | Used when the above is `hours_before` |
| `require_fica_before_paddle` | bool | true | Hard gate: no FICA, no paddle |
| `fica_document_checklist` | json | ID, proof of address, proof of funds | Per-bidder checklist, agency-editable (mirrors `RentalApplicationDocumentRequirement`) |
| `registration_deposit_required` | bool | true | Whether a refundable deposit is taken to bid |
| `registration_deposit_amount` | decimal 12,2 | 50000.00 | Flat amount |
| `registration_deposit_refund_days` | int | 7 | SLA for refunding unsuccessful bidders — drives a task and a calendar event |
| `require_signed_rules_before_paddle` | bool | true | Bidder must e-sign the Rules of Auction before the paddle issues |
| `paddle_number_mode` | `sequential` / `manual` | `sequential` | How paddle numbers are allocated |
| `entity_bidders_allowed` | bool | true | Whether a company/trust may register (drives resolution + mandate docs) |

### 4.5 Reserve, guide and confirmation

| Setting | Type | Default | What it changes |
|---------|------|---------|-----------------|
| `reserve_visibility` | `private` / `disclosed_on_the_day` / `published` | `private` | Who may see the reserve and when |
| `guide_price_enabled` | bool | true | Whether a published guide price / range shows on the advert |
| `confirmation_period_enabled` | bool | true | Whether a below-reserve hammer goes to seller confirmation |
| `confirmation_period_days` | int | 7 | Length of that period (7 / 14 / 21 are the SA norms) |
| `vendor_bidding_disclosure` | text | statutory default | The disclosure line printed on every catalogue and advert (§18) |

### 4.6 Deposit and settlement on the day

| Setting | Type | Default | What it changes |
|---------|------|---------|-----------------|
| `purchase_deposit_mode` | `percent` / `fixed` / `none` | `percent` | How the deposit on the fall of the hammer is computed |
| `purchase_deposit_percent` | decimal 5,2 | 10.00 | % of hammer price |
| `purchase_deposit_due_days` | int | 0 | 0 = on the day |
| `balance_due_days` | int | 30 | Guarantees / balance deadline — drives a Deal milestone and calendar event |
| `default_attorney_provider_id` | FK | null | Transferring attorney pre-filled on the Deal |

### 4.7 Wizard surfacing — CLAUDE.md §10a, non-negotiable

Every setting in §4.1–§4.6 is a **new agency setting**, so §10a applies: the same build
prompt that adds it must surface it in the Agency Onboarding Setup Wizard
(`config/agency-onboarding-copy.php`), with its `explain` and its `affects` line, and wire
its saver into that step's `savers`.

- A new wizard step, **"Auctions"**, placed after the Properties step, gated on the
  `auctions` feature flag — an agency that does not auction never sees it.
- The step asks the five questions that matter and nothing else: *Do you run auctions?*
  → *Who calls the bids?* → *Where do people bid?* → *How are you paid?* → *What must a
  bidder produce before they can bid?* The long tail (increment bands, retraction policy,
  refund SLA) stays on the settings page and is recorded in the spec's "Deliberately NOT
  in the wizard" list below.
- **Read `.ai/specs/agency-onboarding-setup.md` §6.1 before wiring any saver.** The wizard
  step posts a *subset* of the saver's fields, and a saver that coerces an absent checkbox
  to `false` silently wipes settings the step never rendered. Guard every boolean write
  with `$request->has()`.

**Deliberately NOT in the wizard** (expert knobs, configured once the agency is running —
this list is the record that the omission was a decision, not an oversight):
`online_bid_increment_bands`, `bid_retraction_allowed`, `registration_deposit_refund_days`,
`paddle_number_mode`, `vat_rate_source`, `default_attorney_provider_id`,
`external_auctioneer_required_fields`. **Johan's call to confirm** — §22 Q1.

---

## 5. Data model

All tables are tenant-owned: `agency_id` from migration one, model uses
`App\Models\Concerns\BelongsToAgency`, `SoftDeletes` on every one of them (CLAUDE.md
Non-negotiable #1 — no hard deletes, ever, including bids).

### 5.1 `properties` — new columns

| Column | Type | Notes |
|--------|------|-------|
| `sale_method` | `varchar(30) NOT NULL DEFAULT 'private_treaty'` | `private_treaty` \| `auction`. Indexed with `agency_id` — the Auctions lens filters on it on every page load |
| `pre_auction_status` | `varchar(100) NULL` | The on-market status held before the auction was published; restored on cancel/withdraw. Mirrors the existing `pre_deal_offer_status` / `pre_tenant_link_status` pattern already in this table |

Index: `KEY idx_properties_agency_sale_method (agency_id, sale_method, status)`.

New on `Property`: `const SALE_METHODS = ['private_treaty', 'auction']`, a
`setSaleMethodAttribute()` write guard normalising inbound spellings onto the canon
(copying `setListingTypeAttribute()` exactly), `isAuction()` as the single read-side
source of truth, and `currentAuctionLot()` / `auctionLots()` relations.

`Property::listingTypeLabel()` (the method behind the "To Let" / "For Sale" badge at
line ~1757) gains an auction arm so the badge reads **"On Auction"** — one change, in the
one place that already owns that label.

### 5.2 `auctions` — the sale event

One auction may carry many lots. A single-property auction is simply an auction with one
lot; there is no separate "single lot" code path.

| Column | Type | Notes |
|--------|------|-------|
| `id`, `agency_id`, `branch_id` | | |
| `reference` | varchar(40), unique per agency | Human reference, e.g. `AUC-2026-014` |
| `title` | varchar(255) | "Spring Coastal Portfolio Auction" |
| `auction_type_id` | FK `property_setting_items` | Settings-driven (§5.9) — Live On-Site, Live In-Room, Online Timed, Hybrid, Sealed Bid |
| `bidding_mode` | varchar(20) | `in_room` \| `online` \| `hybrid` — constrained to the agency's `bidding_modes_enabled` |
| `auctioneer_kind` | varchar(20) | `internal` \| `external` |
| `auctioneer_user_id` | FK `users` nullable | When internal |
| `auctioneer_contact_id` | FK `contacts` nullable | When external — the auction house is a Contact, so it inherits comms, FICA and the relationship graph rather than becoming a fourth kind of person |
| `auctioneer_company` | varchar(255) nullable | Denormalised for the catalogue |
| `auctioneer_licence_no` | varchar(60) nullable | |
| `starts_at` / `ends_at` | datetime | `ends_at` mandatory for online/hybrid; nullable for in-room |
| `registration_opens_at` / `registration_closes_at` | datetime | Defaulted from settings, overridable |
| `venue_name`, `venue_address`, `venue_lat`, `venue_lng` | | Null for pure online |
| `is_online_streamed` | bool | In-room auction with a public stream |
| `stream_url` | varchar(1000) nullable | |
| `status` | varchar(30) | §6 |
| `rules_document_id` | FK documents nullable | The Rules of Auction pack |
| `conditions_document_id` | FK documents nullable | Conditions of Sale |
| `catalogue_published_at` | timestamp nullable | |
| `notes` | text | |
| `created_by_id`, timestamps, `deleted_at` | | |

### 5.3 `auction_lots` — a property in an auction

| Column | Type | Notes |
|--------|------|-------|
| `id`, `agency_id`, `auction_id`, `property_id` | | Unique `(auction_id, property_id)` where not archived |
| `lot_number` | int | Order in the catalogue; unique per auction |
| `reserve_price` | decimal 15,2 nullable | Visibility governed by `reserve_visibility` (§4.5). **Never** serialised into any public payload unless that setting says `published` |
| `guide_price_min` / `guide_price_max` | decimal 15,2 nullable | What the advert shows |
| `opening_bid` | decimal 15,2 nullable | |
| `bid_increment` | decimal 12,2 nullable | Per-lot override of the band table |
| `buyers_premium_percent` | decimal 5,2 nullable | Per-lot override of the agency default |
| `sellers_commission_percent` | decimal 5,2 nullable | Per-lot override |
| `deposit_percent` / `deposit_amount` | | Per-lot override |
| `status` | varchar(30) | §6.2 |
| `hammer_price` | decimal 15,2 nullable | |
| `hammer_at` | datetime nullable | |
| `winning_bid_id` | FK `auction_bids` nullable | |
| `winning_bidder_id` | FK `auction_bidders` nullable | |
| `reserve_met` | bool nullable | Null until the hammer falls |
| `confirmation_deadline` | datetime nullable | Set when a below-reserve hammer goes to the seller |
| `confirmed_at` / `confirmed_by_id` | | Seller confirmation recorded |
| `deal_id` | FK `deals` nullable | The Deal opened on conclusion |
| `withdrawn_reason`, `passed_in_at` | | |
| timestamps, `deleted_at` | | |

### 5.4 `auction_bidders` — registration

**Why this is its own table and not a `contact_property` role:** `contact_property` carries
a `UNIQUE (contact_id, property_id)` constraint, so a contact can hold exactly one role
per property. A bidder is not a role on a property — it is a registration against an
*auction*, carrying a paddle number, a FICA state, a deposit and an approval decision.
Forcing it onto the pivot would break the constraint the first time a registered bidder was
also the seller's family member already linked to the lot.

| Column | Type | Notes |
|--------|------|-------|
| `id`, `agency_id`, `auction_id`, `contact_id` | | Unique `(auction_id, contact_id)` where not archived |
| `paddle_number` | varchar(20) nullable | Issued on approval, per `paddle_number_mode` |
| `status` | varchar(30) | `draft` → `submitted` → `fica_pending` → `approved` → `declined` / `withdrawn` |
| `bidding_for` | varchar(20) | `self` \| `entity` \| `agent_for_third_party` |
| `entity_contact_id` | FK `contacts` nullable | The company/trust when `bidding_for = entity` |
| `authority_document_id` | FK documents nullable | Resolution / power of attorney |
| `fica_status` | varchar(20) | Mirrors the compliance module's own vocabulary — read from it, never a second FICA truth |
| `fica_verified_at`, `fica_verified_by_id` | | |
| `deposit_required`, `deposit_amount`, `deposit_received_at`, `deposit_reference` | | |
| `deposit_refunded_at`, `deposit_refund_reference` | | |
| `rules_signed_at`, `rules_document_id` | | E-signed Rules of Auction |
| `registration_source` | varchar(20) | `online` \| `at_door` \| `staff` |
| `max_proxy_bid` | decimal 15,2 nullable | Per-auction cap when proxy bidding is on; per-lot caps live on `auction_bids` with `is_proxy` |
| `declined_reason` | text nullable | |
| `approved_at`, `approved_by_id` | | |
| timestamps, `deleted_at` | | |

### 5.5 `auction_bids` — the bid log

Append-only in spirit: a bid is **never** updated or deleted. A retraction (only when
`bid_retraction_allowed`) writes `retracted_at` + `retracted_by_id` + `retracted_reason`
and leaves the row intact. This table *is* the auction register for CPA purposes (§18) and
must survive an audit ten years later.

| Column | Type | Notes |
|--------|------|-------|
| `id`, `agency_id`, `auction_id`, `auction_lot_id`, `auction_bidder_id` | | |
| `amount` | decimal 15,2 | |
| `channel` | varchar(20) | `in_room` \| `online` \| `phone` \| `absentee` \| `proxy` |
| `placed_at` | datetime(3) | Millisecond precision — two online bids in the same second must order deterministically |
| `is_proxy` | bool | Generated by the proxy engine rather than typed by a human |
| `proxy_max` | decimal 15,2 nullable | |
| `recorded_by_id` | FK `users` nullable | The clerk/auctioneer for floor bids; null for online |
| `is_winning` | bool default 0 | Set once, on the fall of the hammer |
| `retracted_at`, `retracted_by_id`, `retracted_reason` | | |
| `ip_address`, `user_agent` | | Online bids only — evidentiary |
| timestamps, `deleted_at` | | |

Index: `(auction_lot_id, placed_at)`, `(auction_lot_id, amount)`.

### 5.6 `auction_lot_viewings` — show days before the sale

Auctions market on scheduled viewing windows, not ad-hoc appointments. One row per
published viewing; each creates a calendar event (§16) and feeds the public lot page.

`id, agency_id, auction_lot_id, starts_at, ends_at, is_by_appointment, notes, agent_id`,
timestamps, soft deletes.

### 5.7 `agency_auction_settings` — one row per agency

Every field in §4.1–§4.6, plus `agency_id` unique, timestamps. Model
`App\Models\AgencyAuctionSettings` with static `...For($agencyId)` accessors returning the
documented default when no row exists, so the module works on an agency that has never
opened the settings page. Copy `RentalApplicationQualifyingSetting` — do not invent a
second accessor style.

### 5.8 `auction_lot_status_history` — the audit trail

`id, agency_id, auction_lot_id, from_status, to_status, changed_by_id, reason, meta json,
created_at`. Written by the state machine, never by a controller. The property-level audit
trail (`.ai/specs/at-321-property-audit-trail.md`) receives a corresponding entry so the
property's own history shows "Went to auction 16 July 2026 — sold R1 450 000".

### 5.9 Settings-driven vocabularies — `property_setting_items`

Per SYSTEM.md §3, these are **not** PHP enums. Two new groups on the existing
`PropertySettingItem` model, seeded per agency through the established
`provisionDefaultsFor()` path (per-group, never per-row, so a curated agency is never
silently re-seeded):

- `GROUP_AUCTION_TYPE = 'auction_type'` — Live On-Site, Live In-Room, Online Timed, Hybrid, Sealed Bid
- `GROUP_AUCTION_LOT_STATUS = 'auction_lot_status'` — the labels for §6.2, so an agency may
  rename "Passed In" to "Not Sold"

The machine-readable status *slugs* stay in code (they drive logic); only the **labels** are
agency-editable. This is the same split the property-status list already uses.

### 5.10 Schema snapshot

Per CLAUDE.md §12a, every migration added here is followed by
`DB_DATABASE=hfc_dash_test php artisan schema:dump` (the **test** DB — a plain
`schema:dump` reads the stale dev DB and silently deletes tables from the committed
snapshot), the DEFINER strip via `perl -i -pe` (the PowerShell recipe in §12a adds a BOM
and phantom deletions), and `git add database/schema/mysql-schema.sql` in the **same
commit** as the migration.

---

## 6. Lifecycle and state machines

### 6.1 Auction (the event)

```
draft → scheduled → registration_open → in_progress → closed → settled
          │              │                   │
          └──────────────┴───────────────────┴──────→ cancelled / postponed
```

- `draft` — being built, invisible outside the agency.
- `scheduled` — date locked, catalogue not yet public.
- `registration_open` — public, bidders may register. Entered automatically at
  `registration_opens_at`.
- `in_progress` — the sale is live. For `online`, entered at `starts_at` automatically; for
  `in_room`, the auctioneer opens the Sale Room by hand.
- `closed` — the last lot has fallen. No new bids accepted, ever.
- `settled` — every lot resolved, deposits refunded, deals opened.
- `postponed` — new date required; every lot returns to `scheduled`, every bidder is
  notified, registrations carry over.
- `cancelled` — lots revert to `pre_auction_status`, deposits refund, bidders notified.

### 6.2 Auction lot

```
draft → catalogued → open_for_bids → under_the_hammer → ┬→ sold
                                                        ├→ sold_subject_to_confirmation → sold / passed_in
                                                        ├→ passed_in
                                                        └→ withdrawn
```

- `sold` — reserve met (or no reserve) and the hammer fell. **CPA s45(4): the sale is
  complete at the fall of the hammer.** CoreX treats it as concluded, not pending.
- `sold_subject_to_confirmation` — hammer fell below reserve. `confirmation_deadline` set
  from `confirmation_period_days`; a calendar event and a task are created; the seller
  confirms or declines.
- `passed_in` — no bid reached the reserve and the seller declined, or no bid at all.
  Property returns to `pre_auction_status` and the post-auction follow-up flow opens
  (§12.3).
- `withdrawn` — pulled before the sale.

**The property's status follows the lot, not the other way round.** Publishing the
catalogue stamps `pre_auction_status` and sets the property to the agency's "On Auction"
status item; `sold` sets it to "Sold"; `passed_in`/`withdrawn`/`cancelled` restore
`pre_auction_status`. One service (`AuctionLotStatusService`) owns every one of those
transitions, writes `auction_lot_status_history`, and emits the domain events in §17. No
controller sets a status directly.

---

## 7. The pages

| # | Screen | Route | New or lens? |
|---|--------|-------|--------------|
| 1 | **Auctions → Properties** | `corex.auctions.properties.index` | **Lens** on `PropertyController@index`, `sale_method='auction'` forced by route name |
| 2 | **Auction Diary** (list of auctions) | `corex.auctions.index` | New |
| 3 | **Auction detail / catalogue builder** | `corex.auctions.show` | New |
| 4 | **Lot detail** | `corex.auctions.lots.show` | New |
| 5 | **Bidder Register** | `corex.auctions.bidders.index` | New |
| 6 | **Bidder detail / FICA + approval** | `corex.auctions.bidders.show` | New |
| 7 | **Sale Room** (the live auction console) | `corex.auctions.room` | New |
| 8 | **Results & settlement** | `corex.auctions.results` | New |
| 9 | **Auctions → Core Matches** | `corex.auctions.core-matches.index` | **Lens** on `ContactMatchController@index` |
| 10 | **Auctions → Bidder Pipeline** | `corex.auctions.pipeline.index` | **Lens** on `BuyerPipelineController@index` |
| 11 | **Public lot page** | public listing route | Extends the existing public listing page — §14.4 |
| 12 | **Public bidder registration** | public tokenised route | New — §10.1 |
| 13 | **Settings → Auctions** | `corex.settings.auctions.*` | New |

### 7.1 Navigation — CLAUDE.md Non-negotiable #2

A new **Auctions** slide-panel group in `resources/views/layouts/corex-sidebar.blade.php`,
built exactly like the existing Rentals panel (Alpine group key `auctions`, added to the
`$activeGroup` resolution block and to the open-chain map). Entries, in order:

```
Auctions
  ├── Auction Diary          corex.auctions.index
  ├── Properties             corex.auctions.properties.index   (lens)
  ├── Bidder Register        corex.auctions.bidders.index
  ├── Sale Room              corex.auctions.room               (only while an auction is live)
  ├── Results                corex.auctions.results
  ├── Core Matches           corex.auctions.core-matches.index (lens)
  └── Bidder Pipeline        corex.auctions.pipeline.index     (lens)
```

The whole panel is hidden behind `config('features.auctions')` **and** the
`access_auctions` permission. An agency that does not auction sees nothing.

The sidebar's `$activeGroup` logic must handle the lens entries the way AT-401 handled
Rentals: landing directly on `corex.auctions.properties.index` must light up **Auctions**,
not Real Estate, and drilling from an auction lens into a property detail page must keep
the Auctions panel open (session lens key `corex.lens.auctions`). This was the exact bug
AT-401 documents at `corex-sidebar.blade.php` lines 186–214; copy the fix, don't rediscover it.

---

## 8. List-screen completeness and scoping — CLAUDE.md Rule 8

This is the floor, designed in from the first build, not requested later. Stated per screen
**before** code, as Rule 8 requires.

### 8.1 Auction Diary (`corex.auctions.index`)

- **Search:** reference, title, venue name, auctioneer name, auction-house company.
- **Sort:** starts_at (**default: starts_at ascending, future first**), reference, title,
  lot count, status, created_at.
- **Filter:** status (multi), bidding mode, auctioneer kind, auctioneer, branch,
  date range on `starts_at`, "has unsold lots".
- **Pagination:** 25 per page, agency-configurable page size honoured if one exists.
- **Empty state:** "No auctions yet — create your first auction" with the create CTA, and
  a distinct "No auctions match these filters" with a Clear link.
- **Scoping:** OWN / BRANCH / AGENCY at the query layer via `BelongsToAgency` +
  `AgencyScope`. OWN = auctions whose lots include a property where the viewer is primary
  or secondary agent. Direct-URL access by ID is blocked by policy, not by the absence of a
  link.

### 8.2 Auctions → Properties (lens)

Inherits the Properties screen's existing search, sort, filter, pagination and scoping
verbatim — that is the point of the lens. Additions visible only through this entry point:
filter by **auction** (which sale), **lot status**, **reserve met**, and sort by **lot
number** and **auction date**. The lock (`sale_method='auction'`) is applied after the query
string is read and cannot be escaped by URL editing; `?sale_method=private_treaty` still
returns only auction stock. Session filter key `corex.auctions.properties.filters`.

### 8.3 Bidder Register (`corex.auctions.bidders.index`)

- **Search:** bidder name, company, paddle number, email, mobile, ID/registration number.
- **Sort:** paddle number (**default: paddle number ascending**), name, status, FICA status,
  deposit received date, registered date.
- **Filter:** auction (required — the register is always *of* an auction), status, FICA
  status, deposit received yes/no, rules signed yes/no, registration source, date range.
- **Pagination:** 50 per page (a sale room needs density).
- **Empty state:** "No bidders registered yet" + the "Invite bidders" and "Register at the
  door" CTAs.
- **Scoping:** agency-scoped always. **Bidder PII is branch-restricted**: an agent from
  another branch sees the paddle number and status but not contact details, unless they
  hold `auctions.bidders.view_all`. FICA documents are visible only to
  `auctions.bidders.verify_fica` holders. Direct-URL access to a bidder record is blocked
  by policy.

### 8.4 Results (`corex.auctions.results`)

- **Search:** lot number, property address, suburb, buyer name, paddle number.
- **Sort:** auction date (**default: auction date descending, most recent first**), lot
  number, hammer price, reserve met, status.
- **Filter:** auction, lot status, reserve met, date range, branch, agent, price range.
- **Pagination:** 25.
- **Empty state:** "No results yet — results appear here as lots are knocked down."
- **Scoping:** OWN / BRANCH / AGENCY. Hammer prices are agency-visible; **reserve prices
  obey `reserve_visibility` even here** and are hidden from anyone without
  `auctions.reserve.view`.
- **Export:** CSV/XLSX of results, scoped identically to the screen — an export can never
  be a scope bypass, and never carries the reserve unless the exporter may see it.

### 8.5 Archive and restore

Every entity in §5 ships Create, Read, Update, **Archive (soft delete)** and **Restore**
from the first build. Archived auctions, lots, bidders and viewings are reachable from the
existing Soft Deletes admin surface (`.ai/specs/soft-deletes-admin.md`). **Bids are never
archived through the UI at all** — they are the legal register; a mistaken bid is retracted
(audited), not removed.

---

## 9. The auction campaign — step by step

1. **Agent sets a property to On Auction.** On the property form, the single listing-type
   picker gains a third option. Choosing it writes `listing_type='sale'` +
   `sale_method='auction'` and reveals the auction panel.
2. **Attach to an auction.** Either pick an existing scheduled auction from the diary, or
   create one inline ("Create a single-lot auction for this property") — the same
   `auctions` row either way.
3. **Set the lot economics.** Reserve (private), guide price (public), opening bid,
   increment, buyer's premium and/or seller commission — all pre-filled from settings,
   all overridable, all audited on override.
4. **Seller sign-off.** The reserve and the fee structure are written into the auction
   mandate and e-signed by the seller through the existing DocuPerfect/e-sign pipeline
   (§13). **A lot cannot be catalogued without a signed auction mandate** — the same gate
   philosophy the mandate flow already applies to a private-treaty listing.
5. **Publish the catalogue.** Property status → "On Auction" (previous status preserved in
   `pre_auction_status`), lot becomes `catalogued`, the public lot page goes live, portals
   are pushed (§14), the countdown starts, viewings publish to the calendar.
6. **Market it.** The existing marketing stack applies unchanged — ad generation, the
   share link, Core Matches, seller outreach, the buyer wishlist — with auction-aware copy
   (§14.5).
7. **Bidders register** (§10). Online through a tokenised public link, or at the door by
   staff. FICA is verified, deposit taken, Rules of Auction e-signed, paddle issued.
8. **The sale runs** (§11). Sale Room console for in-room; timed engine for online; both at
   once for hybrid.
9. **Fall of the hammer** (§12). Reserve met → `sold`; below reserve → seller confirmation.
10. **Documents signed on the day** (§13) — Conditions of Sale / Memorandum of Agreement,
    signed by buyer and seller, e-sign or wet-ink, filed against the property.
11. **Deal opens** (§12.2) with hammer price, premium, deposit, attorney and the balance
    deadline pre-filled. Commission calculates. The deal enters the existing DR2 pipeline.
12. **Settlement.** Unsuccessful bidders' deposits refund on the SLA; the result publishes;
    passed-in lots enter post-auction negotiation (§12.3).

---

## 10. Bidder registration

### 10.1 Public registration

A tokenised public page — same security shape as the existing rental-application and
client-auth links (signed URL, expiry, rate limit, no session, POPIA consent captured
explicitly). The bidder:

1. Identifies themselves (or their entity, if `entity_bidders_allowed`).
2. Uploads the FICA checklist from `fica_document_checklist`.
3. Pays or pledges the registration deposit if required.
4. Reads and e-signs the **Rules of Auction**.
5. Receives a provisional registration; the paddle issues only on staff approval.

**Match-or-create on the Contact:** a registering bidder is matched against existing
contacts before a new one is created — CoreX does not manufacture a duplicate of a contact
it already holds. Existing contact-matching rules apply; no second matcher is written.

### 10.2 Staff approval and the paddle gate

A paddle issues only when every enabled gate passes:
`registration_required` satisfied → FICA verified (if `require_fica_before_paddle`) →
deposit received (if `registration_deposit_required`) → Rules signed (if
`require_signed_rules_before_paddle`) → authority document present (entity bidders) →
staff approval. The Bidder detail screen shows the gates as a checklist with the blocking
one called out in plain words. **The gate is enforced server-side on every bid**, not just
in the UI: an unapproved bidder's bid is rejected by the service, whatever the screen shows.

### 10.3 Deposits

Registration deposits are tracked (`deposit_received_at`, reference) and **refunds are
scheduled work, not hope**: closing an auction creates a refund task per unsuccessful
bidder with a due date of `registration_deposit_refund_days`, plus a calendar event, plus
an overdue escalation. The winning bidder's deposit is offset against the purchase deposit
on the Deal automatically.

---

## 11. Bidding

### 11.1 In-room — the Sale Room console

A single-purpose, keyboard-first screen for the auctioneer's clerk, built for speed under
pressure:

- The current lot, large, with reserve (if the viewer may see it), current bid, next
  increment, and bidder count.
- **One keystroke per bid.** Type a paddle number and press Enter to accept the next
  increment; type an amount to accept an off-increment bid. Backspace-equivalent retracts
  the last bid if `bid_retraction_allowed`, always with a reason, always audited.
- Running bid history down the side, newest first, with channel icons.
- **Fall of the hammer** is a deliberate, confirmed action — never a single mis-key. It
  shows the outcome (reserve met / below reserve) before committing.
- **Offline-tolerant.** The sale room is often a marquee with bad signal. Bids queue
  locally and sync, using the established offline-draft persistence pattern
  (`.ai/specs/offline-draft-persistence.md`). A lost connection never loses a bid and never
  blocks the auctioneer.
- Next/previous lot navigation, with the catalogue order fixed at publish.

### 11.2 Online timed

- Bidding opens at `starts_at`, closes at `ends_at`, per lot or staggered.
- **Auto-extend (anti-sniping):** a bid inside the final `online_auto_extend_minutes`
  pushes the close out by that many minutes. Unlimited extensions; the lot closes when the
  window passes quietly.
- **Proxy bidding:** a bidder lodges a maximum; the engine bids the minimum needed to stay
  ahead, up to that maximum. Every proxy bid is a real row in `auction_bids` with
  `is_proxy = true` — the register shows exactly what the system did on the bidder's behalf.
- **Concurrency is the hard part and must be built correctly first time.** Bid acceptance
  runs inside a database transaction with a row-level lock on the lot
  (`SELECT ... FOR UPDATE`), validating the amount against the current high bid *inside*
  the lock. Two bidders hitting the same increment in the same millisecond must produce one
  accepted bid and one clean rejection — never two winners, never a lost bid. This is
  tested explicitly (§19).
- Public bid feed polls (the established pattern) — no new realtime infrastructure is
  introduced for Phase 2.

### 11.3 Increment bands (default `online_bid_increment_bands`)

| Current bid | Increment |
|---|---|
| R 0 – R 500 000 | R 10 000 |
| R 500 001 – R 1 000 000 | R 25 000 |
| R 1 000 001 – R 2 500 000 | R 50 000 |
| R 2 500 001 – R 5 000 000 | R 100 000 |
| R 5 000 001 + | R 250 000 |

Agency-editable in full. The auctioneer may always accept an off-increment bid on the
floor — the bands govern the online engine and *suggest* on the floor; they never block the
person holding the gavel.

### 11.4 Hybrid

One lot, one bid ladder, two input channels. Floor bids and online bids interleave in
`auction_bids` ordered by `placed_at` (millisecond precision). The Sale Room shows online
bids arriving live and highlights when the current high bid is online, so the auctioneer
knows the floor must beat a screen. Closing is manual (the hammer), not timed.

---

## 12. The fall of the hammer, and what follows

### 12.1 Conclusion

Confirming the hammer, in one transaction: stamp `hammer_price` / `hammer_at`, mark the
winning bid `is_winning`, set `winning_bidder_id`, compute `reserve_met`, move the lot to
`sold` or `sold_subject_to_confirmation`, write the status history and the property audit
entry, update the property status, and emit `AuctionLotSold` /
`AuctionLotSoldSubjectToConfirmation`. Partial completion is not possible — it is one
transaction or none.

### 12.2 Deal creation

A `sold` lot opens a Deal through the **existing** deal-creation path (no parallel deal
writer), pre-filled with:

- property, seller contact(s), **buyer = winning bidder's contact**, listing agent, branch;
- **hammer price** as the sale price;
- **buyer's premium** (hammer × premium % + VAT, floored at
  `buyers_premium_minimum`) and/or **seller's commission**, per `fee_model`;
- purchase deposit per §4.6, less the registration deposit already held;
- `balance_due_days` as a milestone and a calendar event;
- attorney from `default_attorney_provider_id`;
- `deal_type` — auction sales are typically **cash** in the DR2 sense
  (`deals.deal_type ∈ bond|cash|sale_of_2nd`); the build prompt sets the default and lets
  the agent change it. No new `deal_type` value is invented for auctions, because auction
  is *how the deal was sourced*, not how it is financed.

The auction attribution rides on the lot's `deal_id` link, so the Deal Register can filter
"auction deals" without a fourth deal type.

**Commission:** the buyer's premium is agency income, VAT-bearing, and must flow into the
existing commission engine and the Agency Tracker rather than a side calculation. The build
prompt reads `.ai/specs/commission_engine_spec.md` and `.ai/specs/agency-tracker.md` first
and extends the existing engine. **A second money path is not acceptable.**

### 12.3 Passed in, and the post-auction window

A passed-in lot is the single biggest source of auction income that most agencies drop. On
`passed_in`, CoreX automatically: restores the property to `pre_auction_status`; creates
follow-up tasks against **every registered bidder for that lot**, ranked by their highest
bid; surfaces the top under-bidder with their number so the agent can negotiate from a
known position; and offers a one-click "Open private-treaty negotiation" that carries the
lot's data forward. This is the Flows principle (SYSTEM.md §2) applied to the moment an
auction fails — the system offers the next step instead of leaving a dead listing.

---

## 13. Documents and e-signature

All auction documents are **web documents rendered by Puppeteer** (SYSTEM.md §4 — DomPDF is
dead and must not be introduced), produced through the existing DocuPerfect template system
with new document types registered in the established way. No auction document gets a
bespoke renderer.

| Document | When | Signed by |
|---|---|---|
| **Auction Mandate** | Before cataloguing | Seller — e-sign |
| **Rules of Auction** | Before any paddle issues | Bidder — e-sign |
| **Bidder Registration Form** | At registration | Bidder — e-sign |
| **Catalogue** | At publish | — (generated) |
| **Conditions of Sale** | Published with the catalogue; signed on the day | Buyer + seller |
| **Memorandum of Agreement / Deed of Sale** | On the fall of the hammer | Buyer + seller |
| **Auction Register extract** | On demand / after the sale | — (statutory record, §18) |
| **Bidder deposit receipt** | On deposit | — |
| **Refund confirmation** | On refund | — |

Signing on the day must work **on a phone, standing in a marquee, on bad signal** — the
e-sign ceremony already supports this shape; auction documents use it rather than a new
flow. Wet-ink fallback uses the existing `ESIGN-WETINK` path.

**Pipeline gate warning:** if a build prompt touches any file in the e-sign recipient
signing pipeline listed in CLAUDE.md, `scripts/dev-check.ps1` requires an accompanying test
diff under `tests/Feature/Docuperfect/SigningView/`. Do not use `-SkipPipelineGate` to avoid
writing that test.

---

## 14. Marketing, portals and the public page

### 14.1 The portal problem, stated honestly

Property24's ExDev API and PrivateProperty's feed are modelled around private-treaty sale
and rental listings. CoreX's own `Property24ListingMapper` currently collapses any
auction-ish status to `'Active'` (line ~1652) — it has no auction concept at all.

**This spec does not guess what the portals support.** Before Phase 3 is built, a
build prompt must verify, against the live API documentation and a sandbox submission, what
each portal accepts for an auction listing — auction date, guide price, "On Auction" status,
POA handling — and record the findings in this file. Shipping an invented field mapping to a
portal is how listings get rejected in bulk.

### 14.1a Verified portal findings (2026-09-27 — code audit + live-page check)

The paragraph above is now answered for both portals, from their real schemas plus one
live listing, not from guesswork. One conclusion up front, load-bearing for §5–§6:
**neither portal has an "Auction" status.** AUCTION and POA are two independent tags on
the same on-market listing, driven by separate fields — confirmed live on
<https://www.property24.com/for-sale/nimrod-park/kempton-park/gauteng/11539/117195830>
(a live Caprivi Auctions & Bids lot on P24), which shows an **AUCTION** badge and a
**POA** price tag together, plus the auction date/time (16 July 2026, 15:00), venue, and
"FICA Documentation Required for Registration". This means `sale_method = 'auction'`
(§2) and `price_on_application` are correctly modelled as orthogonal in this spec — no
change needed there.

**Property24** (`storage/p24_swagger.json`):
- `ListingStatus` has no Auction member — 13 values (`NewListing`, `Active`, `Rented`,
  `Withdrawn`, `BackOnMarket`, `Expired`, `Extended`, `RaisedPrice`, `ReducedPrice`,
  `Cancelled`, `Pending`, `Sold`, `CancelledSale`). The AUCTION badge on the live page is
  driven by the presence of `auctionInfo`, not by `status`.
- `Listing.auctionInfo` (→ schema `AuctionInfo`) is a real top-level field: `date`
  (date-time), `venue` (string, optional), `description` (string, optional),
  `numberOfLots` (int32, optional).
- `isPOA` (boolean) is a separate top-level field, independent of `auctionInfo` — matches
  the live page showing both tags together.
- `Property24ListingMapper::map()` (`app/Services/Syndication/Property24/Property24ListingMapper.php`)
  never builds `auctionInfo` — confirmed by reading the full method. `isPOA` **is** already
  wired (`:47`, `(bool) $property->price_on_application`) — nothing to do there.
  `mapPropertyStatus()`/`getP24Status()` (`:1652`) maps any status containing "auction" to
  plain `Active`, so an on-auction property syndicated today gets POA (if set) but zero
  auction date/venue/description and no AUCTION badge, because `auctionInfo` is absent —
  this is the exact, live-confirmed defect Phase 3 closes.
- Already flagged (not new): `.ai/audits/2026-06-26-p24-full-audit.md:94` and
  `.ai/audits/syndication-mapping-audit-2026-07-05.md:53` both list `auctionInfo` as
  unmapped.
- No column to hold this yet: `app/Services/Importer/P24ListingsCsvParser.php:86-88`
  already parses `AuctionDate`/`AuctionVenue`/`AuctionDescription` out of inbound P24
  export CSVs, but no migration creates matching columns on `properties` — imported
  auction detail currently has nowhere to land. §5.3's `auction_lots` table (date via the
  parent `auctions.starts_at`, `venue_name`, no free-text auction description field yet)
  should absorb this on write; confirm the importer target during Phase 3 build.
- `numberOfLots` has no CoreX equivalent today; irrelevant for a single-lot auction, but
  note it if a multi-lot batch import is ever built.

**Private Property** (`storage/pp-agentimport.wsdl`):
- `MandateType` includes a real `AuctionOnly` value (`:141`). CoreX's
  `PrivatePropertyListingMapper::mapMandateType()` (`:712-725`) has no `'auction'` case in
  its map — an on-auction property falls through to the default `'OpenMandate'` and never
  reaches PP as an auction listing. Phase 3 adds the case.
- PP's `PropertyStatus` enum (`ForSale`/`ToLet`/`PendingOffer`/`Sold`/`Inactive`/`Archived`)
  also has no Auction member — consistent with P24: PP signals auction via `MandateType`,
  not status, so `mapPropertyStatus()`/`statusFor()` need no new state, only the
  `MandateType` fix above.
- PP exposes a dedicated `ListingAuctionDetailsUpdate` SOAP operation (`AuctionVenueId` in,
  `VenueDetailsGet`/`ArrayOfAuctionVenues` for venue lookup — wsdl `:714-754`), not called
  anywhere in `PrivatePropertySoapClient`/`PrivatePropertySyndicationService`. Already
  flagged as a gap in `.ai/audits/2026-07-07-portal-data-gap-analysis.md:44` ("niche").
  Phase 3 build must decide whether this operation is in scope or whether `auctionInfo`-
  equivalent detail rides the main submit payload only.
- **Naming trap, resolved:** `MandateType` also contains `SAHSPOA` and `AbsaPoa` (wsdl
  `:140,143`). These are **Power of Attorney** bank/attorney distressed-sale categories
  (alongside `FNBQuickSell`, `AbsaHelpYouSell`, `SAHSaleInExecution`, `SAHInsolvencies`,
  `SAHDeceasedEstates`, `NedbankSie`, `StandardBankPip`, …) — **not** Price On Application.
  `price_on_application` must never be wired to either of these.
- PP's actual price-display field is `SalesPricePresentation` — a bare `s:string`
  (wsdl `:108`, no enum), sent today as a hardcoded empty string unconditionally
  (`PrivatePropertyListingMapper.php:97`), never populated from `price_on_application`.
  **Still open:** the wsdl gives no enumerated value, so the exact string PP expects for
  "POA" display (e.g. `"OnApplication"` or similar) is not confirmed from the schema
  alone. This is the one item Phase 3 still needs a sandbox submission or PP support
  confirmation for before wiring `price_on_application` → `SalesPricePresentation`.

### 14.2 The refresh-cost contract still binds

CLAUDE.md's portal sync contract applies unchanged: **a Refresh where nothing changed costs
exactly one portal call.** Auction data added to the submit path must be fingerprinted into
the existing signature so an unchanged auction never re-pushes. A countdown that updates
every minute must **never** trigger a portal push — the countdown is rendered client-side
from `starts_at`, not pushed. `Property24SyndicationService::auditRefreshCost()` and
`tests/Feature/Syndication/Property24RefreshCostTest.php` must stay green.

### 14.3 Website API

`App\Http\Resources\WebsiteApi\ListingResource` gains an `auction` block — mode, starts_at,
ends_at, venue, registration window, guide price, viewings, registration URL, and (only
when `reserve_visibility = 'published'`) the reserve. The endpoint stays under `/api/v1/*`
with a named route so it appears automatically in the Admin → API catalogue at `/admin/api`
(CLAUDE.md Non-negotiable #7). **No hidden JSON endpoint** is created for the bid feed or
the countdown — both are versioned, named, catalogued API routes.

### 14.4 The public lot page — the bar to clear

Everything the Caprivi reference page carries, and then the things it does not:

*Matched:* AUCTION badge; auction date and time; venue; "FICA Documentation Required for
Registration"; auctioneer identity; "Viewing by Appointment Prior to Auction"; "Terms &
Conditions Apply"; full property attributes; POA/price display; enquiry form; share; print.

*Beyond it:* a live countdown to the sale; the guide price or range; whether the lot is
subject to a reserve (without revealing it, per `reserve_visibility`); **Register to Bid**
as a real online flow, not a phone number; the Rules of Auction and Conditions of Sale as
downloadable documents *before* the day; scheduled viewing times with an add-to-calendar
link; the bid count and, for online lots, the live current bid; the auto-extend rule stated
plainly; a "what happens on the day" explainer; the result published after the sale instead
of a dead page; and the statutory vendor-bidding disclosure.

### 14.5 Auction-aware marketing copy

The existing AI ad-copy generator gains auction context — date, venue, guide price,
registration deadline — so generated adverts carry the urgency the format depends on.
`.ai/specs/marketing-ai-copy.md` governs; note that `ANTHROPIC_API_KEY` is empty locally,
so this path cannot be exercised end-to-end on a dev machine.

---

## 15. Matching and buyer intelligence

Auction lots participate in Core Matches and the buyer wishlist **as sale listings**, since
they are sale listings — no second matching engine, no fork of `MatchingService`. Three
auction-specific additions:

1. A buyer preference **"open to auction properties"** (default on), so a buyer who will
   not bid is not matched to lots.
2. Matched buyers receive an **auction-shaped** notification — date, registration deadline,
   guide price — rather than "new listing", with a deadline-driven reminder cadence
   (registration closing, day before, morning of).
3. **Under-bidders become first-class leads.** Everyone who bid and lost is a qualified,
   FICA-verified, deposit-paying buyer with a proven number. They enter the buyer pipeline
   automatically with that number recorded. This is intelligence no portal can hand the
   agency and the single strongest argument for running auctions inside CoreX.

---

## 16. Calendar and notifications

New calendar event classes (`calendar_events.category`, per
`.ai/specs/SPEC_calendar_event_classes.md` — `event_class` **is** `category`; no new column):

| `event_class` | Fires on |
|---|---|
| `auction_date` | The sale itself |
| `auction_registration_closes` | Registration deadline |
| `auction_viewing` | Each published viewing window |
| `auction_confirmation_deadline` | Seller confirmation expiry |
| `auction_deposit_refund_due` | Refund SLA per unsuccessful bidder |
| `auction_balance_due` | Guarantees / balance deadline on the resulting deal |

Each is seeded into `calendar_event_class_settings` with `agency_id = NULL` (global
defaults), so an agency inherits sensible thresholds, colours, visibility and digest
routing and can then change them.

**Notifications** go through the existing `NotificationDispatcher` — no second notification
path. Recipients and triggers: bidder (registration received, approved, declined, outbid,
won, lost, deposit refunded); agent (new registration, FICA outstanding, lot sold, lot
passed in, confirmation deadline approaching); seller (registration count, result, below-
reserve confirmation request); manager (auction opened, auction closed, settlement
outstanding).

**Queue caution:** request-triggered mail must be queued, never synchronous — the 08:30
queue/SMTP contention window is a known live failure mode. Auction-day mail volume is bursty
by nature; every auction notification is a queued job.

---

## 17. Domain events — CLAUDE.md Non-negotiable #9

Auctions is cross-pillar reactivity by definition, so it uses the event/listener catalogue
in `.ai/specs/corex-domain-events-spec.md`. Past-tense facts, under
`App\Events\Auction\`, each extending `AbstractDomainEvent`:

| Event | Fires when | Known subscribers |
|---|---|---|
| `AuctionScheduled` | Auction leaves draft | Calendar, audit |
| `AuctionCataloguePublished` | Catalogue goes public | Property status, syndication, matching, seller notification, audit |
| `AuctionRegistrationOpened` | Registration window opens | Matched-buyer notification, audit |
| `BidderRegistered` | Registration submitted | FICA task, contact enrichment, agent notification, audit |
| `BidderApproved` | Paddle issued | Bidder notification, audit |
| `BidPlaced` | A bid is accepted | Outbid notifications, live feed, audit |
| `AuctionLotSold` | Hammer at/above reserve | Deal creation, property status, commission, seller + buyer notification, audit |
| `AuctionLotSoldSubjectToConfirmation` | Hammer below reserve | Seller confirmation task, calendar deadline, audit |
| `AuctionLotPassedIn` | No sale | Property status restore, under-bidder follow-up, audit |
| `AuctionLotWithdrawn` | Pulled before sale | Property status restore, bidder notification, audit |
| `AuctionClosed` | Last lot resolved | Deposit-refund tasks, results publication, audit |

**Two hard-won rules the build prompt must obey** (both cost a lane a day already):

1. **Event discovery is OFF.** A listener in `app/Listeners` does nothing unless it is
   explicitly registered in `AppServiceProvider::boot()` with `Event::listen`.
2. **A queued listener on a domain event fatals** — `AbstractDomainEvent`'s parent readonly
   `$eventId` cannot be restored from the child scope on deserialisation. Keep the listener
   **sync** and have it dispatch a Job carrying scalars.

---

## 18. Compliance and law

Auctions add a statutory layer on top of the obligations CoreX already carries. **The legal
wording of every document produced here must be confirmed by the agency's attorney or
compliance officer before first live use — this spec specifies the system's behaviour, not
legal advice.**

- **Consumer Protection Act 68 of 2008 s45 and the CPA Regulations, Chapter 2 (Auctions).**
  The obligations CoreX must structurally support:
  - **Written Rules of Auction available for inspection before the sale** — published on the
    lot page and signed by every bidder.
  - **An auction register**, recording the auction, the lots, the registered bidders and
    what was bid. `auction_bidders` + `auction_bids` **are** that register: append-only,
    soft-delete-protected, exportable, retained.
  - **s45(4): a sale by auction is complete at the fall of the hammer.** The state machine
    treats it as concluded, not provisional.
  - **Mock/ghost/vendor bidding is prohibited unless properly disclosed.** CoreX has **no
    mechanism to enter a bid that is not attributed to a registered bidder** — the schema
    makes `auction_bidder_id` mandatory on every bid, so the system cannot produce an
    unattributed bid even if someone wanted it to. Where an agency's rules permit a
    disclosed vendor bid, it registers as a bidder like anyone else and is flagged as such.
  - **Disclosure of whether the lot is subject to a reserve**, carried on the lot page and
    the catalogue.
  - **The CPA cooling-off right does not apply to auction sales** — the buyer must not be
    shown cooling-off language on auction documents.
- **Property Practitioners Act 22 of 2019** — the agency and every practitioner involved
  needs a valid FFC. **Publishing a catalogue checks the listing agent's and the internal
  auctioneer's FFC validity and blocks on expiry**, reusing the existing FFC machinery.
- **FICA** — bidder verification before the paddle, through the existing compliance module.
  Auctions does not create a second FICA truth; it reads and writes the one that exists.
- **POPIA** — bidder registration captures explicit consent; bidder PII is branch-scoped
  (§8.3); the public registration form collects the minimum necessary; refund banking
  details are treated as sensitive and are never exported with the results.
- **VAT** — buyer's premium attracts VAT at the system rate (15%). Where the *seller* is a
  VAT vendor, the sale itself may be VAT-inclusive rather than transfer-duty-bearing; the
  lot carries a VAT treatment flag that flows to the Deal. The build prompt confirms the
  existing deal-level VAT handling and extends it rather than duplicating it.

---

## 19. Acceptance criteria

**Settings and configuration**
1. A fresh agency with no `agency_auction_settings` row can create and run an auction end
   to end on documented defaults.
2. Setting `auctioneer_mode = internal` removes every external-auction-house field from the
   auction form; `external` removes the internal picker; `both` shows the chooser.
3. Setting `bidding_modes_enabled = [in_room]` makes online bidding unreachable — including
   by direct URL to the online bid endpoint, which returns 403.
4. Changing `buyers_premium_percent` changes the premium on the **next** auction and does
   **not** retroactively alter a concluded lot's recorded fee.
5. Every setting in §4 appears in the Setup Wizard's Auctions step, or is named in the
   "Deliberately NOT in the wizard" list with Johan's recorded decision.

**The lens rule**
6. `Auctions → Properties` and `Real Estate → Properties` are served by the **same**
   controller action and the **same** Blade file — verified by asserting the route's action
   and the rendered view name, not by eyeballing.
7. `?sale_method=private_treaty` on the Auctions entry point still returns only auction
   stock.
8. A filter saved on the Auctions entry point does not appear on the Real Estate entry point.
9. Landing directly on the Auctions entry point opens the **Auctions** sidebar panel, not
   Real Estate.

**Data integrity**
10. Setting a property to On Auction leaves `listing_type = 'sale'` and does **not** alter
    `isRental()` behaviour anywhere.
11. A regression test asserts that no auction property is ever rendered, priced, labelled
    or syndicated as a rental, and that `effectivePrice()` is unchanged for auction lots.
12. Cancelling an auction restores every lot's property to its exact
    `pre_auction_status`.
13. A bid is never deleted. Retracting one leaves the row with `retracted_at` set and the
    register intact.

**Bidding correctness**
14. **Two simultaneous online bids at the same amount on the same lot produce exactly one
    accepted bid and one clean rejection** — tested with concurrent transactions, not
    sequential calls. No lot ever has two winning bids.
15. A bid inside the auto-extend window extends the close by exactly
    `online_auto_extend_minutes`, repeatedly.
16. A proxy bid never exceeds the lodged maximum, and every proxy bid appears in the
    register attributed to its bidder.
17. An unapproved bidder's bid is rejected **server-side** even when the UI is manipulated.
18. A bid below the current high bid plus the applicable increment is rejected online, and
    accepted on the floor only as a deliberate off-increment entry by the auctioneer.

**Conclusion and money**
19. Hammer at or above reserve → lot `sold`, property `Sold`, Deal created with the hammer
    price, premium and deposit correct to the cent including VAT.
20. Hammer below reserve → `sold_subject_to_confirmation`, `confirmation_deadline` set,
    calendar event and task created; expiry without confirmation → `passed_in`.
21. `passed_in` restores the property status and creates a ranked follow-up task for every
    registered bidder on that lot.
22. The winning bidder's registration deposit is offset against the purchase deposit, and
    every unsuccessful bidder has a refund task due on the SLA.
23. Buyer's premium reaches the commission engine and the Agency Tracker through the
    **existing** path — no parallel calculation exists anywhere in the diff.

**Scoping and permissions**
24. An agent from Agency B cannot read any auction, lot, bidder or bid of Agency A — by
    list, by detail, by direct URL, by export, or by API.
25. A user without `auctions.reserve.view` never receives a reserve price in any HTML page,
    JSON response, export or PDF. Asserted against the response body, not the rendered UI.
26. A user without `auctions.bidders.view_all` sees paddle numbers but no bidder contact
    details outside their branch.
27. Every list screen in §8 has its stated search, sort (with the stated default), filters,
    pagination and empty state, verified on screen.

**Portals and public output**
28. A Refresh of an unchanged auction listing costs exactly one portal call;
    `Property24RefreshCostTest` stays green.
29. The public lot page carries every item in §14.4, and the reserve appears only when
    `reserve_visibility = 'published'`.
30. Every new API endpoint appears in the Admin → API catalogue at `/admin/api` without
    manual registration.

**Non-negotiables**
31. Every entity has Create, Read, Update, Archive and Restore; nothing hard-deletes.
32. Every new page has a sidebar entry shipped in the same commit.
33. Every permission key is in `config/corex-permissions.php`, gating the sidebar, the route
    middleware **and** the controller.

---

## 20. Permissions

New keys in `config/corex-permissions.php`, section `auctions`, module `auctions`:

| Key | Type | Label |
|---|---|---|
| `access_auctions` | access | Access Auctions |
| `auctions.view` | action | View |
| `auctions.create` | action | Create |
| `auctions.edit` | action | Edit |
| `auctions.archive` | action | Archive |
| `auctions.publish` | action | Publish Catalogue |
| `auctions.reserve.view` | action | View Reserve Prices |
| `auctions.reserve.edit` | action | Set Reserve Prices |
| `auctions.bidders.view` | access | View Bidder Register |
| `auctions.bidders.view_all` | action | View All Branches' Bidders |
| `auctions.bidders.approve` | action | Approve Bidders / Issue Paddles |
| `auctions.bidders.verify_fica` | action | Verify Bidder FICA |
| `auctions.bidders.deposits` | action | Record & Refund Deposits |
| `auctions.room.operate` | access | Operate the Sale Room |
| `auctions.bid.record` | action | Record Bids |
| `auctions.bid.retract` | action | Retract a Bid |
| `auctions.hammer` | action | Knock Down a Lot |
| `auctions.results.view` | access | View Results |
| `auctions.results.export` | action | Export Results |
| `auctions.manage_settings` | action | Manage Auction Settings |

`role_defaults` for fresh installs only: Principal/Admin get everything; Branch Manager gets
everything except `manage_settings` and `bid.retract`; Agent gets `access_auctions`,
`auctions.view`, `auctions.create`, `auctions.edit`, `auctions.bidders.view`,
`auctions.results.view`; Auctioneer (a role the agency creates) gets `room.operate`,
`bid.record`, `hammer`, `reserve.view`. Existing roles are **never** edited directly in
`role_permissions` — Role Manager owns that.

Feature flag: `config/features.php` → `'auctions' => (bool) env('AUCTIONS_ENABLED', false)`,
plus a row in the agency feature switchboard
(`.ai/specs/agency-onboarding-feature-switchboard.md`) so an agency turns the module on for
itself. **Default off** — an agency that does not auction must see no change whatsoever.

---

## 21. Build phases

Each phase is independently shippable, independently testable, and leaves the system whole.
No phase depends on a later one to be correct.

| Phase | Scope | Why this boundary |
|---|---|---|
| **1 — Foundation** | `sale_method` + `isAuction()`, `auctions` + `auction_lots`, Settings → Auctions, feature flag, permissions, sidebar, Auctions → Properties lens, Auction Diary, lot detail, statuses, calendar events, domain events, audit trail | The agency can run an externally-conducted auction end to end: record it, market it, record the result, open the deal. Real value on day one with no bidding engine. |
| **2 — Bidders** | Public registration, FICA gate, deposits, paddles, Rules of Auction e-sign, Bidder Register, approval workflow | The register becomes CPA-compliant and bidder intelligence starts accruing. |
| **3 — The sale** | Sale Room console, in-room bid capture, offline tolerance, fall of the hammer, Deal creation, buyer's premium into the commission engine, Results screen, passed-in follow-up | The agency runs its own auctions inside CoreX. |
| **4 — Online & hybrid** | Timed engine, auto-extend, proxy bidding, concurrency locking, public live bid feed, hybrid Sale Room | The largest and riskiest build; deliberately last, behind a working in-room product. |
| **5 — Portals & public** | Verified portal mapping (§14.1), public lot page, website API auction block, auction-aware ad copy | Gated on the portal verification finding, which may constrain what is possible. |

---

## 22. Open questions — for Johan

Business decisions only. Everything technical is decided above.

1. **Wizard scope (§4.7).** Seven expert settings are proposed for the settings page but
   not the onboarding wizard. CLAUDE.md §10a makes that Johan's call, not the lane's.
   Confirm the omission list, or say which must appear in onboarding.
2. **Does HFC itself intend to run auctions**, or is the first real use marketing lots for
   an outside auction house? This does not change the build — settings cover both — but it
   changes which phase ships first and therefore what Johan is asked to test on QA.
3. **Auction mandate as a hard gate (§9 step 4).** The spec blocks cataloguing a lot until
   the seller has signed an auction mandate. Correct, or does HFC need to market before
   the mandate comes back signed?
4. **Reserve visibility default.** Specced as `private` — only reserve-permission holders
   see it. Confirm that is the agency's policy.

---

## 23. Files to create / modify

*Indicative, for Phase 1 unless marked. A build prompt confirms every path against
`CODEBASE_MAP.md` before touching it — this list is a starting point, not a licence to
create files that duplicate something that already exists.*

**Migrations** — `properties` (2 columns + index); `auctions`; `auction_lots`;
`auction_bidders` (P2); `auction_bids` (P3); `auction_lot_viewings`;
`agency_auction_settings`; `auction_lot_status_history`; seed the two new
`property_setting_items` groups; seed `calendar_event_class_settings` global rows. Then
`DB_DATABASE=hfc_dash_test php artisan schema:dump` + DEFINER strip via `perl -i -pe`, in
the same commit.

**Models** — `App\Models\Auction`, `AuctionLot`, `AuctionBidder` (P2), `AuctionBid` (P3),
`AuctionLotViewing`, `AgencyAuctionSettings`, `AuctionLotStatusHistory`. All with
`BelongsToAgency` + `SoftDeletes`. **Modify** `App\Models\Property` (`SALE_METHODS`,
`setSaleMethodAttribute()`, `isAuction()`, relations, the listing-type label arm) and
`App\Models\PropertySettingItem` (two new group constants + `DEFAULT_ROWS` entries).

**Controllers** — `App\Http\Controllers\CoreX\Auctions\{AuctionController,
AuctionLotController, AuctionBidderController, SaleRoomController, AuctionResultController,
AuctionSettingsController}`; `App\Http\Controllers\Public\AuctionRegistrationController`;
`App\Http\Controllers\Api\V1\AuctionController`. **Modify**
`App\Http\Controllers\CoreX\PropertyController` (route-name lens, mirroring the AT-401
rental lock) and `PropertyWizardController` (the three-option picker).

**Services** — `App\Services\Auctions\{AuctionLotStatusService, BidService (P3),
ProxyBidEngine (P4), PaddleNumberService (P2), BidderGateService (P2),
AuctionDealFactory (P3), BuyersPremiumCalculator (P3), AuctionRegisterExporter}`.

**Events / Listeners** — `App\Events\Auction\*` per §17, each registered explicitly in
`AppServiceProvider::boot()`; listeners **sync**, dispatching Jobs for anything slow.

**Policies** — `AuctionPolicy`, `AuctionLotPolicy`, `AuctionBidderPolicy`, enforcing OWN /
BRANCH / AGENCY per §8.

**Views** — `resources/views/corex/auctions/{index,show,create,edit}.blade.php`,
`lots/show.blade.php`, `bidders/{index,show}.blade.php`, `room.blade.php`,
`results.blade.php`; `resources/views/corex/settings/auctions.blade.php`;
`resources/views/public/auctions/register.blade.php`. **Modify**
`resources/views/layouts/corex-sidebar.blade.php` (Auctions panel + `$activeGroup`),
`resources/views/corex/properties/{index,show}.blade.php` (auction badge, auction panel,
lens-aware links), `resources/views/corex/settings/index.blade.php` (Auctions tab).

**Config** — `config/features.php` (`auctions`), `config/corex-permissions.php` (§20),
`config/agency-onboarding-copy.php` (the Auctions step + savers, §4.7).

**Routes** — `routes/web.php` (the `corex.auctions.*` group, the three lens routes, the
settings routes, the public registration route), `routes/api.php` (`/api/v1/auctions/*`,
all named, all catalogued).

**Documents** — new DocuPerfect document types and Blade web-document templates per §13.

**Tests** — `tests/Feature/Auctions/` covering every acceptance criterion in §19, with
`AuctionLensTest` (same controller/view assertion + URL-escape attempt),
`AuctionScopingTest` (cross-agency, cross-branch, reserve visibility, export),
`AuctionConcurrentBidTest` (P4 — real concurrent transactions),
`AuctionDealCreationTest` (P3 — premium and VAT to the cent).

**Docs** — update `.ai/CHAT_STARTER.md` and `.ai/ROADMAP.md` when the first phase lands.

---

## 24. What is deliberately NOT in this spec

Recorded so the omissions are decisions, not oversights:

- **Vehicle, plant, livestock or general-goods auctions.** CoreX is a real estate OS; the
  lot is always a Property. A future non-property lot would need a different pillar link and
  is out of scope.
- **Payment gateway integration** for registration deposits. Deposits are *recorded* (EFT
  reference, received, refunded), not *collected* by CoreX. Taking card payments is a
  separate product decision with its own compliance surface.
- **Live video streaming infrastructure.** `stream_url` embeds a third-party stream; CoreX
  does not host video.
- **Multi-currency.** ZAR only, consistent with the rest of CoreX.
- **A public bidder-facing mobile app.** Online bidding is responsive web. The existing
  mobile app surface is agent-facing and is not extended here.
