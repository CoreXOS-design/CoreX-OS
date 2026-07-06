# AT-177 / WS6 — Cutover Machinery + Compensator Retirement (Staging build, live GATED)

> Status: built + proven on the **Staging branch** (`c2e2a5cc`). **HELD from live** — the live
> cutover/retirement deployment is gated on Johan's explicit word (not given). This document is
> the cutover-readiness summary the WS6 ruling requires.

---

## 1. What the cutover machinery is

A **per-template, agency-blind, reversible** switch that serves a document from its published
compiled CDS instead of the legacy `merged_html` + compensator chain.

- **Flag:** `docuperfect_templates.compiled_serving` (bool) + `compiled_family` (the published
  `compiled_templates` family it binds to, e.g. `117`).
- **Resolver:** `CompiledServingResolver::resolve($docTemplate)` → the published `CompiledTemplate`,
  or `null` (→ legacy path).
- **Serving:** `CompiledSigningRenderer::renderForSigning()` produces the exact two outputs the
  controller needs (`$webTemplateHtml`, `$editableFields`) straight from the CDS via the WS2
  render-only runtime (`CdsRenderer::renderSigningView`).
- **Integration:** `SigningController::show()` — an **additive** compiled-serving branch at the top
  of the web-template path; the legacy `elseif` body is wrapped **untouched** in `else`.

**Dual-path coexistence is the design.** A template is either cut over (serves compiled everywhere)
or not (serves legacy everywhere). Templates not cut over are byte-for-byte unaffected. The switch
is instantly **reversible** (`compiled_serving = false` → back to legacy).

**Proof:** `tests/Feature/Docuperfect/SigningView/CompiledServingCutoverTest` (pipeline-gate) — a
cut-over 117 template serves the real compiled CDS through the live `/sign/{token}` route (117 legal
prose + compiled signable surfaces, **zero `~~~~`/`data-role-block` artifacts**); the canonical
template-111 session **still serves via the untouched legacy path** (RoleBlockExpansionService still
stamps `data-viewer-editable`); cutover is reversible.

---

## 2. Compensator retirement map (§9) — status

Every §9 compensator exists to repair a frozen `merged_html` snapshot at serve time. The compiled
path has **no snapshot and no re-derivation**, so it calls **none of them** — verified structurally:
zero `app()`/`new`/method calls of any compensator in `Compiler/Serving` or `Compiler/Rendering`
(the only mentions are docblock prose naming what is replaced).

| Compensator | §9 retire-when | Status on the compiled path | Removal gate |
|---|---|---|---|
| `MergedHtmlFreshnessGuard` | render-only cutover proven | **Retired** — no snapshot exists to guard | delete when no template serves legacy |
| `SignatureSurfaceNormalizer` | render-only cutover | **Retired** — surfaces are compiled (`data-marker-party`+`data-marker-type=signature` emitted by `CdsRenderer`) | ″ |
| `LetterheadRefresher` | render-only cutover | **Retired** — letterhead is a CDS block/asset | ″ |
| `InsertableBlockRenderer` (marker/fuzzy) | segmentation → typed slots | **Retired** — no `~~~~` markers; typed `insertable_slot` in CDS | ″ |
| `RoleBlockDetectionService` / `RoleBlockExpansionService` (LCA) | topology declared | **Retired** — per-instance expansion from declared parties (`renderSigningView`) | ″ |
| `RoleBlockNormalizer` | segmentation emits typed role slots | **Retired** — declared topology in CDS | ″ |
| `canonicalFieldMappings` / `pruneOrphanFieldMappings` | template fully bound + linted | **Retired** — binding IS the mapping (L1); orphans unpublishable (L2) | ″ |

**"Retired" here = structurally absent from the compiled serving path** (a cut-over template never
invokes them). Per §9's standing rule *"Do not delete any compensator before its row's retire-when
is met on the affected template"* — the **code stays** while ANY template still serves legacy.
Actual file deletion is a later, separate step gated on **no remaining legacy consumer** (i.e. every
web template cut over). WS6 delivers the mechanism and proves the bypass; it does not delete.

---

## 3. Cutover-readiness for Johan — what flipping LIVE would involve

**Per template (116 / 117 / 119, and any future template):**
1. Ensure the compiled version is published on the target host: `php artisan esign:publish-reference-pack`
   (runs after `deploy:sync-reference-data`, which seeds `DataDictionarySeeder` +
   `ReferencePackDictionarySeeder` so 116's bindings resolve). Idempotent.
2. Ensure a `docuperfect_templates` row exists for the template with `blade_view` set (the branch
   fires only for `render_type='web'` + `blade_view`). **NB:** live/staging currently seed template
   variants `120/123/125`, not `116/117/119` — the cutover binds by `compiled_family`, so either the
   reference-pack rows are created, or the existing rows are pointed at the compiled family.
3. Flip: set `compiled_serving = true`, `compiled_family = '<family>'` on that row.
4. Verify in situ: open a `/sign/{token}` for a recipient → the document serves from the compiled
   CDS (compiled surfaces present, no `~~~~`/`data-role-block`); one final side-by-side vs the legacy
   render of the same template.
5. Reversible at any moment: `compiled_serving = false`.

**What retires when:** nothing is deleted at flip time. Once **every** web template is cut over
(no legacy consumer remains), the seven compensators + the `merged_html` serve-path in
`SigningController` become dead code and are removed in a final, separate, gated commit with the
pipeline-gate SigningView diff proving no regression.

**Legal invariant preserved:** L7 (Alienation of Land Act) is compiled into the CDS `legal_class` —
an OTP-class template cannot be published with `web_esign`, so cutover cannot make an unlawful
instrument e-signable.

---

## 4. Staging-host in-situ flip — HELD, runbook ready

The code + full proof are on the Staging branch. The **in-situ staging-HOST flip** (deploy +
publish + create/flip the reference-pack rows + open a signing view) is **held pending a quiet host
window**: cc3 is actively deploying live/staging comms hotfixes to the shared staging host this
morning (AT-182/184), and a signing-runtime deploy + data flip mid-storm risks interfering with
Johan's live-QA. The runbook above executes it in minutes once the host is clear or the conductor
directs. **No live. No QA1.**
