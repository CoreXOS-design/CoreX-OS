# AT-229 — DR2 W3 pipeline-step supplier work-orders — INVESTIGATION (report before code)

_2026-07-17. INVESTIGATION ONLY, no code. AT-229 In Progress. Base: /corex-dev-3._

## Verdict
**~70% is already built** as deal-level plumbing (send + attach + audit + return-file + a COC request generator). The genuine AT-229 gaps are three, all narrow:
1. **A per-STEP supplier link** (schema) — today suppliers attach only at the DEAL level.
2. **A trigger-on-activation send hook** — the existing auto-send fires on step *completion*, not *activation*.
3. **Generalising the existing `CocRequestGenerator`** (COC-hardcoded) into a template-driven "work authorisation" for any step-type.

Everything else — outbound email with the PDF attached, the audit row, and the return-and-file to property/contact — is built and reusable.

## What EXISTS (reuse)

- **Supplier directory (complete).** `AgencyServiceProvider` (firm, table `agency_service_providers`, has firm-level `email`) + `AgencyServiceProviderContact` (per-contact `email`, `default_delivery_mode`, `default_channel`). `AgencyServiceProviderService::{search,findOrCreate,attachToDeal}`.
- **AT-227 stage→doc→party rules (complete).** `deal_stage_document_rules` = `pipeline_step_id · document_type_id · party_role · delivery_mode · auto_on_stage_tick`. `DocumentDistributionMatrix` resolves which types/roles (not the concrete recipient — that's the distribution service).
- **Outbound send + audit (complete, already supplier-capable).** `DealDistributionService::send()` → `DealDocumentDeliveryMail` (attaches the PDF) → `deal_document_distributions` row (has `recipient_provider_id` FK to `agency_service_providers`) → 3-pillar `Communication` archive. `recipientsForRole()` already resolves **provider parties** under a role (firm `email`), so it can send to a supplier TODAY — but only a deal-level provider party, firm-email only.
- **Return-and-file (complete).** `DealDocumentService::{fileDealDocumentFromDeal, fileClassifiedDocument}` files a returned doc to deal + property + contacts; `autoCompleteMatchingStep()` completes the matching upload step. The **"CoC→property, invoice→contact" rule already lives in `agency_document_type_compliance.save_to_property/save_to_contact`** (AT-105), NOT a new table.
- **`CocRequestGenerator::generate(DealV2, specialty, ?AgencyServiceProvider, User): Document`** — already renders a provider-addressed request PDF (dompdf, Blade `documents.coc-request`) and files it as `coc_request`. **This IS the "authorisation PDF out" — but COC/specialty-hardcoded.**

## The real GAPS (build)

- **G1 — per-step supplier link.** New `service_provider_id` (+ optional `service_provider_contact_id`) on `deal_step_instance` (and template `deal_pipeline_steps`) + relation. **This is m1's pipeline half** (AT-216) per the ticket's cross-lane note — coordinate.
- **G2 — activation send hook.** `DealPipelineService::activateStep()` emits nothing today; the auto-distribution engine is completion-based (`DealStepCompleted` → `AutoDistributeStageDocuments`). AT-229 wants the auth-out when the step is *triggered*.
- **G3 — template-driven work authorisation.** Generalise `CocRequestGenerator` to a per-step-type authorisation template. **Recommended host: `docuperfect_templates`** (new `template_type='work_authorisation'`, bound by `document_type_id`/step-type) — it already has a token/`field_mappings` merge engine + `render_type`/`blade_view` PDF. Reuse `CocRequestGenerator`'s render+file pattern, not the e-sign wizard.
- **G4 (minor) — send-to resolution.** `recipientsForRole()` reads only the firm `email`; the "firm→contacts" intent needs it to prefer a chosen `AgencyServiceProviderContact.email` and honour `default_delivery_mode/channel`.

## Open design questions for Johan (must answer before build)

1. **Flagged in the ticket — auth-doc model:** agency-configurable TEMPLATE per step-type (recommended: host on `docuperfect_templates`) vs a fixed static PDF vs a per-deal generated/merged PDF. Recommendation: **template + per-deal token merge**, reusing the CoC generator pattern.
2. **Trigger semantics:** "triggering the step" = step **activation** (needs a new hook) or a **completion tick** (reuse the existing `DealStepCompleted` engine)? The whole auto-distribution is completion-based today.
3. **Supplier granularity + addressee:** one supplier per step, or per (step × role)? Firm-level email or a specific firm **contact** (`agency_service_provider_contacts` supports it; distribution ignores it today)?
4. **Step-linked supplier vs deal-level provider parties:** if a step links its own supplier, does distribution resolve the recipient from the STEP (new path) or keep routing through deal `party_role` (current path)? Determines whether `deal_stage_document_rules.party_role` stays the routing key.
5. **Return-file authority:** confirm CoC-return files to **property** via the AT-105 compliance `save_to_*` flags and invoice-return to the **responsible contact** via per-contact assignment — with **no new rule table** introduced (outbound uses AT-227 rules; inbound uses AT-105 flags — AT-229 straddles both).
6. **Authorisation doc-type:** is the outbound "work authorisation" a new catalogued `document_type` (like `coc_request`, so it flows through the matrix + audit) or a non-catalogued artifact? The send/audit chain assumes a `document_type_id`.

## Honest build shape (once Johan answers)
Small, mostly-wiring build: 1 migration (step supplier link) + 1 relation, generalise `CocRequestGenerator` behind a template, add the activation (or reuse completion) hook to fire it, refine `recipientsForRole()` for firm-contacts. Return-and-file + audit + mail are reused as-is. Mailpit-testable via the existing Mail sends. **No greenfield; coordinate G1/G2 with m1 (AT-216).**
