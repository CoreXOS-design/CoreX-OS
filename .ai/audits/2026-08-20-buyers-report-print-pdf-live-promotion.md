# Buyers Report — Print + Download PDF, promoted to live

**Date:** 2026-08-20

## What happened

Johan, urgent (Elize presenting from the buyers report tomorrow morning):
"just noticed no download pdf or print button." Built and shipped the same
evening. Reused `AgencyPerformanceReportController::print()`/`agentPrint()`'s
exact pattern — a chrome-free Blade view with a shared print-header (agency
branding, period, generated timestamp) — plus `barryvdh/laravel-dompdf`
(already installed; same mechanism `EvaluationCertificateController` uses)
for a genuine server-generated PDF download, which the ROI report's own
print page doesn't have (it only offers `window.print()`).

One deliberate structural deviation from ROI's pattern: buyers-report's
index/agent/branch pages already share ONE `BuyersReportService::build()`
call keyed by scope, so ONE `print`/`pdf` route pair covers all three
instead of ROI's two separate routes (`print`/`agentPrint`).

## Every active filter carries through — verified, not assumed

Print/PDF read scope, period, comparison period, and the buyer/lead/tenant
type filter straight from the URL query string, exactly as the interactive
page already does. The demand-analysis panel's property-type ticks + price
slider only ever lived in client-side Alpine state — `_demand-analysis.
blade.php` now keeps `window.location.search` in sync via
`history.replaceState()` as the user changes the selection, so the Print/
PDF buttons (which read `location.search` at click time via a small shared
`buyersReportExportUrl()` helper) always carry the live selection, on the
index page directly and via an `extraQuery` merge on the agent/branch pages
(which identify themselves by path segment, not query param).

**Real bug caught and fixed before shipping:** `BuyersReportScopeResolver`
always substitutes the VIEWER's own id for `'own'` scope (and the viewer's
own branch for `'branch'` scope) — correct for the interactive page, wrong
for printing someone ELSE's dedicated agent/branch page. `buildPrintData()`
now mirrors `agent()`/`branch()`'s own direct scope construction +
`canViewAgent()`/`canViewBranch()` authorization instead of routing through
the general resolver, exactly like those two controller actions already do.
A unit test (`BuyersReportPrintPdfTest::test_print_of_another_agent_shows_
their_figures_not_the_viewers_own`) proves it stayed fixed.

Per Johan's explicit call: drilldowns (interactive per-buyer modal lists)
are **omitted entirely** from print/PDF — only the summary levels print
(tiles, needs-attention top-10, by-agent, by-branch, pipeline states,
demand). Elize is presenting the shape of the business, not a phone book.

## Gate discipline — same technique as every promotion tonight

Built via `qa1-buyers-report` (pinned at `9e9241c1b`), gated file-by-file
against `origin/main`'s actual current content, never a branch merge or
`git cherry-pick` inside `/corex`. `config/corex-permissions.php` and
`command-center/buyers/detail.blade.php` showed diffs against the anchor
point but were confirmed zero delta from qa1's side — untouched.
`routes/web.php` needed a 3-way merge (live's route table had moved
substantially since the prior sync) — only the two new routes
(`buyers-report.print`, `buyers-report.pdf`) were inserted; all five
existing buyers-report routes preserved exactly.

**Two more unpushed live-only commits found and recovered mid-deploy**,
same pattern as the two earlier tonight:
- `351bf39a8` and `e2a0aa7f5` (contacts/properties pivot-role fix,
  market-analytics sold-comp fix) — both patch-id-identical to commits
  already properly on `origin/main` (`e5c54b0f0`, `e9c13acb2`) via the
  automated deploy pipeline's own independent push. No action needed.
- `1a6dfa2fc` ("docs(audit): record MIC comp property-type filter live
  hotfix by patch-id") — genuinely missing from origin, no equivalent
  anywhere. Merged into a scratch branch alongside the print/PDF commit
  (file-disjoint: a single new `.ai/audits/*.md`), pushed, then `/corex`
  synced to the real `origin/main` via `checkout` + `branch -f`, never
  `reset --hard`.

## Deploy detail

Live's checkout carried Johan's own uncommitted CDS template WIP
(`template-67.blade.php` modified, `template-68.blade.php` new) throughout
— untouched at every step, verified via `git status` before and after each
sync. `barryvdh/laravel-dompdf` confirmed present in `/corex/vendor` before
touching anything (would have been a hard blocker otherwise). `php8.3-fpm`
reloaded (resolved dynamically from `/etc/nginx/sites-available/
corexos.co.za`, never hardcoded). `view:clear`/`route:clear`/`config:clear`
run. `composer dump-autoload` run; reflection confirmed the controller AND
the Dompdf facade itself resolve from `/corex/...` (including
`/corex/vendor/...`), not a cross-checkout path. `migrate:status` confirmed
**zero pending migrations** both before this deploy and needed by it — this
feature adds none.

## Verification on live — real PDFs, real data

Rendered/generated as Johan Reichel, agency scope, `nexus_os` (live) data:

- **Unfiltered PDF:** 28,726 bytes, 5 pages, A4 landscape, opens cleanly
  (`pdfinfo`), title "Home Finders Coastal — Buyers Report — Whole agency".
- **Filtered PDF** (Apartment / Flat, R500k–R1m): 35,188 bytes, 8 pages —
  genuinely different byte content from the unfiltered PDF (proves the
  filter was actually applied, not silently ignored). `pdftotext` extraction
  confirms the exact line: *"Filtered to: Apartment / Flat, R 500,000 –
  R 1,000,000 — 69 buyers match this selection"* — 69 independently
  confirmed via `DemandAnalysisService::filter()` called directly with the
  same scope/type/price arguments. The PDF shows the filtered number, not
  the unfiltered one.

## Where the buttons are

`https://corexos.co.za/corex/buyers-report` — **Print** and **Download
PDF** buttons sit in the page header, next to the type filter and period
selector. Same two buttons appear on the dedicated agent (`/buyers-report/
agent/{user}`) and branch (`/buyers-report/branch/{branch}`) pages, scoped
to that agent/branch specifically.
