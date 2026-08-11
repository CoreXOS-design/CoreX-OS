<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\Period;

/**
 * AT-366 — one metric category (e.g. "contacts created", "deals registered").
 *
 * Each provider owns its source table, its user-attribution column, and its
 * period timestamp. forUsers() returns the value per user for the window, keyed
 * by user id, so the report queries each category once for the whole cohort.
 */
interface MetricProvider
{
    public function key(): string;

    public function label(): string;

    /**
     * @param  int[]  $userIds
     * @return array<int, int|float>  uid => value (every requested uid present, 0 if none)
     */
    public function forUsers(array $userIds, Period $period): array;
}
