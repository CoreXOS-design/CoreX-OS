<?php

namespace App\Services\MarketAnalytics\Contracts;

use App\Services\MarketAnalytics\DTOs\SoldTransactionsFilter;
use Illuminate\Support\Collection;

interface SoldTransactionsSource
{
    /**
     * Return a Collection of DataSourceRecord objects matching the filter.
     * Implementations must return an empty Collection (not null) when no data found.
     */
    public function getRecords(SoldTransactionsFilter $filter): Collection;
}
