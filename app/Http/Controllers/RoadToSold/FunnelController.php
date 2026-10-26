<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RoadToSold\RtsTask;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\RoadToSold\AgentLevelService;
use App\Services\RoadToSold\FunnelService;
use App\Services\RoadToSold\ManagerActionService;
use App\Services\RoadToSold\Scope;
use App\Services\RoadToSold\SettingsService;
use App\Services\RoadToSold\SuggestionService;
use App\Services\RoadToSold\TaskTargetService;
use App\Services\RoadToSold\TrainingPointerService;
use App\Services\RoadToSold\Track;
use App\Services\RoadToSold\VisibleAgents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * AT-454 v2 phase 5 - the team funnel, the training flags and the suggested targets (road-to-sold.md sections 9, 10.2, 10.4).
 *
 * Gate: `road_to_sold.view_funnel` on every route. Breadth is the viewer's `road_to_sold.view` (branch manager = their branch, admin =
 * the agency) through VisibleAgents, applied before anything is drawn; an agent outside it is a 404 on every action. The agency median
 * that flags are measured against is taken over the WHOLE agency (never just the viewer's branch), so a branch manager and an admin
 * see the same flag for the same agent. Nothing here writes a target on its own: a suggestion is accepted, edited or ignored by a person.
 */
class FunnelController extends Controller
{
    private const PAGE = 25;
    private const AGENTS_PER_PAGE = 12;

    public const DONE_BY = ['' => 'Agent total', 'agent' => 'Done by the agent', 'assistant' => 'Done by an assistant'];

    public function __construct(
        private readonly FunnelService $funnel,
        private readonly ManagerActionService $actions,
        private readonly TrainingPointerService $pointers,
        private readonly SuggestionService $suggestions,
        private readonly TaskTargetService $targets,
        private readonly AgentLevelService $levels,
    ) {
    }

    // ── the grid ─────────────────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $c = $this->context($request);
        $canBranches = Scope::level($c['viewer']) === 'all';
        $view = match ($request->query('view')) {
            'figures' => 'figures',
            'branches' => $canBranches ? 'branches' : 'ratios',
            default => 'ratios',
        };
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $sort = in_array($request->query('sort'), ['flag', 'agent', 'branch'], true) ? $request->query('sort') : 'flag';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $onlyFlagged = $request->boolean('flagged');

        $rows = $c['visible']->filter(function (User $a) use ($q, $c, $onlyFlagged, $request) {
            if ($q !== '' && ! str_contains(mb_strtolower((string) $a->name), $q)) {
                return false;
            }
            if ($request->filled('branch') && Scope::level($c['viewer']) === 'all' && (int) $a->branch_id !== (int) $request->query('branch')) {
                return false;
            }

            return ! $onlyFlagged || ! empty($c['flags'][$a->id]);
        })->values();

        $worst = fn (User $a) => collect($c['flags'][$a->id] ?? [])->max('severity') ?? 0;
        $rows = match ($sort) {
            'agent'  => $rows->sortBy(fn (User $a) => mb_strtolower((string) $a->name), SORT_REGULAR, $dir === 'desc'),
            'branch' => $rows->sortBy(fn (User $a) => mb_strtolower((string) $a->branch?->name) . '|' . mb_strtolower((string) $a->name), SORT_REGULAR, $dir === 'desc'),
            default  => $rows->sortBy(fn (User $a) => [-$worst($a), mb_strtolower((string) $a->name)], SORT_REGULAR, $dir === 'desc'),
        };
        $rows = $rows->values();

        // The agency admin's roll-up by branch (spec 10.3): each branch's figures added up over its agents in this view, with its own ratios.
        $byBranch = [];
        if ($view === 'branches') {
            foreach ($rows->groupBy(fn (User $a) => (int) $a->branch_id) as $branchId => $group) {
                $sum = $this->funnel->sum($group->mapWithKeys(fn (User $a) => [$a->id => $c['computed']['counts'][$a->id] ?? []])->all());
                $byBranch[] = [
                    'name' => $group->first()->branch?->name ?? 'No branch', 'agents' => $group->count(), 'flagged' => $group->filter(fn (User $a) => ! empty($c['flags'][$a->id]))->count(),
                    'counts' => $sum, 'ratios' => $this->funnel->ratiosOf($sum),
                ];
            }
            usort($byBranch, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }

        $team = $this->funnel->sum($rows->mapWithKeys(fn (User $a) => [$a->id => $c['computed']['counts'][$a->id] ?? []])->all());
        $page = max(1, (int) $request->query('page', 1));
        $paged = new LengthAwarePaginator($rows->forPage($page, self::PAGE)->values(), $rows->count(), self::PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('road-to-sold.funnel.index', $c + [
            'rows' => $paged, 'view' => $view, 'team' => $team, 'teamRatios' => $this->funnel->ratiosOf($team), 'byBranch' => $byBranch, 'canBranches' => $canBranches,
            'filters' => ['q' => $request->query('q', ''), 'sort' => $sort, 'dir' => $dir, 'flagged' => $onlyFlagged, 'branch' => $request->query('branch')],
            'branches' => Scope::level($c['viewer']) === 'all' ? Branch::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'tab' => 'grid',
        ]);
    }

    // ── the flags ────────────────────────────────────────────────────────────────────────────

    public function flags(Request $request): View
    {
        $c = $this->context($request, true);
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $status = in_array($request->query('status'), ['open', 'acted', 'all'], true) ? $request->query('status') : 'open';
        $sort = in_array($request->query('sort'), ['severity', 'agent', 'skill'], true) ? $request->query('sort') : 'severity';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $step = (string) $request->query('step', '');

        $acted = $this->actions->actedSince((int) $c['agencyId'], $c['visible']->keys()->all(), $c['track'], $c['period']->start);
        $pointers = $this->pointers->forAgency((int) $c['agencyId'], $c['track']);

        $list = [];
        foreach ($c['visible'] as $agent) {
            foreach ($c['flags'][$agent->id] ?? [] as $ratioKey => $flag) {
                [$label, $stepKey, , , $skill] = FunnelService::RATIOS[$ratioKey];
                $action = $acted[$agent->id][$ratioKey] ?? null;
                if ($q !== '' && ! str_contains(mb_strtolower($agent->name . ' ' . $skill . ' ' . $label), $q)) {
                    continue;
                }
                if ($step !== '' && $stepKey !== $step) {
                    continue;
                }
                if (($status === 'open' && $action) || ($status === 'acted' && ! $action)) {
                    continue;
                }
                $pointer = $pointers->get($ratioKey);
                $list[] = ['agent' => $agent, 'ratio' => $ratioKey, 'label' => $label, 'step' => $stepKey, 'skill' => $skill, 'flag' => $flag, 'action' => $action,
                    'training' => $pointer ? $this->pointers->resolve($pointer) : null, 'has_pointer' => (bool) $pointer];
            }
        }
        usort($list, function ($a, $b) use ($sort, $dir) {
            $cmp = match ($sort) {
                'agent' => strcasecmp($a['agent']->name, $b['agent']->name),
                'skill' => strcasecmp($a['skill'], $b['skill']),
                default => $a['flag']['severity'] <=> $b['flag']['severity'],
            };

            return $dir === 'desc' ? -$cmp : $cmp;
        });
        $page = max(1, (int) $request->query('page', 1));
        $items = collect($list);
        $paged = new LengthAwarePaginator($items->forPage($page, self::PAGE)->values(), $items->count(), self::PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('road-to-sold.funnel.flags', $c + [
            'rows' => $paged, 'filters' => ['q' => $request->query('q', ''), 'status' => $status, 'sort' => $sort, 'dir' => $dir, 'step' => $step],
            'steps' => collect(FunnelService::RATIOS)->mapWithKeys(fn ($r) => [$r[1] => \App\Services\RoadToSold\RecipeCatalogue::STEPS[$r[1]][1] ?? $r[1]])->all(),
            'canAssign' => true, 'tab' => 'flags',
            'qualifying' => collect($c['computed']['ratios'])->filter(fn ($r) => collect($r)->contains(fn ($x) => $x['den'] >= $c['settings']->flagMinSample()))->count(),
        ]);
    }

    public function assign(Request $request): RedirectResponse
    {
        $data = $request->validate(['agent_id' => ['required', 'integer'], 'ratio_key' => ['required', 'string']]);
        $this->actions->assignTraining($request->user(), $this->agent((int) $data['agent_id']), $data['ratio_key'], Track::fromRequest($request));

        return back()->with('status', 'Training assigned. A task is on the agent\'s list and they have been told.');
    }

    public function note(Request $request): RedirectResponse
    {
        $data = $request->validate(['agent_id' => ['required', 'integer'], 'ratio_key' => ['required', 'string'], 'note' => ['nullable', 'string']]);
        $this->actions->coachingNote($request->user(), $this->agent((int) $data['agent_id']), $data['ratio_key'], $data['note'] ?? null, Track::fromRequest($request));

        return back()->with('status', 'Coaching note saved.');
    }

    public function acknowledge(Request $request): RedirectResponse
    {
        $data = $request->validate(['agent_id' => ['required', 'integer'], 'ratio_key' => ['required', 'string']]);
        $this->actions->acknowledge($request->user(), $this->agent((int) $data['agent_id']), $data['ratio_key'], Track::fromRequest($request));

        return back()->with('status', 'Marked as seen.');
    }

    // ── suggested targets ────────────────────────────────────────────────────────────────────

    public function suggestions(Request $request): View
    {
        $viewer = $request->user();
        $track = Track::fromRequest($request);
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        $settings = SettingsService::forAgency($agencyId, $track);
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $show = $request->query('show') === 'all' ? 'all' : 'changes';
        $sort = $request->query('sort') === 'gap' ? 'gap' : 'agent';

        $agents = VisibleAgents::for($viewer)->filter(function (User $a) use ($q, $request, $viewer) {
            if ($q !== '' && ! str_contains(mb_strtolower((string) $a->name), $q)) {
                return false;
            }

            return ! ($request->filled('branch') && Scope::level($viewer) === 'all' && (int) $a->branch_id !== (int) $request->query('branch'));
        })->values();

        $page = max(1, (int) $request->query('page', 1));
        $pageAgents = $agents->forPage($page, self::AGENTS_PER_PAGE)->values();
        $byAgent = $this->suggestions->forAgents($pageAgents, $track);
        $taskQ = mb_strtolower(trim((string) $request->query('task', '')));
        $blocks = [];
        foreach ($pageAgents as $agent) {
            $rows = collect($byAgent[$agent->id] ?? [])->filter(function ($r) use ($show, $taskQ) {
                if ($taskQ !== '' && ! str_contains(mb_strtolower($r['label']), $taskQ)) {
                    return false;
                }

                return $show === 'all' || ($r['change'] && ! $r['ignored']);
            })->values();
            if ($sort === 'gap') {
                $rows = $rows->sortByDesc(fn ($r) => ($r['suggested'] ?? 0) - $r['current'])->values();
            }
            $blocks[] = ['agent' => $agent, 'rows' => $rows, 'level' => $this->levels->levelOf($agent, $track, $agencyId)];
        }
        if ($sort === 'gap') {
            usort($blocks, fn ($a, $b) => $b['rows']->sum(fn ($r) => ($r['suggested'] ?? 0) - $r['current']) <=> $a['rows']->sum(fn ($r) => ($r['suggested'] ?? 0) - $r['current']));
        }
        $paged = new LengthAwarePaginator($blocks, $agents->count(), self::AGENTS_PER_PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('road-to-sold.funnel.suggestions', [
            'viewer' => $viewer, 'track' => $track, 'settings' => $settings, 'blocks' => $paged,
            'filters' => ['q' => $request->query('q', ''), 'task' => $request->query('task', ''), 'show' => $show, 'sort' => $sort, 'branch' => $request->query('branch')],
            'branches' => Scope::level($viewer) === 'all' ? Branch::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'canWrite' => $viewer->hasPermission('road_to_sold.manage_targets'), 'tab' => 'suggestions',
        ]);
    }

    public function accept(Request $request): RedirectResponse
    {
        $data = $request->validate(['agent_id' => ['required', 'integer'], 'task_id' => ['required', 'integer'], 'target' => ['required'], 'suggested' => ['nullable', 'integer']]);
        $viewer = $request->user();
        abort_unless($viewer->hasPermission('road_to_sold.view_funnel'), 403);
        $agent = $this->agent((int) $data['agent_id']);
        $task = RtsTask::query()->where('agency_id', $agent->agency_id)->findOrFail((int) $data['task_id']);
        $level = $this->levels->levelOf($agent, (string) $task->track, (int) $agent->agency_id);
        abort_unless($level, 404);
        $edited = isset($data['suggested']) && (int) $data['suggested'] !== (int) $data['target'];
        $this->targets->set($viewer, $agent, $task, $level, $data['target'], $edited ? 'Edited from the suggested ' . (int) $data['suggested'] : 'Accepted the suggested target');

        return back()->with('status', 'Saved. ' . $agent->name . '\'s target for "' . $task->label . '" is now ' . (int) $data['target'] . '.');
    }

    public function ignore(Request $request): RedirectResponse
    {
        $data = $request->validate(['agent_id' => ['required', 'integer'], 'task_id' => ['required', 'integer'], 'suggested' => ['required', 'integer']]);
        $this->actions->ignoreSuggestion($request->user(), $this->agent((int) $data['agent_id']), (int) $data['task_id'], (int) $data['suggested'], Track::fromRequest($request));

        return back()->with('status', 'Left as it is. This suggestion stays hidden until the figure changes.');
    }

    // ── shared ───────────────────────────────────────────────────────────────────────────────

    /** An agent inside the viewer's breadth, else 404 - the direct-URL check for every action. */
    private function agent(int $id): User
    {
        abort_unless(VisibleAgents::canSee(request()->user(), $id), 404);

        return User::query()->withoutGlobalScopes()->whereKey($id)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function context(Request $request, bool $flagWindow = false): array
    {
        $viewer = $request->user();
        $track = Track::fromRequest($request);
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403, 'Switch into an agency first.');
        $settings = SettingsService::forAgency($agencyId, $track);
        $days = $flagWindow ? $settings->flagWindowDays() : (int) $request->query('window', $settings->flagWindowDays());
        $days = max(7, min(366, $days ?: $settings->flagWindowDays()));
        $doneBy = in_array($request->query('by'), ['agent', 'assistant'], true) ? $request->query('by') : null;
        $period = $this->funnel->windowPeriod($days);

        ['computed' => $computed, 'medians' => $medians, 'flags' => $flags] = $this->funnel->snapshot($agencyId, $period, $track, $settings, $doneBy);

        return [
            'viewer' => $viewer, 'track' => $track, 'agencyId' => $agencyId, 'settings' => $settings, 'days' => $days, 'doneBy' => $doneBy, 'period' => $period,
            'computed' => $computed, 'medians' => $medians, 'flags' => $flags, 'visible' => VisibleAgents::for($viewer),
            'drilldownBase' => route('road-to-sold.drilldown', ['period' => 'custom', 'start' => $period->start->toDateString(), 'end' => $period->end->toDateString()] + Track::params($track)),
        ];
    }
}
