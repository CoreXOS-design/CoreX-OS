<?php

namespace App\Services\RoadToSold;

use App\Models\Leave\LeaveApplication;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Services\Leave\PublicHolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * AT-454 — which days an agent owes a target, and how much.
 * Contract: .ai/specs/road-to-sold.md §2.2. Lanes 2 and 3 code against these methods.
 *
 * A day carries NO target when it is a public holiday (PublicHolidayService, in the country the agency chose
 * under Road to Sold settings - default ZA), when the agent is on
 * approved whole-day leave (leave_applications status approved/taken), or when the agent's weekday
 * number is 0. Approved HALF-day leave keeps the full target unless the agency switched
 * "half-day leave halves the target" on, in which case that day's target is halved (rounded to the
 * nearest whole number).
 *
 * Resolve one instance per batch (app(WorkdayService::class)): it memoises holidays and each agent's
 * leave per month for its own lifetime only, so a long-running worker never sees stale leave.
 */
class WorkdayService
{
    /** Longest look-ahead when searching for the next working day. */
    private const NEXT_WORKDAY_HORIZON_DAYS = 60;

    /** @var array<string,array<string,bool>> "YYYY-MM|CC" => [Y-m-d => true] */
    private array $holidayMemo = [];

    /** @var array<string,array<string,string>> "userId|YYYY-MM" => [Y-m-d => 'full'|'half'] */
    private array $leaveMemo = [];

    public function __construct(
        private readonly PublicHolidayService $holidays,
        private readonly TargetService $targets,
    ) {
    }

    public static function make(): self
    {
        return app(self::class);
    }

    public function isWorkday(User $user, CarbonInterface|string $date, string $track = RtsSetting::TRACK_SALES): bool
    {
        return $this->targetFor($user, $date, $track) > 0;
    }

    public function targetFor(User $user, CarbonInterface|string $date, string $track = RtsSetting::TRACK_SALES): int
    {
        $day = $this->day($date);

        if ($this->isPublicHoliday($day, SettingsService::forUser($user, $track)->holidayCountry())) {
            return 0;
        }

        $leave = $this->leaveOn($user, $day);
        if ($leave === 'full') {
            return 0;
        }

        $base = $this->targets->weekFor($user, $track)[$day->dayOfWeekIso] ?? 0;

        if ($leave === 'half' && SettingsService::forUser($user, $track)->halfDayLeaveHalvesTarget()) {
            return (int) round($base / 2, 0, PHP_ROUND_HALF_UP);
        }

        return $base;
    }

    /** What the agent should have done by the end of $date this month (first of the month through $date). */
    public function monthTargetToDate(User $user, CarbonInterface|string $date, string $track = RtsSetting::TRACK_SALES): int
    {
        $end = $this->day($date);

        return $this->sum($user, $end->copy()->startOfMonth(), $end, $track);
    }

    /** The whole month's target for the month $date falls in. */
    public function monthTarget(User $user, CarbonInterface|string $date, string $track = RtsSetting::TRACK_SALES): int
    {
        $d = $this->day($date);

        return $this->sum($user, $d->copy()->startOfMonth(), $d->copy()->endOfMonth()->startOfDay(), $track);
    }

    /**
     * The first working day AFTER $from, or null when there is none within the look-ahead
     * (e.g. an agent whose every weekday is 0). Callers must handle null.
     */
    public function nextWorkday(User $user, CarbonInterface|string $from, string $track = RtsSetting::TRACK_SALES): ?Carbon
    {
        $cursor = $this->day($from);
        for ($i = 0; $i < self::NEXT_WORKDAY_HORIZON_DAYS; $i++) {
            $cursor = $cursor->copy()->addDay();
            if ($this->isWorkday($user, $cursor, $track)) {
                return $cursor;
            }
        }

        return null;
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function sum(User $user, Carbon $from, Carbon $to, string $track): int
    {
        $total = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $total += $this->targetFor($user, $d, $track);
        }

        return $total;
    }

    private function day(CarbonInterface|string $date): Carbon
    {
        return ($date instanceof CarbonInterface ? Carbon::instance($date) : Carbon::parse($date))->startOfDay();
    }

    private function isPublicHoliday(Carbon $day, string $country): bool
    {
        $key = $day->format('Y-m') . '|' . $country;
        if (! isset($this->holidayMemo[$key])) {
            $this->holidayMemo[$key] = $this->holidays
                ->getHolidaysInRange($day->copy()->startOfMonth(), $day->copy()->endOfMonth(), $country)
                ->mapWithKeys(fn ($h) => [$h->holiday_date->toDateString() => true])
                ->all();
        }

        return isset($this->holidayMemo[$key][$day->toDateString()]);
    }

    /** 'full' | 'half' | null for approved leave covering the day. A full-day entry wins over a half-day one. */
    private function leaveOn(User $user, Carbon $day): ?string
    {
        $key = $user->id . '|' . $day->format('Y-m');
        if (! isset($this->leaveMemo[$key])) {
            $start = $day->copy()->startOfMonth();
            $end   = $day->copy()->endOfMonth();
            $map   = [];

            // The answer must not depend on WHO is asking: a branch manager's view must not hide another
            // branch's leave. The agent's id is the filter; soft-deleted applications stay excluded by hand.
            LeaveApplication::query()->withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('user_id', $user->id)
                ->whereIn('status', ['approved', 'taken'])
                ->where('start_date', '<=', $end->toDateString())
                ->where('end_date', '>=', $start->toDateString())
                ->get(['start_date', 'end_date', 'is_half_day'])
                ->each(function ($l) use (&$map, $start, $end) {
                    $from = Carbon::parse($l->start_date)->startOfDay()->max($start);
                    $to   = Carbon::parse($l->end_date)->startOfDay()->min($end);
                    for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                        $kind = $l->is_half_day ? 'half' : 'full';
                        $date = $d->toDateString();
                        if (($map[$date] ?? null) !== 'full') {
                            $map[$date] = $kind;
                        }
                    }
                });

            $this->leaveMemo[$key] = $map;
        }

        return $this->leaveMemo[$key][$day->toDateString()] ?? null;
    }
}
