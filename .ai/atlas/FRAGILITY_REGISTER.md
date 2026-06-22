# CoreX System Atlas — Master Fragility Register

> **What this is.** Every "Known Fragility" (§9) from all 18 feature docs, consolidated into one
> prioritised "what to harden" backlog. Documentation only — nothing here changes code.
>
> **Severity scale:**
> - **P0** — active **data-integrity / compliance-legal / security / tenant-isolation / signing-correctness**
>   risk (can corrupt, leak, mis-state real records, or break a legal document), OR a standing invariant
>   whose breakage is catastrophic.
> - **P1** — a real bug or gap with **contained blast radius** (review-screen-only, functional-but-incomplete,
>   audit-trail gap, cost under-reporting), or a fix whose verification is pending.
> - **P2** — tech-debt, cleanup, drift-risk, roadmap gap, ops/reliability hardening, or labelling.
>
> **Go-live note (Thursday = P24 + Private Property portals only).** Per the AT-81 safety investigation,
> the import/MIC/scoring layer is **isolated** from the advertising/syndication publish path. **No P0 below
> is go-live-critical.** The only go-live-adjacent items are in `syndication-overview.md` (all P2, and
> AT-81 verified the feeds read property *data*, not the editor taxonomy, so they are safe).
>
> Counts: **9 × P0 · 38 × P1 · 46 × P2** (93 total). Last updated: 2026-06-22.

---

## P0 — harden first (data-integrity / compliance / security / signing)

| # | Fragility | From | One-line | Ticket | Go-live? |
|---|-----------|------|----------|--------|----------|
| **P0-1** | **Rentals have no `agency_id`** | rentals-leases §9.1 | `rentals`, `lease_records`, `rental_properties` carry no `agency_id` — tenant isolation is join-derived, not a global `AgencyScope`; violates CLAUDE.md #7. | no ticket | NO |
| **P0-2** | **Rental `property_id` ID-space collision** | rentals-leases §9.2 | `lease_records.property_id` is joined to the **real `properties`** in the calendar but written from `rental_properties.id` by `assignMetadata` — same column points at two different tables; calendar events resolve to the wrong/no property+agency. | no ticket | NO |
| **P0-3** | **Comms capture has no real gate; a departed agent's device keeps capturing** | communications-capture §9.3 | The only off-switch is `WaDeviceController::destroy → active=false`. No per-session consent, no token expiry, no offboarding handoff — a still-`active` device + token keeps ingesting until manually revoked. **Security + POPIA.** (The assumed "session/midnight-reset/successor" gate does **not** exist.) | no ticket | NO |
| **P0-4** | **Consent suppression is on COMPOSE, not transmission; capture has no opt-out check** | communications-capture §9.5 · compliance §6 | The send gate blocks building the wa.me/mailto; actual delivery is the agent's own client, so an agent can message a suppressed contact out-of-band — and that message is then ingested by capture, which gates only on contact-match, **not** suppression. **POPIA nuance** (partly by-design, but a genuine gap). | no ticket | NO |
| **P0-5** | **WhatsApp IndexedDB schema break = silent zero-capture while the heartbeat looks healthy** | communications-capture §9.1 | `content.js` reads WA Web's private `model-storage`; if WhatsApp changes the `true/false_jid_msgid` id format, `idbExtract` returns null and **zero messages capture with only a console warn** — `ping` still succeeds so "last_seen" looks fine. **LIVE (4 agents); silent gap in a compliance archive.** | no ticket | NO |
| **P0-6** | **The `agency_id`-null class of bug** | platform §7.1 · buyer-pipeline §9.1 | `BelongsToAgency` only force-stamps under `Auth::user()`; in cron/observer/queue contexts it falls to a fallback that returns 0 in multi-agency prod → `agency_id` NULL → orphan, invisible to all tenants (or mis-attributed to agency 1 by `PropertyAuditService ?? 1`). **AT-72 fixed the buyer-pipeline instance; the class persists everywhere non-request writes happen.** | AT-72 (1 instance) | NO |
| **P0-7** | **PAYE duality unreconciled for SARS** | payroll-leave §5/§9.1 · deals-commission §8.5 | `PayrollCalculator::calculatePaye` (SARS engine) and `deal_money_lines.paye_amount` (flat per-deal) are two PAYE figures **never summed** for EMP201/IRP5. An agent on salary + commission has two independent PAYE deductions with no aggregation. **Tax-compliance correctness.** | no ticket | NO |
| **P0-8** | **E-Sign multi-cluster role detection bails → seller 2/3 get no editable block** | esign-docuperfect §9.4 | `RoleBlockExpansionService.php:524-542` bails on `totalClusters > 1`, stamping only `seller_1` — when a role has disjoint clusters, additional signers get no editable surface. **Signing-correctness / legal-document defect (audit Q2, still open).** | esign-reset audit Q2 | NO |
| **P0-9** | **The P0 signing-view invariant (standing must-not-break)** | esign-docuperfect §9.1-2 | No `location.reload()`/JS re-render during signing — a single inadvertent re-render wipes 5-15 min of captured signatures. Protected by the CLAUDE.md pipeline gate (the audit found **5 live signing bugs shipped while 49 unit tests were green**). Listed as P0 because breakage is catastrophic and the surface is easy to break. | esign-reset audit / pipeline gate | NO |

