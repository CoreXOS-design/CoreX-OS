<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalWorkCompletionRound;

/**
 * Work was reported done — by the crew (link / page / signed copy), the office, or captured from a contractor — and a completion round was opened for the tenant check. Dispatched by Build 3. Spec §17.10.1.
 */
final class RentalWorkReportedDone extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalWorkCompletionRound $round,
        public readonly string $via,
        public readonly ?int $actorUserId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->round->agency_id; }
    public function actorUserId(): ?int { return $this->actorUserId; }
    public function subject(): ?array { return [RentalWorkCompletionRound::class, $this->round->id]; }

    public function context(): array
    {
        return ['round_no' => $this->round->round_no, 'via' => $this->via, 'rental_work_order_id' => $this->round->rental_work_order_id];
    }
}
