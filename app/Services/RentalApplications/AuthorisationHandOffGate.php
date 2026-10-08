<?php

namespace App\Services\RentalApplications;

use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;

/**
 * The checks that stand between an agent's working copy of a rental application and a DECISION on it - run at the
 * hand-off to the authoriser (RentalApplicationReviewController::submitForApproval) AND, in one-step agencies where
 * the same person assesses and approves, when the approval is made directly (RentalApplicationAuthorisationController::approve).
 * One definition, so a one-step agency can never approve something the two-step path would have refused.
 *
 *  - an unsorted PDF in the file ("cannot hand the authoriser an unsorted blob", AT-392);
 *  - the applicant's FICA, only when the agency switched the hard stop on, and only while the applicant has not
 *    SUBMITTED it (the gate lifts on submitted - Johan, 8 Oct 2026; RentalApplication::ficaGateDescribe() / FicaGate).
 */
final class AuthorisationHandOffGate
{
    /**
     * @return array{error: string, reason: string, extra: array<string,mixed>}|null  null = clear to go ahead
     */
    public static function refusal(RentalApplication $application): ?array
    {
        $unsplit = $application->documents()
            ->whereNull('document_type_id')
            ->where('mime_type', 'application/pdf')
            ->count();
        if ($unsplit > 0) {
            return [
                'error' => $unsplit === 1
                    ? 'One supporting document hasn\'t been sorted into document types yet - split it before submitting for authorisation.'
                    : "{$unsplit} supporting documents haven't been sorted into document types yet - split them before submitting for authorisation.",
                'reason' => 'unsplit_documents',
                'extra' => ['unsplit_count' => $unsplit],
            ];
        }

        $fica = $application->ficaGateDescribe();
        if (! $fica['open'] && RentalApplicationQualifyingSetting::requireFicaBeforeAuthorisationFor((int) $application->agency_id)) {
            return [
                'error' => 'The applicant has not submitted their FICA yet, and your agency requires that before this goes to the authoriser. '
                    . 'Request it from the applicant (or complete it with them) and try again.',
                'reason' => 'fica_outstanding',
                'extra' => ['fica_url' => $fica['url']],
            ];
        }

        return null;
    }
}
