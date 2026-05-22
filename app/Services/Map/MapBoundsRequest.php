<?php

declare(strict_types=1);

namespace App\Services\Map;

/**
 * Phase 3g B1 — bounding-box + filter spec for MapPinService.
 *
 * Constructed from an HTTP request by MapController. Validation lives in
 * the controller's form-request layer; this DTO assumes valid input.
 */
final class MapBoundsRequest
{
    /** Hard cap so a malformed request can't ask for 1m pins. */
    public const MAX_LIMIT = 5000;

    /** @var string[] */
    public const VALID_LAYERS = [
        'hfc_listings', 'sold_comps', 'active_listings', 'mic_subjects', 'scheme_owners',
    ];

    public function __construct(
        public readonly float  $north,
        public readonly float  $south,
        public readonly float  $east,
        public readonly float  $west,
        /** @var string[] */
        public readonly array  $layers,
        public readonly string $viewMode,
        public readonly int    $agencyId,
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        /** @var string[] */
        public readonly array  $propertyTypes = [],
        public readonly ?int   $priceMin = null,
        public readonly ?int   $priceMax = null,
        public readonly int    $limit = 2000,
    ) {}

    public function isSellerView(): bool
    {
        return $this->viewMode === 'seller';
    }

    public function wantsLayer(string $key): bool
    {
        return in_array($key, $this->layers, true);
    }

    public function effectiveLimit(): int
    {
        return min(max(1, $this->limit), self::MAX_LIMIT);
    }
}
