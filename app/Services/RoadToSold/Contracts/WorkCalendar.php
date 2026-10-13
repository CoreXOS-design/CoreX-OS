<?php

namespace App\Services\RoadToSold\Contracts;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * What the list builder needs to know about an agent's working days (lane 1 owns the real answer:
 * RoadToSold\WorkdayService — public holidays, approved leave, weekday targets).
 */
interface WorkCalendar
{
    /** The agent's call target for the date (0 on a holiday, whole-day leave or a zero weekday). */
    public function targetFor(User $user, CarbonInterface $date, string $track): int;

    /** True only when the date is a working day with a target above zero. */
    public function isWorkday(User $user, CarbonInterface $date, string $track): bool;

    /** First working day AFTER $from, or null when there is none inside the search window. */
    public function nextWorkday(User $user, CarbonInterface $from, string $track): ?Carbon;
}
