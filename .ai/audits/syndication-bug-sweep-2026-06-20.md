# Audit — P24 / Private Property / Agency-Website bug sweep

**Date:** 2026-06-20
**Author:** Andre (Claude, Opus 4.8) — three parallel review passes
**Trigger:** After fixing the mandate-expiry de-syndication gap
(`.ai/audits/mandate-expiry-desyndication-2026-06-20.md`), Johan asked for a full
bug sweep of the P24, Private Property and custom-website code.

Each finding was substantiated by reading the code. Mandate-expiry desync is NOT
re-listed (fixed separately).

**UPDATE 2026-06-20 — all CRITICAL + HIGH findings now FIXED** (Johan: "start with
high and fix"). See the ✅ markers below. MEDIUM/LOW remain open. Tests:
`tests/Feature/Syndication/{OffMarketDelist,Property24ActivationReconcile,Property24ObserverStatusSync,PrivatePropertyEventFeed}Test.php`,
`tests/Feature/Website/WebsiteAgentVisibilityTest.php`,
`tests/Feature/Mandate/MandateExpiryDesyndicationTest.php` — all green (26 tests).
The expiry job was generalised: `App\Jobs\Mandate\DesyndicateExpiredMandateJob` →
`App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob($property, bool $removeFromWebsite)`.

---

## Property24

### P24-1 — CRITICAL — `getDirty()` in `saved()` made ALL P24 auto-sync dead code  ✅ FIXED
`app/Observers/PropertyObserver.php` (~line 353). The P24 status/field auto-sync
block read `$property->getDirty()`, but `saved()`'s first call
(`onPropertyUpdated` → `updateQuietly(last_activity_at)`) runs a nested save that
calls `syncOriginal()`, so `getDirty()` is already empty there. Net effect: when
an agent marked a syndicated property Sold/Withdrawn/Under-Offer, or edited
price/description/photos, **nothing was ever pushed to P24**. Every other change
check in `saved()` correctly uses `getChanges()`.
**Fix shipped:** changed to `getChanges()` (retains `status` because it was still
dirty during the nested save) + regression test
`tests/Feature/Syndication/Property24ObserverStatusSyncTest.php`.

### P24-2 — HIGH — `is-on-portal` boolean response mis-parsed; activation never reconciles  ✅ FIXED
`Property24SyndicationService.php:236-248`. When P24 returns a bare JSON boolean,
the client wraps it as `'data' => true|false`; then `$data['raw'] ?? …`
array-accesses a scalar → `null`, so neither branch fires. Listings stay pinned
at `submitted`/`pending` forever; `syncAllActivations()` re-checks them with no
effect. **Fix:** `$isOnPortal = is_bool($data) ? $data : ($data['raw'] ?? $data['isOnPortal'] ?? $data['IsOnPortal'] ?? null);`

### P24-3 — MEDIUM — `active` listings never re-verified, so portal removals desync silently  ✅ FIXED (bundled with P24-2)
`Property24SyndicationService.php:258-262`. `syncAllActivations()` only selects
`submitted`/`pending`. Once `active`, a listing is never re-checked, so a P24-side
removal (expiry, moderation) leaves CoreX showing `active` indefinitely.
**Fix:** include `'active'` in the `whereIn` for periodic re-verification.

### P24-4 — MEDIUM — first-time submit can POST an invalid create status
`Property24ListingMapper.php:51-57` + `getP24Status()`. On first submission
(no `p24_ref`) `map()` only rewrites status when it equals `NewListing`. A first
create whose CoreX status is `draft` → POSTs `status: 'Withdrawn'` (and
under-offer/reduced/raised map to non-`NewListing` too). **Fix:** force a valid
create status (`NewListing`/`Active`) on first submission, or gate submission to
on-market statuses.

### P24-5 — LOW — lead envelope/dedup edges
`P24LeadService.php:346-358, 195-208`. `extractLeads()` final `return $payload`
can return a non-list (iterated as if each top key were a lead); `isDuplicate()`
matches on `listing_portal_ref = null` within a ±1-min window, so distinct
no-ref enquiries sharing email/phone can be wrongly deduped. **Fix:** return `[]`
for unrecognised payloads; skip the ref clause when ref is null.

---

## Private Property

### PP-1 — CRITICAL — sold/withdrawn/under-offer re-advertised as ForSale on every refresh  ✅ FIXED
**Fix shipped:** (a) `PrivatePropertyListingMapper::mapPropertyStatus()` maps off-market
statuses → `Inactive` (the only off-market submission enum PP documents) so a stray
submit/refresh can never re-advertise a dead listing; (b) generalised off-market delist —
`PropertyObserver::saved()` now dispatches `DesyndicatePropertyFromPortalsJob` on any
off-market status transition (PP always deactivated; website removed for
withdrawn/expired/cancelled, KEPT for sold/rented showcase); (c) the website feed now also
excludes `withdrawn` (belt-and-braces).
`PrivatePropertyListingMapper.php:31, 64-65`. `PropertyStatus` is hardcoded from
`listing_type` (`ForSale`/`ToLet`); `$property->status` is never read. The payload
is sent on every `submitListing()` (incl. the agent "Refresh to portal" button),
so a Sold/Under-Offer/Withdrawn property is re-published as live. `reactivateListing()`
also unconditionally sets `ForSale`. **Same class as the mandate-expiry bug** —
and PP has NO observer-driven delist at all, so manual sold/withdrawn never comes
off PP. **Fix:** make the mapper status-aware (map to `Sold`/`PendingOffer`/`Inactive`),
and wire terminal-status transitions to `deactivateListing` (see recommendation).

### PP-2 — HIGH — multi-agency event feed only ever polls the FIRST PP agency  ✅ FIXED
**Fix shipped:** `ProcessPrivatePropertyEventFeed` now iterates every enabled PP agency,
binds `forAgency()` per iteration, and keys the continuation cursor per branch GUID
(`continuation_key:{guid}`). `syncActivationStatus` now binds `forAgency($property->agency)`
so the activation-sync job is correct per-agency too.
`ProcessPrivatePropertyEventFeed.php:23-37`, `SyncPrivatePropertyActivations.php`,
`PrivatePropertyConfig.php:35-49`. The scheduled jobs never call
`$client->forAgency()`; with no `Auth::user()` in queue context, config falls to
`PrivatePropertyConfig::for(null)` → the single first `pp_enabled` agency. The
continuation cursor in `pp_event_feed_settings` is also a single global row. In
any multi-agency deployment, other agencies' branches are never polled (their
`pp_ref`/feed refs never populate; image-error tasks never fire), and
`SyncPrivatePropertyActivations` calls P24… PP under the wrong branch GUID/token.
**Fix:** iterate every enabled PP agency, `forAgency()` per iteration, key the
cursor per branch GUID.

