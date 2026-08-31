# Queue Worker Monitoring & Alerts

**Status:** Built on QA2, 2026-08-19. Awaiting Johan's QA2 test → Staging → Live promotion.

## What this is and why

On 2026-08-19 a MySQL self-crash (InnoDB semaphore-wait watchdog) took MySQL down for
~90 seconds. Every supervisor-managed `corex-worker-*` process on the live host hit
`Connection refused` on boot, exhausted supervisor's restart-retry budget, and was left
parked in `FATAL` state — which supervisor does **not** auto-recover from even once MySQL
comes back. The workers sat dead for ~9 hours (1,134 jobs backed up: mail, P24 submissions,
webhooks, matching) until a human happened to notice a stuck property and investigate.

Nothing in CoreX previously checked whether a queue worker *process* was actually alive —
`QueueHealthcheck` (`app/Console/Commands/QueueHealthcheck.php`) infers a stall indirectly
from job age in the `jobs` table, logs `Log::critical`, but sends no notification and only
catches a stall after jobs have already piled up for several minutes.

This feature adds direct process-liveness detection (via `supervisorctl status`) and emails
a configured list of recipients the moment any worker goes down.

## Pillars

None of the four pillars (Property/Contact/Deal/Agent) — this is infrastructure/ops
monitoring, not a business-data feature.

## Data model

No migration. Reuses the existing `dev_settings` key/value table (`app/Models/DevSetting.php`):
- New key `queue_worker_alert_emails` — JSON array of lowercased email strings.
  Helper: `DevSetting::queueWorkerAlertEmails(): array`.

## Detection mechanism

`app/Services/System/SupervisorWorkerStatusService.php` shells out to
`sudo -n /usr/local/bin/corex-supervisor-status.sh` (→ `supervisorctl status`) and parses
each `corex-worker-*` process into `{group, process, state, down, detail, environment}`.
`down` is true for state `FATAL|STOPPED|BACKOFF|EXITED|UNKNOWN`.

`environment` is `'staging'` for any group starting `corex-worker-staging`, else `'live'`.
This matters because two groups — `corex-worker-p24import` and `corex-worker-p24images` —
have no `-live-` in their name (unlike every other live lane) despite running
`directory=/corex` in the supervisor conf, i.e. they ARE live. Left unlabeled, that reads as
ambiguous/QA-like at a glance — Johan flagged this after the first version shipped a flat
list. There is no QA1/QA2 in this data at all: those run as separate systemd services
(`corex-qa1-queue.service`, `corex-qa2-queue.service`), invisible to `supervisorctl`.

**Host-level permission grant (already applied to the shared host, not QA2-scoped):**
- `/usr/local/bin/corex-supervisor-status.sh` — root-owned, mode 0755, one line:
  `exec /usr/bin/supervisorctl status`. Status only — no start/stop/restart capability.
- `/etc/sudoers.d/corex-supervisor-status` — `www-data ALL=(root) NOPASSWD:
  /usr/local/bin/corex-supervisor-status.sh`, validated with `visudo -c`.
- Needed because supervisor's control socket is `chmod 0700` root-only — `www-data` (the
  user Laravel/PHP-FPM/queue workers run as, across every environment on this host) has no
  other way to read process state.

## Alerting — queue backlog (`corex:queue-healthcheck`)

**Added 2026-08-22**, from the go-live audit follow-up (`.ai/audits/2026-08-21-go-live-audit-followup.md`
§4): `QueueHealthcheck` was already logging `Log::critical` correctly on every stalled-backlog
detection (confirmed it was never broken), but `LOG_STACK=single` meant those critical logs only
ever reached `storage/logs/laravel.log` — nobody was actually notified. Wired up the same
best-effort-email doctrine already used by `QueueWorkerLivenessAlert` below, applied to
`QueueHealthcheck` instead:

- New key `queue_backlog_alert_emails` on `dev_settings` (JSON array of lowercased emails).
  Helper: `DevSetting::queueBacklogAlertEmails(): array`.
- `QueueHealthcheck::notify()` — same shape as `QueueWorkerLivenessAlert::notify()`: `Log::critical`
  fires unconditionally every run (the guarantee); the email is throttled via
  `Cache::add('queue-backlog-alert', ..., 15 min)` (one alert per stall, re-alerting every 15 min
  while it persists) and wrapped in try/catch so a mail failure never masks the log.
