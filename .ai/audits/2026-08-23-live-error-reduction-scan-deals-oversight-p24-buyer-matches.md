# 2026-08-23 — Live error-reduction: scan-deals, OversightDigestJob, P24 activations, buyer-match regeneration

## ⚠ FIRST THING NEXT SESSION — RegenerateBuyerMatchesJob self-chaining is broken on live

**Read this before anything else in this file, and before touching `RegenerateBuyerMatchesJob` again.**

**What's broken:** the chunked agency-wide regenerate path (see §4b below) is supposed to
self-dispatch a continuation job after each 40-contact chunk, until the whole agency has
been covered for that rotation. On live, it does not. `chain_continuation: true` computes
correctly and `self::dispatch(...)` is called, but no continuation job ever reaches the
queue.

**Why:** `RegenerateBuyerMatchesJob` implements plain `ShouldBeUnique`. Traced directly in
Laravel's own source (`vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php`,
`call()` method): for plain `ShouldBeUnique`, the uniqueness lock is released at line 73,
**after** `dispatchThroughMiddleware()` — i.e. after `handle()` has already fully run and
returned. My design comment claimed "ShouldBeUnique's lock releases as soon as a job starts
processing (before handle() runs)" — that is the behaviour of a *different* interface,
`ShouldBeUniqueUntilProcessing`, and I used the wrong one. The self-dispatch call, made from
inside `handle()`'s own `finally` block, tries to acquire the SAME lock key
(`'regen-buyer-matches:{agencyId}:all'`) that the currently-executing job itself still
holds. `dispatch()` on a `ShouldBeUnique` job silently no-ops when the lock can't be
acquired — no exception, no log line, nothing. That silence is why this wasn't caught
until real end-to-end verification on live.

**The fix — one line:** change `class RegenerateBuyerMatchesJob implements ShouldQueue,
ShouldBeUnique` to implement `ShouldBeUniqueUntilProcessing` instead (import
`Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing`). That interface releases the
lock before `handle()` runs, which is the semantic the design always intended and the
design comment already (incorrectly) claimed was already true.

**The proof this needs before it goes anywhere near live again — do not skip this, it is
the exact thing that didn't happen this time:** an actual end-to-end demonstration that a
chained continuation reaches the `jobs` table. Concretely, on staging: dispatch the
agency-wide (`contactId=null`, `truncate=false`) shape against an agency with more than 40
qualifying contacts, let the first chunk run to completion, and **query the `jobs` table
directly afterward** to confirm a new `RegenerateBuyerMatchesJob` row exists with the same
`agencyId` and the carried `rotationStartedAt`. Do this via the REAL queue (`::dispatch()`,
a real worker), not a direct `handle()` call and not a simulated/manual loop — the bug only
exists in the interaction between the queue's lock bookkeeping and a job dispatching its
own successor, which a direct call bypasses entirely. That's precisely how this passed
every other check tonight (mechanism proof at full scale, real-data chunk-boundary
fingerprint, live gate verification) without ever exercising the one code path that was
actually broken.

Once that's proven on staging, redeploy is code-only — no new migration, no schema
change, same tag-then-fast-forward procedure as tonight.

**Current impact, precisely stated, not minimised or catastrophised:** this is not a
data-safety issue. Every guarantee that protects MIC's tiers is intact and was proven on
live: bounded runs (exactly 40 contacts per invocation, confirmed), no upfront agency-wide
wipe (confirmed — `truncate=false` on this path, unchanged), no holes (5 sampled untouched
contacts had identical row counts before and after a real chunk ran), idempotent per-contact
writes (unchanged from the earlier, separately-proven fix). The failure mode is narrower
than "doesn't work": the rotation doesn't *guarantee* forward progress via self-chaining
the way it was designed to. It instead relies on the next natural `PropertyObserver` trigger
(a property save in that agency) to pick up the next 40-contact slice. Given agency 1's
observed save frequency, the rotation will still complete — just on the schedule of natural
triggers rather than immediately chained ticks. Slower than designed, not worse than the
pre-deploy state (which had no guaranteed completion mechanism at all).

---

**Author:** cc3 (lane-3). **Goal, in Johan's words:** "fix what you can. as long as we
are not activating more alerts I'm fine with it. so keep it off, but fix it so we
have less errors running." Reducing error VOLUME, not adding visibility. Every fix
here is provably neutral on what gets sent — nothing sends today that didn't
send yesterday, and where a fix newly enables something that never ran before,
it's gated behind a flag defaulting OFF with the real volume measured, not
guessed.

