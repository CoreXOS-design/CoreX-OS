<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Prospecting\TrackedProperty;

/**
 * Thrown by TrackedPropertyMatchOrCreateService::promoteToStock() — only when the caller asked to be
 * told ($askOnPossible) — when promoting would otherwise silently create a SECOND property beside one
 * that is only a POSSIBLE match (a neighbouring suburb, a different street type, a missing unit/portion).
 * Decision 2 of the structured-address-matching build: promote never links on a possible match and never
 * creates beside one without the agent choosing "same property" or "different property" first.
 * Nothing has been written when this is thrown.
 */
final class PossiblePropertyMatchException extends \DomainException
{
    /**
     * @param  array<int, array{property: \App\Models\Property, reason: string, columns: array<string,string>, matched_on: array<int,string>, score: int}>  $possible
     */
    public function __construct(public readonly TrackedProperty $trackedProperty, public readonly array $possible)
    {
        parent::__construct('A property that may be the same as this one is already on file. Choose "Same property" or "Different property" first — nothing was created.');
    }
}
