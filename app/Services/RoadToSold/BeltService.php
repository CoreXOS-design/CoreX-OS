<?php

declare(strict_types=1);

namespace App\Services\RoadToSold;

use App\Models\Property;
use App\Models\RoadToSold\RtsListItem;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold v4 - the belt tiles and the lists behind them (.ai/specs/road-to-sold.md 23.13).
 *
 * One tile per station in `config/road-to-sold-belt.php` (only `built` ones). Each tile's count comes from TODAY's data - the station's own source
 * (open claims, owner on the property, presentations without an answer, the agent's draft / active / live stock) - and switches to the prospect
 * stage count the moment Lane A1 makes the station's stage key a real stage (`ProspectStages::has`), so A1 plugs in without rework.
 *
 * Scoping: the caller (RoadToSoldController) has already limited $agent to the viewer's breadth; every query here is bound to the agent AND
 * the agent's agency, never to a figure for anyone else. Read only.
 */
final class BeltService
{
    public const PER_PAGE = 25;

    /**
     * The owner's name as the home list's row reads it (CallListService::ownerOf): the contact linked on the tracked property (alias `o`), else the first
     * contact in its owners table. ONE source for the tile lists and the rows - a tile list must never say "-" for an owner the row names.
     */
    public const OWNER_NAME_SQL = "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', o.first_name, o.last_name)), ''), (SELECT NULLIF(TRIM(CONCAT_WS(' ', oc.first_name, oc.last_name)), '') FROM tracked_property_owners tpo JOIN contacts oc ON oc.id = tpo.contact_id WHERE tpo.tracked_property_id = tp.id ORDER BY tpo.is_primary DESC, tpo.id LIMIT 1))";

    private const UNIT_ORDER = ['hour' => 0, 'day' => 1, 'week' => 2, 'month' => 3];
    private const PERIOD_WORDS = ['hour' => 'this hour', 'day' => 'today', 'week' => 'this week', 'month' => 'this month'];

    public function __construct(
        private readonly StageCountService $stages,
        private readonly WorkdayService $workdays,
        private readonly RecipeProgressService $recipe,
        private readonly ConveyorService $conveyor,
        private readonly CallListService $rows,
        private readonly TargetsService $targets,
    ) {
    }

    /**
     * The recipe target that belongs to a station (23.13): the station's tasks (config `tasks`, by task key) that carry a target for the agent's level,
     * on the SHORTEST period they use (a daily target beats a weekly one). Null when the agency set no target for the station.
     *
     * @param  array<int,array<string,mixed>>  $tasks  RecipeProgressService::forAgent()['tasks']
     * @return array{target:int,done:int,unit:string,period:string}|null
     */
    private function recipeTarget(array $station, array $tasks): ?array
    {
        $keys = (array) ($station['tasks'] ?? []);
        $rows = array_values(array_filter($tasks, fn ($t) => in_array($t['key'], $keys, true) && (int) $t['target'] > 0));
        if ($rows === []) {
            return null;
        }
        $unit = collect($rows)->pluck('unit')->sortBy(fn ($u) => self::UNIT_ORDER[$u] ?? 9)->first();
        $use = array_filter($rows, fn ($t) => $t['unit'] === $unit);

        return ['target' => (int) array_sum(array_column($use, 'target')), 'done' => (int) array_sum(array_column($use, 'actual')), 'unit' => (string) $unit, 'period' => self::PERIOD_WORDS[$unit] ?? ''];
    }

    /** @return list<array<string,mixed>> the stations to draw, in belt order */
    public static function stations(): array
    {
        return array_values(array_filter((array) config('road-to-sold-belt.stations', []), fn ($s) => ! empty($s['built'])));
    }

    /** @return array<string,mixed>|null */
    public static function station(string $key): ?array
    {
        foreach (self::stations() as $s) {
            if ($s['key'] === $key) {
                return $s;
            }
        }

        return null;
    }

    /**
     * @return list<array{key:string,label:string,short:string,figure:string,count:int,target:?int,alert:int,note:?string,url:array,stage:?string}>
     *         `url` is the route parameters for road-to-sold.index
     */
    public function tiles(User $viewer, User $agent, string $track, CarbonInterface $date, array $routeBase = []): array
    {
        if ($track !== RtsListItem::TRACK_SALES) {
            return [];   // Road to Rented has no belt yet (23)
        }
        $day = Carbon::instance($date)->startOfDay();
        $byStage = collect($this->stages->forStages($viewer, (int) $agent->id, $day->copy()->startOfMonth(), $day, $track))->keyBy('stage');
        $progress = $this->recipe->forAgent($agent, $track, $day->isToday() ? now() : $day->copy()->endOfDay());
        $figs = $this->conveyor->figures($agent, $track, $day);   // 23.24: to do / moved on / late / reached, from the same history the funnel reads
        $when = $day->isToday() ? 'today' : $day->format('j M');
        $out = [];
        foreach (self::stations() as $s) {
            $stageKey = (string) ($s['stages'][0] ?? '');
            $useStage = ! empty($s['prefer_stage']) && $stageKey !== '' && ProspectStages::has($stageKey);
            $source = $useStage || $s['source'] === 'stage' ? 'stage' : $s['source'];

            $count = 0;
            $alert = 0;
            $target = null;
            $note = null;
            $url = ['station' => $s['key']];   // the home list, filtered to this station (the Loaded tile is the home list itself)
            switch ($source) {
                case 'stage':
                    foreach ($s['stages'] as $k) {
                        $count += (int) ($byStage[$k]['now'] ?? 0);
                        $alert += (int) ($byStage[$k]['behind'] ?? 0);
                    }
                    break;
                case 'list_today':
                    $count = (clone $this->listItems($agent, $day, $track))->where('rts_list_items.origin', '!=', RtsListItem::ORIGIN_FOLLOW_UP)->count();
                    $target = $this->workdays->targetFor($agent, $day, $track);
                    $url = [];
                    break;
                default:
                    [$count, $alert] = $this->countFor($source, $agent);
                    break;
            }
            if ($s['key'] === 'held') {
                $owed = $this->owedCount($agent);
                $note = $owed > 0 ? $owed . ' owed' : null;
                $alert += $owed;
            }
            // "done today / target" wherever the agency set a target for the station; the count otherwise (the Stock loaded tile keeps its weekday target)
            $period = $target !== null ? 'today' : null;
            $done = $target !== null ? $count : null;
            $tg = $s['key'] !== 'advertised' ? $this->targets->stationTarget($agent, 'me', $s['key'], $day, $track) : null;
            if ($s['key'] !== 'loaded' && $tg !== null && $tg['month'] > 0) {
                $target = (int) ceil($tg['month']);
                $done = (int) (($figs[$s['key']]['month'] ?? 0));
                $period = 'this month';
            }
            $subs = array_values(array_filter([$period !== null && $period !== 'today' ? $period : null, $note]));
            $link = fn (array $extra) => array_filter($routeBase + $extra, fn ($v) => $v !== null && $v !== '');
            $conv = $this->conveyorLines($s['key'], $figs[$s['key']] ?? ['todo' => 0, 'late' => 0, 'moved' => 0, 'today' => 0, 'month' => 0], $count, $agent, $day, $track, $when, $link);
            $out[] = [
                'key' => $s['key'], 'label' => (string) $s['label'], 'short' => (string) $s['short'],
                'figure' => $target !== null ? ($done ?? $count) . ' / ' . $target : (string) $count,
                'count' => $count, 'target' => $target, 'done' => $done, 'period' => $period, 'alert' => $alert, 'note' => $subs === [] ? null : implode(' · ', $subs),
                'url' => array_filter($routeBase + $url, fn ($v) => $v !== null && $v !== ''),
                'stage' => $stageKey !== '' && $source === 'stage' ? $stageKey : null,
                'tgt' => $tg, 'tgt_line' => self::targetLine($s['key'], $tg),
            ] + $conv;
        }

        return $out;
    }

    /**
     * The three lines of a tile (road-to-sold.md 23.24). Backlog stations: "N to do", "N moved on today", "N late" (only when > 0). Loaded: "N to do", "n of N worked".
     * The last four stations (Won, Draft, Active, Advertised): "N this month", "N today". Each line is a link to the station's list with that filter.
     *
     * @param array{todo:int,late:int,moved:int,today:int,month:int} $f
     * @return array{kind:string,todo:int,moved:int,late:int,month:int,lines:list<array{key:string,text:string,n:int,url:array,bad:bool}>}
     */
    private function conveyorLines(string $key, array $f, int $tileCount, User $agent, Carbon $day, string $track, string $when, callable $link): array
    {
        if ($key === 'loaded') {
            [$todo, $worked, $target] = $this->loadedFigures($agent, $day, $track);

            return ['kind' => 'loaded', 'todo' => $todo, 'moved' => $worked, 'late' => 0, 'month' => 0, 'lines' => [
                ['key' => 'todo', 'text' => $todo . ' to do', 'n' => $todo, 'url' => $link([]), 'bad' => false],
                ['key' => 'moved', 'text' => $worked . ' of ' . $target . ' worked', 'n' => $worked, 'url' => $link(['status' => 'worked']), 'bad' => false],
            ]];
        }
        if (in_array($key, ConveyorService::ARRIVAL, true)) {
            return ['kind' => 'arrival', 'todo' => $f['todo'], 'moved' => $f['today'], 'late' => 0, 'month' => $f['month'], 'lines' => [
                ['key' => 'month', 'text' => $f['month'] . ' this month', 'n' => $f['month'], 'url' => $link(['station' => $key, 'filter' => 'month']), 'bad' => false],
                ['key' => 'moved', 'text' => $f['today'] . ' ' . $when, 'n' => $f['today'], 'url' => $link(['station' => $key, 'filter' => 'today']), 'bad' => false],
            ]];
        }
        $lines = [
            ['key' => 'todo', 'text' => $tileCount . ' to do', 'n' => $tileCount, 'url' => $link(['station' => $key]), 'bad' => false],
            ['key' => 'moved', 'text' => $f['moved'] . ' moved on ' . $when, 'n' => $f['moved'], 'url' => $link(['station' => $key, 'filter' => 'moved']), 'bad' => false],
        ];
        if ($f['late'] > 0) {
            $lines[] = ['key' => 'late', 'text' => $f['late'] . ' late', 'n' => $f['late'], 'url' => $link(['station' => $key, 'filter' => 'late']), 'bad' => true];
        }

        return ['kind' => 'backlog', 'todo' => $tileCount, 'moved' => $f['moved'], 'late' => $f['late'], 'month' => $f['month'], 'lines' => $lines];
    }

    /**
     * Stock loaded, the conveyor way (23.24): what is still to do on the day's list, and how many of the day's load are worked. A loaded record that is already CLAIMED
     * (the day feed puts the agent's claims first) is worked at its own station, so it is not counted twice as "to do" - it counts once, at Claimed - but it is part of the
     * day's load, so it stays in "n of N worked", the same numbers as the header line.
     *
     * @return array{0:int,1:int,2:int} to do, worked, the day's load
     */
    public function loadedFigures(User $agent, Carbon $day, string $track): array
    {
        $items = $this->listItems($agent, $day, $track)->get(['rts_list_items.status', 'rts_list_items.origin', 'rts_list_items.prospect_id']);
        $stages = $items->pluck('prospect_id')->filter()->isEmpty() ? collect()
            : DB::table('rts_prospects')->whereIn('id', $items->pluck('prospect_id')->filter()->all())->pluck('stage', 'id');
        $counting = $items->reject(fn ($i) => in_array($i->origin, [RtsListItem::ORIGIN_ADDED, RtsListItem::ORIGIN_FOLLOW_UP], true));
        $todo = $items->filter(fn ($i) => $i->status === RtsListItem::STATUS_OPEN && $i->origin !== RtsListItem::ORIGIN_FOLLOW_UP
            && ($i->prospect_id === null || ! isset($stages[$i->prospect_id]) || $stages[$i->prospect_id] === ProspectStages::FOUND))->count();
        $worked = $counting->where('status', RtsListItem::STATUS_WORKED)->count();
        $load = $counting->count() ?: $this->workdays->targetFor($agent, $day, $track);

        return [$todo, $worked, (int) $load];
    }

    /**
     * The compare popover (23.12): the same figure for me, my branch and the whole agency. Aggregates only - never another agent's rows - so any agent may
     * see them. `now` = the count at the station today; `reached` = properties that entered the station this month (stage stations); `done` / `target` =
     * the recipe target summed over the agents in the scope.
     *
     * @return array{station:string,label:string,period:string,scopes:list<array<string,mixed>>}
     */
    public function scopeFigures(User $agent, string $stationKey, string $track, CarbonInterface $date): array
    {
        abort_if(self::station($stationKey) === null, 404);
        // a minute is fresh enough for a compare popover (the recipe is read once per agent in the scope); tests never cache
        $compute = fn () => $this->computeScopeFigures($agent, $stationKey, $track, $date);

        return app()->runningUnitTests() ? $compute() : \Illuminate\Support\Facades\Cache::remember('rts-belt-scopes:' . $agent->id . ':' . $stationKey . ':' . $track . ':' . Carbon::instance($date)->toDateString(), 60, $compute);
    }

    /** @return array{station:string,label:string,period:string,scopes:list<array<string,mixed>>} */
    private function computeScopeFigures(User $agent, string $stationKey, string $track, CarbonInterface $date): array
    {
        $s = self::station($stationKey);
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $day = Carbon::instance($date)->startOfDay();
        $people = fn (?int $branch) => User::query()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('is_active', true)->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('is_assistant')->orWhere('is_assistant', false))
            ->when($branch !== null, fn ($q) => $q->where('branch_id', $branch))->limit(300)->get(['id', 'name', 'agency_id', 'branch_id']);
        $groups = ['me' => ['Me', collect([$agent])]];
        if ($agent->branch_id) {
            $groups['branch'] = ['My branch', $people((int) $agent->branch_id)];
        }
        $groups['agency'] = ['The agency', $people(null)];

        $stageKey = (string) ($s['stages'][0] ?? '');
        $useStage = ($s['source'] === 'stage') || (! empty($s['prefer_stage']) && ProspectStages::has($stageKey));
        $out = [];
        foreach ($groups as $key => [$label, $users]) {
            $ids = $users->pluck('id')->map(fn ($v) => (int) $v)->all();
            $row = ['scope' => $key, 'label' => $label, 'agents' => count($ids), 'now' => 0, 'reached' => null, 'done' => null, 'target' => null, 'period' => null];
            if ($useStage) {
                $q = DB::table('rts_prospects')->where('agency_id', $agencyId)->where('track', $track)->whereIn('user_id', $ids)->whereIn('stage', $s['stages'])
                    ->whereNull('deleted_at')->whereNull('closed_at')->whereNull('released_at')->whereNull('parked_at');
                $row['now'] = (int) $q->count();
                $row['reached'] = (int) DB::table('rts_prospect_events as e')->where('e.agency_id', $agencyId)->where('e.track', $track)->where('e.kind', 'stage')
                    ->whereIn('e.user_id', $ids)->whereIn('e.to_stage', $s['stages'])->whereBetween('e.occurred_at', [$day->copy()->startOfMonth()->toDateTimeString(), $day->copy()->endOfDay()->toDateTimeString()])
                    ->distinct()->count('e.prospect_id');
            } elseif ($s['source'] === 'list_today') {
                $row['now'] = (int) DB::table('rts_list_items')->where('agency_id', $agencyId)->where('track', $track)->whereIn('user_id', $ids)->whereDate('list_date', $day->toDateString())
                    ->where('origin', '!=', RtsListItem::ORIGIN_FOLLOW_UP)->where('status', '<>', RtsListItem::STATUS_SWAPPED)->whereNull('deleted_at')->count();
                $row['target'] = (int) $users->sum(fn ($u) => $this->workdays->targetFor($u, $day, $track));
                $row['done'] = $row['now'];
                $row['period'] = 'today';
            } else {
                [$q] = $this->queryFor($s['key'] === 'held' ? 'owed' : $s['source'], $agencyId, $ids, '');
                $row['now'] = (int) DB::query()->fromSub($q, 't')->count();
            }
            if ($s['key'] !== 'advertised') {   // 23.29: the derived-or-overridden target of the SAME scope (me / my branch / the agency); one source
                $tg = $this->targets->stationTarget($agent, $key, $s['key'], $day, $track);
                if ($tg !== null) {
                    $row['rate_next'] = $tg['rate_next'];
                    $row['estimate'] = (bool) $tg['rate_estimate'];   // est. only where the seed rate was used at this station
                    $row['derived'] = (int) ceil(($s['key'] === 'loaded' ? $tg['derived_day'] : $tg['derived_month']) - 1e-9);
                    $row['source'] = $tg['source'];
                    if ($s['key'] !== 'loaded' && $tg['month'] > 0) {
                        $row['target'] = (int) ceil($tg['month']);
                        $row['done'] = (int) ($row['reached'] ?? 0);
                        $row['period'] = 'this month';
                    }
                }
            }
            $out[] = $row;
        }

        return ['station' => $s['key'], 'label' => (string) $s['label'], 'period' => 'this month', 'scopes' => $out];
    }

    /** The short target line under a tile: what it should be (derived or set), the conversion to the next station, "est." when the figure rests on default rates. */
    public static function targetLine(string $key, ?array $tg): ?string
    {
        if ($tg === null) {
            return null;
        }
        $f = fn ($v) => (string) (int) ceil((float) $v - 1e-9);   // properties are counted: whole numbers, rounded up
        if ($key === 'loaded') {
            return 'set ' . $f($tg['day']) . '/day · need ' . $f($tg['derived_day']);
        }
        $word = in_array($tg['source'], ['override', 'recipe'], true) ? 'set ' : 'should be ';
        $conv = $tg['rate_next'] !== null ? ' · ' . $f($tg['rate_next']) . '% on' . ($tg['rate_estimate'] ? ' est.' : '') : '';

        return $word . $f($tg['month']) . '/mo' . $conv;
    }

    /** The filters of a station list (23.24): the first seven stations read to do / moved on / late / all; the last four read at the station / today / this month / all. */
    public static function filtersFor(string $stationKey): array
    {
        return in_array($stationKey, ConveyorService::ARRIVAL, true)
            ? ['month' => 'Reached this month', 'today' => 'Reached today', 'todo' => 'At this station now', 'all' => 'All']
            : ['todo' => 'To do', 'moved' => 'Moved on today', 'late' => 'Late', 'all' => 'All'];
    }

    /** The filter a station list opens on: line 1 of its tile. */
    public static function defaultFilter(string $stationKey): string
    {
        return in_array($stationKey, ConveyorService::ARRIVAL, true) ? 'month' : 'todo';
    }

    /**
     * The list behind a tile (23.24): the agent's records at (or that moved on from, or reached) one station, each in the Loaded list's row layout. Search (address / suburb),
     * sort (late first then oldest due by default; oldest first; address), filter (to do / moved on today / late / all), 25 a page. The evaluations owed feedback
     * stay one click away (`owed`) from the Held list.
     *
     * @param  array{q?:string,sort?:string,dir?:string,page?:int,filter?:string,owed?:bool,date?:?string}  $f
     * @return array<string,mixed> rows (paginator of row arrays), label, heading, empty, owed, filter, filters (key => [label, n]), owed_count, kind
     */
    public function list(User $agent, string $stationKey, array $f, string $track = RtsListItem::TRACK_SALES): array
    {
        $s = self::station($stationKey);
        abort_if($s === null, 404);
        $day = Carbon::instance(isset($f['date']) && $f['date'] ? Carbon::parse($f['date']) : now())->startOfDay();
        if ($stationKey === 'held' && ! empty($f['owed'])) {
            return $this->owedList($agent, $s, $f);
        }
        $filters = self::filtersFor($stationKey);
        $filter = array_key_exists((string) ($f['filter'] ?? ''), $filters) ? (string) $f['filter'] : self::defaultFilter($stationKey);
        $label = (string) $s['label'];
        $figs = $this->conveyor->figures($agent, $track, $day);
        $mine = $figs[$stationKey] ?? ['todo' => 0, 'late' => 0, 'moved' => 0, 'today' => 0, 'month' => 0];

        $here = $this->conveyor->records($agent, $track, $day)->where('station', $stationKey)->values();
        $ids = fn (string $kind) => $this->conveyor->idsFor($agent, $track, $day, $stationKey, $kind);
        $byId = fn (array $want) => $this->conveyor->recordsByIds($agent, $track, $day, $want);
        $recs = match ($filter) {
            'late' => $here->where('late', true)->values(),
            'moved' => $byId($ids('moved')),
            'today' => $byId($ids('today')),
            'month' => $byId($ids('month')),
            'all' => $here->concat($byId(in_array($stationKey, ConveyorService::ARRIVAL, true) ? $ids('month') : $ids('moved')))->unique('id')->values(),
            default => $here,
        };
        // counts for the filter chips: the same figures the tile shows
        $chips = in_array($stationKey, ConveyorService::ARRIVAL, true)
            ? ['month' => $mine['month'], 'today' => $mine['today'], 'todo' => $mine['todo']]
            : ['todo' => $mine['todo'], 'moved' => $mine['moved'], 'late' => $mine['late']];

        $term = mb_strtolower(trim((string) ($f['q'] ?? '')));
        if ($term !== '') {
            $recs = $recs->filter(fn ($r) => str_contains(mb_strtolower($r['title'] . ' ' . ($r['suburb'] ?? '')), $term))->values();
        }
        $dir = ($f['dir'] ?? 'asc') === 'desc' ? -1 : 1;
        $sort = in_array($f['sort'] ?? '', ['due', 'age', 'address'], true) ? $f['sort'] : 'due';
        $recs = $recs->sort(function ($a, $b) use ($sort, $dir) {
            $cmp = match ($sort) {
                'address' => strcmp(mb_strtolower($a['title']), mb_strtolower($b['title'])),
                'age' => strcmp((string) $a['since'], (string) $b['since']),
                // late first, then the oldest due date; no date last
                default => [(int) ! $a['late'], $a['due']?->timestamp ?? PHP_INT_MAX, (string) $a['since']] <=> [(int) ! $b['late'], $b['due']?->timestamp ?? PHP_INT_MAX, (string) $b['since']],
            };

            return $cmp === 0 ? $a['id'] <=> $b['id'] : $cmp * $dir;
        })->values();

        $page = max(1, (int) ($f['page'] ?? 1));
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator($recs->forPage($page, self::PER_PAGE)->values(), $recs->count(), self::PER_PAGE, $page, ['path' => request()->url(), 'query' => request()->query()]);
        $agencyId = (int) ($agent->effectiveAgencyId() ?: 0);
        $rich = $this->rows->rowsForTracked($paginator->getCollection()->pluck('tracked_property_id')->all(), $agencyId, $track);
        $paginator->setCollection($paginator->getCollection()->map(fn ($r) => $this->conveyorRow($r, $rich[$r['tracked_property_id']] ?? [], $stationKey, $day)));

        $headings = ['todo' => $label . ' - to do', 'moved' => 'Moved on from ' . $label . ' today', 'late' => $label . ' - late', 'all' => $label . ' - everything',
            'today' => 'Reached ' . $label . ' today', 'month' => 'Reached ' . $label . ' this month'];
        $empties = ['todo' => 'Nothing is waiting at this station.', 'moved' => 'Nothing has moved on from this station today.', 'late' => 'Nothing is late here. Well done.', 'all' => 'Nothing at this station.',
            'today' => 'Nothing has reached this station today.', 'month' => 'Nothing has reached this station this month.'];

        return [
            'rows' => $paginator, 'label' => $label, 'heading' => $headings[$filter] ?? $label, 'empty' => $empties[$filter] ?? 'Nothing at this station.', 'owed' => false,
            'filter' => $filter, 'filters' => collect($filters)->map(fn ($l, $k) => ['label' => $l, 'n' => $chips[$k] ?? null])->all(),
            'owed_count' => $stationKey === 'held' ? $this->owedCount($agent) : 0, 'kind' => in_array($stationKey, ConveyorService::ARRIVAL, true) ? 'arrival' : 'backlog',
        ];
    }

    /** One row of a station list: the Loaded list's fields (why, address, price, days on market, agency, owner, last outcome) + when it is due and its ONE next action. */
    private function conveyorRow(array $rec, array $rich, string $listStation, Carbon $day): array
    {
        $at = $rec['station'];
        $stationLabel = (string) (self::station((string) $at)['label'] ?? '');
        $due = $rec['due'];
        $r = $rich + ['address' => $rec['title'] ?: 'Address not captured', 'street' => '', 'suburb' => $rec['suburb'], 'price' => null, 'dom' => null, 'agency_name' => null,
            'owner_name' => null, 'owner_phone' => null, 'find_owner_url' => null, 'last_outcome' => null];
        $apptTimed = $due !== null && in_array($at, ['booked', 'prepared'], true) && ! empty($rec['appt_at']) ? Carbon::parse($rec['appt_at']) : null;
        $why = $at === 'claimed' && $rec['live'] ? $this->conveyor->claimLine($rec['id']) : (trim((string) $rec['reason']) !== '' ? (string) $rec['reason'] : '');

        return array_merge($r, [
            'prospect_id' => $rec['id'], 'tracked_property_id' => $rec['tracked_property_id'], 'station' => $at, 'station_label' => $stationLabel, 'at_station' => $at === $listStation,
            'live' => $rec['live'], 'why' => $why, 'since' => $rec['since'], 'late' => (bool) $rec['late'], 'due' => $due,
            'due_label' => $due === null ? null : (($apptTimed && $apptTimed->format('H:i') !== '00:00') ? $apptTimed->format('D j M H:i') : $due->format('D j M')),
            'action' => $rec['live'] ? $this->actionFor((string) $at, (int) $rec['id'], $r['owner_name'] !== null) : null,
            'view_url' => route('road-to-sold.index', array_filter(['block' => 'prospects', 'p_q' => $rec['title']])),
        ]);
    }

    /**
     * The ONE next action a record's row offers (23.12, the card's mapping): Loaded / Claimed - Find owner; Owner found - Log contact; Contacted - Book evaluation;
     * Booked - Appointment feedback; Prepared - Presentation results; Held - Result; Won - Move to Draft; Draft - Make active.
     *
     * @return array{kind:string,label:string,step_url:?string,step_label:?string}|null
     */
    private function actionFor(string $station, int $prospectId, bool $hasOwner): ?array
    {
        $step = fn (string $key) => ['kind' => 'step', 'label' => ProspectStepService::LABELS[$key], 'step_url' => route('road-to-sold.prospects.step', [$prospectId, $key]), 'step_label' => ProspectStepService::LABELS[$key]];

        return match ($station) {
            'claimed' => $step($hasOwner ? 'pitch' : 'find_owner'),
            'owner' => ['kind' => 'log_contact', 'label' => 'Log contact', 'step_url' => null, 'step_label' => null],
            'contacted' => $step('evaluation'),
            'booked' => $step('evaluation_feedback'),
            // 23.25 (new stations only; the other buttons are Johan's to remap, 23.28 Q13): Prepared asks how the presentation went, Won moves the property to Draft, Draft makes it Active
            'prepared' => $step('results'),
            'won' => $step('draft'),
            'draft' => $step('active'),
            'held' => ['kind' => 'result', 'label' => 'Result', 'step_url' => null, 'step_label' => null],
            default => null,
        };
    }

    /** The evaluations whose time has passed with no feedback: the Held station's "owed" list (23.10), one click from the Held list. */
    private function owedList(User $agent, array $s, array $f): array
    {
        [$q, $titleExpr, $heading, $empty] = $this->query('owed', $agent, (string) $s['label']);
        $term = trim((string) ($f['q'] ?? ''));
        if ($term !== '') {
            $q->whereRaw("({$titleExpr}) LIKE ?", ['%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%']);
        }
        $dir = ($f['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        ($f['sort'] ?? 'age') === 'address' ? $q->orderByRaw("({$titleExpr}) {$dir}") : $q->orderBy('since', $dir === 'asc' ? 'asc' : 'desc');
        $paginator = $q->paginate(self::PER_PAGE, ['*'], 'page', max(1, (int) ($f['page'] ?? 1)));
        $paginator->getCollection()->transform(fn ($r) => $this->decorate((array) $r, 'owed', (string) $s['label']));

        return ['rows' => $paginator, 'label' => (string) $s['label'], 'heading' => $heading, 'empty' => $empty, 'owed' => true, 'filter' => 'owed', 'filters' => [], 'owed_count' => $this->owedCount($agent), 'kind' => 'owed'];
    }

    // ── counts (today's data) ─────────────────────────────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} count, red */
    private function countFor(string $source, User $agent): array
    {
        [$q] = $this->query($source, $agent, '');
        // the alias `since` is not usable in a WHERE: count over the query as a sub-select
        $sub = fn () => DB::query()->fromSub(clone $q, 't');
        $total = $sub()->count();
        $cut = $this->alertCut($source, $agent);
        $red = $cut === null ? 0 : $sub()->where('since', '<', $cut)->count();

        return [(int) $total, (int) $red];
    }

    private function alertCut(string $source, User $agent): ?string
    {
        return match ($source) {
            'claims_open', 'claims_owner' => Carbon::now()->subDays((int) config('road-to-sold-belt.claim_alert_days', 7))->toDateTimeString(),
            'presentations_open' => Carbon::now()->subDays((int) config('road-to-sold-belt.presentation_alert_days', 30))->toDateTimeString(),
            'properties_draft' => Carbon::now()->subDays(SettingsService::forUser($agent)->prospectStallDays())->toDateTimeString(),
            default => null,
        };
    }

    private function owedCount(User $agent): int
    {
        return (int) $this->owedQueryFor((int) ($agent->effectiveAgencyId() ?: 0), [(int) $agent->id])->count();
    }

    // ── the queries: one builder per source, columns id, title, suburb, owner, since (+ extras) ───────────────

    /**
     * @return array{0:Builder,1:string,2:string,3:string} builder, the SQL expression of the title (for search / sort), heading, empty text
     */
    private function query(string $source, User $agent, string $label): array
    {
        return $this->queryFor($source, (int) ($agent->effectiveAgencyId() ?: 0), [(int) $agent->id], $label);
    }

    /**
     * The same builders for ONE agent or for a group of agents (the compare popover, 23.12): `$ids` is who the figure is about.
     *
     * @param  list<int>  $ids
     * @return array{0:Builder,1:string,2:string,3:string}
     */
    private function queryFor(string $source, int $agencyId, array $ids, string $label): array
    {
        $addr = "TRIM(COALESCE(NULLIF(l.address, ''), CONCAT_WS(' ', tp.street_number, tp.street_name), tp.address_raw, ''))";
        $ownerSql = self::OWNER_NAME_SQL;

        switch ($source) {
            case 'claims_open':
            case 'claims_owner':
                $q = DB::table('prospecting_claims as c')
                    ->join('prospecting_listings as l', 'l.id', '=', 'c.prospecting_listing_id')
                    ->leftJoin('tracked_properties as tp', 'tp.id', '=', 'l.tracked_property_id')
                    ->leftJoin('contacts as o', 'o.id', '=', 'tp.owner_contact_id')
                    ->where('c.agency_id', $agencyId)->whereIn('c.user_id', $ids)->where('c.is_active', 1)->whereNull('c.deleted_at')
                    ->selectRaw("c.id as id, {$addr} as title, COALESCE(l.suburb, tp.suburb) as suburb, {$ownerSql} as owner, COALESCE(c.last_updated_at, c.claimed_at) as since, l.portal_ref as ref, c.pitched_at as pitched_at");
                if ($source === 'claims_open') {
                    $q->whereNull('tp.owner_contact_id');

                    return [$q, $addr, 'Claimed - owner not found yet', 'No open claims without an owner. Claim a property in Market Intelligence and it appears here.'];
                }
                $q->whereNotNull('tp.owner_contact_id')
                    ->whereNotExists(fn ($e) => $e->from('rts_contact_outcomes as oc')->whereColumn('oc.tracked_property_id', 'l.tracked_property_id')->whereIn('oc.user_id', $ids)->whereNull('oc.deleted_at'));

                return [$q, $addr, 'Owner found - not contacted yet', 'No claimed property with an owner waiting for a first contact.'];

            case 'presentations_open':
                $title = "TRIM(COALESCE(NULLIF(p.property_address, ''), NULLIF(p.title, ''), 'Presentation'))";
                $q = DB::table('presentations as p')
                    ->where('p.agency_id', $agencyId)->whereIn('p.created_by_user_id', $ids)->whereNull('p.deleted_at')
                    ->whereNotExists(fn ($e) => $e->from('presentation_outcomes as po')->whereColumn('po.presentation_id', 'p.id'))
                    ->selectRaw("p.id as id, {$title} as title, p.suburb as suburb, NULL as owner, p.created_at as since");

                return [$q, $title, 'Presentations with no answer yet', 'No presentation is waiting for an answer.'];

            case 'owed':
                $title = "TRIM(COALESCE(NULLIF(e.title, ''), 'Evaluation'))";

                return [$this->owedQueryFor($agencyId, $ids)->selectRaw("e.id as id, {$title} as title, NULL as suburb, NULL as owner, e.event_date as since, e.property_id as property_id"), $title,
                    'Evaluations that took place - have you given feedback?', 'Nothing is waiting for feedback. Well done.'];

            case 'properties_draft':
            case 'properties_active':
            case 'properties_live':
                $title = "TRIM(COALESCE(NULLIF(pr.address, ''), CONCAT_WS(' ', pr.street_number, pr.street_name)))";
                $q = DB::table('properties as pr')->where('pr.agency_id', $agencyId)->whereIn('pr.agent_id', $ids)->whereNull('pr.deleted_at')
                    ->selectRaw("pr.id as id, {$title} as title, pr.suburb as suburb, NULL as owner, pr.updated_at as since, pr.price as price");
                $live = Property::query()->withoutGlobalScopes()->liveOnAnyPortal()->where('agency_id', $agencyId)->whereIn('agent_id', $ids)->select('id');
                if ($source === 'properties_draft') {
                    $q->where('pr.status', 'draft');

                    return [$q, $title, 'Draft - getting it ready', 'None of your properties is in Draft.'];
                }
                $q->whereIn('pr.status', ['active', 'for_sale']);
                if ($source === 'properties_active') {
                    $q->whereNotIn('pr.id', $live->toBase());

                    return [$q, $title, 'Active - not on a portal yet', 'Nothing active is waiting to go on a portal.'];
                }
                $q->whereIn('pr.id', $live->toBase());

                return [$q, $title, 'Advertised - live on a portal', 'None of your properties is live on a portal yet.'];

            case 'prospects':
            default:
                $q = DB::table('rts_prospects as rp')->join('tracked_properties as tp', 'tp.id', '=', 'rp.tracked_property_id')
                    ->leftJoin('contacts as o', fn ($j) => $j->on('o.id', '=', 'tp.owner_contact_id')->whereNull('o.deleted_at'))
                    ->where('rp.agency_id', $agencyId)->whereIn('rp.user_id', $ids)->whereNull('rp.deleted_at')->whereNull('rp.closed_at')->whereNull('rp.released_at')->whereNull('rp.parked_at')
                    ->selectRaw("rp.id as id, TRIM(COALESCE(NULLIF(tp.address_raw, ''), CONCAT_WS(' ', tp.street_number, tp.street_name))) as title, tp.suburb as suburb, " . self::OWNER_NAME_SQL . " as owner, rp.stage_entered_at as since, rp.stage as stage, rp.next_action_label as next_label, rp.next_action_at as next_at" . ConveyorService::appointmentSql());

                return [$q, "TRIM(COALESCE(NULLIF(tp.address_raw, ''), CONCAT_WS(' ', tp.street_number, tp.street_name)))", $label, 'Nothing at this station.'];
        }
    }

    /** Evaluations whose time has passed with no feedback captured (23.10 "Owed"). */
    private function owedQueryFor(int $agencyId, array $ids): Builder
    {
        return DB::table('calendar_events as e')
            ->where('e.agency_id', $agencyId)->whereIn('e.user_id', $ids)->whereNull('e.deleted_at')
            ->whereIn('e.category', ['property_evaluation', 'listing_presentation'])
            ->whereNotIn('e.status', ['dismissed', 'cancelled'])
            ->where('e.event_date', '<', Carbon::now()->toDateTimeString())
            ->whereNotExists(fn ($f) => $f->from('calendar_event_feedback as cf')->whereColumn('cf.calendar_event_id', 'e.id')->whereNotNull('cf.captured_at')->whereNull('cf.deleted_at'));
    }

    private function listItems(User $agent, Carbon $day, string $track)
    {
        return RtsListItem::query()->withoutGlobalScopes()->where('rts_list_items.agency_id', (int) ($agent->effectiveAgencyId() ?: 0))
            ->where('rts_list_items.user_id', $agent->id)->where('rts_list_items.track', $track)
            ->whereDate('rts_list_items.list_date', $day->toDateString())->whereNull('rts_list_items.deleted_at')
            // a swapped-out row left the day (23.14: a swap replaces, it never adds): every count reads the same rows the page does
            ->where('rts_list_items.status', '<>', RtsListItem::STATUS_SWAPPED);
    }

    /**
     * "Evaluation booked · 3 Nov 10:00 · Evaluation feedback": the station, when the next step falls due (a time only when one was set) and what it is.
     *
     * @param array<string,mixed> $r
     */
    private function whereLine(array $r, string $source, string $stationLabel): string
    {
        $parts = [];
        $stage = (string) ($r['stage'] ?? '');
        // the station's own name (the belt calls it "Evaluation booked"; the stored stage key reads "Appointment set")
        $parts[] = $stationLabel !== '' ? $stationLabel : ($stage !== '' && ProspectStages::has($stage) ? ProspectStages::label($stage) : '');
        // a booked evaluation is when the appointment is (with its time); anything else is when the next step falls due
        $when = ($stage === ProspectStages::APPOINTMENT_SET && ! empty($r['appt_at'])) ? $r['appt_at'] : ($r['next_at'] ?? null);
        if (! empty($when)) {
            $at = Carbon::parse($when);
            $parts[] = $at->format('j M') . ($at->format('H:i') !== '00:00' ? ' ' . $at->format('H:i') : '');
        }
        if (! empty($r['next_label'])) {
            $parts[] = (string) $r['next_label'];
        } elseif ($stage !== '' && ProspectStages::has($stage) && (string) ProspectStages::defaultNext($stage) !== '') {
            $parts[] = (string) ProspectStages::defaultNext($stage);   // the stage's own standard next step when none was set on the record
        } elseif ($source === 'claims_open') {
            $parts[] = 'Find the owner';
        } elseif ($source === 'claims_owner') {
            $parts[] = 'Make the first contact';
        } elseif ($source === 'presentations_open') {
            $parts[] = 'Waiting for an answer';
        } elseif ($source === 'owed') {
            $parts[] = 'Feedback owed';
        }

        return implode(' · ', array_filter($parts, fn ($p) => $p !== ''));
    }

    /** @param array<string,mixed> $r */
    private function decorate(array $r, string $source, string $stationLabel = ''): array
    {
        $since = ! empty($r['since']) ? Carbon::parse($r['since']) : null;
        $r['since_label'] = $since?->format('j M Y');
        $r['days'] = $since ? (int) $since->diffInDays(now()) : null;
        $r['url'] = match ($source) {
            'claims_open', 'claims_owner' => route('market-intelligence.work', ['search' => $r['ref'] ?? '']),
            'presentations_open' => route('presentations.show', $r['id']),
            'owed' => route('command-center.calendar', ['capture_feedback' => $r['id']]),
            'properties_draft', 'properties_active', 'properties_live' => route('corex.properties.show', $r['id']),
            default => route('road-to-sold.index', ['block' => 'prospects']),
        };
        // "where it is and what is next" - the record's own station and next action, never the list item's open / closed flag
        $r['where'] = $this->whereLine($r, $source, $stationLabel);
        $r['title'] = $r['title'] !== null && trim((string) $r['title']) !== '' ? $r['title'] : 'Address not captured';

        return $r;
    }
}
