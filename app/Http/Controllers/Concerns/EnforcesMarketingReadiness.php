<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Property;
use App\Services\Compliance\MarketingBlockedException;
use App\Services\Compliance\MarketingReadinessService;
use App\Services\Syndication\DraftListingException;

trait EnforcesMarketingReadiness
{
    /**
     * Throws DraftListingException if the property is not currently on the
     * market. Call this BEFORE enforceMarketingReadiness() on every enable/
     * activate/submit/reactivate path so an off-market listing surfaces this
     * precise message rather than a generic compliance block. $portal names
     * the target (e.g. "Property24") so the error is specific.
     *
     * 2026-08-21 (Johan, .ai/specs/2026-08-20-property-status-prospecting.md)
     * — was isDraft() alone (a block-list of exactly one status), which let a
     * new 'prospecting' status (ingested-but-unmandated deeds/MIC stock, no
     * mandate at all) sail straight through undetected -- confirmed the same
     * gap exists in production, not just qa1. Fixed by pointing this check at
     * Property::OFF_MARKET_STATUSES via isOnMarket() -- the codebase's own
     * existing single source of truth for "not live stock", already
     * consulted by ~30 other call sites -- rather than adding a second,
     * prospecting-specific guard beside this one. This also closes a
     * pre-existing gap that had nothing to do with prospecting: withdrawn,
     * archived, cancelled, expired, etc. were never actually blocked from
     * syndication by this check either -- only literal 'draft' was.
     */
    protected function enforceListingNotDraft(Property $property, string $portal = 'any website or portal'): void
    {
        if (! $property->isOnMarket()) {
            throw new DraftListingException($property, $portal);
        }
    }

    /**
     * Throws MarketingBlockedException if the property is not compliance-ready.
     * Call at the start of any controller method that initiates external marketing.
     */
    protected function enforceMarketingReadiness(Property $property): void
    {
        $svc = app(MarketingReadinessService::class);
        if (!$svc->isMarketable($property)) {
            throw new MarketingBlockedException($svc->statusFor($property));
        }
    }
}
