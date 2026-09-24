<?php

declare(strict_types=1);

namespace App\Listeners\Onboarding;

use App\Events\AgencyCreated;
use App\Services\RentalApplications\RentalApplicationChecklistService;

/**
 * AT-430 §3.4 — "An agency that configures nothing gets the default
 * template below and can edit it." Same AgencyCreated-listener convention
 * as SeedDefaultRentalApplicationDeclineReasonTemplates/
 * SeedDefaultRentalApplicationHighlighters — a second, independent
 * reaction to the same signal. seedDefaultTemplateFor() is idempotent, so
 * firing this twice for the same agency is harmless.
 */
class SeedDefaultRentalApplicationChecklistTemplate
{
    public function handle(AgencyCreated $event): void
    {
        RentalApplicationChecklistService::seedDefaultTemplateFor($event->agency->id);
    }
}
