<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsAgentArea;
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
            'mode'     => $mode,
            'warnDays' => $warnDays,
            'tabs'     => SettingsController::tabsFor($viewer, 'areas'),
            'towns'    => $this->areas->towns($agencyId),
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
            $valid = $this->areas->idsInPool($agencyId, $add);
            if (count($valid) !== count($add)) {
                return back()->withErrors(['add' => 'Some of those suburbs are not part of your agency\'s Prospecting Setup, so nothing was added. Add the town in Prospecting Setup first.']);
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
        $poolIds = array_flip($this->areas->idsInPool($agencyId, $suburbIds));
        $captured = $this->areas->lastCaptured($agencyId, array_values(array_intersect($suburbIds, array_keys($poolIds))));

        $townFilterCity = $townId > 0 ? (int) DB::table('towns')->where('id', $townId)->where('agency_id', $agencyId)->value('p24_city_id') : 0;

        $byUser = $areaRows->groupBy('user_id');
        $rows = $agents->map(function (User $u) use ($byUser, $meta, $poolIds, $captured, $warnDays) {
            $chips = ($byUser->get($u->id) ?? collect())->map(function (RtsAgentArea $a) use ($meta, $poolIds, $captured, $warnDays) {
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
                    'stale_portals' => $stale = (isset($poolIds[(int) $a->p24_suburb_id]) ? $this->areas->portalsNeedingRefresh($cap, $warnDays) : []),
                    'needs_refresh' => $stale !== [],
                ];
            })->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();

            // The stalest portal of the stalest suburb (a portal never captured counts as 0).
            $oldest = $chips->flatMap(fn ($c) => [$c['p24']?->timestamp ?? 0, $c['pp']?->timestamp ?? 0])->min();

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
            $pickerCity = $pickerTown > 0 ? (int) DB::table('towns')->where('id', $pickerTown)->where('agency_id', $agencyId)->value('p24_city_id') : 0;
            $already = RtsAgentArea::query()->where('track', $this->track())->where('user_id', $addFor)->pluck('p24_suburb_id')->all();
            $pq = $this->areas->poolQuery($agencyId)
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
        $q        = trim((string) $request->query('q', ''));
        $townId   = (int) $request->query('town_id', 0);
        $unassigned = $request->boolean('unassigned');
        $staleOnly  = $request->boolean('stale');
        $sort     = in_array($request->query('sort'), ['name', 'stale', 'agents'], true) ? $request->query('sort') : 'name';

        $townCity = $townId > 0 ? (int) DB::table('towns')->where('id', $townId)->where('agency_id', $agencyId)->value('p24_city_id') : 0;
        $pool = $this->areas->poolQuery($agencyId)
            ->when($townCity > 0, fn ($b) => $b->where('s.p24_city_id', $townCity))
            ->orderBy('s.name')->get();

        $agents = VisibleAgents::for($viewer);
        $assigned = RtsAgentArea::query()->visibleTo($viewer)->where('track', $this->track())
            ->whereIn('user_id', $agents->keys()->all())->get()->groupBy('p24_suburb_id');
        $captured = $this->areas->lastCaptured($agencyId);

        $rows = $pool->map(function ($s) use ($assigned, $captured, $agents, $warnDays) {
            $cap  = $captured[(int) $s->id] ?? ['p24' => null, 'pp' => null];
            $who  = ($assigned->get((int) $s->id) ?? collect())->map(fn ($a) => $agents->get($a->user_id)?->name)->filter()->sort()->values();

            return [
                'id' => (int) $s->id, 'name' => $s->name, 'town' => $s->town_name,
                'agents' => $who, 'p24' => $cap['p24'], 'pp' => $cap['pp'],
                'stale_portals' => $stale = $this->areas->portalsNeedingRefresh($cap, $warnDays),
                'needs_refresh' => $stale !== [],
                'newest' => min($cap['p24']?->timestamp ?? 0, $cap['pp']?->timestamp ?? 0),
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

        $rows = match ($sort) {
            'stale'  => $rows->sortBy('newest'),
            'agents' => $rows->sortByDesc(fn ($r) => $r['agents']->count()),
            default  => $rows->sortBy(fn ($r) => mb_strtolower($r['name'])),
        };

        $page = $this->paginate($rows->values(), self::SUBURB_PAGE_SIZE, $request);

        return [
            'suburbRows' => $page, 'rows' => null, 'picker' => null,
            'filters' => ['q' => $q, 'status' => 'active', 'branchId' => 0, 'townId' => $townId, 'noAreas' => false,
                'staleOnly' => $staleOnly, 'sort' => $sort, 'unassigned' => $unassigned],
            'emptyFiltered' => $page->total() === 0 && ($q !== '' || $townId > 0 || $unassigned || $staleOnly),
        ];
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @param int[] $ids @return array<int,array{name:string,city_id:?int,town:?string}> */
    private function suburbMeta(int $agencyId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $towns = DB::table('towns')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNotNull('p24_city_id')
            ->orderBy('display_order')->orderBy('id')->get(['name', 'p24_city_id'])->unique('p24_city_id')->pluck('name', 'p24_city_id');

        $out = [];
        foreach (DB::table('p24_suburbs')->whereIn('id', $ids)->get(['id', 'name', 'p24_city_id']) as $s) {
            $out[(int) $s->id] = ['name' => (string) $s->name, 'city_id' => $s->p24_city_id ? (int) $s->p24_city_id : null,
                'town' => $towns->get($s->p24_city_id)];
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
