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

- **Fix:** `CanonicalDocumentRenderer::renderCapturedInitials($template)` returns a labelled block
  of every captured initial image, attributed to the signer (name via `partyProgress()`'s canonical
  keys). `review()` and `SignaturePdfService` APPEND it to the display HTML — read-only; the stored
  canonical is never mutated. Captured initials always show.
