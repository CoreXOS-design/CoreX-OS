<?php

namespace App\Services\RoadToSold\Selections;

/** One property a selection proposes for an agent's day. */
final class Candidate
{
    public function __construct(
        public readonly ?int $trackedPropertyId,
        public readonly ?int $prospectingListingId,
        public readonly ?int $propertyId,
        public readonly string $selection,
        public readonly string $reason,
        public readonly bool $hasAddress,
        public readonly bool $inArea,
    ) {
    }

    /** The key a property is de-duplicated on within a day: tracked property first, else listing, else property. */
    public function dedupeKey(): string
    {
        if ($this->trackedPropertyId) {
            return 't' . $this->trackedPropertyId;
        }
        if ($this->prospectingListingId) {
            return 'l' . $this->prospectingListingId;
        }

        return 'p' . (int) $this->propertyId;
    }
}
