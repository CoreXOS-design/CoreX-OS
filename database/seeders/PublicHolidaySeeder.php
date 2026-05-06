<?php

namespace Database\Seeders;

use App\Services\Leave\PublicHolidayService;
use Illuminate\Database\Seeder;

class PublicHolidaySeeder extends Seeder
{
    public function run(): void
    {
        $service = new PublicHolidayService();

        foreach ([2026, 2027, 2028] as $year) {
            $count = $service->ensureYearSeeded($year);
            $this->command?->info("Seeded {$count} public holidays for ZA {$year}");
        }
    }
}
