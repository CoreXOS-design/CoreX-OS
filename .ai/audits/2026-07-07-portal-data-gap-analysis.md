# Portal Data Gap Analysis — what P24 & Private Property offer vs. what we use

> **For Johan & Andre.** Read-only research — no builds. The question: *"what do they all offer and what don't we have yet? What other data can we get and use?"*
> Prepared 7 July 2026. Sources: the PP Agency Feed WSDL (Rev 4.6, `storage/pp-agentimport.wsdl`), the P24 ExDev Listing Service v53 Swagger (`storage/p24_swagger.json`), and our own client code.

---

## TL;DR

- **Private Property** exposes **43 operations. We use 22, leave 21 unused.** Of the 21, ~5 have real business value; the rest is plumbing (agent/branch admin, ID mapping, ping).
- **Property24 (ExDev v53)** exposes **33 endpoints. We use ~20, leave ~13 unused.** Two of the unused are genuinely valuable — a **bulk statistics** endpoint and a **reconciliation** endpoint.
- **Good news on docs:** we hold the full P24 Swagger, so the P24 surface is fully known — no "ask P24 support to find out what exists." (A couple of *higher-tier data* questions are still worth asking — noted below.)
- **The single biggest finding:** P24 has an **agency-wide bulk `/listings/statistics`** endpoint. Our new stats sweep (AT-200) calls the *per-listing* one 186 times. The bulk endpoint would return everything in one shot — 100% coverage, no starvation, far fewer calls. Worth a follow-up.
- Most of the 43+33 are **plumbing with zero standalone business value** — this menu does not pad them.

---

## 1. Private Property — full menu (43 operations)

### ✅ USED (22)
| Operation | Powers |
|---|---|
| UpdateListing, UpdateListingVideoOrMatterport, ListingStatusUpdate, ListingShowdayUpdate, ListingSummary, GetReferenceNumberByListing, GetListingStatus, GetActiveListings | **Syndication** (listing push, status, show-days) |
| GetListingEventFeedByBranch | **Syndication** — listing lifecycle events (activated / deactivated / image-error) |
| ListingLeadDetailsFeed | **AT-199** — buyer-lead pull → Portal Leads |
| ListingPerformanceStats | **AT-201** — nightly engagement snapshot (views/enquiries) |
| UpdateAgent, UpdateAgentImage, GetAgent, GetAllAgentsForBranch, UpdateUniqueAgentID, UpdateUniqueListingID, GetBranchDetails | Agent/branch admin for syndication |
| GetCountries, GetProvinces, GetCities, GetSuburbs | Location reference data |

### ⚪ UNUSED (21) — grouped
**Listing hygiene / reconciliation (real value):**
- **GetExpiringListings** — listings about to expire on PP
- **GetExpiredListings** — listings that have expired on PP
- GetAllListingsForBranch, GetAllSubmittedListingsForBranch, GetFullDetailsOfAllListingsByBranch, GetListingsDetails, FullBranchListingsDetailsWithMandateID — bulk listing retrieval (reconcile CoreX ↔ PP)

**Lead / stats extras (mostly covered by AT-201):**
- LeadStatSummary, LeadStatDetail — aggregate lead counts per branch/listing over time

**Marketing / config (minor):**
- GetWidgetUrl — embeddable PP listings/search widget for the HFC website
- ShowHideListingContactDetails — toggle contact-detail visibility on a PP listing
- GetListingStatusVerbose — richer status detail than GetListingStatus
- UpdatePropertyAttributes — patch listing attributes without a full re-submit
- ListingAuctionDetailsUpdate — auction-listing support (niche)
- VenueDetailsAdd / VenueDetailsGet — show-day venue records

**Pure plumbing (no business value):**
- GetAgents, GetAgentImageLocation, UpdateBranch, UpdateUniqueListingIDByPrivatePropertyRef, GetListingEventFeedByFeedProvider

### 🔵 BUILT-BUT-DORMANT — the PP webhook
- **`POST /api/pp/webhook`** — push-lead receiver. **Code exists, fully built** (HMAC-verified, creates Contact + CommandTask), but **never registered on PP's admin portal**, so it has captured zero leads. Complements AT-199's 5-min poll with *instant* delivery.

---

## 2. Property24 ExDev v53 — full menu (33 endpoints)

### ✅ USED (~20)
| Endpoint | Powers |
|---|---|
| POST /listings, PUT /listings/{n}/status, GET /listings/{n}/is-on-portal | **Syndication** (push + status) |
| GET /listings/leads | **Portal Leads** (P24LeadService) |
| GET /listings/{n}/statistics | **AT-200** — per-listing view/lead stats |
| POST/PUT /agents, GET /agents/{id}, PUT /agents/{id}/profile-picture, GET /agencies, /agencies/{id} | Agent/agency admin for syndication |
| GET /countries, /provinces, /cities, /suburbs, /suburbs/find, /suburbs/find-from-point, /listing-types, /property-types | Location & type reference data |

### ⚪ UNUSED (~13) — grouped
**Statistics & reconciliation (real value):**
- **GET /listings/statistics** — *agency-wide* stats for ALL listings in a date range (one call, not per-listing)
- **GET /listings/reconciliation** — `ReconciliatoryListingItems` = an authoritative "what is actually live on P24 right now" list to diff against CoreX
- **GET /listings/leads/statistics** + **/listings/leads/statistics-periods** — aggregate lead-volume summaries per period
- GET /statistics/last-update-date — when P24 last regenerated stats (poll only when fresh)

**Redundant with what we already pull:**
- GET /listings/{n}/leads — per-listing leads (we already pull agency-wide /listings/leads)
- GET /listings/updates — listing-status update feed (client method exists, `getListingUpdates`, but is not called by any live feature — dormant)

