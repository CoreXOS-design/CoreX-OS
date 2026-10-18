<?php

namespace App\Services\RoadToSold\Adapters;

use App\Models\User;
use App\Services\RoadToSold\Contracts\WorkCalendar;
use App\Services\RoadToSold\WorkdayService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** WorkCalendar backed by lane 1's WorkdayService (public holidays, approved leave, weekday targets). */
final class LaneWorkCalendar implements WorkCalendar
{
    public function targetFor(User $user, CarbonInterface $date, string $track): int
    {
        return WorkdayService::make()->targetFor($user, $date, $track);
    }

    public function isWorkday(User $user, CarbonInterface $date, string $track): bool
    {
        return WorkdayService::make()->isWorkday($user, $date, $track);
    }

    public function nextWorkday(User $user, CarbonInterface $from, string $track): ?Carbon
    {
        return WorkdayService::make()->nextWorkday($user, $from, $track);
    }
}
