<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Backfill AgencyOnboardingSetup records for agencies that predate the wizard.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §4.1.
 *
 * The feature's normal trigger (AgencyCreated → CreateAgencySetupPortal) only
 * fires for agencies created AFTER it shipped. This migration carries the
 * backfill across every environment on deploy (migrate --force), so existing
 * agencies appear on the owner tracking board and their admins get the nudge.
 *
 * Delegates to the idempotent command so the logic lives in one place and can be
 * re-run manually. No email on the deploy path (existing agencies are already
 * operating — a blast would be wrong; use --email deliberately if ever wanted).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: if the command isn't registered for any reason, don't fail the
        // migration run — the wizard lazily creates a record on first open, and
        // the command can be run by hand afterwards.
        try {
            Artisan::call('agency:backfill-onboarding-setups');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                'Agency onboarding backfill migration could not run the command; '
                . 'lazy-create still covers it. ' . $e->getMessage()
            );
        }
    }

    public function down(): void
    {
        // Non-destructive: we do not delete backfilled setups on rollback (they
        // may hold real progress). No-op.
    }
};
