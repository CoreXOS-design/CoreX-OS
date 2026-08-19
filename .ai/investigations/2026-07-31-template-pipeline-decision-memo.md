# Decision memo — Template pipelines: keep, remove, or re-surface?

**Date:** 2026-07-31
**Author:** cc6 (DR2 deal-model / pipeline-panels lane)
**For:** Johan
**Status:** OPEN QUESTION — decision memo only, **no behaviour changed**
**Trigger:** the DR2 pipeline-rebuild control-drop audit flagged that the on-pipeline
"attach a standard template" control disappeared from the new List/Timeline surface. The
question "are template pipelines retired?" was raised and is **explicitly Johan's to answer**,
not a lane's. An earlier "template pipelines are retired — remove the dead route" line is
**void**; this memo exists so the decision is made on facts, not an assumption.

> **HARD RULE (do not act against this without Johan's explicit word):** do NOT delete or
> remove the `attach()` route/controller or `createPipeline()`. Removal on an unproven
> "retired" assumption would delete live functionality. See §B for exactly what removal deletes.

---

## A. What `attach()` / the route / the engine do today, and every reference

### A.1 The endpoint
- **Route:** `routes/web.php:734` —
  `POST /{deal}/pipeline/attach` → `Dr2\PipelineController@attach`,
  name `deals-dr2.pipeline.attach`, `middleware('permission:view_deals')`.
- **Controller:** `app/Http/Controllers/Dr2/PipelineController.php:234` `attach()` — validates
  `template_id`, resolves the chosen (or deal_type-defaulted) template, and calls
  `Dr1PipelineService::createPipeline($deal, $template->id)` (line 257). Guards a declined deal
  (lock) and a double-attach.
- **Template helpers (private):** `activeTemplates()` (`:691`) and `defaultTemplateFor()` (`:704`)
  — this agency's active templates and the deal_type→default pick. Used by `attach()` **and** by
  the retired `legacyBoard()`.

### A.2 The ONLY UI reference to the attach route is DEAD code
- `route('deals-dr2.pipeline.attach')` appears in exactly one blade:
  `resources/views/dr2/pipeline.blade.php:217` (the "Or attach a standard template" form).
- **That view is unreachable.** `dr2.pipeline` is rendered only by
  `PipelineController::legacyBoard()` (`:131`), which is **`private` and called from nowhere**
  (grep: the only hit for `legacyBoard` is its own declaration at `:70`). The public entry
  `show()` (`:64`) just **redirects** to `viewDefault()` (Timeline/List). So the old board view,
  `legacyBoard()`, and the attach form inside it are **dead code** already.
- (The `deals-v2/index.blade.php:137` "pre-pipeline" badge is unrelated text — not the route.)

### A.3 The ENGINE `attach()` calls is very much ALIVE — shared with the capture flow
- `Dr1PipelineService::createPipeline()` (`app/Services/Deal/Dr1PipelineService.php:67`) is the
  template-instantiation engine (materialises a template's steps onto a DR1 deal, resolves dates,
  sets `deal_pipeline_template_id`; refuses a double-attach).
- **It is called from the live capture flow:** `DealRegisterController.php:394` auto-attaches the
  selected/defaulted template on deal save (`pipeline_template_id` from the capture form,
  wrapped so a bad template never fails the save). Capture also surfaces the picker:
  `DealRegisterController.php:301,309` pass `availableTemplates` to the capture view.
- **Conclusion:** template pipelines are a **live, in-use feature reached at capture time.**
  What went missing in the rebuild is only the **manual, on-pipeline re-attach control** — not
  the template system itself.

### A.4 Tests
- `tests/Feature/DealV2/Dr1PipelineAttachTest.php` exercises `createPipeline()` directly
  (`:45,74,109,132` — materialise, date cascade, double-attach refused). It tests the **engine**,
  not the HTTP endpoint.
- Template/capture coverage also in `DealPipelineDefaultTemplatesTest`, `Dr2CaptureTest`,
  `DealV2SingleFormCaptureTest`, `DealCapturePermissionTest`, `PipelineFoundationTest`, etc.
- **No test hits the `pipeline.attach` HTTP route directly** (grep found none). So removing the
  *endpoint alone* would not turn a test red — which makes silent removal MORE dangerous, not
  less: the safety net that would catch it does not exist.

### A.5 Coexistence with the composable path
- New-model deals build from suspensive conditions: `saveStructure()` →
  `DealStructureAssembler::assemble()`. Template and composable are **mutually exclusive per deal**
  (`DealStructureAssembler.php:148` gates on `deal_pipeline_template_id`). The new empty-state
  (`_deal-structure.blade.php`) offers **composable conditions only** — 0 references to templates
  (confirmed) — which is precisely the gap.

---

## B. "Retired = remove" vs "Re-surface" — what each actually costs

### B.1 If we REMOVE (retire)
**Deletes:** `attach()` (`PipelineController:234`), route `pipeline.attach` (`web.php:734`), and
(optionally, if `legacyBoard()` + `pipeline.blade.php` are also cleaned up)
`activeTemplates()`/`defaultTemplateFor()`.
**Does NOT delete:** `createPipeline()` — the capture flow needs it. So **"remove attach" ≠
"remove template pipelines."** Removing the endpoint only removes the *manual re-attach* path;
the template system keeps running from capture.
**Breakage / risk:**
- **Stranded deals (the real hazard):** any deal that reaches the pipeline with **no steps** and
  is **not** a good fit for composable conditions has **no way to get a pipeline** on the new
  surface. That includes: capture auto-attach that failed/was skipped; a pre-pipeline DR1 deal
  linked later; a template cleared/never chosen; or an agency that works **template-only** and
  never uses composable conditions. Today the composable empty-state is their only option.
- **No test guard** (A.4) → removal is invisible until an agent hits a dead end.
- Irreversible relative to a "we changed our mind" — would need a rebuild to bring back.

### B.2 If we RE-SURFACE (bring the control back onto the new surface)
**Work (additive, view-mostly):**
1. Add `templates` + `defaultTemplateId` to the shared context
   (`Concerns/BuildsPipelineContext.php`, ~6 lines mirroring `PipelineController:90-91`).
2. Add an "Or attach a standard template" block to the new **empty-state** (a small partial, or
   into `_deal-structure`'s empty branch), posting to the **existing** `pipeline.attach` route —
   the same form the dead old board had (`pipeline.blade.php:208-234`), gated
   `@permission('view_deals')`, shown only when `hasPipeline` is false.
3. (Optional) add a Feature test hitting the `pipeline.attach` route to close the A.4 gap.
**Risk:** low. Purely additive; no engine/route/data change; template + composable paths coexist
(agent picks conditions OR a template on an unbuilt deal). Fully reversible.

---

## C. Recommendation

**Do NOT remove. Re-surface the attach control on the empty-state** (B.2) as a low-risk,
reversible, additive restore — OR, at minimum, keep everything exactly as-is until Johan rules.

Rationale:
1. **"Retired" is unproven**, and removal deletes live-reachable functionality (via capture) plus
   strands template-only agencies and pre-pipeline deals — with **no test to catch it** (A.4).
2. Re-surfacing is cheap and reversible; retirement is neither.
3. It preserves the CoreX principle that a deal can **always** get a pipeline — composable *or*
   template — instead of dead-ending on the empty-state.

**The genuinely open product question for Johan** (the part a lane must not decide): *should new
deals still be offered templates at all, or is composable-conditions the single forward path, with
templates kept only as a legacy/import path?* Whichever way that goes, the safe interim is
**keep + re-surface**, never **remove**.

**Next step is Johan's call.** If "re-surface": it's a scoped, additive follow-up (B.2). If
"retire": it needs an explicit, separate instruction that also covers the capture-flow
implications and a migration story for stranded deals — not a drive-by route deletion.
