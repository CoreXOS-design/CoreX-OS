# Private Property Rev 4.6 → 4.7 (Agency Feed Service shim) — Impact Investigation

> Investigation only when first run (2026-08-01) — working tree was verified untouched
> at that point (`git status --porcelain=v1 -uno` empty). Two fixes were subsequently
> approved by Johan and implemented in the same session; see §19 of
> `.ai/specs/private-property.md` for what shipped. This file is the frozen record of
> the original investigation.

**Context:** Private Property is re-implementing the Agency Feed Service (SOAP,
`AgentImport.asmx`) behind a shim. WSDL contract stays the same for core flows per PP's
own email (Ricky, 2026-08-01). Spec moves Rev 4.6 → Rev 4.7. Sandbox not yet available as
of 2026-08-01; production targeted August 2026, one-week sandbox test window promised
before promotion.

**Rev 4.7 change list (verbatim from PP, confirmed against their follow-up email
2026-08-01):**

1. `ListingLeadDetailsFeed` — added `LeadType` (`EmailLead`/`WhatsAppLead`), removed
   `ToEmail`.
2. `GetFullDetailsOfAllListingsByBranch` — now paginated, max 1000/page, new `Take`/`Skip`.
3. `LeadStatDetail` — removed `Work`/`To_Email`; leads now unique per listing (not per
   agent per listing); `LeadStatSummary` unchanged (enum divergence vs `LeadStatDetail`).
4. `GetListingEventFeedByFeedProvider` / `GetListingEventFeedByBranch` — all existing
   `continuationKey` values invalid after release; `ImagesDownloading`,
   `ErrorDownloadingImages`, `ImagesDownloaded` event types removed.

---

## A. Inventory

### A1 — Every PP integration file

**Config / credentials**
- `config/services.php:158-165` — `private_property` block
- `app/Services/PrivateProperty/PrivatePropertyConfig.php` — per-agency DB columns over env
- `app/Models/Agency.php:201,362` — `pp_sandbox` + sibling `pp_*` columns

**Token generator**
- `app/Services/PrivateProperty/PrivatePropertyTokenService.php`

**SOAP client / transport**
- `app/Services/PrivateProperty/PrivatePropertySoapClient.php`

**Fault translation**
- `app/Services/PrivateProperty/PpFaultTranslator.php`

**Request builder / response mapper (listings)**
- `app/Services/PrivateProperty/PrivatePropertyListingMapper.php`

**Domain services (undocumented in the spec until this investigation — now fixed in
`.ai/specs/private-property.md` §18)**
- `app/Services/PrivateProperty/PrivatePropertySyndicationService.php`
- `app/Services/PrivateProperty/PpLeadService.php`
- `app/Services/PrivateProperty/PpStatsService.php`

**Jobs**
- `app/Jobs/SyncPrivatePropertyActivations.php`
- `app/Jobs/PollPrivatePropertyActivation.php`
- `app/Jobs/ProcessPrivatePropertyEventFeed.php`
- `app/Jobs/PrivateProperty/PullPpLeadsJob.php`
- `app/Jobs/PrivateProperty/PullPpStatsJob.php`

**Commands**
- `app/Console/Commands/PpManage.php`
- `app/Console/Commands/PpSmokeTest.php`
- `app/Console/Commands/BulkSyndicatePP.php`
- `app/Console/Commands/SyncPpLocations.php`

**Controllers**
- `app/Http/Controllers/PrivateProperty/SyndicationController.php`
- `app/Http/Controllers/PrivateProperty/PropertyPpController.php`
- `app/Http/Controllers/PrivateProperty/PpWebhookController.php`
- `app/Http/Controllers/PrivateProperty/AgentPpController.php`

**Models**
- `app/Models/Property.php` — `pp_*` columns
- `app/Models/PropertyWebsiteSyndication.php`
- `app/Models/PortalLead.php`
- `app/Models/PropertyPortalMetric.php`
- `app/Models/PpEventFeedSetting.php`

**Migrations** — 15 files under `database/migrations/*pp*`.

