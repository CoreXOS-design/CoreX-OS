# 2026-08-23 — Live error-reduction: scan-deals, OversightDigestJob, P24 activations, buyer-match regeneration

**Author:** cc3 (lane-3). **Goal, in Johan's words:** "fix what you can. as long as we
are not activating more alerts I'm fine with it. so keep it off, but fix it so we
have less errors running." Reducing error VOLUME, not adding visibility. Every fix
here is provably neutral on what gets sent — nothing sends today that didn't
send yesterday, and where a fix newly enables something that never ran before,
it's gated behind a flag defaulting OFF with the real volume measured, not
guessed.

**Scope:** staging only, own commit per fix, all four deployed to `origin/Staging`
and the served `/corex-staging` checkout, none touched live. `DesyndicatePropertyFromPortalsJob`
was explicitly left alone per instruction (cc6 owns the permanent warning on it).

---

## 1. `notifications:scan-deals` — crash fix + kill switch

**Bug:** `CalendarEvent::withoutGlobalScopes()->query()` — the static `withoutGlobalScopes()`
already returns a query builder (it's a passthrough to `query()->withoutGlobalScopes()`),
so the trailing `->query()` calls a method that only exists on the model, not the
builder. Crashed on 100% of the command's scheduled runs (every 30 minutes,
`routes/console.php`) for at least the visible 14.5-hour log window on live —
confirmed via log timestamps, not assumed.

**Nuance that mattered:** the crash sits in the *third* of three sequential blocks
in `handle()`, with no shared try/catch. The first two blocks
(`deal.stalled_offer/bond/conveyancing` — `default_enabled=true` in
`notification_event_types`, so opt-OUT not opt-in; `deal.commission_unpaid` —
opt-in) run to completion and dispatch normally on every single invocation
today. This fix does not touch or change their behaviour at all. Only the third
block (`deal.milestone_due`, opt-in, `default_enabled=false`) has never executed.

**Fix:** corrected the double-`query()` call; gated the `deal.milestone_due` block
specifically behind `config('command_center.deal_milestone_due_scan_enabled')`,
default OFF.

**Measured volume (live, read-only query, before landing the fix):** exactly 3
users have ever opted into `deal.milestone_due`. Of those, exactly 2 notifications
would fire on the very first run. Not a flood — a small one-off, then a trickle
as new milestones cross into their window.

Commit: `0735e97b0` (first half — scan-deals + config/command_center.php).

---

## 2. `OversightDigestJob` — N+1 batched

**Bug:** `OversightNudge::exists()` called once per (manager, candidate row) inside
a nested loop. Measured on staging: 61.3s wall, 3,041 queries for a dry run
across 8 managers — **2,923 of those queries (96%) were this exact existence
check.** No explicit `$timeout` on the job (defaults to 60s) — it has been timing
out on essentially every run, matching the 637 failures in `failed_jobs`.

**Fix:** two passes per manager. First resolves which rows pass the
enabled/disabled preference filter and computes each row's threshold (pure PHP,
no queries). Then ONE query fetches every `OversightNudge` this manager has
received within the widest threshold any of their candidate rows need; each
row's already-alerted check becomes an in-memory lookup against its own
specific threshold — identical per-row cutoff semantics to before, including
the known flat-24h-fallback bug (deliberately left alone — a product decision,
not a mechanical fix, and it directly shapes the volume numbers `run()`'s own
dry-run mode exists to measure honestly, bug included).

**Measured after:** 1.25s wall, 123 queries — 48x faster, 25x fewer queries.
Fingerprint of what would fire (`run($service, persist:false)`, the job's own
dry-run mode) is byte-identical before/after: same 92 items, same
manager/category/subject/threshold on every one.

This job only runs inside the nudge feature that's currently switched off
(`config('oversight.nudges_enabled')` = false) — fixing it reduces error volume
in `failed_jobs` without changing what sends to anyone.

Commit: `0735e97b0` (second half — `OversightDigestJob.php`).

---

## 3. `SyncProperty24Activations` — bounded and rotated, not raised timeout

