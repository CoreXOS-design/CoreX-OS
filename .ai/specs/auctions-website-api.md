# Auction Website API — connect an external auction website to CoreX

> For the developer of the auction website. Everything the CoreX-hosted public auction page shows and does is available here as JSON.
> Design and decisions: `auctions.md` §24. Every endpoint is also listed (live) under **Admin → API** in CoreX.

## 1. The idea in one minute

- Your website has a **server** (not just browser code). That server calls CoreX with an **API key**. Never put the key in browser code.
- A **buyer** (a person bidding) signs up or logs in through your website. Your server forwards that to CoreX and gets back a **buyer token**. Your server keeps it for that visitor (in their session) and sends it with every buyer action.
- It is the **same account as the CoreX auction page**: sign up on your website and the same email and password log in on the CoreX page, and the other way round. A sign-up creates the same Contact in CoreX (assigned to the agency's admin, matched against people CoreX already knows, never duplicated), shows under Auctions → Bidders → Signed up, and the same emails and alerts happen.
- The seller questionnaire ("Sell at auction") works exactly like the page's: same fields, same checks, and it creates the same contact, tracked property and "Auction enquiry" property.
- Nothing is copied: your website always shows CoreX's current auctions, prices and bids. Poll the live feed (section 6) for bids.

## 2. Getting a key

CoreX → **Admin → Agencies → (the agency) → API Access**.

1. Make sure the agency's **Auctions** feature is on and the website master switch there is **LIVE**.
2. Press **Connect a website** (or **Connect another website** — an agency can have as many websites as you like, each with its own key, permissions, rate limit and on/off), give it a name, press the **Auction website** preset, and Generate. Copy the secret **now** — it is shown once.
3. You get a key like `cx_live_ab12cd34.<secret>`.

| Permission (scope) | Lets the website |
|---|---|
| `auctions:read` | read the site settings, auctions, lots, the live feed and documents |
| `auctions:buyers` | sign buyers up / log them in, let them register to bid, bid, auto-bid, watch, see their account and invoices |
| `auctions:leads` | send enquiries and the "Sell at auction" questionnaire |
| `agency:read` | (optional) the general agency branding endpoint (`GET /api/v1/website/agency`) |

A key can only ever see **its own agency**.

## 3. Calling the API

Base address: `https://<corex-host>/api/v1/website/auctions` (shown on the agency's API Access screen).

| Header | When | What |
|---|---|---|
| `Authorization: Bearer cx_live_...` | always | the website's key |
| `X-Buyer-Token: cxb_...` | for anything a signed-in buyer does (and optionally on reads) | the buyer's token (section 4) |
| `X-Visitor-IP: 41.1.2.3` | recommended | the real visitor's address, so rate limits, audit logs and the bidder's recorded address are the visitor's, not your server's. Only taken from a valid key |
| `Accept: application/json` | **always** | without it, a call with a missing or wrong key is redirected to the CoreX login page (as with the rest of the website API) instead of answering 401 JSON |
| `Content-Type: application/json` | for bodies | except registration, which is `multipart/form-data` |

**Responses** are JSON. Lists and single items are wrapped in `{"data": ...}`. Actions answer `{"ok": true, ...}`.

**Errors**

| Status | Body | Meaning |
|---|---|---|
| 401 | `{"message": "Unauthenticated."}` | no / wrong / revoked / expired API key |
| 401 | `{"message": "Please log in again.", "code": "buyer_token_invalid"}` | the buyer token is missing, expired, revoked or another agency's — send the buyer to log in again |
| 403 | `{"message": "This API key does not have the required scope: ..."}` | the key lacks that permission |
| 403 | `{"message": "This agency website is not currently live."}` | the agency's website master switch is off |
| 403 | `{"message": "Register for this auction before you bid."}` / `"Your registration is not approved yet."` | bidding rules |
| 404 | `{"message": ...}` | not found, not public, auctions switched off, or the "Sell at auction" form switched off |
| 422 | `{"message": "...", "errors": {"field": ["..."]}}` | validation — show each message next to its field |
| 429 | `{"message": "...", "code": "rate_limited"}` | too many attempts — wait a minute |

**Rate limits.** Every key has a per-minute limit (default 120, set on the key). Writes (sign-up, log-in, enquiries, the seller form, registration) are also limited **per visitor** (30 a minute per key and `X-Visitor-IP`), and bids to 30 a minute per buyer. Cache the read endpoints on your side (the site, the auction list) and poll the live feed only for the lot a visitor is looking at.

## 4. Buyer accounts and the buyer token

A token belongs to one buyer at one agency, lasts **30 days**, can be revoked (log out), and is accepted by this API only. Store it server-side against the visitor's session.

### Sign up (two steps — the buyer proves their email with a code)
`POST /buyers/signup/send` &nbsp;`{ "email": "thandi@example.com" }` → `{ "ok": true }` (the code is emailed, from the agency)

`POST /buyers/signup/verify`
```json
{ "email": "thandi@example.com", "code": "123456", "name": "Thandi Nkosi", "phone": "0821234567",
  "password": "at-least-8-characters", "password_confirmation": "at-least-8-characters", "popia_consent": true }
```
→ `201 { "ok": true, "token": "cxb_...", "expires_at": "2026-12-01T10:00:00+02:00", "buyer": { "email", "name", "phone" } }`

Errors: wrong/expired code → 422 `errors.code`; no consent → 422 `errors.popia_consent`.

### Log in
`POST /buyers/login` `{ "email", "password" }` → `{ ok, token, expires_at, buyer }`. Wrong details → 422 (`errors.email` carries the message, which also covers "too many attempts").

### Forgot password
`POST /buyers/forgot/send` `{ "email" }` → always `{ "ok": true }` (never reveals who has an account).
`POST /buyers/forgot/reset` `{ "email", "code", "password", "password_confirmation" }` → `{ ok, token, ... }` (signed in).

### Log out
`POST /buyers/logout` (with `X-Buyer-Token`) → `{ "ok": true }`. The token stops working.

### My account (token required)
`GET /buyers/me` → `data`: `buyer`, `registrations[]` (each with its `auction`), `lots_won[]` (lot card + `hammer_price`, `sold_subject_to_confirmation`), `watching[]` (lot cards), `invoices[]`.
`PUT /buyers/me` `{ "name", "phone" }` → `{ ok, buyer }`.
`GET /buyers/me/invoices/{id}/pdf` → the invoice PDF (only the buyer's own issued / part-paid / paid ones).

## 5. Reading the auctions (`auctions:read`)

**`GET /site`** — everything for the header, footer, colours and the home page: `agency` (slug, name, logo_url, email, phone), `site` (title, intro, hero_image_url, how_it_works, footer_text, contact_email, contact_phone, show_past_auctions, currency, public_page_url), `colors` (`brand`, `on_brand`, `button`, `on_button` — use these so the site wears the agency's colours and text stays readable), `features` (`seller_questionnaire`, `bidding_activity`, `bidder_numbers_visible`), and `seller_questionnaire` (null when the agency has switched the form off; otherwise the reply promise `response_text` and every option list the form needs).

**`GET /`** — the auctions the home page lists. `?phase=live|upcoming|finished`. Each: `id, reference, title, phase (live|upcoming|finished), phase_label, status, format (timed|live), starts_at, ends_at, venue_name, lots_count, image_url, public_page_url`. Drafts and cancelled auctions never appear. `phase` is worked out from the clock — use it, not `status`.

**`GET /{auction}`** — the auction page: the card fields plus `description, venue_address, stream_url, registration { open, closes_at, mine }, fee_notice, documents[], hidden_documents, lots[]`. `hidden_documents` is how many "registered only" documents a signed-in buyer would see — show "log in to see N more".

**`GET /{auction}/lots/{lot}`** — the lot page: lot card fields (`lot_number, title, status, suburb, city, opening_bid, guide_price_min/max, image_url, bedrooms, bathrooms, garages`) plus `description, floor_size_m2, photos[], documents[], hidden_documents, auction{}, fee_notice`, and the live part (below). The street address and the reserve price are never included, as on the page.

**`GET /{auction}/lots/{lot}/feed`** — only the live part, cheap to poll (every 3 seconds is what the page does):
```json
{ "data": {
  "bidding": { "lot_status": "open", "phase": "open", "server_now": "...", "opens_at": "...", "closes_at": "...", "seconds_left": 3600,
               "current_bid": 940000, "opening_bid": 900000, "min_next": 950000, "increment": 10000,
               "reserve": "not_met", "you": "winning", "currency": "ZAR", "bid_count": 7,
               "recent_bids": [ { "amount": 940000, "who": 4, "at": "...", "highest": true, "mine": false } ],
               "your_auto_bid": null },
  "my_registration": { "id": 12, "status": "approved", "bidder_number": 4, ... } ,
  "watching": false } }
```
- `phase`: `upcoming` / `open` / `ended`. Count down to `closes_at` using `server_now` as the clock (the page does the same), not the visitor's clock.
- A bid must be **at least `min_next`**; `increment` is the step in force (the page's "+ 10 000" buttons are 1×, 2×, 5×, 10× of it).
- `reserve` is `met` / `not_met` / `null` (null = the agency hides it, or there are no bids). The amount is never sent.
- `recent_bids` is absent when the agency switched "Bidding activity" off. `who` is the bidder number, or `null` when the agency hides bidder numbers. No names, ever.
- `you` is `winning` / `outbid` / `none` / `null` (null = not an approved bidder).

**Documents.** `documents[].download` is a path under the base address; request it **with your key** (and the buyer token for "registered" documents) and stream it to the visitor: `GET /{auction}/documents/{id}`, `GET /{auction}/lots/{lot}/documents/{id}`.

## 6. Registering to bid and bidding (`auctions:buyers`, buyer token required)

**`GET /{auction}/registration`** — what to show the registration form: `open`, `closes_at`, `registration` (the buyer's, or null), `required_documents.individual[]` / `.company[]` (`kind`, `label`), `on_file` (documents the buyer already gave this agency, by kind: `id`, `name`), `conditions_text`, `fee_notice`.

**`POST /{auction}/registration`** — `multipart/form-data`:

| Field | |
|---|---|
| `bidder_type` | `individual` or `company` (required) |
| `company_name`, `company_reg_no` | for a company |
| `accept_conditions` | must be true |
| `documents[<kind>]` | a PDF/JPG/PNG (max 10 MB) for each required kind |
| `existing[<kind>]` | instead of uploading: the id of a document `on_file` |

→ `201 { "ok": true, "data": { "id", "status": "pending", ... } }`. The agency is emailed and approves or declines in CoreX; the buyer is emailed their bidder number. Only an **approved** registration can bid. `GET /{auction}/registration` shows the status; a declined buyer can fix and register again.

**`POST /lots/{lot}/bids`** `{ "amount": 950000 }` · **`POST /lots/{lot}/auto-bid`** `{ "max": 1200000 }` · **`DELETE /lots/{lot}/auto-bid`** → `{ "ok": true, "data": { bidding, my_registration, watching } }` (the new live state).
Refused with 422 and a plain message when: the amount is under `min_next`, the buyer is already the highest bidder, bidding is not open, and so on — show the message. 403 when not registered / not approved.

**`POST /lots/{lot}/watch`** → `{ "ok": true, "watching": true }` (call again to stop watching).

Everything about bids is decided inside CoreX (soft close extends the closing time, auto-bids answer for the buyer, a lot is locked while two bids race), so the website only has to show `bidding` and surface the errors.

## 7. Enquiries and "Sell at auction" (`auctions:leads`)

**`POST /{auction}/enquiries`** and **`POST /{auction}/lots/{lot}/enquiries`**
`{ "name", "email" or "phone" (at least one), "message", "popia_consent": true }` → `201 { "ok": true }`. Creates the enquiry, matches or creates the contact (assigned to the listing agent), and tells the agent. Send `X-Buyer-Token` as well to link it to a signed-in buyer.

**`POST /sell`** — the seller questionnaire. All option lists and the reply promise are in `GET /site` → `seller_questionnaire`. JSON body (all fields exactly as the CoreX page's form; unknown fields are ignored):

| Section | Fields |
|---|---|
| About you | `seller_type` (`individual`, `married_in_community`, `company_or_trust`, `deceased_estate`) *required*; `spouse_name` (required if married in community); `executor_name` (required if deceased estate); `name` *req, max 120*; `email` *req*; `phone` *req, max 30*; `preferred_contact` (`phone`, `whatsapp`, `email`) |
| The property | `property_type` (`house`, `townhouse`, `apartment`, `vacant_land`, `farm`, `commercial`, `industrial`) *req*; `address` *req*; `suburb` *req*; `zoning`; `erf_size`, `floor_size` (whole m²); `bedrooms`, `bathrooms` (0–50); `features[]` (`pool`, `borehole`, `solar`, `garden_flat`, `electric_fence`, `fibre`, `staff_quarters`, `double_garage`) |
| Occupancy | `occupancy` (`owner`, `tenant`, `vacant`) *req*; `monthly_rent` (required if tenant); `condition` (`excellent`, `good`, `fair`, `needs_work`) *req*; `defects` (max 2000) |
| Bond & finances | `has_bond` (bool); `bond_bank` (required if `has_bond`); `bond_amount`; `bond_arrears` (bool); `levies`, `municipal_valuation`, `price_expectation` (whole rand, no symbols) |
| Auction options | `auction_format` (`online`, `live`, `hybrid`, `sealed_tender`) *req*; `reserve` (`confidential`, `none`) *req*; `timing` (`asap`, `1_3_months`, `3_6_months`, `exploring`) *req*; `viewing` (`most_days`, `appointment`, `restricted`) *req*; `sale_reason` (max 500) |
| Documents | `documents[]` (`title_deed`, `rates_account`, `building_plans`, `levy_statement`, `lease_agreement`, `bond_statement`, `id_copies`); `referral_source` |
| Consent | `consent_terms`, `consent_authorised`, `consent_processing` — all three *must be true*; `marketing_opt_in` |
| Spam trap | `website` — a hidden input a real person never fills; any value is rejected |

→ `201 { "ok": true, "message": "Questionnaire received.", "response_text": "within one business day" }` — show "An auctioneer will contact you {response_text}." 422 errors are per field (`errors.consent_terms` etc.); 404 when the agency has switched the form off.

What CoreX does with it (same as the page): stores it under **Auctions → Seller leads**; matches or creates the seller's contact (assigned to the admin); matches or creates the tracked property; creates a property with status **Auction enquiry** under Properties → "Auction enquiries" (only shown while Auctions is on); emails the team and alerts the administrators.

## 8. A typical visitor journey

1. Page load: `GET /site` (cache a few minutes) and `GET /` for the home page; `GET /{auction}` and `GET /{auction}/lots/{lot}` for detail pages.
2. Visitor clicks Sign up: `POST /buyers/signup/send` → they type the emailed code → `POST /buyers/signup/verify` → keep `token` in their session.
3. On an auction page: `GET /{auction}/registration` → `POST /{auction}/registration` (with their documents). They wait for approval (the agency approves in CoreX).
4. On a lot page: poll `GET /{auction}/lots/{lot}/feed` (with their token); when `my_registration.status` is `approved`, show the bid box. Pre-fill it with `bidding.min_next`; `POST /lots/{lot}/bids`.
5. My account: `GET /buyers/me`.

## 9. Security notes

- Keep the API key on your server. If it leaks, **Revoke** it on the agency's API Access screen (other websites of the agency keep working) and make a new one.
- Buyer tokens are hashed in CoreX, expire after 30 days and are revoked on log out; they open nothing but this API for one agency.
- Treat `X-Visitor-IP` as the visitor's address from your own trusted proxy (the one that terminates their connection), never a value the browser can set.
- Passwords are never stored by the website — send them to `/buyers/login` over HTTPS and forget them.
