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
        $ficaContinueUrl = $isSubjectToFica ? $this->ficaContinueUrlFor($application) : null;

        try {
            Mail::to($recipientEmail)->send(new RentalApplicationApprovedMail($application, $properties, $isSubjectToFica, $ficaContinueUrl));

            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application approved mail failed', [
                'rental_application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Same find-or-create shape RentalApplicationSigningController's own
     * FICA hand-off already uses (that controller is a sibling lane's
     * active build — this is a read-only, self-contained copy of the
     * QUERY pattern, not a call into their file) — reuses an existing
     * draft/in-progress submission for this contact if one exists (minting
     * a token if it's missing one), otherwise starts a fresh one. Never
     * returns null: an applicant told "subject to FICA verification" must
     * always get a real, working link.
     */
    private function ficaContinueUrlFor(RentalApplication $application): ?string
    {
        if (! $application->contact_id) {
            return null;
        }

        $existing = FicaSubmission::where('contact_id', $application->contact_id)
            ->whereIn('status', ['draft', 'submitted', 'under_review', 'agent_approved', 'approved'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            if (empty($existing->token)) {
                $existing->token = Str::random(64);
                $existing->token_expires_at = now()->addDays(14);
                $existing->save();
            }

            return route('fica.form', $existing->token);
        }

        $submission = FicaSubmission::create([
            'contact_id' => $application->contact_id,
            'agency_id' => $application->agency_id,
            'branch_id' => $application->branch_id,
            'requested_by' => $application->created_by_user_id,
            'token' => Str::random(64),
            'token_expires_at' => now()->addDays(14),
            'status' => 'draft',
        ]);

        return route('fica.form', $submission->token);
    }
}
