<?php

declare(strict_types=1);

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RoadToSold\AllocationSource;
use App\Services\RoadToSold\BeltService;
use App\Services\RoadToSold\CallListService;
use App\Services\RoadToSold\ListBuilderService;
use App\Services\RoadToSold\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Road to Sold v4 - the compare popover behind each belt tile (.ai/specs/road-to-sold.md 23.12 / 23.13): the same figure for me, my branch and the agency.
 * Aggregates only (a count and a target per scope, never another agent's rows), so any agent may see them. The agent in view must be one the viewer may look at.
 */
class BeltController extends Controller
{
    public function __construct(private readonly BeltService $belt, private readonly CallListService $list)
    {
    }

    public function scopes(Request $request, string $station): JsonResponse
    {
        $viewer = $request->user();
        abort_if((int) ($viewer->effectiveAgencyId() ?: 0) <= 0, 403, 'Switch into an agency first.');
        abort_if(BeltService::station($station) === null, 404);
        $track = Track::fromRequest($request);
        $agentId = (int) ($request->query('agent') ?: $viewer->ownershipUserId());
        abort_unless($this->list->viewableAgents($viewer)->contains('id', $agentId), 404);
        $agent = User::query()->withoutGlobalScopes()->where('agency_id', (int) $viewer->effectiveAgencyId())->findOrFail($agentId);
        $date = $this->list->resolveDate($request->query('date'));

        $out = $this->belt->scopeFigures($agent, $station, $track, $date);
        // the Loaded tile also says where today's stock came from (my buyers / colleagues' buyers / hot pockets / fill-ups); the agent in view only
        if ($station === 'loaded') {
            $counts = app(ListBuilderService::class)->sourceCounts($agent, $date, $track);
            $out['sources'] = collect([AllocationSource::MY_BUYERS, AllocationSource::COLLEAGUE_BUYERS, AllocationSource::HOT_POCKET, AllocationSource::FILL_UP, AllocationSource::FOUND_BY_YOU])
                ->map(fn ($k) => ['kind' => $k, 'label' => AllocationSource::LABELS[$k], 'count' => (int) ($counts[$k] ?? 0)])
                ->filter(fn ($r) => $r['count'] > 0 || in_array($r['kind'], [AllocationSource::MY_BUYERS, AllocationSource::COLLEAGUE_BUYERS, AllocationSource::HOT_POCKET, AllocationSource::FILL_UP], true))->values()->all();
        }

        return response()->json($out);
    }
}