**Scope:** built and proven on staging first for all four items, own commit per fix.
All four were taken to live the same night, after Johan's explicit go, following the
tag → migrate → code → verify procedure in the live-deploy section near the end of this
file. `DesyndicatePropertyFromPortalsJob` was explicitly left alone per instruction (cc6
owns the permanent warning on it).

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
(no 401/403/429/5xx). Every failure is `MaxAttemptsExceededException` (a
killed process, not a caught exception) — the signature of a job dying
mid-loop from its own 300s timeout, not of P24 rejecting anything.

**Correction, made during live verification, not before Johan was told
otherwise:** the original investigation searched `laravel.log` for the
method's own success line ("P24 activation sync complete") and found zero
matches, and reported from that that the job "has never finished." That
search missed the dedicated `property24` log channel (a separate daily-rotated
file, `storage/logs/property24-*.log`, configured in `config/logging.php` —
`Log::channel('property24')`, not the default channel). Checking that channel
during live verification showed the OLD code actually completing successfully
three times in the hour before this fix deployed (185/186 synced each time).
**The corrected picture: the old code alternated between completing and
timing out — it was not a 100% failure rate.** The 3,951 accumulated failures
are real; the claim that it never once succeeded was wrong, and rested on an
incomplete log search. This does not change the fix's validity: bounded and
rotated guarantees completion within budget on every single run, which is
still strictly better than a job that sometimes finishes and sometimes dies
depending on how close the property count pushes it to the 300-second wall —
but the severity as originally reported was overstated, and the correction is
recorded here because it was already passed to Johan before it was caught.

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
  **Claimed here, at design time: "`ShouldBeUnique`'s lock releases as soon as
  a job starts processing (before `handle()` runs), so this does not
  self-deadlock." That claim is WRONG — see the correction at the very top of
  this file. It's the reason the self-chaining doesn't actually work on live.
  Left standing here, struck through in spirit rather than deleted, so the
  gap between what was designed and what was verified is visible in one
  place.**

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

---

## Live deploy — 2026-08-23, same night, Johan's explicit go

All four items above were built and proven on staging first, then taken to live
together after Johan's explicit instruction. This is the record of that deploy:
what was done, what was checked before touching anything, and the true state
live was left in.

**Safety tag:** `live-pre-error-reduction-batch-20260823-192716`, pushed to origin,
at `main`'s pre-deploy HEAD `414f688d5`.

**Migration hardening, done before touching live, not assumed:** both new-column
migrations were originally written as `ALGORITHM=INPLACE, LOCK=NONE` — the
"should be instant" choice for a plain nullable column add. Tested directly on
staging's own `properties`/`contacts` tables (near-identical scale to live,
~10k rows each) before trusting that assumption: **it took 26-38 seconds of
genuine "altering table" execution** (confirmed via `SHOW PROCESSLIST` and
`performance_schema.metadata_locks` — not a lock wait, MySQL was actually
working that whole time). Switched to `ALGORITHM=INSTANT` (MySQL 8.0.12+'s true
metadata-only path, eligible since neither column needs a backfilled default) —
measured 300-400ms on staging. `INSTANT` cannot be combined with an explicit
`LOCK=` clause — MySQL errors outright (confirmed by hitting the error, not by
reading about it). Both migrations committed with the corrected algorithm
(`e51eb4790`) before deployment.

**On live itself:** both migrations ran in 885.72ms and 739.45ms respectively —
matching the staging measurement, confirming the fix rather than assuming it
would generalise. Deploy order was migration-then-code specifically so no
request window could hit the new job classes before the columns existed.
Confirmed (not assumed) that old code tolerates the new columns being present:
fetched a real `Property` and `Contact` from a column-added database using
calls that never reference either column, then called `save()` — both worked
cleanly, no exception. A code-only rollback (leaving the columns in place)
would be harmless.

**Rollback plan, for the record:** code — `git revert` (new commits, never
`reset --hard`, never force-push) on `main`, pushed, then
`git -C /corex merge --ff-only origin/main`, the same fast-forward mechanism
used for the forward deploy. Migration — both `down()` methods exist and use
`ALGORITHM=INSTANT` too; independent of code rollback, not automatically
paired with it, since the column is confirmed harmless to leave in place.

**Gate results, each checked against real live behaviour, not inferred:**

