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

// Prospecting claim maintenance — runs hourly
Schedule::command('prospecting:maintain-claims')->hourly();

// Carry forward targets from previous month — runs on the 1st at 00:05
Schedule::command('targets:carry-forward')->monthlyOn(1, '00:05')->withoutOverlapping();

// Core Matches — archive matches with no engagement, mark fulfilled where the
// contact has a recent deal. Daily at 03:00.
Schedule::command('corex:matches:archive-stale')->dailyAt('03:00')->withoutOverlapping();

// Agency Access Authorization — expire stale pending requests every minute.
Schedule::command('agency-access:expire')->everyMinute()->withoutOverlapping();

// Private Property activation polling — runs every 15 minutes
Schedule::job(new \App\Jobs\SyncPrivatePropertyActivations())->everyFifteenMinutes()->withoutOverlapping();

// Private Property listing event feed — authoritative source for activations,
// deactivations and image errors. Runs every 15 minutes.
Schedule::job(new \App\Jobs\ProcessPrivatePropertyEventFeed())
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->name('pp-event-feed');

// Property24 ExDev activation polling — runs every 15 minutes
Schedule::job(new \App\Jobs\SyncProperty24Activations())->everyFifteenMinutes()->withoutOverlapping();

// Property24 ExDev buyer-enquiry leads pull — runs every 5 minutes.
// Persists into portal_leads alongside PP leads. See .ai/specs/portal-leads.md.
Schedule::job(new \App\Jobs\Syndication\Property24\PullP24LeadsJob())
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->name('p24-leads-pull');

// ── Command Center ──

// Process calendar reminders — runs every 15 minutes
Schedule::command('command-center:reminders')->everyFifteenMinutes()->withoutOverlapping();

// Calculate property health scores — runs nightly at 02:00
Schedule::command('command-center:health')->dailyAt('02:00')->withoutOverlapping();

// Calculate agent scorecards — runs nightly at 02:30
Schedule::command('command-center:scorecards')->dailyAt('02:30')->withoutOverlapping();

// Flag idle properties — runs daily at 07:00
Schedule::command('command-center:flag-idle')->dailyAt('07:00')->withoutOverlapping();

// Auto-archive completed tasks per user setting — runs daily at 03:00
Schedule::command('command-center:archive-done-tasks')->dailyAt('03:00')->withoutOverlapping();

// Manager Oversight digest — runs hourly
Schedule::job(new \App\Jobs\OversightDigestJob())->hourly()->withoutOverlapping();

// ── Pillar Notifications (notification-preferences spec) ──
Schedule::command('notifications:scan-properties')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('notifications:scan-contacts')->hourly()->withoutOverlapping();
Schedule::command('notifications:scan-deals')->everyThirtyMinutes()->withoutOverlapping();

// ── Calendar Event Classes ──
Schedule::command('corex:calendar:send-digests')->dailyAt('06:30')->withoutOverlapping()->onOneServer();
Schedule::command('corex:calendar:reconcile')->dailyAt('03:00')->withoutOverlapping()->onOneServer();

// ── Leave Management ──
Schedule::command('corex:leave:accrue-daily')->dailyAt('02:00')->onOneServer()->withoutOverlapping();
Schedule::command('corex:leave:cycle-rollover')->dailyAt('02:30')->onOneServer()->withoutOverlapping();

// ── Contact Governance (M3.4) ──
Schedule::command('contacts:purge-retention')->dailyAt('02:00')->onOneServer()->withoutOverlapping();
Schedule::command('contacts:detect-duplicates')->dailyAt('03:30')->onOneServer()->withoutOverlapping();

// ── Buyer CRM (M4) ──
Schedule::command('buyers:recompute-states')->dailyAt('04:00')->onOneServer()->withoutOverlapping();

// ── Property Intelligence (M5) ──
Schedule::command('properties:generate-recommendations')->weeklyOn(1, '05:00')->onOneServer()->withoutOverlapping();

// ── Buyer Matching Engine (M6) ──
Schedule::command('matches:recompute')->dailyAt('04:30')->onOneServer()->withoutOverlapping();

// ── Prospecting Intelligence (M13) ──
Schedule::command('prospecting:recompute-matches')->dailyAt('04:00')->onOneServer()->withoutOverlapping();
Schedule::command('corex:leave:send-reminders')->dailyAt('06:00')->onOneServer()->withoutOverlapping();

// P24 location tree sync — monthly on the 1st at 02:00
Schedule::command('p24:sync-locations')->monthlyOn(1, '02:00')->withoutOverlapping();

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