**UI**
- `resources/views/admin/pp/agent-mapping.blade.php`, `admin/pp/mapping-email.blade.php`
- `resources/views/admin/importer/pp-locations.blade.php`
- `resources/views/corex/portal-leads/index.blade.php`,
  `corex/properties/intelligence/_portal-leads.blade.php`,
  `components/portal-lead-toast.blade.php`

**Reference/dev files (not loaded at runtime)**
- `storage/pp-agentimport.wsdl`, `storage/pp-attributetype-enum.txt` — referenced only in
  code comments, never `file_get_contents`/`require`d.

### A2 — Web methods actually called today

| Method | Call site | Trigger |
|---|---|---|
| `GetBranchDetails` | `PrivatePropertySoapClient.php:145` | manual `pp:smoke-test`, admin "Test connection" |
| `UpdateAgent` | `PrivatePropertySoapClient.php:157` | `AgentPpController::sync`, auto-registration |
| `UpdateListing` | `PrivatePropertySoapClient.php:169` | `SyndicationController::submit`, `BulkSyndicatePP` |
| `GetListingStatus` | `PrivatePropertySoapClient.php:181` | `SyncPrivatePropertyActivations` (every 15 min), `PollPrivatePropertyActivation` |
| `ListingStatusUpdate` | `PrivatePropertySoapClient.php:194,279` | `SyndicationController::deactivate/reactivate` |
| `GetListingEventFeedByBranch` | `PrivatePropertySoapClient.php:218` | `ProcessPrivatePropertyEventFeed` (every 15 min, `pp-event-feed`) |
| `ListingLeadDetailsFeed` | `PrivatePropertySoapClient.php:234` | `PpLeadService::pullLeads()` → `PullPpLeadsJob` (every 5 min, gated per-agency) |
| `ListingPerformanceStats` | `PrivatePropertySoapClient.php:254` | `PpStatsService::pullForAgency()` → `PullPpStatsJob` (daily 04:30, gated per-agency) |
| `GetReferenceNumberByListing` | `PrivatePropertySoapClient.php:265` | diagnostic, no scheduled caller |
| `ListingShowdayUpdate` | `PrivatePropertySoapClient.php:296` | `SyndicationController::showday` |
| `UpdateAgentImage` | `PrivatePropertySoapClient.php:309` | `SyndicationController::uploadAgentImage` |
| `GetAllAgentsForBranch` | `PrivatePropertySoapClient.php:322` | `AgentPpController::index` |
| `GetAgent` | `PrivatePropertySoapClient.php:334` | agent-id resolution flow |
| `ListingSummary` | `PrivatePropertySoapClient.php:347` | diagnostic |
| `GetActiveListings` | `PrivatePropertySoapClient.php:360` | diagnostic |
| `UpdateUniqueAgentID` | `PrivatePropertySoapClient.php:372` | `AgentPpController::updateExternalRef` |
| `UpdateUniqueListingID` | `PrivatePropertySoapClient.php:385` | `PropertyPpController::updateId` |
| `UpdateListingVideoOrMatterport` | `PrivatePropertySoapClient.php:417` | `PropertyPpController::video` |
| `GetCountries/Provinces/Cities/Suburbs` | `PrivatePropertySoapClient.php:433-472` | `SyncPpLocations` command |

**`GetFullDetailsOfAllListingsByBranch` and `LeadStatDetail`/`LeadStatSummary`/
`GetListingEventFeedByFeedProvider`: zero call sites anywhere in `app/`.**
`GetFullDetailsOfAllListingsByBranch` appears only in code comments
(`PrivatePropertyListingMapper.php:20,476,861`) documenting manual, ad-hoc dev-time
verification reads (2026-07-02/05/06) — not a programmatic call.

### A3 — Sandbox vs production switching

Config-only. No hardcoded forcing URL.
- Default fallback only: `config/services.php:162`, `.env.example:132-133`.
- Per-agency override takes precedence: `PrivatePropertyConfig::for()`
  (`PrivatePropertyConfig.php:59-71`) reads `agencies.pp_wsdl`/`pp_sandbox`/etc.
- Admin UI: `AgencyController.php:237` (validation), `:254` (save).
- Documented switch procedure: `.ai/PP_DEPLOYMENT_GUIDE.md` §"Switching from Sandbox to
  Production" (lines 211-224).
