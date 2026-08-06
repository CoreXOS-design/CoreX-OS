<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\Period;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AT-366 — base for the common "count rows attributed to a user within a period"
 * metric. Subclasses declare the table, the user column, and the period column;
 * override baseQuery() to add soft-delete / demo-data / status filters.
 *
 * One grouped query per provider for the whole cohort — no N+1.
 */
abstract class AbstractCountMetricProvider implements MetricProvider
{
    abstract protected function table(): string;

    abstract protected function userColumn(): string;

    abstract protected function periodColumn(): string;

    protected function baseQuery(): Builder
    {
        return DB::table($this->table());
    }

    public function forUsers(array $userIds, Period $period): array
    {
        $out = array_fill_keys($userIds, 0);
        if (empty($userIds)) {
            return $out;
        }

        $rows = $this->baseQuery()
            ->select($this->userColumn() . ' as uid', DB::raw('COUNT(*) as c'))
            ->whereIn($this->userColumn(), $userIds)
            ->whereBetween($this->periodColumn(), [$period->start, $period->end])
            ->groupBy($this->userColumn())
            ->pluck('c', 'uid');

        foreach ($rows as $uid => $c) {
            $out[(int) $uid] = (int) $c;
        }

        return $out;
    }
}
