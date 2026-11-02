<?php

declare(strict_types=1);

namespace App\Listeners\RoadToSold;

use App\Events\Property\PropertyCompliancePassed;
use App\Events\Property\PropertyPublished;
use App\Services\RoadToSold\BeltLinks;

/**
 * Road to Sold v4, station 10 "Active" (road-to-sold.md 23.4): compliance passed AND published. Either event can arrive first; each asks BeltLinks to look at the property
 * as it stands, so the second one to arrive is the one that moves the record. Never throws to the caller.
 */
class MoveBeltWithProperty
{
    public function __construct(private readonly BeltLinks $links)
    {
    }

    public function handleCompliancePassed(PropertyCompliancePassed $e): void
    {
        $this->links->syncPropertyStage($e->property->fresh() ?? $e->property);
    }

    public function handlePublished(PropertyPublished $e): void
    {
        $this->links->syncPropertyStage($e->property->fresh() ?? $e->property);
    }
}
