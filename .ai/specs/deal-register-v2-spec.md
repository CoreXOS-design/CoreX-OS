# Deal Register V2 — CoreX OS Full Spec (SSOT)

> **Single source of truth for the Deal Register V2 (DR2) build.**
> **Version:** 2.0 — 2026-07-02. Supersedes v1.1 (2026-03-30). Approved design inputs = Johan's 4 locked decisions + product direction from the 2026-07-02 spec-design session.
> **Grounded in:** the AT-158 investigation (`.ai/audits/2026-07-02-dr2-programme-investigation.md`) — verified as-built against `Staging`. **Jira:** AT-158.
> **Owner:** Johan (product) · Build: Johan + Claude.
> **Pillars:** Property · Contact · Deal · Agent (all four). Documents + Compliance ride on top.
> **Standing constraints:** no hard deletes; everything configurable with sensible defaults; a nav link for every new page the same day; full input-space robustness (BUILD_STANDARD); POPIA-conscious access logging. STAGING build — HOLD from live; promotion gated on Johan.

---

## 0. What changed in v2.0 (read this first)

v1.1 was written **before** the code existed and before the 4 decisions. Three things are now true and reframe the whole build:

1. **DR2 is not greenfield.** Phase 1–3 of v1.1 is already built (`deals_v2`, `App\Models\DealV2\*`, `DealPipelineService`, template/step CRUD, seeded templates, deal-detail tracker, calendar sync) but holds **0 rows** on dev and live and is **untested**. The build **completes** this code; it does not restart it. Full as-built inventory in §2.
2. **The "clock & escalation" runtime is missing** — `deals:process-rag` (persisted RAG goes stale; `updateDealOverallRag()` has zero callers), escalations/notifications, dynamic calendar colour. Because an *untested* engine is about to become the canonical deal store, **the build LEADS with engine tests + the RAG timer** (§15, WS0) before any new feature.
3. **Four decisions are locked** (§3) and the product direction adds a real **document-distribution** capability (secure-link+OTP or direct attachment, per-doc-type×party), an **agency supplier directory**, **auto COC generation**, and **3-pillar comms logging**.

---

## 1. Governing principles

