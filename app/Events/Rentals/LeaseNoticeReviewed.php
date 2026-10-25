<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\LeaseNotice;

/**
 * An agent accepted or rejected a notice given on a lease. AT-508 P1. Dispatched by LeaseNoticeService::accept() / reject().
 */
final class LeaseNoticeReviewed extends AbstractDomainEvent
{
    public function __construct(
        public readonly LeaseNotice $notice,
        public readonly string $outcome,   // 'accepted' | 'rejected'
        public readonly ?int $actorUserId = null,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->notice->agency_id ?? $this->notice->lease?->agency_id; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [LeaseNotice::class, $this->notice->id]; }

    public function context(): array
    {
        return ['outcome' => $this->outcome, 'given_by' => $this->notice->given_by];
    }
}
