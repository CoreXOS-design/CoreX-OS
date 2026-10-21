<?php

declare(strict_types=1);

namespace App\Services\Rentals;

use App\Models\Leave\PublicHoliday;
use App\Services\Leave\PublicHolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * AT-489 (21 Oct 2026) - business days for lease notice: a business day is not a Saturday, not a Sunday and not a South African
 * public holiday. Counting back from a lease's end date gives the notice window of clause 5.1 of the lease (not more than 80 and
 * not less than 40 business days' notice before the lease ends - the numbers are agency settings, see LeaseSetting).
 *
 * Holidays: the fixed holidays, Good Friday and Family Day come from PublicHolidayService::generateHolidaysForYear() (the leave
 * module's own calculation - computed, so a year nobody seeded is still right), joined with whatever the public_holidays table
 * holds (extra gazetted days, e.g. an election day). The Public Holidays Act rule - a holiday on a Sunday makes the Monday after
 * it a holiday - is applied here, and when that Monday is already a holiday the next free weekday takes it (Christmas Day on a
 * Sunday: Day of Goodwill is the Monday, so the Tuesday is the extra day).
 *
 * One calculation, three users: the capture screen's live dates (the holiday list is handed to the browser, which counts the same
 * way), the Notice terms card, and the server's validation (LeaseNoticeTermsService::crossErrors).
 */
class BusinessDayService
{
    /** @var array<int, array{fixed: array<string,true>, observed: list<string>}> year => the computed holidays, per process */
    private static array $yearCache = [];

    /** Is $date a business day? Weekends and the holidays in $holidays (a set keyed by Y-m-d) are not. */
    public function isBusinessDay(CarbonInterface $date, array $holidays): bool
    {
        return ! $date->isWeekend() && ! isset($holidays[$date->toDateString()]);
    }

    /**
     * The date $days business days BEFORE $from: step back a day at a time and count the business days; the date that makes the
     * count reach $days is the answer (so it is itself a business day). $from is not counted.
     */
    public function subtract(CarbonInterface $from, int $days): Carbon
    {
        $cursor = Carbon::instance($from)->startOfDay();
        $holidays = $this->holidaySet($cursor->copy()->subDays(max(30, $days * 3)), $cursor);
        $counted = 0;
        while ($counted < $days) {
            $cursor->subDay();
            if ($this->isBusinessDay($cursor, $holidays)) {
                $counted++;
            }
        }

        return $cursor;
    }

    /**
     * AT-489 - "N business days before $end" counted the way the Consumer Protection Act s2(6) counts a period of days: the first day
     * is excluded, the last day is included, Saturdays, Sundays and public holidays do not count. The answer is the business day with
     * EXACTLY $days business days after it up to and including $end. When $end is itself a business day that is $days business days
     * back from it (subtract()); when it is not (a weekend / holiday end date) one more, because $end then adds nothing to the count.
     */
    public function daysBefore(CarbonInterface $end, int $days): Carbon
    {
        $end = Carbon::instance($end)->startOfDay();
        $holidays = $this->holidaySet($end->copy()->subDays(5), $end);

        return $this->subtract($end, $days + ($this->isBusinessDay($end, $holidays) ? 0 : 1));
    }

    /**
     * Business days from $from to $to counted the CPA s2(6) way: $from excluded, $to included, weekends and public holidays left out.
     * (0 when $to is not after $from.)
     */
    public function countBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $cursor = Carbon::instance($from)->startOfDay();
        $end = Carbon::instance($to)->startOfDay();
        if ($end->lte($cursor)) {
            return 0;
        }
        $holidays = $this->holidaySet($cursor, $end);
        $count = 0;
        while ($cursor->lt($end)) {
            $cursor->addDay();
            if ($this->isBusinessDay($cursor, $holidays)) {
                $count++;
            }
        }

        return $count;
    }

    /** The date $days business days AFTER $from (the mirror of subtract()). */
    public function add(CarbonInterface $from, int $days): Carbon
    {
        $cursor = Carbon::instance($from)->startOfDay();
        $holidays = $this->holidaySet($cursor, $cursor->copy()->addDays(max(30, $days * 3)));
        $counted = 0;
        while ($counted < $days) {
            $cursor->addDay();
            if ($this->isBusinessDay($cursor, $holidays)) {
                $counted++;
            }
        }

        return $cursor;
    }

    /** @return array<string, true> the public holidays from $from to $to (inclusive), keyed by Y-m-d */
    public function holidaySet(CarbonInterface $from, CarbonInterface $to): array
    {
        $set = [];
        for ($year = (int) $from->year; $year <= (int) $to->year + 1; $year++) {
            foreach ($this->holidaysOfYear($year) as $date => $_) {
                $set[$date] = true;
            }
        }
        $start = $from->toDateString();
        $end = $to->toDateString();

        return array_filter($set, fn ($_, $date) => $date >= $start && $date <= $end, ARRAY_FILTER_USE_BOTH);
    }

    /** Sorted Y-m-d list for the browser (the capture screen counts the same way with it). */
    public function holidayList(CarbonInterface $from, CarbonInterface $to): array
    {
        $dates = array_keys($this->holidaySet($from, $to));
        sort($dates);

        return $dates;
    }

    /**
     * @return array<string, true> every public holiday of $year, the substitute Mondays included. The computed holidays are cached per
     * year (they never change); the public_holidays table is read every time (a long-running worker must see a day added later).
     */
    private function holidaysOfYear(int $year): array
    {
        $generated = self::$yearCache[$year] ??= $this->generatedHolidays($year);

        $set = $generated['fixed'];
        // Extra gazetted days kept in the public_holidays table (an election day, a one-off). The table's own "(Observed)" substitute
        // Mondays are skipped - they are worked out below, once, from the Sunday holidays.
        try {
            PublicHoliday::forCountry('ZA')->whereBetween('holiday_date', ["{$year}-01-01", "{$year}-12-31"])->get(['holiday_date', 'name'])
                ->each(function ($row) use (&$set) {
                    if (! str_ends_with((string) $row->name, '(Observed)')) {
                        $set[Carbon::parse($row->holiday_date)->toDateString()] = true;
                    }
                });
        } catch (\Throwable) {
            // no table (a bare test database): the computed holidays stand on their own
        }
        foreach ($generated['observed'] as $date) {
            $d = Carbon::parse($date);
            while (isset($set[$d->toDateString()]) || $d->isWeekend()) {
                $d->addDay();
            }
            $set[$d->toDateString()] = true;
        }

        return $set;
    }

    /** @return array{fixed: array<string,true>, observed: list<string>} the leave module's own calculation for $year */
    private function generatedHolidays(int $year): array
    {
        $fixed = [];
        $observed = [];
        foreach (app(PublicHolidayService::class)->generateHolidaysForYear($year) as $h) {
            $date = $h['holiday_date']->toDateString();
            if (str_ends_with((string) $h['name'], '(Observed)')) {
                $observed[] = $date; // the Monday after a Sunday holiday
            } else {
                $fixed[$date] = true;
            }
        }

        return ['fixed' => $fixed, 'observed' => $observed];
    }

    /** Test seam: forget the per-request cache. */
    public static function flush(): void
    {
        self::$yearCache = [];
    }
}
