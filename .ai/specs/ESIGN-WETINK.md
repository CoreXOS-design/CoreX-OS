# ESIGN-WETINK — the wet-ink doctrine for CoreX e-signature

> **Architectural north star. Johan's ruling, 2026-07-19, permanent record.** Supersedes any e-sign
> design that renders the document per surface or per party. Read before ANY e-sign render/signing work.
> Pairs with `ESIGN-CANON.md` (governing doctrine), `claude_esignature_v2_spec.md`, `esign-ceremony-v3.md`,
> and `amendment-review-v2.md` (AT-302, the flag/amendment detour that plugs into this chain).

---

## 0. The doctrine (non-negotiable)

**E-sign must mimic wet-ink exactly.** There is ONE canonical document artifact. It flows SEQUENTIALLY,
party to party. Each recipient receives the EXACT accumulated version the previous party sent — every
fill, every initial, every signature already present, rendered identically. The document NEVER
re-renders differently per screen. That accumulation is what makes it auditable and court-defensible.

This is legal paperwork that can close an agency if wrong. No half measures, no per-surface rendering,
no ink stored as a viewer-local overlay.

The physical analogue: a paper mandate. Agent prepares it. It goes to seller 1, who signs the SAME sheet
of paper. That same sheet — now bearing seller 1's ink — goes to seller 2, who sees seller 1's signature
and adds their own. Every party writes on the ONE document. Nobody gets a freshly-printed copy.

---

## 1. The five invariants

**I1 — One artifact.** A signing session has exactly ONE canonical document: its rendered HTML
(`documents.web_template_data['canonical_html']`, superseding the current dual `merged_html` +
`signed_paginated_html`). This artifact is the single source of truth for what every party sees and what
the final PDF is generated from. No surface renders the document from any other input.

**I2 — Render once, then display.** The document is composed to its canonical HTML EXACTLY ONCE — when
the agent finalises and sends (v0). From then on, every surface (agent fill&sign, each recipient
ceremony, agent review, PDF) DISPLAYS the stored canonical artifact verbatim. No surface re-runs the
role-block expansion / letterhead / insertable / normalize pipeline at display time. Re-rendering at
display is the defect class behind Johan's finding (a) — it lets the same document look different on
different screens because each screen re-computes it from different inputs.

