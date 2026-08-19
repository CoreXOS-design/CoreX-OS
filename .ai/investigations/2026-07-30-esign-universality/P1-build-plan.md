# P1 BUILD PLAN (GREENLIGHT PACKAGE) — Route MDF/Addendum signature sections through the shared components; retire the resolver family-clone

> **Status: PLANNING ONLY. No code written, nothing deployed. For Johan's greenlight.**
> Companion doc: `esign-conformance-audit.md` (the full R1–R7 matrix this fix comes from).
> E-sign is currently lower priority than DR2 — drafted so it's ready when Johan wants it.

> ⚠️ **BRANCH CAVEAT — read before any build.** Audited against `origin/QA1`. The lane's local branch
> `AT-300-onsite-refix` is **279 commits behind QA1** and its staged AT-303 work is a stale blueprint.
> **This build must branch from `origin/QA1`, not the local branch.**

---

## ★ TWO PRODUCT CALLS FOR JOHAN — decide before build (these are yours, not the lane's)

**DECISION 1 — MDF disclosure gating asymmetry (this is P5, surfaced here because it lives in the same files).**
Today MDF's **bare-table** disclosure lets *any* signer tick the marks, while the **checklist** path
(`.corex-disclosure-checklist`) is gated to the owner/seller only under PPA-s70 (`disclosure-logic.blade.php`).
They disagree. Which is correct?
- (a) **Gate both** to the disclosing owner/seller (strict PPA-s70), or
- (b) **Ungate both** (any signer may tick).

*P1 does not touch this* — but P5 can't be built until you rule. Recorded here so it's on the register.

**DECISION 2 — May P1 re-shape two LIVE blades during the feature-freeze window (1–15 Aug 2026)?**
P1 edits `template-120.blade.php` (Addendum B) and `template-123.blade.php` (Immovable Property Condition
Report / MDF), both live. This is a **fix to existing behaviour, not a new feature**, so the freeze rule
does not forbid it — but it re-shapes the signature markup of two prescribed legal forms, so explicit go
is wanted before touching them. **Confirm: proceed / hold until after 15 Aug.**

---

## 1. What P1 is (one sentence)

Replace the hand-coded fixed-roster signature sections in templates **120** and **123** with the shared
`signature-block` / `signature-line` components (the pattern the other **17** CDS templates already use),
so per-recipient looping + identity-stamping comes from the universal engine — then retire the
`SigningSurfaceResolver` "family-clone" compensator that exists only to patch those two hand-coded shapes.

This is Seam **B** in the conformance audit — the #1 recurring-bug source (the seller_2-has-no-signature
class of bugs on every new MDF/Addendum-shaped document).

## 2. Why exactly these two (scope is provably 2 templates)

Classification of every `web-templates/cds/template-*.blade.php` on QA1:

| Group | Templates | Signature markup |
|---|---|---|
| Correct (shared components) | 1, 6, 7, 9, 10, 111, 112, 113, 114, 115, 116, **117**, 118, 119, 121, 122, 125 (17) | `@include(...signature-block)` (+ inline `signature-line`) |
| **Hand-coded outliers (P1 targets)** | **120, 123** | `$adbSigners`/`$mdfSigners` fixed 4-role `@foreach` → raw `.adb-sig`/`.mdf-sig .signature-section` markers |

Both outliers carry a disclosure checklist AND hand-code signers — exactly the MDF/Addendum shapes where
seller_2 / identity / field-lock bugs keep recurring. The signature section is a **separate block** from
the disclosure grid in both files, so P1 touches signatures only and does **not** disturb the disclosure
machinery (R6 / AT-303). Note `template-117` is itself an MDF and already uses the shared block — the
precedent that the shared path is acceptable for a disclosure form.

## 3. Exact files / blades / components touched

**Edited (2 template blades):**
- `resources/views/docuperfect/web-templates/cds/template-120.blade.php` — replace lines **40-60**
  (`@php $adbSigners=[...] @endphp @foreach ... @endforeach`) with a shared-component include.
