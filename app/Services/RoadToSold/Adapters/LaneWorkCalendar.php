<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\WorkCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * WorkCalendar backed by lane 1's WorkdayService (holidays, approved leave, weekday targets).
 * Until that class exists, a neutral Monday–Friday calendar with a target of 5 — the agency
 * default in lane 1's contract — so the builder works on a branch that does not have it yet.
 */
final class LaneWorkCalendar implements WorkCalendar
{
    private const WORKDAY_SERVICE = '\\App\\Services\\RoadToSold\\WorkdayService';

    public function targetFor(User $user, CarbonInterface $date, string $track): int
    {
        if ($svc = $this->service()) {
            return (int) $svc->targetFor($user, $date, $track);
        }

        return $date->isWeekend() ? 0 : 5;
    }

    public function isWorkday(User $user, CarbonInterface $date, string $track): bool
    {
        if ($svc = $this->service()) {
            return (bool) $svc->isWorkday($user, $date, $track);
        }

        return ! $date->isWeekend();
    }

    public function nextWorkday(User $user, CarbonInterface $from, string $track): ?Carbon
    {
        if ($svc = $this->service()) {
            $next = $svc->nextWorkday($user, $from, $track);

            return $next ? Carbon::instance($next) : null;
        }

        $d = Carbon::instance($from)->startOfDay();
        for ($i = 0; $i < 60; $i++) {
            $d = $d->addDay();
            if (! $d->isWeekend()) {
                return $d;
            }
        }

        return null;
    }

    private function service(): ?object
    {
        return class_exists(self::WORKDAY_SERVICE) ? self::WORKDAY_SERVICE::make() : null;
    }
}
