# Private Property Lead Capture — Parity Research

> Read-only research. NO code changes. Answers Johan's question: *"On P24 we get lead
> information — is there anything on PP that does the same?"*
> Author: cc2. Date: 2026-07-06. Status: research report, decision pending.

---

## TL;DR

**Yes — PP offers lead data, and more completely than we currently use.** Three facts:

1. **P24 leads reach us via a v53 API pull** every 5 min into a unified `portal_leads` table (17 real leads live, 20–27 May). That is the baseline.
2. **PP's own SOAP service — the exact same Rev 4.6 feed we already use for syndication — exposes a matching lead-pull operation (`ListingLeadDetailsFeed`) and per-listing engagement stats (`ListingPerformanceStats`).** We have the SOAP client, token, and config already wired. We have simply **never called those two operations.**
3. **A PP push-webhook (`POST /api/pp/webhook`) is already built** but is producing **zero** leads — never registered on PP's portal / nothing arriving.

Leads already have a home: the unified `portal_leads` ledger + `NewPortalLeadReceived` event that *both* portals feed. The only open question is how PP leads get into it reliably. The clean answer is a SOAP lead-pull sibling to `P24LeadService`.

---

## Truth 1 — The P24 baseline (what "we get lead info on P24" actually is)

**Mechanism: API pull. Not email, not scrape, not webhook.**

