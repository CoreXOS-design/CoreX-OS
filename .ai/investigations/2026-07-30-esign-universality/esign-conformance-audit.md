# E-Sign universality conformance audit — R1–R7 matrix + root-cause seams

> **Read-only analysis. No code changed, nothing deployed.** Greenlight package for Johan.
> Companion doc: `P1-build-plan.md` (the first fix Johan reviews).
> Audited against authoritative current code **`origin/QA1`** (HEAD at audit time `bdcc4879`).

> ⚠️ **BRANCH CAVEAT — read before any build.** This audit reflects `origin/QA1`. The lane's local
> branch `AT-300-onsite-refix` is **7 ahead / 279 behind QA1**, and its staged AT-303 work is a stale
> blueprint. **Any e-sign fix from this package must branch from `origin/QA1`, not the local branch.**

---

## Why this audit exists

E-sign bugs keep reappearing on each new document type because rules that should be **universal**
(identical for every document) were implemented **per-template / per-surface / per-document-type**. The
master rendering doctrine already forbids exactly this — `ESIGN-WETINK.md`: *"Supersedes any e-sign design
that renders the document per surface or per party … ONE canonical artifact, ONE renderer, identical for
every document/party. Per-surface / per-template rendering is the defect class … legally fatal."*

**Spec authority hierarchy:** `ESIGN-CANON.md` governs → `ESIGN-WETINK.md` (rendering north star) →
`esign-ceremony-v3.md` (ceremony) → `esign-recipient-signing-fix.md` (condition-initial rule, "universal,
not document-specific") → `esign-mdf-mark-amendment.md` (AT-303) → `amendment-review-v2.md` (AT-302).

---

## The verdict (one paragraph)

**The universal signing spine is genuinely built and correct.** One compose path —
`CanonicalDocumentRenderer::compose` → `SignatureSurfaceNormalizer::normalize` → `LetterheadRefresher`
→ `InsertableBlockRenderer::renderInDocument` → `RoleBlockExpansionService::expandWithLooping`, with ink
baked identity-scoped by `CanonicalInkComposer::bakeInk` — is read by **every** display surface (agent
show/review `SignatureController.php:285,938,2453`; recipient ceremony `SigningController.php:270`; PDF
`SignaturePdfService.php:215`; amendment review `AmendmentController.php:175`; send-time store
`SignatureService.php:965`) and branches on markup selectors + the recipient set, **never on template id**.
That is why most rules are universal. The recurring bugs come from **six seams where a rule leaves the
spine**; each new document type that doesn't match the shape the engine was tuned against falls into a seam
and re-triggers a "fixed" bug.

---

## The seven spec rules (R1–R7)

| # | Rule | Master anchor |
|---|---|---|
| **R1** | Universal initials/consent modal — shown to all parties on all surfaces before signing; apply-to-all is agent-only, recipients initial each page (informed consent); uniform ink | ceremony-v3 §2; esign-v3 §22.11; WETINK I5 |
| **R2** | Per-recipient field-VALUE persistence + locking across viewers — ink written INTO the one artifact, identity-scoped, read-only to others; never re-derived per viewer | WETINK I3/§2; WETINK-HANDOFF §2 |
| **R3** | Agent → recipients → final-agent-approve flow + completion gate — group-sequential with agent checkpoints; clean accept skips the agent; a candidate practitioner cannot complete (routes to full agent); file+email only on the approving sign-off | ceremony-v3 §4/§11-B.1; WETINK §9 R#1; AT-322 |
| **R4** | Multi-recipient signature-block grouping — ONE block per role, render-time loop expands one full block per recipient, stacked, each editing only their own; all roles; N-party, no hard-coded pairs | CANON §1; WETINK-HANDOFF §0/§5 |
| **R5** | Deliberate condition-initial gate — ANY document gaining an "other condition" → agent AND every recipient must initial; document BLOCKED from advancing until every party initials every added condition; no auto-carry of consent | recipient-signing-fix §1–3 (AUTHORITATIVE); WETINK §9 R#2 |
| **R6** | MDF disclosure-mark lock / amend / counter-initial / route-back — lock on first owner sign; downstream read-only; strike-through + amender initial; route-back keyed on `signature_request_id` (identity, not `party_role`); completion refused until every affected identity counter-initials | mdf-mark-amendment (AT-303) §2 |
| **R7** | Recipient-identity / "mine" scoping — ONE key `SignatureRequest::canonicalPartyKey()`; ink located by `data-recipient-identity="{role}_{index}"`; every surface keys through it, never raw `party_role`; the old per-viewer `is_mine` model is superseded | WETINK AT-324; WETINK-HANDOFF §2 |

---

## Conformance matrix

Legend: **U** = universal (one spine path, all docs) · **P** = per-template / per-surface / per-doc-type
seam · **U\*** = universal server authority but a divergent/duplicated client or a client-only gate.

| # | Where enforced (file:function:line) | U / P | Gap that recurs per new doc type |
|---|---|---|---|
| **R1** | Gate `SigningController::show():547,577` (`isAgent`) + `SignatureController::sign():1046`. Per-page initials-row shared: `partials/a4-page-styles.blade.php::_buildInitialsRow:494`. Capture modal: agent shared partial `partials/signature-modal.blade.php` (`sign.blade.php:559`) **vs** external re-implements inline `external/sign.blade.php:996-1064` | **U\*** | Injection universal; **capture modal is two code bodies** — a fix to one surface skips the other. |
| **R2** | Write authority `SigningController::authoriseWebFieldWrite:1329`; bake `completeWeb:1755-1773` → `CanonicalInkComposer::bakeInk:61`; overlay stamps `data-viewer-editable` respecting `data-recipient-identity` | **U** | Universal, **except** templates with no `field_mappings` fall to legacy lane `WebTemplateFieldPartyMap:1392`; MDF owner-editable text `field_values` still shared/overwritable (out of AT-303 scope). |
| **R3** | `SignatureService::handlePartyCompletion:1169`, `advanceToNextParty:1567`, `holdForFinalAgentReview:1658`, `approveAndAdvance:1353`, `completeDocument:1906`, `isFullyComplete:1139`. File+email inside `completeDocument`, reached only via `approveAndAdvance` | **U** | Universal; keyed on party_role/status. **Wet-ink carve-out** `SignatureService.php:1256,1258,2493` (per-signing-method branch). |
| **R4** | **Two engines:** universal `RoleBlockExpansionService::expandWithLooping:323` clones `.sig-party-block` (:473-544) — used by 17 templates; **compensator** `SigningSurfaceResolver::cloneFamilyBlockForInstance:220` clones `.signature-section`/`.mdf-sig`; `closestSignatureBlock:305` deliberately **excludes** `sig-party-block` | **P** | **Grouping solved twice, once per markup family.** Root: `template-120.blade.php:40-60` & `template-123.blade.php:144-167` hand-code a fixed roster + raw `data-marker` instead of shared components → seller_2 gets no block. **#1 recurring-bug source (→ P1).** |
| **R5** | Models `DocumentCondition`/`ConditionInitial`; slots `InsertableBlockRenderer::renderInitialSlotsForCondition:568` (gated `CONTEXT_RECIPIENT_SIGNING`); actions `SigningController::initialCondition:4279`, `addCondition:3754`, cascade `checkInitialingCascadeComplete:4606`. Enforcement = **client DOM count only** | **U\*** | **No server floor.** `isFullyComplete:1139` does NOT check condition-initials; `completeWeb` floor (AT-293) checks only consent + ≥1 sig + fill-one-field → crafted/JS-failed POST advances. Plus render-path split (seam D): slots emit only on a fresh recipient-context render. |
| **R6** | Lock `completeWeb:1645` + `show():554`; amend `SigningController::proposeDisclosureAmendment:3517`, `isMarkAmendment:3505`; editability `partials/disclosure-logic.blade.php::_disclosureEditable`; completion `isFullyComplete:1157-1160` | **P** | Activates only for `.corex-disclosure-checklist` docs; `isMarkAmendment` hard-matches `section_reference==='Disclosure'`. Completion gate checks only **pending** Disclosure amendments — weaker than spec's per-`signature_request_id` counter-initial. MDF bare-table ungated vs checklist PPA-s70 owner-only — inconsistent. |
| **R7** | Server authority `CanonicalInkComposer::markerBelongsToSigner:324`; stamps `RoleBlockExpansionService::stampIdentities:111`, `SigningSurfaceResolver:261-284`. External client mirrors it (`external/sign.blade.php::_isMyMarker:3029`) | **U\*** | Server universal & correct. **Agent client diverges:** decides ownership with `isMine = baseRole==='agent'` (`sign.blade.php:808,890,977`) — correct only because agent is always sole-of-role. Per-surface client divergence. |

---

## The six seams (root causes), ranked by how often they re-break

| Seam | What it is | Rules corrupted | Evidence |
|---|---|---|---|
| **A. Two signing surfaces, duplicated client code** | `SignatureController::sign()`→`signatures.sign` (agent) vs `SigningController::show()`→`external.sign` (recipient); modal + ownership JS duplicated/divergent | R1, R5, R7 | `SignatureController.php:1046`; `SigningController.php:565`; `external/sign.blade.php:996-1064,3029` vs `sign.blade.php:559,808` |
| **B. Two grouping engines by markup shape** ⭐ | `sig-party-block` loop vs `mdf-sig`/`signature-section` clone; hand-rolled 120/123 blades force the second path | R4, R2, R7 | `RoleBlockExpansionService:323`; `SigningSurfaceResolver:220,305`; `template-120.blade.php:40-60`; `template-123.blade.php:144-167` |
| **C. Client-only condition-initial gate** | gate lives in blade DOM count, not `isFullyComplete` | R5 | `isFullyComplete:1139` (no condition check); `completeWeb` AT-293 floor `:1439-1453` |
| **D. Serve-path fork in `show()`** | compiled_templates (`renderForSigning`) vs stored `merged_html` re-render (`reRenderBlocksForViewer:296,421`) vs legacy — a rule present in one branch absent in another | R5, R2, R1 | `SigningController.php:333-366,296,421` |
| **E. Feature wired per-document-type** | MDF amend keyed on `section_reference==='Disclosure'`; bare-table vs checklist asymmetry | R6 | `SigningController.php:3505,3507`; `disclosure-logic.blade.php` |
| **F. `document_type`/slug/name string branches** | universal behaviours gated on one type | R3, R4 | `SignatureService.php:1003-1006` (`stampLegalDeadline` mandate-only); `ESignWizardController.php:2600` (grouping slug==='mandate'); `TemplateController.php:978` (sig block by name); `SignatureController.php:1931,1991` (`rental_upload_send`) |

Seam **B** is the #1 recurring-bug source and the subject of the **P1 build plan** (companion doc).

---

## Consolidation plan (summary — lift each seam into the universal engine)

Ordered by impact ÷ risk. Every item is a **fix to existing behaviour, not a new feature** (freeze-safe).

- **P1 (MED-HIGH, highest payoff):** route templates 120/123 + future MDF/Addendum through the shared
  `signature-line`/`signature-block`/`initials-line` components; then retire
  `SigningSurfaceResolver::cloneFamilyBlockForInstance`. **See `P1-build-plan.md`.**
- **P2 (LOW):** add the server floor for condition-initials in `SignatureService::isFullyComplete`
  (identity-keyed — every live `DocumentCondition` has a `ConditionInitial` per required party). Closes
  recipient-signing-fix §3(b).
- **P3 (MED):** render condition-initial slots on **every** serve path (fresh / stored `merged_html` /
  compiled), so presence never depends on which branch `show()` takes.
- **P4 (MED):** unify the two surfaces' capture modal + ownership JS into one shared module; delete the
  agent `baseRole==='agent'` shortcut so both surfaces resolve identity through `markerBelongsToSigner`.
- **P5 (MED, legal):** drive the MDF disclosure gate off a structural signal, not the literal
  `section_reference==='Disclosure'`; strengthen the completion gate to the spec's identity-keyed
  "every affected `signature_request_id` counter-initialled". **Requires Johan's ruling on the gating
  asymmetry (see P1 doc, Decision 1).**
- **P6 (LOW-MED, incremental):** replace `document_type`/slug/name string branches with declared template
  capabilities (extend `DocumentTypeClassifier`).

Land P1–P4 first (they carry the recurring bugs); P5–P6 harden.

---

## Conformance-test design (verify any document against the spec before it's clicked)

**Base harness already exists and is the right one:** `tests/Concerns/BuildsSigningSession` drives the
**real** `/sign/{token}` route through the actual controller + blade + Alpine state and asserts against the
rendered document HTML extracted from `webTemplateHtml`. `RealTemplate111EndToEndTest` is the working model.

**Two upgrades make it a universal per-document conformance gate:**
1. **Data-provider over every real template**, not one fixture — enumerate every
   `web-templates/cds/template-*.blade.php` (and each live `render_type=web` Template), each with
   **2 same-role recipients + 1 agent** (the minimum that exposes seller_2 grouping/identity/lock bugs).
   A newly-imported document becomes a new provider row automatically.
2. **Exercise all three serve paths** (fresh render / stored `merged_html` snapshot / compiled-serving) —
   the current harness only injects `merged_html`, so it structurally cannot see the render-path bug
   (seam D). Assertions must hold on all three.

**Assertion battery (one method per rule, run for every template × serve-path × viewer):**
- **R4** grouping: viewer=seller_2 → body contains `data-recipient-identity="seller_2"` on ≥2 block units; distinct signature block per recipient.
- **R2** field lock: seller_2's field has `data-viewer-editable="1"` when viewer=seller_2, NOT when viewer=seller_1.
- **R1** initials modal: initials-row + a single shared capture-modal marker on every party/page-but-last; same modal marker on both surfaces (guards seam A).
- **R5** condition gate: add a condition as agent → agent initial slot renders; a POST to advance with it un-initialled is **rejected server-side** (proves P2); slot renders on all three serve paths (proves P3).
- **R3** agent-review gate: non-agent completion holds at `STATUS_PENDING_AGENT_APPROVAL`; file+email don't fire until `approveAndAdvance`; wet-ink exempt.
- **R6** MDF: seller_1 signs → seller_2 grid read-only → crafted differing `complete-web` → `422`; a mark amendment blocks completion until seller_1 counter-initials (identity-keyed). Vacuous for non-disclosure templates.
- **R7** identity: ownership on BOTH surfaces resolves through the `data-recipient-identity` mirror of `markerBelongsToSigner` (assert the agent surface no longer keys on `baseRole==='agent'`).

**Placement & gate:** lands in `tests/Feature/Docuperfect/SigningView/` (e.g. `UniversalSigningConformanceTest`).
Because `dev-check.ps1`'s e-sign moat already fails any change to the pipeline files without a test diff
there, wiring the conformance test into that directory means **every future template import or engine
change must keep all seven rules green for all templates** — the structural end to per-document recurrence.

---

## Method note (how this was audited)

Read `origin/QA1` in a detached read-only git worktree (never the stale local branch). Spec authority read
in full; engine enforcement traced by `file:function`; per-template hardcoding hunted across
`app/Services/Docuperfect`, `app/Http/Controllers/Docuperfect`, `resources/views/docuperfect`. Key
findings independently cross-checked in code (`isFullyComplete`, the `show()` serve-path fork, the
`isAgent` gate, the two-surface view routing, the 19-template signature-markup classification).
