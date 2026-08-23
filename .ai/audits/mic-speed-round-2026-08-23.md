# MIC speed round — 2026-08-23

**Status:** shipped to Staging. Nothing on live — queued for Johan's explicit go-ahead.
**Result:** Work-tab (`/corex/market-intelligence`) default screen load: **14,562ms → 2.18-2.27s warm, 2.54s on a just-expired cache, 6.3-6.5s only on the genuine first-ever touch of a filter combination.** 12/12 real-data fingerprints byte-identical to the pre-change baseline at every single step of every commit in this round — the screen does exactly what it did this morning, faster.

This document exists because most of what made this land safely is not visible in the diff. Three optimisations were built, measured, and thrown away *because they didn't work* — that reasoning has to survive somewhere or someone re-discovers it the hard way. One finding (the sort tie-break) is a real, previously-invisible correctness risk that has nothing to do with speed. Read this before touching `MarketIntelligenceController::work()`, `ProspectingListingPageResolver`, `OnMarketStockService`, or any of the three cached aggregate methods again.

Commits, in order (all on `Staging`, all individually fingerprint-verified):
1. `perf(mic): memoise Agency::find() per request, reset before every queued job`
2. `feat(mic): add prospecting.suburb_sort_explicit_tiebreak config flag (default OFF)`
3. `feat(mic): add ProspectingListingPageResolver — SQL-side group pagination`
4. `perf(mic): wire ProspectingListingPageResolver into work() as a fast path`
5. `perf(mic): remove dead $stats/$suburbs/$propertyTypes computation from work()`
6. `perf(mic): cache computeSnapshotKpis/computeActionPresetCounts/computeFilterRailAggregates, 60s TTL`
7. `perf(mic): stale-while-revalidate for the 3 cached aggregates (Cache::flexible)`

Related spec: `.ai/specs/mic-speed-option1-full-set-pagination-design.md` (the original Option A design doc this round builds on).

---

## 1. Three optimisations that were built, measured, and rejected

All three targeted the same symptom: after the pagination rewrite (commit 4) and dead-code removal (commit 5), the Work-tab's default load was still ~6.6-7.6s, dominated by `index_merge` scans across `computeSnapshotKpis()`, `computeActionPresetCounts()`, and `computeFilterRailAggregates()` — six-plus separate `COUNT(DISTINCT CASE WHEN normalized_address IS NULL ...)` dedup scans over the agency's ~9,437 candidate rows, each independently re-evaluating the same `agency_id`/`is_active` predicate. The obvious-looking fixes for that shape all failed on measurement. **Do not re-attempt any of these three without new evidence that the underlying cost has actually changed.**

### 1a. NOT IN → temp-table anti-join for the stock exclusion

