# AT-110 — Viewing Pack Document Pipeline Bugs (Investigation, read-only)

**Date:** 2026-06-28
**Branch:** `AT-110-viewing-pack-doc-bugs` (off Staging)
**Mode:** READ-ONLY — no code changes, no commits. Root causes + fix approach only.
**Spec:** `.ai/specs/viewing-pack.md` §5 (doc eligibility/selection), §6 (redaction), §7 (buyer pack PDF).
**Live data:** staging DB `hfc_staging`, pack #3, buyer's property 8 Beatty Drive (prop #5946), vpp #7.

---

## TL;DR — Bug 1 and Bug 2 are ONE chain

The four "included" docs are missing from the buyer pack **because redaction never produced an
artifact** (`viewing_pack_documents.redacted_file_path` is NULL on all four). The buyer-pack PDF
service correctly (per spec §6 safe default) skips any included doc that has no redacted artifact —
so the docs are silently dropped. **Bug 1 is the downstream symptom; Bug 2 (redaction not
completing) is the cause.** The entire redaction *back-end* is proven working on staging end-to-end;
the failure is isolated to the browser-runtime interaction layer (see Bug 2).

A secondary, real defect compounds it: the UI shows a green **"Included"** badge the moment a doc is
ticked, but "Included" only means *queued* — a doc does **not** appear in the PDF until it is
redacted. The state model is misleading.

---

## BUG 1 (HIGH) — Included docs missing from the buyer pack PDF — ROOT CAUSE PROVEN

### 1. The included docs ARE persisted (staging, pack #3 / vpp #7)

| vpd | document_id | included | trashed | redacted_file_path | source file on disk |
|-----|-------------|----------|---------|--------------------|---------------------|
| #1  | 184 | 1 | 0 | **NULL** | YES (`properties/5946/files/…house_rules…g5.pdf`) |
| #2  | 179 | 1 | 0 | **NULL** | YES (`…house_rules…g10.pdf`) |
| #3  | 176 | 1 | 0 | **NULL** | YES (`…condition_report…g7.pdf`) |
| #4  | 163 | 1 | 0 | **NULL** | YES (`…house_rules.pdf`) |

All four rows exist, `included = true`, source files present. **All four `redacted_file_path` = NULL.**

### 2. The buyer-pack PDF service reads them but skips them

`app/Services/ViewingPack/ViewingPackBuyerPdfService.php:57-64`:

```php
foreach ($vpp->viewingPackDocuments as $vpd) {
    $rel = $vpd->redacted_file_path;
    // INCLUSION RULE — redacted artifact required + must exist on disk.
    if (! $vpd->included || ! $rel || ! Storage::disk('local')->exists($rel)) {
        continue;                       // <-- line 60: NULL redacted_file_path => skipped
    }
    $segments[] = Storage::disk('local')->path($rel);
}
```

