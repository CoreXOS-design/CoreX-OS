<?php

declare(strict_types=1);

namespace App\Support\Prospecting;

/**
 * Unified read-model representing one prospecting opportunity.
 *
 * Returned by {@see \App\Services\Prospecting\ProspectingListingResolver}.
 * Consumers (intelligence service, controller, UI partial) should treat this
 * as the canonical shape and never touch source tables directly.
 *
 * Multi-tenancy invariant: agency_id is always populated. The resolver
 * refuses to build a ResolvedListing for a row outside the requested agency.
 *
 * Spec: .ai/specs/prospecting-intelligence-spec.md
 */
final class ResolvedListing
{
    public function __construct(
        /** 'p24' | 'pp' — discriminator from the prospecting_listings.portal_source enum. */
        public readonly string $source,
        public readonly int $sourceId,
        public readonly string $sourceTable,
        public readonly int $agencyId,
        /** Agent who captured the listing (no contact link; this stream is portal-discovered, not contact-tied). */
        public readonly ?int $capturedByUserId,
        public readonly ?string $suburbName,
        public readonly ?string $suburbNormalised,
        /** Resolved via ProspectingConfigurationService; null if the suburb has no town mapping. */
        public readonly ?int $townId,
        /** Either real town name or '(unmapped) {suburb}'. */
        public readonly ?string $townName,
        public readonly ?string $propertyTypeRaw,
        /** Resolved via slug match against PropertyTypeOption; null if unmapped. */
        public readonly ?int $propertyTypeOptionId,
        public readonly ?int $bedrooms,
        public readonly ?int $bedroomSegmentId,
        public readonly ?int $price,
        public readonly string $listingType,           // always 'sale' for v1 — source table has no rental column
        public readonly ?int $priceBandId,
        /** Normalised "active" | "archived". */
        public readonly string $status,
        public readonly ?string $address,
        public readonly ?string $title,
        public readonly ?\DateTimeImmutable $sourcedAt,
        /** When the listing was last seen on the portal. */
        public readonly ?\DateTimeImmutable $lastSeenAt,
        /** Linked Property id when this prospect listing matches our own stock; null otherwise. */
        public readonly ?int $matchedPropertyId,
        /** Source-specific metadata bag (agent name, agency, URL, thumbnail, etc.). */
        public readonly array $sourceMeta = [],
    ) {}

    public function isUnmappedSuburb(): bool
    {
        return $this->suburbName !== null && $this->townId === null;
    }
}