**Niche / plumbing:**
- GET /developments, /franchises, /franchises/{id} — development & franchise structure (only relevant if HFC sells developments / multi-franchise)
- GET /echo, /echo-authenticated, POST /echo-compressed — connectivity/auth ping (diagnostics only)

**Documentation gaps — what to ask P24 (via Andre's thread), honestly small:** the v53 Swagger is complete for *this tier*. The open questions are about **richer data that may sit behind a higher ExDev tier or a different product**: (a) do the statistics expose **referral sources / search-appearances vs. views** (not just totals)?; (b) is there **saved-search / alert-demand data per area** (buyer-demand intelligence)?; (c) any **listing-quality / completeness score**? None of these appear in v53 — confirm whether P24 offers them at all.

---

## 3. Business translation — one row per unused item worth pursuing

| # | Item (portal) | What data it yields | What it could power in CoreX | Effort | Dependencies |
|---|---|---|---|---|---|
| 1 | **P24 /listings/statistics** (bulk) | Every listing's views/leads for a date range in **one call** | Re-plumb the AT-200 stats sweep onto the bulk endpoint → **100% coverage instantly, no per-listing starvation, far fewer calls** | **M** | None (same creds/tier) |
| 2 | **P24 /listings/reconciliation** | Authoritative list of what's actually live on P24 now | **Listing-hygiene alerts**: CoreX↔P24 drift — listings live on P24 but "inactive" in CoreX (or vice-versa), orphaned/duplicated listings | **M** | None |
| 3 | **PP GetExpiringListings / GetExpiredListings** | Which PP listings are about to expire / have expired | **Mandate-expiry & re-list prompts** from the portal's own clock — feeds the agent's chore list before a listing silently drops | **S** | None |
| 4 | **PP webhook** (`/api/pp/webhook`) | PP pushes each lead the instant it happens | **Instant lead delivery** vs the 5-min AT-199 poll — seconds matter on a hot enquiry | **S** (code built) | **PP-side: register the URL + confirm HMAC secret** (Andre thread) |
| 5 | **P24 /listings/leads/statistics + periods** | Lead-volume totals & trends per period | **Demand dashboards & seller-report enrichment** ("enquiries trending up 30% this month"); agency-level lead-volume KPI | **S–M** | None |
| 6 | **PP bulk listing retrieval** (GetAllListingsForBranch etc.) | Full list/detail of everything HFC has on PP | PP-side **reconciliation** (the PP twin of #2) — drift & orphan detection | **M** | None |
| 7 | **P24 /statistics/last-update-date** | Timestamp of P24's last stats generation | Poll-efficiency — only pull stats when P24 has actually refreshed | **S** | None |
| 8 | **PP GetWidgetUrl** | An embeddable PP listings/search widget URL | A live "our listings" widget on the HFC public website | **S** | PP-side widget enablement |
| 9 | **PP LeadStatSummary / LeadStatDetail** | Aggregate PP lead counts over time | Marginal — largely covered by AT-201's Messages/TelLeads | **S** | None (low priority — redundant) |
| — | **P24 /developments** | New-development listing structure | Only if HFC lists developments | S | HFC sells developments |
| — | **Plumbing** (echo, franchises, agent/branch admin, ID-mapping, VenueDetails, ShowHide, per-listing leads) | Auth/ping, structure, config | **Nothing standalone** — infrastructure the used flows already lean on | — | — |

**No inflation:** items 9-and-below are marginal or redundant; the echo/franchise/admin/ID-mapping operations (a good third of both menus) are pure plumbing and are listed only so the menu is complete.

---

## 4. Top-5 shortlist (ranked value ÷ effort)

1. **P24 bulk statistics** (`/listings/statistics`) — *"Pull every listing's views in one call instead of 186 — 100% coverage, and it retires the starvation problem AT-200 worked around."* **HIGH / M.**
2. **P24 listing reconciliation** (`/listings/reconciliation`) — *"Ask P24 what's actually live and auto-flag anything that doesn't match CoreX — orphaned, mismatched, or silently-dropped listings."* **HIGH / M.**
3. **PP expiring/expired listings** (`GetExpiringListings`) — *"Portal-sourced mandate-expiry alerts — an agent gets a chore before the listing drops off PP, not after."* **HIGH / S.**
4. **Register the PP webhook** (`/api/pp/webhook`) — *"Flip PP leads from 5-minutes-late to instant. The code is already built; it just needs PP to register the URL."* **MED-HIGH / S** — needs the Andre→PP email.
5. **P24 lead-volume trends** (`/listings/leads/statistics`) — *"Turn raw enquiries into a demand trend line for seller reports — 'interest up 30% this month'."* **MED / S-M.**

**Andre email threads needed:** (a) **PP** — register `https://corex.hfcoastal.co.za/api/pp/webhook` + confirm the shared secret (item 4); confirm the widget product is enabled (item 8). (b) **P24** — confirm whether any *higher tier* exposes referral-source / search-appearance / saved-search-demand data beyond v53 (the doc-gap questions in §2).

---

## Appendix — counts
- PP: 43 operations · 22 used · 21 unused (~5 valuable, ~16 plumbing) · +1 dormant webhook.
- P24: 33 endpoints · ~20 used · ~13 unused (~4 valuable, ~9 plumbing/redundant).
- Both portals' **lead + stats plumbing is now wired** (AT-199 leads, AT-200/201 stats) — the remaining value is **reconciliation/hygiene, aggregate demand trends, expiry alerts, and instant webhook delivery**, not more raw per-listing data.
