<?php

declare(strict_types=1);

namespace App\Listeners\SoloAgent;

use App\Events\Platform\EsignDocumentCompleted;
use App\Models\SoloAgentSignup;
use App\Services\SoloAgent\SoloAgentService;
use Illuminate\Support\Facades\Log;

/**
 * The single-agent agreement is fully signed: open the account (login on, password-setup email out).
 * Spec: .ai/specs/solo-agent-accounts.md section 4.2 step 3.
 *
 * Acts ONLY on a document of kind 'solo_agent_agreement' that is the very agreement this agency's sign-up sent -
 * a different contract signed for the same agency can never open the account. Idempotent (SoloAgentService::activate).
 * SYNC; EsignService::complete() isolates any failure from the signer.
 */
class ActivateSoloAgentOnAgreementSigned
{
    public function __construct(private readonly SoloAgentService $solo)
    {
    }

    public function handle(EsignDocumentCompleted $event): void
    {
        if ($event->templateKind !== SoloAgentService::TEMPLATE_KIND || ! $event->agencyId) {
            return;
        }

        $signup = SoloAgentSignup::withTrashed()->where('agency_id', $event->agencyId)->first();
        if (! $signup) {
            return;
        }
        if ((int) $signup->agreement_document_id !== $event->documentId) {
            Log::warning('Solo agent: a signed agreement does not belong to this sign-up - account not opened', [
                'signup_id' => $signup->id, 'document_id' => $event->documentId,
            ]);

            return;
        }

        $this->solo->activate($signup, $event->documentId);
    }
}
