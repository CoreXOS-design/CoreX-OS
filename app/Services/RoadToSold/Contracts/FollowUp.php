<?php

namespace App\Services\RoadToSold\Contracts;

/** One due follow-up, as the list builder consumes it. */
final class FollowUp
{
    public function __construct(
        public readonly ?int $trackedPropertyId,
        public readonly ?int $prospectingListingId = null,
        public readonly ?int $propertyId = null,
        public readonly string $reason = 'Follow-up due',
        /** The structured facts behind the line (what was said, when it was due) - stored beside it on the list item. */
        public readonly ?array $facts = null,
    ) {
    }
}
