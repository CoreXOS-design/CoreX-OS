<?php

declare(strict_types=1);

namespace App\Listeners\Onboarding;

use App\Events\AgencyCreated;
use App\Models\RentalApplicationHighlighter;

/**
 * Highlighter collection expansion, 2026-09-09 — Johan: "sensible defaults
 * so it works out of the box for a new agency... use the existing
 * mechanism rather than inventing a parallel one." AgencyCreated is that
 * existing mechanism (see CreateAgencySetupPortal, registered the same way
 * in AppServiceProvider) — this listener just adds a second, independent
 * reaction to the same signal: seed the six starting highlighters so a
 * brand-new agency's review/authorisation screens work immediately, with
 * nothing to configure first.
 *
 * seedDefaultsFor() is idempotent (no-ops if this agency already has any
 * highlighter row), so firing this twice for the same agency is harmless.
 */
class SeedDefaultRentalApplicationHighlighters
{
    public function handle(AgencyCreated $event): void
    {
        RentalApplicationHighlighter::seedDefaultsFor($event->agency->id);
    }
}
