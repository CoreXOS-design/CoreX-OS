<?php

declare(strict_types=1);

namespace App\Services\Deal;

use App\Exceptions\Deal\PipelineLockedException;
use App\Models\Deal;
use App\Models\DealLog;
use App\Models\DealV2\DealStepInstance;

/**
 * AT-244 — the pipeline lock. ONE rule, one place:
 *
 *     the pipeline is live only on the proceeding offer.
 *
 * A Declined deal is not proceeding, so its pipeline is READ-ONLY — visible as
 * history, never advanced. This is derived from `deals.accepted_status`; there is
 * no side flag, and nothing to keep in sync.
 *
 * WHY 'D' IS THE WHOLE SET
 * ------------------------
 * DR2 has exactly four statuses (`deals.accepted_status`, varchar(1)): P Pending,
 * G Granted, D Declined, R Registered. There is no `lapsed` or `cancelled` state —
 * Wave 2's auto-decline (grant cascade AND capture-after-grant) writes the SAME
 * 'D', distinguished only by its `deal_logs.event_type = 'auto_declined'` audit
 * row. So a single predicate covers manual decline, grant-cascade auto-decline and
 * capture-after-grant auto-decline alike.
 *
 * R (Registered) is terminal but PROCEEDED — the deal that made it all the way.
 * Locking it is a different concern (post-completion immutability) and is NOT what
 * "not proceeding" means, so it stays unlocked.
 *
 * THE WAY BACK (we align with DR2, we do not invent)
 * --------------------------------------------------
 * DR2 already has revival semantics and they are load-bearing: a declined deal
 * stays RE-GRANTABLE while no other deal on the property is committed — `D → G` is
 * legal (`AutoDeclineSiblingDealsOnGrant` docblock; proven by
 * Wave2DealPropertyStatusSyncTest::test_fall_through_re_grant_is_allowed_...). That
 * transition is a deliberate, permission-gated (`deals.edit`), audited status write
 * on the register (`DealRegisterController::quickUpdate` → DealLog `status_changed`),
 * and it is already promised to users in `_capture-declined-notice.blade.php`.
 *
 * So the unlock is NOT a new mechanism: moving the deal off 'D' unlocks the
 * pipeline, because the lock is derived from the status. Reinstate = set the status
 * back on the register. Clicking a pipeline stage can never revive a declined deal.
 */
class DealPipelineLockService
{
    /** Not-proceeding terminal codes. The pipeline is read-only on these. */
    private const LOCKED_CODES = ['D'];

    /** Is this deal's pipeline locked (i.e. the deal is not proceeding)? */
    public function isLocked(?Deal $deal): bool
    {
        if (! $deal) {
            return false;
        }

        return in_array((string) $deal->accepted_status, self::LOCKED_CODES, true);
    }

    /** Why it is locked, in a sentence a first-day agent understands. */
    public function reason(?Deal $deal): string
    {
        return 'This deal is Declined, so its pipeline is locked — it stays visible as history, '
             . 'but no step can be completed, added, removed or re-dated.';
    }

    /** How to get it back — the existing, deliberate revival path (never a pipeline click). */
    public function unlockHint(): string
    {
        return 'To work this deal again, set its status back to Pending or Granted on the deal register. '
             . 'A declined deal stays re-grantable while no other deal on the property is granted.';
    }

    /**
     * The gate. Throws when a pipeline mutation is attempted on a not-proceeding
     * deal, and AUDITS the rejected attempt — a blocked write is a security-relevant
     * event, so it is never silently swallowed.
     *
     * @throws PipelineLockedException
     */
    public function assertUnlocked(?Deal $deal, string $attemptedAction): void
    {
        if (! $this->isLocked($deal)) {
            return;
        }

        /** @var Deal $deal */
        $this->auditRejection($deal, $attemptedAction);

        throw new PipelineLockedException($deal, $attemptedAction);
    }

    /**
     * Resolve the DR1 deal that governs a step's lock.
     *
     * DR2 pipeline steps are DR1-anchored (`deal_step_instances.dr1_deal_id`), so this
     * is the whole story for the DR2 surface. A step with no DR1 anchor belongs to the
     * sunsetting deals_v2 engine, which carries its own `status` vocabulary and is not
     * governed by `accepted_status` — it is left alone rather than half-locked.
     */
    public function dealForStep(DealStepInstance $step): ?Deal
    {
        return $step->dr1Deal;
    }

    /** Convenience for the step-anchored call sites. */
    public function assertStepUnlocked(DealStepInstance $step, string $attemptedAction): void
    {
        $this->assertUnlocked($this->dealForStep($step), $attemptedAction);
    }

    /**
     * Record the blocked attempt on the deal's log. Mirrors the DR2 audit shape
     * (`deal_logs`) so a rejected transition shows up on the same Deal Log page an
     * agent already reads. Logging must never itself break the rejection.
     */
    private function auditRejection(Deal $deal, string $attemptedAction): void
    {
        try {
            DealLog::create([
                'deal_id'       => $deal->id,
                'actor_user_id' => auth()->id(),
                'event_type'    => 'pipeline_locked_rejected',
                'from_value'    => (string) $deal->accepted_status,
                'to_value'      => null,
                'message'       => sprintf(
                    'Blocked: "%s" attempted on the pipeline of a Declined deal. A declined deal is not '
                    . 'proceeding — reinstate it on the register before working its pipeline.',
                    $attemptedAction,
                ),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('AT-244 pipeline-lock audit failed', [
                'deal_id' => $deal->id ?? null,
                'action'  => $attemptedAction,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
