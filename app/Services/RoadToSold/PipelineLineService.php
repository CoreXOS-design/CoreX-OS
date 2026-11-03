<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v3 - the production line (road-to-sold.md 22.24): the 18 stations grouped into five phases with what each holds, the flow
 * between phases this month (how many moved on, how many fell out), the list behind a flow figure, and the dates a prospect entered each
 * station (for the card). Read only.
 *
 * The station figures come from StageCountService (one source of truth for now / entered / average days / stalled / overdue); the flow
 * and the entry dates come from the append-only prospect history (rts_prospect_events), so a prospect that moved on keeps counting.
 * Scoping is in the query: the viewer's own / branch / agency breadth (RtsProspect::visibleTo), narrowed to an agent or a branch inside it.
 */
class PipelineLineService
{
    public const LIST_LIMIT = 300;

    public function __construct(private readonly StageCountService $stages)
    {
    }

    /**
     * @param  ?int $agentId one agent, or null = everyone in the viewer's breadth;  ?int $branchId = one branch of them
     * @return array<int,array{key:string,label:string,total:int,behind:int,stations:array<int,array<string,mixed>>,moved:int,out:int,last:bool}>
     */
    public function phases(User $viewer, ?int $agentId, ?int $branchId, CarbonInterface $from, CarbonInterface $to, string $track = 'sales'): array
    {
        $stats = collect($this->stages->forStages($viewer, $agentId, $from, $to, $track, $branchId))->keyBy('stage');
        $flow = $this->flows($viewer, $agentId, $branchId, $from, $to, $track);

        $out = [];
        $keys = ProspectStages::phaseKeys();
        foreach (ProspectStages::PHASES as $phase => $meta) {
            $stations = [];
            foreach ($meta['stages'] as $stage) {
                $s = $stats[$stage];
                $stations[] = $s + ['short' => ProspectStages::short($stage), 'phase' => $phase];
            }
            $i = ProspectStages::phaseIndex($phase);
            $out[] = [
                'key'      => $phase,
                'label'    => $meta['label'],
                'total'    => array_sum(array_column($stations, 'now')),
                'behind'   => array_sum(array_column($stations, 'behind')),
                'stations' => $stations,
                'moved'    => count($flow['moved'][$i] ?? []),
                'out'      => count($flow['out'][$i] ?? []),
                'last'     => $phase === end($keys),
            ];
        }

        return $out;
    }

    /**
     * Who crossed which boundary, and who fell out of which phase, in the window. Index = phase index; the value is a set of prospect ids.
     * moved[i] = left phase i for a later phase (for the last phase: reached Registered); out[i] = parked or lost from phase i.
     *
     * @return array{moved:array<int,array<int,int>>,out:array<int,array<int,int>>,at:array<int,string>}
     */
    public function flows(User $viewer, ?int $agentId, ?int $branchId, CarbonInterface $from, CarbonInterface $to, string $track = 'sales'): array
    {
        $rows = DB::table('rts_prospect_events as e')
            ->where('e.agency_id', (int) ($viewer->effectiveAgencyId() ?: 0))->where('e.track', $track)
            ->whereIn('e.kind', ['stage', 'park', 'close'])
            ->whereBetween('e.occurred_at', [Carbon::instance($from)->startOfDay()->toDateTimeString(), Carbon::instance($to)->endOfDay()->toDateTimeString()])
            ->whereIn('e.prospect_id', $this->visible($viewer, $agentId, $branchId, $track)->select('rts_prospects.id')->toBase())
            ->get(['e.prospect_id', 'e.kind', 'e.from_stage', 'e.to_stage', 'e.note', 'e.occurred_at']);

        $last = count(ProspectStages::PHASES) - 1;
        $moved = [];
        $out = [];
        $at = [];
        foreach ($rows as $r) {
            $pid = (int) $r->prospect_id;
            $fromIdx = $r->from_stage ? ProspectStages::phaseIndex(ProspectStages::phaseOf((string) $r->from_stage)) : -1;
            if ($r->kind === 'stage') {
                $toIdx = $r->to_stage ? ProspectStages::phaseIndex(ProspectStages::phaseOf((string) $r->to_stage)) : -1;
                if ($fromIdx < 0 || $toIdx <= $fromIdx) {
                    continue;   // a first placement, or a step back (a lost deal returns to Advertising): not a crossing
                }
                for ($k = $fromIdx; $k < $toIdx; $k++) {
                    $moved[$k][$pid] = $pid;
                    $at["m{$k}:{$pid}"] = (string) $r->occurred_at;
                }
                if ($r->to_stage === ProspectStages::REGISTERED) {
                    $moved[$last][$pid] = $pid;
                    $at["m{$last}:{$pid}"] = (string) $r->occurred_at;
                }
            } elseif ($fromIdx >= 0 && ! ($r->kind === 'close' && $r->note === 'registered')) {
                $out[$fromIdx][$pid] = $pid;   // parked, or closed for any reason but a registration
                $at["o{$fromIdx}:{$pid}"] = (string) $r->occurred_at;
            }
        }

        return ['moved' => $moved, 'out' => $out, 'at' => $at];
    }