- Mailable: `app/Mail/QueueBacklogAlertMail.php` + `resources/views/emails/queue-backlog-alert.blade.php`.
- Dev Settings — new "Queue backlog emails" section, same rail group ("Alerts"), same add/remove
  email-row UI pattern as "Queue worker emails", submitted as `queue_backlog_alert_emails[]` in the
  hub's shared form.
- Deliberately a **separate** recipient list from `queue_worker_alert_emails`, not reused — a
  worker-down and a backlog-stall are different failure modes an owner may want routed to
  different people; the UI is right next to the other Alerts section either way.
- No `app()->environment('production')` guard needed here (unlike the liveness alert): `QueueHealthcheck`
  reads `DB::table('jobs')`, which is already environment-isolated by each deployment's own DB
  connection — there's no shared-host double-detection risk like `SupervisorWorkerStatusService` has.

### Per-lane thresholds (2026-08-28)

`checkStalledQueue()` originally scanned the WHOLE `jobs` table as one pile and judged it
against a single 600s deadline. That was a correct measure of health for exactly as long as
every job shared the `default` queue. It stopped being one the moment slow work was given
its own lane.

The trigger: on 2026-08-27 `TranscribeVoiceNoteJob` was moved to a dedicated `transcription`
lane so a batch of voice notes could no longer head-of-line-block the fast scheduled work.
That fix worked — but the alarm still counted those notes as one undifferentiated backlog.
A voice note costs ~30-90s of whisper.cpp and box-wide transcription concurrency is exactly 1
by design (`TranscriptionService::WHISPER_LOCK`), so the nightly batch takes ~17 minutes to
drain and ALWAYS parks its own head well past 600s. The alarm fired at 22:15 on 2026-08-26,
08-27 and 08-28 — three consecutive nights — while every worker was RUNNING, `default` was
draining on time, and nothing was wrong. On the 08-28 occurrence the worker log shows 18
notes processed cleanly from 22:03 to 22:19 while the alarm was calling the queue wedged.

A false alarm every night is worse than no alarm: it trains the owner to ignore the one night
it is real, and the alert's own advice (restart the worker) would have killed a note in
flight for no gain. So each lane is now judged against what is normal FOR THAT LANE, via
`config/queue_alerting.php` → `backlog.lanes`.

**Latency lanes** (`default`, `mail`, `webhooks`, `matching`, `buyer-matching`, `p24import`,
`p24images`, `bg_removal`, and any lane not listed) — something is waiting on these, so depth
over time is itself the fault. Behaviour is UNCHANGED: oldest waiting job older than
`--max-age` (600s) alarms, exactly as fast as before. An unrecognised/new lane falls back to
this, so a lane nobody has tuned can never be silently unmonitored.

**Batch lanes** (`requires_progress => true`; currently only `transcription`) — a deep queue
is the normal steady state, so depth proves nothing. What proves a fault is the queue not
MOVING. A batch lane alarms only when the oldest waiting job is past its own `max_age` AND
the head of that lane has not advanced for `progress_window` seconds.

Head-advance is used rather than `reserved_at` deliberately. With `--sleep=3` there is a ~3s
window between a worker finishing one job and reserving the next in which NOTHING on the lane
is reserved; a 5-minute check landing in that window would read a healthy worker as dead
(~1% per run, ~4% across a nightly batch — i.e. it would have reintroduced the very false
alarm this change removes). The head of the waiting queue has no such flicker: it advances
the instant a job is picked up and never moves backwards. The marker lives in the cache
(`queue-healthcheck:lane-head:{queue}`, 6h TTL) and any cache failure reports "just advanced"
— fail-quiet, matching the growth checkpoint's doctrine.

`progress_window` must exceed the longest a single job on that lane can legitimately hold the
head, i.e. that job's own `$timeout`. `TranscribeVoiceNoteJob` has `$timeout = 1200`, so
`transcription` uses 1500. Worker-process DEATH is not this alarm's job in any case:
`corex:queue-worker-liveness-alert` catches a FATAL/STOPPED process within a minute, on a
completely independent mechanism (supervisorctl, not the database).

Two further consequences, both deliberate:

- The email throttle key became **per lane** (`queue-backlog-alert:{queue}`). Under the old
  single global key, a lane that was legitimately noisy would swallow the first page from a
  different lane that had genuinely just died, for up to 15 minutes.
- `QueueBacklogAlertMail` now carries the lane, that lane's own threshold, and the supervisor
  program for that lane. Previously every backlog email read identically no matter which lane
  was stalled and told the reader to restart `corex-worker-live:*` — wrong, and actively
  misleading, for all seven other lanes. The lane→program map was read off
  `/etc/supervisor/conf.d/*.conf` on the live host, 2026-08-28.

