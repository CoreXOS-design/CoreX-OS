# Performance sweep, blank-PDF root cause, and storage-orphan scoping — 2026-08-23

**Lane:** QA1 (conductor-directed). **Scope:** staging + read-only live investigation. **Status at handoff:** clean — nothing half-built, all shipped work is on `origin/Staging` and verified, all worktrees removed. Johan returns ~5pm and this session may lose contact without warning; this doc is the stopping point.

This was one continuous session covering, in order: a MIC Work-tab speed investigation → a general-purpose measurement harness built for it → an app-wide timing survey using that harness → a blank-PDF correctness bug found during the survey and root-caused → a fix shipped to staging → a storage-orphan scoping exercise triggered by something found while root-causing the PDF bug → two more staging perf fixes (sidebar badges, soft-deletes registry). Each piece is written up below with pointers to the artifacts, not re-derived — read the linked files for full detail.

---

## 1. The measurement harness (the most reusable output of tonight)

Two tool sets, both at `/mnt/HC_Volume_103099143/corex-tools/` (outside any git repo — shared, deploy-independent, reachable from every checkout on this box):

- **`mic-work-bench/`** — depth-first: matrix-of-cases timing + byte-for-byte output fingerprinting for one screen. Built for MIC's Work tab, reused same-night by another lane to prove the `core-matches/all` N+1 fix.
- **`page-survey/`** — breadth-first: timing (no fingerprinting) across many routes at once. This produced the ranked list in §2.

**Full documentation:** `/mnt/HC_Volume_103099143/corex-tools/README.md` — what each script does, exact usage, how the read-only guarantee works (rolled-back transaction + never calling `terminate()`, and explicitly what that does *not* protect against — Puppeteer, file writes, outbound HTTP, `Mail::send()`), how to add a route to the survey, and how to read a fingerprint diff. Written so someone with zero session context can pick it up in about ten minutes — start there, not here, if you're about to run either tool set.

**Why this matters going forward:** this is currently the only real test coverage these screens have. There's no automated test suite entry that would catch MIC regressing back to 11 seconds, or `/admin/soft-deletes` regressing back to 270 queries. Re-running the relevant harness before/after a change is that coverage until something more permanent exists.

---

## 2. App-wide page timing survey

**Raw results:** `/mnt/HC_Volume_103099143/corex-tools/page-survey/output/sweep_2026-08-23/results.jsonl` — 167 routes attempted (146 succeeded with a 200; the rest are documented failures, not silent gaps — see below), against **live**, agency 1, user 22 (Johan), 3 runs each, median/min/max reported per route.

**To regenerate:** `cd /mnt/HC_Volume_103099143/corex-tools/page-survey && php survey.php survey_routes.json 22 <new-label> 3` — see the tooling README for full instructions, including how to extend `survey_routes.json` with routes this sweep didn't cover. The specific numbers below will be stale within weeks; the point is that anyone can re-run this and diff against today's file to see what's gotten worse (or better).

### Headline finding: MIC was not the worst offender

| route | median wall | SQL | queries |
|---|---|---|---|
| `/corex/core-matches/all` | **14,733ms** | 11,286ms | 1,221 |
| `/corex/market-intelligence` (MIC) | 11,848ms | 6,694ms | 192 |
| `/corex/settings` | 2,520ms | 1,184ms | 691 |
| `/corex` (dashboard) | 2,498ms | 1,574ms | 515 |
| `/admin/soft-deletes` | 2,446ms | 1,558ms | 280 |
| `/corex/command-center/Today` (same controller as `/corex` above) | 2,424ms | 1,562ms | 516 |

`core-matches/all`'s N+1 (`ContactMatchController.php:133` unbounded `->get()`, `:157-174` `propertyCountsFor()` calling `propertiesForMatch()` once per match) was fixed by another lane same-night, verified via `mic-work-bench`. `/corex` and `/admin/soft-deletes` were addressed by this lane, §5 below. `/corex/settings`'s 8MB response body and `/corex/core-matches/all`'s (now presumably resolved) root cause are NOT re-verified in this doc — check the survey re-run for current state.

### A systemic finding, separate from any single slow page

