# Spec — Returned-Document Change-Highlight Render (RENDER / VERSIONING half)

**Status:** APPROVED (Johan's decisions LOCKED 2026-08-04 — WET-INK model). **Building on QA1.**
**Author:** Claude (ESIGN render/canonical lane) · **Date:** 2026-08-04

> **LOCKED DECISIONS (Johan, wet-ink):**
> 1. **Wet-ink — what is signed stays signed.** NO wholesale re-sign. Prior seals REMAIN. The diff
>    baseline is the last-authorised/signed version; each change's mark **persists until THAT specific
>    change is initialed** — a wholesale re-seal never clears marks.
> 2. **Marks STAY on the final issued document** (like a marked-up wet-ink contract: strike-throughs +
>    write-ins visible). Keep the appended change-history page too.
> 3. **Change render model:** *small change* → strike-through the removed words + **inline** insertion of
>    the new words. *big change* → strike-through the clause + a visible **cross-reference to an Other
>    Conditions entry** where the replacement lives. Render BOTH modes.
> 4. **Granularity:** word-level redline, deletions struck **inline**.
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

### 5A — Field values (data-field diff) — WET-INK strike+insert
Both current and baseline are expanded canonicals carrying `data-field="{var}"` / `data-field="{var}__r{n}"`
spans (the same keying `applyFillReviewAuthoritativeOverlay` already matches). Algorithm:
1. Index the **baseline**'s `data-field` → text.
2. Walk the **current**'s `data-field` spans; for each, if `current.text !== baseline.text` for the same
   key → render **wet-ink**: `<del class="change-del">{old}</del> <ins class="change-ins" data-change-id="…">{new}</ins>`
   — the old value struck through, the new value written in beside it, exactly like a pen correction.
3. Per-recipient correctness is automatic because the match is by the exact `__r{n}` key (Seller 2's edit
   marks Seller 2's cell only). This is the same matching my overlay already proves out.
4. Each mark carries a stable `data-change-id` (hash of key+old+new) so a per-change initial slot attaches
   to it and its "initialed" state can be tracked (§6).

### 5B — Clause / body text diff — WET-INK, two modes (small inline / big cross-ref)
Body text is structured (clause blocks, `data-block` / clause ids, paragraphs). Approach — **anchor then
word-diff**, library-free:
1. **Align blocks** by stable anchors (clause id / `data-role-block` / `data-block-id` / heading text), so
   we only diff *within* corresponding blocks — never a whole-document character diff.
2. Within an aligned block whose text changed, run a **word-level LCS diff** between baseline words and
   current words, and classify by change size:
   - **SMALL change** (≤ N changed words, tunable — default 8) → render **inline**: deleted words
     `<del class="change-del">…</del>` struck **in place**, inserted words `<ins class="change-ins">…</ins>`
     written right beside them. The clause reads as a pen-marked line.
   - **BIG change** (> N changed words, or a whole-clause replacement) → strike the **whole** affected
     clause `<del class="change-del change-clause">…</del>` and append a visible **cross-reference**:
     `<span class="change-xref">See Other Conditions — {ref}</span>` linking to the Other-Conditions entry
     that carries the replacement text (that entry is created by cc6's flow / the existing §7.5
     strikethrough→Other-Conditions route; the render half emits the strike + cross-ref and links to it).
3. **Whole-block add/remove** (a clause present on one side only) → New clause → wrap in
   `<ins class="change-ins change-clause">`; removed clause → keep it struck (`<del class="change-clause">`)
   so the reader sees what was taken out (marks STAY — §6/§9).
4. Skip fields already marked by 5A, and defer to existing `DocumentClauseStrikethrough` renders (§7.5) —
   never double-mark (§7.5 interplay below).

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

## 6. How the highlight persists — WET-INK (per-change, until initialed; STAYS on final)

Because step 6 is a **render-time display overlay** (not baked), EVERY surface that renders through
`compose()` / `forDisplay()` / `resolveOrCompose()` shows the marks. Under the wet-ink model:

- **Baseline is fixed at the last-authorised/signed version and PRIOR SEALS REMAIN.** The diff is always
  current-vs-that-baseline. A wholesale re-seal does NOT move the baseline or clear marks (that was the
  old "new seal = new baseline" model — **removed**). The baseline advances only when the change itself is
  accepted+initialed and a NEW authorised baseline is explicitly established (Johan's call on when that is —
  cc6's status half owns the transition; the render half just reads whichever seal is flagged the baseline).
- **Each mark persists until THAT change is initialed.** A mark carries `data-change-id`; when a party
  initials that change (cc6's initialing flow records it, reusing `condition_initials`), the render shows
  that mark as **initialed** (e.g. a small "✓ initialed by …" tag) — it is **not removed**. Un-initialed
  changes still read as pending. Marks are cleared/settled per-change, never by a blanket re-seal.
- **Marks STAY on the final issued document** (decision #2): the completed contract shows the
  strike-throughs + write-ins like a wet-ink markup, plus the appended change-history page (§9 = PERSIST).
- **Authoriser review + recipients** all render through `compose()` so all see the identical marks and their
  per-change initialed state. Seals stay hash-chained/immutable for audit.

**Contract with cc6:** the render half reads (a) `web_template_data['amendment_render']` to know a returned
doc is in the marked state, (b) the last-authorised **baseline seal** (flagged via
`web_template_data['change_baseline_seal_id']` if cc6 sets it, else the render half resolves the latest
`authoriser_cosigned`/`candidate_final_approved` seal), and (c) per-change initial state from
`condition_initials` keyed by `data-change-id`. cc6 owns setting the flag, creating the Other-Conditions
entry for big changes, capturing initials, and the status transitions. The render half never writes these.

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

## 9. Final issue — **DECIDED: marks PERSIST** (decision #2)

The final issued document **keeps the wet-ink marks** — strike-throughs + inline write-ins + big-change
cross-references stay visible on the completed contract, exactly like a hand-marked paper agreement. In
addition, an **appended change-history page** (generated from the seal chain: each change, old→new, who
initialed, when) is attached to the final document. No "clear-on-final" variant is built.

Rationale (Johan): the marked-up face IS the record — a reader must be able to see the document was amended
and exactly how, without opening an audit log.

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

## 12. Decisions — LOCKED (2026-08-04)

1. §9 clear-vs-stay → **PERSIST** (marks stay on final + change-history page). ✅
2. Granularity → **word-level**, deletions struck **inline**. ✅
3. Change model → **both**: small = inline strike+insert; big = strike + cross-ref to Other Conditions. ✅
4. Wet-ink → prior seals REMAIN; baseline = last-authorised/signed; marks persist per-change until that
   change is initialed; NOT cleared by wholesale re-seal. ✅
5. Baseline seal event → **latest of `authoriser_cosigned` / `candidate_final_approved`** (unless cc6
   explicitly flags `change_baseline_seal_id`). ✅

Remaining tunable (safe default, adjustable later): the SMALL↔BIG word-count threshold `N` (default 8).

---

## 13. BUILD STATUS (RENDER half — DONE on QA1)

**Built + verified** (`app/Services/Docuperfect/DocumentChangeHighlighter.php` + `CanonicalDocumentRenderer.php`,
+ `tests/Unit/Docuperfect/DocumentChangeHighlighterTest.php`):

- ✅ Field-value change → `<del>old</del> <ins>new</ins>` (per-recipient `__r{n}` safe).
- ✅ Field delete-only (struck, no insert) / add-only (insert, no strike).
- ✅ Clause SMALL → inline word-level strike+insert; clause BIG → strike + "See Other Conditions" cross-ref.
- ✅ Clause delete-only / add-only (inline).
- ✅ Multi-change in one document; unchanged clauses stay clean; no-diff/empty fail-safe.
- ✅ Fill-review overlay (step 5) + change-highlight (step 6) compose together per-recipient.
- ✅ Marks + "Schedule of Amendments" appendix PERSIST on the baked/final serve paths (`forDisplay` /
  `resolveOrCompose` at `canonical_version >= 1`), not just while re-composing.
- ✅ Per-change "Initialed by {name}" tag keyed by `data-change-id` (green pill; reads cc6's map).
- ✅ Gated by `amendment_render` → normal docs render byte-identically; `esign:regression-walk` 25/25.
- ✅ Real dompdf render proof (all change types + appendix render correctly in PDF).

## 14a. CONTRACT LOCKED + JOINT INTEGRATION VERIFIED (2026-08-04)

The cc6↔cc1 contract is **locked and proven end-to-end on QA1** (single shared shape — no two variants):

- **`data-change-id = substr(sha1(key|old|new), 0, 12)`** — cc6's `ClauseEditService` and cc1's
  `DocumentChangeHighlighter` compute it identically (field key for cc1, `clause_ref` for cc6). ✅
- **Shared CSS + classes** — cc6 bakes clause strikes into `merged_html` using cc1's `change-del` /
  `change-ins` / `change-xref` classes + `data-strikethrough-applied="1"`; cc1 **defers** its own body-diff
  to them (no double-mark) and **absorbs** them: injects the CSS, lists them in the Schedule of Amendments,
  and stamps their initialed tag — even with an empty baseline (so cc6's permanent clause strikes stay
  STYLED on the final document after re-authorisation). ✅
- **ONE initials shape** — `web_template_data['change_initials'][<data-change-id>] = {name, at}`. cc6 writes
  it (item 4), cc1 reads it → "Initialed by {name}". This is the single render-initials contract; there is
  no `condition_initials`-vs-`change_initials` split in the render path (cc6 may still persist to
  `condition_initials`/`DocumentClauseStrikethrough` for audit, but the RENDER reads only `change_initials`). ✅
- **amendment_render lifecycle** — cc6 sets it on edit, **clears it on re-authorisation** (the newly-baked
  canonical becomes the new authorised baseline, so the FIELD diff correctly goes empty). Clause strikes are
  baked CONTENT and **stay** regardless (cc1 styles them via the absorb pass). This is the agreed wet-ink
  model and is verified. ✅

**Joint proof (11/11 green, real dompdf render):** cc6 `ClauseEditService` small-inline + big→Other-Conditions
edits + cc1 field diff + `change_initials` → one styled wet-ink document (struck old text, written-in new,
"See Other Conditions — clause N", "Initialed by {name}"), marks persisting after the flag clears.

## 14. cc6 ACTIVATION CHECKLIST (flow/status half — to light this up end-to-end)

The render half is live and dormant until cc6 sets these signals (all on `web_template_data`, so no shared
table / migration and no file overlap with the render half):

1. **`web_template_data['amendment_render'] = true`** when a returned doc is re-edited. Keep it set for the
   life of the amended document (marks must STAY on the final — decision #2). The render half no-ops without it.
2. **Baseline**: nothing needed if the last `authoriser_cosigned` / `candidate_final_approved` seal IS the
   right baseline (render half auto-resolves it). Otherwise set `web_template_data['change_baseline_seal_id']`.
3. **Big-change → Other Conditions**: create the OC entry for the replacement text (existing §7.5 route) and
   stamp `data-oc-ref="{clause number}"` on the struck clause element so the render shows "See Other
   Conditions — clause N". Without it the render still shows a generic "See Other Conditions".
4. **Per-change initials**: when a party initials a change, write
   `web_template_data['change_initials'][<data-change-id>] = ['name' => '<who>', 'at' => '<iso ts>']`.
   The `data-change-id` is emitted by the render on every `<ins>`/xref (deterministic
   `substr(sha1(key|old|new),0,12)`). The render then shows "Initialed by {who}" on that change + in the
   appendix; un-initialed changes read "pending".

## 15. AUTONOMOUS SUB-DECISIONS (made while Johan offline — CONFIRM tonight)

1. **SMALL↔BIG threshold = 8 changed words** (`DocumentChangeHighlighter::BIG_CHANGE_WORDS`). Reasonable
   default; trivially tunable. Confirm the number.
2. **Marks persist on EVERY serve incl. baked/final** while `amendment_render` is set (assumes cc6 keeps the
   flag set for the amended doc's life). This delivers decision #2 (marks stay on final). Confirm cc6 keeps
   the flag rather than clearing it.
3. **Per-change initials contract = `web_template_data['change_initials']`** (map keyed by `data-change-id`),
   chosen over a new `condition_initials.change_id` column to keep the two lanes disjoint and migration-free.
   Confirm cc6 is happy to write that map (else we add a column — a bigger, shared change).
4. **`data-change-id = substr(sha1(field/clause-key | old | new), 0, 12)`** — deterministic + stable across
   renders so an initial keyed to it survives re-render. Confirm acceptable (a change reverted then re-made
   reuses the same id, which is the desired wet-ink behaviour).
5. **Initialed marker text = "Initialed by {name}"** (dropped the ✓ glyph — dompdf's base font renders it as
   "?"). Green pill conveys done.
6. **BIG change strikes the WHOLE clause** and routes to Other Conditions (vs a partial inline redline) —
   matches Johan's "big change → strike + cross-reference" wording.
