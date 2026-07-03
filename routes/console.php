<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('rentals:test-inclusion {branchId} {periodStart} {periodEnd}', function ($branchId, $periodStart, $periodEnd) {

    $svc = new \App\Services\Rentals\RentalWorksheetInclusionService();

    $result = $svc->calculateForBranchPeriod(
        (int)$branchId,
        $periodStart,
        $periodEnd
    );

    $this->info("Rental Inclusion Test Result:");
    $this->line("Branch ID: " . $branchId);
    $this->line("Period: " . $periodStart . " to " . $periodEnd);
    $this->line("");

    foreach ($result as $key => $value) {
        $this->line(str_pad($key, 30) . ": " . $value);
    }

})->purpose('Test rental worksheet inclusion service safely');

// P24 alert email import — runs hourly
Schedule::command('p24:import')->hourly();

// P24 location tree (provinces → cities → suburbs) — daily full refresh +
// stamp-and-sweep at 11:00. Keeps p24_verified_at current on everything P24
// returns and soft-deletes anything it no longer returns, so the location tree
// can never drift stale (AT-105/AT-106). withoutOverlapping guards the ~minutes
// -long walk against a slow run still in progress.
Schedule::command('p24:sync-locations')->dailyAt('11:00')->withoutOverlapping();

// Article pool scraper — runs daily
Schedule::command('articles:scrape')->daily();

// Signature reminders — runs daily at 08:00
Schedule::command('signatures:send-reminders')->dailyAt('08:00');

// Lease expiry checks — runs daily at 06:00
Schedule::command('signatures:check-lease-expiry')->dailyAt('06:00');

// Expire outstanding signature requests — runs daily at 07:00
Schedule::command('signatures:expire')->dailyAt('07:00');

// Sales document reminders — runs daily at 09:00
Schedule::command('sales-documents:send-reminders')->dailyAt('09:00');

// AT-168 Part B — POPIA embargo purge: remove un-consented WhatsApp bodies past
// each agency's retention window (envelopes retained). Runs daily at 03:30.
Schedule::command('communications:purge-embargoed-bodies')->dailyAt('03:30')->withoutOverlapping();

// AT-163 — voice-note transcription batch. Hourly; each run processes agencies
// whose configured nightly time (default 22:00, clear of the 03:30 backup) matches
// the current hour. CPU-nice'd inside the worker.
Schedule::command('communications:transcribe-voice-notes')->hourly()->withoutOverlapping();

// Marketing insights sync — runs daily at 04:00
Schedule::job(new \App\Jobs\SyncMarketingInsightsJob())->dailyAt('04:00');

// Phase 8 — daily outcome-capture nudges (>30d old presentations with no outcome).
Schedule::job(new \App\Jobs\PromptOutcomeCaptureJob())->dailyAt('08:30')->withoutOverlapping();
// Phase 8 — daily auto-lock for outcomes recorded >90d ago.
Schedule::job(new \App\Jobs\LockOldOutcomesJob())->dailyAt('02:45')->withoutOverlapping();
// Phase 9a — POPIA 90-day retention for presentation_snapshot_views.
Schedule::job(new \App\Jobs\PurgeOldSnapshotViewsJob())->dailyAt('03:15')->withoutOverlapping();
// Phase 9d — RCR deadline reminder cadence (weekly → 3-daily → daily → critical).
Schedule::job(new \App\Jobs\RcrDeadlineReminderJob())->dailyAt('07:00')->withoutOverlapping();

// Agency Public API — re-dispatch due agency-website webhook retries.
// Spec: .ai/specs/agency-public-api.md §6.2.
Schedule::command('webhooks:retry-due')->everyMinute()->withoutOverlapping();

// Prospecting claim maintenance — runs hourly
Schedule::command('prospecting:maintain-claims')->hourly();

// Module 6 (M6.4) — auto-revoke stale provisional auto_calendar points
// rows whose feedback never arrived inside the mapping's
// auto_revoke_after_hours window. Idempotent; safe to run hourly.
Schedule::command('activity-points:auto-revoke-stale')->hourly()->withoutOverlapping();