**Acceptance criteria**

- [x] The exact 08-28 22:15 condition (23 jobs on `transcription`, oldest 713s) is silent.
- [x] A batch lane past its own threshold but still moving is silent.
- [x] A batch lane whose head has not advanced past `progress_window` alarms.
- [x] A latency lane at 700s still alarms, unchanged.
- [x] A deep batch lane never masks or delays a real stall on another lane.
- [x] Each lane's alert email is throttled independently.
- [x] An unknown lane falls back to the 600s latency default.
- [ ] Observed silent on live through a real 22:00 voice-note batch (first opportunity
      2026-08-29 22:00 SAST).

## Alerting — worker liveness (`corex:queue-worker-liveness-alert`)

`app/Console/Commands/QueueWorkerLivenessAlert.php` (`corex:queue-worker-liveness-alert`),
scheduled `everyMinute()->withoutOverlapping()->onOneServer()` in `routes/console.php`,
alongside the existing `queue-healthcheck` schedule entry — but guarded behind
`if (app()->environment('production'))`. Live (`/corex`, APP_ENV=production) and Staging
(`/corex-staging`, APP_ENV=staging) are separate deployments of this same codebase, each
already running their own `schedule:run` via cron every minute (confirmed
`crontab -u root -l`, 2026-08-19). This check reads supervisor status for the WHOLE shared
host — both live and staging worker groups — so without the guard, once this code reaches
both environments, both schedulers would independently detect the same down process and
(each environment has its own cache store, so the per-process throttle never crosses
environments) send duplicate alert emails for one real incident. The Server Health panel and
Dev Settings page stay fully usable on every environment regardless — only the
cron-triggered check is single-sourced, to Live.

Doctrine (matches `PermissionLockdownAlarm`, AT-265): **the log is the guarantee, the email
is best-effort.** `Log::critical` fires unconditionally for every down process, every run.
Email is throttled per-process via `Cache::add('queue-worker-alert:{process}', ..., 15 min)`
— first detection sends immediately; a worker still down 15 minutes later re-alerts; already-
alerted processes within the window are skipped so a long outage doesn't spam. No recipients
configured → logs a warning, sends nothing (never throws).

Mailable: `app/Mail/QueueWorkerDownMail.php` + `resources/views/emails/queue-worker-down.blade.php`
(Laravel markdown mail component, matches `OversightNudgeMail`/`emails/oversight-nudge.blade.php`).
Lists each down process, its state, and a link to Server Health.

## UI placement

- **Server Health** (`/admin/system-health`, existing page, `view_server_health` permission) —
  new "Queue Workers" panel, added to the existing 10s-poll JSON payload
  (`ServerHealthService::corex()` gained a `queue_workers` key; no new route — stays under
  the existing `GET /api/v1/system-health` endpoint per Non-Negotiable #7). Grouped into two
  sections with an explicit LIVE / STAGING badge and a one-line description under each
  ("corexos.co.za — real agents, real data" / "staging.corexos.co.za") — not a flat process
  list, so it's never ambiguous which environment a down worker belongs to. Green/red dot per
  process. The alert email's table and subject line (`[CoreX][LIVE+STAGING] N down`) carry
  the same environment labelling.
- **Dev Settings** (`/admin/dev-settings`, existing page, `owner_only` middleware) — new
  "Queue worker emails" section (rail group "Alerts"). Add/remove email rows (Alpine.js),
  submitted as `queue_alert_emails[]` in the hub's single shared form (same "one form writes
  every section every time" contract the page already uses for Compliance/Demo — the pane
  stays in the DOM via `x-show`, not removed, so a save from any pane still carries this
  list). Validated server-side (`email:filter`), deduped, lowercased, stored as JSON.

No new pages were added, so Non-Negotiable #2 (nav entry same day) doesn't apply — both
parent pages already have nav entries.

## Permissions

No new permission key. Both host pages are already gated (`view_server_health` for Server
Health; `owner_only` middleware for the whole Dev Settings hub) and this rides on those
existing gates, consistent with how the other Dev Settings sections (`compliance`, `demo`)
work — neither has its own permission key either.

## Deliberately NOT in the Agency Onboarding Setup Wizard