- Confirmed production host (verified 2026-06-29 per the deployment guide):
  `services.privateproperty.co.za` — NOT `services.pp.co.za` (NXDOMAIN, a caught
  documentation error).

**Risk: None.**

---

## B. Impact per change

### Change 1 — ListingLeadDetailsFeed

**We call it** (`PrivatePropertySoapClient::listingLeadDetailsFeed()` line 232-240 →
`PpLeadService::pullLeads()` line 101), scheduled every 5 min, **gated per-agency** by
`agencies.pp_lead_pull_enabled` (default `false`). On the QA2 DB checked during this
investigation, 0 agencies had the toggle on. Production state not checked from this
session.

**`ToEmail` (removed)**: referenced only in a doc comment
(`PrivatePropertySoapClient.php:225`), never read by `PpLeadService::processLead()`
(field extraction list at lines 160-166 does not include it). **Impact: none.**

**`LeadType` (added)**: already read, ahead of the spec change —
`PpLeadService.php:188`, falls back to literal `'Email'` today (field absent under
Rev 4.6). No exact-string comparison on `lead_type` exists anywhere (confirmed by grep).
**Risk: Low**, except for the `is_whatsapp` gap below (now fixed, §19.1 of the spec).

**Batching/cursor**: our cursor (`Cache` key `pp.leads.cursor.agency.{id}`,
`PpLeadService.php:38,90-97`) issues one `StartDate` call per tick, 7-day default
lookback, no in-tick pagination loop. Only matters if a single agency's backlog within
one 5-minute window exceeds 1000 leads — unrealistic at HFC scale. **Risk: Low.**

### Change 2 — GetFullDetailsOfAllListingsByBranch

**Not called programmatically anywhere.** No automated reconciliation/status-sync/
orphan-detection logic consumes it. **Risk: None** for automated paths. Manual ad-hoc
verification reads (as done 2026-07-02/05/06) would need `Take`/`Skip` on a >1000-listing
branch — a runbook note, not a code fix.

(QA2 DB scale reference only, not production: 186 on-market properties, 91
`pp_syndication_status='active'` for agency 1 — both under the 1000-per-page cap
regardless.)

### Change 3 — LeadStatDetail

**We do not call it.** Grep for `LeadStatDetail`, `LeadStatSummary`, `GetLeadStat` across
the repo: zero hits. Our only stats call is `ListingPerformanceStats`
(`PpStatsService.php`), a different WSDL operation, different response shape
(`Date, Messages, TelLeads, Views, Alerts, PropertyRef`), not named in the Rev 4.7
changelog. **Risk: None** for the documented field/enum changes.

**Adjacent open question** (sent to PP, see §F): does the "unique per listing" dedup
change apply only to `LeadStatDetail`, or does it also affect `ListingPerformanceStats`'s
`Messages` count (which feeds our nightly `total_leads` snapshot)?

### Change 4a — continuationKey invalidation

**Persisted**: `pp_event_feed_settings` table via `PpEventFeedSetting::getValue()`/
`setValue()` (`PpEventFeedSetting.php:14-25`), per-branch key
`'continuation_key:' . $agency->pp_branch_guid` (`ProcessPrivatePropertyEventFeed.php:49`).

**Error handling (as found, pre-fix)**: `ProcessPrivatePropertyEventFeed.php:75-80` —
on any SOAP fault, log to the `private_property` channel and `return`. No retry within
the run, no cursor reset, no distinction between a stale-key fault and any other fault,
no escalation. The next scheduled run (15 min later) retries the same now-invalid key,
gets the same fault, repeats indefinitely, silently.

**This was the highest-risk item found.** Rev 4.7 invalidates every stored key on
release. Fixed same-session — see `.ai/specs/private-property.md` §19.2
(consecutive-failure-streak escalation via `Log::critical`). The fix does **not**
distinguish a stale-key fault specifically from other faults — that needs PP's answer to
question F.1 first.

### Change 4b — ImagesDownloading / ErrorDownloadingImages / ImagesDownloaded removed

`ProcessPrivatePropertyEventFeed.php:180-200` (pre-fix line numbers):
- `ImagesDownloading` / `ImagesDownloaded`: log-only, no property update, no task.
  Removal is **harmless**.