- **Canonical DR2, DR1 preserved.** DR2 becomes the canonical operational deal store. DR1 (`deals` / `App\Models\Deal`) stays live and **untouched behaviourally** through the transition (§13). No hard deletes anywhere — DR1 rows survive cutover as audit records.
- **Four pillars are the spine.** Every deal reads Property + Contacts, writes back document/deal/calendar/comms state. No freeform property addresses or client names — if it's not in CoreX, the user adds it first (the one exception: free-text is never invented; a provider not yet a contact is created inline into the directory, §9).
- **Zero hardcoded process.** No hardcoded step names, timelines, RAG thresholds, escalation timing, or distribution rules. Every element is agency-configurable with a **sensible default** shipped in a seeder. CoreX adapts to the agency.
- **Server-authoritative.** Status changes, RAG, visibility scoping, distribution eligibility, and access verification are decided server-side. The client is a skin.
- **Cross-pillar reactivity via domain events** (non-negotiable #9), not ad-hoc cross-table writes. Deal events already exist (`DealCreated`, `DealRegistered`, `DealStageAdvanced`, `DealStatusChanged`, …).
- **Data scoping** (existing pattern): Agent = own, BM = branch, Admin = all, via `DealV2::scopeVisibleTo()` + `PermissionService::getDataScope()`.
- **Branch attribution = the selling agent's acting office (AT-192, Johan doctrine).** A deal belongs to the SELLING agent's acting office; that office's branch is the deal's branch. Listing-side agents from another branch are normal and are **never** a mis-stamp signal. Capture takes the branch by the capturer's effective branch (home OR the managed-branch office they are acting as) or an **explicit** selection — **never** `Branch::first()`, and never a home-branch-match heuristic (AT-192 d). Any future auto-derivation derives from the **selling side**.

---

## 2. As-built inventory (the starting line)

### 2.1 Built and working (do NOT rebuild — complete/extend)
| Area | As-built | File |
|---|---|---|
| Tables | `deals_v2`, `deal_v2_contacts`, `deal_v2_agents`, `deal_step_instances` (RAG cols), `deal_step_documents`, `deal_activity_log`, `deal_pipeline_templates/steps`, `deal_v2_settlements` | `database/migrations/2026_03_30_*`, `_400000_add_status_triggers_*` |
| Engine | `createDeal`, `activateStep`, `completeStep` (pos/neg outcome, status_trigger, BM-approval gate, file upload), `approveStep`/`rejectStep`, `activateDownstreamSteps` (chain recalc from *actual* completion), `recalculateExpectedRegistration` | `app/Services/DealV2/DealPipelineService.php` |
| Models | `DealV2` (`scopeVisibleTo`, ref `DL-YYYY-NNNNN`, ported commission math), `DealStepInstance` (`calculateRag`, `daysRemaining`, `needsApproval`), `DealPipelineStep/Template`, `DealStepDocument`, `DealActivityLog`, `DealV2Settlement` | `app/Models/DealV2/*` |
| Controllers | deal CRUD + search, step complete/approve/reject/upload/overrideDueDate, pipeline-setup CRUD + duplicate, step reorder, settlement | `app/Http/Controllers/DealV2/*` |
| Seeder | 3 templates: Standard Bond Sale (default, 15 steps), Cash Sale (9), Sale of 2nd (16, incl. `auto_from_linked_deal`) | `database/seeders/DealPipelineTemplateSeeder.php` |
| Views | Deal-detail **visual pipeline tracker** (live RAG in Blade), pipeline-setup, settlement | `resources/views/deals-v2/*` |
| Calendar sync | `DealCalendarSource` (`deal_step_deadline`, `deal_registration_target`) + `DealStepInstanceObserver`/`DealV2Observer` + nightly `corex:calendar:reconcile` (idempotent on `(source_type,source_id,category)`) | `app/Services/CommandCenter/Calendar/Sources/DealCalendarSource.php`, `app/Observers/*` |
| Perms/nav | `deals_v2.*` in `config/corex-permissions.php:418-424`; sidebar group | `corex-sidebar.blade.php:1455-1487` |

### 2.2 Missing (the build)
1. `deals:process-rag` timer; `updateDealOverallRag()` is **uncalled** → persisted `current_rag`/`overall_rag` stale.
2. Escalation runtime (`deals:process-escalations`) + `NotificationService` (two `// TODO Phase 5` stubs; `escalation_config` is dead data).
3. Dynamic RAG **colour** persisted onto deal calendar events.
4. Deal link on the unified `documents` store; `deal_step_documents` is disconnected (raw `file_path`, `document_id` NULL).
5. Stage×doc-type×role **distribution matrix** + distribute action + auto-triggers + COC generation.
6. Provider party roles + agency **supplier directory**.
7. `OutboundProvisionalLogger` extension for attachments + Property/Deal links + owner stamp.
8. DR1↔DR2 link + sync service + parity harness.
9. `document_signed` step auto-completion; `auto_from_linked_deal` runtime.
10. Overview board/kanban, dashboard cards, CSV, scope switcher; per-user iCal.
11. **Zero automated tests for the DR2 engine** — the highest-priority gap.

---

## 3. Locked decisions (AT-158)

- **D1 — Sync model.** Canonical DR2 + a **single-writer `DealSyncService`** (the `ContactIdentifierService` mirror pattern) mirroring only the **shared core fields** (status, granted/registration dates, parties, commission totals) both ways during the overlap, keyed by a link column on **both** sides (`deals.deal_v2_id` ↔ `deals_v2.legacy_deal_id`). Then a **Leave-style human reconciliation** proves parity; DR1's UI is retired; the `deals` row is preserved as audit. **Not** a permanent live mirror. (§13)
- **D2 — Providers.** Extend `deal_v2_contacts.role` with provider roles **and** build a reusable **agency preferred-supplier directory** — an agent picks a provider from the list **or creates a new one inline**, which saves to the directory for reuse. (§9)
- **D3 — V1 footprint.** Minimal: add a nullable `deals.deal_v2_id` pointer only. **DR1 integrity fully intact** — no behavioural or forward-date columns on the live table. All new behaviour is DR2-side. (§13)
- **D4 — Document spine.** Add a nullable `documents.deal_id` FK. A deal is **attachable and reachable from every angle** — one upload auto-links everywhere: PDF splitter (per-page assignment gains a **deal** target alongside property/contact), property, contact, and the deal register. (§7)

---

## 4. Data model

### 4.1 Existing tables — unchanged except where noted
`deal_pipeline_templates`, `deal_pipeline_steps`, `deals_v2`, `deal_v2_agents`, `deal_step_instances`, `deal_activity_log`, `deal_v2_settlements` are as v1.1 §8 / as-built. All `BelongsToAgency` + `SoftDeletes`. Notes:
- `deals_v2.overall_rag` and `deal_step_instances.current_rag` are **cached computed** values — they become correct only once WS0 wires the timer (§15). Until then they are read-live in Blade.
- `deal_step_instances` already carries per-step `rag_green_days/amber_days/red_days` (per-step override of the class default).

### 4.2 New columns

```
deals.deal_v2_id            bigint unsigned NULL  FK→deals_v2.id  (D3 pointer; index)   -- ONLY change to DR1
deals_v2.legacy_deal_id     bigint unsigned NULL  FK→deals.id     (D1 back-pointer; index)
documents.deal_id           bigint unsigned NULL  FK→deals_v2.id  (D4 deal anchor; index)
deal_step_documents.document_id  -- already exists (nullable); the build POPULATES it (link to unified documents)
```

### 4.3 `deal_v2_contacts.role` — extended enum (D2)
Add to the existing `buyer, seller, co_buyer, co_seller, conveyancer, bond_originator, other`:
`transfer_attorney, bond_attorney, electrician_coc, entomologist, originator, service_provider`.
(Additive enum change — a migration `MODIFY COLUMN`; no data rewrite; existing rows keep their value.)

### 4.4 `agency_service_providers` (NEW — D2 supplier directory)
```
id                bigint PK
agency_id         bigint  (BelongsToAgency)
contact_id        bigint NULL   FK→contacts  (a provider MAY also be a CoreX contact; NULL = directory-only)
name              string 191
specialty         enum: electrician, entomologist, plumber, gas, electric_fence,
                        transfer_attorney, bond_attorney, conveyancer, bond_originator, other
company           string 191 NULL
email             string 191 NULL   -- normalised; where packs are sent
phone             string 50  NULL
notes             text NULL
is_preferred      boolean default false   -- agency's default pick for this specialty
is_active         boolean default true
created_by_id     bigint
timestamps + deleted_at (SoftDeletes)
```
Index `(agency_id, specialty, is_active)`. A provider is addressed on a deal via `deal_v2_contacts` (role) resolving to either an existing contact or a directory row; the directory is the **reuse** layer.

### 4.5 `deal_stage_document_rules` (NEW — §8 distribution matrix)
```
id                    bigint PK
agency_id             bigint  (BelongsToAgency)
pipeline_step_id      bigint NULL  FK→deal_pipeline_steps  -- the STAGE (NULL = any stage / manual only)
document_type_id      bigint       FK→document_types       -- the DOC TYPE
party_role            string 40    -- the RECIPIENT role (reuses the deal_v2_contacts.role vocabulary)
delivery_mode         enum: secure_link, direct_attachment  default secure_link
auto_on_stage_tick    boolean default false  -- fire automatically when the stage's status_trigger fires
is_active             boolean default true
created_by_id         bigint
timestamps + deleted_at (SoftDeletes)
```
Unique `(agency_id, pipeline_step_id, document_type_id, party_role)`. This is the `doc-type × party-role × deal-stage` matrix, editable on the existing doc-types settings surface (§8.1).

### 4.6 `deal_document_distributions` (NEW — §8 send record + §10 comms anchor)
```
id                    bigint PK
agency_id             bigint  (BelongsToAgency)
deal_id               bigint       FK→deals_v2
document_id           bigint NULL  FK→documents         -- the doc sent (NULL for a generated-on-send COC before filing)
party_role            string 40
recipient_contact_id  bigint NULL  FK→contacts
recipient_provider_id bigint NULL  FK→agency_service_providers
recipient_email       string 191   -- snapshot at send
delivery_mode         enum: secure_link, direct_attachment
secure_token          char(40) NULL UNIQUE  -- for secure_link mode; the tokened URL
otp_required          boolean default true  -- secure_link identity gate
status                enum: queued, sent, delivered_failed, opened, downloaded, revoked  default queued
communication_id      bigint NULL  FK→communications    -- the archived outbound email (§10)
sent_by_id            bigint
sent_at               datetime NULL
first_opened_at       datetime NULL
timestamps + deleted_at (SoftDeletes)
```
Plus **`deal_document_access_log`** (append-only, immutable — mirrors the comms/e-sign audit pattern; POPIA evidence): `id, distribution_id, event enum(link_clicked, otp_sent, otp_verified, otp_failed, downloaded, revoked), ip, user_agent, meta json, created_at`. No `updated_at`/`deleted_at`; `update()`/`delete()` throw.

---

## 5. Pipeline engine (config → tick → status → timer → RAG)

The configuration model (deal types, templates, steps, completion types, triggers, status triggers, RAG thresholds, default template) is **as v1.1 §2–3 and is already built** — retained verbatim as the contract. This section records only what the **build adds/completes**.

### 5.1 Agency-configurable definitions (built)
Per-deal-type pipeline templates with fully user-defined steps: `completion_type` ∈ {manual_tick, date_input, amount_input, document_upload, document_signed, text_input, multi_field, auto_from_linked_deal}; `trigger_type` ∈ {on_creation, after_step, manual, on_date}; per-step `days_offset`, `rag_*_days`, `notify_*`, `is_locked`, `is_milestone`, `required_before`; status triggers (`status_trigger`, `negative_status_trigger`, `negative_outcome_label`, `requires_bm_approval`). Default SA bond template ships in the seeder; every element is editable; locked steps cannot be removed/skipped but can be renamed.

### 5.2 Tick-driven advance (built) — the spine of "agent ticks, status advances"
`DealPipelineService::completeStep()` already: records positive/negative outcome, applies `status_trigger` (or holds it behind the BM-approval gate), then `activateDownstreamSteps()` recalculates all downstream due dates **from the actual completion date**, or `cancelDownstreamSteps()` on a negative outcome. A date-bearing step (e.g. **bond-due date** entered via `date_input`/`multi_field`) sets the due date that **starts the timer**. This works today; the build **tests it** (WS0) and **wires the missing runtime around it** (below).

### 5.3 RAG colour escalation — COMPLETE the runtime (WS0, the lead item)
- **Timer:** new `deals:process-rag` command (every 15 min) sweeps active `deal_step_instances`, recomputes RAG from `now` vs `due_date` against the step's `rag_*_days` (green→amber→red→overdue), **persists** `current_rag`, calls the currently-uncalled `updateDealOverallRag()` (worst-RAG rollup), and — on a change — repaints the linked calendar event's colour and fires the notification (§11).
- **Thresholds are agency-configurable** per step (already modelled); the timer only reads them.
- **Overdue** flips `status: active → overdue` for actionable steps (reuse the calendar `ProcessReminders` overdue idiom, but for deal steps).
- **Verification gate:** a step crossing each threshold purely by the passage of (test-frozen) time transitions `current_rag` and `overall_rag`, repaints the calendar tile, and emits one notification per transition — proven by a feature test with a controlled clock.

> **DECISION RECORD — `green_days` semantics (Johan, 2026-07-03; resolves the WS0 flag).** RAG uses **TWO thresholds only**, not three: a step **starts and stays GREEN** until it enters its amber window (`amber_days` before due) → **AMBER** (through `red_days` before due) → **RED** → **overdue**. `rag_green_days` is **redundant** and is **dropped/hidden** from the config UI (the engine's `calculateRag` already behaves exactly this way — green = anything earlier than `amber_days`, so no engine change is needed). **WS7 is unblocked** (the overview/board no longer waits on a green-threshold decision). **Tile behaviour:** the DR2 pipeline tile (and the AT-164 "My Deals" calendar tile) shows steps **green by default**, going **amber/red as the windows hit**, repainting live via the WS0 `deals:process-rag`/observer server-side RAG — already consistent with the AT-164 §15.5/§15.7B tile spec.

### 5.4 Through to registration
The seeded bond template runs OTP Signed → Bond Application → Bond Approved (granted + BM approval) → COC/Deposit/Attorney (activate on approval) → Rates Clearance → Deeds Lodgement → **Registration** (status → completed). On registration completion, emit `DealRegistered` (existing event) → downstream listeners (property→sold, commission calc) fire through the catalogue.

---

## 6. Calendar integration (Feed System + calendar-interactive)

Deal→calendar is **already wired** via the Calendar Feed System (`.ai/specs/spec-calendar-module.md` source-service model) and coexists cleanly with the interactive/recurrence/privacy layer (`.ai/specs/calendar-interactive.md`). The build only closes the colour gap.

- **Source service (built):** `DealCalendarSource` emits `deal_step_deadline` (per active step `due_date`) + `deal_registration_target` (per deal `expected_registration`), keyed idempotently `(source_type, source_id, category)`. New named stage timers (e.g. a distinct **bond-due** class) are usually just a pipeline step with a `due_date` (flows through `deal_step_deadline`); add a new **event class** row (`CalendarEventClassSeeder`) only when it needs distinct thresholds/visibility.
- **Colour (build):** the calendar tile already escalates on-read via `CalendarThresholdResolver` (per-class + per-step `rag_*_days` overrides). WS0 additionally **persists** the RAG colour onto the deal calendar event when `deals:process-rag` runs, so the deal board and the calendar agree (the two-colour-systems nuance from the audit is resolved by having the timer write both).
- **Source-driven guard (built):** deal events carry a non-manual `source_type` → the interactive calendar refuses edit/drag/delete (422), correct for auto events.
- **Verification gate:** creating a deal materialises step events at the right dates; completing a step completes its event; a due-date recalc moves the event; a RAG change repaints it — all idempotent under a second `corex:calendar:reconcile`.

---

## 7. OTP + document spine (D4)

> "OTP" throughout §7–8 = **Offer to Purchase** (a document type), distinct from the one-time-PIN `OtpService` (which §8 reuses for secure-link identity verification).

**Goal:** one upload, auto-linked everywhere. A deal becomes reachable from the PDF splitter, property, contact, and deal register.

- **Unified anchor (D4):** `documents.deal_id` FK. Any `Document` can now belong to a deal, alongside its existing `document_contacts`(party_role) + `document_properties` links.
- **PDF splitter gains a deal target:** the per-page assignment UI (`/admin/settings/document-types` routing + the splitter review screen) gains a **deal** destination beside property/contact. When a deal is in context, splitting an OTP files each page group as a `Document` linked to the **deal + property + contacts** in one pass (`PdfSplitterController::fileGroupsToDestinations` extended to write `deal_id`).
- **E-sign / DocuPerfect auto-file links the deal:** `SignatureService::autoFileSignedDocument()` already files signed docs to property+contacts; the build passes the deal context through so the filed `Document` also sets `deal_id` (when the signing originated from a deal or matches one).
- **`document_signed` step auto-completion (build the wiring):** on DocuPerfect full-sign, a listener resolves the deal + the matching `document_signed` pipeline step (by `document_type_id`) and calls `DealPipelineService::completeStep()` — closing the loop the v1.1 spec promised but never wired. Idempotent (a re-fire finds the step already complete).
- **`deal_step_documents.document_id` populated:** when a step's `document_upload` runs, create/link a unified `Document` (with `deal_id`) and store its id on the step-document row, instead of an orphaned raw `file_path`.
- **OTP-upload-onto-deal action:** a direct "Upload document" on the deal detail creates a `Document` (`deal_id` set, `document_type_id` chosen, party links optional) and, if it satisfies a `document_upload`/`document_signed` step, offers to complete that step.
- **Verification gate:** upload/split/sign an OTP once → it appears on the deal, the property, and the buyer+seller contacts; the matching pipeline step auto-completes; no orphaned file.

---

## 8. Distribution matrix + distribute action (the COC killer)

### 8.1 The matrix (`deal_stage_document_rules`, §4.5)
Editable on the **existing** doc-types settings surface (`/admin/settings/document-types`, `Admin\SplitterDocTypeController`) as an added "Deal distribution" section, reusing `AgencyComplianceDocTypeService`'s resolver + raw-write pattern and the existing party-role vocabulary. A rule = `stage (pipeline_step) × document_type × party_role → { delivery_mode, auto_on_stage_tick }`. Sensible defaults seeded (e.g. COC request → electrician at the "Electrical COC" stage, secure_link, auto on the OTP-granted tick). Fully agency-configurable; nav entry added the same day.

### 8.2 Two delivery modes (configurable per doc-type × party)
- **(a) Secure link + OTP/ID verification (DEFAULT).** The recipient gets an email with a tokened link (`deal_document_distributions.secure_token`). Opening it triggers an **OTP challenge** via the canonical `App\Services\Otp\OtpService` (6-digit, hashed-at-rest, delivered to the recipient's own email/phone, single-use, throttled) — the e-sign-style identity gate. Every step is written to the **immutable** `deal_document_access_log` (link_clicked, otp_sent, otp_verified/failed, downloaded) — POPIA evidence. Only after verification does the doc stream (via an authenticated, `response()->file()` served route; never a public docroot path). Links are revocable (`status=revoked`).
- **(b) Direct attachment.** The pack is attached to a branded email (the `FeedbackReportMail` multi-attachment pattern extending `BaseSignatureMail`) — for low-sensitivity docs / trusted providers where a link+OTP is friction.

### 8.3 Distribute action + auto-triggers
- **Manual:** a **"Distribute documents"** button on the deal opens a modal that resolves, from the matrix + the deal's current stage + its parties/providers, *who gets which docs by which mode*, lets the agent confirm/adjust, then sends — creating `deal_document_distributions` rows and the comms records (§10).
- **Auto on stage tick:** when a step's `status_trigger` fires (e.g. **OTP marked granted**), any matrix rule with `auto_on_stage_tick=true` for that stage fires automatically — e.g. auto-email the **appointed provider** (electrician/entomologist, resolved via `deal_v2_contacts` → supplier directory) their pack. This is the red-button moment: agent ticks, the COC request goes out.
- **Auto-generate the COC request:** a templated document is generated from **deal + property + contact** data (address, erf, owner, agent, deal ref) — no hand-filling. It is filed as a `Document` (`deal_id` set, type `coc_request`) and distributed per the rule. **This kills HFC's hand-filled electrician/entomologist COC request.**
- **Verification gate:** configure a rule (COC request → electrician, secure_link, auto on OTP-granted); tick OTP granted on a test deal → a `deal_document_distributions` row + an outbound `communications` record (§10) + a generated COC `Document` exist; the secure link demands an OTP before serving; every access writes an immutable log row.

---

## 9. Service-provider parties + supplier directory (D2)

- **Roles:** `deal_v2_contacts.role` extended (§4.3) so a deal can name its transfer attorney, bond attorney, electrician (COC), entomologist, originator, or a generic service provider. Providers are addressed via the **deal-party role**, never a 5th contact type (contact types are hard-locked to 4).
- **Directory:** `agency_service_providers` (§4.4) is the reuse layer. On a deal, adding a provider role opens a picker: **pick an existing directory provider** (filtered by specialty, preferred first) **or create a new one inline** — which saves to the directory (`created_by_id`, agency-scoped) for next time and optionally links/creates a CoreX contact. A settings screen manages the directory (CRUD, mark preferred, deactivate — soft delete only). Nav entry added the same day.
- **Verification gate:** add "the electrician we always use" once on deal A; on deal B the same provider is one click from the picker; distribution to that role reaches the stored email; deactivating a provider hides it from new pickers but preserves historic distributions.

---

## 10. Comms tracking — every distribution on all three pillars

Extend the sanctioned outbound-archive seam `App\Services\Communications\OutboundProvisionalLogger` (AT-59/80) — do not invent a parallel path:
1. **Stamp `owner_user_id` at create** = the sending agent (AT-122 provenance), so the row is visible in the sender's own/branch scope immediately (today it's NULL until reconcile).
2. **Write `communication_attachments`** for the pack files (or a link-record for secure_link mode) and set `has_attachments=true` (today hardcoded false).
3. **Write multiple `communication_links`** — `Contact::class` (recipient) **plus** `Property::class` (the deal's property) **plus** `DealV2::class` (the deal) — the morph already supports all three (calendar already morphs to Property + DealV2); today every writer hardcodes `Contact`.
The `communication_id` is stored on the `deal_document_distributions` row (§4.6). `ProvisionalReconciler` continues to promote the provisional→confirmed row in place when the Sent-folder copy is ingested.
- **Verification gate:** a distribution email surfaces in the Communication Archive **and** on the deal timeline **and** on the property **and** on the recipient contact — one send, three pillars — with the attachment/link recorded and the sending agent as owner.

---

## 11. Notifications & escalation

Build the missing `App\Services\DealV2\NotificationService` and the two schedulers; reuse the calendar notification infra where it already exists (`CalendarNotificationDispatcher`, per-class `*_notifications`, digests).

- **`deals:process-escalations` (hourly):** overdue steps escalate agent → BM (+configurable) → admin (+configurable), reading the now-live `escalation_config`; each level fires once (idempotent, recorded in a reminders log).
- **`deals:daily-digest` (07:00):** per-user morning email — due today, overdue, turning amber/red today, deals registered yesterday.
- **Channels:** in-app (bell), email (branded), calendar colour/transition (already fires via reconcile). SMS is future.
- **Preferences (3 levels):** agency default → per-category → per-user override (reuse `.ai/specs/notification-preferences.md` infra).
- **Verification gate:** an overdue step with a 1-day BM / 3-day admin config produces exactly one BM notification at +1d and one admin at +3d (frozen clock), and no duplicates on re-run.

---

## 12. Overview surfaces

Complete the Pipeline Overview (index is table-only today):
- **Scope switcher** (own/branch/company per role) — reuse `getDataScope`.
- **Dashboard cards:** Active Deals, Overdue Steps, Due This Week, Pending Registration, Total Pipeline Value, Avg Days to Registration.
- **Board/kanban** by milestone columns; cards non-draggable (status changes only through proper step completion — anti-gaming).
- **Table view:** sortable/filterable/searchable (agent, stage, RAG, deal type, branch, date range, overdue-only; search by address/contact/ref), 25/page, empty state, **CSV export**.
- **Per-user iCal feed** (`…/calendar/ical/{token}.ics`, `?scope=branch|company`) — reuse the calendar `ical_token`; deal events already flow through the calendar so the feed is largely wiring.
- **Verification gate:** each card count matches a direct query on a seeded set; the board places each deal in its current-milestone column; CSV row count = filtered result count; the iCal feed validates and contains the deal step events.

---

## 13. DR1↔DR2 sync, parity harness & transition (D1, D3)

### 13.1 Link
`deals.deal_v2_id` ↔ `deals_v2.legacy_deal_id` (§4.2). Nullable both sides; a DR1 deal and its DR2 twin point at each other.

### 13.2 `DealSyncService` (single-writer mirror, `ContactIdentifierService` pattern)
- **One service owns the shared-field invariant.** Mirrors only: status (mapped DR1 `accepted_status`/`commission_status` ↔ DR2 `status`), granted/registration dates, party names, commission totals. **DR2-only concepts (pipeline steps, distributions) never mirror back to DR1.**
- **Thin observers on both sides** (`DealObserver`, `DealV2Observer`) only *call* the service; the service never re-triggers itself (quiet writes, no recursion), wrapped in `DB::transaction`, and **idempotent** — a re-run converges.
- **Mapping table** for the status axes is explicit and agency-agnostic (DR1's two-axis P/G/R/D + Paid ↔ DR2's `active/completed/cancelled/on_hold` + a derived registration/commission state). Documented in the service; the reconciliation (§13.3) is the safety net for anything the mapping can't express.

### 13.3 Parity harness — HFC's real backfilled deals as truth set
- The **131 live `deals` (Oct 2025 → now)** are the parallel-run truth set. Build a `deals:parity-check` command that, for every linked pair, compares the shared fields and reports mismatches (agency-scoped, read-only, `--dry-run` default). Feature tests seed real-shaped SA deals (BUILD_STANDARD §5 — real addresses/prices/messy data, not "Test/0000").
- **Verification gate:** create/edit a DR1 deal → the mirror appears/updates in DR2 (shared fields only) and vice versa; `deals:parity-check` reports **0 mismatches** across the linked set; a status change on either side lands on the other; no pipeline data leaks into DR1.

### 13.4 Transition & retirement (Leave precedent)
Once parity holds: run the **human reconciliation** (Elize/Falan/Johan validate DR2 against DR1 as the Leave/payroll procedures did), sign it off (filed against the agency), then **retire DR1's UI** (route/nav removed behind a flag). The `deals` rows are **never deleted** — they remain as the audit trail, `deal_v2_id` pointing at the operational DR2 record (the TrackedProperty `promoted_to_*` doctrine). DR1 integrity is intact throughout (D3).

---

## 14. Navigation & permissions

- **Sidebar (DR2 group, existing):** Deal Register (overview) · New Deal · Pipeline Setup (admin/BM) · Calendar. **Add:** Supplier Directory (settings), and a Deal-distribution section on the doc-types settings page. Every new page ships its nav link the same day (non-negotiable #2).
- **Permissions (existing + new):** `deals_v2.view` (own/branch/all), `deals_v2.create`, `deals_v2.edit`, `deals_v2.archive`, `deals_v2.manage_pipeline`, `deals_v2.override_dates`; **new** `deals_v2.distribute_documents`, `deals_v2.manage_distribution_rules`, `deals_v2.manage_suppliers`. Registered in `config/corex-permissions.php` + `corex:sync-permissions --merge-defaults`; sidebar-gated + route-middleware + controller checks (non-negotiable #5).
- **API:** any new JSON endpoint under `/api/v1/*` with a `->name()`, discoverable in the Admin→API catalogue (non-negotiable #7).

---

## 15. Build sequence (one continuous build; each work-stream ends at a verification gate)

Sequenced, not phased-for-deferral. **WS0 leads** because an untested engine is about to become canonical.

- **WS0 — Engine hardening + RAG timer (FIRST).** Feature tests for the *existing* engine (createDeal, chain recalculation, completeStep pos/neg, status_trigger, BM-approval gate, expected-registration). Build `deals:process-rag` + wire `updateDealOverallRag` + persist RAG colour to calendar events. *Gate:* engine test suite green + a clock-driven step transitions RAG and repaints its calendar event. **Re-run `schema:dump` after any migration; run only the single relevant test file during active work (non-negotiable #13).**
- **WS1 — DR1↔DR2 link + `DealSyncService` + parity harness** (§13). *Gate:* `deals:parity-check` = 0 mismatches on the linked truth set; two-way shared-field mirror proven; no pipeline leak into DR1.
- **WS2 — Provider roles + supplier directory** (§9). *Gate:* pick-or-create-inline works; reuse across deals; soft-delete preserves history.
- **WS3 — Document spine** (§7): `documents.deal_id`, PDF-splitter deal target, e-sign auto-file deal link, `document_signed` auto-completion, `deal_step_documents.document_id` population, upload-onto-deal. *Gate:* one upload/split/sign links deal+property+contacts and auto-completes the step; no orphaned file.
- **WS4 — Distribution matrix + distribute action + COC generation** (§8). *Gate:* configured rule fires on stage tick; secure-link demands OTP before serving; generated COC filed + sent; immutable access log written.
- **WS5 — Comms archive extension** (§10). *Gate:* one distribution shows on deal + contact + property, with attachment/link + agent owner.
- **WS6 — Notifications + escalation** (§11). *Gate:* exactly-once BM/admin escalation on a frozen clock; digest content correct.
- **WS7 — Overview surfaces + iCal** (§12). *Gate:* card counts match queries; board placement correct; CSV/iCal validate.
- **WS8 — Transition** (§13.4): reconciliation, sign-off, DR1-UI retirement behind a flag, `deals` preserved as audit. *Gate:* parity signed off; DR1 rows intact; DR2 canonical.

Each WS closes with the CLAUDE.md done-checklist (`php -l`, view/route/cache clear, the single relevant test file, functional Tinker verification, commit+push to Staging, demo parity if data-shape changed, CHAT_STARTER update). **No WS ships with a documented "good enough for now" compromise** (Operating Principle).

---

## 16. Robustness, doctrine & POPIA (applies to every WS)

- **No hard deletes** anywhere — SoftDeletes on every new model; distributions/providers/rules archive, never delete; DR1 rows survive cutover.
- **Input-space rule** (BUILD_STANDARD §2): every new field handles required-empty (clear reject), optional-empty (graceful), malformed (validated), the lazy-but-valid shortcut, whitespace, wrong order. Every NOT-NULL column gets a value for every input combination. A deleted-related-record (provider, contact, property, linked deal) renders gracefully, never a 500.
- **Prevent-or-absorb** decided at spec time for each breaking input: e.g. a distribution rule pointing at a party the deal doesn't have → the distribute modal skips it with a visible note (no silent drop, no crash); a secure link opened after revoke → a friendly "link no longer available" page, logged.
- **POPIA:** secure-link mode is the default for anything with personal data; every access is OTP-gated and written to the immutable `deal_document_access_log`; direct-attachment is opt-in per doc-type×party. Owner PII in generated COCs follows existing egress rules.
- **Multi-tenancy:** every new table `BelongsToAgency`; no `withoutGlobalScope` in request code (the sync service's cross-scope reads are the audited exception, like the reassign-capture-owner command).
- **Tests mirror reality** (BUILD_STANDARD §5): real SA deals from the backfill, each-empty paths, the shortcut, malformed, deleted-relation, idempotency (sync + reconcile + auto-triggers must all be safe to re-run).

---

## 17. Acceptance criteria (definition of done for the programme)

1. DR2 engine has a green feature-test suite covering createDeal/chain/complete/status-trigger/BM-approval, and `deals:process-rag` transitions persisted RAG + calendar colour on a clock. **(WS0)**
2. DR1↔DR2 shared-field mirror is bidirectional and `deals:parity-check` reports 0 mismatches across the 131-deal truth set; no pipeline data leaks into DR1; DR1 behaviour unchanged. **(WS1/13)**
3. A provider is added once and reused across deals; distribution reaches the stored address. **(WS2/9)**
4. One OTP upload/split/sign links deal+property+contacts and auto-completes its pipeline step; no orphaned files. **(WS3/7)**
5. A configured matrix rule auto-distributes on a stage tick; secure-link demands OTP before serving; a COC request is auto-generated from deal/property/contact data (no hand-filling) and filed; every access is logged immutably. **(WS4/8)**
6. Every distribution appears on the deal, contact, and property, with attachment/link and the sending agent as owner. **(WS5/10)**
7. Overdue escalation fires exactly once per level; digests are correct; overview cards/board/CSV/iCal are correct and scoped. **(WS6–7)**
8. Reconciliation signed off; DR1 UI retired behind a flag; `deals` rows preserved as audit; DR2 canonical. **(WS8)**
9. Every new page has a nav link + permission gate; every new endpoint is under `/api/v1/*` and in the API catalogue; no hard deletes; input-space + POPIA rules met. **(all WS)**

---

## 18. What this replaces (unchanged from v1.1 — the human wins)
Spreadsheet tracking → visual pipeline + RAG. BM chasing agents → real-time dashboard + escalation. Forgotten bond deadline → phone-calendar reminder. Unknown deal status → company-wide view. Late addendum → RED alert days early. Manual commission → auto-linked settlement. **Hand-filled COC request → one-tick auto-generated + distributed.** "Where is the COC?" emails → step status visible to all. A deal falling through the cracks → impossible; every step, document, and distribution tracked.
## 19. AT-305 — DR2 pipeline screen two-column redesign (layout/density only)
`resources/views/dr2/pipeline.blade.php` (`Dr2\PipelineController@show`). Presentational only — no controller/route/model/DB change; all functionality preserved.

- **Left column (≈60%, `lg:col-span-3`):** the pipeline step board condensed to **dense one-liner rows** — `status dot · step name (· ◆ milestone / · + custom) · due date · status badge`, with **Complete / N-A / Reinstate / Edit due / Remove / Comments(n)** as **compact inline actions** that expand their forms **in place** (N/A reason, due-date edit, comment thread + post + attach-document-to-step). Removed-steps and Add-custom-step blocks unchanged. Blocked/excused notes render as a thin sub-line only when present.
- **Right column (≈40%, `lg:col-span-2 lg:sticky lg:top-4 self-start`):** a **sticky, independently-scrolling rail** modelled on the buyer viewing-pack screen (`command-center/viewing-packs/show.blade.php` — `grid grid-cols-1 lg:grid-cols-5 gap-4 items-start`). Holds the relocated **Documents** + **Send documents to a party** (unchanged `@include('dr2._deal-documents')`) and **Proforma Invoices** (`@include('proforma._deal-section')`). **Stacks to one column on mobile** (`grid-cols-1`).
- **Preserved:** every route, `@csrf`, `@permission('view_deals')` gate, confirm dialog, AT-244 lock-mute, and the empty/attach/declined states.
- **Acceptance (on-site, deal 156):** two columns render; steps read as one-liners with working inline actions; the right rail shows Documents/Send/Proforma and scrolls independently; mobile stacks.

### AT-305b — Johan re-test fixes (independent scroll + hide-completed)
- **True dual-pane independent scroll (fix).** The first cut used `lg:sticky` — still one page scroll (scrolling the pipeline moved the docs rail off-screen). Replaced with a scoped `<style>` (desktop `@media (min-width:1024px)`): the grid gets a bounded height `calc(100vh - 9.5rem)` (class `dr2-pipe-grid`) and **each column** (`dr2-pipe-col`) is its own `overflow-y:auto; max-height:100%; overscroll-behavior:contain` region. Scrolling the left (steps) never moves the right (docs/parties/proforma) and vice-versa; the page does not scroll the columns off. Scoped CSS (not arbitrary Tailwind) so it applies on qa1 without a CSS rebuild. On mobile the media query is inert → columns stack and scroll with the page.
- **Hide-completed toggle (enhancement).** A "Hide completed steps" checkbox at the top of the step board hides completed/terminal (`completed`/`skipped`) step rows so the user sees only outstanding/pending entries. Default **off** (show all). The choice **persists per user** via `localStorage['dr2_hide_completed']` (survives revisits, no migration; a per-browser user preference). Terminal rows carry `x-show="!hideDone"`; the toggle only appears when there are completed steps to hide, with an "(N hidden)" hint when active.
- **Acceptance (deal 156):** left and right columns each scroll independently (scroll the steps → docs rail stays put; scroll the rail → steps stay put); ticking Hide-completed removes the done rows leaving only outstanding ones; the preference persists on reload.
