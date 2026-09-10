<?php

namespace App\Services\RentalApplications;

use App\Mail\RentalApplicationApprovedMail;
use App\Mail\RentalApplicationDeclineMail;
use App\Mail\RentalApplicationInviteMail;
use App\Mail\RentalApplicationMoreInfoRequestMail;
use App\Mail\RentalApplicationReopenedMail;
use App\Models\RentalApplication;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
     * AT-392 authoriser flow — the applicant-facing decline notification.
     */
    public function sendDecline(RentalApplication $application): bool
    {
        $recipientEmail = $application->recipientEmail();

        if (! $recipientEmail) {
            return false;
        }

        try {
            Mail::to($recipientEmail)->send(new RentalApplicationDeclineMail($application));

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
    public function sendApproved(RentalApplication $application, \Illuminate\Support\Collection $properties): bool
    {
        $recipientEmail = $application->recipientEmail();

        if (! $recipientEmail) {
            return false;
        }

        try {
            Mail::to($recipientEmail)->send(new RentalApplicationApprovedMail($application, $properties));

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
