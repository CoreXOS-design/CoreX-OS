<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsAgentArea;
use App\Models\RoadToSold\RtsHiddenArea;
use App\Models\RoadToSold\RtsSetting;
use App\Models\User;
use App\Services\RoadToSold\AreaService;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\Support\Routing;
use App\Services\RoadToSold\VisibleAgents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * AT-454 — which suburbs each agent works (.ai/specs/road-to-sold.md §5.2).
 *
 * Two views of the same assignments: agents x suburbs (default) and suburbs x agents
 * (with "last captured" dates). Every list narrows through VisibleAgents / scopeVisibleTo;
 * every {user} / {area} route re-checks the viewer's breadth and answers 404 outside it.
 */
class AreasController extends Controller
{
    use EnforcesRecordVisibility;

    private const PAGE_SIZE = 25;
    private const SUBURB_PAGE_SIZE = 50;
    private const PICKER_LIMIT = 100;

    /** Sales or rentals: decided by where these screens are mounted (see Support\Routing). */
    private function track(): string
    {
        return Routing::track();
    }

    public function __construct(private readonly AreaService $areas)
    {
    }

    public function index(Request $request): View|RedirectResponse
    {
        $viewer   = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        if ($agencyId <= 0) {
            return redirect()->route('corex.dashboard')->with('error', 'Switch into an agency first - areas belong to one agency.');
        }

        $warnDays = SettingsService::forAgency($agencyId, $this->track())->areaRefreshWarnDays();
        $mode     = $request->query('view') === 'suburbs' ? 'suburbs' : 'agents';

        $data = $mode === 'suburbs'
            ? $this->suburbsView($request, $viewer, $agencyId, $warnDays)
            : $this->agentsView($request, $viewer, $agencyId, $warnDays);

        return view('road-to-sold.settings.areas', $data + [
            // Johan, 13 Oct 2026: the working areas are derived from stock + Market Intelligence; this is what an empty agency is told.
            'poolEmpty'   => ! $this->areas->poolQuery($agencyId, $this->track())->exists(),
            'hiddenCount' => $this->areas->hiddenCount($agencyId, $this->track()),
            'canHide'     => $viewer->hasPermission('road_to_sold.manage_settings'),
            'minArea'     => SettingsService::forAgency($agencyId, $this->track())->minAreaProperties(),
            'micUrl'      => url('/corex/market-intelligence'),
            'autoImportUrl' => $viewer->hasPermission('access_settings') ? route('admin.minion.setup') : null,
            'mode'     => $mode,
            'warnDays' => $warnDays,
            'portals'  => SettingsService::forAgency($agencyId, $this->track())->refreshPortals(),
            'tabs'     => SettingsController::tabsFor($viewer, 'areas'),
            'towns'    => $this->areas->towns($agencyId, $this->track()),
            'branches' => VisibleAgents::for($viewer)->pluck('branch.name', 'branch_id')->filter()->sort(),
            'lockOn'   => SettingsService::forAgency($agencyId, $this->track())->lockAgentsToAreas(),
        ]);
    }

    /**
     * Add and/or remove suburbs for one agent in a single save.
     */
    public function sync(Request $request, int $user): RedirectResponse
    {
        $viewer   = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);

        $agent = VisibleAgents::for($viewer)->get($user);
        abort_unless($agent && $agencyId > 0, 404);

        $data = $request->validate([
            'add'      => ['sometimes', 'array', 'max:500'],
            'add.*'    => ['integer', 'min:1'],
            'remove'   => ['sometimes', 'array', 'max:500'],
            'remove.*' => ['integer', 'min:1'],
        ], [
            'add.*.integer'    => 'One of the suburbs you picked is not valid.',
            'remove.*.integer' => 'One of the suburbs you picked is not valid.',
        ]);

        $add    = array_values(array_unique(array_map('intval', $data['add'] ?? [])));
        $remove = array_values(array_unique(array_map('intval', $data['remove'] ?? [])));

        if ($add !== []) {
            $valid = $this->areas->idsInPool($agencyId, $add, $this->track());
            if (count($valid) !== count($add)) {
                return back()->withErrors(['add' => 'Some of those suburbs are not in your stock or Market Intelligence data (or have been hidden), so nothing was added.']);
            }
        }

