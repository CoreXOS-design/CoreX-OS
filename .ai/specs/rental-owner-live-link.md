# Rental Owner Live Link — "what is happening with my let" (AT-483)

**Status:** SPEC DRAFT, 21 Oct 2026 — NOT built, NOT approved. Written for Johan's review; no code until he says go.
**Ticket:** AT-483. **Pillars:** Property (the rental listing), Contact (the owner — read as the link's holder, never exposed to applicant data),
Agent (the point of contact on the page). Lease/Deal are not touched.
**Builds on, does not duplicate:** `seller-live-link.md` (the page, the token mechanism, the "seller removed → link off" rule, the
shared unavailable page), `rental-applications.md`, `rental-portal-access.md` (owner roles), `calendar-viewing-feedback.md` (one viewings source).
**Source of this draft:** cc2's investigation findings as relayed by Johan on 21 Oct 2026, each re-checked against the code on `origin/QA1`
(7e78c2a9f). Decisions not in that brief are marked **DECISION (proposed)**; business questions are marked **OPEN (business)**.

---

## PART A — WHAT THE OWNER SEES (plain language)

An owner whose property is **on the market to let** gets a link on their phone (WhatsApp, like the seller link). It is a
"are you actually working on my property?" page, and for a rental that means three honest numbers and nothing else:

1. **Enquiries from the property portals** — how many people asked about the property on Property24 / Private Property.
2. **Applications** — how many tenant applications we have **received**, how many were **declined**, how many were **approved**.
3. **Viewings** — how many viewings have actually been held.

Above them: the property photo, address, the asking rent, whether it is currently advertised, how long it has been on the market, and the
agent's name with a call / WhatsApp button. Below them: one plain sentence built only from the non-zero numbers ("12 enquiries, 4 viewings
held and 3 applications received since it went on the market.").

**What the owner never sees:** an applicant's name, ID number, contact details, documents, income, the reason an application was declined, or
any feedback comment from a viewing. Only counts. A section with nothing real behind it does not appear at all (no row of zeroes pretending to
be activity).

**When the property is let:** the link does **not** say "This property has sold" (today it does — a bug). It says **"This property has been
let"**, names the agent, and offers the agency's other listings. (**OPEN #1** below asks if Johan would rather it keep showing the final
numbers.)

**When the link stops working:** the owner is taken off the property, the agent revokes it, or it expires — the page then says "This link is no
longer active" with the agent's details. The agent can always send a fresh one.

**Agent:** on the property, the old "Seller Live Links" box becomes **Owner live link** for a rental. The agent presses **Send link** (WhatsApp
or email) or **Copy link**; the link is shown **only at that moment** (see "Why a link can't be shown again" below). A list under Rentals shows
every owner link — who it is for, when it was last opened, when it expires — with **Revoke** and **Send a fresh link**.

**Why a link can't be shown again (consequence, in one sentence):** links are stored scrambled (so a stolen database cannot be used to open anyone's
page), which means the system cannot show an old link again — pressing **Send** or **Copy** makes a **fresh** link and the previous one stops
working, so always use the newest one.

---

## PART B — TECHNICAL

### 1. Principles
- **Multi-agency, never single.** No HFC wording; every number/switch is an agency setting with a neutral default (`RentalPortalSetting`).
- **No hard deletes** (revoke = soft state, rows kept for the access history). **Scoping at the query layer** for the management screens and
  every export; the public page is scoped by the link's own property.
