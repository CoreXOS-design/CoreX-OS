<?php

declare(strict_types=1);

namespace App\Events\MarketReports;

use App\Events\AbstractDomainEvent;
use App\Models\MarketReports\MarketReport;

/**
 * Fires when a market_report row is created (file uploaded; parse_status =
 * pending). Triggers the parser pipeline. Spec §14.4.
 */
final class MarketReportUploaded extends AbstractDomainEvent
{
    public function __construct(
        public readonly MarketReport $report,
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
            'report_type_id' => (int) $this->report->report_type_id,
            'file_name'      => (string) $this->report->file_name,
            'file_hash'      => (string) $this->report->file_hash,
        ];
    }
}
