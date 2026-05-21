<?php

declare(strict_types=1);

namespace App\Events\MarketReports;

use App\Events\AbstractDomainEvent;
use App\Models\MarketReports\MarketReport;

/**
 * Fires when the AI spot-check on a parsed report finds discrepancies
 * (spot_check_status='flagged'). Severity ≥ medium fires a super-admin
 * notification via a separate Phase B listener.
 *
 * Spec §14.4.
 */
final class MarketReportSpotCheckFlagged extends AbstractDomainEvent
{
    public function __construct(
        public readonly MarketReport $report,
        public readonly int $discrepancyCount,
        public readonly string $maxSeverity,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return (int) $this->report->agency_id; }
    public function actorUserId(): ?int { return null; /* AI-detected */ }

    public function subject(): ?array
    {
        return [MarketReport::class, (int) $this->report->id];
    }

    public function context(): array
    {
        return [
            'discrepancy_count' => $this->discrepancyCount,
            'max_severity'      => $this->maxSeverity,
            'report_type_id'    => (int) $this->report->report_type_id,
        ];
    }
}