### PP-3 — HIGH — final/non-advancing event-feed page is silently dropped  ✅ FIXED
`ProcessPrivatePropertyEventFeed.php:52-63`. `processEvents()` runs only inside
`if ($newKey && $newKey !== $key)`. When PP returns a page whose `ContinuationKey`
doesn't advance (typical on the last page) or is empty, those events are
discarded and never retried (loop also breaks on `count < 100`). **Fix:** process
events whenever a page has any; use key comparison only to decide whether to keep
paging.

### PP-4 — HIGH — first-poll backfill broken (`'0'` continuation key)  ✅ FIXED
`ProcessPrivatePropertyEventFeed.php:29-37` + `PrivatePropertySoapClient.php:199-208`.
On first run it sends `continuationKey = '0'` *together with* `startDateTime`. PP
honours `startDateTime` only when no key is supplied, so the intended 2-day
historical catch-up is ignored. **Fix:** send an empty key on first poll; only
send a real key once PP issues one.

### PP-5 — MEDIUM — SOAP outage in activation sync looks like "not yet active"
`PrivatePropertySoapClient.php:101-124`, `PollPrivatePropertyActivation`. A SOAP
error returns `['error'=>true]`/`success=false` without throwing, so the poller
can't distinguish a transport failure from "not live yet" and can exhaust its
backoff during an outage. **Fix:** propagate the SOAP-error case so the poller
keeps trying.

