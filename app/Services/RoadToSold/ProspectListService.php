<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsProspect;
use App\Models\User;
use App\Services\RoadToSold\Support\CurrentAskingPrice;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v3 - the reads behind "My prospects" and "Parked" (road-to-sold.md 22.4). Every query is scoped to the viewer's own / branch /
 * agency breadth (road_to_sold.view) in the query itself, never in the view; an agent outside it simply has no rows.
 *
 * My prospects  search: address, suburb, owner name, owner phone, next action. filters: stage or group, due, selection, owner. sort: due
 *               (default, soonest first, none last), stage, address, days in stage, last outcome. 25 a page.
 * Parked        search: address, owner, reason, note. filters: reason, revisit, parked between, last stage. sort: revisit (default), parked,
 *               address, reason. 25 a page. A manager's view (no agent) also groups the pile by reason.
 */
class ProspectListService
{
    public const PER_PAGE = 25;
    public const PROSPECT_SORTS = ['due', 'stage', 'address', 'days_in_stage', 'last_outcome'];
    public const LINE_SORTS = ['due', 'days_in_stage', 'address'];
    public const LINE_CHIP_LIMIT = 300;
    public const PARKED_SORTS = ['revisit', 'parked', 'address', 'reason'];
    public const DUE_FILTERS = ['overdue', 'today', 'week', 'later', 'none'];
    public const REVISIT_FILTERS = ['due', 'month', 'later'];

    private function base(User $viewer, ?int $agentId, string $track, ?int $branchId = null)
    {
        $q = RtsProspect::query()->visibleTo($viewer)
            ->where('rts_prospects.track', $track)
            ->leftJoin('tracked_properties as tp', 'tp.id', '=', 'rts_prospects.tracked_property_id')
            ->leftJoin('contacts as oc', 'oc.id', '=', 'tp.owner_contact_id')
            ->leftJoin('users as ag', 'ag.id', '=', 'rts_prospects.user_id')
            ->select('rts_prospects.*', 'tp.street_number', 'tp.street_name', 'tp.suburb as tp_suburb', 'tp.last_known_asking_price',
                'oc.first_name as owner_first', 'oc.last_name as owner_last', 'oc.phone as owner_phone', 'ag.name as agent_name');
        if ($agentId !== null) {
            $q->where('rts_prospects.user_id', $agentId);
        }
        if ($branchId !== null) {
            $q->where('rts_prospects.branch_id', $branchId);
        }

        return $q;
    }