Pages that skip the authenticated sidebar layout (public/print views) run 30-90ms on 8-23 queries. Every page that renders the normal sidebar has a floor around 900-1050ms on 68-110 queries, **regardless of what the page itself does** — traced to five `cache()->remember(..., 60, ...)` badge counts baked into `resources/views/layouts/corex-sidebar.blade.php`, all of which cold-miss on every isolated test request (this app's cache store is the `database` driver, so every hit — warm or cold — is a real MySQL round trip; a rolled-back-transaction test harness never lets a write persist across requests, which is *why* this floor showed up so starkly in the survey and does not necessarily mean the same floor exists on every real production page load — real traffic can warm these keys across users). Addressed for 2 of 5 badges + 1 TTL widened, §5.

### Routes skipped, with reasons (not silently dropped)

21 route groups had no parameter-free variant and were excluded before running anything: token-based one-time links (password reset, onboarding invites, FICA confirmation, sales-document return, etc. — a second hit could plausibly change state a DB rollback doesn't fully cover), public/non-staff-facing pages (agent bio pages, public property portal), and a few detail-action routes judged not worth the risk in the time available (`docuperfect/documents/create/{id}` — plausible lazy-draft-creation on GET, not verified safe; `tracked-properties/{id}/merge`). Full list with reasoning: see the chat transcript from this session, or re-derive via the tooling README's route-classification steps — not re-copied here since it's a "how to think about scope," not a fact worth freezing.

21 routes returned non-200 during the run (12× `403` — permission gates Johan's own account doesn't hold, e.g. `corex/commission/principal`; 6× `302` — legitimate redirects, e.g. already-authenticated `/login`; 3× `404` — demo-gate pages needing session state the harness doesn't set up). All in `results.jsonl` with their status codes, not silently omitted.

---

## 3. Blank-PDF root cause — a pattern worth watching for elsewhere

**What was found:** `SigningController::downloadWebPdf()` produced a valid-but-blank PDF (872 bytes, confirmed by rendering it — genuinely empty page) for any signed document whose `web_template_data` had gone empty. On live, that was **11 of 11** completed `signature_templates` at the time of investigation (2026-03-03 through 2026-05-25 — all pre-2026-07-19, see below).

**The bug shape — this is the reusable part:**

```php
// downloadWebPdf() — BROKEN
$mergedHtml = $pdfService->buildInjectedRenderHtml($signatureTemplate);
if (empty($mergedHtml)) {          // <- can never be true
    return ...error...;
}
```

`buildInjectedRenderHtml()` → `injectInitialsPagination()` **always** wraps its input in a non-empty CSS+JS pagination scaffold, even when the real content was empty. So the emptiness check ran on the *wrapped* output, which is never falsy — dead code, no error, no crash, just a faithfully-rendered blank page handed to a human as if it were their signed legal document.

The fix (contrast, both already correct in the same file before tonight): `printView()` and `SignaturePdfService::generate()` both check emptiness on the **raw pre-wrap** content (`resolveRenderHtml()`'s direct output), *before* calling the function that wraps it. **The general lesson: when a value passes through a "wrap for rendering" step that always produces non-empty output (a template, a pagination scaffold, a default layout), an emptiness/validity check must run before the wrap, not after — checking after will silently always pass.** Worth grepping for this shape (`buildX()` producing guaranteed-non-empty output, followed by an `empty()`/`is_null()` check on its result) anywhere else PDF/HTML generation happens in this codebase; not done as part of this investigation — flagging the pattern, not a completed sweep.

**Fix status:** shipped to staging (`968604995`, commit message has full detail), NOT deployed to live — that decision is Johan's. The fix serves the stored `signed_pdf_client_path`/`signed_pdf_path` file directly when one exists (instant, byte-identical to what was signed) and only falls back to re-rendering — with the corrected pre-wrap emptiness check — when no stored file exists. Verified on staging: stored-file case 200/0.15-0.37s with real content; no-stored-file-and-empty case returns an honest 404 error, not a blank stream; expired/garbage tokens still correctly rejected. Full verification detail and check-order reasoning: see the chat transcript for this session (2026-08-23, "downloadWebPdf fix" exchange) — not duplicated here.

**Root cause of *why* `web_template_data` was empty:** not a live-only bug, not an ongoing regression. `git log -S "webData['canonical_html'] = "` shows the population mechanism landed 2026-07-19 ("ESIGN-WETINK Phase 1a"). Every one of live's 11 affected completions predates that by at least two months. Confirmed via staging's own newer fixtures (9 of 20 completed templates there — the ones created after 2026-07-19 — have it populated correctly). **New completions are not at risk.** This is a closed, bounded population: 11 documents, dates fixed, nothing accumulating.

---

## 4. Storage-orphan scoping — 32 files, exact paths (a lookup, not an investigation)

**Context:** while root-causing §3, found that `Storage::disk('local')` (→ `storage_path('app/private')`) doesn't reach a large volume of historical data — most of it turned out to be already bridged by symlinks someone created 2026-06-26 (~12:51-12:53, one deliberate batch). What's actually still unreachable, after removing that false trail, is narrow and now fully enumerated:

### docuperfect signed documents — 22 files (11 templates × 2 files each)

Path: `/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/{template_id}/{client_signed.pdf, final_signed.pdf}` — note this is the **top-level** `docuperfect/` directory in the old volume, a *different, sibling* location from `private/docuperfect/` (which the June 26 batch correctly linked and IS reachable today). This one was never linked.

Template IDs (all real, genuine content — confirmed by rendering two of them): **3, 8, 14, 24, 40, 43, 52, 54, 55, 56, 58**.

```
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/3/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/3/final_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/8/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/8/final_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/14/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/14/final_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/24/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/24/final_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/40/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/40/final_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/43/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/43/final_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/52/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/52/final_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/54/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/54/final_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/55/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/55/final_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/56/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/56/final_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/58/client_signed.pdf
/mnt/HC_Volume_103099143/corex-storage/app/docuperfect/signed-documents/58/final_signed.pdf
```

Target (where these need to land for the app to see them): `signed_pdf_client_path`/`signed_pdf_path` on the corresponding `signature_templates` row point at `docuperfect/signed-documents/{id}/...` relative to `Storage::disk('local')`'s root — i.e. `/corex/storage/app/private/docuperfect/signed-documents/{id}/...`. Confirmed those destination directories currently exist but are near-empty (a `flattened/` subfolder, no `client_signed.pdf`/`final_signed.pdf`) — this is an additive copy, not an overwrite.

### FICA wet-ink signatures — 10 files

Path: behind a symlink at `/corex/storage/app/public/fica.sharedlink.bak` (deliberately renamed aside — the live, actually-resolved `public/fica` directory is real but empty). Real files:

```
/mnt/HC_Volume_103099143/corex-storage/app/public/fica/1756/Zfnrwzpo8zcW1qhckjF1EjvAyWUkw5LYCEv1uvTx.pdf
/mnt/HC_Volume_103099143/corex-storage/app/public/fica/wet-ink/1754/JjDHR7s3bupVobBF55J2Z26qlbExDFieYVIxRuxK.pdf
/mnt/HC_Volume_103099143/corex-storage/app/public/fica/wet-ink/1754/MEGCOPbl1CxI4hfwbuC83PX9wC1LdUIOhV1anvhz.pdf
/mnt/HC_Volume_103099143/corex-storage/app/public/fica/wet-ink/1754/pSx1TgydlqrO2rqQ110HRWKqDZNXd4DBG23imqCO.pdf
/mnt/HC_Volume_103099143/corex-storage/app/public/fica/wet-ink/1755/14fwiZcRxqn0A9saNptIdV4sYO4BOK1zygraFqa5.pdf
/mnt/HC_Volume_103099143/corex-storage/app/public/fica/wet-ink/1755/Z0FBZcjfBbwACju8c916SUWIRstWyIWiYNEDwdX1.pdf
/mnt/HC_Volume_103099143/corex-storage/app/public/fica/wet-ink/1755/ZHICYPIhfGYwazQyOL9BA9BZbGJDDZHOXXQu6VFQ.pdf
/mnt/HC_Volume_103099143/corex-storage/app/public/fica/wet-ink/1756/A3nA82WSjwOXLM3oucyso27ZrMgpVsklAEVbB9fP.pdf
/mnt/HC_Volume_103099143/corex-storage/app/public/fica/wet-ink/1756/DEf1EJt00Q904Yz1VhF3cKet8HEXPsdHeJT2D1m8.pdf
/mnt/HC_Volume_103099143/corex-storage/app/public/fica/wet-ink/1756/zgaItXgj1v6oTSkoMIH9Oo82QK4MBYY9o01eBi7L.pdf
```

Dated 2026-06-12 to 2026-06-15 (immediately before the June 26 batch fix). **Severity note, checked and confirmed**: `fica_submissions.pdf_path` — the only file-path column on that table — is `NULL` on every row in the database, zero exceptions. Nothing currently holds a live pointer into this location; these 10 files are not blocking any workflow today, just unreachable if someone went looking for them specifically. Lower real-world urgency than the docuperfect set despite the "compliance" label.

### One loose end, not fully scoped

`private/imports` (~7MB on the old volume, not symlinked) — Property24 raw CSV batch-import scratch files, already consumed into the database at import time. Almost certainly zero severity (nobody re-opens an import CSV after the fact) but not independently confirmed unreachable-and-harmless the way the two sets above were. Worth a five-minute check before considering storage scoping fully closed, not before.

**Correction on the count:** earlier same-session verbal framing said "the 21 files stranded" (11 documents + 10 FICA, counting docuperfect by *template* not by *file*). The actual file count is **32** (22 docuperfect PDFs + 10 FICA PDFs) across **21 records** (11 templates + 10 FICA submissions). Both numbers are now written down precisely above — use the file paths, not either summary number, for actual recovery.

**Not done, deliberately:** no files were copied, moved, or written anywhere. This was scoping only, per explicit instruction. Recovery (copying the above into the current disk root) is a separate, low-risk, additive operation someone can now do as a lookup rather than a re-investigation — but it wasn't authorized tonight and wasn't performed.

---

## 5. Two staging perf fixes shipped and verified

### Sidebar badges (`fix/sidebar-badge-cache-cost`, merged to `Staging` as `85c0fe768`)

`resources/views/layouts/corex-sidebar.blade.php` — 5 badges, all previously `cache()->remember(60s)`. Measured cold/warm/direct for each (fresh process per measurement — see harness README for why that matters). Two (`pending-verification-count`, `wb-pending`) had **no write-invalidation at all** and direct computation beat the cache wrapper in every case, not just cold ones — dropped caching entirely for both; also incidentally closed a pre-existing bug where `wb-pending`'s cache key was agency-only but its query varied by the viewer's own permission scope. Two more (`assistants.agent`, `testimonials.pending_count`) looked identical on the surface but **are** explicitly busted on write elsewhere in the codebase — left alone; removing their cache would have made the common case worse. The one genuinely expensive badge (`mi.sidebar_count`, ~750ms/9 queries on a cold hit via `OnMarketStockService::identitySets()`, which memoizes per-process not per-request) had its TTL widened 60s→300s rather than its query touched — `OnMarketStockService` is cc3's, mid-rewrite, deliberately not touched.

Verified: badge output byte-identical before/after (`Company Settings: 4`, `Market intelligence: 32,759`, pulled from real rendered HTML). Page-level before/after on 5 different pages, real deployed staging, real separate processes — modest but consistent, e.g. `/corex` 1028ms→1019ms, `/corex/contacts` 1059ms→1026ms.

### Soft-deletes registry (`perf/soft-deletes-registry-cache`, merged to `Staging` as `654f576cf`)

`SoftDeleteRegistryService::categoriesWithCounts()` — was one uncached `COUNT(*)` per soft-deletable model (~212 for a non-owner viewer), wrapped in a 5-minute cache keyed by **both** agency and owner-status (not just agency — an owner sees a genuinely different model set, not just different counts on the same set). Verified true-cold vs true-warm on real deployed staging: 2581ms/267 queries → 941ms/51 queries. Output verified byte-identical (114,762 total archived records, same per-model breakdown, fresh-compute vs cached).

Both fixes: query/scope logic untouched in every case — only what gets cached, for how long, or whether at all. Neither touches `MarketIntelligenceController` or `SignatureService`, per explicit instruction.

---

## 6. Git authorship note — read before doing any `git blame` archaeology on `/corex-staging`

`/corex-staging/.git/config` has a repo-local `[user]` override:

```
[user]
	email = can.assurance@gmail.com
	name = cc3 (lane-3)
```

This means **every commit made through that specific checkout — by any lane — is attributed to "cc3 (lane-3)"**, regardless of who actually wrote it. Confirmed this affects at least two of tonight's own commits (`968604995`, `85c0fe768`) — both authored by this (QA1) lane, both showing as cc3 in `git log`. Not touched (`NEVER update the git config` is a hard rule) — flagging so nobody mistakes the authorship trail on `Staging` for reliable, and so whoever owns that override knows it's box-wide-shared, not scoped to their own commits.

---

## Where things stand at handoff

- Nothing half-built. Nothing uncommitted from this lane. All worktrees this lane created are removed (`git worktree list` on `/corex-staging` shows only the checkout itself and one unrelated lane's worktree, not touched).
- `origin/Staging` HEAD at handoff: `089d731ee` (or later — other lanes were actively committing throughout the night; check `git log --oneline -10` on `Staging` for the current tip before assuming this doc's commit references are still HEAD).
- Nothing was deployed to live. Every fix in §5, and the §3 blank-PDF fix, are on staging only, awaiting Johan's explicit go-ahead per standing policy.
- The two preserved artifacts (`/mnt/HC_Volume_103099143/corex-tools/README.md` and this file) are both meant to outlive tonight — re-read the README before touching either tool set, and re-read §4 above as a literal copy-paste source list if recovery ever gets authorized.
