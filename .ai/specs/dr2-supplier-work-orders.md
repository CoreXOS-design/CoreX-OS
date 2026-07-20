# DR2 Wave 3 — Pipeline-step supplier work-orders (AT-229)

> Spec. Johan's design + his 2026-07-17 rulings.
> **Status: BUILT on QA1.** Generator + form + send + return-file landed `e39606f2` (2026-07-17);
> the **config surface** (pipeline-builder tickbox: send-work-order + service type + trigger point)
> and the **runtime "Send work order" action** (deal pipeline step, Non-neg #2 entry point) landed
> 2026-07-19. See §12.
>
> **§10a Setup Wizard — deliberately NOT in the onboarding wizard (Johan's call to confirm):** the
> work-order toggle is *per-pipeline-step* config, set in the DR2 pipeline builder (per step, per
> agency), not an agency-wide switch the onboarding wizard walks. It is configured exactly where the
> rest of a step's behaviour is. Recorded here as a deliberate exclusion per §10a step 3.

## 1. What & why (business requirement)
An agent, working a DR2 deal's pipeline, reaches a step that needs an outside tradesman
(electrical COC, entomologist, plumber, gas, etc.). CoreX should let them, **optionally**,
send that supplier a **work-authorisation / instruction PDF** (auto-built, no hand-filling),
and later **upload the returned CoC/result/invoice** so it auto-files to the right pillar.
It is a "nice to have" convenience layered on the pipeline — **never compulsory**.

## 2. Johan's rulings (the design anchors)
- **(Q1) Trigger is defined in PIPELINE SETUP, per agency — no hard-coded setting.** When an
  agency configures a pipeline step, a tickbox + link declares that this step can send a
  document / instruction email, and links WHICH document (template) to the step.
- **(Q2) The supplier is NEVER preselected — always picked from the list at SEND time.**
  Suppliers are creatable ad-hoc at that moment (seller assigns their own tradesman → the
  agent captures them as a supplier, adds, sends if needed). **The whole send is OPTIONAL
  per use — never compulsory.**
- **(Q3) The authorisation template = Johan's Monday form → CoreX auto-builds it ready-to-send**,
  DocuPerfect-template-driven (per the investigation recommendation).
- **(Q4) The agent uploads returns; they auto-file per the existing document-type rules.**
- **(Q5) Address to the supplier's PRIMARY contact, selectable.**
- **(Q6) The work authorisation is a CATALOGUED `document_type`.**

Consequence of Q2: **there is NO supplier link on the step.** The step config links a
*template*; the *supplier* is chosen (or created) at send time, every time.

## 3. Pillars
- **Deal** (`DealV2`) — the work-order is raised in the deal's pipeline; outbound + return both link the deal.
- **Property** — a returned CoC files to the property (per type rules).
- **Contact** — the supplier (firm + primary contact) and the "responsible contact" a returned invoice files to.
- **Agent** — raises + sends; audit records who.

## 4. Data model / migrations
Small — most infra exists (see §7). New:
1. **New catalogued `document_type`: `work_authorisation`** (Q6) — seeded global reference row
   (travels via `deploy:sync-reference-data`, per AT-162). Flows through the AT-227 matrix + audit.
2. **`docuperfect_templates.template_type = 'work_authorisation'`** — the authorisation template,
   bound to the `work_authorisation` document_type, built from Johan's Monday form; token/merge via
   the existing `field_mappings` engine (deal/property/supplier/agent/agency + a free work-description).
3. **Pipeline-step config (on `deal_pipeline_steps`, the template):**
   - `work_authorisation_template_id` (nullable FK → `docuperfect_templates`) — links the doc to the step (Q1).
   - `work_order_trigger_point` (nullable enum `activated|completed`, default `activated`) — the "when",
     set in pipeline setup (Q1: no hard setting). NULL / no template = the step offers no work order.
   - **No supplier column** (Q2).
4. **Reuse `deal_document_distributions`** for the outbound audit (already has `recipient_provider_id`
   FK + `recipient_contact_id` + `recipient_email` snapshot). Add nothing.

## 5. User flow
**Setup (agency, pipeline builder — m1's surface, coordinate):** on a step, tick "send a work
order / instruction on this step", pick the trigger point, and link a work-authorisation template.

**Runtime (agent, deal pipeline):** when a step whose config links a template reaches its trigger
point, CoreX surfaces an **optional** "Send work order" action (skippable — never blocks the step):
1. CoreX **auto-builds the authorisation PDF** from the linked template merged with this deal/property/agent/agency (+ an editable work-description) — ready to send, no hand-filling (Q3).
2. Agent **picks the supplier from the list, or creates one ad-hoc** (firm + primary contact) — never preselected (Q2, Q5); addressed to the supplier's selectable **primary contact** email.
3. Send → `DealDistributionService::send()` emails the supplier the PDF, logs `deal_document_distributions` + the Communication archive.

**Return (agent):** the supplier replies (out of band); the agent **uploads the returned CoC /
result / invoice** via the existing deal-document upload; it **auto-files per the document-type
rules** — CoC → property, invoice → responsible contact (AT-105 `save_to_property/save_to_contact`),
and auto-completes the matching step (Q4). Audit-logged.

## 6. Permissions
Reuse the DR2 deals / distribution permission set (no new keys expected — confirm the send action
sits under an existing deal-distribution/edit permission during build).

## 7. Reuse vs build (from the AT-229 investigation)
**Reuse as-is:** `AgencyServiceProviderService` (supplier + ad-hoc create + primary contact);
`DealDistributionService::send()` + `DealDocumentDeliveryMail` (PDF attach) + `deal_document_distributions`
(supplier audit); `DealDocumentService` + AT-105 destination flags (return-and-file);
`CocRequestGenerator` (render+file pattern to generalise).
**Build:** the 3 schema items (§4); generalise `CocRequestGenerator` → a template-driven
`WorkAuthorisationGenerator` (renders the linked template, files as `work_authorisation`);
the runtime "Send work order" action + supplier picker/create UI; refine
`DealDistributionService::recipientsForRole()` (or a new supplier-at-send path) to address the
chosen supplier's **primary contact** rather than the firm email; the pipeline-setup step config
(m1's half — coordinate on the link + trigger fields).

## 8. Acceptance criteria
- In pipeline setup, a step can link a work-authorisation template + trigger point (Q1); a step with none offers no work order.
- At the trigger point, the "Send work order" action is **optional/skippable** (Q2) and the PDF is pre-built ready-to-send (Q3).
- The agent picks or ad-hoc-creates a supplier at send (never preselected, Q2); it addresses the supplier's primary contact (Q5); Mailpit catches the email with the PDF attached.
- Send is audit-logged in `deal_document_distributions` (+ Communication archive).
- A returned CoC uploaded by the agent files to the property; a returned invoice files to the responsible contact — both per the existing type rules (Q4); auto-completes the matching step; audit-logged.

## 9. Deliberately NOT in scope
- No supplier stored on the step (Q2 — chosen at send).
- Send is never automatic/compulsory (Q2).
- No new rule table for returns — reuse AT-105 `save_to_*` (Q4).
- No supplier-reply-email auto-intake — returns are agent-uploaded (Q4).

## 10. Files to create / modify (Monday build)
- Migration: `document_type` seed (`work_authorisation`); `deal_pipeline_steps` +`work_authorisation_template_id`,`work_order_trigger_point`.
- `docuperfect_templates` — new `template_type='work_authorisation'`; build the template from Johan's Monday form.
- New `App\Services\DealV2\WorkAuthorisationGenerator` (generalise `CocRequestGenerator`).
- Runtime action + supplier picker/create UI on the deal pipeline (coordinate step-config UI with m1).
- `DealDistributionService` — primary-contact addressee resolution for the chosen supplier.
- Reuse: `DealDocumentService` (+AT-105) for the return-and-file; `deal_document_distributions` audit.

## 11. Dependency / sequencing
**BLOCKED on Johan's Monday example work-order form** → CoreX builds the `work_authorisation`
template from it → then build. Coordinate G1 (step-config link) + the trigger with m1 (AT-216 pipeline).

## 12. Build record (2026-07-17 → 2026-07-19)
**`e39606f2` (2026-07-17):** `WorkAuthorisationGenerator` (auto-fills 17 fields from the deal, renders
the HFC work-authorisation form to PDF, files it as the catalogued `work_authorisation` doc-type);
`documents/work-authorisation.blade.php`; migration (doc-type + per-step columns
`sends_work_order` · `work_order_service_type` · `work_order_trigger_point`); `WorkOrderController`
(`form`/`send`) + routes; returns reuse `DealDocumentService` + AT-105 `save_to_*`.

**2026-07-19 (this session) — the two surfaces that made it usable + configurable:**
- **Config (pipeline builder):** `deals-v2/pipeline-setup/edit.blade.php` gains a "Send a supplier work
  order on this step" tickbox + service-type + trigger-point selects; `DealPipelineStepController`
  (`validateStep`/`formatStep` + locked-allow) and `DealPipelineSetupController::edit` round-trip the
  three columns. Gated on `deals_v2.manage_pipeline`.
- **Runtime (deal pipeline, Non-neg #2 entry point):** `deals-v2/show.blade.php` active/at-trigger step
  gains an OPTIONAL "Send work order" action → modal that loads the auto-filled fields + supplier list
  (`work-order.form`), lets the agent edit fields, pick or ad-hoc-create the supplier, choose its
  primary contact, and send (`work-order.send`). Shown only when the step's config `sends_work_order`
  is on and the step is at its `work_order_trigger_point`. Gated on `deals_v2.distribute_documents`.
- **Tests:** `tests/Feature/DealV2/DealPipelineWorkOrderConfigTest.php` (config round-trip + validation).
- **Permissions:** reused `deals_v2.manage_pipeline` (config) + `deals_v2.distribute_documents` (send) —
  no new keys (§6).

## 13. Runtime surface correction (2026-07-19) — LIVE = DR1 pipeline

Investigation during the runtime build found `DealV2Controller::show()` is **soft-retired (AT-219)**:
the only live deal-pipeline surface is **`dr2/pipeline.blade.php`** (`Dr2\PipelineController::show(Deal $deal)`,
route `deals-dr2.pipeline`), which runs on the **DR1 `Deal`** model — all live `DealStepInstance`s carry
`dr1_deal_id`, `deal_id` (DealV2) is null. So the runtime action was wired there (Johan's ruling:
target dr2/pipeline, generalise to DR1, audit on the twin like every other distribution; editing m1's
view is fine — CoreX owns files).

- **Runtime action** lives on `dr2/pipeline.blade.php`'s per-step action bar (Non-neg #2), gated on
  `$s->pipelineStep?->sends_work_order` + trigger point + `deals_v2.distribute_documents`. Modal loads
  `deals-dr2.pipeline.step.work-order.form`, agent edits fields + picks/ad-hoc-creates the supplier +
  chooses its primary contact, posts to `…work-order.send`.
- **`WorkAuthorisationGenerator` generalised** to accept a DR1 `Deal|DealV2` (maps
  sellers/buyers/property/listing agent/reference); DR1 files via
  `DealDocumentService::fileDealDocumentFromDeal` (deal-twin + property + property-contacts + the step).
- **Send reuses the shipped AT-228 DR1 path** `Dr2DistributionSendService::sendToParty` — mints the
  on-demand DealV2 twin (as every DR1 distribution does), attaches the PDF, audits
  `deal_document_distributions` via `deal_v2_id` with `recipient_provider_id`. No new audit schema.
- `WorkOrderController::dr1Form`/`dr1Send` + routes `deals-dr2.pipeline.step.work-order.{form,send}`.
- The earlier `deals-v2/show` button + DealV2 `WorkOrderController::form/send` remain but are **inert**
  (retired view) — superseded by the DR1 action; left in place for the DealV2 model if ever un-retired.

**Cross-lane note (per Johan's ruling):** this edits `dr2/pipeline.blade.php` (m1/AT-216) and reuses
`Dr2DistributionSendService` (AT-228). Flagged on the ticket; git resolves any merge clash.

**On-site proof (deployed qa1, real DR1 deal #155 / active step #13):** button gate true + action in the
live view; `dr1Form` → 17 auto-filled fields + supplier list; `dr1Send` → `work_authorisation` PDF filed
to the deal+step, emailed to the picked supplier (Mailpit), audited in `deal_document_distributions`
(recipient_provider_id, twin `deal_v2_id`). Self-cleaning proof; step config restored.

## 14. Multi-supplier work orders (on-site 2026-07-20) — a step triggers SEVERAL

Johan's on-site test: a single "Certificates of Compliance" step needs to trigger *several* COCs at
once (Electrical, Gas, Beetle, Plumbing), each its own supplier + service. The per-step config moved
from a single `{sends_work_order, service_type, trigger}` to a **collection**.

- **Data model:** new `deal_pipeline_step_work_orders` (pipeline_step_id · agency_id · service_type ·
  trigger_point · sort_order · softDeletes) + `DealPipelineStepWorkOrder` model +
  `DealPipelineStep::workOrders()`. Migration backfills every existing single-config into a one-entry
  collection; the legacy columns are LEFT in place (no hard delete) and mirrored from the first entry.
- **Config UI:** the block is a **repeatable list** — add/remove rows, each = service type + trigger
  timing (Activated/Completed). `DealPipelineStepController::syncWorkOrders` rebuilds the collection from
  the posted `work_orders` array (removed rows soft-deleted).
- **Runtime:** `dr2/pipeline.blade` renders **one "Send work order — {service}" per configured entry**
  matching the step's trigger state; each opens its own modal, supplier chosen/captured per entry,
  posts its `service_type`. `WorkOrderController::dr1Form/dr1Send` take the entry's service type; each
  send = one work order + one AT-228 audit (loops naturally via N independent buttons).
- **On-site proof (deployed qa1, real deal / active step):** 2 entries (COC + Gas) → 2 per-entry
  buttons render → each send generates a distinct `work_authorisation` PDF + AT-228 distribution row.

### 14.1 Save-block hotfix (same screen)
Johan also hit **"the selected negative status trigger is invalid"** saving a step — root cause: the
"Bond Approved" steps carry a legacy `negative_status_trigger='declined'` the current select can only
render as None/Cancelled, so it silently re-posts `'declined'` and `in:cancelled` rejects it.
`DealPipelineStepController::normalizeOptionalTriggers` now coerces every OPTIONAL trigger enum
(`status_trigger`, `negative_status_trigger`, `work_order_trigger_point`, `trigger_step_id`) to
null/default when the posted value is out of the current set — class-wide, so None/legacy is always
valid and a legitimate step never fails to save. Test: `tests/Unit/PipelineStepTriggerNormalizeTest.php`.

## 15. COC sub-process — per-deal selection, responsible party + recipient, CC & de-dupe (on-site 2026-07-20)

Johan's full COC sub-process, layered on §14's multi-supplier config. §14 said *which COC service
types a step CAN trigger* (template config). §15 is the **runtime, per-deal** layer: on a real deal the
agent picks *which of those COCs THIS deal actually needs*, and for each one *who is responsible* and
*who receives it*.

### 15.1 Data model — runtime selection
New `deal_step_work_orders` (per-deal, per-instance — NOT the template config):
`deal_step_instance_id · dr1_deal_id · agency_id · service_type · responsible_party (default 'supplier')
· service_provider_id · recipient_name · recipient_email · cc_emails · status (pending|sent) ·
document_id · sent_at · sent_by_id · softDeletes`. FK → `deal_step_instances` cascadeOnDelete.
`DealStepWorkOrder` model — `RESPONSIBLE = [seller, listing_agent, selling_agent, supplier,
transfer_attorney]`; `isSent()`.

### 15.2 Responsible party → recipient resolution
`CocWorkOrderService::resolveRecipient(Deal,DealStepWorkOrder)` switches on `responsible_party`:
- **seller** (self-handling) → `deal->sellers()->first()` (Contact).
- **listing_agent** → `deal->listingAgents()->first()` (User).
- **selling_agent** → `deal->sellingAgents()->first()` (User).
- **transfer_attorney** → the twin `DealV2`'s attorney provider-party (else `service_provider_id`).
- **supplier** → `service_provider_id` (AgencyServiceProvider).
A responsible party with no resolvable email throws `DomainException` (surfaced as 422) — never a
silent no-send.

### 15.3 CC the listing + selling agents, de-duped
`CocWorkOrderService::ccList(Deal, primaryEmail)` collects the listing + selling agent emails, keys them
by **lowercased address** (so listing==selling collapses to one), and drops the primary recipient's own
address. One unique address = one email. Threaded into AT-228 send via a new `$cc` arg on
`Dr2DistributionSendService::sendToParty`/`deliver` (single `Mail::to()->cc()`).

### 15.4 Runtime UI — the COC panel
`dr2/pipeline.blade` — a step that carries a work-order config (`pipelineStep->workOrders` non-empty)
and is active/completed shows a **"Work orders (COCs)"** button opening a panel: one row per COC =
service type (from the offered set) + responsible party + supplier picker (when supplier/attorney) +
Send + live status. Add/remove COC; **Save** = `cocSync`; **Send** per row = `cocSend`.
- `WorkOrderController::cocPanel` → JSON (offered_types, responsible_labels, work_orders, suppliers).
- `cocSync` upserts the selection; **a sent row is never re-written** (audit integrity); removed
  non-sent rows soft-deleted.
- `cocSend` → `CocWorkOrderService::send` (resolve → generate PDF → AT-228 file+email with CC → stamp
  status=sent + document_id + recipient/cc snapshot).
- Routes: `deals-dr2.pipeline.step.coc.{panel,sync,send}` (`permission:deals_v2.distribute_documents`).

### 15.5 Decision steps fire the forward chain ONLY on the positive outcome (item 6b)
A **decision step** = a step with a `negative_status_trigger` configured (e.g. "Bond Application" with a
"Bond Declined" branch). `Dr1PipelineService::completeStep` now accepts an `outcome`:
- **positive** (default) → activates downstream successors + applies `status_trigger` (unchanged).
- **negative** → completes the step, applies `negative_status_trigger` (declined/cancelled → 'D'), and
  **never** activates the positive-path successors.
The DR1 pipeline action bar shows a red **"{negative_outcome_label}"** button (only when the step has a
negative branch); the positive button reads "Mark passed" for a decision step, "Mark complete"
otherwise. `Dr2\PipelineController::completeStep` only honours `outcome=negative` when the step actually
has a negative trigger.

### 15.6 On-site proof (deployed qa1 `e45623a4`, real deal #156 / active step #16, listing==selling==Barbara)
Driven through the real controller path:
- PANEL offers COC + Gas; 5 responsible options.
- SYNC creates 2 work orders to **different** parties (COC→listing_agent, Gas→supplier).
- SEND: COC → `barbara@hfcoastal.co.za`, **CC empty** (primary == both agents, de-duped away);
  Gas → `gas-coc@example.com`, **CC = the one agent** (listing==selling collapsed). Confirmed in
  Mailpit: `TO gas-coc@example.com / CC barbara@hfcoastal.co.za`.
- TWO distinct `Documents` generated; **+2** AT-228 `deal_document_distributions` rows on the twin.
- IDEMPOTENT re-sync: the sent COC row is preserved (responsible_party unchanged, no duplicate).
- 6b (constructed decision step, rolled back): NEGATIVE → parent completed, downstream **not_started**
  (not activated), deal → 'D'; POSITIVE → parent completed, downstream **active**, deal → 'G'.
All proofs self-cleaning (WO rows, docs, audits, supplier email, WO config reverted; 6b rolled back).

## 16. Agency-configurable COC / service-type list (on-site 2026-07-20)

The COC/service-type dropdown in the pipeline step-config was a **hardcoded** array
(`COC / Beetle / Gas / Electric Fence / Plumbing / Other`). It is now an **agency-owned,
agency-configurable** list — each agency curates its own certificates & services.

### 16.1 Data model
New agency-scoped table `agency_service_types` — `agency_id · code · label · sort_order ·
is_active · softDeletes`. `App\Models\DealV2\AgencyServiceType` (BelongsToAgency + SoftDeletes).
- **`code`** = the STABLE value stored on a work order (`service_type` on both
  `deal_pipeline_step_work_orders` and `deal_step_work_orders`). Renaming a label never
  rewrites a configured step. **`label`** = agent-facing name.
- `AgencyServiceType::DEFAULTS` = the exact historical hardcoded set; `seedDefaultsFor($agencyId)`
  is idempotent. The **migration backfills every existing agency**; the **AgencyObserver** seeds
  each new agency — so the dropdown is never empty and nothing breaks. Per-agency seed (not global)
  → no `deploy:sync-reference-data` entry needed.

### 16.2 Settings screen (full CRUD)
`deals-v2/settings/service-types` — `AgencyServiceTypeController` (index/store/update/destroy/restore).
Add / edit (label · order · active) / **soft-delete** (archive) / restore. New types get a slugged
`code`; a live-code-uniqueness guard blocks duplicates. **Nav (Johan's ruling — agency-configurable
lists live under Settings):** listed on the **Settings page rail** (`corex.settings`, Operations
group) as a `type=>'link'`, the exact pattern of Document Types / DocuPerfect Types / P24 Suburbs —
**Sidebar → Settings → COC / Service Types**. Gated `deals_v2.manage_pipeline` (the same gate as
pipeline setup — no new permission key). NOT under the Admin group and NOT under the (retired,
`@if(false)`-hidden) deals-v2 nav group.

### 16.3 The dropdown reads the list
`DealPipelineSetupController::edit` passes `$serviceTypes` (agency active list, ordered) to
`pipeline-setup/edit`; the hardcoded `<option>`s are replaced by a loop over it. The runtime COC
panel (`dr2/pipeline` + `WorkOrderController::cocPanel`) also reads the list and shows **labels**
(code→label map, withTrashed). **No silent drop:** a `code` a step already stores whose type was
later archived stays selectable in-context, marked "(archived)" — the archive only removes it as a
NEW choice, never mid-config.

### 16.4 On-site proof (deployed qa1 `d49b4ba0`, agency 1, template #1)
Migration ran; **12 rows across 2 agencies** (6 defaults each). Through the real controllers:
index shows the 6 seeded defaults; ADD "Solar PV Certificate" (code `solar_pv_certificate`);
RENAME Gas label → "Gas Compliance Certificate" with **code 'Gas' unchanged**; ARCHIVE Beetle →
soft-deleted, gone from the active list; **the step-config dropdown reflects all of it** (new type
in, renamed label shown, Beetle excluded); RESTORE Beetle; agency-scoped (my 6+ vs another agency's
6 not visible). Settings page + edit page both **render** over the web stack. Proof rolled back —
list restored to seeded defaults.

### 16.5 Deliberately pending Johan's call — Setup Wizard (Non-negotiable #10a)
This adds an agency setting (the COC/service list). Per #10a a new setting normally also surfaces in
the Agency Onboarding Setup Wizard. This is arguably an expert/rarely-touched list an agency curates
once running, seeded sensibly by default — a legitimate candidate for "not in the wizard". **Flagged
to Johan for the call; not added to `config/agency-onboarding-copy.php` pending his word.**

## 17. Right-panel Supplier Work Orders (up-front config, trigger-driven) — Johan FINAL 2026-07-20

Replaces the inline-per-step attempt. ONE right-hand panel on the DR2 pipeline (`dr2/pipeline`)
configures every COC up front; the sends fire automatically off a trigger step.

- **Panel:** a "Supplier Work Orders" panel in the pipeline right rail. Lists the agency's active
  COC/service types (Settings → COC / Service Types). Header shows the trigger step (default
  "Bond Granted").
- **Per type the agent sets:** ☐ Applies · Responsible party (seller / listing agent / selling agent /
  supplier / transferring attorney) · Recipient (auto from responsible; supplier/attorney → pick from
  directory). Saved to `deal_step_work_orders` (already exists) keyed to the type's matching COC step.
- **Auto-N/A cascade:** on Save, every COC/service type that is NOT ticked → its matching pipeline step
  is marked **N/A** (existing `markNotApplicable`, reason "not required — supplier work orders"). Re-tick
  → reinstate. Ticked ones stay live.
- **Trigger-driven send:** when the configured trigger step (e.g. "Bond Granted") COMPLETES, every ticked
  work order is sent — PDF generated, filed via AT-228, emailed to the recipient. Fires from
  `Dr1PipelineService::completeStep` after the step completes (positive outcome only).
- **CC + de-dupe:** each send CCs the listing + selling agents, de-duped by lowercased address, primary
  dropped (existing `CocWorkOrderService::ccList`).
- **Idempotent:** a work order already `sent` is never re-sent; the trigger send skips sent rows.
- **Reuses:** `deal_step_work_orders`, `CocWorkOrderService` (resolveRecipient/ccList/send),
  `AgencyServiceType` list, AT-228 `Dr2DistributionSendService`. New: panel UI + `cocConfig` save
  endpoint + trigger hook in `completeStep`.
- **Trigger step config:** which step fires the sends is defined in PIPELINE SETUP — the granting
  step (the step whose `status_trigger`='granted', i.e. Bond Granted/Approved). It is **not** selected
  on the deal panel. **Superseded 2026-07-20 — see §19** (the per-panel trigger dropdown was removed;
  the trigger is read from pipeline setup, never re-selected on the deal).

### 17.1 On-site proof (deployed qa1 `a5af6fdd`, deal_no 1804 = deal #155)
Trigger step = #28 "Bond Approved". `matchStep` maps Electrical COC→step34, Beetle→35, Gas→36,
Electric Fence→37 (Plumbing/Other = no step). Via the real controllers:
- PANEL lists the agency's 4 LIVE COC types (Plumbing/Other archived → excluded); trigger default #28.
- SAVE: tick Electrical (supplier) + Gas (listing agent), untick the rest → 2 work orders created
  (trigger_step_instance_id=28); **un-ticked Beetle + Electric Fence steps auto-cascade to N/A**,
  ticked Electrical step stays live.
- FIRE: completing "Bond Approved" sent BOTH — **Mailpit: TO electrician@example.com / CC
  rochelle; TO rochelle (listing agent) / CC none (self-CC de-duped)**. 2 distinct Documents + 2
  AT-228 audit rows. Sent rows never rewritten.
Also: "Mark passed"/"Mark complete" now renders as a bordered green button like its siblings.

## 18. PLACEMENT CORRECTION (Johan FINAL) — right-hand panel, NOT a modal

§17's first build wrongly floated a **modal popup** ("Supplier Work Orders — which COCs does this
deal need?") over the pipeline. Johan's ruling: the config lives **inline in the RIGHT-HAND column**
of the DR2 pipeline (`dr2/pipeline.blade`), the same right region as Documents / Send-to-party /
Proforma, set up front — **NOT a modal, NOT per COC step**.

- New partial `dr2/_supplier-work-orders.blade.php`, `@include`d in the right column
  (`lg:col-span-2 space-y-4 dr2-pipe-col`) above `_deal-documents` + `proforma._deal-section`.
- Inline Alpine (loads on `x-init`, no `open`/overlay). Trigger-step selector at top; a **vertical
  tick-list** of the agency COC types; a ticked row reveals ONE aligned responsible-party + recipient
  (+ supplier picker) selector beneath it — no stacked/overlapping dropdowns. One "Save work orders".
- Removed BOTH the §17 deal-level modal AND the older §15 per-step COC modal — **zero `position:fixed`
  overlays remain** in the pipeline.
- Twin fix: `CocWorkOrderService::send` now mints the DR2 twin BEFORE generating the PDF, so the FIRST
  work order on a twin-less deal lands in the corpus (was throwing "select at least one document").

### 18.1 On-site proof (deployed qa1 `d5498781`, deal_no 1802 = deal #153 — the screenshot deal)
Rendered the real pipeline: the **inline "Supplier Work Orders" section renders in the right column,
no modal / no `position:fixed`**. Functional: panel lists the 4 live COC types + trigger default #59
"Bond Approved"; tick Electrical (supplier) + Gas (listing agent), untick the rest → 2 work orders,
**Beetle + Electric Fence steps auto-N/A**; completing "Bond Approved" fired both — **Mailpit: TO
electrician / CC falan+shawn (both agents); TO falan (listing) / CC shawn (selling; self-CC
de-duped)**. 2 distinct PDFs + 2 AT-228 audit rows, twin minted. Proof rolled back.

## 19. Pipeline-setup work-order config PERSISTENCE fix (2026-07-20)

Bug (Johan): configuring the "Supplier work orders on this step" list in Pipeline Setup, clicking
Save, showed a "Step saved" toast — but on refresh the work orders were gone.

**Cause (frontend only):** `deals-v2/pipeline-setup/edit.blade.php` `saveStep()` built the axios
payload field-by-field and **omitted `work_orders`**. So `DealPipelineStepController::syncWorkOrders`
hit `if (! $request->has('work_orders')) return;` and wrote nothing. The step row itself saved (hence
the toast), but the collection was never posted → empty on reload.

**Reload path was already correct:** `DealPipelineSetupController::edit()` eager-loads
`steps.workOrders` and maps them into `stepsJson.work_orders`.

**Fix:** include the filtered `work_orders` array in the `saveStep` payload. `syncWorkOrders`
rebuilds the collection (soft-deletes the old set, recreates — no hard deletes).

**On-site proof (deployed qa1 `76812804`, template #1 / step #1):** posting the fixed payload
(`work_orders: [COC/activated, Gas/completed]`) wrote 2 `deal_pipeline_step_work_orders` rows;
`edit()` reload returned `stepsJson.work_orders = 2`; the old payload without `work_orders` wrote 0
(early-return, confirming the cause). Proof rolled back.

## 19. Panel trigger dropdown REMOVED — trigger is read from pipeline setup (Johan FINAL 2026-07-20)

Johan's ruling: **the WHEN/trigger for supplier work orders is defined in PIPELINE SETUP (at the step
level), NOT on the deal's right panel.** §17/§18's right panel carried a "Send work orders when this
step completes" dropdown (default "Bond Approved") that let the agent re-select the trigger per deal —
that dropdown is **removed**. The panel keeps ONLY the tick-list of COC types + per-ticked-COC
responsible-party/recipient selection; the send still fires per the pipeline-setup-defined trigger step.

- **UI removed** — `dr2/_supplier-work-orders.blade.php`: the "Trigger step" `<div>` (label + select),
  the `triggerOptions`/`triggerId` x-data props, the `load()` lines that hydrated them, and the
  `trigger_step_instance_id` field in the `save()` POST body are all gone. No dropdown, no per-panel
  trigger selection.
- **Backend derives the trigger from pipeline setup** — `WorkOrderController::cocConfigSave` no longer
  reads `trigger_step_instance_id` from the request (removed from validation); `$triggerId` is derived
  purely from the pipeline-setup granting step (`status_trigger`='granted', fallback 'accepted') — the
  same default §17 already used. `cocConfigPanel` no longer returns `trigger_options` /
  `trigger_step_id` / `trigger_default_id` (nothing consumes them).
- **Send mechanism unchanged** — the derived `$triggerId` is still written to each ticked work order's
  `deal_step_work_orders.trigger_step_instance_id`; `Dr1PipelineService::fireSupplierWorkOrders` still
  sends every pending work order whose trigger step is the one just completed (positive outcome only).
  For the common case (agent leaves the default) behaviour is IDENTICAL to §17 — only the override
  capability is gone.
- **No modal reintroduced** — the panel stays inline in the right column (§18).

### 19.1 On-site proof (deployed qa1 `__COMMIT__`, deal #___ / granting step "Bond Approved")
- PANEL renders inline in the right column with **NO trigger dropdown** — only the COC tick-list +
  per-ticked responsible/recipient. `cocConfigPanel` JSON carries no `trigger_options`.
- SAVE: tick a COC + set responsible → work order created with `trigger_step_instance_id` = the
  granting ("Bond Approved") step, derived server-side (client posts no trigger).
- FIRE: completing "Bond Approved" sent the ticked work order — Mailpit caught the email with the
  work-authorisation PDF. Un-ticked COCs auto-N/A; sent rows never rewritten. Proof rolled back.