    private function like(string $term): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    }

    private function search($q, string $term, array $columns): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $like = $this->like($term);
        $q->where(function ($w) use ($like, $columns) {
            foreach ($columns as $c) {
                $w->orWhereRaw("{$c} LIKE ?", [$like]);
            }
        });
    }

    /**
     * @param  array{q?:?string,stage?:?string,group?:?string,due?:?string,selection?:?string,owner?:?string,sort?:?string,dir?:?string,page?:int} $f
     * @return array{rows:LengthAwarePaginator,counts:array<string,int>}
     */
    public function prospects(User $viewer, int $agentId, array $f = [], string $track = 'sales'): array
    {
        $q = $this->base($viewer, $agentId, $track)->activeStage();
        $this->search($q, (string) ($f['q'] ?? ''), ["CONCAT_WS(' ', tp.street_number, tp.street_name)", 'tp.suburb', "CONCAT_WS(' ', oc.first_name, oc.last_name)", 'oc.phone', 'rts_prospects.next_action_label']);

        // the stage strip counts the whole pile (before the stage / group filter) so the agent sees where everything sits
        $strip = (clone $q)->reorder()->select(DB::raw('rts_prospects.stage as s'), DB::raw('COUNT(*) as n'))->groupBy('rts_prospects.stage')->pluck('n', 's')->map(fn ($v) => (int) $v)->all();
        $counts = [];
        foreach (ProspectStages::keys() as $k) {
            $counts[$k] = $strip[$k] ?? 0;
        }

        if (! empty($f['stage']) && ProspectStages::exists((string) $f['stage'])) {
            $q->where('rts_prospects.stage', $f['stage']);
        } elseif (! empty($f['group']) && in_array($f['group'], ['get_stock', 'sell_stock'], true)) {
            $q->whereIn('rts_prospects.stage', ProspectStages::inGroup($f['group']));
        }
        $today = Carbon::today()->toDateString();
        match ($f['due'] ?? null) {
            'overdue' => $q->whereNotNull('rts_prospects.next_action_at')->whereDate('rts_prospects.next_action_at', '<', $today),
            'today'   => $q->whereDate('rts_prospects.next_action_at', $today),
            'week'    => $q->whereNotNull('rts_prospects.next_action_at')->whereDate('rts_prospects.next_action_at', '>', $today)->whereDate('rts_prospects.next_action_at', '<=', Carbon::today()->addDays(7)->toDateString()),
            'later'   => $q->whereDate('rts_prospects.next_action_at', '>', Carbon::today()->addDays(7)->toDateString()),
            'none'    => $q->whereNull('rts_prospects.next_action_at'),
            default   => null,
        };
        if (! empty($f['selection'])) {
            $q->where('rts_prospects.selection', (string) $f['selection']);
        }
        if (! empty($f['suburb'])) {   // the page's suburb filter (a list of the agent's own suburbs)
            $q->where('tp.suburb', (string) $f['suburb']);
        }
        if (($f['owner'] ?? null) === 'with') {
            $q->whereNotNull('oc.id');
        } elseif (($f['owner'] ?? null) === 'without') {
            $q->whereNull('oc.id');
        }

        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        switch (in_array($f['sort'] ?? 'due', self::PROSPECT_SORTS, true) ? ($f['sort'] ?? 'due') : 'due') {
            case 'stage':
                $order = implode(',', array_map(fn ($k) => DB::getPdo()->quote($k), ProspectStages::keys()));
                $q->orderByRaw("FIELD(rts_prospects.stage, {$order}) {$dir}");
                break;
            case 'address':
                $q->orderBy('tp.street_name', $dir)->orderBy('tp.street_number', $dir);
                break;
            case 'days_in_stage':
                $q->orderBy('rts_prospects.stage_entered_at', $dir === 'asc' ? 'desc' : 'asc');   // most days = oldest entry
                break;
            case 'last_outcome':
                $q->leftJoin('rts_contact_outcomes as lo', 'lo.id', '=', 'rts_prospects.last_outcome_id')->orderByRaw("lo.occurred_at IS NULL, lo.occurred_at {$dir}");
                break;
            default:   // due: soonest first, nothing scheduled last
                $q->orderByRaw("rts_prospects.next_action_at IS NULL, rts_prospects.next_action_at {$dir}");
        }
        $q->orderBy('rts_prospects.id');

        $page = max(1, (int) ($f['page'] ?? 1));
        $total = (clone $q)->reorder()->count('rts_prospects.id');
        $rows = $q->forPage($page, self::PER_PAGE)->get();
        $prices = CurrentAskingPrice::forTracked($rows->pluck('tracked_property_id')->all());
        $items = $rows->map(fn ($r) => $this->row($r, $today, $prices));

        return [
            'rows'   => new LengthAwarePaginator($items->values(), $total, self::PER_PAGE, $page, ['path' => request()->url(), 'query' => request()->query()]),
            'counts' => $counts,
        ];
    }

    /**
     * The production line's chips (road-to-sold.md 22.24): every ACTIVE prospect in the scope, one plain row each, no paging - up to
     * LINE_CHIP_LIMIT, ordered by station then the sort. search: address, suburb, owner name, owner phone, next action. filters: stage (one
     * station), due, suburb. sort: due (default), days_in_stage, address. Scoping in the query (own / branch / agency); a branch or agent
     * outside the breadth simply has no rows.
     *
     * @param  ?int $agentId one agent, or null = everyone in the viewer's breadth;  ?int $branchId = one branch of them
     * @param  array{q?:?string,stage?:?string,due?:?string,suburb?:?string,sort?:?string,dir?:?string} $f
     * @return array{rows:array<int,array<string,mixed>>,total:int,limited:bool}
     */
    public function line(User $viewer, ?int $agentId, array $f = [], string $track = 'sales', ?int $branchId = null): array
    {
        $q = $this->base($viewer, $agentId, $track, $branchId)->activeStage();
        $this->search($q, (string) ($f['q'] ?? ''), ["CONCAT_WS(' ', tp.street_number, tp.street_name)", 'tp.suburb', "CONCAT_WS(' ', oc.first_name, oc.last_name)", 'oc.phone', 'rts_prospects.next_action_label']);
        if (! empty($f['stage']) && ProspectStages::exists((string) $f['stage'])) {
            $q->where('rts_prospects.stage', $f['stage']);
        }
        $today = Carbon::today()->toDateString();
        match ($f['due'] ?? null) {
            'overdue' => $q->whereNotNull('rts_prospects.next_action_at')->whereDate('rts_prospects.next_action_at', '<', $today),
            'today'   => $q->whereDate('rts_prospects.next_action_at', $today),
            'week'    => $q->whereNotNull('rts_prospects.next_action_at')->whereDate('rts_prospects.next_action_at', '>', $today)->whereDate('rts_prospects.next_action_at', '<=', Carbon::today()->addDays(7)->toDateString()),
            'later'   => $q->whereDate('rts_prospects.next_action_at', '>', Carbon::today()->addDays(7)->toDateString()),
            'none'    => $q->whereNull('rts_prospects.next_action_at'),
            default   => null,
        };
        if (! empty($f['suburb'])) {
            $q->where('tp.suburb', (string) $f['suburb']);
        }

        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $order = implode(',', array_map(fn ($k) => DB::getPdo()->quote($k), ProspectStages::keys()));
        $q->orderByRaw("FIELD(rts_prospects.stage, {$order})");
        switch (in_array($f['sort'] ?? 'due', self::LINE_SORTS, true) ? ($f['sort'] ?? 'due') : 'due') {
            case 'address':
                $q->orderBy('tp.street_name', $dir)->orderBy('tp.street_number', $dir);
                break;
            case 'days_in_stage':
                $q->orderBy('rts_prospects.stage_entered_at', $dir);   // the line's default reads "longest at the station first": the oldest entry
                break;
            default:
                $q->orderByRaw("rts_prospects.next_action_at IS NULL, rts_prospects.next_action_at {$dir}");
        }
        $q->orderBy('rts_prospects.id');

        $total = (clone $q)->reorder()->count('rts_prospects.id');
        $rows = $q->limit(self::LINE_CHIP_LIMIT)->get();
        $prices = CurrentAskingPrice::forTracked($rows->pluck('tracked_property_id')->all());

        return [
            'rows'    => $rows->map(fn ($r) => $this->row($r, $today, $prices))->values()->all(),
            'total'   => $total,
            'limited' => $total > self::LINE_CHIP_LIMIT,
        ];
    }

    /**
     * @param  array{q?:?string,reason?:?string,revisit?:?string,from?:?string,to?:?string,stage?:?string,sort?:?string,dir?:?string,page?:int} $f
     * @param  ?int $agentId one agent, or null = everyone in the viewer's breadth (the manager's pile)
     * @return array{rows:LengthAwarePaginator,by_reason:array<int,array{reason:string,count:int}>}
     */
    public function parked(User $viewer, ?int $agentId, array $f = [], string $track = 'sales'): array
    {
        $q = $this->base($viewer, $agentId, $track)->parked();
        $this->search($q, (string) ($f['q'] ?? ''), ["CONCAT_WS(' ', tp.street_number, tp.street_name)", 'tp.suburb', "CONCAT_WS(' ', oc.first_name, oc.last_name)", 'rts_prospects.park_reason_label', 'rts_prospects.park_note']);

        $byReason = (clone $q)->reorder()->select(DB::raw("COALESCE(rts_prospects.park_reason_label, 'No reason given') as r"), DB::raw('COUNT(*) as n'))
            ->groupBy('r')->orderByDesc('n')->get()->map(fn ($x) => ['reason' => (string) $x->r, 'count' => (int) $x->n])->all();

        if (! empty($f['reason'])) {
            $q->where('rts_prospects.park_reason_label', (string) $f['reason']);
        }
        $today = Carbon::today();
        match ($f['revisit'] ?? null) {
            'due'   => $q->whereDate('rts_prospects.parked_until', '<=', $today->toDateString()),
            'month' => $q->whereDate('rts_prospects.parked_until', '>', $today->toDateString())->whereDate('rts_prospects.parked_until', '<=', $today->copy()->addDays(30)->toDateString()),
            'later' => $q->whereDate('rts_prospects.parked_until', '>', $today->copy()->addDays(30)->toDateString()),
            default => null,
        };
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (! empty($f[$k]) && strtotime((string) $f[$k]) !== false) {
                $q->whereDate('rts_prospects.parked_at', $op, Carbon::parse((string) $f[$k])->toDateString());
            }
        }
        if (! empty($f['stage']) && ProspectStages::exists((string) $f['stage'])) {
            $q->where('rts_prospects.parked_from_stage', $f['stage']);
        }

        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        match (in_array($f['sort'] ?? 'revisit', self::PARKED_SORTS, true) ? ($f['sort'] ?? 'revisit') : 'revisit') {
            'parked'  => $q->orderBy('rts_prospects.parked_at', $dir),
            'address' => $q->orderBy('tp.street_name', $dir)->orderBy('tp.street_number', $dir),
            'reason'  => $q->orderBy('rts_prospects.park_reason_label', $dir),
            default   => $q->orderBy('rts_prospects.parked_until', $dir),
        };
        $q->orderBy('rts_prospects.id');

        $page = max(1, (int) ($f['page'] ?? 1));
        $total = (clone $q)->reorder()->count('rts_prospects.id');
        $rows = $q->forPage($page, self::PER_PAGE)->get();
        $prices = CurrentAskingPrice::forTracked($rows->pluck('tracked_property_id')->all());
        $items = $rows->map(fn ($r) => $this->row($r, $today->toDateString(), $prices));

        return [
            'rows'      => new LengthAwarePaginator($items->values(), $total, self::PER_PAGE, $page, ['path' => request()->url(), 'query' => request()->query()]),
            'by_reason' => $byReason,
        ];
    }

    /**
     * One row as the page shows it. The price is the property's current asking price, the same figure Today's intake shows.
     *
     * @param  array<int,int>  $prices  tracked property id => current asking price
     * @return array<string,mixed>
     */
    private function row($r, string $today, array $prices = []): array
    {
        $address = trim(implode(' ', array_filter([$r->street_number, $r->street_name])));
        $due = $r->next_action_at?->toDateString();

        return [
            'id'                  => (int) $r->id,
            'tracked_property_id' => (int) $r->tracked_property_id,
            'property_id'         => $r->property_id ? (int) $r->property_id : null,
            'agent_id'            => (int) $r->user_id,
            'agent_name'          => $r->agent_name,
            'address'             => $address !== '' ? $address : '(no address)',
            'suburb'              => $r->tp_suburb,
            'price'               => $prices[(int) $r->tracked_property_id] ?? ($r->last_known_asking_price ? (int) $r->last_known_asking_price : null),
            'owner_name'          => trim(($r->owner_first ?? '') . ' ' . ($r->owner_last ?? '')) ?: null,
            'owner_phone'         => $r->owner_phone,
            'stage'               => $r->stage,
            'stage_label'         => ProspectStages::label($r->stage),
            'group'               => ProspectStages::ALL[$r->stage]['group'] ?? null,
            'next_action'         => $r->next_action_label,
            'next_action_at'      => $due,
            'due_state'           => $due === null ? 'none' : ($due < $today ? 'overdue' : ($due === $today ? 'today' : 'later')),
            'days_in_stage'       => $r->stage_entered_at ? (int) $r->stage_entered_at->startOfDay()->diffInDays(Carbon::parse($today)) : null,
            'reason'              => $r->reason,
            'reason_facts'        => $r->reason_facts,
            'selection'           => $r->selection,
            'tries'               => (int) $r->tries,
            'park_reason'         => $r->park_reason_label,
            'park_note'           => $r->park_note,
            'parked_at'           => $r->parked_at?->toDateString(),
            'parked_until'        => $r->parked_until?->toDateString(),
            'parked_from_stage'   => $r->parked_from_stage ? ProspectStages::label($r->parked_from_stage) : null,
        ];
    }
}
