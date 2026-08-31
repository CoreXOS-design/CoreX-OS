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

---

## 9. Flow rulings — Elize full-flow run (2026-07-20, Johan)

Rulings from Johan's live full-flow test with Elize, on the canonical-document spine.

### Ruling #1 — clean accept flows straight to the next recipient (IMPLEMENTED `8360202f`)
A recipient who **ACCEPTS with NO strikeout and NO flag does NOT go back to the agent** —
the pen passes **straight to the next recipient**. The agent is a checkpoint **only** when a
flag or a strikeout raises a PENDING amendment; then the amendment-ripple runs (§4 / Build 1).
Implementation: `SignatureService::handlePartyCompletion` advances a clean accept via
`advanceToNextParty` (next waiting party, any group) and parks at
`STATUS_PENDING_AGENT_APPROVAL` only when a `DocumentAmendment` is PENDING; `completeWeb`
delegates routing entirely (dropped its pre-emptive per-co-owner approval set). N-party.

### Ruling #2 — clause/condition initialing (TICKET — not built)
Adding **any** Other Condition, or inserting a **Clause-Library** clause, requires an
**INITIAL from whoever added it** (agent or recipient) — the same mechanism as a strikeout
initial (initial composed INTO the canonical, audit-logged: who/when/text/initial). More than
a small addition → own ticket (AT, reporter Johan). Relates to Build 1 strikeout initialing.

### Ruling #3 — completion → PRINT + FILE (TICKET — not built)
On approve/complete, the agent must be able to **PRINT** the final document (hard-copy filing
is a legal requirement). The **filed document on the property/mandate must have BOTH a VIEW
and a PRINT button.** Print exists on some e-sign surfaces (`esign/download.blade.php`,
`wet-ink-confirmation.blade.php`) but the **property/deal filing surface** needs auditing —
add whichever of view/print is missing there. Own ticket (AT, reporter Johan).

### Build 1 — AMENDMENT MODEL (LOCKED + SIMPLIFIED, Johan + Elize 2026-07-20)
> This SUPERSEDES the earlier "strikeout on both sides + full N-party ripple" spec. It is
> SMALLER. Do NOT build recipient-side strikeout; do NOT build a full-document re-sign ripple.
> Build-gated: land + verify the 2 render bugs + recipient-2 leg FIRST.

