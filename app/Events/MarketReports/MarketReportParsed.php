<?php

declare(strict_types=1);

namespace App\Events\MarketReports;

use App\Events\AbstractDomainEvent;
use App\Models\MarketReports\MarketReport;

/**
 * Fires when a market_report transitions to parse_status='parsed' (the
 * parser ran cleanly and wrote market_data_points). Downstream listeners
 * may trigger the spot-check audit, refresh AI brief cache, etc.
 *
 * Spec §14.4.
 */
final class MarketReportParsed extends AbstractDomainEvent
{
    public function __construct(
        public readonly MarketReport $report,
        public readonly int $dataPointsWritten,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return (int) $this->report->agency_id; }
    public function actorUserId(): ?int { return (int) $this->report->uploaded_by_user_id; }

    public function subject(): ?array
    {
        return [MarketReport::class, (int) $this->report->id];
    }

    public function context(): array
    {
        return [
            'data_points_written' => $this->dataPointsWritten,
            'parser_version'      => $this->report->parser_version,
            'report_type_id'      => (int) $this->report->report_type_id,
        ];
    }
}
