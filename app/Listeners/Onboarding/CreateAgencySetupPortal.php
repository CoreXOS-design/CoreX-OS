<?php

declare(strict_types=1);

namespace App\Listeners\Onboarding;

use App\Events\AgencyCreated;
use App\Mail\AgencyOnboardingSetupMail;
use App\Models\AgencyOnboardingSetup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Creates the AgencyOnboardingSetup record for a newly-created agency and
 * emails its Admin the guided-setup link.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md §3.5
 *
 * Wired by Laravel's automatic listener discovery (it scans app/Listeners and
 * binds this handle() to its type-hinted event). Do NOT add an explicit
 * Event::listen() in AppServiceProvider — that double-registers the listener
 * and it fires twice (two portals / two emails). See the double-registration
 * trap in .ai/audits/mandate-expiry-desyndication-2026-06-20.md.
 *
 * Idempotent (E5): firstOrCreate on agency_id means firing twice yields one
 * portal. The email is only sent when the record is created fresh, so a
 * re-fire never double-emails.
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
            Log::info('AgencyOnboardingSetup already exists — not re-creating or re-emailing.', [
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

        try {
            // Send via the dedicated 'corex' mailer so it delivers even where
            // the default mailer is 'log' (staging). Mirrors UserInviteMail.
            Mail::mailer('corex')
                ->to($adminEmail)
                ->send(new AgencyOnboardingSetupMail($setup));
        } catch (\Throwable $e) {
            // The record is already created and resumable from the tracking
            // page, so a mail hiccup must not roll anything back or 500 the
            // create request. Log and move on; the link can be re-sent.
            Log::error('Failed to send agency onboarding setup email.', [
                'agency_id' => $agency->id,
                'setup_id'  => $setup->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
