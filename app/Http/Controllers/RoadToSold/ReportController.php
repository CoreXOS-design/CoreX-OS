<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Services\Performance\Period;
use App\Services\Performance\PeriodResolver;
use App\Services\RoadToSold\CallListService;
use App\Services\RoadToSold\DrilldownService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * AT-454 - the rows behind a Road to Sold figure (summary bar and report), and (later) the report itself.
 * Spec: .ai/specs/road-to-sold.md section 10.6. The agents a viewer may look at come from
 * CallListService::viewableAgents() (own / branch / agency breadth); an agent outside it is a 404.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly CallListService $list,
        private readonly DrilldownService $drill,
        private readonly PeriodResolver $periods,
    ) {
    }

    /** GET /road-to-sold/drilldown - the shared drill-down envelope, JSON. */
    public function drilldown(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403);

        $metric = (string) $request->query('metric', '');
        if (! $this->drill->supports($metric)) {
            return response()->json(['message' => 'Unknown figure.'], 422);
        }

        try {
            $period = $this->period($request);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $agentIds = $this->cohortIds($request);

        return response()->json($this->drill->rows($metric, $agentIds, $period, $agencyId));
    }

    /**
     * The agent ids this request covers: one agent (?agent_id, must be in the viewer's scope - else 404),
     * otherwise everyone the viewer may see, optionally narrowed to a branch.
     *
     * @return int[]
     */
    protected function cohortIds(Request $request): array
    {
        $agents = $this->list->viewableAgents($request->user());

        if ($request->filled('agent_id')) {
            $id = (int) $request->query('agent_id');
            abort_unless($agents->contains('id', $id), 404);

            return [$id];
        }
        if ($request->filled('branch_id')) {
            $agents = $agents->where('branch_id', (int) $request->query('branch_id'));
        }

        return $agents->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** @throws InvalidArgumentException */
    protected function period(Request $request): Period
    {
        $preset = (string) $request->query('period', 'this_month');
        if (! in_array($preset, PeriodResolver::PRESETS, true)) {
            throw new InvalidArgumentException('Unknown period.');
        }

        return $this->periods->resolve($preset, $request->query('start'), $request->query('end'));
    }
}