    /**
     * The list behind a flow figure: the properties that moved on from (or fell out of) one phase this month, with where each is now.
     *
     * @param  'moved'|'out' $kind
     * @return array<int,array<string,mixed>>
     */
    public function flowList(User $viewer, ?int $agentId, ?int $branchId, string $phase, string $kind, CarbonInterface $from, CarbonInterface $to, string $track = 'sales'): array
    {
        $idx = ProspectStages::phaseIndex($phase);
        if ($idx < 0 || ! in_array($kind, ['moved', 'out'], true)) {
            return [];
        }
        $flow = $this->flows($viewer, $agentId, $branchId, $from, $to, $track);
        $ids = array_values($flow[$kind][$idx] ?? []);
        if ($ids === []) {
            return [];
        }
        $prefix = $kind === 'moved' ? 'm' : 'o';

        $rows = $this->visible($viewer, $agentId, $branchId, $track)->withTrashed()
            ->leftJoin('tracked_properties as tp', 'tp.id', '=', 'rts_prospects.tracked_property_id')
            ->leftJoin('contacts as oc', 'oc.id', '=', 'tp.owner_contact_id')
            ->leftJoin('users as ag', 'ag.id', '=', 'rts_prospects.user_id')
            ->whereIn('rts_prospects.id', array_slice($ids, 0, self::LIST_LIMIT))
            ->get(['rts_prospects.id', 'rts_prospects.stage', 'rts_prospects.parked_at', 'rts_prospects.park_reason_label', 'rts_prospects.closed_at', 'rts_prospects.closed_reason',
                'rts_prospects.released_at', 'tp.street_number', 'tp.street_name', 'tp.suburb', 'oc.first_name', 'oc.last_name', 'ag.name as agent_name']);

        return $rows->map(function ($r) use ($flow, $prefix, $idx) {
            $address = trim(implode(' ', array_filter([$r->street_number, $r->street_name])));
            $now = $r->closed_at ? ($r->closed_reason === 'registered' ? 'Registered' : 'Closed' . ($r->closed_reason ? ' - ' . str_replace('_', ' ', $r->closed_reason) : ''))
                : ($r->released_at ? 'Given back to the pool'
                : ($r->parked_at ? 'Set aside' . ($r->park_reason_label ? ' - ' . $r->park_reason_label : '') : 'At ' . ProspectStages::label((string) $r->stage)));

            return [
                'id'      => (int) $r->id,
                'address' => $address !== '' ? $address : '(no address)',
                'suburb'  => $r->suburb,
                'owner'   => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: null,
                'agent'   => $r->agent_name,
                'now'     => $now,
                'when'    => $flow['at']["{$prefix}{$idx}:{$r->id}"] ?? null,
            ];
        })->sortByDesc('when')->values()->all();
    }

    /**
     * The date each prospect entered each station it has been at (the first time), from the history: prospect id => station => Y-m-d.
     *
     * @param  int[] $prospectIds  ids already inside the viewer's breadth (they came from a scoped query)
     * @return array<int,array<string,string>>
     */
    public function journeys(array $prospectIds): array
    {
        if ($prospectIds === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($prospectIds, 500) as $chunk) {
            $rows = DB::table('rts_prospect_events')->whereIn('prospect_id', $chunk)->whereIn('kind', ['placed', 'stage'])->whereNotNull('to_stage')
                ->orderBy('occurred_at')->orderBy('id')->get(['prospect_id', 'to_stage', 'occurred_at']);
            foreach ($rows as $r) {
                $out[(int) $r->prospect_id][(string) $r->to_stage] ??= Carbon::parse($r->occurred_at)->toDateString();
            }
        }

        return $out;
    }

    private function visible(User $viewer, ?int $agentId, ?int $branchId, string $track)
    {
        return RtsProspect::query()->visibleTo($viewer)->where('rts_prospects.track', $track)
            ->when($agentId !== null, fn ($q) => $q->where('rts_prospects.user_id', $agentId))
            ->when($branchId !== null, fn ($q) => $q->where('rts_prospects.branch_id', $branchId));
    }
}
