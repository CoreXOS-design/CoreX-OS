<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalJobCard;

/**
 * The crew pressed "Send to office" on their link: parts / labour lines (a price for the job, or extras) are waiting for the office to accept and price. One event per send, never per line. Dispatched by Build 1. Spec §17.5.
 */
final class RentalCrewLinesSubmitted extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalJobCard $jobCard,
        public readonly int $count,
        public readonly string $via,
        public readonly ?int $priceRequestId,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->jobCard->agency_id; }
    public function subject(): ?array { return [RentalJobCard::class, $this->jobCard->id]; }

    public function context(): array
    {
        return ['count' => $this->count, 'via' => $this->via, 'rental_job_card_price_request_id' => $this->priceRequestId];
    }
}