- `resources/views/docuperfect/web-templates/cds/template-123.blade.php` — replace lines **144-167**
  (`@php $mdfSigners=[...] @endphp @foreach ... @endforeach`) with a shared-component include.

**Extended (1 shared component) — only if the prescribed-layout decision = "preserve wording" (see §4):**
- `resources/views/docuperfect/web-templates/components/signature-block.blade.php` — add optional
  `variant`/`prescribed` params (e.g. `signed_at_caption`, per-role `sig_caption`, `always_show_roles`)
  so the prescribed "Signed at ___ on [day][month][year] / Signature of {Seller|Purchaser|Property
  Practitioner}" wording and the always-present Purchaser/Co-signer slots survive. No new file; additive,
  defaulted OFF so the 17 existing callers are unchanged.

**Retired (after 120/123 convert AND a repo-wide grep is clean):**
- `app/Services/Docuperfect/SigningSurfaceResolver.php` — remove `cloneFamilyBlockForInstance()`
  (~:220-298) and its caller (~:127), and the `signature-section`/`mdf-sig` arm of
  `closestSignatureBlock()` (~:305-312). Keep the class; only the family-clone compensator goes.

**Data flow to verify (no edit expected, but confirm):**
- `recipients_by_role` is injected into the template render at **prepare/bake** time in
  `ESignWizardController.php:1733,1903,4391,4567` — this is what drives the shared component's per-recipient
  loop. It already fires for the 17 shared-component templates. **If any serve/re-bake path renders 120/123
  without `recipients_by_role`, the component falls back to a single line — that path must be identified and
  fed the same data.** (Build-time verification item, not a guess.)

