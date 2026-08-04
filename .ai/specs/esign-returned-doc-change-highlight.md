# Spec — Returned-Document Change-Highlight Render (RENDER / VERSIONING half)

**Status:** DRAFT — awaiting Johan's approval. **No code written.** Spec only.
**Author:** Claude (ESIGN render/canonical lane) · **Date:** 2026-08-04
**Owns:** the RENDER / change-highlight / versioning half. **cc6 owns:** the FLOW / edit-surface /
control / status half (the return→re-edit→resubmit→re-authorise loop, `SignatureService::returnToCandidate`
+ resubmit, the return loop `df7afa82`). This spec cites cc6's half at every boundary; the two are disjoint.

---

## 1. What this feature is

When an agent edits a **returned** document — both **field values** (e.g. a phone, a commission %) AND
**actual clause/body text** — the rendered document must **mark every change so it JUMPS OUT**: a
highlighted / boxed / redline callout that no viewer (authoriser, recipients) can miss. "What changed
since this was last authorised" must be unmissable before anyone re-approves or re-signs.

**My half:** given a *baseline* (the last-authorised document) and the *current* (freshly re-edited)
canonical, compute the diff and render the change-marks inside `compose()`. **cc6's half:** the edit
surface that produces the new field values + body text, and the status machinery that says "this doc has
been re-edited and is pending re-authorisation."

---

## 2. The two change classes to highlight

| Class | What changed | Where it lives | Marking |
|---|---|---|---|
| **A — Field values** | a `data-field` value the agent re-typed (base `{var}` or per-recipient `{var}__r{n}`) | `data-field` spans in the canonical HTML | inline **boxed highlight** on the new value + a "was: `<old>`" hover/footnote |
| **B — Clause / body text** | free-text edits to printed clause / body paragraphs (words added, removed, reworded, whole clause inserted/deleted) | text nodes inside the document body / clause blocks | **redline**: inserted text highlighted/underlined, deleted text struck-through in a margin callout; a whole new/removed clause gets a boxed **"Amended"/"New"/"Removed"** ribbon |

Both classes are marked by the **same** render pass, against the **same** baseline, so the callout style
is one visual language.

---

## 3. Versioning — what we diff against (NO new storage needed)

The baseline already exists. `DocumentSealService` seals an immutable, hash-chained snapshot of the baked
`canonical_html` at every hop into `document_sealed_versions` (`sealed_html` longText, `event_type`,
`version`, `content_hash`/`prev_hash`). Relevant seal points already defined:
`EVENT_AUTHORISER_COSIGNED`, `EVENT_CANDIDATE_FINAL_APPROVED`, `EVENT_RECIPIENT_SIGNED`, `EVENT_COMPLETED`.

- **Baseline = "last-authorised version"** = the most-recent sealed row for this document whose
  `event_type ∈ {authoriser_cosigned, candidate_final_approved}` (whichever authorisation gate applies).
  Its `sealed_html` is the authoritative "what the authoriser last approved."
- **Current = "newly-edited canonical"** = the fresh `compose()` output for the document after the agent's
  returned-doc edit (with `_fill_review_overlay` applied — §7).
- **Diff = current vs baseline.** If there is no prior-authorised seal (first-ever authorisation, never
  returned), there is nothing to diff → no marks (normal render).

This means the render half needs **no new table** — it reads the existing seal. The only new persisted bit
is a small **signal flag** (cc6 sets it — §8): `web_template_data['amendment_render'] = true` while the doc
is "re-edited, pending re-authorisation," plus optionally `web_template_data['last_authorised_seal_id']`
(a convenience pointer; otherwise the render half queries the latest qualifying seal).

---

## 4. Where in `compose()` this applies

`CanonicalDocumentRenderer::compose()` is the ONE choke point every surface funnels through (recipient
ceremony, authoriser review, agent sign, PDF, send-time bake). The pass is a **new final step**, added
AFTER the existing pipeline:

```
1 normalize → 2 letterhead → 3 insertable blocks → 4 expandWithLooping
5 applyFillReviewAuthoritativeOverlay   (existing — AT-360c, my work)
6 applyReturnedDocChangeHighlight       (NEW — this spec)   ← only when amendment_render && a prior-authorised seal exists
```

Step 6 is **gated**: it runs only when `web_template_data['amendment_render']` is set (cc6) AND a
prior-authorised seal exists. Otherwise `compose()` is byte-for-byte unchanged (zero risk to the normal
path). It is a **display overlay**, exactly like steps 5 and the viewer-editability stamp — it is NOT
baked into `sealed_html` (see §6).

New service: **`DocumentChangeHighlighter`** — `highlight(string $currentHtml, string $baselineHtml): string`.
`compose()` calls it in step 6; keeping it a separate service keeps `compose()` thin and the diff logic
unit-testable in isolation.

---

## 5. How the diff is computed + rendered

