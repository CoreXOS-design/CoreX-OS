<?php

namespace App\Services\RentalApplications;

use App\Models\FicaSubmission;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Compliance\FicaWindows;
use Illuminate\Support\Str;

/**
 * The ONE place a rental applicant's FICA link is found or created, and how long it lives.
 *
 * Audit fixes A15 / A25 (2026-10-19). The find-or-create used to exist twice (the applicant's submit hand-off in
 * RentalApplicationSigningController and the approval email in RentalApplicationMailer) with a hard-coded 14-day link life
 * in four places, one copy even writing a NULL `requested_by` that the other had a fallback for. The link life is the
 * agency's own FICA setting (`FicaWindows::linkExpiryDays`, Settings -> FICA time windows and the Setup Wizard's
 * Compliance step; default 14), so a second agency that wants 30 days gets 30 days here too.
 *
 * The FICA form itself is deliberately NOT pre-filled from the application (spec ruling, rental-applications.md).
 *
 * A repeat contact with any submission from ANY earlier transaction (approved, or still in progress) is reused as-is -
 * "have they FICA'd before" is a yes/no per contact, not per application.
 */
final class ApplicantFicaLink
{
    private const REUSABLE_STATUSES = ['draft', 'submitted', 'under_review', 'agent_approved', 'approved'];

    public function submissionFor(RentalApplication $application): FicaSubmission
    {
        $expiresAt = now()->addDays(FicaWindows::linkExpiryDays((int) $application->agency_id));

        $existing = FicaSubmission::where('contact_id', $application->contact_id)
            ->whereIn('status', self::REUSABLE_STATUSES)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            // A reused draft whose link has lapsed (or that has no token) is renewed, never handed out dead (2026-10-11 hotfix).
            $existing->keepLinkAliveUntil($expiresAt);
            if ($existing->isDirty()) {
                $existing->save();
            }

            return $existing;
        }

        return FicaSubmission::create([
            'contact_id' => $application->contact_id,
            'agency_id' => $application->agency_id,
            'branch_id' => $application->branch_id,
            'requested_by' => $this->requestedBy($application),
            'token' => Str::random(64),
            'token_expires_at' => $expiresAt,
            'status' => 'draft',
        ]);
    }

    public function urlFor(RentalApplication $application): string
    {
        return route('fica.form', $this->submissionFor($application)->token);
    }

    /**
     * fica_submissions.requested_by is NOT NULL (every earlier FICA request was staff-initiated) and an online applicant has
     * no user. The agent who owns the application, else the agency's own admin - the agency can never be left without one.
     * (A plain null would hide the submission from an 'own'-scoped agent's compliance queue.)
     */
    private function requestedBy(RentalApplication $application): ?int
    {
        return $application->created_by_user_id
            ?? User::where('agency_id', $application->agency_id)->where('role', 'admin')->value('id');
    }
}
