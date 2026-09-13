<?php

declare(strict_types=1);

namespace App\Listeners\RentalApplications;

use App\Events\Fica\FicaApproved;
use App\Models\RentalApplication;
use App\Models\RentalApplicationStatusHistory;
use App\Models\User;
use App\Services\RentalApplications\RentalApplicationAuditService;
use Illuminate\Support\Facades\Log;

/**
 * AT-410d, 2026-09-16 — Johan's ruling: an authoriser may approve an
 * applicant whose FICA is still outstanding ("yes, can become approved
 * subject to fica verification"), but that condition must resolve itself
 * the moment FICA is actually verified — "if a human has to remember to
 * come back and upgrade it, it will not happen."
 *
 * Subscribed to the SAME domain event FicaController::complianceApprove()
 * already fires the moment a compliance officer approves a FICA
 * submission (App\Events\Fica\FicaApproved — see .ai/specs/
 * corex-domain-events-spec.md) — no polling, no cron, no separate hook to
 * remember to wire up elsewhere. A second listener on an event that
 * already has one (LogFicaEvent) is the established pattern here, not a
 * new one.
 *
 * Only clears the condition, never touches status/approved_rental_amount
 * — an already-approved application stays approved either way; this only
 * removes the qualifier once the fact it names is no longer true.
 * Deliberately re-checks status === 'approved' at resolution time (not
 * just "has the column set") — an override that later moved this
 * application to declined must not have its now-irrelevant FICA
 * condition silently cleared as if it still mattered.
 */
class ResolveConditionalApprovalOnFicaVerified
{
    public function handle(FicaApproved $event): void
    {
        try {
            $applications = RentalApplication::where('contact_id', $event->contact->id)
                ->where('status', 'approved')
                ->whereNotNull('approved_subject_to_fica_at')
                ->get();

            if ($applications->isEmpty()) {
                return;
            }

            // Explicit, not left to fall through to auth()->user() inside
            // the audit service — this event fires synchronously inside
            // the compliance officer's own request, so an implicit fallback
            // would happen to resolve to the same person, but relying on
            // that would silently break if this event is ever queued.
            $approvedBy = $event->approvedByUserId ? User::find($event->approvedByUserId) : null;

            foreach ($applications as $application) {
                $application->approved_subject_to_fica_at = null;
                $application->save();

                RentalApplicationStatusHistory::record(
                    $application, 'approved', 'approved', $approvedBy,
                    'FICA verified — the "subject to FICA verification" condition on this approval was cleared automatically.',
                );

                app(RentalApplicationAuditService::class)->log(
                    $application,
                    eventCategory: 'compliance',
                    eventType: 'approval_fica_condition_resolved',
                    user: $approvedBy,
                    newValues: ['approved_subject_to_fica_at' => null],
                    metadata: ['fica_submission_id' => $event->package->id],
                    humanSummary: 'FICA verified — the approval on this application is no longer conditional.',
                );
            }
        } catch (\Throwable $e) {
            Log::warning('ResolveConditionalApprovalOnFicaVerified failed', [
                'contact_id' => $event->contact->id,
                'fica_submission_id' => $event->package->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
