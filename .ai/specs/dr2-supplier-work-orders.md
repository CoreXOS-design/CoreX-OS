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
