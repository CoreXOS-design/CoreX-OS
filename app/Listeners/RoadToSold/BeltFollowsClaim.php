<?php

declare(strict_types=1);

namespace App\Listeners\RoadToSold;

use App\Events\Prospecting\ClaimCreated;
use App\Events\Prospecting\ClaimReleased;
use App\Models\RoadToSold\RtsProspect;
use App\Services\RoadToSold\ClaimBeltService;
use App\Services\RoadToSold\ProspectService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road to Sold v4, station 2 (road-to-sold.md 23.5): a MIC claim IS the prospect.
 *
 *   ClaimCreated   the claim puts the property on the belt (ClaimBeltService::placeClaim).
 *   ClaimReleased  the claim ends (the agent gives it up, a manager releases it, the hold ends, a lost / not-interested close): the agent's
 *                  belt record for that property goes with it, so the claim and the prospect are never out of step.
 *
 * Never throws to the caller: the claim save stands whatever happens here.
 */
class BeltFollowsClaim
{
    public function __construct(private readonly ClaimBeltService $belt, private readonly ProspectService $prospects)
    {
    }

    public function handleCreated(ClaimCreated $e): void
    {
        // the model fresh from the insert has none of the column defaults (is_active, hold columns): read the row as it is
        $claim = $e->claim->fresh();
        if ($claim) {
            $this->belt->placeClaim($claim);
        }
    }

    public function handleReleased(ClaimReleased $e): void
    {
        try {
            $c = $e->claim;
            if (! $c->rts_prospect_id) {
                return;
            }
            $p = RtsProspect::withoutGlobalScopes()->find($c->rts_prospect_id);
            if (! $p || $p->released_at !== null || $p->closed_at !== null || (int) $p->user_id !== (int) $c->user_id) {
                return;
            }
            // a result (lost / not selling) closes the claim but keeps the belt record, set aside with its reason (23.9)
            if (str_starts_with((string) $c->release_reason, 'result_')) {
                return;
            }
            $note = match ((string) $c->release_reason) {
                'hold_ended' => 'Claim hold ended',
                default      => in_array((string) $c->status, ['lost', 'not_interested'], true)
                    ? 'Closed in Market Intelligence: ' . \App\Models\ProspectingClaim::humanStatus((string) $c->status)
                    : 'Claim given up in Market Intelligence',
            };
            // the agent gave the claim up (or its hold ended): the property stays on today's list as loaded - it is not "missed" until the day ends
            $keepRow = in_array((string) $c->release_reason, ['hold_ended'], true) || $note === 'Claim given up in Market Intelligence';
            $this->prospects->release($p, null, $note, ! $keepRow);
        } catch (Throwable $ex) {
            Log::warning('Belt: the prospect could not follow the released claim (the release stands)', ['claim_id' => $e->claim->id ?? null, 'message' => $ex->getMessage()]);
        }
    }
}
