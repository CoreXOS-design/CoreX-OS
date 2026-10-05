<?php

declare(strict_types=1);

namespace App\Listeners\Onboarding;

use App\Events\AgencyCreated;
use App\Models\RentalVatType;

/**
 * Agency VAT set-up — same established AgencyCreated reaction every other
 * per-agency default list in this codebase uses (RentalApplicationHighlighter,
 * RentalApplicationDeclineReasonTemplate, etc.): a brand-new agency gets
 * Standard/No VAT/Custom immediately, nothing to configure first.
 * seedDefaultsFor() is idempotent — firing this twice is harmless.
 */
class SeedDefaultRentalVatTypes
{
    public function handle(AgencyCreated $event): void
    {
        RentalVatType::seedDefaultsFor($event->agency->id);
    }
}
