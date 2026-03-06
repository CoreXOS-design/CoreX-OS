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