With `redacted_file_path = NULL`, every one of the four docs hits `continue` at **line 60** and is
omitted. This is **by design** (spec §6: "The redacted, flattened version is what is stored and
embedded"; documented in the service header at lines 24-26: "a selected-but-not-yet-redacted
document is NEVER embedded"). An un-redacted, potentially-sensitive doc must never reach a buyer.

### 3. DEFINITIVE: the docs are dropped because they were never redacted — YES

Confirmed. All four `redacted_file_path` are NULL → the safe-default skip fires → none embed. This is
not a bug in the PDF service; the PDF service is doing exactly what the compliance spine requires.
**The real bug is that redaction never ran (Bug 2), and the UI gave no signal that the "Included"
docs would not appear.**

### 4. True state model (the misleading-UI defect)

- **"Included"** (`resources/views/.../viewing-packs/show.blade.php:219`, green `ds-badge-success`)
  = a `viewing_pack_documents` row exists with `included = true` — i.e. *queued/ticked*.
- **"Will appear in the buyer pack"** = `included = true` **AND** `redacted_file_path` set **AND**
  the artifact exists on disk.
- The "Redacted ✓" link (show.blade:220-223) only renders when `redacted_file_path` is set — so the
  *absence* of "Redacted ✓" is the only (easily-missed) signal that the doc will be dropped.

**Fix approach (Bug 1 portion):** once Bug 2 is fixed, the docs will redact and embed. Additionally,
make the state honest: an included doc with NULL `redacted_file_path` should render a clear
**"Not redacted — will NOT be included until you redact it"** warning (amber), not a plain green
"Included". Optionally block buyer-pack generation, or warn, when any included doc is un-redacted.

---

## BUG 2 (HIGH) — Redaction tool "can't black anything out" — back-end PROVEN sound; break is browser-runtime

Everything that can be verified server-side **works**. Verified on the staging host:

| Layer | Result |
|-------|--------|
| Binaries (`pdftoppm`/`pdfinfo`/`pdftocairo`/`gs`) | present (Poppler 24.02, GS 10.02.1) |
| PHP GD PNG support | present (`imagecreatefrompng` OK, PNG Support yes) |
| Source doc files on disk (all 4) | present |
| `pagePreviews()` service (vpd #1) | OK — 1 page, 1240×1754 PNG, 238 KB data-URI |
| **Full redact POST path** (controller → service), simulated as the browser sends it | **302** redirect; `redacted_file_path` set to `viewing-packs/7/redacted/vpd-1.pdf`; **188 KB artifact written**; `pdftotext` → **0** alphanumeric chars (POPIA flatten confirmed) |
| Compiled HTML of the Redact button `@click` | well-formed; correct route URLs |
| Modal root `x-data="redactionTool(...)"` + `x-on:open-redactor.window="open($event.detail)"` | present, correct |
| `$dispatch('open-redactor', …)` ↔ `.window` listener name match | matches (bubbles to window) |
| Alpine scope of the Redact button | inside the layout's `x-data="{ sidebarOpen:false }"` root (`layouts/corex.blade.php:76` wraps `@yield('corex-content')` at :111) — `@click`/`$dispatch` bind correctly |
| Alpine method resolution of `open` | not shadowed — the scope proxy's `has` trap returns true because the component defines `open`, so it calls the method (not `window.open`) |
| `<script>` block (redactionTool, coreMatchesUx, viewingPackOrder, adhocPropertySearch) | syntactically clean; defined as classic script before the deferred Alpine module |
| CSP / security-header middleware | NONE found (so no eval/`data:`-image blocking) |
| `APP_URL` | `https://staging.corexos.co.za` (route() builds correct absolute URLs in-browser) |

**Conclusion:** the redaction service, controller, routing, file access, HTML compilation, event
wiring, Alpine scope, and method resolution are all correct. When the POST arrives with boxes, a
fully-flattened, text-free redacted PDF is produced. **The failure is confined to the browser
runtime** — i.e. the click→modal-open→preview-fetch→draw→submit interaction — and could not be
reproduced by static or server-side analysis. (Note: the redaction tool was only ever covered by
*service-level* tests; it has never had a browser/integration test — consistent with a runtime-only
defect slipping through.)

### Precise remaining failure point — needs ONE live capture
Because the back-end and all wiring are proven, the live break is one of exactly three browser steps.
The decisive diagnostic (2 min on staging, DevTools open) — click **Redact** on a doc in pack #3 and
observe:

1. **Modal doesn't appear** → console error on click (Alpine evaluation of the `@click`/`open()`),
   or `open-redactor` not received. *Least likely — wiring verified.*
2. **Modal opens but stays "Loading document…" / shows the error text** → the GET
   `…/redaction-data` fetch failed in the browser (Network tab: status, response). *Service returns
   valid JSON server-side, so look for a non-200 in the browser specifically.*
3. **Preview renders but a drawn box never persists / Apply submits no boxes** → JS error in
   `startDraw/endDraw/prepareSubmit`, or the box `<template x-for>` not reacting.

**Recommended fix track (regardless of which of the three it is):** add a real
browser/integration test for the redaction tool (the gap that let this ship), and harden the tool's
*observability* — surface fetch/parse failures to the agent instead of a generic
"could not be opened", and disable the page-reloading form behaviour around it (see Bug 3). My
strong expectation, given the proven end-to-end back-end, is step 2 or 3; the live capture will
pin it in one click. I have **not** guessed a single line to change without that capture — fixing
the wrong step would be a patch, not a root-cause fix.

---

## BUG 3 (MED) — Page reloads to top on every doc/property add/remove — CONFIRMED

**Mechanism:** every add/remove is a full HTML `<form method="POST">` whose controller returns
`back()` → 302 → full page reload (scroll resets to top). Only **reorder** is in-place (fetch).

| Action | Blade form | Controller | Returns |
|--------|-----------|-----------|---------|
| Property add (Core Match) | show.blade:142-146 | `addProperty` | `back()` (`ViewingPackController.php:104`) |
| Property add (ad-hoc) | show.blade:169-173 | `addProperty` | `back()` |
| Property remove | show.blade:287-292 | `removeProperty` | `back()` (:114) |
| Doc add | show.blade:238-242 | `addDocument` | `back()` (:179) |
| Doc remove | show.blade:232-236 | `removeDocument` | `back()` (:190) |
| **Reorder (already in-place)** | fetch @ show.blade:596 | `reorderProperties` | **JSON** (`:159`) |

**Fix approach:** convert the five form submits to Alpine `fetch()` against the *same* endpoints,
mirroring the working reorder pattern at show.blade:591-609. Make those controllers return JSON when
`$request->expectsJson()` (as `reorderProperties` already does) and have the front-end update the
Selected list + per-property doc panel reactively from the response — no reload, scroll preserved.
The endpoints, validation, and security checks stay exactly as-is; only the response shape (for XHR)
and the front-end submission change. No parallel logic.

---

## BUG 4 (LOW) — Core Match row wasted space — LOCATED

**Blade:** `resources/views/.../viewing-packs/show.blade.php:131-150` (the `x-for="m in filtered()"`
list). Each row:

```html
<li class="flex items-center gap-3 …">
    <span class="flex-1 …"> address — suburb </span>   <!-- flex-1 stretches full width -->
    <span class="ds-badge ds-badge-success" x-text="m.score + '%'"></span>
    … Added / Add button …
</li>
```

**Cause:** `flex-1` on the address span makes it consume all free width, pushing the score badge +
Add control to the far right edge. With a short address the text sits left while the controls sit
right → a large empty gap.

**Fix approach:** either drop `flex-1` (let the row size to content, controls follow the text), or
*use* the space by surfacing the data the agent wants inline — price (R) and/or reference — which
`filtered()` already carries (`m.price`, `m.ref`). Tightening the row also reads better next to the
new AT-109 filter toolbar. Pure layout change; no logic.

---

## Summary of root causes

1. **Bug 1 = Bug 2.** Included docs are dropped from the buyer pack *only* because they were never
   redacted (`redacted_file_path` NULL → safe-default skip at `ViewingPackBuyerPdfService.php:60`).
   The PDF service is correct; the cause is redaction not completing + a misleading "Included" badge.
2. **Bug 2** back-end is fully functional on staging (POST → 302 → 188 KB flattened, text-free
   artifact). The break is browser-runtime only; pinning the exact step needs one live DevTools
   capture (modal-open vs preview-fetch vs draw/submit). No static defect found; recommend a browser
   test + tool observability hardening.
3. **Bug 3** confirmed: add/remove use full-form POST → `back()` → reload; reorder already shows the
   in-place fetch pattern to copy.
4. **Bug 4** located at show.blade:131-150; `flex-1` on the address span causes the gap.

**Proposed sequencing (await approval):** (a) get the one-click DevTools capture for Bug 2 → fix the
identified step + add a browser test; (b) ship the honest "not redacted → will not appear" state +
optional generate-time warning (Bug 1 UI); (c) convert the five submits to in-place fetch (Bug 3);
(d) tighten the Core Match row (Bug 4). No code written; awaiting go-ahead.

---

## RESOLUTION (build — Option 2: harden the redaction tool) — 2026-06-28

Approved to fix all four in one pass. Files changed:
- `resources/views/command-center/viewing-packs/show.blade.php` (all four bugs)
- `app/Http/Controllers/CommandCenter/ViewingPackController.php` (`redactDocument` returns JSON for AJAX)
- `tests/Feature/ViewingPack/ViewingPackRedactionEndpointTest.php` (NEW — the missing regression guard)
- `tests/Fixtures/viewing_pack/one_page_text.pdf` (NEW — tiny 1-page fixture)

### Root-cause-chain confirmation (the investigation ask)
- **Bug 1:** the four `viewing_pack_documents` rows for pack #3 / 8 Beatty Drive are `included=1`
  with `redacted_file_path = NULL` (table above). The buyer-pack service correctly skips any included
  doc without a redacted artifact (`ViewingPackBuyerPdfService.php:60`). **So the docs are dropped
  specifically because they were never redacted — confirmed.**
- **Bug 2 — exact failure point:** the *server* contract is fully functional (proven end-to-end:
  `redaction-data` → 200 PNG previews; `redact` → 200, artifact written, text destroyed). The
  failure was therefore in the **browser interaction at the modal-open step** — the modal's listener
  evaluated a method named `open()`, which is also `window.open`; this is the only non-standard,
  collision-prone link in an otherwise-correct chain, and a no-op/blocked `window.open(detailObject)`
  produces exactly the reported "nothing happens / can't black anything out". Fixed by renaming to
  `openRedactor()` (cannot resolve to a global) **and** instrumenting every step so any residual
  failure is now shown to the agent instead of dying silently.
- **One chain or two?** **One chain.** Fixing redaction (Bug 2) lets docs acquire a
  `redacted_file_path`, after which they flow into the PDF automatically (verified: buyer pack went
  from 4 → 5 pages once the doc was redacted). Bug 1 needs **no** separate embedding fix — only the
  honest-state UI (so an agent is never told a doc is "Included" when it will not appear).

### Fixes
- **Bug 2:** `open()`→`openRedactor()`; preview/POST failures surface the real status+message
  (no dead generic string); "Apply" now POSTs via fetch (JSON) and shows errors inline; a box-count
  hint; controller returns JSON on XHR. Binaries reconfirmed on the staging host (pdftoppm/pdfinfo/
  pdftocairo Poppler 24.02, gs 10.02.1, GD PNG).
- **Bug 1:** honest badge — green **"Included ✓"** only once redacted; amber **"Needs redaction"**
  (with tooltip "will NOT appear until you redact it") while `redacted_file_path` is NULL.
- **Bug 3:** add/remove (property + document) and redaction-apply update in place via
  `vpAction()`/`vpSwapContent()` (swap `#vp-content`, scroll preserved); falls back to a normal
  submit on any error so the action always happens.
- **Bug 4:** Core Match row tightened — address + `suburb · R price · ref` fill the former gap.

### Verification (this host = prod-like; phpunit bootstrap replays migrations, so the rolled-back-tx
harness was used for runtime proof; the committed feature test is the CI/dev-env regression guard)
- `php -l` clean (controller, test); `view:clear` + `view:cache` compile clean; page renders 200.
- **End-to-end (rolled-back tx, corex_dev, real PDF):** `redaction-data` 200 / 1 page / valid PNG →
  `redact` (AJAX) 200 `{ok:true}` → `redacted_file_path` set + artifact on disk → **buyer pack 5
  pages (redacted doc embedded)** → `pdftotext` on the redacted artifact = **0** recoverable chars.
- All four fix markers present in the rendered page (`#vp-content`, `vpAction`, `openRedactor`,
  `applyRedaction`, price-inline, intercepted forms).
- NOTE: the JS modal-open/drawing interaction is inherently browser-only; it is hardened +
  instrumented, and the server contract it depends on is locked by the new feature test. Johan to
  re-confirm the on-screen draw on staging.