Per Non-Negotiable #10a: `dev_settings` is an owner-only internal ops/dev configuration
space (existing sections: compliance overrides, demo mode) — it is never agency-facing and
agencies never see or configure it. This setting is the same category. Not a wizard candidate.

## User flow

1. Owner opens Dev Settings → Alerts → Queue worker emails, adds one or more addresses, saves.
2. Every minute, the scheduler runs the liveness-alert command.
3. If any `corex-worker-*` process is down: `Log::critical` fires; if this is a fresh
   detection (or the 15-minute re-alert window has elapsed) and at least one recipient is
   configured, an email goes out listing the down worker(s), their state, and a link to
   Server Health.
4. Server Health's existing "Queue Workers" panel shows live per-process state (green
   RUNNING / red down) on the same 10s poll as everything else on that page.

## Acceptance criteria

- [x] `SupervisorWorkerStatusService::status()` correctly parses `supervisorctl status`
      output and classifies down states.
- [x] Server Health page shows a "Queue Workers" panel with live per-process status.
- [x] Dev Settings has a "Queue worker emails" section; emails persist across saves from any
      pane (shared-form contract preserved); invalid input is rejected server-side and lands
      back on the right pane with an inline error.
- [x] `corex:queue-worker-liveness-alert` logs critical on every down process every run, and
      emails configured recipients on first detection + every 15 min while still down (not
      every minute).
- [x] Host sudoers grant is read-only (`supervisorctl status` only) and validated with
      `visudo -c`.
- [ ] Johan verifies on QA2 (simulate a down worker, confirm the email arrives and the panel
      goes red), then explicit go for Staging → Live.

## Files created/modified

**Created:**
- `app/Services/System/SupervisorWorkerStatusService.php`
- `app/Console/Commands/QueueWorkerLivenessAlert.php`
- `app/Mail/QueueWorkerDownMail.php`
- `resources/views/emails/queue-worker-down.blade.php`
- `.ai/specs/queue-worker-monitoring.md` (this file)
- `/usr/local/bin/corex-supervisor-status.sh` (host, not in repo)
- `/etc/sudoers.d/corex-supervisor-status` (host, not in repo)
- `app/Mail/QueueBacklogAlertMail.php` (2026-08-22)
- `resources/views/emails/queue-backlog-alert.blade.php` (2026-08-22)
- `app/Support/Queue/QueueFailureAlerter.php` (2026-08-23) — real `Queue::failing()` alerting;
  see `.ai/audits/2026-08-23-queue-failed-jobs-triage.md` for the full investigation.
- `app/Mail/QueueJobFailureDigestMail.php` + `resources/views/emails/queue-job-failure-digest.blade.php` (2026-08-23)
- `app/Mail/QueueFailedJobsGrowthAlertMail.php` + `resources/views/emails/queue-failed-jobs-growth-alert.blade.php` (2026-08-23)

**Modified:**
- `app/Services/System/ServerHealthService.php` — inject `SupervisorWorkerStatusService`,
  add `queue_workers` to the `corex()` snapshot.
- `app/Models/DevSetting.php` — add `queueWorkerAlertEmails()` helper (2026-08-22: also
  `queueBacklogAlertEmails()`).
- `app/Http/Controllers/Admin/DevSettingsController.php` — add `queue_worker_emails`
  section, persist the email list on every `update()` (2026-08-22: also `queue_backlog_emails`).
- `resources/views/admin/dev-settings/index.blade.php` — new rail group + pane (2026-08-22: also
  the "Queue backlog emails" pane).
- `resources/views/admin/system-health/index.blade.php` — new "Queue Workers" panel.
- `app/Console/Commands/QueueHealthcheck.php` (2026-08-22) — wire `notify()` to email
  `queue_backlog_alert_emails` on a stalled backlog, throttled 15 min. (2026-08-23: added
  `checkFailedJobsGrowth()` — a failing job is deleted from `jobs` the instant it fails, so
  the oldest-waiting-job check alone reported a rapidly-failing queue as healthy. Tracks
  failed_jobs COUNT growth between runs via a cached checkpoint, not the cumulative total.)
- `routes/console.php` — schedule `corex:queue-worker-liveness-alert` every minute.
- `app/Providers/AppServiceProvider.php` (2026-08-23) — `Queue::failing()` previously only
  popped the audit-context stack; now also calls `QueueFailureAlerter::handle()`.
- `app/Jobs/Syndication/DesyndicatePropertyFromPortalsJob.php` (2026-08-23) — added a
  bulk-retry hazard warning to the class docblock (see the audit above, §3).
