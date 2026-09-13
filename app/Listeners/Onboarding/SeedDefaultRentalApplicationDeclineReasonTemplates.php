<?php

declare(strict_types=1);

namespace App\Listeners\Onboarding;

use App\Events\AgencyCreated;
use App\Models\RentalApplicationDeclineReasonTemplate;

/**
 * Decline reason templates, 2026-09-15 — Johan: "SEED SENSIBLE DEFAULTS so
 * an agency is useful on day one without writing a word." AgencyCreated is
 * the existing mechanism this codebase already uses for exactly this
 * problem (see SeedDefaultRentalApplicationHighlighters, registered the
 * same way in AppServiceProvider) — this listener is a second, independent
 * reaction to the same signal: seed the two starting decline reason
 * templates so a brand-new agency's decline flow has something real to
 * pick from immediately.
 *
 * seedDefaultsFor() is idempotent (no-ops if this agency already has any
 * template row), so firing this twice for the same agency is harmless.
 */
class SeedDefaultRentalApplicationDeclineReasonTemplates
{
    public function handle(AgencyCreated $event): void
    {
        RentalApplicationDeclineReasonTemplate::seedDefaultsFor($event->agency->id);
    }
}