**I3 — Ink is written INTO the artifact.** When a party fills a field, initials, or signs, that ink is
composed INTO the canonical HTML and persisted — it becomes part of the document, not a per-viewer
overlay keyed on `is_mine`. After party N completes, the canonical artifact literally contains party N's
signature image, initials and field values in the document body. The next party loads that same artifact
and sees them because they ARE the document. (Finding (b): today signatures render as Alpine overlays
filtered by `is_mine`, so party 2 never composes party 1's ink — architecturally wrong.)

**I4 — Sequential accumulation with an immutable snapshot per hop.** The session advances one party at a
time (existing sequential routing). At each hop the canonical artifact is snapshotted immutably before
the next party touches it, forming the audit chain:

```
v0  agent-prepared (sent)
v1  = v0 + seller-1's fills/initials/signature      (snapshot on seller-1 complete)
v2  = v1 + seller-2's fills/initials/signature      (snapshot on seller-2 complete)
…
vN  final (all parties)                             → the signed PDF is generated from vN
```

Each `vK` is stored read-only (`document_versions` / append-only `web_template_data['version_chain'][]`
= `{version, party_role, party_name, at, canonical_html, hash, ip, ua}`). The chain IS the legal record:
it proves exactly what each party saw and signed, in order. No version is ever mutated after its hop.

**I5 — Uniform ink rendering.** ONE signature style, ONE initial style, ONE size rule, applied by the
single renderer. A signature always renders in a fixed-dimension render box (fixed width×height, vector
where possible, `object-fit: contain`, no per-marker scaling); initials in a fixed initial box. Ink is
never upscaled from a small raster (the cause of finding (c)'s "large and faint"). Signature/initial
appearance is a property of the canonical renderer, not of the marker geometry it happens to land on.

---

## 2. The canonical pipeline (one renderer, one artifact)

```
                          ┌─────────────────────────────────────────┐
  agent finalises  ──────▶│ CanonicalDocumentRenderer::compose()     │  runs ONCE
  (fill & send)           │  merged_html → normalize → letterhead →  │
                          │  insertable → role-block expansion       │
                          │  → CANONICAL HTML (v0)                    │
                          └───────────────────┬─────────────────────┘
                                              │  persisted as documents.canonical_html
                                              ▼
        every surface DISPLAYS the stored canonical HTML verbatim (no re-render):
        ┌──────────────┐   ┌──────────────────┐   ┌───────────────┐   ┌──────────┐
        │ agent        │   │ recipient N       │   │ agent review  │   │ final    │
        │ fill&sign    │   │ ceremony (signs)  │   │ (AT-302)      │   │ PDF (vN) │
        └──────────────┘   └────────┬─────────┘   └───────────────┘   └──────────┘
                                    │ party N signs → ink composed INTO canonical HTML
                                    ▼
                     CanonicalInkComposer::apply(v, party N ink) → v+1 (snapshot)
```

- **`CanonicalDocumentRenderer`** — the ONLY place role-block expansion (collective-clause logic,
  per-seller detail loop), letterhead, insertable blocks and surface normalisation run. Output is the
  canonical HTML. Runs at finalise/send and on an agent amendment apply (AT-302) — never at recipient
  display.
- **`CanonicalInkComposer`** — writes a party's fills/initials/signature INTO the canonical HTML at the
  party's field/marker positions, producing the next version. Uniform ink render boxes (I5).
- **Display** — the recipient ceremony, fill&sign and review surfaces load `canonical_html` (the current
  version for that hop) and render it read-only; the only interactive elements are the CURRENT party's
  own unfilled fields / unsigned markers, overlaid as input affordances that, on submit, are composed
  into the artifact by the composer. Prior parties' ink is already in the HTML — displayed, never
  re-derived, never filtered by `is_mine`.

---

## 3. Recipient-fillable blocks (other-conditions) as first-class flow steps

The "other conditions" block (finding (d)) is a recipient-fillable region of the canonical document, not
an afterthought. It is a first-class step in the party's ceremony: the current party may add/edit their
other-conditions text; on submit it is composed INTO the canonical artifact (I3) and snapshotted (I4),
so every subsequent party sees the added conditions as part of the document. It must be RENDERED and
EDITABLE for the party whose turn it is, on the canonical surface — never gated off. Its history and the
regression that removed it are in the gap audit (§ below).

---

## 4. Where the amendment / flag flow (AT-302) plugs in — a versioned detour

The flag → agent-amend → re-send → initial-only continuation loop (AT-302, keep its phases) is a
**versioned detour** on the same chain, not a parallel system:

- A recipient flag freezes the chain at the current version (AT-291 ⑤) and notifies the agent (AT-299).
- The agent reviews IN the canonical document (AT-302 Phase 1 — already renders the document + highlighted
  clause + note) and applies an amendment. Apply runs `CanonicalDocumentRenderer` on the amended clause
  ONLY (tracked-change `<del>`/`<ins>` composed into the canonical HTML), producing a new version `vK+1`
  with a snapshot — the same immutable chain (I4). The edit is audit-logged (who/when/original/new).
- The chain resumes: every party (incl. already-signed) initials the amendment ONLY on the canonical
  artifact (initial composed in, snapshot taken); the ceremony CONTINUES — no full re-sign. Prior ink
  stays because it is part of the artifact (I3).

So AT-302 is: freeze → agent composes an amendment version → resume with initial-only accumulation. It
reuses `CanonicalDocumentRenderer` (amend) + `CanonicalInkComposer` (initials) + the version chain.

---

## 5. What this replaces (defect classes eliminated)

| Wet-ink invariant | Eliminates Johan's finding |
|---|---|
| I2 render-once-then-display | (a) render divergence across screens |
| I3 ink written into the artifact | (b) recipient 2 not seeing recipient 1's ink |
| I5 uniform ink render box | (c) signature/initial size/weight variance |
| §3 fillable blocks as flow steps | (d) other-conditions no longer recipient-fillable |
| I4 immutable version chain | the audit/court-defensibility requirement |

---

## 6. Acceptance (Johan's OTP import test doc, deployed qa1)

Drive the full chain on his OTP import mandate: agent fills & sends (v0, canonical) → seller 1 opens,
sees the SAME document, fills/initials/signs (v1 snapshot) → seller 2 opens and **sees seller 1's ink
already in the document**, adds their own (v2) → (optional) a flag → agent amends in-doc (versioned
detour) → initial-only resume → final PDF generated from vN carrying every party's ink, rendered
identically to what each party saw at their hop. Every surface shows THE artifact; nothing re-renders
differently. The version chain is inspectable as the audit record.

---

## 7. GAP AUDIT — current implementation vs the doctrine (2026-07-19, file:line evidence)

### Finding (a) — render divergence: FOUR surfaces, four (input, pipeline) pairs, no shared render
| Surface | file:line | INPUT | expandWithLooping? |
|---|---|---|---|
| Recipient ceremony `show()` | `SigningController.php:281-359` | stored `merged_html` | YES (352) — normalize(318)→letterhead(323)→insertable(332)→expand(352) |
| Agent pre-send `templatePages()` | `ESignWizardController.php:1398-1464` | **FRESH blade** (1398) + RoleBlockNormalizer(1457) + TRANSIENT recipients(1440) | YES (1459) |
| Agent signing `sign()` | `SignatureController.php:915-946` | `merged_html` | **NO** — normalize+letterhead only |
| Final PDF `generate()` | `SignaturePdfService.php:32-42` | `signed_paginated_html` else `merged_html` | **NO** — verbatim to Puppeteer |
> **PLUS a fifth, rival renderer:** the AT-177/WS6 cutover (`c2e2a5cc`, 2026-07-06) added
> `CompiledSigningRenderer::renderForSigning` (`SigningController::sign` compiled branch :259-267) which
> **bypasses the whole legacy chain** (`CompiledSigningRenderer.php:22`). Compiled vs legacy templates
> render by completely different code. **Root: the document is a re-render recipe, not a stored artifact.**

### Finding (b) — recipient 2 never sees recipient 1's ink: two rival, both-broken mechanisms
- **Baked (merged_html):** `completeWeb` DOES embed + persist the signer's ink into `merged_html`
  (`SigningController.php:1513-1531`) — intent right — BUT `embedSignaturesIntoHtml`
  (`SignatureController.php:1504-1603`) matches by **party alias** (`data-marker-party`, Strategy 3 fills
  every same-party surface :1579-1588), NEVER by `data-recipient-identity`; and `merged_html` is stored
  **UN-EXPANDED** (looping is a render-time transform, never persisted :1502-1510). So `show()`'s
  `expandWithLooping` `cloneNode(true)`s the one baked block into every seller instance
  (`RoleBlockExpansionService.php:1340/1410`); `mutateCloneForInstance` (:1743-1787) rewrites only
  `data-field` nodes, never the signature `<img>` → ink is **duplicated, not identity-scoped**.
  Representing N same-party recipients' distinct ink in `merged_html` is **structurally impossible**.
- **Overlay (markers):** `is_mine` is per-request (`sign.blade.php:1418`); recipient-1's `signature_data`
  IS serialised into recipient-2's payload (:1420) but render only draws `marker.is_mine` (:519/527) —
  and the other-party branch is **hard-disabled by AT-300** (`x-if="false"` :545/:788).
- **⚠️ AT-300 REGRESSION on (b):** the marker-hide I shipped this weekend removed the ONLY path
  recipient-2 had to an overlay-stored recipient-1 signature. It is CORRECT under the wet-ink doctrine
  (overlays die; ink is baked) but **must not stand until ink-baking lands** — until then it worsens (b).
  Reconcile in Phase 1 (bake first, then the overlay removal is safe).
> **Canonical column verdict:** `merged_html` is the *intended* canonical (only column that accumulates
> ink) but is re-rendered by every surface AND party-scoped + un-expanded → cannot represent N same-party
> recipients. `signed_paginated_html` = last-writer-wins browser DOM, PDF-only. `markers[].signature_data`
> = per-viewer/`is_mine`-gated. **Canon needs ONE stored, FULLY-EXPANDED, identity-scoped
> (`data-recipient-identity`) artifact, baked on submit, rendered verbatim by all surfaces.**

### Finding (c) — signature/initial size & weight variance: FOUR uncoordinated sizing regimes
Capture (400×150 / 400×100 PNG, `sign.blade.php:1361/1034`) · browser (`height:40px` + variable
`width:${marker.width}%` :514/:757, `object-contain`) · PDF bake (`DocumentFlattener.php:277-278` +
`imagecopyresampled` no aspect lock :908-913 → upscaled small raster = "large and faint") · initials
(page-break `60×30px` :102-104; markers "80% field height" `DocumentFlattener.php:931`). **No shared
ink-size constant.** Root: every surface/marker sizes ink to its own geometry.

### Finding (d) — recipient other-conditions no longer fillable
The "+ Add condition" affordance is emitted ONLY by the legacy `InsertableBlockRenderer`
(`:222-236`, `CONTEXT_RECIPIENT_SIGNING`; modal `add-condition-modal.blade.php:64-77`; endpoint
`SigningController::addCondition():3255-3360` — all intact). The **compiled** path
(`CompiledSigningRenderer`, `CdsRenderer.php:130`) emits the block as an empty `<div class="cds-slot">`
— no button/textarea/POST. **Broken by `c2e2a5cc` (AT-177/WS6, 2026-07-06)** for cut-over templates;
Johan ran a compiled template. Built `61014a56`→wired `89776d17`→finalised `a8b0620c`→broke `c2e2a5cc`.

---

## 8. REBUILD PLAN — phased, onto the canonical spine

**SURVIVES (aligns; moves onto the spine):** collective-clause + per-seller-detail render (AT-300b,
becomes part of the one compose) · flag freeze (AT-291⑤) · agent notification + FLAGGED list (AT-299) ·
Amendment Review V2 Phase 1 (renders the document — becomes the review surface displaying the artifact) ·
mail identity (AT-296) · field autosize (AT-300) · sequential routing (`advanceToNextParty`) + initialing
cascade (`requeueAllPartiesForInitialing`, `checkInitialingCascadeComplete`, `SectionAcceptance`) ·
seller-ID preserve (AT-292) · mandatory floor (AT-293) · empty-email deferral (AT-294).

**REBUILT onto the spine:** the 5 rival renderers → ONE `CanonicalDocumentRenderer::compose()` run once ·
per-viewer `is_mine` ink overlays + party-alias embed → `CanonicalInkComposer` writing identity-scoped
ink INTO the stored expanded artifact · dual legacy/compiled path → single compose (fold
`CompiledSigningRenderer` in or retire it) · four ink-size regimes → one render box · add the version chain.

**⚠️ REVISED EFFORT (the audit moved the number).** Phase 1 is NOT a serve-swap. Because `merged_html`
is un-expanded + party-scoped, ink cannot be composed for N same-party recipients in it — Phase 1 must
(i) compose+store a FULLY-EXPANDED, identity-scoped artifact and (ii) re-key ink embedding from party to
`data-recipient-identity`. That is a data-model + embed-logic change, not a swap.

| Phase | Scope | Fixes | Honest estimate |
|---|---|---|---|
| **1a** | `CanonicalDocumentRenderer::compose()` — run the full chain ONCE at finalise/send; persist FULLY-EXPANDED, identity-stamped `canonical_html`. `show()` serves it verbatim (no re-render). | (a) for the ceremony | 1.5–2 d |
| **1b** | `templatePages()` + `sign()` + PDF serve the same `canonical_html`; retire/fold `CompiledSigningRenderer`. | (a) fully; (d) path-unification | 1.5–2 d |
| **1c** | `CanonicalInkComposer` — bake each party's fills/initials/signature INTO `canonical_html` by `data-recipient-identity` on submit; recipient N+1 serves the accumulated artifact. Remove `is_mine` overlays. Reconcile AT-300. | (b) | 3–4 d |
| **1d** | Uniform ink render box (one size/style constant, browser + PDF). | (c) | 1 d |
| **1e** | Sealed immutable version per hop (`version_chain[]` v0…vN + hash). | audit | 1–1.5 d |
| **2**  | Other-conditions fillable as a flow step on the canonical surface (restore, compose-in). | (d) | 1 d |
| **2**  | AT-302 amendment detour refit onto the chain (amend = new version via renderer; initial-only resume via composer). | flag loop | 2–3 d |

**Total ≈ 11–15 working days** for the full canon; the go-live-critical journey (1a–1e + other-conditions)
≈ 9–12 days. This exceeds the 12-day runway if done sequentially by one lane — flag for Johan: either
parallelise across lanes or scope the first battle-test to 1a+1b+1c (render-once + accumulation), which
delivers the court-defensible core, with 1d/1e/2 fast-following.

**Build order tonight:** 1a first (compose-once + store + serve on `show()`) — the spine + the finding-(a)
fix for the ceremony, verifiable on the Anine doc — then 1b, then 1c.
