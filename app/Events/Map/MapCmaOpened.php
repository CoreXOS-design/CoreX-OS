<?php

declare(strict_types=1);

namespace App\Events\Map;

use App\Events\AbstractDomainEvent;
use App\Models\MarketReports\MarketReport;

/**
 * Phase A.2 — agent clicked "Open valuation" on an MIC subject record
 * from the map and navigated to the source report.
 *
 * event_type: `map_cma.opened`
 */
final class MapCmaOpened extends AbstractDomainEvent
{
    public function __construct(
        public readonly MarketReport $report,
        public readonly int $agencyId,
        public readonly int $actingUserId,
        public readonly string $locationKey,
        public readonly string $source,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return $this->agencyId; }
    public function actorUserId(): ?int { return $this->actingUserId; }

    public function subject(): ?array
    {
        return [MarketReport::class, (int) $this->report->id];
    }

    public function context(): array
    {
        return [
            'report_id'    => (int) $this->report->id,
            'location_key' => $this->locationKey,
            'source'       => $this->source,
        ];
    }
}
