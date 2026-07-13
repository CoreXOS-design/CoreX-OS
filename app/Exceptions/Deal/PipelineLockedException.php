<?php

declare(strict_types=1);

namespace App\Exceptions\Deal;

use App\Models\Deal;
use RuntimeException;

/**
 * AT-244 — thrown when a pipeline mutation is attempted on a deal that is not
 * proceeding (Declined). The pipeline of a declined deal is read-only history:
 * it may be looked at, never advanced. Carries the deal so callers can surface
 * the lock reason and the way back (reinstate via the register's status control).
 */
final class PipelineLockedException extends RuntimeException
{
    public function __construct(public readonly Deal $deal, public readonly string $attemptedAction)
    {
        parent::__construct(sprintf(
            'Deal #%s is Declined — its pipeline is locked and cannot be advanced.',
            (string) ($deal->deal_no ?? $deal->id),
        ));
    }

    /** The user-facing sentence: what is locked, why, and how to unlock it. */
    public function userMessage(): string
    {
        return sprintf(
            'Nothing changed — deal #%s is Declined, so its pipeline is locked. '
            . 'To work this deal again, set its status back to Pending or Granted on the deal register first.',
            (string) ($this->deal->deal_no ?? $this->deal->id),
        );
    }

    /**
     * Rendered here rather than caught in each controller, so EVERY caller is covered by
     * one rule — including CalendarController, which completes DR1 pipeline steps without
     * going through PipelineController. The user gets a plain-language message and the way
     * forward; never a stack trace (BUILD_STANDARD §4).
     */
    public function render(\Illuminate\Http\Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => false,
                'error'   => $this->userMessage(),
                'reason'  => 'pipeline_locked',
                'deal_id' => $this->deal->id,
            ], 422);
        }

        return back()->with('error', $this->userMessage());
    }
}