// Carry forward targets from previous month — runs on the 1st at 00:05
Schedule::command('targets:carry-forward')->monthlyOn(1, '00:05')->withoutOverlapping();

// Core Matches — archive matches with no engagement, mark fulfilled where the
// contact has a recent deal. Daily at 03:00.
Schedule::command('corex:matches:archive-stale')->dailyAt('03:00')->withoutOverlapping();

// Core Matches — the single daily digest email. Coalesces every new match
// surfaced since the last run into ONE email per agent (never one per property).
// The in-app bell stays real-time; only the email is batched. Daily at 07:00.
Schedule::command('corex:matches:send-digests')->dailyAt('07:00')->onOneServer()->withoutOverlapping();

// Agency Access Authorization — expire stale pending requests every minute.
Schedule::command('agency-access:expire')->everyMinute()->withoutOverlapping();

// AT-118 — Communications Access Gate: midnight reset of all live grants
// (closes the never-closed-session loophole) + expire stale pending requests.
Schedule::command('comms-access:reset')->dailyAt('00:00')->withoutOverlapping();

// Private Property activation polling — runs every 15 minutes
Schedule::job(new \App\Jobs\SyncPrivatePropertyActivations())->everyFifteenMinutes()->withoutOverlapping();

// Communication Archive (AT-32) — nightly retention + inbound-grace maintenance.
// 5-year soft-purge of the archive index, and attach/prune of inbound pending.
Schedule::command('communications:prune-retention')->dailyAt('03:20')->withoutOverlapping();
Schedule::command('communications:prune-pending')->dailyAt('03:35')->withoutOverlapping();

// AT-59 — soft-purge orphaned provisional outbound rows (an edited-before-send
// click that never reconciled to a real send). Hourly: provisional rows are
// short-lived and the prune age is agency-configurable.
Schedule::command('communications:prune-provisional')->hourly()->withoutOverlapping();

// Communication Archive (AT-33) — email adapter: dispatch IMAP poll jobs for
// due mailboxes. Per-mailbox cadence enforced via poll_interval_minutes.
Schedule::command('communications:poll-mailboxes')->everyFiveMinutes()->withoutOverlapping();

// Private Property listing event feed — authoritative source for activations,
// deactivations and image errors. Runs every 15 minutes.
Schedule::job(new \App\Jobs\ProcessPrivatePropertyEventFeed())
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('pp-event-feed');

// Queue worker healthcheck — runs every 5 minutes on the scheduler (independent
// of the worker), so a STOPPED/wedged worker is caught in minutes instead of the
// ~1.5h silent stall on 2026-06-25. Logs Log::critical when the queue isn't drained.
Schedule::command('corex:queue-healthcheck')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('queue-healthcheck');

// Property24 ExDev activation polling — runs every 15 minutes
Schedule::job(new \App\Jobs\SyncProperty24Activations())->everyFifteenMinutes()->withoutOverlapping();

// Property24 ExDev buyer-enquiry leads pull — runs every 5 minutes.
// Persists into portal_leads alongside PP leads. See .ai/specs/portal-leads.md.
Schedule::job(new \App\Jobs\Syndication\Property24\PullP24LeadsJob())
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('p24-leads-pull');

// ── Command Center ──

// Process calendar/task reminders — runs every 5 minutes. The cadence is the
// lower bound on lead-time precision: an event fires at the first tick its
// event_date enters [now, now + lead], so effective lead ∈ [lead − 5min, lead].
// 5-min ticks keep sub-hour reminders (event_reminder_minutes_before, min 5)
// honoured to within one tick instead of the old ±15min slop.
Schedule::command('command-center:reminders')->everyFiveMinutes()->withoutOverlapping();

// Calculate property health scores — runs nightly at 02:00
Schedule::command('command-center:health')->dailyAt('02:00')->withoutOverlapping();

// Calculate agent scorecards — runs nightly at 02:30
Schedule::command('command-center:scorecards')->dailyAt('02:30')->withoutOverlapping();

// Flag idle properties — runs daily at 07:00
Schedule::command('command-center:flag-idle')->dailyAt('07:00')->withoutOverlapping();

