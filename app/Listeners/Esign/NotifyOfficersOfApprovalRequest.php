<?php

declare(strict_types=1);

namespace App\Listeners\Esign;

use App\Events\Esign\ComplianceApprovalRequested;
use App\Models\Compliance\OfficerAppointment;
use App\Notifications\SignatureActivityNotification;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Compliance\OfficerRegistry;
use App\Services\Docuperfect\EsignApprovalService;
use Illuminate\Support\Facades\Log;

/**
 * Tell every e-sign officer whose scope covers the held document that it is waiting. Sync (the
 * sender expects the officers to know the moment they see "awaiting approval"), idempotent through
 * the gateway's threshold_hit_at dedup key (one alert per approval row per officer).
 *
 * Spec §6.2 / §8.1. Registered explicitly in AppServiceProvider — discovery is OFF (AT-261).
 */
class NotifyOfficersOfApprovalRequest
{
    public function __construct(
        private OfficerRegistry $registry,
        private EsignApprovalService $approvals,
    ) {}

    public function handle(ComplianceApprovalRequested $event): void
    {
        $approval = $event->approval;
        $template = $event->template;
        $agencyId = (int) $approval->agency_id;
        if ($agencyId <= 0) {
            return;
        }

        $documentName = $template->document?->name ?? 'Untitled document';
        $senderName   = $template->creator?->name ?? 'An agent';
        $queueUrl     = route('docuperfect.approvals.index');

        foreach ($this->registry->officers($agencyId, OfficerAppointment::MODULE_ESIGN) as $officer) {
            if ((int) $officer->id === (int) $approval->requested_by_user_id) {
                continue; // the sender never gets their own request
            }
            if (! $this->approvals->canAct($officer, $approval)) {
                continue; // outside their own / branch / all scope
            }

            try {
                app(NotificationDispatcher::class)->send(
                    $officer,
                    'esign.approval_requested',
                    $approval,
                    SignatureActivityNotification::complianceApprovalRequested($senderName, $documentName, (int) $template->document_id, $queueUrl),
                    [
                        'threshold_hit_at' => optional($approval->created_at)->toIso8601String() ?? now()->toIso8601String(),
                        'approval_id'      => $approval->id,
                    ],
                );
            } catch (\Throwable $e) {
                // A notification failure must never block the hold itself (FICA precedent).
                Log::warning('E-sign approval request notification failed (non-fatal)', [
                    'approval_id' => $approval->id,
                    'officer_id'  => $officer->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }
    }
}