- **Counts only. No buyer/applicant identity, ever** (same rule as the seller page's rule 4, tightened: not even a tier or band).
- **One link table, one URL.** No second table, no second route: `/property/live/{token}` stays; the controller picks the page by what the property is.
- **The sale page is untouched.** Output of the existing seller page is byte-identical before/after (proved in the build by a before/after render).

### 2. What exists today (verified on `origin/QA1`) and what is wrong with it
| Fact | Where |
|---|---|
| Links live in `property_seller_links` (`token` = **plaintext** 64-hex, `contact_id`, `revoked_at`, `access_count`, `last_accessed_at`) | `app/Models/PropertySellerLink.php` |
| `SELLER_SIDE_ROLES = ['owner','seller','landlord','lessor']` — landlords/lessors already count as "seller-side" | same file |
| Links are **auto-created** for every seller-side contact on a property, including landlords of rentals (10 call sites call `ensureExists`) and the property page re-ensures them on every view | `PropertyContactController`, `PropertyController`, `corex/properties/show.blade.php` ~10990 |
| The property page shows the **raw URL** and a Copy/Email button, labelled "Seller Live Links" / "Seller" even for a landlord | `show.blade.php` ~11004 |
| `SellerLinkController::show()` renders **one** page for every property — the sale page (buyers matched, sold/under-offer comparables, mandate expiry, "your property", asking price) | `app/Http/Controllers/SellerLinkController.php` |
| **The sold bug:** `if ($property->isConcluded()) → showUnavailable(..., 'sold')`; `CONCLUDED_STATUSES` includes `rented` and `let_out`, so a **let** rental answers "This property has sold — it is no longer being marketed" | `SellerLinkController::show()`, `Property::CONCLUDED_STATUSES` |
| A rental is identified by `Property::isRental()` (`listing_type` in `rental, to_let, to-let, lease`) | `app/Models/Property.php` |
| The "seller removed → link off" derived rule (`scopeStillHeld`) already works for landlord/lessor roles | `PropertySellerLink` |
| Access is logged (`property_seller_link_accesses`) and the route is throttled 30/min | `routes/web.php:44` |
So for rentals today the owner is already auto-issued a link — to a **sale-shaped page that is wrong for a let** (buyers, sold comps, mandate, "sold"). This spec fixes that and adds the privacy/expiry hardening.

### 3. Page choice (the one branch)
`show($token)`: resolve link → if `$property->isRental()` render **`owner-link.live`** (new), otherwise the existing `seller-link.live` exactly as today.
Both share the same guards in the same order: unknown token 404 → revoked / seller-removed / expired → `showUnavailable(...)` → property gone → concluded → access recorded → page.
**The concluded branch becomes kind-aware (the sold-bug fix):** for a rental, `rented` / `let_out` → title *"This property has been let"*, body *"The link you
followed pointed to a listing that has since been let. It is no longer being marketed."* (never "sold"); a rental in any status of `sold`/`transferred`/
`sold_by_3rd_party` is not expected — if it happens it falls through to the generic "no longer available" wording, not "sold". Wording lives in one
`LABELS`-style array beside the existing one (a rename is a one-line change). A sale property's off-ramp is unchanged.

### 4. The numbers — exact definitions (each independently re-derivable)
All three share one **period**: from the start of the current letting — `App\Services\Properties\DaysOnMarket::for($property)` boundary (the date the current
advert went on the market) — to now. When the start cannot be proved (`DaysOnMarket` returns null) the counts are shown **to date** with no "since" claim and
no day-count (the same refusal the seller page makes — never guess).
1. **Enquiries from portals** = the sum of `leads` + `pp_leads` for the property over the period, from the **same** series the seller page and the agent
   Intelligence tab use (`PropertyIntelligenceService::getPortalEngagementSeries()`) — one query, not a second implementation.
2. **Applications** (counted from `rental_applications`, `property_id` = this property, not archived, `created_at` inside the period):
   - **Received** = status in `RentalApplication::POST_RETURN_STATUSES` plus `reopened` — i.e. the applicant has handed it in (a draft/sent/in-progress form is **not** received).
   - **Declined** = status `declined`.  **Approved** = status `approved`.
   (Withdrawn counts only inside *received*; it is not shown separately. An application is counted once however many times it moved.)
3. **Viewings** = `App\Services\Properties\PropertyViewings` rollup `total_viewings` for the property over the period — the single viewings source every other screen
   uses (completed viewing appointments or ones with feedback; booked-only/dismissed/deleted never count). **No feedback text, no concerns, no outcomes.**
A count is shown only if its agency switch is on **and** the value is greater than zero **or** it is part of the applications trio when any application was received
(so "0 declined" is not shown in isolation). Whole page absent-sections rule: a section with nothing real behind it is removed including its heading.

### 5. Privacy tests the build must prove (rendered HTML **and** any JSON/`@json` payload, not just the visible page)
No applicant/contact name, email, phone, ID/passport number, document name, income figure, decline reason, application id or reference appears anywhere in the
response source; no viewing feedback comment; no other agency, no other owner. The page loads with no third-party script (the seller page's CDN rule).
Phone viewport checked. A grep-style test fails if the owner view or its data array ever references `contact`, `applicant`, `reason`, `feedback` fields.

### 6. Token security + expiry (applies to **owner links**; the sale links are NOT changed by this spec)
- **Hashed at rest.** A new owner link stores only `token_hash = sha256(token)` (256-bit random token, so an unsalted SHA-256 is sound); the plaintext is returned
  **once** to the agent at issue time and never stored or logged. Lookup: `where token_hash = hash('sha256', $token)`; legacy rows (sale links) keep resolving by the old
  `token` column unchanged.
- **Expiry.** Every owner link has `expires_at` = issue time + the agency's `owner_link_expiry_days`; with `owner_link_renew_on_use` on (default) each valid open slides it
  forward (the existing contractor/crew-link precedent, `DEFAULT_LINK_RENEW_ON_USE`). An expired link answers the same "This link is no longer active" 410 as a revoked
  one, not a different message (no information about *why* beyond what the seller link already says).
- **Rotation.** Send / Copy always issues a fresh link and revokes the previous one in the same transaction (an owner never holds two live links to one property by accident).
- **Back-fill (rental links already issued, plaintext today):** one migration computes `token_hash = sha256(token)` for every existing link whose property is a rental, sets
  `expires_at`, then nulls the plaintext `token` for those rows — links already sent keep working (the URL still hashes to the stored value) and a database leak no longer
  opens them. The property-page raw-URL display is removed for rentals. Sale links untouched.
- **Unknown/guessed tokens** keep the generic 404 (the three-branch public-link policy); timing: constant-time compare on the hash.
- Access log (`property_seller_link_accesses`) keeps IP / user agent as today; throttle stays 30/min.

### 7. Data model changes (additive migration on `property_seller_links`; the build verifies the current constraints first)
| Column | Notes |
|---|---|
| `link_kind` | `'seller'` (default, all existing rows) \| `'owner'` (rental). Set from `Property::isRental()` at issue; the page choice in §3 reads the *property*, this column drives hashing/expiry/labels |
| `token_hash` | nullable string(64), **unique** index; set for owner links |
| `token` | made nullable (owner links store none) |
| `issued_at`, `expires_at` | nullable datetimes |
| `rotated_from_link_id` | nullable self-reference (audit of rotation) |
Auto-creation (`ensureExists`) keeps creating **one row per owner per property** but, for a rental, creates it **without** a secret (state *not issued*); the secret only comes
into existence when an agent presses Send/Copy. (Prevents plaintext secrets nobody asked for.)

### 8. Agency settings (`RentalPortalSetting` columns; read-time defaults)
| Setting | Default | Range / note |
|---|---|---|
| `owner_link_enabled` | on | master switch; off → no owner links can be issued and existing ones answer "no longer active" |
| `owner_link_expiry_days` | 60 | 1–365 (no "never": every link expires) |
| `owner_link_renew_on_use` | on | |
| `owner_link_show_portal_enquiries` | on | |
| `owner_link_show_applications` | on | received / declined / approved shown together or not at all |
| `owner_link_show_declined_count` | on | lets an agency show received + approved only |
| `owner_link_show_viewings` | on | |
| `owner_link_message_wording` | neutral default sentence set | agency-editable wording for the one summary sentence (no HFC text in code) |
**Setup Wizard (non-negotiable #10a):** every setting above is a control in the `leases` step of `config/agency-onboarding-copy.php` with a full-sentence `explain`
and a concrete "What this changes:" `affects`, each through its own narrow saver with `$request->has()`-guarded writes (`agency-onboarding-setup.md` §6.1). Removing
a control from the settings page removes it from the wizard in the same change.

### 9. Screens — the design standard (CRUD, search/sort/filter/pagination, scoping, navigation)
**A. Property page box** — "Owner live link" (rentals) / "Seller Live Links" (sales, unchanged): per owner: state (*not issued · live · expires {date} · revoked*), last opened /
times opened, buttons **Send link** (WhatsApp, email), **Copy link**, **Revoke**. No raw URL is ever rendered after issue.
**B. Owner links list** (`corex.rental-owner-links.index`, Rentals sidebar → *Owner links*) — full CRUD on the *issuing* of links:
- Create = **Issue** (Send/Copy); Read = detail with the access history (counts and dates only); Update = **extend / change expiry** for one link; Archive = **Revoke** (a
  manual revoke is permanent, as today); Restore = **Send a fresh link** (a new row, linked by `rotated_from_link_id`).
- **Search (named fields):** property address, owner name, owner email/phone.  **Sort:** last opened (**default, newest first**), expires, issued, property, owner, times opened.
- **Filters:** state (live / expired / revoked / not issued), issued-date range (minimum), agent, branch.  **Paging:** the agency per-page setting.  **Empty state:** "No owner links yet — they appear when an owner is linked to a rental."
- **Export:** CSV of the filtered, **scoped** list (no tokens, ever).
**Navigation (non-negotiable #2):** the Rentals sidebar entry ships with the feature; the property box is on the property page. **Permissions (non-negotiable #5):** `view_rental_owner_links`,
`manage_rental_owner_links` (issue/revoke/extend) in `config/corex-permissions.php` + sidebar gating + route middleware + controller checks.
**Scoping:** `PropertySellerLink` already uses `BelongsToAgency`; the list adds the rentals property-first own / branch / agency derivation (own = the viewer's properties; branch; agency).
Direct URL by id outside scope → 404.
**Routes (non-negotiable #7):** staff JSON, if any, under `/api/v1/…` and named; the public page keeps `seller-link.show`.

### 10. Domain events (non-negotiable #9)
Emit `RentalOwnerLinkIssued`, `RentalOwnerLinkRevoked`, `RentalOwnerLinkOpened` (the last only for the audit trail, not alerting); add to `corex-domain-events-spec.md`. No alert to the agent on open (the seller link
does not alert either); the "last opened" stamp is enough.

### 11. Acceptance criteria
1. A rental with a landlord: the owner's link opens the **owner** page — three counts + header + agent card — and shows **no** buyer, comparable, mandate or "sold" content.
2. Counts re-derive exactly from the definitions in §4 against real QA1 data (the build lists the SQL it checked).
3. The same property marked let: the link says **"This property has been let"** — never "sold"; a **sale** property that sells still says "has sold" (unchanged).
4. The rendered HTML and every JSON payload contain no applicant/contact identity, document, decline reason or viewing comment (automated test).
5. Tokens: only `token_hash` is stored for owner links; the plaintext appears once at issue; Send/Copy rotates (old link stops); an expired link answers like a revoked one; renew-on-use slides expiry; the back-fill leaves already-sent rental links working and nulls their plaintext.
6. Owner removed from the property → link off; re-linked → works again; a manual revoke is permanent (existing rule, re-tested for the owner kind).
7. The **sale** seller page's rendered output is byte-identical before/after for the same data (before/after render attached to the build report).
8. The list has working search, sort (stated default), filters, paging, empty state; export and every route honour own/branch/agency scope; an out-of-scope id is 404.
9. Every setting changes behaviour and appears on the settings page **and** in the wizard; a new agency on defaults works; no HFC text anywhere.
10. Phone-viewport check and a no-CDN render check (Puppeteer request-interception) pass.

### 12. Tests (targeted, one file at a time)
Page choice (rental vs sale), counts (each status; draft/sent not received; archived excluded; period boundary; unknown start → "to date"), privacy grep tests, concluded wording (let vs sale),
hashing/expiry/renewal/rotation/back-fill, removed-owner & revoke rules for owner links, auto-create without secret, list search/sort/filter/page/scope/export, settings + wizard `has()`-guard, sale-page regression render.

### 13. Files (proposed — the build confirms exact paths)
New: migration (`property_seller_links` columns + rental back-fill); `resources/views/owner-link/live.blade.php`; `app/Http/Controllers/CoreX/RentalOwnerLinkController.php` (list/issue/extend/revoke/export); an `OwnerLinkSummaryService` (the three counts — a thin
caller of `getPortalEngagementSeries`, `PropertyViewings`, and a `RentalApplication` count query); two Mail/Share helpers; events; tests.
Modified: `SellerLinkController` (page choice + kind-aware concluded branch + hashed lookup/expiry for owner kind); `PropertySellerLink` (`link_kind`, `issueFreshToken()`, `scopeUsable()`); `corex/properties/show.blade.php` (rental box); `RentalPortalSetting` + `RentalPortalSettingsController`; `config/agency-onboarding-copy.php` (`leases` step); `config/corex-permissions.php`; Rentals sidebar; `corex-domain-events-spec.md`; `seller-live-link.md` (cross-reference + the corrected "rentals" note).

### 14. OPEN — business questions for Johan (plain; nothing technical)
1. When a property is **let**, should the owner's link (a) just say "This property has been let" (this draft), or (b) keep showing the **final numbers** ("12 enquiries, 4 viewings, 3 applications before it was let")?
2. Should the owner see the **declined** count at all? (This draft: yes, an agency can switch it off.)
3. How long should an owner link last if nobody opens it? (This draft: 60 days, renewed each time it is opened; the agency can change it.)

### 15. Not in this spec
Owner link for a **sale** (unchanged, no hashing change); owner-visible applicant details of any kind; viewing feedback for rentals; per-application timelines; owner login (the portal already covers authenticated owners); changing what `TransactionStateService::linkedPropertyIds()` counts as "owned" (it unions un-revoked seller links — flagged in `seller-live-link.md`, deliberately not touched).