1. **`scan-deals`: PASS.** Ran clean, exit 0, 0.465s. Flag confirmed `false`
   in live config. Zero new `deal.milestone_due` dispatch rows (0→0 across the
   run). No new crash log entries after deploy.
2. **`OversightDigestJob`: PASS.** Ran directly (4.98s, real live data) and via
   the real restarted queue worker (2s, twice, including one job whose stale
   pre-deploy reservation had to be released first — see below). `failed_jobs`
   count for this class read **655 → 655 → 655** across three checks spanning
   the whole deploy window. Flat, which was the actual bar, not just "it ran."
3. **`SyncProperty24Activations`: PASS**, after ruling out a false alarm. One
   stale job — dispatched and already running under the OLD code before the
   restart, its `attempts` counter already at 1 when its orphaned reservation
   (see below) was released — hit `MaxAttemptsExceededException` on Laravel's
   own pre-flight attempts check, before the new code ever ran. Timeline-traced
   to confirm this rather than assumed; my code was never actually invoked for
   that specific failure. A genuinely fresh dispatch completed in 29s, stamped
   exactly 40 properties, zero new failures. See the correction above the P24
   section for the "never completed" claim that also needed fixing.
4. **`RegenerateBuyerMatchesJob`: chunking gate and data-safety PASS; found the
   self-chaining bug documented at the top of this file.** Verified all four
   real dispatch shapes against the live class directly via reflection —
   chunking activates ONLY for `(agencyId=1, contactId=null, truncate=false)`,
   exactly the `PropertyObserver` shape, `false` for every other shape. A real
   dispatch of that shape processed exactly 40 contacts in 6m48s, zero new
   failures. Five sampled untouched contacts (not yet reached by the chunk)
   had byte-identical row counts before and after — no holes. The
   self-chaining continuation did not reach the queue; see the top of this
   file for the full diagnosis and required fix.

**An operational side-effect worth recording, not a bug:** restarting
`corex-worker-live` and `corex-worker-live-buyer-matching` to load the new
code interrupted whatever those workers were mid-processing at that moment,
leaving their DB job reservations "stuck" (not stale by Laravel's own
`retry_after` reckoning) because live's `DB_QUEUE_RETRY_AFTER=3900` (65
minutes) — a pre-existing live-specific config value, not something touched
tonight. Confirmed via `ps`/`/proc` that the current worker processes were
genuinely idle (not silently working on them) before manually clearing
`reserved_at` on the three affected job rows so they could be picked up fresh.
Standard, safe post-restart cleanup — but worth remembering: any worker
restart on live orphans in-flight jobs for up to 65 minutes unless someone
manually releases them, and that number is easy to get wrong if you assume
staging's shorter default.

**Current true state, left in production:**
- Code: `main` at `76716a4c2`, matches `origin/main` exactly, nothing unpushed.
- Both migrations ran, both columns present and confirmed harmless if ever
  rolled back to independently.
- `notifications:scan-deals`: crash fixed, confirmed dispatching nothing new
  with the flag off.
- `OversightDigestJob`: N+1 fixed, confirmed completing, failure count flat
  across the deploy window.
- `SyncProperty24Activations`: bounded to 40 properties per run, rotation
  cursor confirmed advancing on live's real 186-property set, zero new
  failures from a fresh dispatch.
- `RegenerateBuyerMatchesJob`: bounded to 40 contacts per invocation on the
  `PropertyObserver` path, confirmed on live. No upfront wipe (unchanged from
  before — `truncate=false` was already the production shape). No holes in
  existing buyer-match data (verified by sample). Idempotent per-contact
  writes (unchanged from the earlier, separately-proven N+1 fix). Rotation
  now advances via natural `PropertyObserver` triggers rather than the
  intended self-chaining, until the fix at the top of this file lands.
- All queues confirmed drained of anything stuck; `corex.matches.regenerating`
  cache flag confirmed cleared, not held; all supervisor-managed live workers
  confirmed `RUNNING` with healthy uptimes; `php8.3-fpm` confirmed active.
  Nothing half-applied.

**Known housekeeping, not urgent:** a handful of `/tmp/rbm_*`, `/tmp/oversight_*`,
and one `/tmp/p24_chunk_verify.php` measurement scripts from tonight's staging
verification could not be deleted — `rm` was denied outright in this session's
permission mode regardless of target, confirmed by retrying. They contain
internal staging contact IDs/scores, nothing live, nothing secret, and nothing
references them. Safe to clear whenever someone with the right permissions
gets to it.
