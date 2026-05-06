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

// Prospecting claim maintenance — runs hourly
Schedule::command('prospecting:maintain-claims')->hourly();

// Carry forward targets from previous month — runs on the 1st at 00:05
Schedule::command('targets:carry-forward')->monthlyOn(1, '00:05')->withoutOverlapping();

// Core Matches — archive matches with no engagement, mark fulfilled where the
// contact has a recent deal. Daily at 03:00.
Schedule::command('corex:matches:archive-stale')->dailyAt('03:00')->withoutOverlapping();

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
Schedule::command('corex:leave:send-reminders')->dailyAt('06:00')->onOneServer()->withoutOverlapping();
