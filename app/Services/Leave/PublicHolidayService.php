<?php

namespace App\Services\Leave;

use App\Models\Leave\PublicHoliday;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PublicHolidayService
{
    /**
     * In-request cache keyed by 'YYYY-MM-DD|CC'.
     */
    private static array $cache = [];

    /**
     * Check if a given date is a public holiday.
     */
    public function isPublicHoliday(Carbon $date, string $country = 'ZA'): bool
    {
        $key = $date->toDateString() . '|' . $country;

        if (!isset(self::$cache[$key])) {
            self::$cache[$key] = PublicHoliday::where('country_code', $country)
                ->where('holiday_date', $date->toDateString())
                ->exists();
        }

        return self::$cache[$key];
    }

    /**
     * Get all public holidays in a date range.
     */
    public function getHolidaysInRange(Carbon $start, Carbon $end, string $country = 'ZA'): Collection
    {
        return PublicHoliday::forCountry($country)
            ->between($start, $end)
            ->orderBy('holiday_date')
            ->get();
    }

    /**
     * Count working days between start and end (inclusive), excluding
     * non-working days per mask AND public holidays.
     */
    public function countWorkingDays(Carbon $start, Carbon $end, array $workingDayMask, string $country = 'ZA'): int
    {
        // Pre-load all holidays in range into cache
        $holidays = $this->getHolidaysInRange($start, $end, $country)
            ->pluck('holiday_date')
            ->map(fn($d) => $d->toDateString())
            ->flip()
            ->all();

        $count = 0;
        $cursor = $start->copy()->startOfDay();
        $endDate = $end->copy()->startOfDay();

        while ($cursor <= $endDate) {
            $dayName = strtolower($cursor->englishDayOfWeek);

            if (($workingDayMask[$dayName] ?? false) && !isset($holidays[$cursor->toDateString()])) {
                $count++;
            }

            $cursor->addDay();
        }

        return $count;
    }

    /**
     * Check if a single date is a working day for the given mask.
     */
    public function isWorkingDay(Carbon $date, array $workingDayMask, string $country = 'ZA'): bool
    {
        $dayName = strtolower($date->englishDayOfWeek);

        if (!($workingDayMask[$dayName] ?? false)) {
            return false;
        }

        return !$this->isPublicHoliday($date, $country);
    }

    /**
     * Calculate Easter-dependent dates for a year.
     * Uses easter_days() which returns the offset from March 21.
     */
    public function calculateEasterDates(int $year): array
    {
        $easterDays = easter_days($year);
        $easterSunday = Carbon::create($year, 3, 21)->addDays($easterDays);

        return [
            'good_friday' => $easterSunday->copy()->subDays(2),
            'family_day'  => $easterSunday->copy()->addDay(),
        ];
    }

    /**
     * Generate all public holidays for a year.
     * Returns array of ['holiday_date' => Carbon, 'name' => string, 'is_movable' => bool].
     * Includes Sunday-rolls-to-Monday observed entries.
     */
    public function generateHolidaysForYear(int $year, string $country = 'ZA'): array
    {
        $holidays = [];

        // 10 fixed holidays
        $fixed = [
            ['month' => 1,  'day' => 1,  'name' => "New Year's Day"],
            ['month' => 3,  'day' => 21, 'name' => 'Human Rights Day'],
            ['month' => 4,  'day' => 27, 'name' => 'Freedom Day'],
            ['month' => 5,  'day' => 1,  'name' => "Workers' Day"],
            ['month' => 6,  'day' => 16, 'name' => 'Youth Day'],
            ['month' => 8,  'day' => 9,  'name' => "National Women's Day"],
            ['month' => 9,  'day' => 24, 'name' => 'Heritage Day'],
            ['month' => 12, 'day' => 16, 'name' => 'Day of Reconciliation'],
            ['month' => 12, 'day' => 25, 'name' => 'Christmas Day'],
            ['month' => 12, 'day' => 26, 'name' => 'Day of Goodwill'],
        ];

        foreach ($fixed as $f) {
            $date = Carbon::create($year, $f['month'], $f['day']);
            $holidays[] = [
                'holiday_date' => $date,
                'name'         => $f['name'],
                'is_movable'   => false,
            ];

            // Sunday roll-forward: Public Holidays Act s2(1)
            if ($date->isSunday()) {
                $holidays[] = [
                    'holiday_date' => $date->copy()->addDay(),
                    'name'         => $f['name'] . ' (Observed)',
                    'is_movable'   => false,
                ];
            }
        }

        // 2 moveable holidays (Easter-based)
        $easter = $this->calculateEasterDates($year);

        $holidays[] = [
            'holiday_date' => $easter['good_friday'],
            'name'         => 'Good Friday',
            'is_movable'   => true,
        ];

        $holidays[] = [
            'holiday_date' => $easter['family_day'],
            'name'         => 'Family Day',
            'is_movable'   => true,
        ];

        // Sort by date
        usort($holidays, fn($a, $b) => $a['holiday_date']->timestamp <=> $b['holiday_date']->timestamp);

        return $holidays;
    }

    /**
     * Ensure a year's holidays are seeded in the DB. Upserts.
     * Returns count of holidays created/updated.
     */
    public function ensureYearSeeded(int $year, string $country = 'ZA'): int
    {
        $holidays = $this->generateHolidaysForYear($year, $country);
        $count = 0;

        foreach ($holidays as $h) {
            PublicHoliday::updateOrCreate(
                [
                    'country_code' => $country,
                    'holiday_date' => $h['holiday_date']->toDateString(),
                ],
                [
                    'name'            => $h['name'],
                    'is_movable'      => $h['is_movable'],
                    'applies_to_year' => $year,
                ]
            );
            $count++;
        }

        // Clear request cache for this year
        self::$cache = [];

        return $count;
    }
}
