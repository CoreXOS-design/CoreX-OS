<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Property;
use App\Services\Compliance\MarketingBlockedException;
use App\Services\Compliance\MarketingReadinessService;
use App\Services\Syndication\DraftListingException;

trait EnforcesMarketingReadiness
{
    /**
     * Throws DraftListingException if the property is still a draft. A draft is
     * never publishable to any portal/website — it must be set Active first.
     * Call this BEFORE enforceMarketingReadiness() on every enable/activate/
     * submit/reactivate path so a draft surfaces the precise "set to Active"
     * message rather than a generic compliance block. $portal names the target
     * (e.g. "Property24") so the error is specific.
     */
    protected function enforceListingNotDraft(Property $property, string $portal = 'any website or portal'): void
    {
        if ($property->isDraft()) {
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
