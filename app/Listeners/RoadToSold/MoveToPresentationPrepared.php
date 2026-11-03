<?php

declare(strict_types=1);

namespace App\Listeners\RoadToSold;

use App\Events\PresentationGenerated;
use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\RoadToSold\BeltLinks;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Road to Sold v4, station 6 (road-to-sold.md 23.4 / 23.8): a presentation generated for the property moves the agent's belt record to "Presentation prepared".
 * Several versions are one station (the move is forward-only and once per record). The record is found through the presentation's own link
 * (`rts_prospect_id`), else the creating agent's open record for the property. Never throws to the caller.
 */
class MoveToPresentationPrepared
{
    public function __construct(private readonly ProspectService $prospects, private readonly BeltLinks $links)
    {
    }

    public function handle(PresentationGenerated $e): void
    {
        try {
            $pres = $e->presentation;
            $agencyId = (int) $pres->agency_id;
            $agentId = (int) $pres->created_by_user_id;
            $p = null;
            if (BeltLinks::presentationColumns() && ! empty($pres->rts_prospect_id)) {
                $p = RtsProspect::withoutGlobalScopes()->find((int) $pres->rts_prospect_id);
            }
            if (! $p) {
                $tracked = $this->links->trackedOf($pres->property_id ? (int) $pres->property_id : null, $agencyId);
                $p = $tracked ? $this->links->openProspect($agencyId, $agentId, $tracked) : null;
            }
            if (! $p || $p->closed_at !== null || $p->released_at !== null) {
                return;   // no belt record for this property: nothing moves
            }
            $actor = User::query()->withoutGlobalScopes()->find($agentId);
            $this->prospects->advance($p, ProspectStages::PRESENTATION_PREPARED, $actor, [
                'event_class' => PresentationGenerated::class, 'subject_type' => 'presentation', 'subject_id' => (int) $pres->id, 'note' => 'Presentation prepared',
            ]);
        } catch (Throwable $ex) {
            Log::warning('Belt: Presentation prepared could not be applied (the presentation stands)', ['presentation_id' => $e->presentation->id ?? null, 'message' => $ex->getMessage()]);
        }
    }
}
