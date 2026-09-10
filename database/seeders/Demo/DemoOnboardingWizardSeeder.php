<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;

/**
 * Configuration sweep addendum (2026-09-02, webinar prep) — /corex/agency-setup
 * showed "Step 1 of 16 · 0%" even though the agency is fully configured
 * everywhere else (company settings, branches, roles, users all fixed by
 * this same sweep). The wizard's own field values were already correct
 * (it reads live from the same stores the settings page uses) — only its
 * progress tracker was stuck at the initial state, because nobody had
 * ever walked through it for agency 1.
 *
 * Marks every step that's actually ACTIVE for this agency (respecting the
 * same feature-gating AgencyOnboardingSetup::activeSteps() uses — a
 * gated-off step, e.g. Proforma if that feature were off, is correctly
 * never "completed") as done, and marks the setup itself completed.
 *
 * IDEMPOTENT BY CONSTRUCTION: no-ops once completed_at is already set.
 */
class DemoOnboardingWizardSeeder
{
    /** @return array{completed:bool, note:string} */
    public function run(int $agencyId = 1): array
    {
        $setup = AgencyOnboardingSetup::where('agency_id', $agencyId)->first();
        if (!$setup) {
            return ['completed' => false, 'note' => 'Skipped — no onboarding setup row for this agency.'];
        }
        if ($setup->completed_at !== null) {
            return ['completed' => false, 'note' => 'Skipped — onboarding already marked complete.'];
        }

        $agency = Agency::find($agencyId);
        $activeSteps = AgencyOnboardingSetup::activeSteps($agency);

        $setup->completed_steps = $activeSteps;
        $setup->current_step = AgencyOnboardingSetup::totalSteps();
        $setup->completed_at = now();
        $setup->last_opened_at = now();
        $setup->open_count = max(1, $setup->open_count);
        $setup->save();

        $note = 'Onboarding wizard marked complete — ' . count($activeSteps) . ' active steps.';

        return ['completed' => true, 'note' => $note];
    }
}
