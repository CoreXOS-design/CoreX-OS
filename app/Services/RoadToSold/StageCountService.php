<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v3 - what a manager sees per stage (road-to-sold.md 22.11, ruling R9): how many prospects sit at the stage now, how many
 * ENTERED it in the window (from the prospect history, so a prospect moving on never loses the fact it passed through), the average days
 * they have been there, how many have stalled, and the on-track state of the recipe step the stage belongs to. Read only.
 *
 * Scoping is in the query: the viewer's own / branch / agency breadth (RtsProspect::visibleTo); history rows are limited to those prospects.
 */
class StageCountService
{
    public function __construct(private readonly RecipeProgressService $progress)
    {
    }

    /**
     * @param  ?int $agentId one agent, or null = every agent in the viewer's breadth
     * @param  ?int $branchId only the prospects of one branch (the production line's "whole branch", 22.24); always inside the viewer's breadth
     * @return array<int,array{stage:string,label:string,group:string,step:string,now:int,entered:int,avg_days:?float,stalled:int,overdue:int,behind:int,step_state:?string}>
     */
    public function forStages(User $viewer, ?int $agentId, CarbonInterface $from, CarbonInterface $to, string $track = 'sales', ?int $branchId = null): array
    {
        $visible = fn () => RtsProspect::query()->visibleTo($viewer)->where('rts_prospects.track', $track)
            ->when($agentId !== null, fn ($q) => $q->where('rts_prospects.user_id', $agentId))
            ->when($branchId !== null, fn ($q) => $q->where('rts_prospects.branch_id', $branchId));

        $now = $visible()->activeStage()->selectRaw('rts_prospects.stage as s, COUNT(*) as n, AVG(DATEDIFF(?, rts_prospects.stage_entered_at)) as d', [Carbon::now()->toDateTimeString()])
            ->groupBy('rts_prospects.stage')->get()->keyBy('s');

        $stallDays = $this->stallDays($viewer);
        $cutoff = Carbon::now()->subDays($stallDays)->toDateTimeString();
        $stalled = $visible()->activeStage()->where('rts_prospects.updated_at', '<=', $cutoff)
            ->selectRaw('rts_prospects.stage as s, COUNT(*) as n')->groupBy('rts_prospects.stage')->pluck('n', 's');
        // the production line (22.24): overdue next actions, and the properties that are overdue OR stalled (each counted once)
        $today = Carbon::today()->toDateString();
        $attention = $visible()->activeStage()
            ->selectRaw('rts_prospects.stage as s,
                SUM(CASE WHEN rts_prospects.next_action_at IS NOT NULL AND DATE(rts_prospects.next_action_at) < ? THEN 1 ELSE 0 END) as o,
                SUM(CASE WHEN (rts_prospects.next_action_at IS NOT NULL AND DATE(rts_prospects.next_action_at) < ?) OR rts_prospects.updated_at <= ? THEN 1 ELSE 0 END) as b', [$today, $today, $cutoff])
            ->groupBy('rts_prospects.stage')->get()->keyBy('s');

        $entered = DB::table('rts_prospect_events as e')
            ->where('e.agency_id', (int) ($viewer->effectiveAgencyId() ?: 0))->where('e.track', $track)->where('e.kind', 'stage')
            ->whereBetween('e.occurred_at', [Carbon::instance($from)->startOfDay()->toDateTimeString(), Carbon::instance($to)->endOfDay()->toDateTimeString()])
            ->whereIn('e.prospect_id', $visible()->select('rts_prospects.id')->withTrashed()->toBase())
            ->selectRaw('e.to_stage as s, COUNT(DISTINCT e.prospect_id) as n')->groupBy('e.to_stage')->pluck('n', 's');

        $stepStates = $this->stepStates($viewer, $agentId, $track);

        $out = [];
        foreach (ProspectStages::ALL as $key => $meta) {
            $out[] = [
                'stage'      => $key,
                'label'      => $meta['label'],
                'group'      => $meta['group'],
                'step'       => $meta['step'],
                'now'        => (int) ($now[$key]->n ?? 0),
                'entered'    => (int) ($entered[$key] ?? 0),
                'avg_days'   => isset($now[$key]) ? round((float) $now[$key]->d, 1) : null,
                'stalled'    => (int) ($stalled[$key] ?? 0),
                'overdue'    => (int) ($attention[$key]->o ?? 0),
                'behind'     => (int) ($attention[$key]->b ?? 0),
                'step_state' => $stepStates[$meta['step']] ?? null,
            ];
        }

        return $out;
    }

    private function stallDays(User $viewer): int
    {
        return SettingsService::forUser($viewer)->prospectStallDays();
    }

    /** @return array<string,string> recipe step key => the on-track state of the agent (a single agent only; a pile has no one target) */
    private function stepStates(User $viewer, ?int $agentId, string $track): array
    {
        if ($agentId === null) {
            return [];
        }
        $agent = User::query()->withoutGlobalScopes()->find($agentId);
        if (! $agent) {
            return [];
        }
        $out = [];
        foreach ((array) ($this->progress->forAgent($agent, $track)['steps'] ?? []) as $s) {
            $out[(string) $s['key']] = (string) $s['state'];
        }

        return $out;
    }
}