### PP-6 — MEDIUM — agent images silently skipped on non-HTTPS base
`PrivatePropertySyndicationService.php:537-558`. Agent photos are skipped (not
errored) when the image base is `http://`; the listing still reports success. On
any http `APP_URL` env every agent photo is silently dropped. **Fix:** surface a
hard config error (or force https).

### PP-7 — MEDIUM — inconsistent Sale/Rental derivation targets wrong PP record  ✅ FIXED
**Fix shipped:** one shared source of truth — `PrivatePropertyListingMapper::resolveListingType()`
(prefer `listing_type`, fall back to `mandate_type`) — now used by the submit payload AND
all five follow-up calls (deactivate, reactivate, GetReferenceNumberByListing, video push,
unique-id update). A sole rental now deactivates as `Rental`, hitting the same
`(PropertyId, ListingType)` record it was submitted under. Tests:
`tests/Unit/PrivateProperty/PpListingTypeResolutionTest.php`,
`tests/Feature/Syndication/PrivatePropertyDeactivateTypeTest.php`.
`PrivatePropertySyndicationService.php` — `deactivateListing`/`reactivateListing`
use `mandate_type` only; others use `listing_type` (or `mandate_type ?? listing_type`).
A sole rental (`listing_type=rental`, `mandate_type=sole`) is deactivated as
**Sale** but ref/video pushed as **Rental**; PP keys on `(id, listingType)`, so
the deactivate targets the wrong record and the rental stays live. **Fix:**
centralise listing-type resolution in one helper used everywhere.

### PP-8 — MEDIUM — premature `active` from a synchronous PPRef bypasses the poller
`PrivatePropertySyndicationService.php:111-115`. If `UpdateListing` echoes a
`PPRef`, status is set `active` immediately, but PP activation is asynchronous; the
controller then skips starting the poll, so a stale/echoed ref means the listing
never reconciles. **Fix:** treat submit as `submitted`; let the feed/poller flip
to `active`.

### PP-9 — LOW — loose `PPRef` extraction can latch onto an unrelated scalar
`PrivatePropertySyndicationService.php:758-795`. The extractor scans the whole
response for any `PPRef`/`ListingFeedRef` key. **Fix:** restrict to the known
`UpdateListingResult` container.

### PP-10 — LOW — `parseStreetName` sends the whole address as StreetName
`PrivatePropertyListingMapper.php:670-687`. With no leading number it returns the
full address (e.g. a bare suburb) as StreetName. **Fix:** return empty so the
readiness gate blocks instead.

---

## Agency website (Public API)

### WEB-1 — HIGH — deactivated agents (`is_active = false`) still served publicly  ✅ FIXED
**Fix shipped:** `is_active = true` added to `AgentsController` (index + show) and
`BranchesController::hydrate`; `ListingResource` now omits a deactivated agent from the
single `agent` object and the `agents[]` array.
`Api/V1/Website/AgentsController.php:28-31, 53-57`; `BranchesController.php:87-93`;
listing `agent`/`agents[]` cards. These filter only `agency_id` + `show_on_website`,
never `is_active`. Deactivation doesn't clear `show_on_website`, so an agent who
left the agency stays visible on `/agents`, `/branches`, and every listing card.
(Soft-deleted agents are correctly hidden.) **Fix:** add `->where('is_active', true)`
wherever `show_on_website` is filtered, or clear `show_on_website` on deactivation.

