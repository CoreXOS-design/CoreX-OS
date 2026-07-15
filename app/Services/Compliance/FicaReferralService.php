<?php

namespace App\Services\Compliance;

use App\Models\Agency;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\FicaStatusHistory;
use App\Models\FicaSubmission;
use App\Models\User;
use App\Notifications\FicaReferralReturnedNotification;
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
     * Returns null when the agency has NO active CO able to receive a referral —
     * the caller must treat that as a hard block (AT-269: never orphan a pack).
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

    /**
     * AT-269 (P2-49) — station-2 authorisation, action-enforced not display-only.
     * The CO-decision station (a `referred_to_co` pack) belongs to whoever the
     * referral is routed to: the resolved recipient, or the agency's primary CO.
     * Any OTHER officer (a secondary/MLRO who isn't the recipient) may not decide
     * a referred pack, even though the queue merely hides it from them.
     */
    public function isReferralStationOwner(FicaSubmission $submission, User $user): bool
    {
        $agencyId = (int) $submission->agency_id;

        return $this->resolveRecipient($agencyId)?->id === $user->id
            || $user->isPrimaryComplianceOfficer($agencyId);
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
            $this->notifyRecipientOfReferral($submission, $recipient, $referrer, $reason);
        }
        // No recipient here is not silently ignored: referToCo() blocks BEFORE this
        // service is ever called (AT-269), so a referred pack always has a CO to act.
    }

    /**
     * AT-269 — deliver the escalation alert to the CO through the AT-235 gateway.
     * Keyed on `referred_at` (a STABLE fact), so re-routing the SAME pack to the
     * SAME CO is a no-op (dedup), while re-routing to a DIFFERENT CO fires once.
     */
    private function notifyRecipientOfReferral(FicaSubmission $submission, User $recipient, User $referrer, string $reason): void
    {
        try {
            app(NotificationDispatcher::class)->send(
                $recipient,
                'fica.referred_to_co',
                $submission,
                new FicaReferredToCoNotification($submission, $referrer, $reason),
                [
                    'threshold_hit_at' => optional($submission->referred_at)->toIso8601String() ?? now()->toIso8601String(),
                    'submission_id'    => $submission->id,
                ],
            );
        } catch (\Throwable $e) {
            // A notification failure must never block a legally-recorded referral.
            Log::warning('FICA referral notification failed (non-fatal)', [
                'submission_id' => $submission->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * AT-269 — reconcile every OPEN referral for an agency after an officer
     * designation change (a new primary appointed, one ended, MLRO list edited,
     * or the referral recipient reconfigured). Two outcomes per open pack:
     *
     *   • a CO still resolves  → re-route: notify the (possibly new) recipient.
     *     Dedup on `referred_at` means the incumbent recipient is not re-spammed;
     *     a NEW recipient (different user) is alerted exactly once.
     *   • no CO resolves        → the pack cannot sit orphaned: return it to its
     *     referrer with a system note and notify them so someone owns it again.
     *
     * Returns a small summary for logging/verification.
     */
    public function reconcileOpenReferrals(int $agencyId): array
    {
        $open = FicaSubmission::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('status', 'referred_to_co')
            ->get();

        if ($open->isEmpty()) {
            return ['rerouted' => 0, 'returned' => 0];
        }

        $recipient = $this->resolveRecipient($agencyId);
        $rerouted = 0;
        $returned = 0;

        foreach ($open as $submission) {
            if ($recipient) {
                $referrer = $submission->referred_by ? User::find($submission->referred_by) : null;
                $this->notifyRecipientOfReferral(
                    $submission,
                    $recipient,
                    $referrer ?? $recipient,
                    (string) ($submission->referral_note ?? 'Referred for a compliance decision.'),
                );
                $rerouted++;
            } else {
                $this->returnToReferrer(
                    $submission,
                    null,
                    'The Compliance Officer designation changed and no active CO remains to decide this referral. '
                    . 'Returned to you for re-assignment — please re-escalate once a Compliance Officer is appointed.',
                    systemInitiated: true,
                );
                $returned++;
            }
        }

        Log::info('FICA open referrals reconciled after officer change', [
            'agency_id' => $agencyId,
            'rerouted'  => $rerouted,
            'returned'  => $returned,
        ]);

        return ['rerouted' => $rerouted, 'returned' => $returned];
    }

    /**
     * CO returns a referred pack to its referrer with comments. `$actor` is null
     * when the system returns it automatically (AT-269 reconcile, no CO left).
     * The referrer is notified through the gateway in both cases.
     */
    public function returnToReferrer(FicaSubmission $submission, ?User $actor, string $comments, bool $systemInitiated = false): void
    {
        $from = $submission->status;

        // Back to the stage the referrer was working (agent review), carrying the
        // CO's comments. referred_by stays as the audit trail of who escalated.
        $submission->update([
            'status'   => 'corrections_requested',
            'co_notes' => $comments,
        ]);

        FicaStatusHistory::record(
            $submission,
            $systemInitiated ? 'referral_auto_returned' : 'co_returned_to_referrer',
            $from,
            'corrections_requested',
            $actor,
            $comments,
            ['referrer_id' => $submission->referred_by, 'system_initiated' => $systemInitiated],
        );

        Log::info('FICA referral returned to referrer', [
            'submission_id'    => $submission->id,
            'co_id'            => $actor?->id,
            'referrer_id'      => $submission->referred_by,
            'system_initiated' => $systemInitiated,
        ]);

        $referrer = $submission->referred_by ? User::find($submission->referred_by) : null;
        if ($referrer) {
            try {
                app(NotificationDispatcher::class)->send(
                    $referrer,
                    'fica.referral_returned',
                    $submission,
                    new FicaReferralReturnedNotification($submission, $actor, $comments, $systemInitiated),
                    ['threshold_hit_at' => now()->toIso8601String(), 'submission_id' => $submission->id],
                );
            } catch (\Throwable $e) {
                Log::warning('FICA referral-return notification failed (non-fatal)', [
                    'submission_id' => $submission->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }
    }
}
