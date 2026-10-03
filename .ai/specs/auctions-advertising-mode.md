# Spec: Auctions — Advertising-only mode (addendum to `auctions.md`)

> Addendum to `.ai/specs/auctions.md` (which lives on branch `AT-432-Auction-side-of-corex`).
> Written 2026-10-02 on Johan's instruction: "assume the auction is just a place to advertise
> auction properties and not to do the actual auctions" and "build all the missing stuff".

## 1. What and why
Many agencies advertise stock that is sold at an auction run by someone else (an outside auction
house, or the agency's auctioneer off-system). For them Auctions is an **advertising channel**:
the agent attaches a property to an auction, publishes it, and buyers see a proper public advert.
CoreX does not register bidders or take bids. This mode is the **default**; an agency that wants
CoreX to run the sale unticks "Advertising only" in Settings → Auctions and everything built in
the original spec is available again, unchanged.

Pillars: **Property** (lot = property in an auction), **Contact** (enquirers become Contacts via
match-or-create), **Agent** (listing agent receives the lead; FFC gate), **Deal** (unchanged — a
recorded "Sold" result still fires `AuctionLotSold`).

## 2. Mode switch
`agency_auction_settings.advertising_only` (nullable bool; NULL = default **true**). When true:
Sale Room and Bidder Register routes are blocked server-side (`EnsureAuctionRunMode`), their
buttons are hidden, and `register-to-bid` redirects to the auctioneer's own link (or the public advert).

## 3. Publish gate (`AuctionPublishGate`)
Publishing is blocked, with plain-English reasons, until: each lot's property passes the existing
`MarketingReadinessService` (signed mandate document etc. — seller authority, same gate as any public
listing); each lot states whether it has a reserve (`0` = no reserve — CPA s45 disclosure); the listing
agent (and an internal auctioneer) have an unexpired FFC; an external auctioneer's company, licence
number and a contact (phone/email) are present per Settings → Auctions required fields.

## 4. Public advert
`/auction-catalogue/{auction}` (auction), `/…/lots/{lot}` (lot), `/…/documents/{rules|conditions}`,
`POST /…/lots/{lot}/enquire`. Public only once the catalogue is published; draft lots never shown.
Shows date/venue, "Conducted by" (auctioneer, licence no., phone/email), register-to-bid link, guide
price (if enabled), reserve disclosure (amount only when reserve visibility = published), viewings,
Rules of Auction / Conditions of Sale PDFs, photos, and the result badge (Sold / Sold subject to
confirmation / Passed in / Withdrawn). Throttled. No bidder data exposed.

## 5. Recording the result
`Record result` on the lot page (advertising mode): Sold (+ price) / Passed in / Withdrawn. Walks the
existing state machine in one transaction, so history, property status and `AuctionLotSold` behave
exactly as after a Sale Room sale. **Staff screen: search/sort/filter/pagination for the Diary and
Results already exist from the original build (spec §8) and are unchanged.**

## 6. Buyers and leads
- Enquiry form → `WebsiteLeadService::capture()` (match-or-create Contact, `portal_leads` row,
  buyer-pipeline seed, agent notification). Source tagged `auction_page:{auction}:{lot}`. POPIA consent required.
- `contact_matches.open_to_auction` (default true). `MatchingService::applyHardFilters` excludes a
  wishlist with it false from auction lots. Checkbox on the wishlist form.

## 7. Reminders
`auctions:send-reminders` (hourly): queued email to enquirers — "registration closes within 24h" and
"auction within 24h" — once each (recorded in `lead_source_raw.auction_reminders_sent`).

## 8. Settings / Setup Wizard (CLAUDE.md §10a)
New setting `advertising_only` on Settings → Auctions. Wizard step `auctions` (gated on the `auctions`
feature) carries advertising-only, auctioneer mode, reserve visibility and guide price via
`AuctionSettingsController::updateWizard` (every write `has()`-guarded). The rest stay on the settings
page — see `agency-onboarding-setup.md` §5.1.

## 9. Data model
`auctions`: `external_registration_url`, `auctioneer_phone`, `auctioneer_email`, `rules_file_path/name`,
`conditions_file_path/name`. `agency_auction_settings.advertising_only`. `contact_matches.open_to_auction`.
All additive and nullable/defaulted.

## 10. Not built here (stated, not hidden)
- Auction Mandate / Conditions of Sale generated and e-signed through DocuPerfect — the gate reuses the
  existing mandate-document check; a bespoke auction-mandate template is a separate piece of work.
- Auction-shaped notification for *matched* buyers (only enquirers get reminders).
- SMS/WhatsApp reminders; PDF catalogue; statutory register export (only relevant when CoreX runs the sale).
- Legal wording of any uploaded document is the agency's attorney's responsibility.

## 11. Acceptance
1. Default agency: no Sale Room/Bidder Register; direct URL redirects. 2. Publish blocked with reasons,
passes when satisfied. 3. Public pages reachable without login only after publish; reserve rules respected.
4. Enquiry creates a Contact + lead for the listing agent. 5. Record result updates lot + public badge.
6. Reminder sent once. 7. Wizard step appears only with the feature on and saves without wiping other settings.

## 12. Follow-ups built 2026-10-03 (Johan, QA2 testing)
- **Forms:** auction date / registration open / close and lot viewings use a date picker + time picker
  (`corex/auctions/_datetime.blade.php`, theme-aware); Create/Edit/Diary/Lot/Settings pages use the standard
  banner header and full-width layout; Diary filters match Contacts/Properties (single-status select, auto-apply).
- **Attach a Property** (auction page): search by address or title (`GET /api/v1/auctions/{auction}/property-search`,
  `permission:auctions.create`, scoped with `Property::visibleTo`, excludes properties already in the auction),
  pick one, it shows beneath the search. Server rules: one lot per property per auction (`AuctionLotAttacher::attach`
  is idempotent; `addLot` returns a validation error), and `addLot`/`store` honour own/branch/agency visibility.
- **Auction page** shows every detail and a Documents panel with View / Download for the Rules of Auction and
  Conditions of Sale (`GET corex/auctions/{auction}/documents/{rules|conditions}[?download=1]`, `auctions.view`,
  agency-scoped; works before publishing, unlike the public route). A failed upload now raises a validation error
  instead of saving path "0".
- **Property page:** a property with `sale_method = auction` gets an **Auction** tab (auction, lot, auctioneer,
  registration window, reserve/guide/opening bid/result, viewings, document links) and the **live preview** shows an
  "Auction details" section (same disclosure rules as the public advert: reserve amount only when reserve visibility
  = published; PDFs linked only once the catalogue is published). Data: `App\Services\Auctions\PropertyAuctionInfo`.
  The preview status badge reads "On Auction", the price card label "Auction".
- **Settings:** Auction settings are reached from Settings (Modules -> Auctions); the sidebar "Auction Settings"
  link was removed. Needs `auctions.manage_settings` AND access to Settings.
- **Price On Application** (property-wide, not auction-specific): `Property::formattedPrice()` returns
  "Price on Application" when the flag is on, so every consumer (preview, header, cards, brochure, match cards,
  portals' display strings, mobile `price_display`) hides the amount. The Pricing Details popup fields are linked
  to `#prop-update-form` via `form=` (the popup is teleported to `<body>`) and count toward the dirty/Save tracker.
