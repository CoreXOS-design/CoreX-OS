<?php

namespace App\Services\RentalApplications;

use App\Mail\RentalApplicationDecisionMail;
use App\Mail\RentalApplicationReturnedMail;
use App\Models\RentalApplication;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * AT-392, Johan 2026-09-07 — notifies the sending agent when their tenant's
 * application comes back. Deliberately its own file, separate from
 * RentalApplicationMailer (the applicant-facing invite), and from anything
 * on the agent-side lane — new, additive, nothing shared touched.
 */
class RentalApplicationNotifier
{
    /**
     * Best-effort — a notification failure must never break the applicant's
     * submit action itself (their data is already saved either way), same
     * "never let mail break the real action" posture as the invite mailer.
     */
    public function notifyAgentOfReturn(RentalApplication $application): bool
    {
        $agentEmail = $application->createdBy?->email;
        if (! $agentEmail) {
            return false;
        }

        try {
            Mail::to($agentEmail)->send(new RentalApplicationReturnedMail($application));

            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application returned-notification mail failed', [
                'rental_application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * AT-392 authoriser flow — notifies the agent of an authoriser decision
     * (approved / declined / more info requested). Same best-effort posture
     * as notifyAgentOfReturn() above.
     */
    public function notifyAgentOfDecision(RentalApplication $application, string $decision, ?string $reason = null, bool $isOverride = false): bool
    {
        $agentEmail = $application->createdBy?->email;
        if (! $agentEmail) {
            return false;
        }

        try {
            Mail::to($agentEmail)->send(new RentalApplicationDecisionMail($application, $decision, $reason, $isOverride));

            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application decision-notification mail failed', [
                'rental_application_id' => $application->id,
                'decision' => $decision,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
