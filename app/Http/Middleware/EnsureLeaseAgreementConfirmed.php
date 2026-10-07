<?php

namespace App\Http\Middleware;

use App\Exceptions\Rentals\LeaseAgreementConfirmationRefused;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Lease;
use App\Services\Rentals\LeaseAgreementCheck;
use App\Services\Rentals\LeaseAgreementConfirmService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/leases.md §15.8.4 #1 / §15.9 (Build L3c) — sits on the e-sign engine's own "Approve" route
 * (docuperfect.signatures.approveAndAdvance) and does nothing at all unless the document being approved is a lease
 * agreement launched from a lease. The engine's controller is not touched.
 *
 * For a lease agreement waiting for the agent's final approval (R5):
 *   - no payload and nothing differs → straight through to the engine's approve action; the agent never sees an extra
 *     screen ("no difference → no extra screen", §15.9);
 *   - no payload and the agreement was changed in e-sign (or something cannot be verified) → the agent is sent to the
 *     lease screen in confirm mode, which shows the lease's value beside the agreement's for each difference;
 *   - the confirm screen's own post (`lease_confirm[fingerprint]`, `lease_confirm[entered][key]`) → the confirmation is
 *     taken here (the lease and its agreement details change, every change logged old → new) and the request then
 *     continues into the engine's unchanged approve action. A refusal (the agreement changed again, a different person
 *     is printed in it) sends the agent back to the screen with the reason. Nothing is written on a refusal.
 *
 * A fault in this check never blocks a legally valid approval: it is logged and the request goes through. The net is
 * the completion step, which re-checks on every path and leaves a lease it cannot vouch for signed but still a draft
 * (§15.5).
 */
class EnsureLeaseAgreementConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $lease = $this->leaseAwaitingApproval($request);

            if ($lease) {
                $payload = $request->input('lease_confirm');

                if (is_array($payload)) {
                    try {
                        app(LeaseAgreementConfirmService::class)->confirm(
                            $lease,
                            $request->user(),
                            (string) ($payload['fingerprint'] ?? ''),
                            (array) ($payload['entered'] ?? []),
                        );
                    } catch (LeaseAgreementConfirmationRefused $e) {
                        return redirect()->route('corex.leases.agreement.confirm', $lease)
                            ->withInput(['lease_confirm' => $payload])
                            ->with('error', $e->getMessage());
                    }
                } elseif (app(LeaseAgreementCheck::class)->verdict($lease)['needs_confirmation']) {
                    return redirect()->route('corex.leases.agreement.confirm', $lease);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Lease agreement check on approval failed — approval allowed to continue', [
                'document' => $request->route('document') instanceof Document ? $request->route('document')->id : $request->route('document'),
                'error' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }

    /**
     * The lease whose agreement this approval is — only when the user is allowed to confirm it and the envelope is at
     * the agent's final approval. Anyone else passes through untouched: the engine's own authorisation decides.
     */
    private function leaseAwaitingApproval(Request $request): ?Lease
    {
        $user = $request->user();
        $document = $request->route('document');
        $documentId = $document instanceof Document ? $document->id : (is_numeric($document) ? (int) $document : null);
        if (! $user || ! $documentId) {
            return null;
        }

        $envelope = SignatureTemplate::withoutGlobalScopes()->where('document_id', $documentId)->first();
        if (! $envelope || $envelope->status !== SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL) {
            return null;
        }

        $lease = Lease::withoutGlobalScopes()
            ->where('signature_template_id', $envelope->id)
            ->whereIn('signing_status', Lease::SIGNING_IN_FLIGHT)
            ->first();

        return $lease && app(LeaseAgreementConfirmService::class)->mayConfirm($user, $lease) ? $lease : null;
    }
}
