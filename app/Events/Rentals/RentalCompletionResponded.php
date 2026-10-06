<?php

declare(strict_types=1);

namespace App\Events\Rentals;

use App\Events\AbstractDomainEvent;
use App\Models\RentalWorkCompletionRound;

/**
 * A tenant (response link or portal), or the office on their behalf, answered a completion round: confirmed, or reported the work as not complete (which reopens the job as Disputed). Dispatched by Build 3. Spec §17.10.4–§17.10.6.
 */
final class RentalCompletionResponded extends AbstractDomainEvent
{
    public function __construct(
        public readonly RentalWorkCompletionRound $round,
        public readonly string $outcome,
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
        return ['round_no' => $this->round->round_no, 'outcome' => $this->outcome, 'via' => $this->via];
    }
}