### WEB-2 — MEDIUM — listings pagination has no tiebreaker → rows skipped/duplicated
`Api/V1/Website/ListingsController.php` (`->latest('published_at')`).
`published_at` is nullable and often shared (bulk activate), so a paginated crawl
can drop/duplicate rows. Articles/Testimonials controllers already add a stable
secondary sort. **Fix:** `->latest('published_at')->orderByDesc('id')`.

### WEB-3 — LOW — testimonial/article agent linkage ignores agent visibility
`WebsiteApi/TestimonialResource.php:29-32`; `ArticlesController.php` + `ArticleResource.php`.
Emit `agent_id`/agent card regardless of the agent's `show_on_website`/`is_active`,
producing dangling `/agents/{id}` links (compounds WEB-1). **Fix:** only surface
the agent card when the agent is `show_on_website && is_active && !trashed`.

**Clean (reviewed, no issues):** `AgencyApiKeyResolver` (constant-time secret
check, revoked/expired enforced), `AgencyApiKey` (encrypted/hidden secrets),
`EnsureAgencyWebsiteLive` / `EnsureWebsiteApiScope`, the rate limiter, all four
`Dispatch*Webhooks` listeners (master switch + scope + per-agency fan-out honoured),
`DeliverAgencyWebhook`/retry sweep (HMAC-SHA256, bounded retries, no re-throw),
`ListingResource` (no owner/PII/credential leak; image URL builder correct),
`WebsiteSyndicationService` (withoutGlobalScope always re-constrained by agency).
**No cross-agency auth/tenancy leak, no PII/credential leak, no webhook
retry-forever/double-fire/master-switch bypass was found.**

---

## Confirmed off-market syndication policies (locked by Johan, 2026-06-20)

These are the intended per-portal behaviours when a property leaves the market.
Codified in `PropertyObserver` (status-sync + off-market dispatch),
`DesyndicatePropertyFromPortalsJob`, `PrivatePropertyListingMapper::mapPropertyStatus`,
and the website feed filter.

| CoreX status | Property24 | Private Property | Agency website |
|---|---|---|---|
| sold / rented | set to `Sold`/`Rented` (stays, badged) | **deactivated** (`Inactive`) | **KEPT** — showcase (bulkActivateSold) |
| withdrawn / expired / cancelled / archived | `Withdrawn`/`Expired`/`Cancelled` | **deactivated** (`Inactive`) | **REMOVED** (pivot disabled + feed excludes expired/withdrawn) |
| under offer / pending | `Pending` | stays live (`ForSale`/`ToLet`) | stays live |

Rationale for the one asymmetry (sold): **P24 shows the listing as Sold; PP removes
it** — PP's submission contract only documents `ForSale | ToLet | Inactive`, so we
cannot safely submit a `Sold` value to PP. If PP later confirms a `Sold` submission
value, revisit `mapPropertyStatus` to badge sold on PP too.

Contract checks behind these (verified 2026-06-20): P24 `is-on-portal` returns a bare
JSON boolean (swagger §`/is-on-portal`); PP takedown is `ListingStatusUpdate` with
`PropertyStatus=Inactive`; `Inactive` is a valid `PropertyStatus` enum value (shared
WSDL type). NOT yet round-tripped against a live P24/PP sandbox.

## Status / remaining work

**DONE (2026-06-20):** P24-1, P24-2, P24-3, PP-1, PP-2, PP-3, PP-4, WEB-1 — every
CRITICAL and HIGH finding, plus the generalised off-market delist.

**Still open (MEDIUM/LOW) — for a later pass:**
- PP-5 (SOAP outage looks like "not yet active" in activation sync)
- PP-6 (agent images silently skipped on non-HTTPS base)
- PP-8 (premature `active` from synchronous PPRef bypasses poller)
- PP-9 (loose `PPRef` extraction)
- PP-10 (`parseStreetName` sends whole address)
- P24-4 (first-time submit can POST invalid create status)
- P24-5 (lead envelope/dedup edges)
- WEB-2 (listings pagination tiebreaker — quick win, recommend doing next)
- WEB-3 (testimonial/article agent linkage ignores visibility)
