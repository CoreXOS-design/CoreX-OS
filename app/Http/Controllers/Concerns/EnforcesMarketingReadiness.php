<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Property;
use App\Services\Compliance\MarketingBlockedException;
use App\Services\Compliance\MarketingReadinessService;

trait EnforcesMarketingReadiness
{
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
