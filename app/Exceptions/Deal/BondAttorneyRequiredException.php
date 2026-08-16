<?php

namespace App\Exceptions\Deal;

use App\Models\Deal;
use RuntimeException;

/**
 * Feature 1 (enforce-at-grant) — thrown when a bonded deal that carries the "Capture Bond
 * Attorney" step tries to reach Registered (or complete that step) without a bond attorney
 * captured on the deal. Surfaced to the agent by PipelineController::completeStep as a
 * "capture the bond attorney first" message; the step completion / R-advance rolls back.
 * Scoped to pipelines that actually contain the capture step, so legacy deals are unaffected.
 */
class BondAttorneyRequiredException extends RuntimeException
{
    public function __construct(public readonly Deal $deal)
    {
        parent::__construct('A bond attorney must be captured (Email Parties) before this deal can be registered.');
    }
}
