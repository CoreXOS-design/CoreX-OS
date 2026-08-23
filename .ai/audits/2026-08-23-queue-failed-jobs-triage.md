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

## Files created/modified (2026-08-23)

**Created:**
- `app/Support/Queue/QueueFailureAlerter.php`
- `app/Mail/QueueJobFailureDigestMail.php` + `resources/views/emails/queue-job-failure-digest.blade.php`
- `app/Mail/QueueFailedJobsGrowthAlertMail.php` + `resources/views/emails/queue-failed-jobs-growth-alert.blade.php`
- `tests/Feature/Queue/QueueFailureAlertingTest.php`, `tests/Feature/Queue/QueueHealthcheckFailedJobsGrowthTest.php`
- `app/Console/Commands/ArchiveAndDiscardOversightNudgeFailures.php` — the archive-then-discard
  tool for §1a. Dry-run by default; `--execute` required to actually archive/delete. Not run
  against the real backlog anywhere yet — awaiting Johan's explicit go for the live cherry-pick.
- This file.

**Modified:**
- `app/Mail/OversightNudgeMail.php`, `app/Mail/FeedbackReportMail.php` — `view:` → `markdown:`.
- `app/Providers/AppServiceProvider.php` — `Queue::failing()` wired to `QueueFailureAlerter`.
- `app/Console/Commands/QueueHealthcheck.php` — added `checkFailedJobsGrowth()`.
- `app/Jobs/Syndication/DesyndicatePropertyFromPortalsJob.php` — bulk-retry hazard warning.
- `.ai/specs/queue-worker-monitoring.md` — changelog entry for the above.