**Two amendment mechanisms, by actor:**
- **STRIKEOUT = AGENT-ONLY, PREP-TIME ONLY.** The agent may strike/edit text while PREPARING
  the document (before/at prep). Struck text stays VISIBLE (lined-through, never deleted) with
  an auto-rendered initial beside it (the agent's), audit-logged (who/when/original/initial).
  **Recipients CANNOT strike anything in e-sign** — a recipient who needs a strike must move to
  the WET-INK process. (No recipient-side strikeout build.)
- **RECIPIENTS ONLY FLAG.** The existing FLAG mechanism IS the recipient amendment path. Only
  the AGENT ever mutates the document — **agent = sole source of truth.** Flags are captured +
  logged; the agent makes the actual edit.

**FLAG-RESOLUTION FLOW (the core of v1):**
- **Gating:** while ANY flag is unresolved, that person CANNOT sign — the doc returns straight
  to the AGENT to fix; once fixed, the person signs. (Largely built — the 423 freeze gate +
  pending-amendment routing; VERIFY it.)
- **Recipient 1 flags (COMMON, ~999/1000):** nobody else has signed yet → agent fixes → recipient
  1 signs FRESH / FULL. Simple. This is the architecture's centre.
- **Recipient 2+ flags (RARE late-flag edge):** agent resolves that part → it returns to
  recipient 1 (and anyone else who already signed) to **INITIAL ONLY THAT CHANGE** — they do
  NOT re-sign the whole document — then flow continues forward to the next unsigned recipient.
  (Forcing a full re-sign of all prior parties is unacceptable — loses deals.)

Rides the canonical spine (§1–§4): the agent's edit is a new canonical version; the initial-only
re-consent composes the prior signer's initial INTO the artifact at the changed clause. Build
the initial-only path but treat it as the edge case, not the centre.

**v1 = perfect-world flow (agent → r1 → r2 → agent approve → file) + flag→agent-fix→sign gating
+ the two flag cases + agent-prep strikeout + email-all-ink + filed/view/print. NO recipient
strikeout, NO full-document re-sign ripple.**

### Phase 2 — OTP clause-select / build-document-from-clauses (QUEUED — ticket, post-launch)
Agent selects at e-sign setup (property: SS vs FH; parties: VAT / no-VAT; price: cash /
cash+bond / bond-only / sale-of-2nd) → only applicable clauses render; every clause tagged
with applicability rules; a setup wizard that cannot produce an invalid contract. RISK: a
missing clause is invisible (unlike a visible strikeout) → heavy validation + testing; build
properly after launch. Related to Build 1.

---

## 10. E-SIGN v1 — Definition of Done (Johan, 2026-07-20) — THE target

Build to EXACTLY this, nothing more, until it works end-to-end. Sequenced so each lands testable.

**(a) PERFECT-WORLD FLOW must work clean FIRST (the immediate gate):**
agent creates → recipient 1 signs → recipient 2 signs → agent approves → files. On a 2-seller
doc: both sellers get IDENTICAL sign/initial actions; ink accumulates into the canonical;
agent-review shows ALL ink. (This is the six-bug + recipient-2-parity work.)

**IN v1 (non-negotiable, NOT deferred):**
1. **Amendment model (§9 Build 1, LOCKED)** — agent-only prep-time strikeout + recipients-only
   flag → agent fixes → sign; r2+ late-flag = prior signers initial-only-the-change. NO recipient
   strikeout, NO full re-sign ripple.
2. **Auto-initial on ADD** — adding any clause / other-condition requires an initial from
   whoever added it (same mechanism as a strikeout initial). (Ruling #2.)
3. **Emails carry ALL ink** — recipient emails must render the document with every prior
   signature/initial (currently they don't). Fix the email doc render to use the accumulated
   `canonical_html`, not a bare/early snapshot.
4. **Final document FILED + VIEWABLE + PRINTABLE** — the filed doc on the property has a VIEW
   button AND a PRINT button (print = hard-copy for legal filing). (Ruling #3.)

**Flow optimisation (Elize, IMPLEMENTED `8360202f`):** a clean accept (no strikeout/flag)
flows straight to the next recipient; only flag/strikeout routes back to the agent.

**Build order:** (a) prove perfect-world spine clean on-site FIRST → (b) strikeout+ripple +
clause-add-initial → (c) email-all-ink + filed/view/print.

**⚠️ BUG1 correction (2026-07-20):** the "other-conditions recipient-fillable block" is a v1
item, NOT a one-line bug. Traced on doc 431: its template has `insertable_blocks: 0`, no
`~~~~` markers, no other-conditions region in merged_html — so `compose()` produces no body
block; the "+ Add condition" button is blade-only (`add-condition-modal.blade.php`) with
nowhere to render. Delivering it requires a recipient-fillable other-conditions region to
EXIST in the document (default region for all mandates, or template config) + the add→initial
of item (2). Folds into build order (b). `stampConditionSigningToken` (token overlay on show)
is in place for templates that DO carry a body block, but is a no-op where none exists.

### NOTE (log only — do NOT build now): WET-INK / OTP flow REVISED
OTP is generated THROUGH e-sign but distributed **download → sign → upload**, and ALWAYS
returns to the AGENT for approval **between each party** (never recipient→recipient) — an
uploaded scan can't be trusted for what changed, so the agent verifies every hop. This is a
SEPARATE build AFTER e-sign v1 (supersedes any recipient→recipient assumption for the wet-ink
path; the e-sign electronic path keeps Ruling #1's straight-through flow).

---

## 11. Agent-review renders the ONE canonical spine (2026-07-20)

The agent-review surface (`SignatureController::review` → `review.blade.php`) renders the
SAME canonical artifact as the signing screens — it MUST NOT re-render or re-style the
document. Concretely:

- **Render read = `CanonicalDocumentRenderer::forDisplay($template)`** (identical to
  show()/sign()/setup). Read-only: no editability overlay, no field→input conversion.
- **`forDisplay` staleness rule:** returns the STORED `canonical_html` ONLY once ink is
  baked (`canonical_version >= 1` — the accumulated source of truth carrying every prior
  party's signatures/initials/fills). For an UNBAKED doc (version 0 / never composed) it
  **re-composes fresh**, so structural pipeline fixes (per-recipient attestation split,
  uniform ink) always reach unsigned docs and the review never shows a stale snapshot. A
  stored v0 composed before a fix is otherwise served forever — that was the agent-review
  divergence (1 shared seller block instead of the per-recipient split; wrong ink sizing).
- **Styling is the shared spine ONLY.** `review.blade.php` must not add its own document
  styles. The shared `a4-page-styles` partial governs ink sizing (fixed-height, uniform per
  party), the per-recipient blocks, and initial rendering. The old review-only emerald
  border on `.web-sig-interactive` (the "green box") was removed — the review only makes the
  document non-interactive (`pointer-events:none`), never re-styles it.
- **Accumulated ink** (prior recipients' baked signatures/initials) renders because it is
  IN the canonical `forDisplay` returns; the review renders it verbatim.

Result: agent-review is byte-identical to the ceremony's document render — same ink size,
same per-recipient scoping, same accumulated signatures.

---

## AT-324 / AT-325 — canonical per-recipient key + captured page-break initials (doc 452)

Two faults surfaced on the agent-review/approval screen of a 2-seller document (452):

**Bug A — a signed 2nd co-seller misread as the next signer ("Send to Andre" after Andre had
signed).** N same-role recipients are stored as N `signature_requests` rows sharing the base
`party_role` ("seller") but carrying a distinct `role_index` (1..N). Every OTHER surface —
`signing_order_json`, `parties_json`, `partyProgress()`, `signed_initials` — identifies them by
the COMPOSITE key ("seller", "seller_2", …; bare = index 1). `review()` built its
`completedParties` set from a raw `pluck('party_role')`, so `seller_2` was never in the completed
set and the next-party loop resolved to it.

- **THE key, one place:** `SignatureRequest::canonicalPartyKey()` = `role_index > 1 ? party_role .
  '_' . role_index : party_role`. Any surface comparing a request against the signing order MUST
  key through this, never raw `party_role`.
- **Fix:** `SignatureController::review()` builds `completedParties` via `canonicalPartyKey()`.
  The ACTION side (`SignatureService::approveAndAdvance` / `advanceToNextParty`) already advances
  by `signing_order` (next WAITING request), so it was correct — only the DISPLAY was wrong.
- Closes AT-324/AT-325 (same root: two representations of one recipient identity that disagreed).

**Bug B — the previous recipient's INITIALS missing from the rendered document (review + PDF).**
Page-break initials are a PAGINATION-time artifact (a per-page-boundary row); they are absent from
the un-paginated `canonical_html` that both the review and the PDF render. The captured ink lives
in `web_template_data['signed_initials']` keyed `"{recipientKey}-init-{page}"`, but had no slot to
render into, so it vanished.

- **Requirement:** the captured initials must render IN THEIR CORRECT PAGE-BREAK SLOTS on the
  document pages (where the signer placed them) on the review/approval surface AND the PDF — exactly
  like the signatures do. A separate "initials captured" summary block is NOT acceptable.
- **Fix — the ONE renderer, everywhere.** Page-break initial slots are created by the shared
  `paginateDocument()` and filled by `restoreStoredInitials()` in
  `resources/views/docuperfect/signatures/partials/a4-page-styles.blade.php` — the exact code the
  ceremony uses so a later signer sees earlier signers' initials. Two gaps closed:
  1. **Keying (review + everywhere):** `restoreStoredInitials()` matched only the base-role
     top-level group of `signed_initials`, so the 2nd co-seller's initials (nested as
     `{ seller: { "seller_2-init-0": … } }`) landed in the 1st seller's page-break box and the 2nd's
     stayed empty. It now keys each initial by the CANONICAL RECIPIENT KEY embedded in its sub-key
     (`seller_2-init-0` → `seller_2`) and matches each box by its own `data-marker-party` — so every
     signer's initials land in THEIR box. (Same canonical-key root as Bug A.)
  2. **PDF (was rendering nothing):** the PDF renders the un-paginated canonical via Puppeteer and
     never ran the pagination JS. `SignaturePdfService::injectInitialsPagination()` now wraps the
     canonical in `#pdfDocContent` and appends the SAME shared JS (read verbatim from the partial via
     `esignPaginationJs()`) plus a bootstrap that runs `paginateDocument()` + `restoreStoredInitials()`
     with `parties_json` + `signed_initials`, so Chromium paginates and places every initial in-position
     before printing. Fail-open (try/catch) — the PDF always at least renders the canonical.
- **Proof (doc 452, real Chromium):** the PDF-input HTML paginates and fills agent + seller_2 boxes,
  seller (captured none) empty — both recipients' initials in-position, correctly attributed.
- **Write-side integrity (the upstream half — why a genuinely-initialed co-seller was blank):**
  `SigningController::completeWeb()` stored `signed_initials` as
  `$existingInitials[$partyRole] = $initials` — keyed by the **bare** `party_role` and **overwritten**
  per completion. Two same-role co-signers share one base role, so the 2nd seller's completion
  **clobbered** the 1st's captured initials: the ink survived in `web_template_data['signatures']`
  but vanished from `signed_initials` (the store the review + PDF read), so a genuinely-initialed
  co-seller rendered a blank box. This was NOT an enforcement gap — the ceremony did require and
  capture her initials. Fixed: keyed by `$signingRequest->canonicalPartyKey()` (seller vs seller_2)
  and **merged** (`array_merge`), so every co-signer keeps their own group. Sub-keys are already
  recipient-distinct, so the render fix then places each in its own box. Regression guard:
  `tests/Feature/Docuperfect/SigningView/CoSignerInitialsPersistTest.php` (two sellers both initial →
  both persist under their own canonical keys after the 2nd completes).

---

## AT-332 — generated/emailed PDF must match the on-screen signed pages

Two production defects on a filed Exclusive-Authority-to-Sell (doc 452):

**(1) Filed doc not tagged "Mandate".** `SignatureService::fileSingleDocument()`
(`:2115`) files with `document_type_id => $docTemplate?->document_type_id` — the
filed document inherits its type from the SOURCE TEMPLATE. The mandate templates
already carry `document_type_id = 1` (Mandate) and the DocuPerfect editor already
exposes "Document Type" (edit / edit-web / cds-builder → `saveFields`/`cdsGenerate`
persist it), so real mandate docs already file as Mandate. The one gap was the
"monday morning test" CDS template (#67, behind doc 452) had it NULL — set to 1.
Every doc filed from a mandate template files as Mandate; the setting is a real,
editor-exposed field, not a DB poke.

**(2) Emailed PDF ≠ on-screen signed document.** The single-doc PDF rendered
`canonical_html`, but the ink baker stamps a "Signed by {name}" caption into EVERY
role-block signature cell of the canonical — including inline clause cells the
signer's paginated document collapsed — so the PDF showed 12 signature captions
(4 extra mid-document, around clauses 2.6/2.7/2.7.4) vs the 8 the signer saw, and
being un-paginated it re-flowed under A4/`zoom:0.82` to a different page count with
mismatched "Page X of Y" footers. Fix: `SignaturePdfService` render precedence now
prefers **`signed_paginated_html`** (the exact per-document `.corex-a4-page` DOM the
last signer submitted, with all ink + page-break initials in position) over the
canonical; `injectInitialsPagination()` is a no-op when the source is already
paginated (contains `corex-a4-page`) so it renders verbatim. Canonical stays the
fallback for docs with no captured paginated DOM.

**Proof (doc 452, real Chromium):** PDF-input now = signed_paginated_html →
**3 pages**, **8 "Signed by"** (no extra inline rows), **6/6 initials in position**,
footers **"Page 1 of 3 / 2 of 3 / 3 of 3"** — matches the on-screen signed document.

---

## AT — agent's OWN completion never baked into canonical_html (I3 gap, doc 1113)

**Finding:** the agent's own "Complete Signing & Send" (`SignatureController::
webSignComplete()`, the in-app screen at `/documents/{id}/sign`) has never, since
I2/I3 landed (`ba2792a96`, 2026-07-19), written the agent's ink INTO
`canonical_html`. It only ever embedded into the legacy `merged_html` (§7 Finding
(b)'s "party-aliased embed", kept for backward compat). `SigningController::
completeWeb()` — the RECIPIENT ceremony's completion — was correctly wired to
`CanonicalInkComposer::bakeInk()` in that same commit; the agent's own path was
not. A straight I3 violation: "After party N completes, the canonical artifact
literally contains party N's signature" — it didn't, for the agent specifically.

**Why it was invisible until 2026-08-27:** `CanonicalDocumentRenderer::
resolveOrCompose()` used to RE-DERIVE canonical from `merged_html` on every view
while still at v0 ("so the served structure always reflects the current
pipeline"). That re-derivation accidentally picked up the agent's `merged_html`
-only ink on every recipient's view, masking the gap. Commit `996fa5452`
(2026-08-27, "one document, composed once") correctly REMOVED that
re-derivation to fix a real, separate, confirmed bug (a Domicilium
position-numbering disagreement — the fix is right; "serve what is stored;
never recompose it" is the correct rule for THAT bug). Removing it stopped
masking this one: with nothing ever baking the agent's ink into canonical_html,
every recipient's screen showed the agent's signature block blank
("Awaiting agent", no image) — reported live by Johan on doc 1113/template 737.

**Fix:** `SignatureController::webSignComplete()` now bakes the agent's own
signatures/initials/ceremony values into `canonical_html` via
`CanonicalInkComposer::bakeInk()` and bumps `canonical_version`, mirroring
`SigningController::completeWeb()`'s pattern exactly (re-derive canonical from
merged_html only when not-yet-baked, split `-init-` keys, sole-of-role
bleed-safe fallback, re-apply accumulated ceremony_values after). The agent is
now just another party baking ink into the one artifact (I3), same as every
recipient already was.

**Proof:** doc 1113 (Anine Van der Westhuizen, seller, signing_order 2) —
before the fix, the agent's 4 signature markers on her screen all read
`data-signed=null`, "Awaiting agent", no `<img>`. Repaired the existing
document's data (agent's already-captured signatures/initials baked in
one-time via the same `bakeInk()` call), then reloaded Johan's exact link:
all 4 blocks now `data-signed="true"`, real signature image, "Signed by Johan
Reichel". New documents completed after this fix bake correctly at
`webSignComplete()` time — no manual repair needed going forward.

**Residual, not touched (flagging, not fixing):** `SigningController::
completeWeb()`'s own not-yet-baked re-derive branch (line ~2058-2078, "AT-373
Issue D") still re-composes fresh from `merged_html` when `canonical_version <
1` — this is the SAME re-derive pattern `996fa5452` removed from
`resolveOrCompose()`, kept here deliberately for a narrower purpose (picking
up mid-ceremony amendments). `996fa5452`'s own commit message already flagged
this exact branch as unverified under the new single-composition model and
out of that day's scope — still true; not re-examined here.

---

## Rule — the final agent-approval gate is UNCONDITIONAL on every flow

**The gate:** once every real signing party has completed, the document
ALWAYS lands at `pending_agent_approval` and holds — `completeDocument()`
(the call that files the PDF and emails every recipient) may only fire after
the agent reviews and clicks Approve. This is Johan's absolute rule (2026-08-25).
It must never be special-cased per party shape — plain natural-person, joint
sellers, company/director groups, and estate/proxy shapes all gate identically.
The one *acknowledged* exception is wet-ink (see below) — everything else is
unconditional.

### Bug — a late-estate document skipped the gate and dispatched straight to recipients (fixed 2026-08-25)

**Symptom (Johan's report):** on a natural-person-with-late-estate mandate
(a deceased seller represented by an executor, alongside a living seller),
once the recipients finished signing the document skipped
`pending_agent_approval` entirely, filed the PDF, and emailed every recipient
immediately — landing straight under Completed. The plain natural-person flow
(no deceased party) was unaffected.

**Root cause — the phantom-row trap.** `SignatureService::
handlePartyCompletion()` hands the pen to the next member of a completing
party's `signing_group` via `nextWaitingInGroup()`, which finds "next" by raw
`status === waiting`. A `SignatureRequest` row for a deceased party (or one
collapsed out by a proxy) is ALSO created at `status = waiting` and only
lazily flips to `not_required` the moment `sendSigningRequest()` actually
walks it (`isSigningParticipant()`) — it is never `not_required` up front. So
when the group's true last real signer completes, and a not-yet-visited
deceased/proxy-collapsed sibling still shows `waiting` with a later
`signing_order`, the group-handoff call
(`SignatureService.php` line ~1429, inside `handlePartyCompletion()`) treats
that phantom row as "someone else to sign" and hands off to it. Inside
`advanceToNextSigningParticipant()`, the phantom is walked, found not to be a
real participant, flipped to `not_required`, and the walk finds nobody left —
at which point `advanceToNextParty()` checks whether to hold for agent review
or complete outright. The group-handoff call had never told it this could be
the final release: `gateFinalizeForAgentReview` silently defaulted to `false`
(the parameter default on `advanceToNextParty()`), so `completeDocument()`
fired directly instead of `holdForFinalAgentReview()`.

Plain natural-person groups never hit this: with two living co-sellers,
`nextWaitingInGroup()` always resolves to a real person, so the caller's
default-`false` gate value is never consulted — the bug only surfaces when
the group's last "waiting" row turns out to be a phantom, which only happens
in estate/proxy-collapse shapes.

**The other, already-correct call site** — the clean-accept branch a few
lines below (line ~1476) — computes the gate fresh every time:
`$request?->signing_method !== 'wet_ink'`. It was never wrong; the
group-handoff call simply never got the same treatment.

**Fix:** the group-handoff call now passes the identical gate computation:
```php
$this->advanceToNextParty($template, $completedParty, $nextInGroup, $request?->signing_method !== 'wet_ink');
```
One line. No new branching, no per-shape special-casing — it closes the bug
CLASS (any signing_group whose apparent "next" member resolves to nobody),
not just the late-estate instance.

**Verified (2026-08-25), all 6 regression-harness shapes, driven through
`SignatureService::handlePartyCompletion()` exactly as the real controllers
call it** (`SigningController::completeWeb()` / the amendment-cascade path):

| Shape | Structure | Result after final real signer |
|---|---|---|
| A | two natural sellers | `pending_agent_approval` (unchanged) |
| B | natural + late estate (executor's signing_order BEFORE the deceased row) | `pending_agent_approval` (was the bug — now fixed) |
| C | natural + late estate (Supplier-executor variant) | `pending_agent_approval` (was the bug — now fixed) |
| D | company, 3 directors, no proxy | `pending_agent_approval` (unchanged) |
| E | company + proxy | `pending_agent_approval` (never actually vulnerable — see below) |
| F | manual recipient | `pending_agent_approval` (unchanged) |

Confirmed directly in Mailpit against a live shape-B document (template 758):
zero "Fully signed" completion emails to any recipient at the moment the
final real signer (the executor) completed; the completion email only
appeared the instant the agent's own `approveAndAdvance()` ran. The deceased
row correctly ends at `not_required` in every case — the fix only changes
which gate value is passed through, not the walk/skip logic itself.

**Why shape E (company + proxy) was never actually vulnerable:** unlike a
deceased party, a proxy-collapsed co-director never gets its own
`SignatureRequest` row at all — `Signing Setup` only creates a row for the
proxy who actually signs. Shape E's `seller`-role group therefore contains
exactly one row; `nextWaitingInGroup()` has no sibling to find, so the
group-handoff branch is never even entered — the clean-accept branch (already
correct) handles it every time. The phantom-row trap is specific to shapes
that create a real DB row for a non-signing party — today, that means
deceased sellers only.

**Known, pre-existing, ACKNOWLEDGED exception — wet-ink.** Wet-ink parties
never complete via `handlePartyCompletion()` at all: they upload a physical
scan, and a staff/agent inspector reviews it via
`SignatureService::reviewWetInkUpload()` / `approveUploadOnBehalf()`, which
call `advanceAfterWetInkApproval()` directly — a separate advancement path
that has NEVER held at `pending_agent_approval`, by design ("wet-ink review
IS the agent approval" — see the comment at `completeDocument()`'s wet-ink
call sites). That inspection is a per-scan approval, not the same
whole-document "Review & Approve" gate every digital flow now holds at
unconditionally. This is pre-existing behaviour, untouched by the fix above,
and was flagged in-code before this fix as "AT-322 open question." **Whether
wet-ink should also route through a final whole-document Review & Approve
step is Johan's call, not made here** — reported, not fixed, in this pass.

---

## Rule — representative display order is ONE decision, never re-derived

When an entity (a company) is represented by more than one person and the
document shows all of them (the Domicilium address block, the parties
clause), the order they appear in is decided ONCE, per document, and every
surface that lists them agrees with it:

1. **A manual drag-order** the agent set on the Recipients screen
   (`moveEntityRep()` → `_entity_rep_order`), if one was set; else
2. **Proxy first**, everyone else in their existing relative order — the
   same fallback `ESignWizardController::resolveEffectiveRepOrder()` itself
   uses when no manual order exists.

The single shared primitive for applying this is `Contact::
applyRepresentativeOrder(Collection $reps, ?array $orderContactIds)`
(`app/Models/Contact.php:737`) — "ONE ordering, every consumer reuses THIS,"
per its own docblock. Nothing that lists an entity's representatives may sort
them a different way.

### Bug — the Domicilium disagreed with the Recipients screen on a company+proxy document (fixed 2026-08-27)

**Symptom (Johan's report, doc 1135):** on a company-with-a-proxy mandate,
the Recipients screen correctly showed the proxy first. Once the agent
opened the document to sign it, the Domicilium block's address/contact
listing had the proxy LAST instead — and because the artifact is composed
exactly once and never recomposed (see "one document, composed once" below),
that wrong order was permanent for the rest of the document's life.

**Root cause — two independent implementations of the same expansion, only
one of them order-aware.** Turning "one collapsed proxy row" into "one
address/contact block per representative" is a single conceptual operation,
but it had two separate, disagreeing implementations:

- `ESignWizardController::buildEntityRepresentationPreview()` (the
  Recipients-screen preview) — correctly threads `resolveEffectiveRepOrder()`
  through `Contact::applyRepresentativeOrder()`. Always correct.
- `CanonicalDocumentRenderer::expandRepresentedEntitiesForDisplay()`
  (`app/Services/Docuperfect/CanonicalDocumentRenderer.php:211`, added
  2026-08-26 to fix a different bug — a proxy-narrowed row's OTHER
  representatives never getting their own address block) — cloned the
  entity's representatives in whatever raw order `$entity->
  representatives()->get()` returned. No `ORDER BY` on that relationship, no
  proxy awareness, no call to `applyRepresentativeOrder()` at all: a THIRD,
  independent order source, exactly the failure class `4bf3f7166` (today,
  cc1's living-first ordering fix) closed for a different dimension
  (living-vs-deceased) but did not touch.

**Why the flip lands specifically at "agent sign," not earlier:** the
Recipients-screen preview never touches `canonical_html` — it's a live,
separate render. The FIRST and ONLY time `compose()` (which contains the
buggy function) ever runs for a document is `ESignWizardController::
prepareSigning()` — the handler behind the agent's own "Sign Document" step
— via `CanonicalDocumentRenderer::composeAndStore()`, which refuses to ever
recompose an existing `canonical_html`. So whatever order that one call
produces is frozen for the document's life. Not a re-derive-vs-preserve
timing bug (compose() genuinely only runs once) — a wrong-of-two-
disagreeing-implementations bug, where the wrong one is the one that gets
frozen.

**Fix, two parts:**

1. `expandRepresentedEntitiesForDisplay()` now sorts `$reps` via `Contact::
   applyRepresentativeOrder()` before assigning `role_index` — reusing the
   shared primitive rather than inventing a fourth sort.
2. `compose()`/`composeAndStore()` gained an optional `$entityOrderOverrides`
   parameter (map of `represented_contact_id => ordered contact-id array`).
   `prepareSigning()` — the only caller with the wizard's recipient array in
   scope — resolves each represented entity's real per-document order via
   the SAME `resolveEffectiveRepOrder()` the Recipients screen and the
   `party_clause_text` snapshot already use, and passes it through. This
   means the Domicilium now honours a genuine manual drag-order too, not
   just the proxy-first fallback — matching the Recipients screen exactly,
   not approximately. A caller with no recipient array in scope (the
   `resolveOrCompose()` back-fill fail-safe; `prepareWetInk()`, not touched
   in this pass) still falls back to proxy-first alone via
   `expandRepresentedEntitiesForDisplay()`'s own default — strictly better
   than the pre-fix raw-order behaviour, never worse.

**Verified (2026-08-27):**
- Direct `compose()` calls against template 759 (doc 1135): no override →
  proxy first (Steve Jobs, then Elize Reichel, then HA Pretorius); an
  explicit manual order `[HA Pretorius, Elize, Steve Jobs]` → that exact
  order — proving the override mechanism itself, not just the fallback.
- Two fresh, real, end-to-end company+proxy builds via `prepareSigning()`'s
  actual HTTP path (docs 1136, 1141): proxy first in the Domicilium
  immediately after generation, and unchanged after the agent's own
  signature bake (canonical_version 0→1) — `CanonicalInkComposer` only ever
  queries `@data-marker-party`/`@data-marker-type` elements (confirmed by
  code inspection), never `@data-recipient-instance`, so no ink-baking step
  — the agent's or any recipient's — can disturb Domicilium order once v0
  is composed.
- Doc 1135 itself repaired in place (see below) and reads proxy-first now.
- All 6 regression-harness shapes: agent completion + bake succeed cleanly,
  no regressions. Shape D (3 directors, no proxy) is provably unaffected —
  it already has one real row per representative, so
  `expandRepresentedEntitiesForDisplay()`'s single-collapsed-row branch
  (where this fix lives) never executes for it.

**Doc 1135 repair:** the document was already frozen `completed` with the
wrong order and no separately-stored signature data for the proxy's own ink
(recipients bake straight into `canonical_html`, never into the `Signature`
table — that table only ever gets rows from the agent's own
`webSignComplete()`), so a full recompose-and-rebake was not safe — it would
have discarded the proxy's already-captured signature with no way to
reconstruct it. Repaired surgically instead: parsed the stored
`canonical_html`'s Domicilium segment, grouped its per-instance divs by
`data-recipient-instance`, and physically reordered the groups (proxy
group first) in place — same bytes, same ink, only the read order changed.
Confirmed proxy-first afterward; `canonical_version` and all baked ink
unchanged.

**Not fixed, flagged only:** the document's "parties" intro clause
(`party_clause_text`, via `RoleBlockExpansionService::renderEntityParty()`/
`composeEntityPartyText()`) is a separate render from the Domicilium and was
NOT investigated for the same disagreement — doc 1135's own
`party_clause_text` snapshot is NULL, meaning that clause is falling back to
`renderEntityParty()`'s own live-recompute path (no order threaded there
either). Whether that clause has the same proxy-ordering gap is unknown;
out of scope for this fix (Johan's report named the Domicilium block
specifically) and not touched.

---

## Rule — the amendment-approval action and the final-release gate are deliberately separate

When a recipient strikes something out on a document and it returns to the agent for
review, approving that amendment and completing the document are two distinct,
intentional steps (Johan's decision, 2026-08-28): approving the amendment
(`SignatureController::approveAmendmentNode()` → `SignatureService::
approveAmendmentNode()`) records the agent's acceptance and, once no other
already-signed recipient still owes an initial on the change, hands off to the
SAME unconditional AT-322 final-release gate every document — amended or not —
passes through (`holdForFinalAgentReview()` → `pending_agent_approval`). It does
NOT complete the document itself, even for a single-recipient document with
nothing else pending. The bottom/right-rail "Approve and Finalise" action
(`approveAndAdvance()` → `completeDocument()`) is the one that actually files the
PDF and emails recipients. **This is deliberate and stays exactly as it is** — do
not merge the two gates.

### Bug — the amendment-approve button was mislabelled "Approve & Finalise" (fixed 2026-08-28)

**Symptom:** Johan reviewed a recipient's strike-out, initialed it, clicked
"Approve & Finalise →" on the right-rail Amendments panel, and was returned to
My E-Sign Documents — but the document was still `pending_agent_approval`, not
completed. He had to reopen it and click the genuinely-separate "Approve and
Finalise" button to finish.

**Confirmed NOT a logic bug** — traced the real audit log (template 78, doc
525): `amendment_node_approved` (10:16:59) → `pending_agent_approval` (same
second) → …2.5 minutes later… → `completed` (10:19:36) →
`agent_approved_complete` (10:19:37). The first click did real, correct work;
it simply isn't the completion step, by the design above.

**The actual defect:** the button's label. Live location —
`resources/views/docuperfect/signatures/partials/_agent-amendments-panel.blade.php`
(included by `review.blade.php:692`, gated on `@if($isAmendmentApproval ??
false)` at `review.blade.php:671` — confirmed the real, rendered surface).
`$approveLabel` (lines 14-20) read `'Approve &amp; Finalise'` for the
no-next-party case — word-for-word identical to the separate button that
actually finalises, promising completion this one doesn't deliver.

**Trap for the next person:** `review.blade.php` ALSO has an `@if(!empty(
$isAmendmentApproval)) ... 'Approve &amp; Finalise' ...` block at lines
520-547, inside a form nearly identical to the real one. **This block is
DEAD CODE** — it sits inside `@unless(!empty($isAmendmentApproval))` (line
510), so its own `@if($isAmendmentApproval)` can never be true (confirmed
both by reading the nesting and by rendering the page with
`isAmendmentApproval=true`: zero occurrences of "Review Actions" or
`approveAmendmentBtn`, matching the comment at lines 508-509 that says this
whole block is suppressed in amendment mode). The first attempt at this fix
edited that dead block and verified nothing, because nothing renders there —
caught before push by re-deriving the branch conditions, not by the render
test (the render test's own contradiction — `isAmendmentApproval` true via
`getData()` but the block's markers absent from the output — was the tell).
Left reverted, untouched, exactly as found. If `review.blade.php:520-547`
is ever going to matter again, the outer `@unless` needs to be dealt with
first; until then, `_agent-amendments-panel.blade.php` is the only surface
that renders during amendment approval.

**Fix:** `_agent-amendments-panel.blade.php` — `$approveLabel`'s no-next-party
value changed to `'Approve Amendments'` (Johan's wording), and the matching
`confirm()` dialog text changed from "Approve and finalise the document?" to
"Approve the amendments?" for the same reason (same misleading claim, one
click earlier). The next-party wording (`'Approve &amp; Send to ' . name`)
already correctly describes what that branch does and was left untouched.
Label only — no status transition, gate, or `approveAmendmentNode()`
behaviour changed.

**Verified by code inspection, not by a rendered page** — codebase-wide grep
for the old string confirms `_agent-amendments-panel.blade.php` now has zero
occurrences of "Approve & Finalise"; the branch condition
(`@if($isAmendmentApproval ?? false)` gating the panel's inclusion) matches
Johan's exact traced scenario exactly; compiled view cache cleared. Nobody
should assume this was seen rendering on screen — the next real amendment
review on Staging is the first live confirmation.

### PIN sign missing on the agent review page — investigated further, PARKED as its own ticket (2026-08-28)

`resources/views/docuperfect/signatures/review.blade.php` never includes
`_capture-modal.blade.php` (or `signature-modal.blade.php`, which does) —
confirmed by its complete include list (4 entries, neither present). That
partial is the only place the "Use my saved signature 🔒" PIN option exists.

**A first attempt to fix this (2026-08-28) found the approved approach doesn't
work, and was reverted in full — nothing from that attempt is live.** The
actual trigger for initialing a change on this page is NOT a document-body
slot click reaching `_capture-modal.blade.php` — it's the right-rail
Amendments panel's "Accept & Initial" button
(`_agent-amendments-panel.blade.php`), which turns out to own **its own,
separate, self-contained capture modal** (`#agentCiModal` / `window.AgentCI`,
plain vanilla JS, its own `<canvas>`, its own Draw/Type tabs) — not
`_capture-modal.blade.php`, never has been. It has no saved-signature/PIN
concept because that bespoke implementation was never built with one — not
hidden, not gated, just absent. This is a genuine second, independent
capture-modal implementation already sitting in the codebase, separate from
the one `sign.blade.php` uses.

**Correct fix (not yet built):** replace `_agent-amendments-panel.blade.php`'s
bespoke `#agentCiModal`/`AgentCI` canvas with the real shared modal
(`signature-modal.blade.php`, `savedSignatureSupport: true`), keeping
`AgentCI.capture(item)`'s existing `Promise<bool>` contract (the panel's
`accept(it)` already awaits it) so only what happens *inside* `capture()`
changes — the `window.__corexApplyChangeInitial()` /
`__corexApplyConditionInitial()` persistence calls need no changes. Real
feature work, not a one-line include — needs a careful, non-live-tested pass.
**Parked as its own ticket by Johan's explicit decision** — do not build
without a fresh, explicit go-ahead.

### Fix — redirect after amendment approval now returns to the document (2026-08-28)

`SignatureController::approveAmendmentNode()` (`app/Http/Controllers/
Docuperfect/SignatureController.php`, ~line 3196) used to unconditionally
redirect to `docuperfect.esign.myDocuments` after a successful approval,
regardless of outcome — meaning the agent had to reopen the document to reach
the separate final-release gate (see the mislabelled-button fix above).
Changed to redirect to `docuperfect.signatures.review` for the same document
instead. Safe because: the review page's own status guard
(`SignatureController.php:2644-2660`) explicitly includes
`STATUS_PENDING_AGENT_APPROVAL` — the exact status this action leaves the
document in — in its `$reviewableStatuses` allow-list, so the redirect passes
cleanly; `review()` has no side effects on GET; only one caller (a plain form
POST on the panel above) submits this route.

**Verified**, on a disposable test document (526, not any of Johan's), never
Johan's own documents: with the template set to `STATUS_PENDING_AGENT_APPROVAL`,
`docuperfect.signatures.review` renders the normal (non-amendment) branch —
"Review Actions" heading present, the real `approveAndAdvance()`-backed
"Approve & Finalise" button present, `#approveAmendmentBtn` (the
amendment-mode button) absent. Combined with the code diff itself (the
redirect target is the only change), both halves of the fix are confirmed:
where it goes, and what the agent sees once there.

## Rule — role-block/domicilium expansion runs on EVERY body-rendering path, wizard steps included

### Bug — domicilium rendered correctly in a single document but not inside a web pack, at the wizard's own steps (fixed 2026-08-31, AT-391)

Johan's report, confirmed identical on both QA1 and Staging: a single
EXCLUSIVE AUTHORITY TO SELL document showed the domicilium block correctly
split per seller ("Seller 1" / "Seller 2", each with their own address/tel/
email slots) throughout the wizard steps. The SAME document inside a 3-
template web pack (EATS + MDF + Addendum B) showed one generic, unsplit
"Seller" block during the pack's own wizard steps — but rendered correctly
again at the preview immediately before agent signing, and at the agent-
signing screen itself.

This is the doctrine's §2 canonical-pipeline rule violated in one specific
spot, not a new render path: `ESignWizardController::templatePages()`
(`app/Http/Controllers/Docuperfect/ESignWizardController.php:1823`) is the
ONE shared, client-side-AJAX-driven preview renderer every wizard step calls
after every field edit (`loadTemplatePreview()` in `wizard.blade.php`) —
there is no separate per-step screen. Its single-template branch (~line
2029) correctly runs every preview through `RoleBlockNormalizer::normalize()`
then `RoleBlockExpansionService::expandWithLooping()` before rendering — the
same pair the recipient/agent signing screen (`SigningController::show()`,
lines 294/458) and the final canonical compose (`CanonicalDocumentRenderer::
compose()`, line 151, reached via `prepareSigning()`) already run. Its PACK
branch (~lines 1904-1968, "Pack flow — merge all templates") built the merged
preview by calling `WebTemplateBladeEnsurer::renderOrFallback()` per member
template and concatenating directly — it never called either. An imported
blade view carries no `data-role-block` contract by default (confirmed:
`template-85.blade.php` line 26 is a bare `<div class="corex-h2">Seller</div>`
with no stamped attribute), so without `normalize()` first,
`expandWithLooping()` was never even reached with a usable contract for the
pack case — the generic block simply passed through unexpanded. Single
documents were never affected because they never entered this branch; the
preview-before-signing and agent-signing screens were never affected because
they run an entirely separate, already-correct code path
(`CanonicalDocumentRenderer::compose()` / `SigningController::show()`), not
`templatePages()` at all.

**Fix**: the pack branch now builds `$wizardRecipients` once (same source as
the single-template branch — `buildTransientSignatureRequestsForPreview()`
over the flow's raw, un-deduped recipients) and runs the identical
`RoleBlockNormalizer::normalize()` + `RoleBlockExpansionService::
expandWithLooping()` pair per pack member before concatenating into
`$mergedHtml`. No new expansion logic, no new renderer — the one shared base
screen now runs the same step for a pack member it already ran for a single
document.

**Verified** on Staging (throwaway pack container id 6, EATS+MDF+AddendumB,
throwaway contacts only — never touching `web_packs` id 5 or Johan's own
data): a 2-seller pack now shows "Seller 1"/"Seller 2" correctly split at
steps 4, 5, and 6. Confirmed no regression on three adjacent shapes: a
1-seller pack (exactly one "Physical address" occurrence within the
domicilium segment — no double-render), a 2-seller single document
(unaffected, still correct), and a 1-seller single document (unaffected,
still correct, no double-render).

**The general rule this establishes**: any code path that renders a
template's body HTML for display — preview, wizard step, signing screen, or
canonical compose — must run role-block normalize + expansion before
showing it, whenever recipients exist to expand against. A future body-
rendering surface that skips this pair will reproduce this exact bug class
under a different name.

---

### Bug — the last recipient in a multi-document pack could never enable Submit: disclosure-answer count scoped to the whole pack, not to the document (fixed 2026-08-31, AT-410)

Johan's report: on a natural-persons web pack, the last recipient in the
signing chain reached the end with the ECTA consent box ticked, nothing
outstanding on screen, and the "Submit Signed Document" button permanently
disabled — no way forward. Reproduced live in a real headless-Chromium
browser (never the PHPUnit harness — Alpine reactivity has to actually run)
on a throwaway 3-document pack (EATS + MDF + Addendum B), 2 sellers + agent,
sequential signing order. Agent and seller 1 completed cleanly; seller 2
(the last recipient) measured `webIncompleteCount: -6`, `totalRequired: 46`,
`signedCount: 52` (the derived `total - incomplete`, not an independent
count), `incompleteItems: []` (empty — nothing left to show).

**Root cause.** `canSubmitWeb()` is `webConsented && webIncompleteCount === 0`
— a strict equality, so once the counter overshoots past zero it can never
recover. `webIncompleteCount` is built by `_computeWebCounts()`
(`external/sign.blade.php`, mirrored in `sign.blade.php` for the agent), one
section of which is disclosure:

```js
if (this._signerIsDisclosingParty() && this.totalDisclosureRows > 0) {
    total += this.totalDisclosureRows;
    const answered = Object.keys(this.webDisclosureAnswers)
        .filter(k => this._isDisclosureAnswerKey(k)).length;
    incomplete += (this.totalDisclosureRows - answered);
}
```

`totalDisclosureRows` (the denominator) is built PER DOCUMENT, correctly
scoped to only the rows required of THIS signer right now — gated by
`_disclosureEditable()`, which returns `false` once an earlier owner-party
recipient has locked the grid (`disclosureMarksLocked`). But `answered` (the
numerator) counted **every key in `webDisclosureAnswers`**, seeded wholesale
via `_seedDisclosureFromStore()` from the single, pack-wide,
document-unscoped `documents.web_template_data['disclosure_lock']['answers']`
blob. A pack merges N disclosure-bearing documents into that ONE flat
answer store; the denominator only ever reflected ONE document's rows, the
numerator counted all of them.

Confirmed on the real repro document: `disclosure_lock.answers` held 17
keys split across two `docKey` prefixes — 11 from the MDF's legacy
bare-table converter (`_processDisclosureTable`, which counts every
qualifying row unconditionally — no lock gate) and 6 from Addendum B's
`.corex-disclosure-checklist` converter (`processWebDisclosureChecklists`,
correctly excluded from `totalDisclosureRows` for this signer because it was
locked). `totalDisclosureRows` measured 11 (MDF only); `answered` measured
17 (both documents); `11 − 17 = −6`, matching the live counter exactly.
Every other `_computeWebCounts()` section (DB markers, inline signatures,
page initials, ceremony fields, consent, B3 editable fields, condition
initials) measured a clean 0 — the entire defect was isolated to this one
subtraction. This is the same bug class as the AT-391 entry above in spirit
(a pack-merge assumption that held for a single document breaking once a
second document enters the same flat, unscoped state) but a different
mechanism — trigger condition is specifically **any pack containing more
than one disclosure-bearing sub-document**, regardless of party shape.

**Fix**: scope the numerator to the denominator. `_gatedDisclosureRowKeys` (a
`Set`, contract state alongside `webDisclosureAnswers`/`totalDisclosureRows`/
`storedDisclosure` in `disclosure-logic.blade.php`'s shared header) is reset
every pass in `_processAllDisclosures()` and populated 1:1 with every
`totalDisclosureRows` increment — unconditionally in the bare-table
converter (matching its unconditional `totalRows++`), only when `editable`
in the checklist converter (matching its conditional `gatedIdx++`). Every
site that compares an "answered" count against `totalDisclosureRows` now
filters through it: `Object.keys(webDisclosureAnswers).filter(k =>
isDisclosureAnswerKey(k) && gatedKeys.has(k))`. `answered` can now never
exceed `totalDisclosureRows` (it counts a subset of the same set), so
`incomplete` can never go negative and `canSubmitWeb()`'s strict `=== 0`
check is left untouched — the arithmetic is correct, not loosened. Fixed in
both consuming views (all four call sites: `_computeIncompleteItems()`,
`_computeWebCounts()`, and `completeWebSigning()`'s pre-submit warning in
`external/sign.blade.php`; the equivalent `_computeWebCounts()`-analogue in
`sign.blade.php` for the agent) and in the shared
`processWebDisclosureChecklists()`/`_processDisclosureTable()` converters —
the SAME single source both views already pull from, so the fix does not
fork per view.

**Verified** on Staging (throwaway agency/pack/documents only — never
touching Johan's document 896, template 106, or any real data), in a real
headless-Chromium browser driving the actual rendered page and the actual
"Submit Signed Document" button — never a direct POST to `complete-web`:
- Pack with 2 disclosure-bearing documents, 3 recipients (agent, seller 1,
  seller 2, sequential order): all three recipients' `webIncompleteCount`
  landed on exactly 0, `canSubmitWeb: true`, the button enabled, submit
  succeeded, each reached "Thank You! You have successfully signed the
  document."
- Single document (no pack merge, one disclosure-bearing document), 2
  recipients: both completed exactly as before — no regression.

**Server-side note, explicitly NOT addressed by this fix, on Johan's
instruction**: `SigningController::completeWeb()`'s floor check (≥1
non-empty signature/initial anywhere in the request body, ≥1 non-empty
field value if the party has editable fields, `consented: true`) does not
verify the payload actually covers every genuinely-required marker. A direct
POST to `/sign/{token}/complete-web` with a minimal fabricated payload was
confirmed to return `200 {"ok":true,"completed":true,"fully_complete":true}`
against a fresh, genuinely-incomplete recipient session (button correctly
disabled, consent never given, 16 real items outstanding). This means every
automated/test pass that completes a recipient's turn via a direct request
rather than the real enabled button is not proof a human could have done the
same. Logged here so it isn't lost; a separate authorised task, not this
one.

**The general rule this establishes**: any per-signer "required" count built
from a pack-wide, document-unscoped store (here, `webDisclosureAnswers`
seeded from a flat `disclosure_lock.answers` blob) must track WHICH keys
were actually counted into the denominator this pass, and score the
numerator against that same set — never against every key the flat store
happens to hold. A future disclosure-like gate (any "N of M required,
M computed per-signer, sourced from a shared multi-document store") that
skips this scoping will reproduce this exact bug class under a different
name.

---

## Fix — agent lands on My E-Sign Documents after final approval (2026-08-31)

After the agent's final approval (`SignatureController::approveAndAdvance()`'s
completed/filed-and-emailed exit), the agent now returns to My E-Sign Documents
(`docuperfect.esign.myDocuments`) instead of the unrelated `/docuperfect/sales`
dashboard.

Same fix applied to `SignatureController::resumeDeferred()` (2026-08-31, Johan)
— submitting a deferred party's details now also returns to My E-Sign Documents
instead of `/docuperfect/sales`. Five other occurrences of this same wrong-redirect
pattern remain, deliberately unfixed until Johan reports each one.

---

## Rule (BUG3 class) — an indexed same-role party never matches by literal role string

`parties_json` names the Nth same-role party `{role}_N` (e.g. `seller_2`), but its
`SignatureRequest` stores the BASE role (`party_role = "seller"`) plus a separate
`role_index = N`. Any lookup that matches `party_role` against the literal
`parties_json` role string (`firstWhere('party_role', $role)`) never finds the
indexed party — they resolve as null/unknown and any control gated on finding
them (a deferred-party name, a resume-signing button) never draws. This is a
class, not a seller-specific or index-2-specific bug: it applies to any
seller_N, buyer_N, landlord_N, tenant_N, etc.

**The canonical resolution** (already correct in `SignatureTemplate::
partyProgress()`): parse the trailing `_N` off the parties_json role (bare =
index 1), then match `party_role === $baseRole && role_index === $N`, falling
back to a plain `firstWhere` only for safety. Any new code resolving a
parties_json party to its `SignatureRequest` MUST use this pattern — never a
bare `firstWhere('party_role', $role)`.

**Fixed by this same rule (2026-08-31):** `SignatureController::
propertyDocuments()` (per-party status on the property Documents page) used
the broken bare match; now uses the base-role+index resolution. Separately,
`resources/views/docuperfect/esign/my-documents.blade.php`'s per-request
status list had no rendering branch at all for `deferred` (or a resume
action) — a deferred party was invisible on the agent's own My E-Sign
Documents card with no way to supply their details. Added a `deferred`
branch showing the party's name and a "Resume Signing" control that posts to
the existing, unchanged `resumeDeferred()` (which resolves by `request_id`,
never by role string, so it was already sound).

**Not touched, flagged for a future authorised pass:** `SignaturePdfService.php:470`
and `SignatureController.php:2869` also do a bare `firstWhere('party_role', ...)`
against markers/agent lookups respectively — out of tonight's scope, not
verified as reachable for an indexed same-role party, reported not fixed.

---

## Async completion becomes an agency setting, and finalisation failure is never silent (2026-08-31, Johan authorised overnight)

Johan tested tonight's async-completion switch live and approved it ("for the
user its massively faster... can easily live with" a ~30s email delay on a
pack). Two follow-on instructions, in his words: *"the async turn on should be
in settings under esign. and the queue worker should trigger off it too. plus
failure notifications... we cannot have it fail silently."* Built overnight,
unsupervised — Johan is asleep and unavailable to confirm design choices, so
every assumption below is stated explicitly for his morning review, per his
own instruction to keep moving rather than stall on an unanswerable question.

### Priority order (deliberate, per Johan)

Stage 2 (failure must never be silent) matters more than Stage 1 (the
setting). If anything had to be cut, Stage 1 would be cut first. Both were
completed.

### Stage 1 — the setting

- New table `docuperfect_esign_settings` (one row per agency): `async_completion_enabled`
  (bool, default true), `finalization_stuck_threshold_minutes` (int, default 15).
- New model `App\Models\Docuperfect\EsignSettings::forAgency($agencyId)` — Rule 17
  guarded (`$agencyId <= 0` returns in-memory defaults, no DB row). Resolution order,
  exactly as instructed: an agency's own saved row wins; `config('docuperfect.async_completion')`
  (the pre-existing `DOCUPERFECT_ASYNC_COMPLETION` env flag) is consulted ONLY when no
  row has ever been saved for that agency — the env handling is not deleted.
- `SignatureService::completeDocument()` now resolves the dispatch decision through
  `EsignSettings::forAgency($template->agency_id)->asyncCompletionEnabled()` instead of
  reading `config('docuperfect.async_completion')` directly — turning the setting off
  for one agency makes that agency's finalisation run inline again, every other agency
  unaffected. This is the reading of "the queue worker should trigger off it too" this
  build implements — the dispatch decision, not the worker process itself; a worker is
  either running on the box or it isn't, and Stage 2's stuck-detection below is what
  catches "async is on but no worker is picking jobs up," which is a different failure
  mode than "should the switch use the queue."
- Settings screen: `docuperfect.esign.settings.finalization` (E-Sign → Finalisation
  Settings in the sidebar, same `permission:esign.settings` gate as every other e-sign
  settings screen), `EsignFinalizationSettingsController@edit/update`.
- **Assumption — DEFAULT ON.** Per Johan's explicit instruction ("He has tested it
  tonight and wants the speed"). New agencies, and any agency saving this screen for
  the first time with the box left checked, get `async_completion_enabled = true`.
- **Assumption — deliberately NOT added to the Agency Onboarding Setup Wizard tonight,
  flagged per CLAUDE.md #10a's "Deliberately NOT in the wizard" list, not silently
  skipped.** `config/agency-onboarding-copy.php`'s controls are read via a generic
  `source: 'agency'|'perf'` key-value mechanism (a direct Agency column or a
  PerformanceSetting key); wiring a third source type for a dedicated settings model
  means touching the wizard's generic step-rendering engine itself, not just adding a
  config entry — a change with a much larger blast radius (every onboarding step, every
  agency) that cannot be verified against a live wizard run with Johan asleep. This is
  exactly the "ask Johan" case CLAUDE.md #10a describes; asking isn't possible tonight,
  so it stays out and is recorded here rather than guessed at. Needs his explicit call
  in the morning: either wire the wizard's engine to support a model-backed control
  source properly, or accept this as a settings-page-only control (some settings are
  legitimately expert/rarely-touched, per #10a's own carve-out).

### Stage 2 — failure must never be silent (the priority)

**New state, deliberately separate from the signing status** (`signature_templates`
migration `2026_08_31_240001`): `finalization_status` (`running`|`succeeded`|`failed`,
null = never attempted — e.g. a document that hasn't been async-completed at all),
`finalization_error` (text), `finalization_attempts` (int), `finalization_started_at`,
`finalization_finished_at`. `SignatureTemplate::status` still reads `completed` — a
legally completed signing is never touched by this; finalisation is the separate
post-completion work (PDF, filing, contact linking, emails, lease extraction) and its
outcome is tracked independently.

**Detection, both paths (fix the class, not the instance):**
- `FinalizeSignedDocumentJob` (the async path) — `handle()` records `running` +
  increments `finalization_attempts` before the cascade, `succeeded` after; a
  `failed(Throwable $e)` handler (Laravel's own job-failure hook, called once retries
  are exhausted) records `failed` + the error and fires the notification below.
- The pre-existing SYNCHRONOUS inline cascade (`completeDocument()`'s `DB::afterCommit`
  closure, used when the setting is off) already had its own try/catch that only
  logged; it now records the SAME `running`/`succeeded`/`failed` states through one
  shared pair of methods (`SignatureService::recordFinalizationStarted/Succeeded/Failed()`)
  both paths call — one recorder, not two copies that could drift apart.
- **Stuck detection (the queue-with-no-worker scenario Johan named specifically):** a
  new scheduled command, `docuperfect:detect-stuck-finalizations`, finds any
  `SignatureTemplate` whose `finalization_status` is `running` (or null while
  `completed_at` is old — covers a job that never even started, e.g. dispatched to a
  queue nothing is consuming) for longer than that agency's own
  `finalization_stuck_threshold_minutes` (Stage 1 setting, default 15) and marks it
  `failed` with a clear "no worker picked this up in time" error, through the same
  recorder — so it surfaces identically to a genuine job failure. Scheduled
  `everyFiveMinutes()` in `routes/console.php`.
  **CONFIRMED gap, not fixed tonight, needs Johan's decision:** checked the box's
  actual crontab — `/corex-staging`'s `schedule:run` line is present but COMMENTED
  OUT (`#* * * * * cd /corex-staging && ... schedule:run`); only `/hfc-staging` (a
  different, older directory) and the demo host have an active line. **This means
  Laravel's scheduler does not currently run at all for /corex-staging**, so this
  stuck-detector — and every other already-existing scheduled command in this
  codebase (`signatures:send-reminders`, `signatures:expire`, etc.) — will not fire
  until that cron line is uncommented. Deliberately NOT changed tonight: uncommenting
  it activates every scheduled command for Staging at once, not just this one, which
  is a bigger decision than tonight's scope and needs Johan's or the conductor's eyes
  on the full list before flipping it live. Job-level failure detection (the `failed()`
  handler + inline-cascade recording, above) does NOT depend on this — it fires
  synchronously regardless of the scheduler — so only the specific "queue on, worker
  not running at all" scenario Johan named is blocked by this gap; a genuine job
  failure is still caught and surfaced tonight.
- Notification reuses the existing mechanism, not a new one: a new factory method,
  `SignatureActivityNotification::finalizationFailed(...)` (same class every other
  e-sign in-app notification already uses, database channel). Sent to the approving
  agent (`SignatureTemplate::creator`) and, separately, the agency's admin, resolved via
  the existing shared `User::resolveBranchManagerOrAdminFallback($agencyId)` (already
  used elsewhere for exactly this "who do we tell" question) — no new admin-lookup
  query invented.
- Visible on the one screen Johan actually watches: My E-Sign Documents. A document
  whose `finalization_status = 'failed'` gets a prominent red banner ("Finalisation
  failed — the signed PDF, filing or emails may be missing") on its card in the
  Completed section, with a **Retry Finalisation** button.
- **Recovery, idempotent:** `SignatureController::retryFinalization()` re-dispatches
  `FinalizeSignedDocumentJob` for the template. Every step inside the cascade already
  had its own idempotency guard before tonight (confirmed by direct testing on template
  466 earlier tonight) — PDF generation checks the file already exists, filing checks
  storage_path+source_type, and completion emails are gated by the atomic
  `completion_emails_sent_at` claim (`whereNull(...)->update(...)`), which this build
  does not touch or bypass. A retry after a genuine partial failure resumes only the
  steps that didn't complete; it cannot send a second copy of a signed document to
  anyone who already received one.

### Not touched tonight (explicitly out of scope, per the hard constraints)

The disclosure counter, the MDF completion guard, the four redirect fixes, the
deferred-party rendering, and the `set_time_limit(300)` backstop in
`approveAndAdvance()` — all tested and pushed earlier tonight, none of it touched by
this build.

---

### Bug — a pre-filled, property-linked field could permanently block Submit: the guard demanded a value no recipient can ever provide (fixed 2026-08-31, AT-410b)

Johan's report, reproduced live on the Mandatory Disclosure Form (MDF,
template 100): the Seller recipient reached the end of the document with
consent ticked, every marker signed, every disclosure row answered, the
progress counter at 0 outstanding, "Ready to submit" on screen, and the
"Submit Signed Document" button enabled. Clicking it returned "Please
complete the fields assigned to you before submitting." — an unsatisfiable
requirement, since nothing on the page was left for the recipient to fill.

**Business rule (Johan, authoritative — encode this, do not work around
it):** a document's property address is creation-time data, resolved from
the Property record the document was linked to when it was made. It is
**never editable by a recipient, by design** — a recipient changing "1 Steve
Street, Uvongo" to "10 Pete Street, Uvongo" in flight would detach the
document from the property it was created for and exposes the agency to an
accusation of altering paperwork after the fact. If the address is wrong,
it is corrected AT CREATION by re-linking the correct property — never by a
recipient editing the rendered document. The field correctly renders as
static, non-editable text. That render behaviour is correct and stays.

**Root cause.** `SigningController::completeWeb()`'s completion floor
(~line 1845) decided "this recipient still has fields to fill" purely from
`docuperfect_templates.field_mappings[tag].editable_by` — a config value
recording who is *permitted* to reference/see a field, not whether the
field is actually a recipient-fillable input on the rendered page. The MDF's
one field (`tag-mtgzu4ye-larlqr`, "Property Address") carries
`editable_by: ["agent", "owner_party"]` but `sourceType: "property"` — it is
sourced from the linked Property record, not typed by any signer. Because
the guard read `editable_by` alone, it demanded a non-empty
`field_values` entry for a field no recipient interface will ever submit a
value for. Unsatisfiable by construction — the correctly-static render (see
the business rule above) and the guard's requirement disagreed, and the
guard was the one asserting something false.

**Fix, server-side only** (`SigningController::completeWeb()`, ~line
1845-1855; nothing else touched — no render-path change, no
`RoleBlockExpansionService` change, no `sign.blade.php` change): before
checking whether the recipient has outstanding fields, the guard now
excludes any `field_mappings` entry whose `sourceType === 'property'` from
the "must supply a value" set. A field's presence in `editable_by` still
gates every other surface it always has; it just no longer, on its own,
manufactures a submission requirement for a field sourced from the
property record. Genuinely recipient-fillable fields (`sourceType` anything
else) are completely unaffected — a recipient with real fields to complete
is still correctly blocked until they fill them.

**The general rule this establishes**: a completion gate must never derive
"the recipient still owes a value here" from a permission list alone —
`editable_by` says who is allowed to reference a field, not who is expected
to type into it. Where a field's `sourceType` marks it as sourced from
another pillar record (here, Property) rather than signer input, that
field is definitionally not something a signing completion floor may
require a submitted value for, regardless of what `editable_by` lists.
A future completion gate that reads only the permission list and not the
field's data-source will reproduce this exact "unsatisfiable requirement"
bug class under a different name.