**Tests (new, land with the change — pipeline gate requires a test diff for these files):**
- `tests/Feature/Docuperfect/SigningView/` — 2-seller render assertions for 120 and 123 (and, ideally, the
  `UniversalSigningConformanceTest` from the audit's test design).

## 4. Before / after markup shape

**BEFORE (template-123 lines 144-167 — same shape in template-120 lines 40-60):**
```blade
@php
  $mdfSigners = [
    ['role'=>'seller','label'=>'Seller','sigLabel'=>'Signature of Seller'],
    ['role'=>'buyer','label'=>'Purchaser','sigLabel'=>'Signature of Purchaser'],
    ['role'=>'agent','label'=>'Property Practitioner','sigLabel'=>'Signature of Property Practitioner'],
    ['role'=>'co_signer','label'=>'Co-signature (if required)','sigLabel'=>'Signature'],
  ];
@endphp
@foreach($mdfSigners as $i => $s)
  <div class="mdf-sig signature-section" data-marker-party="{{ $s['role'] }}" data-marker-index="{{ $i }}">
    <div class="mdf-sig-title">{{ $s['label'] }}</div>
    <p>Signed at <span data-marker-party="{{ $s['role'] }}" data-marker-type="location">…</span>
       on <span data-marker-party="{{ $s['role'] }}" data-marker-type="day">…</span> …month…year…</p>
    <p>{{ $s['sigLabel'] }}: <span data-marker-party="{{ $s['role'] }}" data-marker-type="signature">…</span></p>
  </div>
@endforeach
```
Problem: `data-marker-party="seller"` is emitted **once**, hard-coded. Two sellers → one block →
seller_2 has no signature/date surface. The engine's per-recipient loop never sees a `.sig-party-block`
to expand, so `SigningSurfaceResolver::cloneFamilyBlockForInstance` was bolted on to detect the lone
`.mdf-sig` and clone it — a per-template-shape patch (its own comment at `:123`: *"the `.mdf-sig` block was
not expanded, so seller_2 landed as a lone [marker]"*).

**AFTER (both templates):**
```blade
@include('docuperfect.web-templates.components.signature-block', [
    'parties'          => ['Seller', 'Purchaser', 'Property Practitioner'],
    'document_context' => 'sales',
    // prescribed-form options (only if the decision is "preserve wording"):
    'variant'           => 'prescribed_signed_at',
    'always_show_roles' => true,   // keep blank Purchaser/Co-signer slots even with no such recipient
])
```
The shared component already expands per recipient — `markerKey = idx===0 ? role : role.'_'.(idx+1)`
(`signature-block.blade.php:54`) — emitting one `.sig-cell` per recipient inside a `.sig-party-block`
grouped by role (`:145`). Two sellers → `data-marker-party="seller"` and `="seller_2"`, each with
`data-name`. This is the markup `RoleBlockExpansionService::expandWithLooping` and
`CanonicalInkComposer::markerBelongsToSigner` already understand universally.

**Layout-fidelity note (why §3 may extend the component):** the shared block's default caption reads
"Thus done and signed by the {role} at … on this … day of …". The prescribed MDF/Addendum wording reads
"Signed at ___ on [day][month][year]" + "Signature of {Seller|Purchaser|Property Practitioner}". If that
exact wording is legally required, the build adds the `variant`/caption params so the component emits the
prescribed text while keeping the universal per-recipient structure. Confirm the prescribed-wording
requirement (Decision 2 context) before assuming a verbatim swap is legally acceptable.

## 5. How each spec rule (R1–R7) is preserved or improved

| Rule | Effect of P1 | Why |
|---|---|---|
| **R1** initials modal | **Unchanged** | Initials-row + capture modal come from the shared surface partials, not the signature section. |
| **R2** per-recipient field value + lock | **Improved** | seller_2 now gets a real, identity-stamped surface; `data-viewer-editable` overlay + `CanonicalInkComposer` bake apply because it's now standard `.sig-party-block` markup. |
| **R3** agent→final-agent gate | **Unchanged** | Completion/approval gate is in `SignatureService`, independent of signature markup. |
| **R4** multi-recipient block grouping | **Fixed (core win)** | Grouping now comes from the single universal engine for 120/123, exactly as for the other 17. The recurring seller_2-has-no-block bug is removed at source. |
| **R5** condition-initial gate | **Unchanged by P1** (fixed separately by P2/P3) | The `~~~~OTHER_CONDITIONS~~~~` frames block in 120/123 is untouched. |
| **R6** MDF lock/amend/counter-initial | **Unchanged** | The `.corex-disclosure-checklist` grid is a separate block; P1 edits only the signature section below it. |
| **R7** recipient-identity scoping | **Improved** | Marker identity now flows from `markerKey = role_{idx}` via the shared component + `markerBelongsToSigner`, instead of the hand-coded single `data-marker-party="seller"`. |

Net: P1 directly fixes **R4** (and improves R2/R7) for the two worst-offending templates and removes a
per-template compensator — nothing regresses because the target pattern is already proven on 17 templates.

## 6. Migration / rollout order (each step its own commit, QA1-only, verified before the next)

1. **Extend the shared component** (if the decision is "preserve wording"). Add `variant`/caption/`always_show_roles`, defaulted OFF. Prove the 17 existing callers render byte-identical (no-param path unchanged).
2. **Convert template-123** to the shared include. Verify 1-seller and 2-seller render (§7) + PDF pagination.
3. **Convert template-120** to the shared include. Same verification.
4. **Repo-wide grep** for any remaining emitter of `signature-section` / `mdf-sig` (blades, PDF path, compiled templates, legacy docs). Only when zero live emitters remain →
5. **Retire** `cloneFamilyBlockForInstance` + the `signature-section`/`mdf-sig` arm of `closestSignatureBlock`. Re-run 2-seller verification on 120/123 to prove the universal path — not the resolver — now does the grouping.
6. **Land the conformance test** (2-seller assertions for 120/123) in `tests/Feature/Docuperfect/SigningView/`.
7. Deploy to QA1 (`optimize:clear` + `view:clear`; reload php8.2-fpm; Blade views are not Vite assets). Hand Johan the recipient + agent links for both templates.

Steps 1–3 are independently reversible; the risky retirement (5) happens only after both blades are proven
on the shared path.

## 7. Hand-verification on a real 2-seller doc, QA1 (before → after)

**Setup:** a real e-sign document on each template with **two seller recipients + one agent** (the minimum
that exposes the bug). Reuse an existing MDF (123) and Addendum B (120) doc, or mint fresh via the wizard on
QA1. Drive the actual rendered signing page in headless Chromium (Johan clicks the live UI) — not unit
tests alone.

**BEFORE (capture the current defect, so the fix is provable):**
1. Open MDF (123) recipient link as **seller_2** → screenshot. Expected today: **no signature/date surface
   for seller_2** (only seller_1's block, or seller_2 as a lone resolver-cloned marker with wrong/absent
   identity).
2. Same for Addendum B (120).
3. Note whether seller_2 can place their signature + "Signed at/on" date at all.

**AFTER (P1 on QA1):**
1. MDF (123) as **seller_1**: signature + date surface present, identity `seller`; as **seller_2**: own
   signature + date surface present, identity `seller_2`; each editable only for that viewer
   (`data-viewer-editable` on own, absent on the other's).
2. Both sellers sign → baked canonical HTML / final PDF shows **two distinct seller signatures + two
   distinct "Signed at ___ on ___" lines**, agent block intact, prescribed wording unchanged.
3. Addendum B (120): same two-seller proof.
4. **Regression guard:** a single-seller MDF/Addendum still renders exactly one seller block (no phantom
   second); the other 17 templates render byte-identical to pre-P1 (diff the rendered HTML).
5. Confirm the resolver retirement changed nothing: 2-seller 120/123 identical with the family-clone code
   removed (proves the universal engine, not the compensator, produced the blocks).

Screenshot seller_1, seller_2, agent, and the final PDF for both templates, before and after.

## 8. Risk assessment — re-shaping two LIVE blades

| Risk | Likelihood | Mitigation |
|---|---|---|
| **Prescribed-form wording/layout changes** (MDF & Addendum are legal forms; PPA-s70 / Reg s36) | MED-HIGH | Preserve wording via the component `variant` (§3/§4); confirm legal requirement (Decision 2); template-117 precedent shows the shared block is acceptable for a disclosure form. |
| **`recipients_by_role` not populated on some serve/re-bake path** → component falls back to single line | MED | Trace all render callsites (ESignWizardController:1733/1903/4391/4567 + any serve-time re-bake) before converting; verify 2-seller render on the actual serve path, not just prep. |
| **PDF pagination shift** (signature block height changes → page breaks move) | MED | `PdfPaginationVerbatimTest` + hand-verify the PDF (§7.2); measure-and-fit pagination runs inside Chromium so screen/PDF divergence is expected and checked visually. |
| **Retiring the resolver breaks a legacy doc still emitting `mdf-sig`/`signature-section`** | LOW-MED | Repo-wide grep gate (step 4) before removal; keep retirement as its own commit so it reverts cleanly. |
| **In-flight documents already baked with old markup** | LOW | Old baked artifacts serve as-is (backward-compat retained); only newly-prepared 120/123 docs take the new shape. Confirm no forced re-bake of live in-flight docs. |
| **Prescribed always-present blank slots** (Purchaser/Co-signer shown even with no such signer) | LOW | `always_show_roles` param preserves the blank slots; verify they don't become clickable/required for absent parties. |

Overall: MED risk, fully gated. The pattern is proven on 17 templates; the blast radius is 2 files plus a
defensively-sequenced resolver retirement; every step is independently verifiable on QA1 before the next.

## 9. What P1 explicitly does NOT do (scope lock)
- Does not touch the disclosure grid / marks (R6 / AT-303) — separate block, separate ticket.
- Does not add the condition-initial server floor (R5) — that is P2/P3.
- Does not unify the two signing surfaces' client JS (seam A) — that is P4.
- Does not resolve Decision 1 (disclosure gating asymmetry) — that is P5, Johan's call.
- Adds no new feature, field, page, or permission — pure consolidation of existing behaviour.
