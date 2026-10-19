<?php

namespace App\Services\Rentals;

use App\Models\RentalJobCardLine;

/**
 * .ai/specs/rental-work-orders.md §17.43 (audit C-E3): the crew is adding a part the office already has on the job card. Not an error - a
 * question: the crew link answers "Already on the job card. Add anyway?" and sends the same add again with `add_anyway` to go ahead.
 */
final class CrewLineLooksDuplicate extends \RuntimeException
{
    public function __construct(public readonly RentalJobCardLine $existing)
    {
        parent::__construct('Already on the job card. Add anyway?');
    }

    /** @return array{description: string, quantity: string, unit: ?string} what the office already has, for the question */
    public function summary(): array
    {
        return [
            'description' => (string) $this->existing->description,
            'quantity' => rtrim(rtrim(number_format((float) $this->existing->quantity, 2, '.', ''), '0'), '.'),
            'unit' => $this->existing->unit ?: null,
        ];
    }
}
