# Spec — Returned-Document Edit Flow (FLOW / EDIT-SURFACE / CONTROL half)

**Status:** LOCKED (Johan 2026-08-04, wet-ink model) — BUILDING on QA1.
**Author:** ESIGN lane (cc6) · **Date:** 2026-08-04

> **⚡ WET-INK MODEL (Johan's LOCKED decisions, supersede the earlier reset model):**
> 1. **Edit ALL** — field values, other-conditions, AND full clause/body text. No clause lock, no gate.
> 2. **Signed stays signed.** Prior signatures/seals REMAIN valid — never reset/invalidated, never a
>    whole-document re-sign. The ONLY new signing is **of the CHANGES**: the affected party initials/signs
>    THAT change, exactly like wet ink.
> 3. **Change-authoring = wet-ink markup that STAYS VISIBLE on the final contract.** *Small change:* strike
>    the old clause text and write the new inline. *Big change:* strike the clause and insert a REFERENCE to
>    an Other Conditions entry holding the full replacement. Both supported; strike-outs + write-ins are
>    never cleared.
> 4. **Recipient-side returns = fast-follow** after the candidate/authoriser path.
**Owns:** the FLOW / edit-surface / control / status half — the return → re-edit → resubmit →
re-authorise loop and what is editable by whom.
**cc1 owns:** the RENDER / change-highlight / versioning half — see
[`esign-returned-doc-change-highlight.md`](esign-returned-doc-change-highlight.md). This spec cites cc1's
half at every boundary; the two are disjoint. Builds on the candidate return loop
[`esign-candidate-authoriser-return-loop.md`](esign-candidate-authoriser-return-loop.md) (`df7afa82`,
LIVE) and relates to the P2 strike-out primitive
[`esign-wetink-p2-strikeout-insert-primitive.md`](esign-wetink-p2-strikeout-insert-primitive.md).

---

## 1. Problem

Today a **returned** document lets the agent only **RE-SIGN** — they cannot **edit** anything. The
return loop (`df7afa82`) unlocks the creator's signature and re-routes, but the sign screen has no edit
capability beyond the agent-editable field inputs. If the authoriser's note is "fix the purchase price"
or "reword clause 5.2", the agent has no surface to make that change. Same gap for **any** returned doc,
including **recipient-side** returns (a recipient flags a clause → the agent must change it, not just
re-sign).

**Johan's direction:** relaunch the **Fill & Review** screen as the edit surface; allow **FULL** edits
**including actual CLAUSE/body text**; every change must render so it **JUMPS OUT** (cc1's half).

---

## 2. Scope — this applies to EVERY returned document, one surface

Two return origins, ONE edit surface + control model:

| Origin | Enters | Who returned it | Today | Re-route target after edit |
|---|---|---|---|---|
| **Candidate flow** | `returned_to_candidate` | authoriser (senior) → candidate (junior) | re-sign only (`df7afa82`) | creator → **authoriser** → recipients |
| **Recipient-side** | `amendment_review` (flag/strikeout raised, `SignatureService:3791`) | a recipient (or CO) → the agent | agent reviews/resolves (`AmendmentController`), limited | creator → parties who must re-sign/initial the change |

Both funnel into the **same relaunched Fill & Review edit surface** and the same
"reset → re-edit → re-route" control model below. The status token differs (`returned_to_candidate`
vs `amendment_review`) but the edit mechanics are identical.

---

## 3. The edit surface — relaunch Fill & Review, seeded from the RETURNED doc

**Decision (recommended): edit a sent doc by opening Fill & Review in an EDIT session seeded from the
document's CURRENT state — do NOT try to revive the original wizard Flow.**

Why not the original Flow: the originating `Flow` persists after send (`status='completed'`, keeps
`step_data['fill_review']['fieldValues']`) but carries **no `document_id`** — there is no reliable link
back from a sent `Document` to its Flow, and the doc's live values may have moved past the Flow's since
send (per-recipient overlays, AT-360). The document is the source of truth, not the stale Flow.

**Mechanism:** a returned doc's "Fix & Re-sign" CTA (already added in `my-documents.blade` for the
candidate; add the equivalent on the amendment-review surface for recipient-side) opens an **edit
session**:
- **Seed** a fresh edit Flow (or an in-place edit mode) from the Document: `fieldValues` from
  `fields_json` / `web_template_data['_fill_review_overlay']`; parties from `parties_json`; the current
  body from `web_template_data['merged_html']`.
- **Render the Fill & Review UI** (`wizard.blade` fill_review step) against that seed — same screen the
  agent built it on, now in edit mode over live values.
- On save, values flow through the **existing** `fill_review` autosave (`ESignWizardController` step 5,
  `saveFillReviewFields`) into the overlay, and clause/body edits (§4) into `merged_html`.

> **⚠️ OPEN QUESTION 1 (Johan):** in-place edit mode on the sent Document, vs materialising a real edit
> Flow linked by a new `documents.source_flow_id` (a tiny addition that also fixes the missing back-link
> for future features)? This spec assumes **in-place edit seeded from the Document** (no migration);
> flag if you want the durable Flow link.

---

## 4. What is editable — three tiers, all fully captured

| Tier | What | Editable by | Storage (the source cc1's `compose()` reads) | Capture |
|---|---|---|---|---|
| **T1 — Field values** | any `data-field` value (price, %, dates, per-recipient `{var}__r{n}`) | agent (always) | `fields_json` + `_fill_review_overlay` (existing AT-360 overlay) | overlay is the new value; cc1 diffs vs the last-authorised seal (§5A of cc1) |
| **T2 — Other-Conditions block** | add / edit / remove conditions | agent | `document_conditions` + `other_conditions_text` (existing §7.5) | already row-per-condition + `condition_initials`; ties to P2 |
| **T3 — Main clause / body text** | **FULL** free-text edits to any printed clause / paragraph — reword, add, delete, insert a whole clause | agent | `web_template_data['merged_html']` (the canonical body source) | **NEW capture (§4.1)** |

**T1 + T2 already have storage + capture.** T3 is the new capability Johan asked for.

### 4.1 T3 clause/body change — the WET-INK STRIKE-OUT primitive (Johan's change-authoring model)

Clause/body change is **NOT** a silent contenteditable rewrite. It is authored as a visible **wet-ink
strike-out** that STAYS on the final contract (Johan #3). This is exactly the P2 primitive
([`esign-wetink-p2-strikeout-insert-primitive.md`](esign-wetink-p2-strikeout-insert-primitive.md)),
now the clause-change mechanism for returned docs. **No clause is locked** — any clause is strikeable
(Johan #1).

The agent picks a clause on the edit surface and chooses one of two paths:

- **SMALL change — strike + inline replacement.** The old clause text renders **struck through** (kept
  visible) with the new text written **inline** immediately after. One `DocumentClauseStrikethrough`
  (`clause_ref`, `clause_original_text` snapshot, `replacement_text` inline).
- **BIG change — strike + Other-Conditions reference.** The old clause renders struck through with an
  inline annotation **"See Other Conditions #N"**, and the FULL replacement is written as a numbered
  entry in the existing **Other Conditions** block. This reuses `storeStrikethrough`'s existing pairing
  (`ConditionsController` auto-creates the paired `DocumentCondition` with `is_override=true`,
  `overrides_clause_ref`) and `InsertableBlockRenderer::applyStrikethroughs` (renders the line-through +
  `[See Other Conditions #N]`).

**Both paths already have ~70%-built machinery** (`DocumentClauseStrikethrough`, the paired
`DocumentCondition`, `applyStrikethroughs`, `condition_initials`) — see the P2 spec §3. The strike +
write-in **persist and stay visible on every surface including the PDF** (`applyStrikethroughs` runs in
`compose()` for all contexts) — the wet-ink marked-up contract Johan wants, never cleared.

**Storage / capture:** `DocumentClauseStrikethrough` (the struck clause + replacement/OC-ref) is the
durable structured record — who struck what, old text snapshot, replacement, timestamp, status. Field
values (T1) and OC entries (T2) keep their existing stores. `web_template_data['merged_html']` carries
the struck markup so `compose()` renders it everywhere.

---

## 5. Control model — SIGNED STAYS SIGNED, initial-on-change (wet-ink)

### 5.1 Prior signatures are PRESERVED — the ONLY new signing is of the changes
Johan #2, wet-ink: **what is already signed STAYS signed.** Editing does **NOT** invalidate prior
consent and there is **NO whole-document re-sign**.

- **No signature reset.** The prior `returnToCandidate` reset (agent request → `pending`, clear
  `completed_at`) is **REMOVED** — the creator's (and every party's) existing signatures + P1 seals
  remain valid and stay on the document. *(This corrects the `df7afa82` return loop, which reset the
  junior — see §11 Build.)*
- **Each change carries its own initial.** When the agent authors a change (a struck clause, an edited
  field value, a new OC entry), that specific change requires an **initial/sign of THAT change** from
  the affected party — the same `ConditionInitial` (append-only, per-change) mechanism the OC/strike
  flow already uses (`renderInitialSlotsForCondition`, `initialCondition`). The agent initials the
  changes they author; any other party whose consent the change touches initials that change too.
- **Already-signed recipients** who must consent to a later change **initial only that change** — the
  existing initialing cascade (`requeueAllPartiesForInitialing` / `SectionAcceptance` /
  `checkInitialingCascadeComplete`). They keep their original signature; they add one initial per change.
  (Cited to `ESIGN-WETINK` §9 Build 1.)

**No legal/audit conflict** (checked, as Johan asked): marginal initial-on-amendment with preserved
signatures is standard wet-ink practice; P1's hash-chained seals immutably preserve every prior signed
version, and each change adds a NEW seal + a NEW initial — the chain records exactly who signed what and
who initialed each subsequent change. Nothing is overwritten.

### 5.2 Re-routing (the loop) — no re-sign, explicit resubmit
```
returned doc → agent opens edit surface → authors changes (T1/T2/T3), initialling each →
               clicks RESUBMIT (explicit — NOT a re-sign of the doc)
   ├─ candidate flow:  → awaiting_supervisor (resubmit) → authoriser reviews the MARKED-UP doc
   │                     (strike-outs + write-ins + change-initials visible) → INITIALS each change to
   │                     authorise it (or returns again) → after authorising, forward to any recipients
   │                     whose consent a change touches → they INITIAL that change → complete
   └─ recipient-side:  → parties whose consent a change touches initial that change (initialing cascade)
                         → continue forward → complete
```
Because there is no re-sign, **RESUBMIT is an explicit action** (button), not the side-effect of a
signature completion. Re-routing otherwise **reuses existing machinery**: `advanceToSupervisor`
(candidate), the initialing cascade (recipients). No new routing engine.

---

## 6. Tie-in to the notes thread + the cc1 render contract

- **Thread (existing, `df7afa82`):** `web_template_data['return_thread']` already records every send-back
  (with note) and resubmit hop. On a re-edit resubmit, the thread hop is **enriched with a change
  summary** derived from the authored changes (struck clauses + edited fields + new OC entries): e.g.
  *"resubmitted — struck clause 5.2 (→ OC #3), changed purchase price, occupation date."* Running,
  human-legible change log; the immutable `SignatureAuditLog` + the sealed-version chain are the legal
  record.
- **Two DISTINCT change-visibility mechanisms — reconciled with cc1 (Johan #3):**
  - **Clause/body changes → cc6's WET-INK STRIKE-OUTS** (`applyStrikethroughs`). These are **real
    document content** in `merged_html`, rendered by `compose()` on every surface **and they STAY on
    the final contract — never cleared** (the wet-ink marked-up document Johan wants). They do NOT
    depend on `amendment_render` and do NOT clear on re-authorisation.
  - **Field-value changes (T1) → cc1's diff-highlight** (their gated `compose()` step 6). These have no
    strike-out glyph, so cc1's "boxed highlight + was: `<old>`" is how a changed field JUMPS OUT. This
    is the part gated by `amendment_render`, and cc1's §9 policy decides whether it clears or leaves a
    stamp on final issue.
- **The cc1 contract (I SET, cc1 READS — cited to their §8):**
  1. On a field-value edit, cc6 sets **`web_template_data['amendment_render'] = true`** → cc1's step 6
     highlights the changed fields vs the last-authorised seal. (Clause strike-outs need no flag — they
     render as document content regardless.)
  2. cc6 guarantees the **current canonical/body is in `web_template_data`** (`merged_html` carries the
     struck markup; the T1 overlay carries new field values) BEFORE any surface composes.
  3. On **re-authorisation**, cc6 clears **`amendment_render`** so cc1's *field* highlight resolves per
     their §9 policy. **The strike-outs remain** (they are content, not a diff overlay) — the final
     contract stays wet-ink marked up. A new authoriser seal captures this marked-up state as the
     baseline, so the NEXT round's field-diff is clean while the accumulated strikes persist.

---

## 7. Status model (reuses existing constants — no new status)

| State | Set by | Editable? | Next |
|---|---|---|---|
| `returned_to_candidate` | authoriser send-back (`df7afa82`) | **YES — agent edits + initials changes; signatures stay** | **explicit RESUBMIT** → `awaiting_supervisor` |
| `amendment_review` | recipient flag/strikeout (`:3791`) | **YES — agent edits + initials changes; signatures stay** | initialing cascade → continue |
| `awaiting_supervisor` | resubmit | no (authoriser reviews marked-up doc, initials each change) | authorise / return |
| `amendment_initialing` | after authoriser authorises a change | no (affected parties initial that change) | complete |

Prior signatures + P1 seals are preserved across ALL these states (§5.1). `amendment_render` (field-diff
flag) is ON from the first field edit until re-authorisation (§6); clause strike-outs need no flag.

---

## 8. Disjoint boundary with cc1 (cited both ways)

**cc6 (this spec) provides / owns:**
- the relaunched Fill & Review **edit surface** (T1/T2/T3), seeded from the returned Document;
- **T3 clause/body change** = the wet-ink strike-out primitive → `DocumentClauseStrikethrough` + struck
  markup in `merged_html` (small=inline, big=OC reference); strikes STAY visible;
- the **control model**: SIGNED-STAYS-SIGNED + initial-on-change (remove the `df7afa82` reset; explicit
  resubmit; reuse the initialing cascade for change-initials);
- the **thread** change-summary enrichment;
- **sets/clears `amendment_render`** and guarantees current canonical/body in `web_template_data`;
- re-seal trigger on re-authorisation (invokes the existing seal at the authoriser gate).
- Files: `ESignWizardController` (edit-session + fill_review + T3 save), `SignatureService`
  (reset/route/flag/thread), the edit CTA on `my-documents.blade` + amendment-review surface.

**cc1 (their spec) provides / owns:**
- `DocumentChangeHighlighter` + gated `compose()` step 6; last-authorised seal baseline; field+body diff
  + marks; display-overlay persistence + auto-clear.
- Files: `CanonicalDocumentRenderer`, the new highlighter service, callout CSS.

**Contract = exactly two things:** the `amendment_render` flag, and "current canonical/body is in
`web_template_data` when compose() runs." Neither lane edits the other's files.

---

## 9. Decisions LOCKED by Johan + remaining open question
- **Edit scope (was OQ2):** LOCKED — **all editable, no clause lock.**
- **Signed stays signed / initial-on-change (was OQ3):** LOCKED — **prior signatures preserved; only the
  changes are initialed** (no full re-sign).
- **Change-authoring:** LOCKED — small = strike + inline; big = strike + OC reference; strikes STAY visible.
- **Recipient-side (was OQ4):** LOCKED — **fast-follow** after candidate/authoriser.
- **Remaining OQ1 (§3):** in-place edit seeded from the Document (assumed for the build) vs a durable
  `documents.source_flow_id` Flow link. Building with the in-place seed; will flag if a durable link
  proves necessary.

*(cc1's open questions live in their spec §9/§12.)*

---

## 10. Build order (LOCKED — building on QA1)
1. **Signed-stays-signed (§5.1)** — REMOVE the `returnToCandidate` signature reset; add an **explicit
   resubmit** action (replaces the re-sign-triggered resubmit). *(Corrects `df7afa82`.)* ← foundational.
2. **Returned-doc edit surface** — the agent opens the returned doc and edits field values + Other
   Conditions (existing edit mechanisms), each change initialed; signatures intact.
3. **Wet-ink clause strike-out primitive (§4.1)** — agent strikes a clause (small=inline / big=OC ref),
   reusing `DocumentClauseStrikethrough` + `applyStrikethroughs` + `condition_initials`; strikes stay
   visible incl. PDF.
4. **Change-initial routing** — authoriser initials each change; affected recipients initial via the
   cascade; thread change-summary; `amendment_render` for field diffs (cc1 contract).
5. **Recipient-side (fast-follow)** — same surface for `amendment_review`.

Regression-walk stays the moat. QA1 only, don't promote.
