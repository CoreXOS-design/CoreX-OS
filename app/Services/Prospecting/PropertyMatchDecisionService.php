<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\PropertyMatchDecision;
use Illuminate\Support\Carbon;

/**
 * CX-102 part 2 — "the system must show its working and let the agent
 * overrule it" (Johan, 2026-08-19).
 *
 * THE reusable entry point for recording, checking, and rejecting a "same
 * property?" match decision. Any caller that decides two records are the
 * same property calls record(); any screen that shows a match calls
 * current() to render the reason; any control that lets an agent say it's
 * wrong calls reject(). isRejected() is the veto check every matching
 * strategy must consult before offering a candidate again.
 *
 * Deliberately not scoped to deeds-capture or MIC — subject_type is the
 * caller's own namespace (see PropertyMatchDecision doc comment for the
 * subject_key shape each caller uses). A future caller (CX-101's stale-stock
 * work, or anything else) defines its own subject_type/subject_key and gets
 * the same reason storage, veto, and rejection audit trail for free.
 */
class PropertyMatchDecisionService
{
    /**
     * Record a fresh match decision. Call this at the moment a match is
     * made — the reason and candidates must be generated from the real
     * values seen right then, never reconstructed later from whatever the
     * matched record looks like today (it may have changed since).
     *
     * @param  array<int, array{type: string, id: int, label: string}>|null  $candidates
     */
    public function record(
        int $agencyId,
        string $subjectType,
        string $subjectKey,
        string $matchedType,
        int $matchedId,
        string $strategy,
        string $reason,
        ?array $candidates = null,
    ): PropertyMatchDecision {
        return PropertyMatchDecision::create([
            'agency_id'    => $agencyId,
            'subject_type' => $subjectType,
            'subject_key'  => $subjectKey,
            'matched_type' => $matchedType,
            'matched_id'   => $matchedId,
            'strategy'     => $strategy,
            'reason'       => $reason,
            'candidates'   => $candidates,
            'decided_at'   => Carbon::now(),
        ]);
    }

    /**
     * The current (most recent, unrejected preferred) decision for a subject,
     * for a screen to render "here is the match, here is why". Returns the
     * latest REJECTED decision instead if that's all there is, so a screen
     * can still show "this was rejected" rather than nothing.
     */
    public function current(int $agencyId, string $subjectType, string $subjectKey): ?PropertyMatchDecision
    {
        return PropertyMatchDecision::queryWithoutAgencyScope()->where('agency_id', $agencyId)
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->orderByDesc('decided_at')
            ->first();
    }

    /**
     * The veto check — every match strategy must consult this before
     * offering a candidate. True when this EXACT (subject, target) pairing
     * has already been rejected, meaning it must never be silently offered
     * again. Deliberately narrow: it does not veto the target for any OTHER
     * subject, and it does not veto this subject against any OTHER target —
     * widening it into "never match this property to anything resembling
     * this address again" would start silently blocking genuinely correct
     * future matches (Johan, 2026-08-19: an accepted, explicit limit, not a
     * gap to close).
     */
    public function isRejected(int $agencyId, string $subjectType, string $subjectKey, string $matchedType, int $matchedId): bool
    {
        return PropertyMatchDecision::queryWithoutAgencyScope()->where('agency_id', $agencyId)
            ->where('subject_type', $subjectType)
            ->where('subject_key', $subjectKey)
            ->where('matched_type', $matchedType)
            ->where('matched_id', $matchedId)
            ->whereNotNull('rejected_at')
            ->exists();
    }

    /**
     * "Not the same property." Marks the decision rejected — permanently
     * (this row is never deleted; it is the audit trail AND the veto). Does
     * NOT touch either matched record's own data — breaking the link is the
     * whole of what this does. Undoing whatever side-effect the original
     * match caused (e.g. a listing's matched_property_id, a claim's status)
     * is the caller's job, immediately after this call, because that
     * side-effect shape is specific to the caller (deeds-capture vs MIC
     * claim vs whatever comes next), not something this generic service
     * can know.
     */
    public function reject(
        PropertyMatchDecision $decision,
        int $byUserId,
        ?string $reason = null,
        ?string $resolvedMatchedType = null,
        ?int $resolvedMatchedId = null,
    ): PropertyMatchDecision {
        $decision->update([
            'rejected_at'            => Carbon::now(),
            'rejected_by_user_id'    => $byUserId,
            'rejected_reason'        => $reason,
            'resolved_matched_type'  => $resolvedMatchedType,
            'resolved_matched_id'    => $resolvedMatchedId,
        ]);

        return $decision->refresh();
    }
}
