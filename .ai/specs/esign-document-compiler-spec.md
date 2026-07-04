# E-Sign Document Compiler — Build Spec

> **Status:** DRAFT for Johan's approval (AT-177). Foundation-first programme; launches the three-lane campaign.
> **Supersedes at completion:** the runtime half of `esign-v3-complete-spec.md` (the compile model becomes canonical; that spec's snapshot/merge runtime is retired per §9).
> **Author:** CC1, synthesizing (a) the conductor's design and (b) the as-built e-sign recon (AT-158 thread + the 2026-05 e-sign audits).
> **Pillars:** Document (primary) · Deal · Contact · Agent. Cross-pillar reactivity via the domain-events catalogue (NN#9).

---

## 0. Why this exists (the thesis)

CoreX e-sign today **stores a compiled structure (`cds_json`) but signs from `merged_html` snapshots.** That inversion is the disease. Every live bug in the 2026-05 reset (`.ai/audits/esign-reset-investigation-2026-05-27.md` — 5 live bugs shipped while 49 RecipientLoop unit tests were green) traces to the same root: **the artifact that gets signed is not the artifact that was validated.**

Two compounding facts make it worse than a single stale snapshot:

- **`cds_json` is not canonical — it's an importer-time fossil**, never updated by builder edits. What the render pipeline actually consumes is `field_mappings` + the generated blade + `merged_html`. So "we store a CDS" is itself misleading.
- **There is no single source of truth for "what fields does this template have."** The reset audit counts **six** overlapping definitions — `cds_json`, `editor_state.tags`, `editor_state.mappings`, `editor_state.tagged_html`, `field_mappings`, `fields_json` (+ the generated blade + live `CdsDraft` rows). *"Six places to be inconsistent."* Save 1 seller, reload 4.

A snapshot drifts from its source, and six field-definitions drift from each other; so the codebase grew a ring of *compensators* whose only job is to detect and paper over that drift (`MergedHtmlFreshnessGuard`, marker fuzzy re-expansion, orphan-mapping pruning, signature-surface re-stamping, letterhead re-swapping, role re-detection). Compensators are load-bearing patches over a wrong shape.

**The fix is architectural, not another patch.** Compile the document **once** into a canonical, typed, versioned structure. Validate *that structure* at compile time behind a hard linter gate. Sign, render, and PDF **only** from the compiled structure. When the signed artifact *is* the validated artifact, the entire staleness class becomes **unrepresentable** — there is no snapshot to drift, so there is nothing for a freshness guard to guard.

> Design filter (CoreX Operating Principle): we do not ship a better snapshot pipeline. We remove the need for snapshots. "Best-in-class or rebuild."

---

## 1. Product framing — TWO DOORS

The compiler serves two go-to-market doors. Both compile to the **same** canonical structure; only the ingest front-end differs.

1. **Door A — CoreX Standard SA Pack (included).** A curated, versioned library of South-African real-estate documents (mandates, OTP, FICA, disclosures, lease) shipped and maintained by CoreX. Agencies use them out of the box.
2. **Door B — "Your agency docs, CoreX compiles them for you" (white-glove onboarding).** An agency hands over its own Word/PDF documents; CoreX compiles them into canonical templates as an onboarding service.

**The Compile Studio is an internal tool, not customer-facing.** Agents/agencies consume *published* templates; they never see the compiler. This keeps the linter gate strict (no self-service publish of an unlinted template) and makes Door B a service moat, not a support burden.

**Three delivery modes preserved** (the March CDS decision): every published template supports **web e-sign**, **PDF wet-ink**, and **download-only** — all rendered from the one canonical structure (§6), never from divergent code paths.

---

## 2. The canonical model — Compiled Document Structure (CDS v2)

The compiled artifact is a typed, addressable tree. It is the **sole** runtime truth.

```
CompiledTemplate (immutable once published)
  id, agency_id (nullable = CoreX standard), family (116/117/…), version (int), content_hash
  data_dictionary_ref          # which dictionary version the bindings resolve against
  parties: [ Party ]           # declared topology, roles 1..n
  blocks:  [ Block ]           # ordered, typed, addressable
  assets:  [ Asset ]           # letterhead, images — pinned by hash
  delivery_modes: [web_esign, pdf_wetink, download]   # which are enabled
  render_parity: { web_hash, pdf_hash }               # proven equal at compile (linter L6)

Party
  key (e.g. seller_1, buyer_2, agent, witness_1), role, cardinality (1 | 1..n),
  required, ordering (signing order), contact_binding_rule

Block  (addressable by stable block_id — NEVER by DOM position)
  block_id, type ∈ {prose, clause, field_group, signature, initial, insertable_slot, letterhead, page_break, conditional},
  visibility:  PartyExpr        # who sees it (declared, not detected)
  editability: PartyExpr        # who may edit it (declared; replaces data-viewer-editable stamping)
  condition:   Expr?            # when it renders (party combination / data predicate)
  fields:      [ Field ]        # for field_group
  anchors:     [ Anchor ]       # for signature/initial — per role

Field  (every fill-point — no exceptions)
  field_id, label,
  binding: DataDictionaryEntryRef   # MANDATORY — an unbound field cannot compile (linter L1)
  source:  {auto | party_input | agent_input},
  validation: (inherited from the dictionary entry; may tighten, never loosen)

Anchor
  anchor_id, kind ∈ {signature, initial, date, name},
  party_key,            # which declared role signs here (linter L3: every role has ≥1 anchor)
  page/coords (for PDF), block-relative (for web)

Insertable_slot  (replaces today's marker-expansion)
  slot_id, accepts: BlockType[], typed contract, default_block?
```

**Everything the runtime needs is *in* the CDS.** No merged HTML. No re-derivation. No "normalize the surface at request time." The compiled tree already is the normalized surface.

### 2.1 The typed CoreX real-estate Data Dictionary

Field binding is the heart of the studio. A dictionary entry is a **typed, validated** CoreX concept — validation lives on the entry, so it is enforced identically at compile, at fill, and at sign.

| Category | Example entries | Type / validation |
|---|---|---|
| Money | `purchase_price`, `deposit`, `commission_incl_vat` | ZAR; `R 1,250,000` formatting; VAT 15% derivations |
| Identity | `seller_id_number`, `buyer_id_number` | SA-ID **checksum** (Luhn+DOB+citizenship) |
| Property | `erf_number`, `title_deed_no`, `scheme_name`, `unit_no`, `gps` | erf/sectional-title shape; matches Property pillar |
| Practitioner | `agent_ppra_no`, `agent_ffc`, `designation` | PPRA registration format; truthful-status gate (AT-142) |
| Dates | `offer_date`, `occupation_date`, `expiry_date` | ISO+display; ordering constraints (occupation ≥ transfer) |
| Parties | `seller_full_name`, `buyer_marital_status` | resolves against the Contact pillar |

The dictionary is **versioned**; a compiled template pins the dictionary version it bound against (so a later dictionary change can never silently alter a published template's meaning).

---

## 3. The compile-once pipeline

```
INGEST → SEGMENT → BIND (studio) → DECLARE TOPOLOGY → LINT (gate) → PUBLISH (immutable, hashed) → RUNTIME (render-only)
```

1. **Ingest** — DOCX / PDF / existing CoreX catalogue template in. Normalize to an intermediate parse (styles, tables, images, existing merge-markers if any). Door A and Door B share everything downstream of here.
2. **Segment** — split into **typed addressable blocks** (§2). Each block gets a stable `block_id`. Prose stays prose; a detected fill-point becomes a `Field`; a signature zone becomes a `signature` block with anchors. *This is where today's fragile marker detection is replaced by deterministic segmentation with human confirmation in the studio.*
3. **Field-binding studio** — the internal Compile Studio. For **every** fill-point the compiler operator binds a Data Dictionary entry. Unbound fields are visible and blocking. Insertable regions become **typed declared slots**. This is a human-in-the-loop step (Door B onboarding); AI *suggests* bindings, the operator confirms (AI enhances, never replaces — Pillar principle).
4. **Declare party & signature topology** — roles 1..n as data; per-block visibility/editability as `PartyExpr`; signature/initial anchors per role. No detection of roles from HTML — they are *declared*.
5. **Compile-time LINTER gate** (§4) — the publish gate. Nothing publishes that fails it.
6. **Publish** — freeze into an **immutable, content-hashed, versioned** `CompiledTemplate`. Signing requests **pin the exact version** (§5).
7. **Runtime** — render web/PDF/download **only** from the canonical compiled structure. No merge, no snapshot, no freshness check.

---

## 4. The compile-time LINTER gate (publish gate)

A `CompiledTemplate` **cannot be published** unless every rule passes. This gate is the whole point: it moves correctness from "hope the runtime merge matches" to "prove it at compile."

- **L1 — All fields bound.** Zero unbound fill-points. Every `Field.binding` resolves to a live Data Dictionary entry.
- **L2 — Zero orphan mappings.** No binding points at a field that no longer exists; no field points at a missing dictionary entry. (Obsoletes `pruneOrphanFieldMappings` — orphans are *unpublishable*, not pruned-after-the-fact.)
- **L3 — Anchors per declared role.** Every declared signing party has ≥1 signature anchor; every anchor references a declared party. (Obsoletes role-block re-detection.)
- **L4 — Every conditional / party combination resolves.** Enumerate the party-cardinality × conditional space; each combination must produce a valid, fully-bound render (no dangling block, no unreachable required field).
- **L5 — Validation coherence.** Each field's validation is the dictionary entry's, optionally tightened, never loosened.
- **L6 — Web + PDF render-parity diff.** Render every party-combination in web and PDF; a structural diff must pass (same blocks, same bound values, same anchors). Parity hashes are stored on the artifact. (This is the guarantee the three delivery modes never diverge.)
- **L7 — Legal-mode coherence** (§6.1). A template whose `legal_class` forbids e-sign cannot publish with `web_esign` enabled (Alienation of Land Act §2(1) / ECTA §13(1)). The legal block is a compile-time invariant, not a runtime name-regex.

Gate output is an auditable **lint report** attached to the version. A failed lint blocks publish with precise, block-addressed errors.

---

## 5. Immutable versioned artifacts — freshness becomes unrepresentable

- A published `CompiledTemplate` is **immutable** and **content-hashed**. Editing produces a **new version**; the old version is retained (NN#1 no hard deletes).
- A **signing request pins the exact `(template_id, version, content_hash)`**. The signer signs *that* artifact; the audit trail records the hash on every event (already an e-sign invariant).
- **There is no snapshot to go stale.** The "freshness" class — the thing `MergedHtmlFreshnessGuard` exists to police — is now **unrepresentable**: runtime has nothing to re-derive, so nothing can drift from source.
- Re-issuing after a template update is an explicit, audited **version migration** of the signing request, never a silent re-render.

---

## 6. Runtime — render-only from canonical structure

One renderer, three modes, zero divergent paths:

- **Web e-sign** — the signing view is a projection of the CDS for the signer's party (visibility/editability from declared `PartyExpr`, anchors from declared anchors). No `data-viewer-editable` stamping at request time — editability is compiled in. **Security invariant preserved:** the DOM is never the authority — on write, the server re-derives "may this party edit this field" from the compiled CDS (today's `authoriseWebFieldWrite`), so a tampered client cannot write a field it wasn't compiled to own. Signer identity per instance stays `{party_role}_{role_index}` (e.g. `seller_2`).
- **PDF wet-ink** — the same CDS rendered to PDF with the same bound values + anchor coordinates. Parity with web is *proven at compile* (L6), not hoped for at runtime.
- **Download-only** — the compiled PDF artifact, no signing session.

The renderer is a pure function `render(CDS, party, mode) → surface`. Pure ⇒ trivially testable ⇒ the golden harness (§7) can enumerate it.

### 6.1 Delivery modes are declared **and legally constrained at compile time**

Today `allowed_delivery_modes` is a CSV string (`'esign,wet_ink,download'`) and an `isEsignBlocked()` check strips `esign` at *runtime* via a 4-layer heuristic (slug → template_type → name regex → audit) because **South African law forbids e-signing certain instruments** — Alienation of Land Act 68 of 1981 §2(1) + ECTA 25 of 2002 §13(1) mean an Offer to Purchase / sale agreement **must** be wet-ink. That legal constraint is a **document-class fact**, so it belongs in the compiled artifact, not in a runtime name-regex:

- The CDS declares `delivery_modes` explicitly, and a **`legal_class`** (e.g. `alienation_of_land`) that the compiler resolves from the document family — not from a fuzzy name match.
- **Linter rule L7 (legal-mode coherence):** a template whose `legal_class` forbids e-sign **cannot publish with `web_esign` enabled.** The block becomes a compile-time invariant with an audit trail, not a runtime string-strip that could be fooled by a renamed template.
- Runtime simply honours the compiled `delivery_modes`; the wet-ink portal and download paths render from the same CDS. (Wet-ink retains its March-decision behaviour, incl. no strikethrough override.)

---

## 7. Golden test harness (CI-gated per template version)

- For each `CompiledTemplate` version, **auto-generate fixture signings for every party combination** (seller×n, buyer×m, with/without witness, each conditional branch) from the declared topology.
- Each fixture drives a full signing through the render-only runtime and asserts on the **rendered document body**: all bound fields populate, every anchor is placed for the right party, web/PDF parity holds, the completed document hash is stable.
- **Build on the existing golden tier, not the misleading one.** Extend `tests/Feature/Docuperfect/SigningView/*` and its harness `tests/Concerns/BuildsSigningSession.php` — which creates a **real** template + recipients through `SignatureService::createSigningRequest()` (never `Model::create` shortcuts) and asserts against the true rendered body via `extractRenderedDocumentHtml()`. **Retire the `tests/Feature/RecipientLoop/*` tier's role** as the coverage signal: it asserted on hand-crafted synthetic HTML and isolated service methods — *49 green while 5 live bugs shipped*. The lesson is baked into the compiler design: because the runtime is a pure `render(CDS,…)`, the harness exercises the **real** path per combination, not a mocked loop.
- **CI gate:** a template version cannot be marked publishable in CI unless its full golden set is green.
- Fixtures are generated *from the CDS*, so they can never fall out of sync with the template the way hand-written fixtures did (the current `template-111-canonical.blade.php` fixture carries 0 `data-role-block` attrs and thus silently exercises the legacy fallback — the auto-generated model makes that class of fixture-drift impossible).

---

## 8. Migration path (compile the reference proofs first)

Foundation-first, prove-before-retire:

1. **Reference proofs — templates 116 / 117 / 119** (the hand-crafted Sales Mandate Pack: HFC Marketing Permission v11 `116`, MDF `117`, Addendum B `119`; signed in sequence as a `web_pack`). They are centrally-owned, legally-fixed, hand-crafted Blade documents — exactly the "Johan/Claude own template design" model the March decision blessed — and they **cleanly separate the compiler's concerns**:
   - `117` (**0 `data-field`s** — pure static legal prose + a YES/NO/N-A table + signature includes) and `119` (**0 fields** — static addendum + signature block) isolate the **signature-surface + letterhead + pagination** compilers with zero role-loop/field complexity.
   - `116` (**15 `data-field`s** — the interactive marketing-permission form) then adds the **field-binding** layer on top.
   - The pack path exercises **multi-party sequencing**.
   A compiler that reproduces all three **exactly** (parity-diff against current live output before cutover) has proven signature surfaces, letterhead, pagination, field binding, and pack sequencing — without yet touching template-111's hard role-loop. They are the golden reference.
2. **Parallel-run** — compiled artifacts render alongside the legacy path (behind a per-template flag) until the golden harness + a live parity diff prove equivalence for each.
3. **Cutover per template** — flip a template to render-only from its CDS once its proof is green. No global switch; each template migrates on its own clock.
4. **Door A pack** — compile the standard SA library.
5. **Door B** — onboard the first agency's own docs through the studio.

---

## 9. Compensator retirement map

Each compensator is retired **only after** the compiler feature that obsoletes it is proven on the reference templates. (Structural retirement, not deletion-on-faith.)

All seven exist for one reason: **they repair a frozen `merged_html` snapshot at serve time because there is no compile step and no canonical structure to sign from.** (Proof of the snapshot path: `SigningController::show()` uses `web_template_data['merged_html']` verbatim when present — `SigningController.php:265-266` — then mutates it through five services in sequence.)

| Compensator (today, real path) | What it repairs at serve time | Root cause it patches | Obsoleted by | Retire when |
|---|---|---|---|---|
| `MergedHtmlFreshnessGuard` (`:81`) | re-renders the blade when `template.updated_at > document.rendered_at` — and note there is **no `rendered_at` column**, so it proxies with `updated_at` and can *miss* a needed rerender | snapshot is authoritative but drifts from source | **§5** — no snapshot exists; runtime renders from canonical CDS | render-only cutover proven for the template (§8.3) |
| `SignatureSurfaceNormalizer` (`:37`) | additively stamps `data-marker-type="signature"` so the engine's `[data-marker-party][data-marker-type=signature]` selector finds a surface | hand-rolled templates put party on a wrapper but never emit a signable surface → **zero signable surfaces** | **declared `signature` blocks + anchors** (§2) — surfaces are compiled, not stamped | render-only cutover |
| `LetterheadRefresher` (`:30`) | finds the header `div` by an inline-style needle and swaps a fresh header into every occurrence | baked letterhead in the snapshot shows a stale agency forever | **pinned compiled asset** (§2 `assets`, re-resolvable by ref) | render-only cutover |
| `InsertableBlockRenderer` unbound/fuzzy layer (`:460`) | `~{4,}…~{4,}` tolerant regex + **Levenshtein ≤2** fuzzy-matches garbage markers (`~~~~<span>Other Contitions</span>~~~~`) to a purpose | typed-in-body text markers split by HTML; live `insertable_blocks=[]` so *everything* hits this fallback | **typed declared `insertable_slot`** (§2) — slots are structure, not tildes to find | segmentation produces typed slots |
| `RoleBlockDetectionService` legacy clustering + most of `RoleBlockExpansionService` LCA machinery | regex-parses `data-field` names into `{role_base, instance_index}`, clusters contiguous same-role fields, walks LCAs (fragile — two disjoint `seller` clusters was a live bug) | party topology is *inferred from HTML*, not declared | **declared party topology** (§2, L3) + promoting `data-role-block` to a first-class typed slot | topology declared for the template |
| `RoleBlockNormalizer` (`:53`) — the import-time contract stamper | stamps `data-role-block`/`data-role-block-segment` at `cdsGenerate` time so expansion can prefer a declared path over LCA guessing | there is no declared block model — this bolts one on *post-hoc, in the DOM* | **§2 makes this contract first-class in the CDS** (its whole job moves into segmentation) | segmentation emits typed role slots |
| `canonicalFieldMappings` (`:98`) / `pruneOrphanFieldMappings` (`:144`) | single read-site over 6 divergent field-truth sources + prune tags not referenced by the renderer | **six places to be inconsistent** (§0) | **§2.1 binding** — one canonical structure; the binding *is* the mapping (L1); orphans are unpublishable (L2) | template fully bound + linted |

**Do not delete any compensator before its row's "retire when" is met on the affected template.**

**Fix the pipeline gate as WS0 hygiene (recon found two defects):** `scripts/dev-check.ps1` + CLAUDE.md list `app/Services/Docuperfect/SurfaceNormalizer.php` — **that file does not exist** (phantom entry); and the gate **omits `RoleBlockNormalizer.php`**, which is the most compiler-adjacent file in the codebase (the `data-role-block` contract stamper). Correct the gate list before the programme starts so the moat guards the real files. The gate itself stays in force throughout — every change to a pipeline file still requires a matching `tests/Feature/Docuperfect/SigningView/` diff.

---

## 10. Workstreams (WS0..n) — gate criteria, sized for three lanes

DR2's proven pattern: each workstream ships behind an explicit gate; the next cannot start until the gate is met. Lane assignments are indicative (rebalance at kickoff).

- **WS0 — Foundation & CDS v2 schema + Data Dictionary (Lane A).** The `CompiledTemplate` schema, migrations, the versioned Data Dictionary (money/id/property/practitioner/date/party entries) with validation. **Gate:** schema + dictionary land with unit tests for every dictionary validator (SA-ID checksum, ZAR, PPRA, date ordering); no runtime touched.
- **WS1 — Linter gate engine (Lane B).** L1–L6 as a pure, testable engine over a CDS, with block-addressed error reporting. **Gate:** golden pass/fail fixtures for each rule; a deliberately-broken CDS fails on the exact rule + block.
- **WS2 — Render-only runtime (web/PDF/download) (Lane C).** The pure `render(CDS, party, mode)` renderer for all three modes. **Gate:** renders a hand-authored CDS in all modes; L6 parity passes on it.
- **WS3 — Golden test harness + CI gate (Lane A).** Auto-generate fixture signings per party combination from a CDS; wire the CI gate. **Gate:** harness enumerates a multi-party CDS and blocks CI on any red.
- **WS4 — Ingest + segmentation + Compile Studio (Lane B).** DOCX/PDF ingest, deterministic segmentation, the internal binding studio (AI-suggest + operator-confirm), typed insertable slots. **Gate:** an operator compiles a real document end-to-end to a lint-passing CDS.
- **WS5 — Reference-proof migration: 116/117/119 (Lane C).** Compile the three, parallel-run, live parity diff, per-template cutover. **Gate:** all three render byte/parity-identical to today's live output; golden sets green; render-only cutover for at least one.
- **WS6 — Compensator retirement (all lanes, gated by §9).** Retire each compensator as its row's condition is met on the migrated templates. **Gate:** each retirement lands with the pipeline-gate test diff proving the replacement; dev-check green.
- **WS7 — Door A standard pack + Door B onboarding studio polish.** Compile the SA standard library; harden the studio for white-glove onboarding. **Gate:** the standard pack ships lint-clean; a pilot agency doc onboarded.

Each WS: one concern, its own gate, its own tests, sized so three lanes run WS0–WS2 in parallel (schema / linter / renderer are independent), then converge on WS3–WS5.

---

## 11. Non-negotiables carried in

- **No hard deletes** — template versions and signing artifacts are retained; supersede, never delete (NN#1).
- **Permissions** — compile/publish is a gated internal capability (`esign.compiler.*`); signers need no new perms.
- **Domain events** (NN#9) — `TemplateCompiled`, `TemplatePublished`, `SigningRequestVersionPinned`, `DocumentSigned` emit through the catalogue; auto-file + FICA + deal-pipeline listeners subscribe (the integration moat).
- **The e-sign pipeline gate** (`scripts/dev-check.ps1`) remains in force for the entire programme.
- **Spec-first** — this document is the spec; WS build prompts read it + the referenced audits before code.

---

## 12. Open decisions for Johan (before WS0)

1. **Data Dictionary storage** — DB table (agency-overridable entries) vs config + migration backfill? (Recommend DB, versioned, with a CoreX-standard seed.)
2. **CDS storage** — one `compiled_templates` table with a JSON `structure` column + hash, or normalized block/field tables? (Recommend JSON structure + hash for immutability + a thin index table for querying bindings.)
3. **PDF engine parity** — confirm the PDF renderer (dompdf / Puppeteer) that can meet L6 parity with the web render; this constrains WS2.
4. **Lane assignment + sequencing** — confirm WS0/WS1/WS2 as the three parallel opening lanes.

---

*This spec is the foundation. It is deliberately uncompromising about the one thing that matters: the signed artifact is the compiled, linted, versioned artifact — nothing else can be signed. Everything else is workstream detail.*
