<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalWorkCompletionRound;

/**
 * A completion round passed the end of its response window with no answer, so the finished work counts as accepted. Dispatched by the daily settle command (Build 3). Spec §17.10.8.
 */
final class RentalCompletionSettledBySilence extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalWorkCompletionRound $round,
        ?string $traceId = null,
    ) {
        parent::__construct($traceId);
    }

    public function agencyId(): ?int { return $this->round->agency_id; }
    public function subject(): ?array { return [RentalWorkCompletionRound::class, $this->round->id]; }

    public function context(): array
    {
        return ['round_no' => $this->round->round_no, 'rental_work_order_id' => $this->round->rental_work_order_id];
    }
}
