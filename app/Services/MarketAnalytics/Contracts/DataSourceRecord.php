<?php

namespace App\Services\MarketAnalytics\Contracts;

interface DataSourceRecord
{
    /**
     * Return a plain array representation suitable for JSON serialisation.
     */
    public function toArray(): array;
}
