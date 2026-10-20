<?php

declare(strict_types=1);

namespace App\Events\MarketReports;

use App\Events\AbstractDomainEvent;
use App\Models\MarketReports\MarketReport;

/**
 * Fires when a market report could not be read at all — no known format, no learned layout, and the AI step was off, refused or failed (read_method='unread'). The file is kept and sits in the CoreX admin queue.
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.9.
 */
final class MarketReportUnreadable extends AbstractDomainEvent
{
    public function __construct(
        public readonly MarketReport $report,
        ?string $traceId = null,
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
        ];
    }
}
