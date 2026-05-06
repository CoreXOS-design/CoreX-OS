<?php

namespace App\Console\Commands\Leave;

use App\Services\Leave\PublicHolidayService;
use Illuminate\Console\Command;

class SeedPublicHolidaysCommand extends Command
{
    protected $signature = 'corex:seed-public-holidays {year?} {--country=ZA}';
    protected $description = 'Seed SA public holidays for a given year (or current + next 2 years)';

    public function handle(): int
    {
        $service = new PublicHolidayService();
        $country = $this->option('country');
        $year = $this->argument('year');

        if ($year) {
            $years = [(int) $year];
        } else {
            $currentYear = (int) now()->format('Y');
            $years = [$currentYear, $currentYear + 1, $currentYear + 2];
        }

        foreach ($years as $y) {
            $count = $service->ensureYearSeeded($y, $country);
            $this->info("Seeded {$count} public holidays for {$country} {$y}");

            // List the holidays
            $holidays = $service->generateHolidaysForYear($y, $country);
            foreach ($holidays as $h) {
                $this->line("  {$h['holiday_date']->format('Y-m-d D')} — {$h['name']}");
            }
        }

        $total = \App\Models\Leave\PublicHoliday::where('country_code', $country)->count();
        $this->info("Total {$country} holidays in database: {$total}");

        return self::SUCCESS;
    }
}
