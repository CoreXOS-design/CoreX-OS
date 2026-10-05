<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Property;
use App\Services\Syndication\SyndicationApprovalRequiredException;
use App\Services\Syndication\SyndicationApprovalService;

/**
 * Layer 3 of the market gate — spec .ai/specs/syndication-approval-gate.md §6.
 *
 * DELIBERATELY SEPARATE from EnforcesMarketingReadiness. Layer 2 (compliance)
 * and layer 3 (a named human's approval) are different concerns with different
 * owners; folding this into the compliance trait would make a compliance
 * service responsible for a non-compliance rule, and every existing caller
 * would report "compliance blocked it" on a listing whose compliance is green.
 *
 * Call it immediately AFTER enforceMarketingReadiness() on every enable /
 * submit / activate / reactivate path — compliance first, because an
 * outstanding compliance gate is the more actionable message for the agent.
 *
 * Inert when the agency has not switched layer 3 on.
 */
trait EnforcesSyndicationApproval
{
    /**
     * Throws SyndicationApprovalRequiredException (renders itself as a 422)
     * when the property has not been approved for syndication. $target names
     * the destination (e.g. "Property24") so the message is specific.
     */
    protected function enforceSyndicationApproval(Property $property, string $target = 'any website or portal'): void
    {
        $svc = app(SyndicationApprovalService::class);

        if ($svc->isApproved($property)) {
            return;
        }

        throw new SyndicationApprovalRequiredException($svc->stateFor($property), $target);
    }
}
