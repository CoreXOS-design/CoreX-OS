<?php

declare(strict_types=1);

namespace App\Listeners\Onboarding;

use App\Events\AgencyCreated;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;

/**
 * Pastel-style enhancement, 2026-10-05 — same established AgencyCreated
 * reaction as SeedDefaultRentalVatTypes: a brand-new agency gets the
 * Labour/Part catalogue types and the eleven default units immediately,
 * nothing to configure first. Both seedDefaultsFor() calls are idempotent.
 */
class SeedDefaultRentalCatalogueTypesAndUnits
{
    public function handle(AgencyCreated $event): void
    {
        RentalCatalogueItemType::seedDefaultsFor($event->agency->id);
        RentalCatalogueUnit::seedDefaultsFor($event->agency->id);
    }
}
