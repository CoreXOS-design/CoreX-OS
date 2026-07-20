# AT-312 (corrected) — Font/formatting tooling in the TEMPLATE create-document editor — INVESTIGATION (no fix)

Read-only. Repo `/mnt/HC_Volume_103099143/corex-dev`. Blame/history verified. (Supersedes the earlier Import-path note per Johan — that path is NOT the flow in question.)

Flow in question: **My Documents → Create Document → pick a TEMPLATE → complete the document → Save → PDF.**

## 1. The editor component
- `DocumentController@edit` (`app/Http/Controllers/Docuperfect/DocumentController.php:93-140`) → **`resources/views/docuperfect/documents/edit.blade.php:52,143-172`**, which mounts `<div id="docuperfect-editor">` and boots `window.DocuperfectConfig = { mode: 'document', pageImages: […], fields: $document->fields_json }` (edit.blade.php:151-168) → **`public/js/docuperfect-editor.js`** in `mode === 'document'`.
- The editor renders each template page as a **rasterized image** (`docuperfect.page.image`, edit.blade.php:146-149) and overlays **fields** the agent completes. No render_type/cds branch — every template opens this same editor (:139).

## 2. What font/formatting tooling it has (present today)
There IS font + text-formatting tooling — a **per-field inline toolbar** (`buildInlineToolbar`, `docuperfect-editor.js:790-846`) shown **when a text-capable field is selected**:
- **Font family** (Helvetica / Times / Courier) — :810-818
- **Font size** (number, 6-48) — :820-824
- **Bold (B)** — :827-831 · **Underline (U)** — :834-838 · **Solid background (BG)** — :841-845
- Gated by `isTextCapable(type)` = `['placeholder','date','condition']` i.e. **Text / Date / Clause** fields (`docuperfect-editor.js:808, 2381`).
- It is wired for **document mode** too: `buildDocumentField` appends it on select — `docuperfect-editor.js:540-543` (`if (field.id === selectedFieldId && isTextCapable(field.type)) el.appendChild(buildInlineToolbar(field))`). CSS is fully visible (`.dp-inline-toolbar { display:flex … }`, `docuperfect-editor.css:314-359`).

## 3. What removed/hid it — **finding: nothing did, on the current code**
This is the key result of the investigation, and it's important because it means a blind "revert" would be wrong:
- `git blame` on every font-control line is **February–March** (`91249311` 2026-02-28 for the font controls :807-846; `bc6544380` 2026-02-24 for the document-mode toolbar hook :540-543 and `isTextCapable` :2381). **Untouched since.**
- **No July / recent commit removed or hid it.** The recent editor commits — `4c858b39` AT-263 (Jul 14, "remove the light"; only a 1-line blade change), `bf0c399e` AT-220 (Jul 10, session armour), `ff88a300` AT-207 (Jul 9, PDF export) — **do not touch** the inline toolbar, font controls, `isTextCapable`, field selection, or the CSS. Verified by diffing each.
- The CSS (`docuperfect-editor.css`) last changed **2026-03-13**; the inline toolbar rule is visible, not hidden.
- `docuperfect-editor.js` has **no HTML/`contenteditable`/rich-text body path** and never did — the "word processor" was never a free-text body editor in this flow; formatting has always been **per-field**.

**Conclusion:** on `origin/main`, `origin/Staging`, `origin/QA1` (and this branch), the font/size/bold/underline tooling is **still present** in the template create-document editor. There is **no commit that removed it** to point at.

