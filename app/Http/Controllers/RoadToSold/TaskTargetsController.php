<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsUserTaskOverride;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\RoadToSold\AgentLevelService;
use App\Services\RoadToSold\Scope;
use App\Services\RoadToSold\Support\Routing;
use App\Services\RoadToSold\TaskTargetService;
use App\Services\RoadToSold\VisibleAgents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

/**
 * AT-454 v2 phase 5 - Road to Sold settings -> Task targets (road-to-sold.md sections 5.2, 10.4 "Task targets per user"): each agent's own
 * target for each task on their level, over the level's. Permission `road_to_sold.manage_targets`; breadth is the viewer's
 * `road_to_sold.view` (branch manager = their branch, admin = the agency) through VisibleAgents; an agent outside it is a 404 on every
 * write. Every write goes through TaskTargetService (audited). Clear archives, restore brings it back.
 */
class TaskTargetsController extends Controller
{
    private const PAGE = 25;

    public function __construct(private readonly TaskTargetService $targets, private readonly AgentLevelService $levels)
    {
    }

    public function index(Request $request): View
    {
        $viewer = $request->user();
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403, 'Switch into an agency first.');
        $track = Routing::track();
        $q = mb_strtolower(trim((string) $request->query('q', '')));
        $show = in_array($request->query('show'), ['own', 'archived'], true) ? $request->query('show') : 'all';
        $sort = in_array($request->query('sort'), ['agent', 'task', 'level'], true) ? $request->query('sort') : 'agent';

        $agents = VisibleAgents::for($viewer)->filter(function (User $a) use ($request, $viewer) {
            return ! ($request->filled('branch') && Scope::level($viewer) === 'all' && (int) $a->branch_id !== (int) $request->query('branch'));
        })->values();

        $steps = RtsStep::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $track)->where('is_enabled', true)->get()->keyBy('id');
        $tasks = RtsTask::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $track)->where('is_active', true)
            ->whereIn('step_id', $steps->keys())->get()->keyBy('id');
        $overrides = RtsUserTaskOverride::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->where('agency_id', $agencyId)->whereIn('user_id', $agents->pluck('id'))->whereNotNull('target')->get()
            ->groupBy(fn ($o) => $o->user_id . ':' . $o->task_id . ':' . $o->level_key);
        $levelTasks = RtsLevelTask::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('target', '>', 0)->get()->groupBy('level_id');

        $rows = [];
        $levelNames = [];
        foreach ($agents as $agent) {
            $level = $this->levels->levelOf($agent, $track, $agencyId);
            if (! $level) {
                continue;
            }
            if ($request->filled('level') && (int) $request->query('level') !== (int) $level->id) {
                continue;
            }
            $levelNames[$level->id] = $level->name;
            foreach ($levelTasks->get($level->id, collect()) as $lt) {
                $task = $tasks->get($lt->task_id);
                if (! $task) {
                    continue;
                }
                $set = $overrides->get($agent->id . ':' . $task->id . ':' . $level->id);
                $live = $set?->first(fn ($o) => ! $o->trashed());
                $gone = $set?->first(fn ($o) => $o->trashed());
                if ($q !== '' && ! str_contains(mb_strtolower($agent->name . ' ' . $task->label), $q)) {
                    continue;
                }
                if (($show === 'own' && ! $live) || ($show === 'archived' && (! $gone || $live))) {
                    continue;
                }
                $rows[] = ['agent' => $agent, 'level' => $level, 'task' => $task, 'step' => $steps->get($task->step_id), 'level_target' => (int) $lt->target,
                    'own' => $live?->target, 'archived' => $gone && ! $live ? $gone->target : null, 'unit' => $task->recurrence_unit];
            }
        }
        usort($rows, function ($a, $b) use ($sort) {
            $byAgent = strcasecmp($a['agent']->name, $b['agent']->name);
            $byTask = strcasecmp($a['task']->label, $b['task']->label);

            return match ($sort) {
                'task'  => $byTask ?: $byAgent,
                'level' => ($a['level']->number <=> $b['level']->number) ?: ($byAgent ?: $byTask),
                default => $byAgent ?: $byTask,
            };
        });
        $page = max(1, (int) $request->query('page', 1));
        $paged = new LengthAwarePaginator(array_slice($rows, ($page - 1) * self::PAGE, self::PAGE), count($rows), self::PAGE, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('road-to-sold.settings.task-targets', [
            'tabs' => SettingsController::tabsFor($viewer, 'task-targets'), 'rows' => $paged, 'track' => $track, 'levelNames' => $levelNames,
            'filters' => ['q' => $request->query('q', ''), 'show' => $show, 'sort' => $sort, 'level' => $request->query('level'), 'branch' => $request->query('branch')],
            'branches' => Scope::level($viewer) === 'all' ? Branch::query()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function save(Request $request, int $user, int $task): RedirectResponse
    {
        [$agent, $taskModel, $level] = $this->resolve($request, $user, $task);
        $request->validate(['target' => ['required']]);
        $this->targets->set($request->user(), $agent, $taskModel, $level, $request->input('target'), $request->input('note'));

        return back()->with('success', 'Saved. ' . $agent->name . ' now has their own target for "' . $taskModel->label . '".');
    }

    public function clear(Request $request, int $user, int $task): RedirectResponse
    {
        [$agent, $taskModel, $level] = $this->resolve($request, $user, $task);
        abort_unless($this->targets->clear($request->user(), $agent, $taskModel, $level), 404);

        return back()->with('success', 'Cleared. The level\'s own target applies to ' . $agent->name . ' again.');
    }

    public function restore(Request $request, int $user, int $task): RedirectResponse
    {
        [$agent, $taskModel, $level] = $this->resolve($request, $user, $task);
        abort_unless($this->targets->restore($request->user(), $agent, $taskModel, $level), 404);

        return back()->with('success', 'Restored.');
    }

    /** @return array{0:User,1:RtsTask,2:\App\Models\RoadToSold\RtsLevel} the agent (inside the viewer's breadth, else 404), the task and the agent's level */
    private function resolve(Request $request, int $user, int $task): array
    {
        $viewer = $request->user();
        abort_unless(VisibleAgents::canSee($viewer, $user), 404);
        $agent = User::query()->withoutGlobalScopes()->whereKey($user)->firstOrFail();
        $taskModel = RtsTask::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agent->agency_id)->where('track', Routing::track())->whereKey($task)->firstOrFail();
        $level = $this->levels->levelOf($agent, Routing::track(), (int) $agent->agency_id);
        abort_unless($level, 404);

        return [$agent, $taskModel, $level];
    }
}
