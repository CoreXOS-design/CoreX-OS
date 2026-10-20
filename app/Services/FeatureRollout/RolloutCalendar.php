<?php

namespace App\Services\FeatureRollout;

use App\Models\FeatureRollout\AgencyFeatureRollout;
use App\Models\Leave\PublicHoliday;
use App\Services\Leave\PublicHolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The ONE date helper for gradual rollout (spec agency-feature-rollout.md §3.5). Every schedule —
 * first plan, pause/resume, interval change, release-now — goes through here, so "weekdays only"
 * and "skip public holidays" can never be applied in one place and forgotten in another.
 *
 * A release day is any day, or with weekdays_only only Monday–Friday, and with skip_holidays never a
 * public holiday in the plan's holiday_country (PublicHolidayService / public_holidays table).
 */
class RolloutCalendar
{
    /**
     * Countries PublicHolidayService::generateHolidaysForYear() has real rules for. Only these years are
     * generated on demand; any other country uses whatever rows are already in public_holidays.
     */
    private const GENERATED_COUNTRIES = ['ZA'];

    /** @var array<string,bool> "<country>|<year>" already ensured by this instance */
    private array $seeded = [];

    public function __construct(private readonly PublicHolidayService $holidays)
    {
    }

    /** The timezone release dates and times are evaluated in. */
    public function timezone(): string
    {
        return config('app.timezone') ?: 'UTC';
    }

    public function today(): Carbon
    {
        return Carbon::now($this->timezone())->startOfDay();
    }

    public function isReleaseDay(CarbonInterface $date, AgencyFeatureRollout $plan): bool
    {
        if ($plan->weekdays_only && $date->isWeekend()) {
            return false;
        }

        if ($plan->skip_holidays) {
            $country = strtoupper((string) $plan->holiday_country);
            $this->ensureYear($country, (int) $date->year);

            if ($this->holidays->isPublicHoliday(Carbon::parse($date->toDateString()), $country)) {
                return false;
            }
        }

        return true;
    }

    /** The first release day on or after $date. */
    public function onOrAfter(CarbonInterface $date, AgencyFeatureRollout $plan): Carbon
    {
        $d = Carbon::parse($date->toDateString(), $this->timezone());
        for ($guard = 0; $guard < 60 && ! $this->isReleaseDay($d, $plan); $guard++) {
            $d->addDay();
        }

        return $d;
    }

    /** Move $days release days forward from $from (0 = $from itself if it is a release day). */
    public function addReleaseDays(CarbonInterface $from, int $days, AgencyFeatureRollout $plan): Carbon
    {
        $d = $this->onOrAfter($from, $plan);
        for ($i = 0; $i < $days; $i++) {
            $d = $this->onOrAfter($d->copy()->addDay(), $plan);
        }

        return $d;
    }

    /** The release day immediately before $date (for the "day before" admin preview). */
    public function previousReleaseDay(CarbonInterface $date, AgencyFeatureRollout $plan): Carbon
    {
        $d = Carbon::parse($date->toDateString(), $this->timezone())->subDay();
        for ($guard = 0; $guard < 60 && ! $this->isReleaseDay($d, $plan); $guard++) {
            $d->subDay();
        }

        return $d;
    }

    /** The moment a wave scheduled for $date is released: that day at the plan's release time. */
    public function releaseMoment(CarbonInterface $date, AgencyFeatureRollout $plan): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', substr((string) $plan->release_time, 0, 5)) + [0, 0]);

        return Carbon::parse($date->toDateString(), $this->timezone())->setTime($h, $m);
    }

    private function ensureYear(string $country, int $year): void
    {
        $key = $country . '|' . $year;
        if (isset($this->seeded[$key])) {
            return;
        }
        $this->seeded[$key] = true;

        if (in_array($country, self::GENERATED_COUNTRIES, true)
            && ! PublicHoliday::where('country_code', $country)->whereYear('holiday_date', $year)->exists()) {
            $this->holidays->ensureYearSeeded($year, $country);
        }
    }
}
