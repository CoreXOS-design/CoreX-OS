# DR2 completeness audit — for Johan (read-only, QA1 tree)

**Date:** 2026-08-01 · **Tree:** `/corex-qa1` (branch QA1) · **Method:** 4 parallel read-only passes +
hand-verification of the two headline defects and the `work_authorisation` DocumentType (exists, 1 row).
No code changed.

## Status table

| Area / control | Status | Evidence |
|---|---|---|
| Add custom step (List + Timeline) | SHIPPED | `pipeline-list.blade.php:346`, `pipeline-timeline.blade.php:374` → `web.php:743`. Relative "+N after step" is new-model-only (`PipelineController.php:452`); old-model + relative + empty date → null due → Unscheduled tray. |
| Decline deal | SHIPPED | List header `pipeline-list.blade.php:231`; Timeline via `_pipeline-context-tabs.blade.php:22` but inside the default-collapsed "Deal panels" (not header-visible). |
| Restore removed step | SHIPPED | `_removed-steps.blade.php:25` → `web.php:751`; `$removedSteps` plumbed to both views. |
| Hide-completed | PARTIAL (by design) | List `pipeline-list.blade.php:262`; absent on Timeline (intentional). |
| Zoom / density (Timeline) | SHIPPED | `pipeline-timeline.blade.php:308`, client `applyZoom:679`. |
| Edit-dates + right-edge cascade (ffd0f098) | SHIPPED, new-model-only | `PipelineListController::editDates:98-116` + `web.php:733`. Cascade no-ops old-model (`DealDateCascade:38-40`). List has no date editor. |
| Drag-to-reschedule (Timeline) | SHIPPED | `pipeline-timeline.blade.php:600` → `web.php:729`. Dated non-terminal steps only. |
| Drag-to-relink / fan-in | BROKEN affordance | Grip `title="Drag to relink"` (`_pipeline-step-tile.blade.php:33`) but List handler only POSTs `{order}` (`pipeline-list.blade.php:539-540`); hint says "display only — never changes dependencies" (`:268`). `relinkBySet`/`depends_on` backend (`PipelineController.php:565`) has no live UI (only dead `legacyBoard`). Single-follows relink works via Sequence modal. |
| Deposit anchor (Signed+N / Bond-Grant+N / fixed) | SHIPPED | `_deal-structure.blade.php:80-83`, `PipelineController.php:186-197`, `Dr2ConditionCatalog.php:207-222`. Stays suspensive → drives Granted when late (`DealDateCascade.php:50-96`). |
| Granted→Registered lifecycle | SHIPPED | Forward-only P→G→R (`Dr1PipelineService.php:378-389,410,512`); composable via convergence (`:448-501`); stamps + `DealObserver` reactivity. |
| ↳ back-dated grant date | PARTIAL (defect) | Back-date on the grant-triggering (last suspensive) completion is lost from the Granted date — `syncDealStatus` computes it (`:472-473`) before the controller stamps `actual_date` (`PipelineController.php:316`); gate then locks `completed` (`:471`). Downstream Due cascade still honours the back-date. |
| Final agent-review gate / no auto-file | SHIPPED | `SignatureService.php:1602-1660` holds at `pending_agent_approval`; regression-tested (`EsignRegressionWalk.php:200`). |
| "Due X · Done Y" tiles + re-anchoring | SHIPPED (Timeline) | `pipeline-timeline.blade.php:478-479`, `PipelineTimelineService.php:183-185`; re-cascade on complete/reopen/edit (`PipelineController.php:311-317`). Step-tile grid shows ✓actual OR due, not both. Latent: `PipelineTimelineService.php:184` no null-due guard. |
| External agency | SHIPPED | Full mirror incl. e-sign exclusion by construction. Known-open (product call): no default doc-rules seeded (confirmed absent; configurable in admin). Minor half-wire: absent from `CorrespondenceMatchService` inbound corroboration (cc2). |
| Supplier WO pipeline send | SHIPPED / READY | `WorkAuthorisationGenerator::generate()` + `documents/work-authorisation.blade.php` + `work_authorisation` DocumentType all exist (DB row verified). Fires from `completeStep` + `syncDealStatus`-on-grant; `trigger_step_instance_id` wired; hold-until-assigned. |
| Comms hooks | PARTIAL (by design) | WO `awaiting_supplier` parking wired. Comms-suspense parks at deal/party level — no pipeline-step park. Pipeline→comms one-way (WO supplier emails feed inbound matcher). |
| Markers screen ("Step 2") | NOT obsolete | Rental uses directly; sales wizard auto-places then routes to it for review (`ESignWizardController.php:2408-2417`). Question for Johan, not a removal. |
| Schema snapshot / migration debt | needs reconcile | `mysql-schema.sql` stale + internally inconsistent (6 migrations missing incl. external_agency pair). Not a promotion correctness risk (Staging runs pending migrations) but must be regenerated. |

## Genuinely outstanding (would block "done")

1. **Drag-to-relink: dead/misleading grip tooltip + unreachable fan-in editing** (BROKEN). Fix the tooltip → "Drag to reorder" and drop the dead `data-drop-follows`/`data-follows-url`, OR wire the fan-in UI. Single-follows relink works via the Sequence modal.
2. **Back-dated Granted date lost on the grant-triggering completion** (correctness). Stamp `actual_date` before `syncDealStatus`, or let the gate re-derive.
3. **Restructure (change conditions after build) — UI unbuilt** (`_deal-structure.blade.php:26` "coming soon"; engine `DealStructureAssembler` `$force` exists). In-scope-for-done? — Johan's call.
4. **Schema snapshot regen before QA1→Staging** — regenerate, `composer dump-autoload`, strip baked `DEFINER=`, reconcile the one same-name/different-content migration collision (`.ai/investigations/staging-qa1-reconciliation-plan-2026-07-31.md`). Care on promotion: raw enum `ALTER` (`2026_08_01_120001`) + backfills.

## Not blockers (by-design / product-call / already-ready)
- WO send is ready — only Johan's finished PDF **template content** pending; must preserve `WorkAuthorisationGenerator::generate()` signature + view-data keys (new fields → `defaultFields()`). No send-side rewiring.
- External-agency default doc-rules deliberately not seeded (product call).
- Old-model deals skip the downstream cascade on edit-dates; relative add-step new-model-only — intentional split.
- Comms→pipeline step-park absent (by design).
- Decline buried on Timeline (collapsed panel) — UX nit.
- `DealV2SettlementController.php:121-123` — 3 TODOs (V2 settlement doesn't feed V1 rebuilder/rollup/dashboards), deferred.
- Dead-but-harmless: `legacyBoard()` (zero callers); `DealV2Controller` AT-219 soft-retire (routes redirect, bodies archived).