**Investigation finding (reported before any fix, per instruction):** NOT a
credential or P24 API-health issue. No HTTP-error-level log entries anywhere
(no 401/403/429/5xx), and the method's own success log line ("P24 activation
sync complete") appears ZERO times in live's current log — it has never
finished. Every failure is `MaxAttemptsExceededException` (a killed process,
not a caught exception) — the signature of a job dying mid-loop from its own
300s timeout, not of P24 rejecting anything.

**Root cause:** `syncAllActivations()` looped over every qualifying property
(186 on live, 183 on staging) making one sequential HTTP call each, inside a
single job with a 300s timeout — unbounded work against a fixed ceiling.

**Fix — STALE-FIRST + BOUNDED**, the exact idiom this codebase already uses for
an identical problem (`P24StatsService::pullForAgency`, AT-200): order by a new
per-property "last ATTEMPTED" cursor (`p24_activation_last_checked_at` — NULLs
first, then oldest), cap the batch at `ACTIVATION_SYNC_MAX_PER_RUN = 40`, stamp
the cursor for every property checked — success or failure — so a
chronically-failing listing still rotates away instead of sorting first
forever and starving the batch.

**Why bounded-sequential across runs, not queue-parallel:** this talks to an
external portal. Parallelising across the queue means multiple workers hitting
P24 concurrently — more simultaneous load, not less. Chunking keeps the exact
same request RATE (one call at a time, unchanged) and spreads the same total
work across more of the job's existing 15-minute ticks. At 183-186 qualifying
properties, the full set now rotates through in ~5 ticks (~75 minutes) instead
of one run trying and failing to do all of it. The 300s timeout itself was
deliberately not touched.