        $added = 0;
        $removed = 0;
        DB::transaction(function () use ($add, $remove, $agencyId, $agent, $viewer, &$added, &$removed) {
            foreach ($add as $suburbId) {
                $row = RtsAgentArea::withTrashed()
                    ->where('agency_id', $agencyId)->where('track', $this->track())
                    ->where('user_id', $agent->id)->where('p24_suburb_id', $suburbId)->first();

                if ($row && ! $row->trashed()) {
                    continue; // already assigned - nothing to do
                }
                if ($row) {
                    $row->restore();
                    $row->forceFill(['branch_id' => $agent->branch_id, 'assigned_by_user_id' => $viewer->id])->save();
                } else {
                    RtsAgentArea::create([
                        'agency_id' => $agencyId, 'branch_id' => $agent->branch_id, 'track' => $this->track(),
                        'user_id' => $agent->id, 'p24_suburb_id' => $suburbId, 'assigned_by_user_id' => $viewer->id,
                    ]);
                }
                $added++;
            }
            foreach ($remove as $suburbId) {
                $removed += RtsAgentArea::query()
                    ->where('agency_id', $agencyId)->where('track', $this->track())
                    ->where('user_id', $agent->id)->where('p24_suburb_id', $suburbId)
                    ->get()->each->delete()->count();
            }
        });

        $msg = $added === 0 && $removed === 0
            ? 'Nothing changed - those suburbs were already set.'
            : trim(($added ? "Added {$added} suburb" . ($added === 1 ? '' : 's') . ' to ' . $agent->name . '. ' : '')
                . ($removed ? "Removed {$removed}." : ''));

