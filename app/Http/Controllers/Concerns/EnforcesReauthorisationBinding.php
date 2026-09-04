<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Docuperfect\EsignSettings;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;

/**
 * AT-332 — Johan, verbatim: "re-auth only allowed by original auth party."
 * After a recipient amends a document, re-authorisation must come from the
 * SAME user who authorised the original — not the party slot, not any
 * other full-status agent with document-edit permission. Must survive
 * multiple amendment rounds: signature_requests.authorised_by is a
 * write-once column (SignatureService.php ~1510/1542 — `?? auth()->id()`
 * guard) on the supervisor/supervisor_final row, and that row is reused
 * (never recreated) by requeueAllPartiesForInitialing() across every
 * amendment round, so the bound user stays the same every time.
 *
 * Closes three previously-unbound re-approval paths (2026-08 audit —
 * .ai/audits/2026-08-01-candidate-authoriser-signing-coupling.md):
 * AmendmentController::approve(), SignatureController::amendmentAction()
 * (which bulk-accepted every pending AmendmentAcceptance row with no check
 * at all), and SignatureController::approveAmendmentNode(). All three
 * previously only checked scope (guardDocument() — agency/branch/owner),
 * never identity — any full-status agent with documents.edit in scope
 * could re-authorise someone else's amendment.
 *
 * Deliberately does NOT throw/403 — Johan: "A blocked agent gets a clear
 * message naming who authorised the original — never a 403." Callers get
 * a plain-language block reason back and redirect with it, exactly like
 * every other user-facing failure in this pipeline.
 */
trait EnforcesReauthorisationBinding
{
    /**
     * Returns null when re-authorisation may proceed; otherwise the exact
     * message to show the blocked agent (redirect back with it). Logs every
     * block to signature_audit_log as 'amendment_reauthorisation_blocked'.
     */
    protected function reauthorisationBindingBlockReason(SignatureTemplate $template, User $user, string $attemptedAction): ?string
    {
        $agencyId = (int) ($user->effectiveAgencyId() ?? $user->agency_id ?? 0);
        if (! EsignSettings::forAgency($agencyId)->strictReauthorisationBinding()) {
            return null;
        }

        // The write-once binding record: whichever supervisor/supervisor_final
        // SignatureRequest row actually authorised this document originally.
        // Reused across every amendment round — never recreated — so this
        // resolves to the SAME row every time, per AT-332's "survive multiple
        // amendment rounds" requirement.
        $boundRequest = SignatureRequest::where('signature_template_id', $template->id)
            ->whereIn('party_role', ['supervisor', 'supervisor_final'])
            ->whereNotNull('authorised_by')
            ->orderByDesc('authorised_at')
            ->first();

        // No original authoriser recorded yet — nothing to bind to. Fail
        // open rather than block a document that was never authorised
        // through the candidate/supervisor flow in the first place.
        if ($boundRequest === null) {
            return null;
        }

        if ((int) $boundRequest->authorised_by === (int) $user->id) {
            return null;
        }

        $boundUser = User::withTrashed()->find($boundRequest->authorised_by);
        $boundName = $boundUser->name ?? 'the original authoriser';

        SignatureAuditLog::log(
            $template,
            'amendment_reauthorisation_blocked',
            SignatureAuditLog::ACTOR_USER,
            $user->name,
            $user->email,
            actorId: $user->id,
            metadata: [
                'bound_authoriser_id' => $boundRequest->authorised_by,
                'bound_authoriser_name' => $boundName,
                'attempted_action' => $attemptedAction,
            ],
        );

        return "Only {$boundName}, who authorised this document originally, can re-authorise it after an amendment. Please ask {$boundName} to review this change.";
    }
}
