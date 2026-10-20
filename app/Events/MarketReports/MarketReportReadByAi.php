<?php

declare(strict_types=1);

namespace App\Events\MarketReports;

use App\Events\AbstractDomainEvent;
use App\Models\MarketReports\MarketReport;

/**
 * Fires when a market report no known format or learned layout recognised was read by AI instead (read_method='ai'). The uploader and the platform owners are asked to check the figures.
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.9.
 */
final class MarketReportReadByAi extends AbstractDomainEvent
{
    public function __construct(
        public readonly MarketReport $report,
        ?string $traceId = null,
        /** true when the AI's earlier answer for this exact file was reused (no new AI cost). */
        public readonly bool $fromCache = false,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int    { return (int) $this->report->agency_id; }
    public function actorUserId(): ?int { return $this->report->uploaded_by_user_id ? (int) $this->report->uploaded_by_user_id : null; }

    public function subject(): ?array
    {
        return [MarketReport::class, (int) $this->report->id];
    }

    public function context(): array
    {
        return [
            'read_method' => $this->report->read_method,
            'note'        => $this->report->ai_read_note,
            'from_cache'  => $this->fromCache,
        ];
    }
}