        return redirect()->route(Routing::base() . 'areas.index', array_filter($request->only(['view', 'q', 'status', 'branch_id', 'town_id', 'sort'])))
            ->with('success', $msg);
    }

    public function archive(Request $request, RtsAgentArea $area): RedirectResponse
    {
        $this->assertVisible($request, $area);
        abort_unless(VisibleAgents::canSee($request->user(), (int) $area->user_id), 404);

        $area->delete();

        return back()->with('success', 'Suburb removed from the agent. You can bring it back from the "Removed" list.');
    }

    public function restore(Request $request, RtsAgentArea $area): RedirectResponse
    {
        $this->assertVisible($request, $area);
        abort_unless(VisibleAgents::canSee($request->user(), (int) $area->user_id), 404);

        if ($area->trashed()) {
            $area->restore();
        }

        return back()->with('success', 'Suburb given back to the agent.');
    }

    // ── agents x suburbs ──────────────────────────────────────────────────

    private function agentsView(Request $request, User $viewer, int $agencyId, int $warnDays): array
    {
        $portals = SettingsService::forAgency($agencyId, $this->track())->refreshPortals();
        $agents   = VisibleAgents::for($viewer);
        $status   = $request->query('status') === 'removed' ? 'removed' : 'active';
        $q        = trim((string) $request->query('q', ''));
        $branchId = (int) $request->query('branch_id', 0);
        $townId   = (int) $request->query('town_id', 0);
        $noAreas  = $request->boolean('no_areas');
        $staleOnly = $request->boolean('stale');
        $sort     = in_array($request->query('sort'), ['name', 'branch', 'areas', 'oldest'], true) ? $request->query('sort') : 'name';

        $areaQuery = RtsAgentArea::query()->visibleTo($viewer)
            ->where('track', $this->track())->whereIn('user_id', $agents->keys()->all());
        if ($status === 'removed') {
            $areaQuery->onlyTrashed();
        }
        $areaRows = $areaQuery->get();

        $suburbIds = $areaRows->pluck('p24_suburb_id')->unique()->values()->all();
        $meta = $this->suburbMeta($agencyId, $suburbIds);
        $poolIds = array_flip($this->areas->idsInPool($agencyId, $suburbIds, $this->track()));
        $captured = $this->areas->lastCaptured($agencyId, array_values(array_intersect($suburbIds, array_keys($poolIds))), $this->track());

        $townFilterCity = max(0, $townId); // a town is its Property24 town id

        $byUser = $areaRows->groupBy('user_id');
        $rows = $agents->map(function (User $u) use ($byUser, $meta, $poolIds, $captured, $warnDays, $portals) {
            $chips = ($byUser->get($u->id) ?? collect())->map(function (RtsAgentArea $a) use ($meta, $poolIds, $captured, $warnDays, $portals) {
                $m   = $meta[(int) $a->p24_suburb_id] ?? null;
                $cap = $captured[(int) $a->p24_suburb_id] ?? ['p24' => null, 'pp' => null];

                return [
                    'area_id'  => $a->id,
                    'suburb_id' => (int) $a->p24_suburb_id,
                    'name'     => $m['name'] ?? 'Unknown suburb',
                    'town'     => $m['town'] ?? null,
                    'city_id'  => $m['city_id'] ?? null,
                    'in_pool'  => isset($poolIds[(int) $a->p24_suburb_id]),
                    'p24'      => $cap['p24'],
                    'pp'       => $cap['pp'],
                    'stale_portals' => $stale = (isset($poolIds[(int) $a->p24_suburb_id]) ? $this->areas->portalsNeedingRefresh($cap, $warnDays, $portals) : []),
                    'needs_refresh' => $stale !== [],
                ];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

            // The stalest portal of the stalest suburb (a portal never captured counts as 0).
            $oldest = $chips->flatMap(fn ($c) => collect($portals)->map(fn ($p) => $c[$p]?->timestamp ?? 0))->min();

            return ['user' => $u, 'chips' => $chips, 'count' => $chips->count(), 'oldest' => $oldest ?? PHP_INT_MAX];
        })->values();

        if ($status === 'active' && $noAreas) {
            $rows = $rows->filter(fn ($r) => $r['count'] === 0);
        }
        if ($branchId > 0) {
            $rows = $rows->filter(fn ($r) => (int) $r['user']->branch_id === $branchId);
        }
        if ($townFilterCity > 0) {
            $rows = $rows->filter(fn ($r) => $r['chips']->contains(fn ($c) => (int) $c['city_id'] === $townFilterCity));
        }
        if ($staleOnly) {
            $rows = $rows->filter(fn ($r) => $r['chips']->contains(fn ($c) => $c['needs_refresh']));
        }
        if ($status === 'removed') {
            $rows = $rows->filter(fn ($r) => $r['count'] > 0);
        }
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(function ($r) use ($needle) {
                return str_contains(mb_strtolower((string) $r['user']->name), $needle)
                    || str_contains(mb_strtolower((string) $r['user']->email), $needle)
                    || $r['chips']->contains(fn ($c) => str_contains(mb_strtolower($c['name']), $needle)
                        || str_contains(mb_strtolower((string) $c['town']), $needle));
            });
        }

        $rows = match ($sort) {
            'branch' => $rows->sortBy(fn ($r) => mb_strtolower((string) ($r['user']->branch?->name ?? '')) . '|' . mb_strtolower((string) $r['user']->name)),
            'areas'  => $rows->sortByDesc('count'),
            'oldest' => $rows->sortBy('oldest'),
            default  => $rows->sortBy(fn ($r) => mb_strtolower((string) $r['user']->name)),
        };

        $page = $this->paginate($rows->values(), self::PAGE_SIZE, $request);

        // The "add suburbs" picker for one chosen agent (server-rendered; no JSON endpoint).
        $picker = null;
        $addFor = (int) $request->query('add_for', 0);
        if ($addFor > 0 && $agents->has($addFor)) {
            $suburbQ = trim((string) $request->query('suburb_q', ''));
            $pickerTown = (int) $request->query('picker_town', 0);
            $pickerCity = max(0, $pickerTown);
            $already = RtsAgentArea::query()->where('track', $this->track())->where('user_id', $addFor)->pluck('p24_suburb_id')->all();
            $pq = $this->areas->poolQuery($agencyId, $this->track())
                ->when($suburbQ !== '', fn ($b) => $b->where('s.name', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $suburbQ) . '%'))
                ->when($pickerCity > 0, fn ($b) => $b->where('s.p24_city_id', $pickerCity))
                ->when($already !== [], fn ($b) => $b->whereNotIn('s.id', $already))
                ->orderBy('s.name');
            $found = $pq->limit(self::PICKER_LIMIT + 1)->get();
            $picker = [
                'agent'    => $agents->get($addFor),
                'results'  => $found->take(self::PICKER_LIMIT),
                'overflow' => $found->count() > self::PICKER_LIMIT,
                'suburb_q' => $suburbQ,
                'town'     => $pickerTown,
                'counts'   => $this->areas->derived($agencyId),
            ];
        }

        return [
            'rows' => $page, 'picker' => $picker, 'filters' => compact('q', 'status', 'branchId', 'townId', 'noAreas', 'staleOnly', 'sort'),
            'emptyFiltered' => $page->total() === 0 && ($q !== '' || $branchId > 0 || $townId > 0 || $noAreas || $staleOnly || $status === 'removed'),
            'suburbRows' => null,
        ];
    }

    // ── suburbs x agents ──────────────────────────────────────────────────

    private function suburbsView(Request $request, User $viewer, int $agencyId, int $warnDays): array
    {
        $track    = $this->track();
        $portals  = SettingsService::forAgency($agencyId, $track)->refreshPortals();
        $q        = trim((string) $request->query('q', ''));
        $townId   = max(0, (int) $request->query('town_id', 0)); // a town is its Property24 town id
        $unassigned = $request->boolean('unassigned');
        $staleOnly  = $request->boolean('stale');
        $status   = $request->query('status') === 'hidden' ? 'hidden' : 'active';
        $source   = in_array($request->query('source'), ['stock', 'mic'], true) ? $request->query('source') : 'all';
        $sort     = in_array($request->query('sort'), ['name', 'stale', 'agents', 'stock', 'mic'], true) ? $request->query('sort') : 'name';

        // What an administrator has hidden (suburbs and whole towns), for the "Hidden" view and its Restore buttons.
        $hiddenRows = collect();
        if ($status === 'hidden') {
            $hiddenRows = RtsHiddenArea::query()->where('track', $track)->orderByDesc('id')->get()->map(function (RtsHiddenArea $h) {
                if ($h->kind === RtsHiddenArea::KIND_TOWN) {
                    $name = (string) (DB::table('p24_cities')->where('id', $h->p24_city_id)->value('name') ?? 'Unknown town');

                    return ['id' => $h->id, 'kind' => 'town', 'name' => $name, 'town' => null, 'at' => $h->created_at];
                }
                $s = DB::table('p24_suburbs as s')->leftJoin('p24_cities as c', 'c.id', '=', 's.p24_city_id')->where('s.id', $h->p24_suburb_id)->first(['s.name', DB::raw('c.name as town_name')]);

                return ['id' => $h->id, 'kind' => 'suburb', 'name' => (string) ($s->name ?? 'Unknown suburb'), 'town' => $s->town_name ?? null, 'at' => $h->created_at];
            });
        }

        $derived = $this->areas->derived($agencyId);
        $pool = $status === 'hidden' ? collect() : $this->areas->poolQuery($agencyId, $track)
            ->when($townId > 0, fn ($b) => $b->where('s.p24_city_id', $townId))
            ->orderBy('s.name')->get();

        $agents = VisibleAgents::for($viewer);
        $assigned = RtsAgentArea::query()->visibleTo($viewer)->where('track', $track)
            ->whereIn('user_id', $agents->keys()->all())->get()->groupBy('p24_suburb_id');
        $captured = $status === 'hidden' ? [] : $this->areas->lastCaptured($agencyId, $pool->pluck('id')->map(fn ($i) => (int) $i)->all(), $track);

        $rows = $pool->map(function ($s) use ($assigned, $captured, $agents, $warnDays, $portals, $derived) {
            $cap  = $captured[(int) $s->id] ?? ['p24' => null, 'pp' => null];
            $who  = ($assigned->get((int) $s->id) ?? collect())->map(fn ($a) => $agents->get($a->user_id)?->name)->filter()->sort()->values();
            $d    = $derived[(int) $s->id] ?? ['own' => 0, 'mic' => 0];

            return [
                'id' => (int) $s->id, 'name' => $s->name, 'town' => $s->town_name, 'city_id' => $s->p24_city_id ? (int) $s->p24_city_id : null,
                'own' => (int) $d['own'], 'mic' => (int) $d['mic'],
                'agents' => $who, 'p24' => $cap['p24'], 'pp' => $cap['pp'],
                'stale_portals' => $stale = $this->areas->portalsNeedingRefresh($cap, $warnDays, $portals),
                'needs_refresh' => $stale !== [],
                'newest' => collect($portals)->map(fn ($p) => $cap[$p]?->timestamp ?? 0)->min() ?? 0,
            ];
        });

        if ($q !== '') {
            $needle = mb_strtolower($q);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['name']), $needle)
                || str_contains(mb_strtolower((string) $r['town']), $needle)
                || $r['agents']->contains(fn ($n) => str_contains(mb_strtolower((string) $n), $needle)));
        }
        if ($unassigned) {
            $rows = $rows->filter(fn ($r) => $r['agents']->isEmpty());
        }
        if ($staleOnly) {
            $rows = $rows->filter(fn ($r) => $r['needs_refresh']);
        }
        if ($source === 'stock') {
            $rows = $rows->filter(fn ($r) => $r['own'] > 0);
        } elseif ($source === 'mic') {
            $rows = $rows->filter(fn ($r) => $r['own'] === 0 && $r['mic'] > 0);
        }

        $rows = match ($sort) {
            'stale'  => $rows->sortBy('newest'),
            'agents' => $rows->sortByDesc(fn ($r) => $r['agents']->count()),
            'stock'  => $rows->sortByDesc(fn ($r) => $r['own'] * 1000000 + $r['mic']),
            'mic'    => $rows->sortByDesc(fn ($r) => $r['mic'] * 1000000 + $r['own']),
            default  => $rows->sortBy(fn ($r) => mb_strtolower($r['name'])),
        };

        $page = $this->paginate($rows->values(), self::SUBURB_PAGE_SIZE, $request);
        $townName = $townId > 0 ? ($this->areas->towns($agencyId, $track)->firstWhere('id', $townId)?->name) : null;

        return [
            'suburbRows' => $page, 'rows' => null, 'picker' => null, 'hiddenRows' => $hiddenRows, 'townName' => $townName,
            'filters' => ['q' => $q, 'status' => $status, 'source' => $source, 'branchId' => 0, 'townId' => $townId, 'noAreas' => false,
                'staleOnly' => $staleOnly, 'sort' => $sort, 'unassigned' => $unassigned],
            'emptyFiltered' => $page->total() === 0 && ($q !== '' || $townId > 0 || $unassigned || $staleOnly || $source !== 'all'),
        ];
    }

    // ── hide / show a suburb or a whole town (soft; Road to Sold only) ─────────

    public function hide(Request $request): RedirectResponse
    {
        $viewer   = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_unless($agencyId > 0, 404);

        $v = $request->validate(['kind' => ['required', 'in:suburb,town'], 'id' => ['required', 'integer', 'min:1']]);
        $exists = $v['kind'] === 'town'
            ? DB::table('p24_cities')->where('id', $v['id'])->exists()
            : DB::table('p24_suburbs')->where('id', $v['id'])->whereNull('deleted_at')->exists();
        abort_unless($exists, 404);

        $this->areas->hide($agencyId, $this->track(), $v['kind'], (int) $v['id'], $viewer->id);

        return redirect()->route(Routing::base() . 'areas.index', ['view' => 'suburbs'])
            ->with('success', ($v['kind'] === 'town' ? 'The town' : 'The suburb') . ' is hidden from Road to Sold. Nothing was deleted - choose Hidden under Show to bring it back.');
    }

    public function showAgain(Request $request, int $hidden): RedirectResponse
    {
        $viewer   = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $row = RtsHiddenArea::query()->where('agency_id', $agencyId)->where('track', $this->track())->findOrFail($hidden);
        $row->delete(); // soft: the row stays as the record that it was hidden

        return redirect()->route(Routing::base() . 'areas.index', ['view' => 'suburbs', 'status' => 'hidden'])->with('success', 'It is back in Road to Sold.');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @param int[] $ids @return array<int,array{name:string,city_id:?int,town:?string}> */
    private function suburbMeta(int $agencyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('p24_suburbs as s')->leftJoin('p24_cities as c', 'c.id', '=', 's.p24_city_id')->whereIn('s.id', $ids)->get(['s.id', 's.name', 's.p24_city_id', DB::raw('c.name as town_name')]) as $s) {
            $out[(int) $s->id] = ['name' => (string) $s->name, 'city_id' => $s->p24_city_id ? (int) $s->p24_city_id : null,
                'town' => $s->town_name];
        }

        return $out;
    }

    private function paginate(Collection $items, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('page', 1));

        return (new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        ));
    }
}
