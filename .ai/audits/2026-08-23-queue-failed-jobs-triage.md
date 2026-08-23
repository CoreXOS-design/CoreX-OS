# Queue failed_jobs triage — 2026-08-23

Investigation into a 16,882-row (live) `failed_jobs` backlog, requested after Johan found
`Queue::failing()` did nothing but pop an audit-context stack. Root-caused and fixed the
largest single failure class, investigated the other four, rebuilt real alerting, and closed
a detection blind spot in `QueueHealthcheck`. Staging only — nothing deployed to live from
this investigation.

## 1. OversightNudgeMail (10,356 rows) — root cause found and fixed

`app/Mail/OversightNudgeMail.php` used `Content(view: 'emails.oversight-nudge')` for a
template written in Laravel's markdown-component syntax (`@component('mail::message')`).
That view namespace is only registered by the `Markdown` renderer, which `Content(view:)`
never routes through — every send threw `InvalidArgumentException: No hint path defined for
[mail]`. Fixed by switching to `Content(markdown: ...)`, matching the pattern already correct
in `QueueWorkerDownMail`/`QueueBacklogAlertMail`. Same bug found and fixed in
`app/Mail/FeedbackReportMail.php` (not in the reported top-5, silently broken the same way).

Proven by rendering against real staging data and a real end-to-end queued send (log driver).
See `app/Support/Queue/QueueFailureAlerter.php`'s own docblock and the 2026-08-23 commits on
`Staging` for the full change.

### 1a. Backlog decision — DISCARD, not retry (Johan, 2026-08-23)

The 10,356 (live) / 9,961 (staging) `failed_jobs` rows for this class are to be **archived
then deleted, never retried**. Written down here because whoever finds a 10,000-row archive
file a year from now should be able to see this was a decision, not neglect.

**Why discard rather than retry, once the fix is live:** these are automated "action
required" nudges to real managers, some many weeks old by the time any retry would run.
Retrying would not "catch up" a manager on 10,356 items — it would land as a burst of stale
alerts about things many of them will already have handled through other means in the
meantime, with no way for the recipient to tell an urgent current nudge from a weeks-late
echo. **A stale automated nudge arriving weeks late is worse for a client relationship than
the nudge never having arrived at all** — it reads as CoreX being broken or ignored, not as
CoreX catching up. Nothing real is lost by discarding: `OversightDigestJob` runs hourly and
re-evaluates every manager's outstanding items on its own idempotency key (manager, category,
subject, within the configured threshold window) — anything still genuinely outstanding gets
a fresh, correctly-timed nudge on its own, not a batch of backdated ones.

**Mechanics, when Johan authorises the live cherry-pick** (not yet — see safety contract
below): `php artisan corex:archive-discard-oversight-nudge-failures`
(`app/Console/Commands/ArchiveAndDiscardOversightNudgeFailures.php`). Scoped exactly to
`payload->displayName = 'App\Mail\OversightNudgeMail'` (JSON-path exact match, not a LIKE
substring) — provably never touches `SyncProperty24Activations`,
`RegenerateBuyerMatchesJob`, `OversightDigestJob`, or `DesyndicatePropertyFromPortalsJob`
rows, which remain the evidence of real unresolved problems (§2, §3). Defaults to a dry run
(prints the exact SQL and row count, changes nothing) unless `--execute` is passed. When
executed: archives every matching row in full to a JSON file on the data volume
(`/mnt/HC_Volume_103099143/corex-backups/` by default), verifies the written file's row count
against the query before deleting anything, then deletes by the exact row ids just archived
(not a second run of the same WHERE clause). Mechanism proven correct on staging 2026-08-23
via an isolated synthetic-data test (3 target rows + 2 real-class-name control rows) —
precise scoping confirmed, real backlog and control rows both left untouched. The real
9,961-row staging backlog itself was deliberately NOT archived/deleted in that proof, pending
explicit instruction — dry-run only was run against it.

**EXECUTED on staging, 2026-08-23 06:35, Johan's explicit go.** Default `--path` fixed first
— `corex-backups/` is `root:root` 755 (holds root-run mysqldump backups), so the command's own
first `--execute` attempt (as `www-data`, correctly — never artisan-as-root on a served
checkout) failed at the `file_put_contents` step with Permission Denied, **before reaching the
delete step** — nothing was touched by that failed attempt. Fixed by creating a dedicated
`corex-backups/queue-job-archives/` subdirectory owned by `www-data` and repointing the
command's default there (`app/Console/Commands/ArchiveAndDiscardOversightNudgeFailures.php`).

