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
        /** Why the AI step did not read it: ai_monthly_cap | ai_platform_daily_cap | layout_case_open | ai_failed | … */
        public readonly ?string $reasonCode = null,
        /** true when this report opened a new layout case (the team is alerted once per case). */
        public readonly bool $newCase = false,
        /** The layout case the report sits in, or null when it could not be fingerprinted (no case exists). */
        public readonly ?int $caseId = null,
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
            'reason_code' => $this->reasonCode,
            'new_case'    => $this->newCase,
            'case_id'     => $this->caseId,
        ];
    }
}
