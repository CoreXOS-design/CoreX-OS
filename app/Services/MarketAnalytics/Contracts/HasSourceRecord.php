<?php

namespace App\Services\MarketAnalytics\Contracts;

use App\Services\MarketAnalytics\Support\SourceRecord;

/**
 * Optional interface for data sources that expose their last query metadata.
 * Both adapters implement this so the service can collect DataSourceRecord
 * entries without coupling to concrete classes.
 */
interface HasSourceRecord
{
    public function getLastSourceRecord(): ?SourceRecord;
}
