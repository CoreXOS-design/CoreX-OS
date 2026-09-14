<?php

declare(strict_types=1);

namespace App\Listeners\Esign;

use App\Events\Esign\ComplianceApprovalDecided;
use App\Models\Docuperfect\EsignApproval;
use App\Models\User;
use App\Notifications\SignatureActivityNotification;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

/**
 * Tell the sender what the officer decided — approved (and released), declined (with the reason),
 * or overridden by the CO. Spec §6.3 / §8.1. Explicitly registered (discovery OFF).
 */
class NotifySenderOfApprovalDecision
{
    public function handle(ComplianceApprovalDecided $event): void
    {
        $approval = $event->approval;
        $template = $event->template;

        $sender = $approval->requested_by_user_id
            ? User::withoutGlobalScopes()->find($approval->requested_by_user_id)
            : null;
        if (! $sender) {
            return; // deleted sender — nothing to tell, nothing to break
        }

        $officerName  = $approval->decider?->name ?? 'A compliance officer';
        $documentName = $template->document?->name ?? 'Untitled document';
        $url          = route('docuperfect.esign.myDocuments');

        $notification = $approval->status === EsignApproval::STATUS_APPROVED
            ? SignatureActivityNotification::complianceApprovalGranted($officerName, $documentName, (int) $template->document_id, $url, (bool) $approval->is_override)
            : SignatureActivityNotification::complianceApprovalDeclined($officerName, $documentName, (int) $template->document_id, $url, (string) $approval->decision_note);

        try {
            app(NotificationDispatcher::class)->send(
                $sender,
                'esign.approval_decided',
                $approval,
                $notification,
                [
                    'threshold_hit_at' => optional($approval->decided_at)->toIso8601String() ?? now()->toIso8601String(),
                    'approval_id'      => $approval->id,
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('E-sign approval decision notification failed (non-fatal)', [
                'approval_id' => $approval->id,
                'sender_id'   => $sender->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
