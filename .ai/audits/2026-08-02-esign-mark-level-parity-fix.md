# E-sign candidate↔authoriser parity — MARK-LEVEL engine fix (implemented)

Date: 2026-08-02 · Lane: cc3 (e-sign/DocuPerfect) · Branch built off origin/QA1
Johan: "fix it engine side now, just get it fixed and working like it should — import ANY document
and the engine should work, not faff around to get per-document fixes."
Companion analysis: `.ai/audits/2026-08-02-esign-engine-vs-document-audit.md` (engine-vs-document classification).

## The defect (Exhibit A)
Candidate signs a document; the authoriser (full-status practitioner, shared queue) must sign/initial
at EVERY place the candidate does. The old `CandidateAuthoriserSurfaceInjector` provisioned ONE
authoriser surface per *segment*, at the segment TAIL, and skipped any segment already holding a
supervisor marker. That is **structure-level**: a document with 3 candidate signature blocks in the
MIDDLE of the body received ONE (or zero) authoriser surfaces — the mid-body marks were never mirrored.
An incomplete document = bank/conveyancer rejection. The completeness harness passed green because it
checked "no EXISTING slot is empty" — a never-created authoriser mark is *absent*, not empty.

## The fix — MARK-LEVEL, structure-agnostic (driven by the marks, not the layout)
The bake (`CanonicalInkComposer`) was already mark-level; the gap was entirely in surface *provisioning*.
The fix converts provisioning to mark-level, so parity holds for ANY imported document with the ink
core unchanged.

1. **`CandidateAuthoriserSurfaceInjector` — rebuilt per-mark.**
   - For EVERY candidate (`agent`/`property_practitioner`) `data-marker-type="signature|initial"` mark,
     a mirrored authoriser mark is cloned and inserted as its IMMEDIATE SIBLING (same parent, same
     location), identity-stamped (`data-recipient-identity="supervisor"`, `data-authoriser-mirror`), no
     placeholder name. The mark-level bake then fills every mirror automatically.
   - **Pairing is per-mark, not per-parent**: `pairedAuthoriserMark()` resolves the authoriser mark
     paired to THIS candidate mark (its next element sibling if that is an authoriser mark of the same
     type — where our mirror lands and where an adjacent enumerated slot sits; else a PRE-EXISTING
     non-mirror authoriser sibling — the enumerated per-condition/per-row slot). This is what lets 3
     candidate marks sharing one parent each get their OWN mirror, while never doubling an enumerated
     per-condition initial row.
   - **Idempotent** on re-run; **candidate-only** (a seller/lessor is never mirrored — the authoriser
     mirrors the candidate, not every party); **fail-open** on any DOM error.
   - One authoriser **ceremony attestation** (location/date/time marks, NO signature line so it can
     never double a per-mark signature mirror) is injected once per signing segment — the authoriser's
     when/where, filled by `completeWeb` via `supervisor_*` keys exactly as before.
2. **`unmirroredCandidateMarks()` (static, same class) — COMPLETENESS 1:1 by location.**
   For each candidate signature/initial mark, requires a FILLED authoriser mark at its OWN anchor; a
   single missing/unfilled mark is a violation. Pure/static so runtime + harness call ONE authority.
3. **`signature-block.blade.php` — removed the hardcoded authoriser block.** It provisioned exactly one
   tail surface (half the defect) and would DOUBLE the per-mark mirror of the candidate's final
   signature. The injector is now the single authoriser authority (a code comment forbids reinstating a
   hardcoded block).
4. **`SignatureService::completeDocument()` — runtime bank-reject alarm.** On completion of a candidate
   flow, `unmirroredCandidateMarks()` runs over the final canonical; any violation logs
   `AUTHORISER_PARITY_INCOMPLETE` (ERROR) with the exact anchors. Non-blocking (completion is the durable
   legal record); the injector guarantees parity by construction, so this is a safety net/alarm.