**Confirmed: none of the 9 P0 items gate the Thursday portal go-live** (rentals, comms, payroll, e-sign signing, and cron-context tenancy are all outside the P24/PP publish path, which AT-81 verified is isolated).

---

## P1 — real, contained (fix in normal cadence)

| # | Fragility | From | One-line | Ticket |
|---|-----------|------|----------|--------|
| P1-1 | Condition uplift above the comp set | presentations §9.3 | +20% (Excellent) on an already top-of-market comp median over-values — a **doctrine** question, review-screen only. | AT-82 |
| P1-2 | R13m parsed vicinity benchmark | presentations §9.4 · cma-import §7.1 | `cma_info_benchmark` is a verbatim parsed scalar from the source CMA — bypasses CoreX outlier exclusion; review-screen only, never seller PDF. | AT-82 §D |
| P1-3 | Publishing a draft freezes current condition | presentations §9.6 | One "Confirm" click freezes the property's *current* `Excellent` onto the seller version — the over-valuation is one click from the seller. | AT-82 §C |
| P1-4 | `PropertyCmaPropagationService` bypasses the audit observer | properties §9.3 · presentations §9 | Writes erf/municipal-valuation/GPS/title-deed via `DB::table()->update()` — no `property_audit_log` row. | no ticket |
| P1-5 | Browser-autofill into address fields | properties §9.1 | Chrome/password-managers write a profile name into `unit_section_block`; mitigated by autocomplete tokens, structural split still open. | AT-78 |
| P1-6 | Match-or-Create strategy-5 token false-merge | prospecting §9.1 | House numbers <3 chars dropped → "12 Mitchell St" and "98 Mitchell St" merge. Biggest dedup hazard; bites the sparse email feed (not yet routed). | AT-81 |
| P1-7 | GPS ~5m merges stacked sectional units | prospecting §9.2 | Units share a footprint GPS + 0%-populated unit_number → collapse into one TP. | AT-81 |
| P1-8 | Email island (`p24_listings` invisible to scorers) | prospecting §9.6 | Email path never calls Match-or-Create → email intelligence invisible to every scorer (#10 violation). | AT-81 |
| P1-9 | Two writers / two scorers on buyer-match caches | market-intelligence §7.1 | `prospecting_buyer_matches` canonical (Engine A) but `property_buyer_matches` still legacy (Engine B) — same buyer scores differently per surface. | AT-75 |
| P1-10 | Buyer-match tier vocab differs across readers | market-intelligence §7.2 | perfect/strong/approximate vs strong/good/fair vs strong/mid/weak — reporting-consistency hazard. | no ticket |
| P1-11 | Manual-placement protection depends on `manual_override` being written | buyer-pipeline §9.3 | Only `BuyerPipelineController::updateState` writes it; detail-side mark-lost/reengage must too (TODO verify). | AT-74 |
| P1-12 | Empty-then-edited wishlist won't auto-land | buyer-pipeline §9.5 | `landOnPipeline` fires only on `created()`; a wishlist later edited to become countable needs the manual backfill command. | AT-72 |
| P1-13 | Buyer auto-land is manual | buyer-pipeline §9 · market-intelligence §7.5 | `buyers:autoland-pipeline` is not scheduled — a missed run leaves countable buyers unlanded. | AT-72 |
| P1-14 | Orphaned commission cap/revenue-share engine | deals-commission §8.1 | `CommissionCalculationService` + `commission_ledger` are built but **never invoked** — cap/rev-share/"My Earnings" dashboards read empty unless populated manually. | no ticket |
| P1-15 | Opt-out token has no TTL + CSRF-exempt | compliance §9.3 | Tokens never expire; mitigated by 48-char entropy + throttling; CSRF exemption is the documented 419 fix. | outreach-public-link |
| P1-16 | Retention enforcement is cron-only | compliance §9.4 | `PurgeContactRetention` relies on the scheduler running; no in-app trigger. | no ticket |
| P1-17 | `transaction_only` depends on `TransactionStateService` config | compliance §9.2 · contacts §9.2 | Live-status set is config-driven; deal-status/mandate drift can silently flip a contact in/out of transaction-only. | no ticket |
| P1-18 | FICA-gate spec divergence | esign-docuperfect §9.3 | Code lifts the gate on **submission**; V3 spec §6.2 says **approval**. Doc/code disagree — confirm intent. | no ticket |
| P1-19 | Two filed-document pivot lineages | esign-docuperfect §9.5 · document-library-filing §9.1 | `document_contact` (singular) and `document_contacts` (plural) both written on one e-sign completion against different records; drive UI vs signed-docs UI can diverge. | no ticket |
| P1-20 | Document auto-link race (residual, plural side) | document-library-filing §9.4 | Singular side fixed (V2 BUG#4); residual non-atomic `Document::where(storage_path)->exists()` can create duplicate `documents`. | V2 BUG#4 |
| P1-21 | `party_role`-null duplicate pivot rows | document-library-filing §9.5 | A contact linked once with a role (e-sign) and once null (manual attach) → two pivot rows, inflating the drive. | no ticket |
| P1-22 | OpenAI / Ellie-chat / Whisper spend unmetered | ai-tools §9.1-2 | KB embeddings, the DocuPerfect OpenAI fallback, Ellie chat, and STT write nothing to the ledger — "total AI cost" excludes them. | no ticket |
| P1-23 | Budget cap is gateway-only | ai-tools §9.8 | The 7 direct Anthropic callers record cost but are never checked against `canMakeAiCall()` — a capped agency can still spend via voice/image/DocuPerfect/marketing. | no ticket |
| P1-24 | Image-analysis cost has null user attribution | ai-tools §9.4 | `AnalysePropertyImageJob` runs queued (no Auth) → every `image_analysis` row has `user_id=null`. | no ticket |
| P1-25 | Unknown AI model prices at ZERO silently | ai-tools §9.5 | `AiCostCalculator` returns 0 for any model name without haiku/sonnet/opus — tokens log, cost reads 0, undercounting with no error. | no ticket |
| P1-26 | Calendar broken Alpine root `<div>` | calendar §8.1 | Missing closing `>` at `index.blade.php:47` — likely single root cause for broken calendar interactivity (verify fixed on branch). | calendar audit |
| P1-27 | Calendar `store()` didn't write `calendar_event_links` | calendar §8.2 | Historically broke `linkedContacts()`/feedback button on user events; current `store()` calls `syncEventLinks` (verify). | calendar audit |
| P1-28 | Calendar notification dispatch is lossy | calendar §8.5 | Empty `*_notifications` silently no-ops; the generic dispatcher's open-hours window **drops with no defer** — a feedback alert outside hours is lost. | no ticket |
| P1-29 | Lease expiry depends on an unguaranteed cron | rentals-leases §9.3 | Views filter on persisted `status`; if `CheckLeaseExpiry` isn't scheduled, active/expired counts drift and stale `active` never self-corrects. | no ticket |
| P1-30 | Lease termination date not persisted | rentals-leases §9.4 | `terminateLease` writes only `status` — the date survives only in audit metadata; "when did it end" is unreliable. | no ticket |
| P1-31 | Lease auto-creation is fuzzy-keyword-driven | rentals-leases §9.5 | `isLeaseDocument` keyword-matches → false positives create spurious `LeaseRecord`s; term defaults to +1 year. | no ticket |
| P1-32 | `PresentationGenerated` escapes the audit spine | platform §7.5 | It does **not** extend `AbstractDomainEvent`, so it writes **no `domain_event_log` row** despite being framed as a pillar event. | no ticket |
| P1-33 | `withoutGlobalScope` volume in controllers | platform §7.2 | ~223 occurrences across 30+ controller files (CLAUDE.md #7 forbids in request code) — each a potential tenant-leak surface needing individual review. | multi-tenancy spec |
| P1-34 | `DB::table()` writes bypass observers + scopes + audit | platform §7.3 | A class of untenanted/unaudited writes (e.g. `PropertyCmaPropagationService`, audit-service raw inserts). | no ticket |
| P1-35 | `PropertyAuditService` default-to-1 | platform §7.6 | `agency_id => ... ?? 1` mis-attributes audit rows for an orphaned property to agency 1 (combines with P0-6). | no ticket |
| P1-36 | Payroll PAYE engine gaps | payroll-leave §9.2 | Medical-aid tax credits unimplemented despite take-on capturing medical aid; unknown age defaults to 40. | no ticket |
| P1-37 | Leave approval concurrency | payroll-leave §9.6 | Status flip is atomically guarded, but the post-approval ledger write + calendar event sit outside it — a failure leaves an approved app without its deduction/event. | no ticket |
| P1-38 | Property `status` is a free string, not an enum | properties §9.4 | Settings list and literal column values kept in sync by a rename migration — drift hazard if a status is added in one place only. | AT-67-era |

---

## P2 — tech-debt / cleanup / roadmap / ops hardening

| # | Fragility | From | One-line | Ticket |
|---|-----------|------|----------|--------|
| P2-1 | Triple inc→ex-VAT calculators | deals-commission §8.2 | Three copies of the pool math (`Deal`, `DealV2`, `Finance/CommissionCalculator`) — drift risk. | no ticket |
| P2-2 | V1 deal status implicit/derived, duplicated | deals-commission §8.3 | `statusSummaryForBranch` vs `…ForCompany` boolean derivation — easy to desync. | no ticket |
| P2-3 | Legacy unlinked deals | deals-commission §8.4 | Deals without `branch_id`/`property_id` rely on view_all or the link-review queue. | no ticket |
| P2-4 | Comm status derived, never stored | contacts §9.2 | Adding a state means editing both `communicationStatus()` and `…Meta()`. | no ticket |
| P2-5 | `display_email` is on User, not Contact | contacts §9.3 | Easy to misattribute; there is no `display_email` on contacts. | AT-79 |
| P2-6 | `is_buyer` is the only role boolean | contacts §9.4 | "Is this a seller?" lives in `ContactType` + `contact_property.role`, not one column. | no ticket |
| P2-7 | Countability bar dual-source (PHP + SQL) | contacts §9.5 · buyer-pipeline §9.5 | `presentCriteriaGroups()` and `countableGroupSql()` must stay in lock-step. | AT-71 |
| P2-8 | Street-name normalisation gaps | prospecting §9.4 | Only 6 suffixes canonicalised; Crescent/Blvd/Place/Terrace/Way miss → duplicates. | AT-81 |
| P2-9 | Cross-source ref mismatch | prospecting §9.5 | P24 ref ≠ PP ref; strategy 1 won't bridge — relies on GPS/erf/address. | AT-81 |
| P2-10 | No feature column on import/comp tables | prospecting §9.7 · market-intelligence §7.4 | `prospecting_listings`/`tracked_properties` have no `features_json` → offering axis one-sided. | AT-81 / AT-77 |
| P2-11 | Re-parse clears `report_type_id` → fallback parser loses facts | cma-report-import §7.3 | An un-registered parser re-detects as `GenericFallbackParser` (0 facts). | no ticket |
| P2-12 | CMA parse needs `pdftotext` on PATH; synchronous | cma-report-import §7.4 | Missing binary → fallback parser; large PDF blocks the upload request. | no ticket |
| P2-13 | CPA Amendment Regs 2026 / NCC registry not built | compliance §9.1 | No national do-not-contact registry consult before sending; suppression is agency-scoped only. | no ticket (future) |
| P2-14 | PPRA vs FFC mislabel | compliance §9.5 | `Agency.ffc_no` rendered as "PPRA"; no structured agency PPRA number. | popia-columns audit |
| P2-15 | Whistleblower is not anonymous | compliance §9.6 | Agent-attributed by design; no anonymous intake path. | by design |
| P2-16 | CDS "six sources of truth" | esign-docuperfect §9.6 | `cds_json`/`editor_state`/`field_mappings`/`fields_json`/blade/`CdsDraft` can disagree (template-revert). | esign-reset Q1 |
| P2-17 | Moat-gate phantom file | esign-docuperfect §5 | `dev-check.ps1` lists a non-existent `SurfaceNormalizer.php`; real `RoleBlockNormalizer.php` not in the gate. | no ticket |
| P2-18 | E-Sign V2 backlog | esign-docuperfect §9.9 | `editable_by` unpopulated; clause flags collected but amendment auto-create only wired for Other Conditions. | no ticket |
| P2-19 | Orphan unified documents | document-library-filing §9.2 | No global reaper for `documents` with all-empty pivots; a failed attach leaves an invisible doc. | no ticket |
| P2-20 | No restore UI for unified `Document` | document-library-filing §9.3 | Soft-delete keeps file+pivots but recovery is DB-only (unlike Filing Register / Shared Drive). | no ticket |
| P2-21 | `source_type` loose string, no enum | document-library-filing §9.6 | A typo silently mis-categorises a document. | no ticket |
| P2-22 | Python AI service is a single-point dependency | ai-tools §9.3 | `127.0.0.1:3100` hardcoded, not in git, restarted manually; down → Ellie "AI service error". | no ticket |
| P2-23 | `usd_to_zar` forward-only | ai-tools §9.6 | Historical ledger rows not re-priced; stale rate skews the cap. | no ticket |
| P2-24 | AI ledger tenancy write-time only | ai-tools §9.7 | No `AgencyScope` on `ai_usage_events` (deliberate); migration comment wrongly claims otherwise. | no ticket |
| P2-25 | AI recorder swallows failures silently | ai-tools §9.9 | A persistently failing ledger write produces only a log warning; dashboard under-reports. | no ticket |
| P2-26 | AI cost-ledger spec/code divergence | ai-tools §9.10 | `ai-cost-ledger.md` is "DRAFT — not on main" while the implementation is complete. | no ticket |
| P2-27 | Syndication: two integration styles | syndication §9.1 | P24 REST/Basic-Auth vs PP SOAP/SHA1; no shared transport; PP SSL verify disabled. | Andre |
| P2-28 | Syndication taxonomy hand-sync (AT-81 §2) | syndication §9.2 | P24's feature vocabulary is a private inline list kept in lockstep by hand with `features_json`/`spaces_json`. **Go-live-adjacent — AT-81 verified safe** (feeds read data, not the editor dictionary). | AT-81 |
| P2-29 | Syndication suburb-id cross-wiring | syndication §9.3 | `resolveSuburbId` reads `pp_suburb_id` to resolve a `P24Suburb` — cross-portal column coupling. | Andre |
| P2-30 | Syndication credential CLI auto-pick | syndication §9.4 | CLI/queue picks the first enabled agency — can route jobs to an unexpected branch if multiple are enabled. | Andre |
| P2-31 | Syndication listing-state sync | syndication §9.5 | State reconciled by polling/event-feed jobs; website publish and portal syndication fully decoupled. | Andre |
| P2-32 | Calendar visibility resolver edge cases | calendar §8.4 | Leave-matrix needs `user_id`; global (`agency_id=null`) rows bypass isolation; empty role list hides from all. | no ticket |
| P2-33 | Calendar class-settings `$fillable` drift | calendar §8.6 | Migration-added columns historically missing from `$fillable` (now test-guarded). | no ticket |
| P2-34 | `buyer_property_views` derived-cache drift | calendar §8.7 · market-intelligence | If `storeFeedback` sync and `RecomputeBuyerPropertyViews` diverge, counts drift. | no ticket |
| P2-35 | BCEA accrual edge cases | payroll-leave §9.3 | Accrual capped at entitlement; rollover is a separate cron 30 min later (drift if accrue runs but rollover fails). | no ticket |
| P2-36 | Take-on opening-balance semantic mismatch | payroll-leave §9.4 | Opening "taken" recorded as `manual_adjustment`, not the `opening_balance` type `getBalance` recognises. | no ticket |
| P2-37 | Leave-payroll integration partial | payroll-leave §9.5 | Only `affects_payroll=true` types flow to payroll; paid leave has no payslip representation; PDF reports Tier 2. | no ticket |
| P2-38 | Payroll/leave soft-delete asymmetry | payroll-leave §9.7 | Ledger/cache/reference tables lack SoftDeletes — a hard-deleted payslip line or tax-table row is unrecoverable. | no ticket |
| P2-39 | View-As vs Switch-User gotcha | platform §7.4 · calendar §8.3 | View-As doesn't change `Auth::user()` → visibility scopes still see the original user. Test scoped features with Switch-User. | STANDARDS limitation |
| P2-40 | Property legacy `address` column drift | properties §9.6 | Many properties have NULL legacy `address`; code reading it directly (not `buildDisplayAddress()`) mis-renders/mis-matches. | AT-78-related |
| P2-41 | MIC garbage-in from import | market-intelligence §7.3 | Scoring quality capped by tracked/prospecting data quality (type 81% missing, GPS 87% missing). | AT-81 |
| P2-42 | `buyers_matched` depends on the countability invariant | market-intelligence §7.6 | The tile equates distinct cached contact_id with distinct countable buyers — correct only while the invariant holds. | AT-71 |
| P2-43 | Comms CORS wildcard | communications-capture §9.2 | `allowed_origins => ['*']` covers `api/*` too — broad if cookie/session auth is ever added there. | no ticket |
| P2-44 | Comms provisional-reconciliation gaps | communications-capture §9.4 | 48h window + edited-text hash mismatch can create a duplicate archive row (inflates the AT-59 tile). | no ticket |
| P2-45 | Comms batch error swallowing / IMAP since-window | communications-capture §9.6-7 | A systematically failing message type is invisible; a >1-day poller outage can miss older messages. | no ticket |
| P2-46 | MIC snapshot rows deleted+recreated per generate | presentations §9.8 · market-intelligence | Comp-row ids aren't stable across regenerations; the version's `included_comp_ids_json` whitelist is the durable record. | no ticket |

---

## How to use this register

- **P0** is the harden-first backlog — start here when capacity allows. None block Thursday's portal go-live.
- **P1** is normal-cadence fix work; several are "verify the fix landed on the current branch" (P1-11, P1-26,
  P1-27) rather than fresh builds.
- **P2** is opportunistic cleanup — fold into whatever feature you're already touching (the "fix the class,
  not the instance" rule applies especially to P0-6, P1-34, and the duplicate-calculator/pivot-lineage items).
- Each row cites its source doc + section — open that doc's §9 for the full file:line context and the
  prevent-or-absorb decision.
