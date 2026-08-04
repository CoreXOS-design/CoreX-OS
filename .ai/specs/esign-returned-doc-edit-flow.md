# Spec — Returned-Document Edit Flow (FLOW / EDIT-SURFACE / CONTROL half)

**Status:** DRAFT — awaiting Johan's approval. **No code written.** Spec only.
**Author:** ESIGN lane (cc6) · **Date:** 2026-08-04
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

### 4.1 T3 clause/body capture (the new bit — "full but fully captured")

Fill & Review gains a **clause-edit mode**: each clause/paragraph block in the body (anchored by clause
id / `data-block-id` / `data-role-block`, the same anchors cc1 aligns on) is made editable
(contenteditable or a per-clause edit modal). On save:
1. The edited body is written back to `web_template_data['merged_html']` (the single canonical source
   every surface composes from) — so the change is real document content, not an overlay.
2. **Fully captured** = for each changed block we ALSO persist a structured change record (block anchor,
   old text, new text, editor, timestamp) into `web_template_data['pending_body_changes'][]`. This is the
   audit capture Johan requires ("full per Johan but fully captured"): the edit is free-form, but the
   *record* of what changed is structured and durable.
3. **cc1 does NOT need `pending_body_changes` to render** — cc1 diffs current `merged_html` vs the
   last-authorised seal (their §3/§5B). `pending_body_changes` is the **cc6-side audit + thread feed**
   (§6) and a convenience index; the seal diff remains the authoritative visual. (Cited to cc1 §5B/§7.)

> **⚠️ OPEN QUESTION 2 (Johan):** T3 lock scope — may the agent edit **any** clause, or are certain
> legally-fixed clauses (voetstoots, FICA, mandate-core) **locked** from free edit (matching the P2
> `is_locked` concept)? This spec assumes **all editable** per your "FULL edits" direction; flag if
> specific clauses must be locked.

---

## 5. Control model — reset & re-route

### 5.1 Signature reset (who loses their signature on edit)
- **Editing invalidates prior consent.** The moment the agent saves an edit on a returned doc, the
  document content differs from what anyone signed. So on entering/committing the edit session:
  - **Creator (agent/candidate):** signature reset to re-signable — already done by the return loop
    (`returnToCandidate` sets the agent request → `pending`, clears `completed_at`). For recipient-side
    (amendment_review) the same reset applies to the agent.
  - **Authoriser:** reset to `waiting` (return loop already does this) — they re-review the edited doc.
  - **Recipients:** any recipient who had signed BEFORE the edit must re-consent. Per the wet-ink
    doctrine this is the **initialing cascade** (`requeueAllPartiesForInitialing` /
    `SectionAcceptance`) — they **initial only the changed regions** (cc1's marks show them exactly
    what), NOT a full re-sign, unless the change is material enough that Johan wants a full re-sign.
    (Cited to `ESIGN-WETINK` §9 Build 1 late-flag rule.)

> **⚠️ OPEN QUESTION 3 (Johan):** after an agent edit, do already-signed recipients **initial only the
> changed regions** (recommended, matches wet-ink) or **fully re-sign**? This spec assumes initial-only
> of the highlighted changes.

### 5.2 Re-routing (the loop)
```
returned doc → agent opens Fill & Review (edit) → edits T1/T2/T3 → re-signs
   ├─ candidate flow:   → awaiting_supervisor (resubmit, existing advanceToSupervisor)
   │                    → authoriser re-reviews (sees cc1 highlights) → authorise OR return again
   │                    → after authorise → recipients (fresh) → … → complete
   └─ recipient-side:   → parties who must re-consent initial the changed regions (initialing cascade)
                        → continue forward → complete
```
Re-routing **reuses the existing machinery** wholesale: `advanceToSupervisor` (candidate),
`advanceToNextParty` / initialing cascade (recipients). No new routing engine.

---

## 6. Tie-in to the notes thread + the cc1 render contract

- **Thread (existing, `df7afa82`):** `web_template_data['return_thread']` already records every send-back
  (with note) and resubmit hop. On a re-edit resubmit, the thread hop is **enriched with a change
  summary** derived from §4.1 `pending_body_changes` + the overlay field diff: e.g. *"resubmitted —
  changed: purchase price, clause 5.2, occupation date."* So the thread reads as a running,
  human-legible change log across rounds; the immutable `SignatureAuditLog` + the sealed-version chain
  are the legal record.
- **The cc1 contract (I SET, cc1 READS — cited to their §8):**
  1. On edit commit, cc6 sets **`web_template_data['amendment_render'] = true`** → cc1's gated
     `compose()` step 6 turns on the change-highlight overlay.
  2. cc6 guarantees the **current canonical/body is in `web_template_data`** (`merged_html` updated per
     §4.1; overlay per T1) BEFORE any surface composes — so cc1 diffs the correct "current" against the
     last-authorised seal.
  3. On **re-authorisation**, cc6 **clears `amendment_render`** and lets the authoriser seal
     (`EVENT_AUTHORISER_COSIGNED`) capture the new baseline → cc1's diff goes empty → marks clear
     automatically (their §6). (For a permanent "Amended" stamp, that is cc1's §9 policy toggle — Johan's
     call there, not here.)
  4. cc6 also clears `pending_body_changes` at re-authorisation (folded into the new baseline).

---

## 7. Status model (reuses existing constants — no new status)

| State | Set by | Editable? | Next |
|---|---|---|---|
| `returned_to_candidate` | authoriser send-back (`df7afa82`) | **YES — agent, via Fill & Review edit** | re-sign → `awaiting_supervisor` |
| `amendment_review` | recipient flag/strikeout (`:3791`) | **YES — agent, via Fill & Review edit** | edit → initialing cascade → continue |
| `awaiting_supervisor` | resubmit | no (authoriser reviews) | authorise / return |
| `amendment_initialing` | after agent edit approved | no (parties initial changes) | complete |

`amendment_render` (web_template_data flag) is orthogonal to status — it is ON from the first edit until
re-authorisation, driving cc1's marks across whatever states the doc passes through in between.

---

## 8. Disjoint boundary with cc1 (cited both ways)

**cc6 (this spec) provides / owns:**
- the relaunched Fill & Review **edit surface** (T1/T2/T3), seeded from the returned Document;
- **T3 clause/body capture** → writes `merged_html` + `pending_body_changes`;
- the **control model**: signature reset + re-route (reuse return loop + initialing cascade);
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

## 9. Open questions for Johan (mine)
1. **§3** — in-place edit seeded from the Document (assumed) vs a durable `documents.source_flow_id` Flow link?
2. **§4.1** — T3: any clause editable (assumed), or lock legally-fixed clauses (voetstoots/FICA/mandate-core)?
3. **§5.1** — already-signed recipients after an edit: **initial-only the changed regions** (assumed) or full re-sign?
4. Recipient-side scope now, or candidate-flow first then recipient-side as a fast-follow? (Same surface either way.)

*(cc1's open questions — clear-vs-stay on final issue, redline granularity, deleted-text display, which
seal is the baseline — are in their spec §9/§12 and are theirs to field.)*

---

## 10. Build order (on approval — NOT now)
1. Edit-session relaunch of Fill & Review seeded from a returned Document (T1 first — field values).
2. T3 clause/body edit mode + capture (`merged_html` + `pending_body_changes`); set `amendment_render`.
3. Control model: reset/route already exists for candidate; extend to recipient-side (initialing cascade).
4. Thread change-summary; re-authorisation clear + re-seal.
5. Coordinate with cc1's highlighter (their build) — the two meet only at the `amendment_render` flag.

Regression-walk stays the moat. QA1 only.
