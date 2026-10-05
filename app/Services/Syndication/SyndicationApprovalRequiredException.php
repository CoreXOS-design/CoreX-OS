<?php

declare(strict_types=1);

namespace App\Services\Syndication;

use Illuminate\Http\Request;

/**
 * Layer 3 refusal — the listing is compliance-clear but nobody has approved it
 * for syndication yet. Spec: .ai/specs/syndication-approval-gate.md §6.1.
 *
 * Deliberately shaped like MarketingBlockedException (same 422, same renderable
 * pattern) so every existing JSON caller in the syndication panel handles it
 * with the code path it already has — but with its OWN error code, so an agent
 * is never told "compliance blocked this" when compliance is green.
 */
class SyndicationApprovalRequiredException extends \Exception
{
    public function __construct(
        private SyndicationApprovalState $state,
        private string $target = 'any website or portal',
        string $message = 'This listing has not been approved for syndication yet.',
    ) {
        parent::__construct($message);
    }

    public function getState(): SyndicationApprovalState
    {
        return $this->state;
    }

    public function render(Request $request)
    {
        $who = empty($this->state->approverNames)
            ? 'your agency admin'
            : implode(' or ', $this->state->approverNames);

        $detail = $this->state->badge === SyndicationApprovalState::BADGE_AWAITING
            ? "This listing is waiting for approval from {$who} before it can go to {$this->target}."
            : "This listing must be approved by {$who} before it can go to {$this->target}. Use \"Send for approval\" on the property.";

        if ($request->expectsJson()) {
            return response()->json([
                'error'          => 'syndication_approval_required',
                'message'        => $detail,
                'approval_state' => $this->state->toArray(),
            ], 422);
        }

        return redirect()->back()->with('error', $detail);
    }
}