## 4. So why does it look gone? — two live possibilities (need one confirmation)
1. **Discoverability/design, not a removal.** The controls are **per-field on select**: the agent must click a **Text / Date / Clause** field, and the font toolbar appears **above that field**. There is **no always-visible top formatting toolbar** — the top toolbar only holds field-placement buttons (Text/Initial/Date/Clause/Sign) + the property/contact markers. If agents expect a persistent Word-style ribbon, they'd report it "gone" even though per-field formatting works. This matches the symptom exactly and is **not a code regression**.
2. **A runtime interaction bug** (would be a real regression but not visible in static diff): e.g. the field's value `<input>` swallowing the click so `selectField` never fires, or a field defaulting to a **signer assignment** (`assignedTo !== 'creator'` → greyed out, non-interactive, no toolbar — `docuperfect-editor.js:504-516`) so it can't be selected/formatted. This can only be confirmed by reproducing on the user's actual environment.

**Decisive next step:** on the user's env (qa1/live), open a template document, **click a Text field** — does the small font toolbar (family/size/B/U/BG) appear above it? YES → it's #1 (discoverability). NO → it's #2 (runtime bug); capture the exact field type + assignment and we bisect the interaction.

## 5. Fix directions (no code yet — pick after the confirm)
- **If #1 (discoverability):** the real ask is a **more prominent / always-available formatting control**. Cleanest = surface a persistent formatting toolbar in document mode that acts on the selected (or last-focused) text field — reuse the existing `buildInlineToolbar` font logic + `ensureStyle()/renderFieldsForPage()` so no new styling model is invented. **Effort: LOW–MED. Risk: LOW** (additive UI over existing, proven style plumbing). This is an **enhancement**, not a revert (there's nothing to revert).
- **If #2 (runtime bug):** fix the interaction so selecting a text field reliably shows the toolbar (e.g. ensure the value input's click selects the field; ensure creator-owned text fields aren't greyed). **Effort: LOW once reproduced. Risk: LOW.** Requires a live repro to pin the exact trigger + the commit that changed it.

## Key evidence
`DocumentController.php:139` · `documents/edit.blade.php:52,146-170` · `docuperfect-editor.js:498-544` (document field + toolbar hook), `:790-846` (font controls), `:808,2381` (isTextCapable), `:504-516` (signer-assigned greyed) · `docuperfect-editor.css:314-359` (visible) · blame = `91249311`(Feb-28)/`bc6544380`(Feb-24), no July change · recent editor commits `4c858b39`/`bf0c399e`/`ff88a300` do not touch font/toolbar/selection.

## ROOT CAUSE (confirmed) + FIX (AT-312)
Johan confirmed with screenshots: the font toolbar appears for a **manually-added** text field but NOT when clicking into a **template-defined** field (e.g. "Lessor Name", cursor flashing in it, no toolbar).

**Exact gate — it is NOT `isTextCapable`.** A template data-bound field is `type: 'placeholder'` with a `named_field_id` (`docuperfect-editor.js:364-376`), so `isTextCapable('placeholder')` is TRUE. The gate is **selectability**:
- In document mode, `buildDocumentField` calls `appendHandles(el, field.id)` only `if (userAdded)` — `docuperfect-editor.js:519`. Template fields have `isUserAdded=false` → **no handles**.
- The field's value input/textarea stops mousedown propagation to the container's `selectField` handler — `docuperfect-editor.js:561,575,730` (`inp/ta.addEventListener('mousedown', stopProp)`), so clicking *into* the input never selects the field.
- Result: a template field can never become `selectedFieldId`, so the toolbar hook `if (field.id === selectedFieldId && isTextCapable(field.type))` (`:541`) never fires. Manual fields work only because they have handles AND are auto-selected on placement.

**Fix (docuperfect-editor.js):** new helper `selectFieldOnFocus(inp, field)` calls `selectField(field.id)` on the input's `focus` event, wired into the three text-capable renderers (`renderPlaceholderInput`, `renderDateInput`, `renderConditionArea`). `selectField` now restores the cursor to `.dp-field.selected .dp-field-input` after the re-render (document mode), so clicking a template field selects it → the font toolbar (family/size/B/U/BG) appears AND the agent can keep typing. No handles added to template fields (they stay fixed by the template); only selectability for formatting. Static `public/js` file — no build step.