5. **Drift fixes (audit §4 P3).**
   - `SignatureController` review view now enumerates via `SignatureTemplate::enumeratedSigningParties()`
     — no more inline `supervisor_final` fold that drifts from `CHECKPOINT_ROLE_ALIASES`.
   - `SignatureService::countSignatureLocationsPerRole()` now counts REAL marks in the composed
     `merged_html` (structure-agnostic); the former blade-file grep for the `signature-line`/
     `signature-block` partial names is retained ONLY as a PDF/coordinate-template fallback.

## Harness (regression-walk) — rewritten to enforce mark-level, not structure-level
`EsignRegressionWalk::assertAuthoriserParity()`:
- **AUTH-b (NEW)**: 3 mid-body candidate signature marks → **3** co-located authoriser mirrors (Exhibit A:
  was 1). This is the fixture the old harness never had.
- **AUTH-b2 (NEW)**: the signature-block component no longer emits a hardcoded authoriser surface.
- **AUTH-c/d/d2/e**: bake ownership across the checkpoint fold + co-recipient isolation + no-empty-slot —
  retained (the bake is unchanged).
- **AUTH-f (REWRITTEN)**: injector is mark-level on a NON-component (lease `.signature-line`) doc, mirrors
  the candidate but NOT the non-candidate lessor, ignores pure-info pages, and is idempotent. (Was:
  "exactly ONE authoriser surface per segment" — which certified the wrong invariant.)
- **AUTH-g (NEW)**: completeness 1:1 by location — passes when every candidate mark has a filled
  authoriser mark; FAILS the doc when one is missing (bank-reject).

## Proof (disposable, corex_dev3 — no real data mutated)
- Faithful synthetic fixtures replicating the REAL composed structures — **14/14 PASS**:
  - Case A (component: attestation block + 3 mid-body agent sigs + condition-initial row): 4 agent sigs
    → 4 authoriser mirrors; condition-initial NOT doubled; 1 ceremony.
  - Case B (Exhibit A — 3 mid-body inline, non-component): **3/3 mirrored** (was 1).
  - Case C (lease non-component, `SignatureSurfaceNormalizer` output): 1/1 mirrored; non-candidate lessor
    untouched; ceremony present.
  - Idempotent on re-run; completeness PASSES filled / FAILS (bank-reject) one missing.
- `assertAuthoriserParity()` (real injector + real bake + real component view) — **9/9 PASS** (AUTH-a…g).
- `php -l` clean on all changed PHP; `view:clear` OK (blade compiles).
- Full `esign:regression-walk` end-to-end (real pack pipeline) — run on /corex-qa1 post-deploy (the pack
  fixture lives there; corex_dev3 lacks it). [result recorded at deploy]

## Files changed (this fix)
- `app/Services/Docuperfect/CandidateAuthoriserSurfaceInjector.php` — per-mark mirror + completeness authority
- `app/Services/Docuperfect/SignatureService.php` — runtime parity alarm + marker-count location tally
- `app/Http/Controllers/Docuperfect/SignatureController.php` — review view via shared enumeration authority
- `app/Console/Commands/EsignRegressionWalk.php` — mark-level AUTH-b/b2/f + completeness AUTH-g
- `resources/views/docuperfect/web-templates/components/signature-block.blade.php` — removed hardcoded authoriser block

## Deliberately NOT done in this pass (safety valve — reported, not silently skipped)
- **Hard-BLOCK completion on a parity violation.** The runtime guard LOGS (loud, with anchors) but does
  not throw. A hard block lives on the completion path (`SigningController::completeWeb` is behind the
  dev-check pipeline gate and needs a `tests/Feature/Docuperfect/SigningView/` integration test) and must
  be false-positive-analysed across wet-ink / deferred / amendment-initialing flows before it can safely
  refuse a completion. The injector guarantees parity by construction and the harness fails if that
  regresses, so the logged alarm is the safe maximal enforcement now. **Recommendation:** follow-up ticket
  to add the SigningView test + convert the alarm to a hard gate.
- Broader recognition generalization (audit §4 P4 — CSS-class surface recognizers, the `ROLE_BASES`/
  `BLOCK_TAGS`/sub-name closed vocabularies) is a separate, larger track; not required for candidate↔
  authoriser parity, which this fix delivers for component, mid-body, and non-component (lease) docs.
