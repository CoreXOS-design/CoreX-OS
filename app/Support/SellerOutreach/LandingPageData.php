<?php

declare(strict_types=1);

namespace App\Support\SellerOutreach;

use App\Models\Property;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\User;

/**
 * Resolved landing-page data — output of SellerOutreachLandingService.
 * View consumes this to render Active / Generic / Agent-Unavailable mode.
 */
final class LandingPageData
{
    public const MODE_ACTIVE = 'active';
    public const MODE_GENERIC = 'generic';
    public const MODE_AGENT_UNAVAILABLE = 'agent_unavailable';

    public function __construct(
        public readonly string $mode,
        public readonly SellerOutreachSend $send,
        public readonly ?Property $property,
        public readonly User $contactCard,
        public readonly int $agencyId,
        public readonly string $agencyName,
        public readonly ?string $townName,
        public readonly int $liveBuyerCount,
        public readonly int $liveMatchingBuyerCount,
        public readonly string $agentWhatsappUrl,
        public readonly string $agencyBlurb,
    ) {}
}
