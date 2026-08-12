<?php

declare(strict_types=1);

namespace App\Listeners\Onboarding;

use App\Events\AgencyCreated;
use App\Models\AgencyOnboardingSetup;
use Illuminate\Support\Facades\Log;

/**
 * Creates the AgencyOnboardingSetup record for a newly-created agency.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §3.5
 * AMENDED 2026-08-12 (.ai/specs/agency-admin-rule.md §R1a/§R1b): this listener no
 * longer emails AgencyOnboardingSetupMail. At creation time the new Admin has no
 * password yet (email-only invite — UserInviteMail is what goes out, sent from
 * AgencyController@store). AgencyOnboardingSetupMail now fires on the Admin's
 * first successful login (see App\Services\Onboarding\AgencyAdminFirstLoginService)
 * or via manual resend from the owner tracking page — never here.
 *
 * Wired by Laravel's automatic listener discovery (it scans app/Listeners and
 * binds this handle() to its type-hinted event). Do NOT add an explicit
 * Event::listen() in AppServiceProvider — that double-registers the listener
 * and it fires twice (two portals). See the double-registration trap in
 * .ai/audits/mandate-expiry-desyndication-2026-06-20.md.
 *
 * Idempotent (E5): firstOrCreate-style guard below means firing twice yields
 * one portal, never two.
 */
class CreateAgencySetupPortal
{
    public function handle(AgencyCreated $event): void
    {
        $agency = $event->agency;
        $adminEmail = $event->adminEmail ?: $event->adminUser?->email;

        // No admin email = nothing to onboard (should not happen for a live
        // agency, but absorb rather than break — BUILD_STANDARD §3).
        if (!$adminEmail) {
            Log::warning('AgencyCreated without admin email — skipping onboarding setup.', [
                'agency_id' => $agency->id,
            ]);
            return;
        }

        // Idempotent: one live setup per agency. queryWithoutAgencyScope keeps
        // this correct even when fired from a console/queue context with no
        // authenticated tenant (the model's BelongsToAgency scope would
        // otherwise filter by the actor's agency, not the new agency).
        $existing = AgencyOnboardingSetup::queryWithoutAgencyScope()
            ->where('agency_id', $agency->id)
            ->first();

        if ($existing) {
            Log::info('AgencyOnboardingSetup already exists — not re-creating.', [
                'agency_id' => $agency->id,
                'setup_id'  => $existing->id,
            ]);
            return;
        }

        $setup = new AgencyOnboardingSetup();
        $setup->agency_id        = $agency->id;
        $setup->token            = AgencyOnboardingSetup::generateToken();
        $setup->slug             = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $setup->created_by       = $event->createdByUserId;
        $setup->admin_user_id    = $event->adminUser?->id;
        $setup->current_step     = 1;
        $setup->completed_steps  = [];
        $setup->expires_at       = now()->addDays(30);
        $setup->save();
    }
}