- `Property24ApiClient::getLeads()` → `GET /listing/v53/listings/leads?after=<iso8601>`, HTTP Basic Auth (`Property24ApiClient.php:330`).
- Scheduled `PullP24LeadsJob` **every 5 min** (`routes/console.php:145-150`; spec says 15 min — drift) → `P24LeadService::pullForAllAgencies()`.
- `P24LeadService::processLead` (`P24LeadService.php:128-202`): dedup → resolve Property via `TrackedPropertyMatchOrCreateService` (rule #10) → resolve/create **Buyer** Contact via `ContactDuplicateService` → save `PortalLead(portal='p24')` with full raw payload → `event(NewPortalLeadReceived)` (line 199) → `BuyerLeadCascadeService::seedFromListing` (buyer pipeline) + email-to-agent + mobile push + agency toast.
- Separate **daily stats pull**: `PullP24StatsJob` (`routes/console.php:157`, 04:00) → `P24StatsService::getListingStatistics()` → `property_portal_metrics` (views / alerts / lead counts). See `.ai/specs/portal-metrics.md`.
- **Red herring:** `p24:import` (IMAP, `no-reply@property24.com`) parses **competitor listing alerts** → `p24_listings`. It extracts listingNumber/price/beds, **never a buyer's name/phone/email**. Not a lead channel.

**Proof it flows:** `portal_leads` holds 17 P24 rows (20–27 May). Real lead `lead_source_raw`:
```json
{"date":"2026-05-20T15:21:56+02:00","name":"Andre Roets",
 "message":"I'm interested in this property, please contact me.",
 "emailAddress":"a.roets12@gmail.com","contactNumber":"081 323 0105",
 "listingNumber":100314494}
```
Maps 1:1 onto `portal_leads.name / email / phone / message / listing_portal_ref`.

**The pattern a PP equivalent must match:** provider API client (`getLeads`, cursor in cache) → `*LeadService::processLead` (dedup → property match-or-create → contact resolve/create-as-Buyer → save `PortalLead` → cascade → fire event) → scheduled `Pull*LeadsJob`.

---

## Truth 2 — PP SOAP's full menu

Source: `storage/pp-agentimport.wsdl` (PP **AgentImport Rev 4.6**). Configured endpoint (`config/services.php:131`):
`https://services.sandbox.pp.co.za/AgentImport/AgentImport.asmx?WSDL` — **⚠ SANDBOX default; confirm live `PP_WSDL` points at production.**

**43 operations total.** The ones that matter for this question:

### Lead-shaped (the money)
| Operation | Signature | Returns |
|---|---|---|
| **`ListingLeadDetailsFeed`** | `(dateTime StartDate, SecurityToken Token)` | `ArrayOfListingLeadDetail` |
| `LeadStatDetail` | `(string UniqueListingId, guid BranchGuid, dateTime StartDate, dateTime EndDate, Token)` | lead stats per listing |
| `LeadStatSummary` | `(guid BranchGuid, dateTime StartDate, dateTime EndDate, Token)` | branch lead stats |

**`ListingLeadDetail` fields** = a direct structural twin of P24's `getLeads` payload:
`LeadId, Date, BranchId, UniqueListingId, PPRef, FromEmail, FromName, FromContactNumber, ToEmail, Message, PropertyRefs[]` — name, email, phone, message, listing ref: everything `portal_leads` needs.

### Stats-shaped (P24-parity engagement metrics)
| Operation | Signature | Returns |
|---|---|---|
| **`ListingPerformanceStats`** | `(dateTime Date, SecurityToken Token)` | `ArrayOfListingPerformanceStatsOnDate` |

**`ListingPerformanceStatsOnDate` fields**: `Date, Messages, TelLeads, Views, Alerts, PropertyRef` — a direct twin of P24 stats → `property_portal_metrics`.

### Listing-status / update (their side → us)
`GetListingEventFeedByBranch` (**we use this** for `pp_syndication_status`), `GetListingEventFeedByFeedProvider`, `GetListingStatus`, `GetListingStatusVerbose`, `ListingStatusUpdate`, `GetActiveListings`, `GetExpiringListings`, `GetExpiredListings`, `ShowHideListingContactDetails`, `ListingShowdayUpdate`, `ListingAuctionDetailsUpdate`.

### Everything else (agents / branch / locations / widgets / venues)
`GetAgent(s)`, `GetAllAgentsForBranch`, `UpdateAgent`, `UpdateAgentImage`, `GetAgentImageLocation`, `GetBranchDetails`, `UpdateBranch`, `GetCountries/Provinces/Cities/Suburbs`, `GetWidgetUrl`, `VenueDetailsAdd/Get`, `UpdateListing`, `UpdatePropertyAttributes`, `UpdateListingVideoOrMatterport`, `UpdateUniqueAgentID/ListingID`, `GetReferenceNumberByListing`, `ListingSummary`, `FullBranchListingsDetailsWithMandateID`, `GetFullDetailsOfAllListingsByBranch`, `GetAllListingsForBranch`, `GetAllSubmittedListingsForBranch`, `GetListingsDetails`.

### What our code uses today
`GetActiveListings`, `GetListingStatus`, `ListingStatusUpdate`, `UpdateListing`, `GetListingEventFeedByBranch`, `GetBranchDetails`, `UpdateAgent`/`UpdateAgentImage`, agents, locations.
**NONE of the 4 lead/stats operations are called anywhere in `app/`.** (grep: zero references to `ListingLeadDetailsFeed`, `ListingPerformanceStats`, `LeadStatDetail`, `LeadStatSummary`.)

**Stored spec gap:** `.ai/specs/private-property.md` documents the webhook (§11) but does **not** mention the lead-pull or performance-stats operations — they exist in the WSDL, undocumented in our spec, unbuilt.

---

## Truth 3 — Is a PP/P24 lead email already flowing into the archive?

**No, and by design it never will.**

- Archive = table `communications` (model `App\Models\Communications`). Dev DB empty (can't sample live).
- **Config actively blocks it.** `CommunicationIngestFilter` + `config/communications.php`:
  - `ingest_blocklist_domains` **explicitly lists `privateproperty.co.za` and `property24.com`** (line ~143, "Property portals / industry notification senders").
  - `ingest_noreply_local_parts` drops `no-reply` / `notification` / `notifications` (lines ~131–134) — catches `no-reply@property24.com`.
  - Only escape is "matches a CoreX contact" — a portal no-reply never does.
- So even with live mailboxes polling, PP/P24 lead notification emails are **deliberately logged-and-dropped**, never stored.
- Verdict: **do not mine leads from the archive.** The emails aren't there and are blocklisted. Structured leads already live in `portal_leads` (cleaner than any email parse).

---

## Truth 4 — Options, sized

**Reframe:** leads already have a home (`portal_leads` + `NewPortalLeadReceived`, fed by both portals; dedup by `(portal, listing_portal_ref, received_at)`). The question is only how PP leads reliably get in. Current PP state: webhook built, **0 leads captured**.

### (a) SOAP lead-pull — ✅ RECOMMENDED. Size ~S/M.
Add `listingLeadDetailsFeed()` + `listingPerformanceStats()` to the existing `PrivatePropertySoapClient`; a `PpLeadService` mirroring `P24LeadService`; a `PullPpLeadsJob` on a 5–15 min schedule writing `PortalLead(portal='pp')` and firing the same event; optionally a `PullPpStatsJob` → `property_portal_metrics`.
- Reuses **all** existing PP auth / token / config / SOAP client. No new integration surface.
- No dependence on PP-side webhook registration.
- Delivers leads **and** P24-parity engagement stats (Views / Messages / TelLeads / Alerts).
- Makes PP a first-class sibling of P24. **One-word-able: "PP lead pull."**

### (b) Email-parse off the archive — ❌ NOT for PP. Size ~M, wrong shape.
Would require lifting the portal blocklist **and** building a parser **and** deduping against the API. Strictly worse than (a) for PP (SOAP already gives clean structured data). *Only* merit: a generic template for a future portal that has **no** API/feed. Note it; don't build it for PP.

### (c) Fix the existing webhook — ⚠ Cheap complement, not a substitute. Size ~XS.
Register `https://corex.hfcoastal.co.za/api/pp/webhook` in PP's admin portal + confirm `PP_WEBHOOK_SECRET`. Near-zero code; leads start pushing. **But:** no historical backfill, no stats, push-only, entirely dependent on PP's portal staying correctly configured (which is exactly why it currently captures nothing).

### Recommendation
**(a) as primary + (c) as a belt-and-braces safety net; skip (b) for PP.** Both (a) and (c) land in the same `portal_leads` ledger and dedup against each other automatically.

---

## PP-side actions to confirm with Private Property (flag for Johan/Andre)

The WSDL exposes the operations, but these are account/portal-level settings the docs are silent on — ask PP:

1. **Is the lead feed (`ListingLeadDetailsFeed`) + `ListingPerformanceStats` enabled** for HFC's branch / feed-provider account on Rev 4.6? (Some PP accounts have the feed gated on.)
2. **Production endpoint + credentials.** Our `PP_WSDL` defaults to the **sandbox** host — confirm the live production WSDL URL and that live `.env` overrides it.
3. **Lead-feed lookback ceiling** (P24's is 30 days) — needed to initialise the pull cursor safely.
4. If using the webhook too: **register the webhook URL** in PP's admin portal and confirm the shared HMAC secret.

---

## Key files (reference)

- P24 baseline: `app/Services/Syndication/Property24/P24LeadService.php`, `.../Property24ApiClient.php:330`, `app/Jobs/Syndication/Property24/PullP24LeadsJob.php`, `routes/console.php:145-170`
- Shared ledger: `app/Models/PortalLead.php`, `app/Events/Leads/NewPortalLeadReceived.php`, `app/Observers/CommandTaskPortalLeadObserver.php`, `.ai/specs/portal-leads.md`
- PP SOAP: `app/Services/PrivateProperty/PrivatePropertySoapClient.php`, `.../PrivatePropertyConfig.php`, `storage/pp-agentimport.wsdl`, `config/services.php:131`
- PP webhook: `app/Http/Controllers/PrivateProperty/PpWebhookController.php`, `.ai/specs/private-property.md` §11
- Archive block: `app/Services/Communications/CommunicationIngestFilter.php`, `config/communications.php`
