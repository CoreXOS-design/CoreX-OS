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