**Proof, without a single real P24 call:** staging shares live's P24
credentials, so no test call was made anywhere in this work. Verified the
rotation directly against the database instead: batch 1 (40 properties) and
batch 2 (the next 40, after stamping batch 1's cursor) have zero overlap —
confirmed three times, including once against the served checkout post-deploy,
each time rotating to a genuinely new set of 40.

Commit: `702ce267d`.

---

## 4. `RegenerateBuyerMatchesJob` — N+1 fetch fixed, then chunked

Two separate rounds of work, reported honestly as two separate results because
the first one, alone, did not solve the reported symptom.

### 4a. Fetch-once fix (the named N+1)

**Bug:** `recomputeForBuyer()` and `recomputeProspectingMatchesForBuyer()` each
re-ran their own agency-scoped candidate query on EVERY contact, even though
neither query depends on `contactId` — only on `agencyId`. Same shape as MIC and
Core Matches (fetch once, don't re-fetch per row) — third and fourth instance
of the pattern in one day.

**Fix:** `MatchingService::matchableCandidatePool()` made public (was already
built for Core Matches); `matchSurvivesFilters()` extended to take an
`$includeHidden` parameter so it correctly serves both call shapes (Core
Matches uses `include_hidden=true`, buyer-match regen uses `false`); new
`MatchingService::bestScoreAcrossMatches()` batches the per-match scoring
against an already-fetched pool. `recomputeForBuyer()` /
`recomputeProspectingMatchesForBuyer()` both take a new optional pre-fetched
pool parameter, defaulting to `null` (exact original per-call-fetch behaviour,
unchanged, for the two OTHER callers — `RecomputePropertyMatches` and
`RecomputeProspectingMatches`, the manual console commands).

**Measured (10-contact real sample, agency 1):** 108.35s / 153 queries →
99.27s / 111 queries. Isolating the fetch itself: hydrating all 32,972
listings costs 0.89s — eliminating 9 of the original 10 re-fetches in the
sample saves ~8s, and scales linearly with contact count. Peak memory flat
(267MB → 261MB) — no regression traded for the speed win. Fingerprint of the
actual output rows (property_buyer_matches + prospecting_buyer_matches) is
byte-identical before/after.

**The honest limit, reported instead of hidden:** ~10s of the ~10.8s per
contact is `canonicalBestAcross()`'s CPU-bound per-listing scoring loop — pure
PHP, 33,000 in-memory `score()` evaluations per contact — untouched by this
fix. 600s timeout ÷ ~10s/contact ≈ 60 contacts fit; agency 1 has 380. **This
fix alone does not eliminate the 637 timeout failures at full scale.**

Commit: `77842a998`.

### 4b. Chunking (the follow-up, same night, same idiom as item 3)

**Why now, not parked:** the job currently NEVER completes at full scale — which
means buyer matches are already only partially refreshed, unpredictably, with
whichever contacts sort first in `buildContactIdQuery()`'s natural order
getting served on every trigger and the rest silently never reached. Chunking
does not introduce staleness that wasn't already there — it replaces
unpredictable partial refresh with deterministic complete refresh across
several ticks. Strictly better than today.

**The trap checked before writing any code:** does this job clear-then-rebuild
(which would put holes in MIC, the screen Johan just tested and approved) or
update in place? Traced every real dispatch site:
- `ContactMatchObserver` (wishlist saved) — single contact, `truncate: true`.
  Scoped to just that one contact's own rows. Safe, narrow, untouched by this
  change — not the failing path.
- `PropertyObserver` (property saved, 60s delay) — agency-wide, `contactId:
  null`, **`truncate: false`**. This is the actual production dispatch behind
  the 1,076 failures (confirmed: the `failed_jobs` payload I captured earlier
  had `truncate: b:0`, i.e. false). **`truncate=false` means `truncateScope()`
  is never called on this path — there is no upfront agency-wide wipe today,
  and chunking must not introduce one.**

Each per-contact recompute call already does its own scoped
delete-stale-then-upsert (`DB::transaction` in
`recomputeForBuyer`/`recomputeProspectingMatchesForBuyer`, keyed to that one
`contact_id`) — narrow, in-place, no window where a contact's rows are fully
absent. Chunking only had to preserve that; it does not add any new wipe.

**Design — STALE-FIRST + BOUNDED + SELF-CHAINING**, mirroring item 3's idiom as
directly as the two jobs' different trigger shapes allow:
- New `contacts.buyer_matches_last_regenerated_at` column — an ATTEMPT cursor
  (stamped success or failure, same reasoning as P24's activation cursor).
- Chunking activates ONLY when `contactId === null && agencyId !== null &&
  truncate === false` — precisely the `PropertyObserver` dispatch shape. A
  single-contact dispatch, a cross-agency super-admin rebuild, or an explicit
  `truncate=true` agency-wide rebuild (`WishlistRegenerateMatches`, a
  deliberate manual wipe-and-rebuild someone is presumably watching) all keep
  today's unchunked behaviour byte-for-byte unchanged.
- `AGENCY_REGEN_MAX_PER_RUN = 40` per invocation (~400s budget inside the 600s
  timeout at the measured ~10s/contact).
- Unlike item 3 (a fixed 15-minute schedule that will naturally re-trigger and
  keep rotating), this job fires from an event (a property save), which could
  be frequent or rare depending on agency activity — so instead of relying on
  being externally re-triggered to eventually finish a rotation, the job
  **self-dispatches its own continuation** (`self::dispatch($agencyId, null,
  false, $traceId, $rotationStartedAt)`) whenever contacts remain, carrying a
  `rotationStartedAt` timestamp through the chain so each continuation knows
  which contacts have already been touched THIS rotation versus a prior one.
  `ShouldBeUnique`'s lock releases as soon as a job starts processing (before
  `handle()` runs), so this does not self-deadlock.

**Proof — mechanism, at full real scale (380 contacts, no scoring run, purely
the selection/cursor logic):** simulated the rotation end to end. Exactly 10
ticks (9×40 + 1×20 = 380), zero missing contacts, zero contacts touched twice,
every chunk within the 40 cap, correct termination on the last partial chunk.

**Proof — data correctness, across a real chunk boundary (80 real contacts, 2
full chunks):** processed as two separate 40-contact chunk-style invocations
(each fetching its own pool fresh, exactly as two separate real job
dispatches would) versus the same 80 contacts in one unchunked pass.
Measured: 749.7s (2×40 chunked) vs 751.9s (1×80 unchunked) — essentially
identical, as expected (the extra pool fetch costs ~0.89s, negligible against
~750s of real scoring work). **property_buyer_matches: 112 rows, byte-identical.
prospecting_buyer_matches: 32,895 rows, byte-identical.** Same rows, same
scores, same order, chunked or not.

Together, the full-scale mechanism proof (zero gaps, zero duplicates across
all 380 real contacts) and this real-data chunk-boundary proof (byte-identical
output across an actual 40/40 split) constitute the full-rotation proof: the
mechanism is proven to cover everyone exactly once at real scale, and the data
is proven identical across the exact boundary that mechanism creates. A
literal single 380-contact double-run (chunked vs unchunked) was not run — at
the measured ~10s/contact it would cost over 2 hours combined for a claim this
composition already establishes rigorously.

Did not exercise the real queue (`self::dispatch`) for this proof — the
served checkout was still running the pre-chunking job class at test time, and
dispatching a job built from the new (5-argument) constructor to a worker
still running the old (4-argument) class risked a version-mismatched payload.
The rotation/selection mechanism was proven separately, queue-free, at full
scale above; this test isolates the DATA question the mechanism proof
doesn't answer on its own.

---

## The habit, named once, for whoever reads this next

Today's five fixes are one pattern wearing five faces: **pull an unbounded set,
then do per-row work over it, inside a single request/job with a fixed time
budget.**

1. MIC — full unpaginated listing set, sorted/grouped in PHP.
2. Core Matches — `propertiesForMatch()` re-run once per match on an oversight
   page.
3. `OversightDigestJob` — `OversightNudge::exists()` once per (manager, row).
4. `RegenerateBuyerMatchesJob` — the agency's full property/listing pool
   re-fetched once per contact.
5. `SyncProperty24Activations` — every qualifying property swept in one
   unbounded loop against an external API with a fixed timeout.

Five patches went in, but the fix for the *habit* is a review reflex, not a
sixth patch: **any time a loop's body issues a query or an external call, ask
whether the thing being fetched depends on the loop variable at all; and if a
loop's total work has no ceiling, ask what happens when the row count
doubles, because on this codebase it already has, more than once, without
anyone changing the code.**

---

## Next piece of work — NOT attempted tonight, deliberately

`canonicalBestAcross()` (`PropertyMatchScoringService.php`) is the actual
dominant cost inside `RegenerateBuyerMatchesJob`: measured at roughly 9-10 of
the ~10.8 seconds spent per contact, running `score()`-equivalent logic
against every one of an agency's ~33,000 active prospecting listings, in pure
PHP, once per contact. Fetching the candidate pool once (4a, tonight) fixed the
REDUNDANT I/O; it does not and cannot touch this cost, because the scoring
itself — not the fetch — is what's slow, and every contact's own matches
genuinely do need scoring against the full candidate set at least once.

This is not tonight's work, on purpose. Reducing per-listing scoring cost is a
change to MATCHING LOGIC — the same engine Core Matches, MIC's tiers, and the
mobile client all read from — not a change to how work is organised. That
category of change is the product's actual value and needs:
- Johan's explicit input on what "correct" means if any shortcut trades
  accuracy for speed (e.g. pre-filtering candidates by suburb/price band
  BEFORE running full `score()`, which would need sign-off that the pre-filter
  can never exclude something the full scorer would have kept).
- A rested pair of eyes, not the tail end of an overnight session.
- Its own measure → fingerprint → fix → prove pass, exactly like tonight's
  four items, once someone is actually assigned to it.

The numbers to start from, so nobody re-derives them: fetch+hydrate 32,972
listings = 0.89s. Full per-contact cost (fetch already eliminated) ≈ 10s.
~33,000 listings × N contacts × however many of the buyer's active wishlists
each contact holds = the real shape of the cost. 60 contacts fit in the job's
600s timeout at today's per-contact cost; agency 1 has 380 and is presumably
not the largest agency this will ever need to run for.
