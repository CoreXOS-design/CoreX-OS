# Buyers Report — pipeline-marriage redesign promoted to live

**Date:** 2026-08-20

## What happened

Johan: "move the buyers report from staging to live that I can look at it
with actual data." Explicit order, following a full-day redesign arc: an
honest lost-value/real-vs-auto split, a buyer/lead/tenant type filter, and
(the last piece) marrying the report's own numbers to the buyers pipeline
board — "why or where is that reflected on the buyers report - I would
think this report especially should marry up to the buyers pipeline."

Live already carried an **earlier** version of the buyers report, frozen at
qa1 commit `cfa222478` (resolver-permission fix) from an earlier,
uncontrolled wholesale Staging merge. This promotion brings live's buyers
report up to qa1's `a9deef459` — the tiles-first layout, honest lost-value,
top-10 needs-attention, type filter, Lost real/auto split, and the
pipeline-state spine + reconciliation + demand analysis built today.

## Not a literal cherry-pick — each environment gated and merged independently

Unlike a small surgical fix that patch-id-matches across environments, this
feature was built incrementally across a full day and each environment
(qa1 → Staging → live) started from a **different** point in that history:

| Environment | Commit | Base it merged onto | Patch-id |
|---|---|---|---|
| qa1 (`qa1-buyers-report`) | `a9deef459` | qa1's own prior commit (`3154a44aa`) | `44ade2df3...` |
| `origin/Staging` | `1214aa784` | Staging's own state (already partially caught up, `3154a44aa`-equivalent) | `afec9122e...` |
| `origin/main` (live) | `0ed127f4c` | live's much-further-behind state (`cfa222478`-equivalent) | `8c449d23a...` |

Different patch-ids are **expected and correct** here — each commit is that
environment's own delta to reach equivalent final file content, not the
same patch replayed. Content equivalence was verified per-file (every
buyers-report file's current content diffed byte-for-byte against the
intended qa1 source), not by patch-id.

**Gate discipline (all three environments):** `qa1-buyers-report`'s own
history has deeds-capture and other unrelated commits merged in from
earlier branch consolidation. None of it reached Staging or live — every
promotion was built by diffing/3-way-merging individual files against the
target's actual current state, never a branch merge or literal
`git cherry-pick`. `config/corex-permissions.php` and
`command-center/buyers/detail.blade.php` showed real diffs against the qa1
anchor point at each hop but were confirmed 100% the target's own unrelated
history (zero delta from qa1's side) — left untouched every time.

**Files needing a real 3-way merge, not a direct copy, at the live hop:**
- `app/Http/Controllers/CommandCenter/BuyerPipelineController.php` — live
  already has an independently-shipped Sales/Rentals `lead_type` filter;
  the `applyPipelineScope()` extraction (delegating to the new
  `BuyerPipelineScope` class, so the report's pipeline-state section uses
  the board's own scoping) merged in alongside it, verified byte-identical
  SQL for own/branch/agency before either promotion.
- `routes/web.php` — only the new `/corex/buyers-report/demand` route
  needed inserting into live's current (much-diverged) route table.
- `tests/Unit/BuyersReport/BuyersReportServiceTest.php` — preserved live's
  own `show_in_performance_reports` fixture column alongside the newer
  test coverage.

## An unpushed live-only commit, found and recovered mid-deploy

While landing this commit, `/corex`'s local `main` briefly diverged from
`origin/main` at `e9ea03ad5` ("fix(presentations): remove the Outcomes nav
badge entirely, not relabel (CX)") — a commit that existed **only** on the
live checkout, never pushed to `origin`. Confirmed file-disjoint from every
buyers-report file (`corex-sidebar.blade.php` + a presentations test only).
Rather than discard it, it was merged into a scratch branch alongside the
buyers-report commit and about to be pushed when the automated deploy
pipeline independently pushed the identical patch as `554ccc09f` — same
content, different hash, cleanly stacked **on top of** the buyers-report
commit already on `origin/main`. The redundant local merge was discarded
(never pushed); `/corex`'s `main` was synced to the real `origin/main`
(`554ccc09f`) via `checkout` + `branch -f`, never `reset --hard`. See
`.ai/audits/2026-08-20-outcomes-dashboard-date-column-live-cherry-pick-
divergence.md` for that fix's own dedicated record.

## Deploy detail

Live's checkout carried Johan's own uncommitted CDS template WIP
(`template-67.blade.php` modified, `template-68.blade.php` new) throughout
— untouched at every step; verified via `git status` before and after.
`php8.3-fpm` reloaded (resolved dynamically from `/etc/nginx/sites-
available/corexos.co.za`, never hardcoded — Staging is `php8.2-fpm`, a
different pool). `view:clear`/`route:clear`/`config:clear` run.
`composer dump-autoload` run; reflection confirmed all 8 buyers-report-
adjacent classes resolve to `/corex/app/...`, no cross-checkout
contamination. `migrate:status` confirmed **zero pending migrations** —
this feature's one migration (`wishlist_share_events`) was already applied
during the earlier wholesale merge, and no unrelated backlog was queued.

## Verification on live — real data, real numbers

Rendered as Johan Reichel, agency scope, `nexus_os` (live) data:

**Reconciliation — report state counts vs the pipeline board's own
`stateCounts()` method (called via reflection, not a re-derived copy):**

| State | Report | Board | Match |
|---|---|---|---|
| New | 206 | 206 | YES |
| Warm | 51 | 51 | YES |
| Cold | 52 | 52 | YES |
| Lost | 67 | 67 | YES |
| Won | 3 | 3 | YES |
| No state | 1 | 1 | YES |

**Sections:** both render with Johan's exact headings ("What happened to
buyers", "What buyers do we have now"); index page 321,566 bytes.

**Demand filter** (type ticks + price slider, overlap matching): count and
list agreed at every step — 243/243 unfiltered, 87/87 with a type tick
(Apartment / Flat), 69/69 with type + price R500k–R1m. Real property types
confirmed from data: Apartment / Flat, Commercial Property, House,
Townhouse, Vacant Land / Plot.

**Coverage, live, agency scope:** 380 current buyers · 137 have no core
match at all (36.1%) · 144 have no property type recorded (37.9%) · 145
have no price range recorded (38.2%).

**Pipeline board regression check** (Sales/Rentals filter, already live
before this deploy, at risk from the `applyPipelineScope()` refactor):
board renders at 1,280,842 bytes unfiltered, 888,092 bytes with
`lead_type=sale`, 547,718 bytes with `lead_type=rental` — genuinely
different render sizes confirm the filter still narrows the board
correctly, not just that the page doesn't error.

## The one thing this note exists to prevent

If `Staging` and `main` are ever reconciled directly (branch merge, not a
fresh per-file gate), do not expect `1214aa784` and `0ed127f4c` to match by
patch-id or to cherry-pick cleanly onto each other — they are different
deltas onto different bases that happen to converge on the same final file
content. Diff the actual files, not the commits, to confirm equivalence.