// Auto-archive completed tasks per user setting — runs daily at 03:00
Schedule::command('command-center:archive-done-tasks')->dailyAt('03:00')->withoutOverlapping();

// Self-healing backstop: soft-delete redundant auto chore tasks on compliant /
// imported / orphaned stock so they can never accumulate into the Tasks-board
// backlog that OOM'd the page on staging. Prevention is at the source
// (Property::$skipNewListingAutomation + DismissComplianceClearedChores); this
// sweep catches anything that slips through. Runs daily at 03:15.
Schedule::command('command-center:clear-compliant-chores')->dailyAt('03:15')->withoutOverlapping();

// Manager Oversight digest — runs hourly
Schedule::job(new \App\Jobs\OversightDigestJob())->hourly()->withoutOverlapping();

// ── Pillar Notifications (notification-preferences spec) ──
Schedule::command('notifications:scan-properties')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('notifications:scan-deals')->everyThirtyMinutes()->withoutOverlapping();
// Contact birthdays are no longer scanned per-contact — they are delivered as a
// single "Birthdays today" section in the 06:30 daily digest below (one email
// per user, never one email per birthday). See SendCalendarDigests.

// ── Calendar Event Classes ──
Schedule::command('corex:calendar:send-digests')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
Schedule::command('corex:calendar:reconcile')->dailyAt('03:00')->withoutOverlapping()->onOneServer();

// ── Deal Register V2 (WS0) — RAG timer ──
// Keeps persisted step/deal RAG + deal calendar-event colour in sync as deadlines
// approach (green→amber→red→overdue), independent of user activity.
Schedule::command('deals:process-rag')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// ── Deal Register V2 (WS6) — escalation ladder + morning digest ──
// process-rag flips a step overdue + nudges the agent; this escalates the still-
// overdue step up the ladder (BM → admin) exactly once per rung, and sends each
// agent a morning pipeline digest.
Schedule::command('deals:process-escalations')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('deals:daily-digest')->dailyAt(config('deals.digest.time', '07:00'))->withoutOverlapping()->onOneServer();

// ── Leave Management ──
Schedule::command('corex:leave:accrue-daily')->dailyAt('02:00')->onOneServer()->withoutOverlapping();
Schedule::command('corex:leave:cycle-rollover')->dailyAt('02:30')->onOneServer()->withoutOverlapping();

// ── Contact Governance (M3.4) ──
Schedule::command('contacts:purge-retention')->dailyAt('02:00')->onOneServer()->withoutOverlapping();
Schedule::command('contacts:detect-duplicates')->dailyAt('03:30')->onOneServer()->withoutOverlapping();

// ── Buyer CRM (M4) ──
Schedule::command('buyers:recompute-states')->dailyAt('04:00')->onOneServer()->withoutOverlapping();

// ── Seller Outreach (AT-81) — lapse silent PENDING contacts to no_response ──
Schedule::command('outreach:recompute-no-response')->dailyAt('04:15')->onOneServer()->withoutOverlapping();

// ── Outreach Queue (AT-117 §5) — surface due rows (claim → re-check canMarketTo →
// surface/drop) + expire stale surfaced rows. Every minute, single-runner. ──
Schedule::command('outreach:surface-due')->everyMinute()->onOneServer()->withoutOverlapping();

// ── Property Intelligence (M5) ──
Schedule::command('properties:generate-recommendations')->weeklyOn(1, '05:00')->onOneServer()->withoutOverlapping();

// ── Buyer Matching Engine (M6) ──
Schedule::command('matches:recompute')->dailyAt('04:30')->onOneServer()->withoutOverlapping();

// ── Prospecting Intelligence (M13) ──
Schedule::command('prospecting:recompute-matches')->dailyAt('04:00')->onOneServer()->withoutOverlapping();
Schedule::command('corex:leave:send-reminders')->dailyAt('06:00')->onOneServer()->withoutOverlapping();

// (P24 location tree now refreshes DAILY at 11:00 with stamp-and-sweep — see
// the schedule near the top of this file. The old monthly entry was removed as
// the daily run supersedes it.)

