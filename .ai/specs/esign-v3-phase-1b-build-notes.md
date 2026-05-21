# E-Sign V3 Phase 1B — Build Notes (Pagination + Wet-ink + Signing UI integration)

These three areas of the Phase 1B spec ship in V1 as **scoped-down deliverables with documented limitations**. Full implementation is deferred to a follow-up because each has architectural depth that exceeds the Phase 1B time budget without compromising data-layer correctness.

---

## 1. Pagination recalc engine (Part 13) — V1: SIMPLIFIED

**Phase 1B ship:** No real-time pagination recalculation. Adding a condition or strikethrough updates the data layer (`document_conditions` + `signature_templates.other_conditions_text`) but the rendered document's page count is recomputed only when the document is re-rendered (next view of the signing surface, or PDF flatten on completion).

**Why:** The existing render pipeline (`cds_drafts.cds_json → tagged_html → WebTemplatePdfService → Puppeteer/PDFKit`) is invoked on demand. Trying to do mid-edit pagination introduces a synchronous render call into the API path or requires an async queue with a "render in progress" state surfacing back to the agent review screen.

**Acceptable degraded behaviour for V1:**

- Conditions are appended to `other_conditions_text` as numbered lines.
- The agent review surface (§7.5.6) renders the conditions list as a structured diff, NOT as a paginated preview.
- The final PDF flatten on completion picks up the conditions automatically via the existing render pipeline — page-bottom initial slots are added by the existing pagination layer at that time.

**Follow-up work (Phase 9.5.1):**

- Add a synchronous "preview" render endpoint that returns the new page count.
- Surface "+N pages added" in the agent review modal.
- For documents already in `STATUS_AMENDMENT_INITIALING`, ensure newly-added pages receive initial slots in the focused initialing view.

---

## 2. Wet-ink rendering (Part 14) — V1: DEFERRED to render layer

**Phase 1B ship:** No special wet-ink template treatment in this build. The existing wet-ink PDF generator (`WebTemplatePdfService` / `SignaturePdfService`) is unchanged.

When a template marked `wet_ink` delivery enters the render pipeline, the `~~~~OTHER_CONDITIONS~~~~` marker is currently treated like any other text placeholder. The Blade-generation pipeline strips placeholders that don't have a registered conditions array; the marker token therefore renders literally as visible text.

**Follow-up work (Phase 9.5.2):**

- Extend `WebTemplatePdfService::renderSection()` (or the generation path inside `TemplateController::generateCdsBladeView()`) to recognise `insertable_block_placeholder` items and emit either:
  - For **e-sign mode**: a styled block showing the live conditions list (already covered by the signing-page partial in this build).
  - For **wet-ink mode**: an empty numbered-line grid (2 blank lines per slot, 5 slots default) for manual writing.

**Acceptable V1 workaround:** Templates that need wet-ink Other Conditions should bake the empty numbered lines directly into the source `.docx` (5 lines pre-formatted) rather than relying on the `~~~~OTHER_CONDITIONS~~~~` marker for wet-ink output. The marker is fully functional for e-sign delivery.

---

## 3. Signing-experience integration (Parts 10 + 11) — V1: PARTIAL provided

**Phase 1B ship:**

- **Backend API endpoints** — complete and tested (`POST /docuperfect/signing/{signatureTemplate}/conditions`, `POST .../strikethroughs`).
- **Reusable Blade partial** — `resources/views/docuperfect/signing/_partials/add-condition-affordance.blade.php`. Embeds an "Add condition" button or "Strike through and replace" affordance + modal + clause library picker + AJAX submit. Wires straight to the new API endpoints. Reload-on-success keeps the signing flow's state consistent without a JS-driven re-render.

**What is NOT yet done:**

- The partial is **not yet embedded** into the existing signing views. The signing surface architecture (per CDS audit) involves multiple Blade files in `resources/views/docuperfect/signatures/external/` and `resources/views/docuperfect/signing/`; integration requires a careful pass to identify the right insertion points for each template type (mandate, OTP wet-ink, FICA, etc.).

**Follow-up work (Phase 9.5.3):**

- Add `@include` of the partial in the relevant signing views at the position where the rendered document contains an insertable block placeholder.
- Wire the strikethrough flow: click handler on `[data-clause-ref]` spans to launch the partial in `mode: 'strikethrough'` with the clause text pre-filled.

---

## Summary of V1 scope ship

| Item | V1 status |
|---|---|
| Schema (3 new tables + `insertable_blocks` JSON on `docuperfect_templates`) | SHIPPED |
| Models with relationships + immutability on `ConditionInitial` | SHIPPED |
| `CdsParserService::detectMarkers()` extended for `~~~~<PURPOSE>~~~~` | SHIPPED |
| `SignatureService::requeueAllPartiesForInitialing()` + `rejectAmendmentChange()` + `rejectAmendmentDocument()` | SHIPPED |
| `STATUS_AMENDMENT_INITIALING` constant + amendment-status sub-states | SHIPPED |
| `ConditionsController` (POST condition + strikethrough) + routes | SHIPPED |
| `AmendmentController` (review / approve / reject / reject-document) + view + routes | SHIPPED |
| CDS builder "Insert Block" + "Clauses" panel | SHIPPED |
| Signing partial for add-condition + strikethrough modal | SHIPPED (not yet embedded) |
| Pagination recalc | DEFERRED — V1 ships static; covered by final-flatten render |
| Wet-ink empty-slot rendering | DEFERRED — V1 relies on source-doc pre-formatted lines |

Spec ref: `.ai/specs/esign-v3-complete-spec.md` §7.5, §8, §17 ES-3, §17 ES-9.
