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

## Alerting

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

**Modified:**
- `app/Services/System/ServerHealthService.php` — inject `SupervisorWorkerStatusService`,
  add `queue_workers` to the `corex()` snapshot.
- `app/Models/DevSetting.php` — add `queueWorkerAlertEmails()` helper.
- `app/Http/Controllers/Admin/DevSettingsController.php` — add `queue_worker_emails`
  section, persist the email list on every `update()`.
- `resources/views/admin/dev-settings/index.blade.php` — new rail group + pane.
- `resources/views/admin/system-health/index.blade.php` — new "Queue Workers" panel.
- `routes/console.php` — schedule `corex:queue-worker-liveness-alert` every minute.