// P24 agent-list cache warm — nightly at 22:00 SAST. P24's GET /agencies/{id}/agents
// takes ~90s; warming it off-hours keeps manual Refresh / agent sync fast (~7s) all
// the next day (cache TTL outlives the day). runInBackground so the ~90s fetch
// doesn't block the rest of the 22:00 schedule tick.
Schedule::command('p24:warm-agents-cache')
    ->dailyAt('22:00')
    ->timezone('Africa/Johannesburg')
    ->runInBackground()
    ->onOneServer()
    ->withoutOverlapping();

// ── AI Narrative Cache hygiene (MIC Phase B2) ──
// Daily: soft-delete expired rows at 03:00 SAST.
Schedule::job(new \App\Jobs\AI\SweepExpiredNarrativeCacheJob())
    ->dailyAt('03:00')
    ->timezone('Africa/Johannesburg')
    ->onOneServer()
    ->withoutOverlapping()
    ->name('ai-cache-sweep');

// Weekly: hard-delete rows soft-deleted > 90 days. Sundays at 03:30 SAST.
Schedule::job(new \App\Jobs\AI\PurgeOldSoftDeletedCacheJob())
    ->weeklyOn(0, '03:30')
    ->timezone('Africa/Johannesburg')
    ->onOneServer()
    ->withoutOverlapping()
    ->name('ai-cache-purge');

// Weekly: retention sweep of the AI cost ledger — hard-delete ai_usage_events
// rows older than 13 months. Sundays at 03:45 SAST (after the cache purge).
// Spec: .ai/specs/ai-cost-ledger.md §3.2.8.
Schedule::job(new \App\Jobs\AI\PurgeOldAiUsageEventsJob())
    ->weeklyOn(0, '03:45')
    ->timezone('Africa/Johannesburg')
    ->onOneServer()
    ->withoutOverlapping()
    ->name('ai-usage-ledger-purge');

// Nightly: warm the "This Week" tile cache so morning agent visits hit cache
// instead of paying AI cost during peak. 02:30 SAST is before the 03:00 SAST
// expired-cache sweep so any stale rows are gone before the warm starts.
Schedule::job(new \App\Jobs\AI\WarmThisWeekTilesJob())
    ->dailyAt('02:30')
    ->timezone('Africa/Johannesburg')
    ->onOneServer()
    ->withoutOverlapping()
    ->name('ai-tiles-warm');

// Hourly: flag claims as stale once the agent has gone >48h without
// feedback. Surfaces on the BM Team Dashboard (Phase G2). Idempotent.
Schedule::job(new \App\Jobs\Prospecting\FlagStaleClaimsJob())
    ->hourly()
    ->timezone('Africa/Johannesburg')
    ->onOneServer()
    ->withoutOverlapping()
    ->name('flag-stale-claims');

// ── Geocoding cache hygiene (Phase 11a B) ──
// Daily: hard-delete rows past expires_at (90-day success TTL, 7-day failure TTL).
Schedule::command('geo:cache-purge')
    ->dailyAt('03:00')
    ->timezone('Africa/Johannesburg')
    ->onOneServer()
    ->withoutOverlapping()
    ->name('geo-cache-purge');

// Demo reset — wipe [DEMO]-prefixed data and reseed daily at 03:00.
// Only runs when APP_ENV is local or demo (guarded inside the commands).
if (in_array(app()->environment(), ['local', 'demo'], true)) {
    Schedule::command('demo:cleanup --force')->dailyAt('03:00')->withoutOverlapping();
    Schedule::command('demo:seed')->dailyAt('03:05')->withoutOverlapping();
}

// Mandate expiry — daily at 01:00. Marks stock properties whose expiry_date
// has passed as 'expired' and fires Mandate\MandateExpired domain events.
// Spec: .ai/specs/corex-domain-events-spec.md (Wave 6 deferred wiring).
Schedule::command('mandates:expire')->dailyAt('01:00')->onOneServer()->withoutOverlapping();

// Fault reports auto-prune — soft-delete reports older than 3 days, daily at 02:30.
Schedule::call(function () {
    \App\Models\FaultReport::where('last_seen_at', '<', now()->subDays(3))->delete();
})->dailyAt('02:30')->name('fault-reports.prune')->onOneServer()->withoutOverlapping();
