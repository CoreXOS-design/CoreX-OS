<?php

declare(strict_types=1);

namespace App\Events\MarketReports;

use App\Events\AbstractDomainEvent;
use App\Models\MarketReports\MarketDataPoint;

/**
 * Fires when a market_data_points row is superseded by a newer one (is_superseded
 * flipped to true, superseded_by_id set). Allows downstream caches to invalidate.
 *
 * Spec §14.4.
 */
final class MarketDataPointSuperseded extends AbstractDomainEvent
{
    public function __construct(
        public readonly MarketDataPoint $oldPoint,
        public readonly int $newPointId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return (int) $this->oldPoint->agency_id; }
    public function actorUserId(): ?int { return null; /* parser-emitted */ }

    public function subject(): ?array
    {
        return [MarketDataPoint::class, (int) $this->oldPoint->id];
    }

    public function context(): array
    {
        return [
            'new_point_id' => $this->newPointId,
            'metric_key'   => (string) $this->oldPoint->metric_key,
            'metric_date'  => optional($this->oldPoint->metric_date)->toDateString(),
        ];
    }
}