**The hypothesis:** `OnMarketStockService::applyNotStock()` sends a 300-600 item literal `NOT IN (...)` list on every call (~8-10 times per request, independently, since it's called fresh at several different call sites with different other filters). Replacing the literal list with a real temporary table + indexed anti-join (`NOT EXISTS` against `_mic_stock_refs`/`_mic_stock_norm_addrs`) should be much cheaper for MySQL to evaluate.

**Built it.** Proved it byte-identical first: 39,279 rows, exact same id set, old `whereNotIn` vs new anti-join. Then measured the actual query cost.

**Result: 250-253ms (old) → 254-258ms (new). No improvement, marginally worse.** `EXPLAIN` on the new version showed the anti-join itself was genuinely cheap and properly indexed (`type=index`/`ref`, tiny row estimates) — but that was never the bottleneck. The `EXPLAIN` on BOTH versions showed the same `index_merge` on `(agency_id_index, is_active_index)`, ~9,437 rows, completely unrelated to how the exclusion list is expressed. **Root cause: MySQL 8's optimiser already hashes large `IN` lists efficiently — the literal-list cost was never real.** The actual cost is the base table access pattern, which this change never touched.

Discarded. Not committed. Table restored to its original 20-index state, verified via `SHOW INDEX`.

### 1b. Materialising the filter-rail's shared base for `by_suburb`/`by_type`/`by_beds`

**The hypothesis:** these three facets all read the *identical* filtered row set (`$railCountBase`, cloned three times), each paying the full WHERE-clause cost independently for a different `GROUP BY`. Materialise the filtered set into a temp table once, then run three cheap `GROUP BY`s against the small already-filtered table instead.

**Built it.** Measured: **835.8ms (3 separate scans) → 747.7ms (materialise once + 3 cheap group-bys), where the materialisation step alone cost 681ms** — nearly as much as the entire original approach. Same root cause as 1a: the materialisation query still has to pay the full `index_merge` cost to build the temp table in the first place, because that cost was never about redundant *declaration* of the WHERE clause, it was about MySQL's access plan for the WHERE clause itself. There is no way to avoid paying that cost at least once per genuinely distinct row-selection, and three different `GROUP BY` dimensions can't share one SQL statement's execution.

(Also caught mid-test: the informal comparison version used a simplified `COUNT(*)` instead of the real dedup `COUNT(DISTINCT CASE...)`, meaning even this ~10% number flattered the real version. The honestly-measured real version would have been worse, not better.)

Discarded. Not committed.

### 1c. Composite `(agency_id, is_active)` index

**The hypothesis (correct, as far as it went):** `EXPLAIN` on every affected query shape — main list, `canvassPool`'s pluck, the rail's `GROUP BY` — showed the identical `index_merge` intersecting two weakly-selective single-column indexes. A composite index covering both columns should let MySQL do one indexed range scan instead of an intersection.

**Built it, fully, with the discipline this deserves as a schema change:**
- Checked the actual WHERE/ORDER BY shapes across all three query types before picking column order; tested `(agency_id, is_active)`, reversed order, and a third `deleted_at` column (rejected — only 1 soft-deleted row out of 39,665, no selectivity to offer).
- `ALTER TABLE ... ADD INDEX ..., ALGORITHM=INPLACE, LOCK=NONE` — **1,023ms**, no error (confirms MySQL genuinely honoured in-place/no-lock rather than silently falling back to a table copy — a copy would have either errored on the explicit request or taken far longer on this row count).
- `FORCE INDEX` comparison proved the new index genuinely faster in isolation: 147-205ms vs 177-286ms for the existing index (~15-30% real).
- **But the natural, unforced query plan — with fresh `ANALYZE TABLE` statistics, tested three times across different column orders and after removing a redundant competing index — never once picked the new composite.** It kept choosing the existing `agency_id_portal_source_portal_ref_unique` index instead. A full end-to-end page-load timing with the new index present but unforced (`no_filter`, 3 reps) measured statistically identical to the already-deployed baseline: zero real-world benefit.
- **Write cost, measured on a real 500-row bulk insert/update batch (the shape a P24 import batch takes):** INSERT 90.6ms → 203.5ms with the index present — **~2.25x slower**. UPDATE unaffected (9.9ms → 11.4ms — `last_seen_at` isn't part of the new index).
- `DROP INDEX ..., ALGORITHM=INPLACE, LOCK=NONE`: **767.4ms**, plan confirmed to revert to the original `index_merge` immediately. Table verified back to its exact original state.

**Rejected. Real, measured downside (write cost), zero real, measured upside (optimiser won't use it). Not shipped.** This is the correctness-over-instinct result Johan explicitly asked for and got: "an index is additive and reversible" is true of the *mechanism*, not of whether it's worth having — measure the actual plan choice, don't assume it from the schema alone.

**Root cause of the optimiser's refusal, and why this isn't a dead end — see §3.**

---

## 2. The sort tie-break finding — a correctness risk, not a speed one

While proving the new SQL-side pagination (`ProspectingListingPageResolver::resolvePage()`) reproduces today's row order exactly, before writing a single line of controller code: **today's screen order on this table has never been a documented guarantee. It has been correct by luck.**

All four allowed sort columns (`last_seen_at`, `first_seen_at`, `price`, `suburb`) have massive tie clusters — up to 336 rows sharing an identical `price`, 119 sharing an identical `last_seen_at`/`first_seen_at`, 1,870 sharing an identical `suburb`, on a table with only 374-2,200 distinct values per column across 32,972+ rows. MySQL's plain `ORDER BY <col>` (no secondary key — this was never in the code) resolves those ties via whatever order its query plan happens to produce, which is an artefact of the execution plan chosen at that moment, not a language guarantee.

**Why this matters, plainly:** this screen was one index change, one MySQL version upgrade, or one query-planner decision away from silently reshuffling rows under an agent's feet — no error, no log line, nothing to notice except "the list looks different today" with no explanation anyone could give. That is now fixed for three of the four sort columns, and the fourth is handled honestly rather than papered over — see below.

### What was actually proven, precisely

For `last_seen_at`, `first_seen_at`, and `price`: the implicit tie-break under the real, fully-filtered `work()` query shape is **`id ASC`**, consistently and reproducibly — proven by running the exact query shape 5 times independently (byte-identical output every time) and by tracing two real live tie clusters (16 rows, 32 rows) sitting directly on a page-1 boundary and confirming ascending-id order held throughout. `ProspectingListingPageResolver::resolvePage()` now declares this explicitly (`ORDER BY <col> <dir>, id ASC`) instead of leaving it implicit — this is a correctness fix in its own right, independent of the pagination rewrite it shipped alongside.

### The suburb exception — reported honestly, not smoothed over

For `suburb`, the same plan shape (`index_merge` + `Using filesort`, confirmed identical via `EXPLAIN` across all four columns) does **not** tie-break as `id ASC`. Traced one concrete case: listing 126 (property_group_id = 126, self-referencing single-member group, suburb "Albersville", byte-verified identical to its tied neighbours — no hidden whitespace, no collation trick) sits third in today's real order, ahead of listings with far smaller ids. When `suburb = 'Albersville'` is added as an explicit equality filter, MySQL switches to a completely different plan (`type=ref` on a suburb index) and *that* version tie-breaks cleanly by `id ASC` — but that isn't the query the real screen runs, so it isn't the order that matters. The genuine, no-equality-filter `ORDER BY suburb` query's filesort tie-break is real, stable across repeats, and **not reproducible via any explicit secondary key tried** (plain `id ASC`, reversed column order — neither matched).

**This was not approximated.** Rather than declare `id ASC` for suburb anyway and accept a silent one-time reshuffle of tied rows, the decision was escalated and made explicitly: `config('prospecting.suburb_sort_explicit_tiebreak')`, **default OFF**. Off: `sort=suburb` stays on the pre-rewrite, full-hydration code path — byte-identical to today, ~11-15s, deliberately not sped up. On: the fast SQL-side path applies with an explicit, reproducible `id ASC` tie-break — under 2s, but rows sharing an exact suburb value will show in a different relative order than today, once, the first time it's flipped. Nothing about which suburb a row groups under changes, only the order within a tied value. This is a one-time product decision for Johan, not an engineering default — see the commit for the full flag docblock.

**`canUseFastPath()` in `ProspectingListingPageResolver` also excludes**, for the same "don't guess" discipline, three other states that need a fundamentally different (buyer-match-aggregate-aware) query shape not built in this round: the AT-75 score-band filter, `matched_only=1`, and `sort=buyer_matches`/`match_score`/buyer-mode's implicit sort — plus `include_in_stock=1` for a prospecting manager, which injects synthetic rows from the `properties` table and floats them across as many pages as the agency's on-market stock needs (the correctness-critical, hardest-to-verify part of the whole redesign per the original design spec's own risk section — deliberately deferred, not attempted). None of Johan's 12 baseline profiling cases exercise the first three; `include_in_stock=1` is gated to manager-only permission and is not the default landing view, so a plain agent can never reach it regardless.

---

## 3. The 20-agency answer

This is the direct answer to the question that started the day: **does the rejected composite index (§1c) stay wrong as CoreX grows to real multi-tenancy, or does the picture change?**

The optimiser's refusal to use `(agency_id, is_active)` today is a direct, mechanical consequence of `agency_id` having **cardinality ~2 on Staging** (effectively one real tenant's data plus a sliver of test rows) — pruning by `agency_id` first barely narrows anything, so MySQL's cost-based optimiser sees no advantage over the wider existing index and picks the one it already has a habit of using. That is not a flaw in the index design. It is a direct read of what "near-single-tenant" data does to a composite index led by the tenant column.

**At 20 real agencies, `agency_id` stops being close to a coin-flip and becomes the dominant selectivity signal in this table.** Each agency's own rows become a small fraction of the total, and pruning to just that agency's rows first — via either the existing single-column `agency_id_index` alone, or the rejected composite — becomes obviously cheaper than an index-merge across two currently-weak signals. **The index isn't wrong for that future. It's early.**

**What this means concretely, for whoever revisits this:**
- Do not re-run this exact test on synthetic/simulated multi-tenant data and trust the result — table statistics, row physical ordering, and buffer-pool behaviour under real multi-tenant write patterns will differ from anything simulated cheaply. Re-test with real data once agency count has genuinely grown, not before.
- The signal to watch for is `agency_id` cardinality in `SHOW INDEX FROM prospecting_listings` (or `information_schema.STATISTICS`) climbing meaningfully above single digits. That's the point this is worth re-measuring, not a calendar date.
- When re-tested, use the exact same methodology as §1c (`EXPLAIN` before/after with `ANALYZE TABLE`, `FORCE INDEX` comparison, a real write-cost batch, `ALGORITHM=INPLACE`/`LOCK=NONE` timing) — the write-cost tax (~2.25x on bulk insert, measured) doesn't go away with more tenants; it has to still be worth paying at that point, not just theoretically justified.

---

## 4. The cache contract — computeSnapshotKpis / computeActionPresetCounts / computeFilterRailAggregates

**Mechanism:** `Cache::flexible($key, [$fresh, $stale], $callback, $lock)` — Laravel's built-in stale-while-revalidate (not hand-rolled). `[$fresh, $stale] = [60, 300]` on all three. `$lock = ['seconds' => 10]` on all three — **this is load-bearing, not decorative**: `Cache::flexible()`'s own default lock duration of `0` maps internally to `DatabaseLock`'s 24-hour fallback timeout, meaning an un-set lock parameter would let one crashed or hung refresh block every future refresh attempt for a day. Do not remove the explicit `['seconds' => 10]` when touching this code.

**Behaviour, precisely:**
- **0-60s (fresh):** served instantly from cache, no computation, no refresh triggered.
- **60-300s (stale):** the *old* value is served immediately — the requesting agent never waits — while a refresh is deferred (Laravel's `defer()`) to run *after* the response is sent (`InvokeDeferredCallbacks::terminate()`, which under php-fpm uses `fastcgi_finish_request()` so the recompute genuinely does not delay what the browser receives). Cold now means "first time ever this exact key has been requested," not "first time this 60-second window" — this is the whole reason SWR replaced a flat TTL: a single agent working normally resets the cache key on every filter/page/sort change, so a flat 60s TTL meant *most* real loads were cold, not the exception.
- **>300s with no successful refresh (total failure — e.g. the refresh callback has been erroring):** the underlying cache row itself expires (stored with TTL = the stale ceiling), so the next request falls through to a genuine synchronous, guaranteed-fresh recompute. This is the hard ceiling — never "a value from last Tuesday." In practice a real blip clears within seconds, because every request past the fresh window (not just the first one) queues its own retry attempt.
- **Stampede protection is built into `Cache::flexible()` itself** — an atomic `DatabaseStore` lock (it implements `LockProvider`) wraps the refresh; ten agents hitting an expired key at once produce one winning recompute and nine silent no-ops. Verified directly: held the lock externally (simulating an in-progress refresh), fired a second refresh attempt, confirmed it backed off without interfering or stealing the lock.

**Key structure — this is the part that will bite someone if it's not respected:**
- `computeSnapshotKpis` / `computeFilterRailAggregates` key on a fingerprint of the `$scopedBase` query builder (`queryFingerprint()` — `md5($builder->toSql() . '|' . json_encode($builder->getBindings()))`). This is **correct by construction**: the builder already encodes agency, visibility scope, and every active list filter by the time it reaches these methods, so there is no separate list of filter parameters to keep in sync by hand — any WHERE/scope condition change automatically produces a different key. **If you add a new filter to the Work-tab query, you do not need to touch the cache key** — it's already covered, provided the new filter is applied to `$scopedBase`/`$kpiCountBase`/`$railCountBase` before it's passed in, same as every existing filter.
- `computeActionPresetCounts` is different and must stay different: it genuinely mixes an agency-wide figure (`pitch_now_high`/`pitch_now`) with per-**viewer** ones (`log_outcomes`/`my_claims`/`expiring`, each scoped to the specific agent's own claims/outreach). Its key explicitly includes `viewerId`. **If a future change makes any part of this method depend on something not already in {agencyId, viewerId, suburbFilter, thresholds}, that new dependency must be added to the key, or a stale/cross-agent number will be served silently** — this is exactly the "getting the key wrong is the whole risk" scenario this was built to avoid, verified directly (a different `viewerId` against otherwise-identical parameters produces a fresh cache miss, not a stale cross-viewer hit).
- **If you add a new KPI tile or filter-rail facet that reads a variable not already threaded into the relevant method's parameters, the cache key will not automatically account for it.** Add the new input to the key construction at the top of the method, the same way `suburbFilter`/`activeSuburb`/`$stockCountBySuburb` already are. This is the one manual step in an otherwise self-maintaining design.

---

## 5. Things found along the way that surprised us — recorded, not fixed unless noted

- **A confirmed dead-code block**, computed on every single full Work-tab page load and hand off to the view, never rendered: `$stats` (six-plus queries including the two heaviest dedup scans in the whole request), `$suburbs`, `$propertyTypes`. The visible stats-strip reads `$snapshotKpis`; the visible filter-rail reads `$filterRailAggregates`. Confirmed via exhaustive grep across `work.blade.php` and every partial it includes — zero references, not even in the fragment/tick-refresh JSON payload. **The comment sitting directly above this block already said as much** ("the fragment stats-strip reads `$snapshotKpis`... both computed below on every path") without anyone following through and deleting the superseded computation it was describing — the same class of leftover as the `$snapshot`/`$resolvedListings`/`$segmentLabels` removal from the day before (2026-08-22), a few hundred lines below this one. Removed in commit 5 of this round. **If similar "the comment already told you this was dead" patterns exist elsewhere in this controller, they weren't hunted for beyond this one block** — worth a dedicated pass if anyone has the time.
- **`OnMarketStockService::$identityCache` (and `$suburbCountCache`) carry the same class of latent risk this session's `Agency::find()` fix addressed for a different class — and it has not been fixed.** Both are plain `private static array` caches with the docblock's own words: "Request-scoped memoisation... the underlying property scan runs once." That's true and safe for a normal HTTP request under php-fpm (PHP tears down all static state between requests). It is **not** automatically safe for a long-running `queue:work` worker, the same failure mode Johan explicitly flagged for `Agency::find()` this session — nothing currently resets these between queued jobs. Not touched in this round (out of scope for a MIC-screen-speed task, and `OnMarketStockService` is used well beyond MIC), but flagged here because the pattern is now proven to exist twice in this codebase and is worth a deliberate sweep rather than a third discovery.
- **`.git/config` on this checkout (`/corex-staging`) carries a repo-local `user` override attributing every commit made through it to "cc3 (lane-3)" regardless of who actually made it** — flagged by another lane mid-session. Not changed (neither lane should touch it), but the authorship trail on this specific checkout is not reliable — don't trust `git blame`/`git log --author` here without cross-checking commit content and dates.
- **The stale-while-revalidate mechanism itself (§4) required a genuine Laravel-internals gotcha to test correctly**: authenticating a real `curl` request against this app requires replicating `Illuminate\Cookie\CookieValuePrefix` — Laravel's name-bound tamper-prefix prepended to a cookie's value before encryption — or `EncryptCookies` silently discards the cookie as tampered and issues a fresh anonymous session with no error surfaced anywhere. Cost real time to diagnose (the failure mode looks exactly like "the session just didn't take," not "the cookie was rejected"). Worth remembering for the next person who needs a real authenticated HTTP round-trip against this app rather than the transaction-rolled-back harness pattern.

---

## Appendix — measured numbers, all runs, for the record

| stage | wall (median, no_filter) | queries |
|---|---|---|
| this morning (baseline) | 14,562ms | 202 |
| after pagination rewrite | 7,638ms | 133 |
| after dead-code removal | 6,654ms | 124 |
| real HTTP, cold (post-caching+SWR) | 6,321-7,166ms | — |
| real HTTP, warm | 2,178-2,272ms | — |
| real HTTP, just-past-fresh-window (SWR stale-serve) | 2,538ms | — |

Every stage above was verified against the same 12-case matrix (no_filter, address_filter=with_address, action_preset=pitch_now_high, search=Rocklands, search=zero-match, page=1/2/last, sort=price asc, sort=suburb asc, include_in_stock=1/0) with a byte-for-byte fingerprint diff (listing ids in order, every KPI tile, every action-preset count, every filter-rail facet count) against the pre-round baseline — **12/12 clean at every single commit**, plus a 31-check pagination invariant suite (28 pass, 3 legitimately skipped pending an exhaustive `FULL=1` walk that only becomes cheap once the pagination fix itself is deployed — see the harness at `/mnt/HC_Volume_103099143/corex-tools/mic-work-bench/` and this session's own extension for the exact checks).