Archived 9,961 rows to
`/mnt/HC_Volume_103099143/corex-backups/queue-job-archives/failed-oversight-nudges-staging-20260823-063515.json`
(151MB, row count verified against the query before any delete ran), then deleted the same
9,961 rows by their exact archived ids. Before/after counts for the other four classes,
confirming precise scoping held on the real backlog, not just the synthetic proof:

| Class | Before | After |
|---|---|---|
| SyncProperty24Activations | 3,947 | 3,947 |
| RegenerateBuyerMatchesJob | 1,052 | 1,052 |
| OversightDigestJob | 598 | 598 |
| DesyndicatePropertyFromPortalsJob | 712 | 712 |
| OversightNudgeMail | 9,961 | 0 |
| **Total failed_jobs** | **16,420** | **6,459** |

16,420 − 9,961 = 6,459 exactly — arithmetic and per-class counts both confirm nothing else
moved. **Live's equivalent backlog (10,356 rows) has NOT been touched** — this ran on staging
only; live requires Johan cherry-picking this himself, separately, per his instruction.

### 1b. Deployment consideration — this is not "cleaning up test junk"

**Flagging this because Johan's own working assumption going in was wrong, and the correction
matters more than the row count.** These were never leftover e-sign test emails. They are real
manager-facing oversight nudges, and — per §1 — **they have never once been delivered
successfully in production**, since the day the feature shipped. The actual finding here is
not "10,356 junk rows cleaned up"; it is **a manager-facing notification feature has been
silently dead for its entire life, and nobody knew, because nothing surfaced the failure**
(that gap is what §5's `Queue::failing()` rewrite exists to close going forward).

**Consequence for the live deploy, specifically:** the moment the mail-namespace fix reaches
live, `OversightDigestJob`'s hourly run will start successfully delivering nudges to managers
who have never received one before — not "resuming" a feature they know, but a notification
type appearing in their inbox with no prior warning. Without context, this will read to a
manager as either a brand-new, unannounced feature, or as CoreX suddenly starting to email
them unprompted — neither is the impression this feature was originally supposed to make.
**Someone should tell the managers this is coming before it goes live, not after** — a heads-up
that "oversight nudges" is a real, sanctioned CoreX notification, not spam and not new. This is
a rollout/communications step, not a code change, and outside what a deploy can do on its
own — recorded here so it isn't lost between "the code is ready" and "the code is live."

## 2. The other four failure classes — investigated, not bulk-fixed

None share the mail-namespace root cause. Do not treat these as fixed by the mail fix above.

| Class | failed_jobs count | Real exception | Verdict |
|---|---|---|---|
| `App\Jobs\SyncProperty24Activations` | 3,951 | `MaxAttemptsExceededException` (`tries=1`, 300s timeout — a timeout with `tries=1` reports this way, not `TimeoutExceededException`) | Genuine scale problem — sync workload has outgrown its timeout. Needs profiling, not a one-line fix. |
| `App\Jobs\RegenerateBuyerMatchesJob` | 1,075 | `TimeoutExceededException` (600s) | Same pattern. The no-agency-id "master rebuild" path (per the job's own docblock) is plausibly the one blowing the timeout as data volume grows. |
| `App\Jobs\Syndication\DesyndicatePropertyFromPortalsJob` | 710 | `RuntimeException: Desyndication failed for property #N: p24:HTTP 401` | Recurring P24 auth rejection, **April–August 2026, 1,072 total 401-tagged failures across the window** — not a one-time credential blip. **See the bulk-retry hazard section below — this is the one that matters most.** |
| `App\Jobs\OversightDigestJob` | 637 | `TimeoutExceededException` | **Not caused by the mail bug** — it queues `OversightNudgeMail` via `Mail::queue()`, which doesn't block on the send. See §4 below — this is a separate, real design problem. |

## 3. Bulk-retry hazard — DesyndicatePropertyFromPortalsJob (710 rows)

**This job must never be bulk-retried.** Written permanently into the job's own docblock
(`app/Jobs/Syndication/DesyndicatePropertyFromPortalsJob.php`) so it is visible to anyone
about to act on this backlog, not just readable here.

This job removes a property from live syndication portals (Property24, Private Property,
agency websites). A failure recorded months ago does not mean de-syndication is still the
correct action today — the property may since have been re-listed, sold, or had its status
legitimately changed by an agent. Retrying blind risks **de-listing a property that should
currently be live** — a real, external, business-facing harm to a real agency, not a
technical inconvenience recoverable by re-running a job. Any retry of this class's
`failed_jobs` backlog requires per-property confirmation of current status first. No bulk
`php artisan queue:retry` sweep on this class, ever, by anyone, without that review.

Contrast deliberately with §1a: OversightNudgeMail's backlog is safe to discard because the
cost of being wrong is a manager missing (or re-receiving late) an internal notification —
recoverable, low-stakes, and self-healing via the hourly digest. This job's backlog is the
opposite: the cost of being wrong is an incorrect, externally-visible, real-world action
against a live listing. Never bulk-act on this one; the other one is fine to bulk-discard
once archived. Same backlog, opposite treatment, for a reason — not an inconsistency.

## 4. Recurring pattern — OversightDigestJob's unbounded pull + per-row PHP work

`app/Jobs/OversightDigestJob.php:35-89` (`handle()`). The job pulls **every** user with a
non-null `agency_id` into memory (`User::query()->whereNotNull('agency_id')->get()`), filters
in PHP via `hasPermission()` per user, then for every manager calls `OversightService::feed()`
and for every row in that feed runs an `OversightNudge::exists()` query — an unbounded,
all-agencies, single-job-run design with per-row query cost that scales with total user count
× feed size, not with what actually changed since the last run.

This is the **third instance we've now found of the same underlying pattern**: unbounded pull
of a full table/collection, followed by per-row work (often N+1 queries) done in PHP inside a
single job run, with no batching or incremental/delta processing. Naming it here so it's
watched for as a class of problem, not re-diagnosed from scratch each time it resurfaces
elsewhere in the codebase.

**Redesign needed** (not done here — this is a real change, not a one-liner): scope the run to
agency and/or manager batches (dispatch one job per agency/manager instead of one job for
every agency), and replace the per-row `OversightNudge::exists()` check with a single
up-front query that pulls all recently-alerted (manager, category, subject) tuples once and
checks against an in-memory set, instead of one query per feed row. Whoever picks this up
should profile against a realistic manager/agency count before re-tuning the timeout — raising
`$timeout` alone would hide the scaling problem, not fix it.

## 5. Alerting fixed

- `Queue::failing()` (`app/Providers/AppServiceProvider.php:196`) now calls
  `App\Support\Queue\QueueFailureAlerter::handle()` — `Log::critical` fires unconditionally on
  every failure (job class, queue, connection, exception class, exception message), never
  routed through mail, so it can't be taken down by the same failure it reports. A
  per-job-class digest email is debounced to one per 15-minute window regardless of failure
  volume, reusing the existing `DevSetting::queueBacklogAlertEmails()` recipients and the
  "log is the guarantee, email is best-effort" doctrine already established by
  `QueueHealthcheck`/`QueueWorkerLivenessAlert`.
- `QueueHealthcheck` (`app/Console/Commands/QueueHealthcheck.php`) gained
  `checkFailedJobsGrowth()` — the original oldest-waiting-job check alone reports a queue as
  healthy while it fails fast, because a failed job is deleted from `jobs` the instant it
  fails. Growth is tracked between runs via a cached checkpoint (not the cumulative total), so
  the existing large backlog does not itself pin the check to permanently unhealthy — only
  *new* growth since the last run does.

## 6. ParseDocumentJob — confirmed dead code

`app/Jobs/ParseDocumentJob.php` has zero references anywhere outside its own file (dispatch
side and its `import_job_*` cache-key polling contract both orphaned). The real, shipped
Document Importer (`app/Http/Controllers/Docuperfect/DocumentImporterController.php`, same
original commit `bd571694b`) calls `DocxParserService` directly and synchronously at both its
call sites — this job was never wired into it. Reads as abandoned async scaffolding from a
design pivot within the same original build. Not deleted — batched with other cleanup for
Johan to approve separately.

## 7. Nudge emails held OFF by kill switch — volume analysis (2026-08-23, later same day)

**Change of requirement, Johan:** the mail fix working is not the same as it being safe to turn
on. CoreX's original live cutover flooded staff with email; a manager-facing feature that has
never once fired in production, switched on with zero prior volume data, risked repeating that
at real managers' inboxes. New instruction: fix the bug, but do not let it send — add a
config-driven kill switch, default OFF, and produce real volume numbers before anyone decides
whether to turn it on.

**Kill switch**: `config/oversight.php` `nudges_enabled`, default `false` via
`OVERSIGHT_NUDGES_ENABLED` — same doctrine as `docuperfect.async_completion`. Gates ONLY the
`Mail::to(...)->queue(new OversightNudgeMail(...))` call inside `OversightDigestJob::run()`
(refactored from `handle()` so the same evaluation logic can run read-only). **Not** a
dispatch-and-short-circuit design — nothing is queued at all while off, because a queued
Mailable is processed by Laravel's own internal `SendQueuedMailable` job with no clean
framework hook to abort mid-flight, so not queuing in the first place is simpler and by
construction can never produce a `failed_jobs` row. The `OversightNudge` idempotency row and
the in-app `DatabaseNotification` are deliberately **unaffected** by the flag and keep
recording normally — if they didn't, every nudge that "should" have fired during the off
period would look brand new the moment this is switched on and all fire in one burst, which is
the exact flood this flag exists to prevent.

**Proven on staging, flag OFF**: ran the real `OversightDigestJob::run(persist: true)` — the
exact hourly production code path. 2,684 nudge-worthy items evaluated, 334 in email-channel
categories. `jobs` table: 0→0. `failed_jobs`: 6,459→6,459. `OversightNudgeMail` jobs queued: 0.
`Mail::assertNothingSent()`: pass.

**Proven on staging, flag ON**: flipped the flag, queued a real `OversightNudgeMail` (using a
real nudge the flag-off run had just created) to `proof-test@corexos.invalid` — never a real
manager. Sent cleanly through the real queue (83ms), zero new `failed_jobs` rows.

### The volume answer — this is the real finding

The flag-off run above persisted real `OversightNudge` rows, which is the most authoritative
possible source (real managers, real current data, not a simulation):

| | |
|---|---|
| Nudge-worthy items, one run (any channel) | 2,684 |
| Email-channel items, one run | 334 |
| Distinct managers who would receive email, one run | 5 |
| **Worst case, single manager, one run** | **82 emails** (manager #44, angelique@hfcoastal.co.za — 64 `deals_near_expiry` + 18 `expiring_mandates`) |
| Next worst | 76, 76, 75 emails (three more managers) |

**Does idempotency prevent repeat nudges across runs, or would a manager get the same nudge
every hour?** Not hourly — but this needs the fuller picture, and it is the more concerning
half of the answer. `OversightDigestJob` runs hourly (`routes/console.php:305`,
`Schedule::job(...)->hourly()`), and the idempotency check does correctly prevent the exact
same (manager, category, subject) triple from re-firing inside its threshold window. BUT: the
threshold lookup (`OversightDigestJob::run()`, the `$pref->threshold_hours ?? 24` line) falls
back to a **flat 24 hours** when no `UserOversightPreference` row exists for that manager —
**not** the per-category default (168h/336h/720h for
deals_near_expiry/expiring_mandates/expiring_ffcs) the way `OversightService::feed()` correctly
does at its own threshold lookup. **Zero `UserOversightPreference` rows exist on staging at
all**, so every category currently runs on the flat 24h window regardless of its intended
cadence. Net effect: a manager will not be re-nudged for the same item within the same hour,
but almost certainly WILL be re-nudged for the same still-outstanding item the next day, and
the day after that, for as long as the underlying deal/mandate/FFC stays in its "near expiry"
window — which for `expiring_ffcs` (intended 30-day cadence) or `expiring_mandates` (intended
14-day cadence) could be weeks of daily repeats for the same item. **Manager #44's 82 emails
in one run is plausibly close to their daily total, sustained, not a one-time burst** — worse
than "flood every hour", but the flood recurs daily instead of hourly. This is reported, not
fixed — correcting the fallback is a product decision about intended re-nudge cadence, not a
mechanical bug fix, and belongs in the same conversation as the enable decision, not silently
changed underneath it.

**Recommendation to Johan, not a decision made here:** before enabling, the flat-24h-fallback
finding above should be resolved one way or another (either fix the fallback to genuinely use
the per-category defaults, or make a deliberate call that 24h is fine) — otherwise "turning it
on" means the very first day produces sustained ~80-email/day volume for at least one manager,
which is close to the outcome this whole exercise exists to avoid.

Repeatable, non-mutating tool for re-checking volume later without side effects:
`php artisan corex:oversight-nudge-volume-report` (`app/Console/Commands/OversightNudgeVolumeReport.php`)
— runs the same evaluation read-only, writes and sends nothing, safe to run as often as needed.

## 8. Live deploy attempt, 2026-08-23 ~19:30 — rolled back, root cause understood, redeploy pending

Deployed the whole batch to live (tag `pre-queue-alerting-batch-20260823-1930` on `d2da51f27`,
merged through `bb647129e`), ran a four-part verification gate. Rolled back (clean `git revert`,
commit `29834be21`, not a manual working-tree patch or `git reset --hard`) after one of the four
checks came back wrong. Root cause investigated and understood before any redeploy decision.

**The lesson worth keeping, more than the incident itself:** GATE 1 — the one that actually
mattered, whether a REAL forced failure on live sends email — PASSED, with direct evidence.
Triggered a genuine failure, read the actual live log file (not a mock, not an assertion
library — those don't exist in a production `composer install`) and found the real
`production.CRITICAL` entry, then confirmed the alert digest's debounce cache key was never
set, proving the code returned before ever reaching the point where it would consider sending
mail. `a.roets12@gmail.com` (the real, live-configured recipient) received nothing.

The check that failed was a secondary one — total `jobs` table row count, before vs. after a
~90-second test window. It went 2→3. Investigated from the safe, rolled-back position rather
than reasoned past on live: the real live worker log
(`/corex/storage/logs/worker.log`) shows `App\Jobs\SyncProperty24Activations` running on a
clean, unbroken 15-minute cadence — 18:45:06, 19:00:43, 19:15:06, 19:30:07, **19:45:07** — the
last one landing exactly inside the test window, matching its pre-existing
`everyFifteenMinutes()` schedule (`routes/console.php:213`) precisely. Nothing to do with
today's code. The batch's own actual claim — zero `OversightNudgeMail` jobs queued with the
switch off — was 0→0 throughout, correct the whole time.

**That is the right way round for a gate to be wrong.** A too-strict gate (measuring total
table depth on a system that's constantly and legitimately writing to that table) cost one
redeploy cycle — inconvenient, ten minutes. A too-loose gate — one that would have let a real
problem through because it wasn't watching closely enough — would have cost Johan's staff an
inbox full of mail on the one evening he'd said twice to keep it quiet. Err toward the gate
that over-reacts to noise, never the one that under-reacts to a real signal.

**The fix is to the verification method, not the deployed code.** Any future check of "did
this deploy queue anything new" must scope to the specific job class under test
(`payload->displayName = '<Class>'`), never to the raw row count of a table a live system
writes to continuously for unrelated reasons. The application code did not need fixing —
the test needed fixing.

Batch currently on the shelf, not live. `origin/main` at `29834be21`. Redeploy is a clean
`git revert 29834be21` (or re-cherry-pick the original 10, order already proven:
`138f4d262 → 51d26cfb1 → 047a0c1b3 → 3ee5749e8 → 923148d58 → 0c4d90488 → afa9be14b →
4d1786c5b → 3dd6a8c47 → ab53e28c9`), pending Johan's call on tonight vs. tomorrow.

## Files created/modified (2026-08-23)

**Created:**
- `app/Support/Queue/QueueFailureAlerter.php`
- `app/Mail/QueueJobFailureDigestMail.php` + `resources/views/emails/queue-job-failure-digest.blade.php`
- `app/Mail/QueueFailedJobsGrowthAlertMail.php` + `resources/views/emails/queue-failed-jobs-growth-alert.blade.php`
- `tests/Feature/Queue/QueueFailureAlertingTest.php`, `tests/Feature/Queue/QueueHealthcheckFailedJobsGrowthTest.php`
- `app/Console/Commands/ArchiveAndDiscardOversightNudgeFailures.php` — the archive-then-discard
  tool for §1a. Dry-run by default; `--execute` required to actually archive/delete.
  **Executed on staging 2026-08-23 — see §1a's own update for the result.**
- `config/oversight.php`, `app/Console/Commands/OversightNudgeVolumeReport.php` — the kill
  switch and volume-report tool for §7.
- This file.

**Modified:**
- `app/Mail/OversightNudgeMail.php`, `app/Mail/FeedbackReportMail.php` — `view:` → `markdown:`.
- `app/Providers/AppServiceProvider.php` — `Queue::failing()` wired to `QueueFailureAlerter`.
- `app/Console/Commands/QueueHealthcheck.php` — added `checkFailedJobsGrowth()`.
- `app/Jobs/Syndication/DesyndicatePropertyFromPortalsJob.php` — bulk-retry hazard warning.
- `.ai/specs/queue-worker-monitoring.md` — changelog entry for the above.
- `app/Jobs/OversightDigestJob.php` — refactored `handle()` into `run($service, $persist)`;
  gated the `Mail::queue()` call behind `oversight.nudges_enabled` (§7).
