<?php

namespace App\Services\Matching;

use App\Models\ContactMatch;
use Illuminate\Support\Collection;

/**
 * Thin facade over {@see MatchingService} for the buyer-facing Client Portal
 * and the agent-facing Core Match results page.
 *
 * Historically this was a separate, stricter engine. As of the relaxed-matching
 * work it delegates to MatchingService so the whole system uses ONE scorer and
 * ONE filter implementation — see .ai/specs/matches.md. Relaxed matching means
 * near-misses (a 2-bed for a 3-bed search, a property slightly over budget) are
 * surfaced with a decayed `match_score` instead of being hard-excluded.
 *
 * Resolution is always agency-wide (`agent_id => null`): a buyer sees every
 * listing in the agency that fits, not just one agent's stock.
 */
class ClientMatchResolver
{
    /**
     * Returns the properties that match, scored and tiered, sorted best-first.
     *
     * @param  bool  $includeHidden  When true, properties the agent has hidden
     *         from this match are still returned (agent-facing view needs them
     *         so they can be reviewed / un-hidden). The client portal passes
     *         false so hidden properties never reach the client.
     * @return \Illuminate\Support\Collection<int, \App\Models\Property>
     */
    public function resolve(ContactMatch $match, bool $includeHidden = false): Collection
    {
        return app(MatchingService::class)->propertiesForMatch($match, [
            'agent_id'       => null,            // agency-wide stock, not one agent
            'include_hidden' => $includeHidden,
        ]);
    }
}
