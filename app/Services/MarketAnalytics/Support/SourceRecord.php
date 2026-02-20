<?php

namespace App\Services\MarketAnalytics\Support;

use App\Services\MarketAnalytics\Contracts\DataSourceRecord;

/**
 * Concrete value object capturing metadata about a single data source query.
 * Implements DataSourceRecord so it can be stored in data_sources_json.
 */
class SourceRecord implements DataSourceRecord
{
    public function __construct(
        public readonly string  $sourceTag,
        public readonly int     $rowCount,
        public readonly string  $queryHash,
        // Optional snapshot metadata (populated by ImportedListingsAdapter)
        public readonly ?int    $snapshotRunId     = null,
        public readonly ?string $snapshotCreatedAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'source_tag'          => $this->sourceTag,
            'row_count'           => $this->rowCount,
            'query_hash'          => $this->queryHash,
            'snapshot_run_id'     => $this->snapshotRunId,
            'snapshot_created_at' => $this->snapshotCreatedAt,
        ];
    }
}