- `ErrorDownloadingImages`: sets `pp_syndication_status='error'`, `pp_last_error`, and
  **creates a `CommandTask`** assigned to the property's agent (`createImageErrorTask()`)
  — a real, agent-facing capability.

**Rev 4.7 removes `ErrorDownloadingImages` from the emitted events. This capability
disappears** — nothing else in the codebase detects an image-download failure any other
way. **Not fixed this session** — needs PP's answer to question F.2 (is there a
replacement signal?) and is explicitly Johan's call once that answer is in, per
CLAUDE.md §10a-style "ask, don't assume" discipline. **Risk: High**, tracked as open in
`.ai/specs/private-property.md` §17/§19 until resolved.

---

## C. Failure-mode hardness

**C1 — Tolerant, not strict.** `PrivatePropertySoapClient::call()`
(`PrivatePropertySoapClient.php:79-139`) flattens every response via
`json_decode(json_encode($response), true) ?? []`; every consumer
(`PpLeadService::extractLeads()`, `PpStatsService::extractRows()`,
`ProcessPrivatePropertyEventFeed::extractEvents()`) walks wrapper keys defensively with
`isset()`/`??`. A field PP adds is ignored until code reads it; a field PP removes falls
through to null/default. **Risk: Low** for "shape changes and we blow up" — except C.
Change 4a above, which is a SOAP *fault*, not a shape change, and does halt the drain.

**C2 — WSDL fetched live, cached by PHP.** `PrivatePropertySoapClient.php:45-69` —
`cache_wsdl => WSDL_CACHE_BOTH`, TTL governed by `soap.wsdl_cache_ttl` (php.ini default
86400s). Nothing proactively invalidates on a PP republish. **Risk: Medium** for the
sandbox window specifically — recommend clearing the WSDL cache / restarting PHP-FPM
right after the sandbox host is confirmed on Rev 4.7 (question F.5 covers timing).

**C3 — Array-vs-object handled explicitly** in all three consumers (`isAssoc()` /
`array_is_list()` checks). **Risk: Low.**

**C4 — Raw response storage**: leads — yes (`portal_leads.lead_source_raw`, full raw
payload). Stats — no (only 4 numeric fields extracted). Event feed — no (log-only, not
DB-persisted). Webhook — log-only per spec §11.8. **Risk: Low-Medium** for stats/event
feed diagnosability; leads are fully covered.

---

## D. Stats blast radius

| Surface | Location | Read/Write |
|---|---|---|
| `property_portal_metrics`, `portal='pp'` | `PpStatsService::upsertRow()` `PpStatsService.php:128-147` | Write |
| `PropertyIntelligenceService::getPortalPerformance()` | `PropertyIntelligenceService.php:55-92` | Read |
| `PropertyIntelligenceService::getPortalEngagementSeries()` | `PropertyIntelligenceService.php:103-139` | Read |
| `SellerLinkController` | `app/Http/Controllers/SellerLinkController.php:50` | Consumer (seller-facing) |
| `Api\V1\ClientSellerInsightsController` | `:32,45` | Consumer (mobile API) |
| `corex/properties/show.blade.php`, `.../intelligence/_portal-leads.blade.php` | blade | Consumer (internal) |
| `portal_leads`, `portal='pp'` | `PpLeadService.php:185-203` | Write (individual leads) |
| `corex/portal-leads/index.blade.php:141` | blade | Consumer |