### 5A — Field values (data-field diff)
Both current and baseline are expanded canonicals carrying `data-field="{var}"` / `data-field="{var}__r{n}"`
spans (the same keying `applyFillReviewAuthoritativeOverlay` already matches). Algorithm:
1. Index the **baseline**'s `data-field` → text.
2. Walk the **current**'s `data-field` spans; for each, if `current.text !== baseline.text` for the same
   key → wrap the current value in a change-mark span: boxed highlight + `data-changed-from="<old>"` (for a
   "was: …" tooltip / print footnote).
3. Per-recipient correctness is automatic because the match is by the exact `__r{n}` key (Seller 2's edit
   marks Seller 2's cell only). This is the same matching my overlay already proves out.

### 5B — Clause / body text diff
Body text is structured (clause blocks, `data-block` / clause ids, paragraphs). Approach — **anchor then
word-diff**, to keep it robust and library-free:
1. **Align blocks** by stable anchors (clause id / `data-role-block` / `data-block-id` / heading text), so
   we only diff *within* corresponding blocks — never a whole-document character diff (which would be noisy
   and mis-align on re-flow).
2. Within an aligned block whose text changed, run a **word-level LCS diff** (longest-common-subsequence)
   between baseline words and current words. Wrap runs:
   - inserted words → `<mark class="change-ins">…</mark>` (highlighted/underlined),
   - deleted words → `<del class="change-del">…</del>` (struck-through, kept visible inline or in a margin
     callout so the reader sees what was removed).
3. **Whole-block add/remove** (a clause present in one side only) → box the block with a ribbon:
   **"New clause"** / **"Removed"** / **"Amended"**.
4. Skip fields already marked by 5A (a `data-field` span inside a clause is a value change, not body text —
   mark it once, as a field).

**Reuse the existing visual vocabulary** so the document reads consistently: `InsertableBlockRenderer`
already renders amendment pills (`background:#fef3c7; color:#92400e` "Amendment pending agent review"),
strikethroughs (`applyStrikethroughs`), and an `agent_review` context described as "preparation + diff
highlights." The change-highlight callout should adopt the same amber/redline palette and the same
`data-*` hooks, so §7.5 amendment marks and these returned-doc marks look like one system.

### 5C — Print/PDF
The marks are plain HTML/CSS (mark/del/box), so they carry into the dompdf/Chromium PDF render unchanged
(no `object-fit`-style engine gotchas). A one-line print stylesheet ensures the highlight background prints
(`-webkit-print-color-adjust: exact` where the PDF engine honours it).

---

## 6. How the highlight persists through authoriser review, then clears

Because step 6 is a **render-time display overlay** (not baked), EVERY surface that renders through
`compose()` / `forDisplay()` / `resolveOrCompose()` shows the marks for as long as:
`amendment_render == true` AND a prior-authorised seal exists to diff against.

- **Authoriser review:** the authoriser opens the doc → `forDisplay()` → `compose()` → step 6 runs → they
  see every change vs what they last authorised. (Recipients who view before re-signing see the same.)
- **On re-authorisation:** cc6's flow re-runs the authorisation gate. At that moment a **NEW seal** is taken
  (`EVENT_AUTHORISER_COSIGNED`) capturing the current canonical as the new baseline, and cc6 **clears**
  `amendment_render`. The next `compose()` diff is therefore empty → **marks clear automatically**. No extra
  work: "the new authorised version becomes the baseline" is the natural clear.
- Because seals are hash-chained and immutable, the full before/after history is preserved for audit even
  after the marks clear.

---

## 7. Interplay with `_fill_review_overlay` (my existing AT-360c work)

- `_fill_review_overlay` is applied in **step 5** and is the authoritative source of the **current** field
  values — so step 6 diffs the already-overlaid (correct) current values against the baseline. Order matters:
  highlight AFTER overlay, never before.
- The overlay map is also a useful **hint** of "which fields the agent touched this round," but the
  **authoritative** field-change signal is `current.text !== baseline.text` (§5A), because: (a) an agent can
  edit a value back and forth, (b) the overlay may carry an unchanged value, (c) body-text edits aren't in
  the overlay at all. So: overlay = current values; **seal = what to compare against**; mark where they differ.
- No conflict with the pack/base keying — the highlighter matches the exact `data-field` attribute, the same
  contract the overlay writes.

## 7.5 Interplay with the EXISTING amendment / strikethrough machinery (§7.5 of esign-v3 spec)

The signing-time **Other-Conditions strikethrough / amendment** flow (`DocumentClauseStrikethrough`,
`condition_initials`, `InsertableBlockRenderer` amendment badges) is a DIFFERENT mechanism: a recipient
proposes a clause override *during signing*, routed through agent review. The returned-doc change-highlight
is about an **agent re-editing a returned doc** *before re-authorisation*. They must not double-mark: where a
change is already represented as a `DocumentClauseStrikethrough` / amendment condition, the highlighter
**defers** to that existing render (skip elements carrying `data-strikethrough-applied` / `data-amendment-id`)
and only marks changes NOT already covered. One visual language, no double callouts.

---

## 8. The disjoint boundary with cc6 (cited both ways)

**cc6 (flow/edit/status) provides:**
- the edit surface that writes the new field values (into `fill_review` / `_fill_review_overlay`) and the new
  clause/body text (into `merged_html` / the canonical source) on a returned doc;
- the status machinery (`returned_to_candidate` → re-edit → resubmit → re-authorise) — `SignatureService::returnToCandidate`
  + resubmit (`df7afa82`);
- **sets** `web_template_data['amendment_render'] = true` when a returned doc is re-edited, and **clears** it
  (and takes the new authoriser seal) on re-authorisation.

**I (render/versioning) provide:**
- `DocumentChangeHighlighter` (diff + mark) and the gated step 6 in `compose()`;
- reading the last-authorised seal as the baseline; the field + body diff; the display-overlay persistence
  and auto-clear semantics.

Neither edits the other's files: cc6 in the flow/controller/status + edit blades; me in `CanonicalDocumentRenderer`
+ the new highlighter service. The **contract** between us is exactly two things: the `amendment_render` flag
and "the current canonical / new body text is in `web_template_data` when compose() runs."

---

## 9. Clears vs stays on final issue — **JOHAN'S CHOICE** (flagged, not decided)

- **Option A — marks CLEAR on final issue.** On re-authorisation the new baseline makes the diff empty; the
  final issued/served document is clean. Audit trail lives only in the sealed-version history. *(Cleanest
  output; change record is "behind the scenes.")*
- **Option B — a permanent "AMENDED" mark STAYS.** The final document keeps a visible "Amended after initial
  authorisation on `<date>`" stamp (and/or an appended **change-log page** listing each field/clause change),
  so every future reader sees it was amended. *(Maximum transparency; slightly busier document.)*
- **Recommended hybrid (my suggestion, Johan decides):** inline redlines **clear** on final issue (clean body),
  but a small permanent **"Amended — see change log"** note + an **appended change-history page** (generated
  from the seal chain) **stays**. Best of both: clean contract face + a durable, unmissable audit record.

This is a **rendering policy toggle** on my side once Johan picks — the mechanics (seal diff) are identical;
only whether step 6 (or a final-issue variant) still emits marks/appendix after re-authorisation changes.

---

## 10. File touch-list (for the future BUILD, on approval)

**New**
- `app/Services/Docuperfect/DocumentChangeHighlighter.php` — `highlight($currentHtml, $baselineHtml): string`
  (field diff §5A + body diff §5B; reuses the RoleBlockDetectionService fragment load/serialize like the
  overlay pass; skips §7.5-covered elements).
- `resources/css` (or an inline `<style>` in the canonical head) — `.change-ins/.change-del/.change-box`
  callout styles + print-color rule.

**Changed (mine)**
- `app/Services/Docuperfect/CanonicalDocumentRenderer.php` — gated step 6 call in `compose()`; a helper to
  resolve the last-authorised seal (`document_sealed_versions` latest `authoriser_cosigned`/`candidate_final_approved`).
- *(optional)* `app/Services/Docuperfect/DocumentSealService.php` — a tiny `lastAuthorisedSeal(Document): ?DocumentSealedVersion`
  accessor (or the highlighter queries directly).

**Contract only (cc6 owns the writes — do NOT edit here)**
- `web_template_data['amendment_render']` flag set/cleared by cc6's flow; re-seal on re-authorisation by cc6.

**No migration** — the baseline lives in the existing `document_sealed_versions`.

## 11. Acceptance criteria (render half)

1. With `amendment_render` set and a prior-authorised seal present, a returned doc whose agent changed a
   field value renders that value with a boxed highlight + "was: `<old>`"; per-recipient edits mark only the
   right recipient's instance.
2. A changed clause/body paragraph renders inline redline (ins highlighted, del struck); a whole new/removed
   clause gets a ribbon. Marks carry into the PDF.
3. The authoriser (and any recipient viewing pre-re-sign) sees identical marks — they are a compose()-time
   overlay on every surface.
4. On re-authorisation (new seal + flag cleared by cc6), marks clear automatically (Option A) or the chosen
   permanent stamp/appendix remains (Option B) — per Johan's §9 choice.
5. Normal (never-returned) documents render byte-identically to today — step 6 is fully gated.
6. No double-marking against §7.5 strikethrough/amendment renders.

## 12. Open questions for Johan

1. **§9 — clear vs stay** on final issue (Option A / B / recommended hybrid)?
2. Redline granularity for body text — **word-level** (recommended) or whole-paragraph box?
3. Show **deleted** text inline (struck) or only in a margin/footnote callout?
4. Which seal event is the authorisation baseline — `authoriser_cosigned`, `candidate_final_approved`, or
   both (whichever is latest)? (I'll default to "latest of either" unless told otherwise.)
