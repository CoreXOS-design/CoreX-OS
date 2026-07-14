<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\FicaStatusHistory;
use App\Models\FicaSubmission;
use App\Models\User;
use App\Notifications\FicaReferredToCoNotification;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * AT-236 — the Refer-to-CO transition + its side effects, in one place.
 *
 * refer()      — a reviewer escalates a pack to the CO with a MANDATORY reason:
 *                state → referred_to_co, provenance stamped, audit row written,
 *                the recipient CO notified through the AT-235 gateway.
 * returnToReferrer() — the CO sends a referred pack BACK to whoever referred it,
 *                with comments (distinct from return-to-agent).
 *
 * Recipient + on/off are agency-configurable (defaults: ON, primary CO), read
 * defensively so the service works before the WS7 settings columns exist.
 */
class FicaReferralService
{
    /** States from which a pack may still be referred (pre-decision, active). */
    public const REFERABLE_FROM = ['submitted', 'under_review', 'agent_approved', 'corrections_requested'];

    public function referralEnabled(int $agencyId): bool
    {
        if (! Schema::hasColumn('agencies', 'fica_referral_enabled')) {
            return true; // default ON until the setting exists
        }
        $val = Agency::withoutGlobalScopes()->whereKey($agencyId)->value('fica_referral_enabled');
        return $val === null ? true : (bool) $val;
    }

    /**
     * The CO who receives referrals for this agency: the agency's configured
     * recipient if set AND still an active officer, otherwise the primary CO.
     */
    public function resolveRecipient(int $agencyId): ?User
    {
        if (Schema::hasColumn('agencies', 'fica_referral_recipient_user_id')) {
            $configuredId = Agency::withoutGlobalScopes()->whereKey($agencyId)->value('fica_referral_recipient_user_id');
            if ($configuredId) {
                $appt = FicaOfficerAppointment::where('agency_id', $agencyId)
                    ->where('user_id', $configuredId)->active()->first();
                if ($appt && $appt->user) {
                    return $appt->user;
                }
            }
        }
        return FicaOfficerAppointment::currentPrimary($agencyId)?->user;
    }

    public function refer(FicaSubmission $submission, User $referrer, string $reason): void
    {
        $from = $submission->status;

        $submission->update([
            'status'        => 'referred_to_co',
            'referred_by'   => $referrer->id,
            'referred_at'   => now(),
            'referral_note' => $reason,
        ]);

        FicaStatusHistory::record($submission, 'referred_to_co', $from, 'referred_to_co', $referrer, $reason);

        Log::info('FICA referred to CO', [
            'submission_id' => $submission->id,
            'referrer_id'   => $referrer->id,
        ]);

        $recipient = $this->resolveRecipient((int) $submission->agency_id);
        if ($recipient) {
            try {
                app(NotificationDispatcher::class)->send(
                    $recipient,
                    'fica.referred_to_co',
                    $submission,
                    new FicaReferredToCoNotification($submission, $referrer, $reason),
                    ['threshold_hit_at' => now()->toIso8601String(), 'submission_id' => $submission->id],
                );
            } catch (\Throwable $e) {
                // A notification failure must never block a legally-recorded referral.
                Log::warning('FICA referral notification failed (non-fatal)', [
                    'submission_id' => $submission->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }

    /** CO returns a referred pack to its referrer with comments. */
    public function returnToReferrer(FicaSubmission $submission, User $actor, string $comments): void
    {
        $from = $submission->status;

        // Back to the stage the referrer was working (agent review), carrying the
        // CO's comments. referred_by stays as the audit trail of who escalated.
        $submission->update([
            'status'   => 'corrections_requested',
            'co_notes' => $comments,
        ]);

        FicaStatusHistory::record($submission, 'co_returned_to_referrer', $from, 'corrections_requested', $actor, $comments, [
            'referrer_id' => $submission->referred_by,
        ]);

        Log::info('FICA referral returned to referrer', [
            'submission_id' => $submission->id,
            'co_id'         => $actor->id,
            'referrer_id'   => $submission->referred_by,
        ]);
    }
}