Historical `ListingPerformanceStats`-derived history should **not** retroactively
disagree with PP (that operation isn't one of the 4 changed ones), unless the dedup
change is systemic rather than endpoint-scoped (open question F.3) — in which case
divergence would be forward-only from cutover (PP gives no backfill, so old rows are
frozen regardless). `portal_leads` via `ListingLeadDetailsFeed` was dormant everywhere
checked this session, so no existing history to disagree with yet.

---

## E. Sandbox test readiness

**E1** — `php artisan pp:smoke-test [--agency=]` (`PpSmokeTest.php`) is the closest thing
to an existing sandbox script. Automated (mocked) coverage:
`tests/Feature/Leads/PpLeadServiceTest.php`,
`tests/Feature/Stats/PpStatsServiceTest.php`,
`tests/Feature/Syndication/PrivatePropertyEventFeedTest.php`,
`tests/Feature/PrivateProperty/PrivatePropertyConfigTest.php`,
`tests/Unit/PrivateProperty/*` (7 files). No dedicated sandbox-test checklist found in
`.ai/qa`, `.ai/runbooks`, `.ai/tickets`, `.ai/prompts`.

**E2** — Config-only, proven by A3. **Caveat found this session**: on the QA2 DB, agency
1 has `pp_enabled=true`, `pp_sandbox=false` (pointed at PP **production**), username
`CoreXUser`, branch GUID `C9FECB32-2025-4ADD-B3F2-29531DD939B9` — distinct from the
HFC credentials documented in the deployment guide. No code-level "PP outbound blanked on
QA" guard was found (grep for `blanked`/`neutralis` around PP services: no hits). The
`.ai/BUILD_STANDARD.md` claim that PP is "blanked" on QA does not appear structurally
enforced in code for PP specifically — it relies on the DB config being pointed
somewhere safe. **Recommend confirming what the `CoreXUser` account actually is before
sandbox testing begins.**

**E3 — Recommended test cases** (mapped to the 4 changes, recommend only):
1. Flip `pp_lead_pull_enabled` on a test agency against sandbox, confirm `lead_type`
   arrives as `EmailLead`/`WhatsAppLead` and (post-fix) `is_whatsapp` is now derived
   correctly for a `WhatsAppLead` row.
2. Confirm no notice/exception from the absent `ToEmail` field.
3. Manual Tinker call to `GetFullDetailsOfAllListingsByBranch` with `Take`/`Skip`, if time
   permits — low priority, no automated path depends on it.
4. Diff `PpStatsService`'s `Messages` count pre/post cutover for the same
   listings/date — answers F.3 empirically without waiting on PP.
5. **Highest priority**: after sandbox is on Rev 4.7, clear a test branch's stored
   continuation key deliberately stale and run `drainFeed()` once to capture PP's actual
   fault text/code — needed to build the change-4a stale-key-specific handler.
6. Re-verify `Activated` event envelope shape is unchanged (regression check on the
   2026-05-18 fix).
7. Submit a listing with a deliberately bad photo URL, confirm whether **any** event
   still signals the failure now that `ErrorDownloadingImages` is gone — directly tests
   whether the change-4b capability loss has a replacement.
8. Re-run `pp:smoke-test` against sandbox immediately after clearing the WSDL cache.

---

## F. Questions sent to Private Property

Sent 2026-08-01 (see `.ai/investigations/` session for the drafted reply, or ask Johan
for the sent copy):

1. Exact SOAP fault code/message for a pre-release `continuationKey` submitted
   post-release.
2. Is there any replacement signal for image-download failure now that
   `ErrorDownloadingImages` is removed?
3. Does the "unique per listing" lead dedup apply only to `LeadStatDetail`, or also to
   `ListingPerformanceStats.Messages`?
4. Is `GetFullDetailsOfAllListingsByBranch`'s `Skip` zero-indexed, and is ordering stable
   across pages within one pass?
5. Is there a specific date/time to treat as "the sandbox WSDL contract has changed," for
   forcing a fresh WSDL fetch ahead of testing?

---

## Fixes implemented this session (2026-08-01)

Approved by Johan, independent of PP's release timeline (both are gaps in our own
fault-tolerance):

1. **`is_whatsapp` derivation** — `app/Services/PrivateProperty/PpLeadService.php`. See
   `.ai/specs/private-property.md` §19.1.
2. **Event-feed consecutive-failure escalation** —
   `app/Jobs/ProcessPrivatePropertyEventFeed.php`. See
   `.ai/specs/private-property.md` §19.2.

Deferred pending PP's answers / Johan's decision:
- Change-4a stale-key-specific detection (needs F.1).
- Change-4b image-error replacement signal (needs F.2, then Johan's call).

Deferred as a documentation-only fix, not yet actioned:
- `.ai/specs/private-property.md` §5 SOAP method table should be extended to include
  `ListingPerformanceStats` and the location-tree methods (partially addressed by the
  new §18 in this pass, full table merge still outstanding).
