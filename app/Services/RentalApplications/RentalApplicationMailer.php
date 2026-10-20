<?php

namespace App\Services\RentalApplications;

use App\Mail\RentalApplicationApprovedMail;
use App\Mail\RentalApplicationDeclineMail;
use App\Mail\RentalApplicationInviteMail;
use App\Mail\RentalApplicationMoreInfoRequestMail;
use App\Mail\RentalApplicationReopenedMail;
use App\Models\FicaSubmission;
use App\Models\RentalApplication;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RentalApplicationMailer
{
    /**
     * Best-effort — a mail failure must never break the send action itself
     * (the record is already saved and the links are shown on-screen either
     * way, same "never Mail::to('') and die" posture as the e-sign mailer).
     */
    public function sendInvite(RentalApplication $application): bool
    {
        $recipientEmail = $application->recipientEmail();

        if (! $recipientEmail) {
            return false;
        }

        try {
            Mail::to($recipientEmail)->send(
                (new RentalApplicationInviteMail($application))->fromAgent($application->createdBy)
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application invite mail failed', [
                'rental_application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * AT-392 authoriser flow — the agent's "request more info from the
     * applicant" action. Same best-effort posture as sendInvite().
     */
    public function sendMoreInfoRequest(RentalApplication $application, string $note): bool
    {
        $recipientEmail = $application->recipientEmail();

        if (! $recipientEmail || ! $application->token) {
            return false;
        }

        try {
            Mail::to($recipientEmail)->send(new RentalApplicationMoreInfoRequestMail($application, $note));

            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application more-info-request mail failed', [
                'rental_application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Reopen/resubmit, 2026-09-08 — the agent's "send it back to the
     * applicant" notification. Same best-effort posture as sendInvite().
     */
    public function sendReopened(RentalApplication $application, string $note): bool
    {
        $recipientEmail = $application->recipientEmail();

        if (! $recipientEmail || ! $application->token) {
            return false;
        }

        try {
            Mail::to($recipientEmail)->send(
                (new RentalApplicationReopenedMail($application, $note))->fromAgent($application->createdBy)
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application reopened mail failed', [
                'rental_application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * AT-392/AT-410b — the applicant-facing decline notification. Sent by
     * the AGENT (RentalApplicationReviewController::sendDecline()), never
     * automatically on decline() itself — see RentalApplicationDeclineMail's
     * own docblock. $subject/$body are the EXACT final text the agent saw
     * and (possibly) edited — this method delivers them literally, it does
     * not re-derive wording from settings.
     */
    public function sendDecline(RentalApplication $application, string $subject, string $body): bool
    {
        $recipientEmail = $application->recipientEmail();

        if (! $recipientEmail) {
            return false;
        }

        try {
            Mail::to($recipientEmail)->send(new RentalApplicationDeclineMail($application, $subject, $body));

            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application decline mail failed', [
                'rental_application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * AT-392 authoriser flow — the applicant-facing approval notification.
     * Johan: "congrats you are approved to rent for x amount." No
     * "matching properties" content — that's explicitly unsettled, not built.
     */
    /**
     * AT-410d, 2026-09-16 — $isSubjectToFica is resolved LIVE, right here,
     * at the moment of sending, not read from a value frozen back when the
     * authoriser approved. Johan: the email must say what's true when it
     * leaves, not what was true when someone clicked Approve — FICA can
     * resolve in the gap between the two.
     */
    public function sendApproved(RentalApplication $application, \Illuminate\Support\Collection $properties): bool
    {
        $recipientEmail = $application->recipientEmail();

        if (! $recipientEmail) {
            return false;
        }

        $isSubjectToFica = $application->status === 'approved' && $application->ficaOutstanding();
        $ficaContinueUrl = null;
        if ($isSubjectToFica && ! $application->ficaGateOpen() && $application->contact_id) {
            try {
                $ficaContinueUrl = app(ApplicantFicaLink::class)->urlFor($application);
            } catch (\Throwable $e) {
                // The approval mail still goes, just without the FICA button (the applicant can use the link on their application page).
                Log::warning('AT-392 rental application approval mail: FICA link could not be made', ['rental_application_id' => $application->id, 'error' => $e->getMessage()]);
            }
        }

        try {
            // FICA already SUBMITTED (compliance still verifying) is not the same as FICA still to do: the email must not ask
            // them to "verify now" again.
            $ficaSubmitted = $isSubjectToFica && $application->ficaGateOpen();
            Mail::to($recipientEmail)->send(new RentalApplicationApprovedMail($application, $properties, $isSubjectToFica, $ficaSubmitted ? null : $ficaContinueUrl, $ficaSubmitted));

            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application approved mail failed', [
                'rental_application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
