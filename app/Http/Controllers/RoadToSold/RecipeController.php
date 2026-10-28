<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Services\RoadToSold\RecipeCatalogue;
use App\Services\RoadToSold\RecipeService;
use App\Services\RoadToSold\TaskMappingService;
use App\Models\RoadToSold\RtsTaskMappingAuditEntry;
use App\Services\RoadToSold\Support\Routing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-454 phase 1 - the Recipe tab of Road to Sold settings: the 13 steps, the tasks inside them and the levels an agent works through
 * (road-to-sold.md section 5, 10.4, 13.1). Three lists (Tasks / Steps / Levels), each with search, sort (stated default), filters
 * (status at least), pagination, a real empty state and full create / read / update / archive / restore - soft delete only.
 *
 * Scope: a recipe belongs to one agency (and one track, from where the screens are mounted). Every {task} / {step} / {level} answers 404
 * outside the viewer's agency - the global AgencyScope plus an explicit check here (a platform owner has no scope to lean on).
 * Writes go through RecipeService only. Reading never writes: an agency that has not started shows the neutral recipe as a preview, and
 * the "Start with the standard recipe" button is what creates it.
 */
class RecipeController extends Controller
{
    private const PER_PAGE = 25;
    private const SECTIONS = ['tasks', 'steps', 'levels'];

    public function __construct(private readonly RecipeService $recipe)
    {
    }

    // ── The page ─────────────────────────────────────────────────────────

    public function index(Request $request): View|RedirectResponse
    {
        $agencyId = $this->agencyId($request);
        if ($agencyId <= 0) {
            return redirect()->route('corex.dashboard')->with('error', 'Switch into an agency first - the recipe belongs to one agency.');
        }
        $track   = Routing::track();
        $section = in_array($request->query('section'), self::SECTIONS, true) ? $request->query('section') : 'tasks';
        $started = $this->recipe->isStarted($agencyId, $track);

        $data = [
            'tabs'    => SettingsController::tabsFor($request->user(), 'recipe'),
            'rb'      => Routing::base(),
            'section' => $section,
            'started' => $started,
        ];

        if (! $started) {
            return view('road-to-sold.settings.recipe.index', $data + [
                'previewSteps' => RecipeCatalogue::STEPS,
                'previewTasks' => RecipeCatalogue::defaultTasks(),
                'groupLabels'  => RecipeCatalogue::GROUP_LABELS,
            ]);
        }

        $data += match ($section) {
            'steps'  => $this->stepsData($request, $agencyId, $track),
            'levels' => $this->levelsData($request, $agencyId, $track),
            default  => $this->tasksData($request, $agencyId, $track),
        };

        return view('road-to-sold.settings.recipe.index', $data + ['groupLabels' => RecipeCatalogue::GROUP_LABELS]);
    }

    public function start(Request $request): RedirectResponse
    {
        $agencyId = $this->requireAgency($request);
        $track    = Routing::track();

        if ($this->recipe->isStarted($agencyId, $track)) {
            return redirect()->route(Routing::base() . 'recipe.index')->with('success', 'Your recipe is already started.');
        }
        $made = $this->recipe->start($agencyId, $track);

        return redirect()->route(Routing::base() . 'recipe.index')
            ->with('success', "The standard recipe is ready: {$made['steps']} steps, {$made['tasks']} tasks and {$made['levels']} levels. Change anything you like.");
    }

    // ── Tasks ────────────────────────────────────────────────────────────

    public function taskCreate(Request $request): View|RedirectResponse
    {
        $agencyId = $this->requireAgency($request);
        if (! $this->recipe->isStarted($agencyId, Routing::track())) {
            return $this->notStarted();
        }

        return $this->taskForm($request, null, $agencyId);
    }

    public function taskStore(Request $request): RedirectResponse
    {
        $agencyId = $this->requireAgency($request);
        if (! $this->recipe->isStarted($agencyId, Routing::track())) {
            return $this->notStarted();
        }
        $task = $this->recipe->saveTask($agencyId, Routing::track(), null, $request->all());

        return $this->back('tasks', "Task \"{$task->label}\" saved.");
    }

    public function taskEdit(Request $request, RtsTask $task): View
    {
        return $this->taskForm($request, $this->own($task, $request), $this->agencyId($request));
    }

    public function taskUpdate(Request $request, RtsTask $task): RedirectResponse
    {
        $this->own($task, $request);
        $this->recipe->saveTask((int) $task->agency_id, Routing::track(), $task, $request->all());

        return $this->back('tasks', "Task \"{$task->label}\" saved.");
    }

    public function taskArchive(Request $request, RtsTask $task): RedirectResponse
    {
        $this->recipe->archiveTask($this->own($task, $request));

        return $this->back('tasks', "Task \"{$task->label}\" archived. You can bring it back from the archived list.");
    }

    /** Put a task back on the step / event it started on. One audit row; credit already recorded is never touched. */
    public function taskRestoreDefault(Request $request, RtsTask $task): RedirectResponse
    {
        $task = $this->own($task, $request);
        if (! app(TaskMappingService::class)->restoreDefault($task, $request->user()?->id)) {
            return $this->back('tasks', "\"{$task->label}\" has no default to go back to.");
        }

        return $this->back('tasks', "\"{$task->label}\" is back on its default step.");
    }

    public function taskRestore(Request $request, RtsTask $task): RedirectResponse
    {
        $this->recipe->restoreTask($this->own($task, $request));

        return $this->back('tasks', "Task \"{$task->label}\" is back.");
    }

    // ── Steps ────────────────────────────────────────────────────────────

    public function stepUpdate(Request $request, RtsStep $step): RedirectResponse
    {
        $this->own($step, $request);
        $this->recipe->saveStep($step, [
            'label'      => $request->input('label'),
            'position'   => $request->input('position'),
            'is_enabled' => $request->boolean('is_enabled'),
        ]);

        return $this->back('steps', "Step \"{$step->label}\" saved.");
    }

    public function stepArchive(Request $request, RtsStep $step): RedirectResponse
    {
        $this->recipe->archiveStep($this->own($step, $request));

        return $this->back('steps', "Step \"{$step->label}\" archived. You can bring it back from the archived list.");
    }

    public function stepRestore(Request $request, RtsStep $step): RedirectResponse
    {
        $this->recipe->restoreStep($this->own($step, $request));

        return $this->back('steps', "Step \"{$step->label}\" is back.");
    }

    // ── Levels ───────────────────────────────────────────────────────────

    public function levelCreate(Request $request): View|RedirectResponse
    {
        $agencyId = $this->requireAgency($request);
        if (! $this->recipe->isStarted($agencyId, Routing::track())) {
            return $this->notStarted();
        }

        return $this->levelForm($request, null, $agencyId);
    }

    public function levelStore(Request $request): RedirectResponse
    {
        $agencyId = $this->requireAgency($request);
        if (! $this->recipe->isStarted($agencyId, Routing::track())) {
            return $this->notStarted();
        }
        $level = $this->recipe->saveLevel($agencyId, Routing::track(), null, $this->levelInput($request));

        return $this->back('levels', "Level \"{$level->name}\" saved.");
    }

    public function levelEdit(Request $request, RtsLevel $level): View
    {
        return $this->levelForm($request, $this->own($level, $request), $this->agencyId($request));
    }

    public function levelUpdate(Request $request, RtsLevel $level): RedirectResponse
    {
        $this->own($level, $request);
        $this->recipe->saveLevel((int) $level->agency_id, Routing::track(), $level, $this->levelInput($request));

        return $this->back('levels', "Level \"{$level->name}\" saved.");
    }

    public function levelArchive(Request $request, RtsLevel $level): RedirectResponse
    {
        $this->recipe->archiveLevel($this->own($level, $request));

        return $this->back('levels', "Level \"{$level->name}\" archived. You can bring it back from the archived list.");
    }

    public function levelRestore(Request $request, RtsLevel $level): RedirectResponse
    {
        $this->recipe->restoreLevel($this->own($level, $request));

        return $this->back('levels', "Level \"{$level->name}\" is back.");
    }

    // ── Lists ────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function tasksData(Request $request, int $agencyId, string $track): array
    {
        $q      = trim((string) $request->query('q', ''));
        $type   = in_array($request->query('type'), [RtsTask::TYPE_CORE, RtsTask::TYPE_CUSTOM], true) ? $request->query('type') : 'all';
        $stepId = (int) $request->query('step', 0);
        $unit   = array_key_exists((string) $request->query('unit'), RtsTask::UNITS) ? $request->query('unit') : 'all';
        $status = in_array($request->query('status'), ['on', 'off', 'archived', 'all'], true) ? $request->query('status') : 'live';
        $sort   = in_array($request->query('sort'), ['step', 'label', 'unit', 'type'], true) ? $request->query('sort') : 'step';
        $mapping = in_array($request->query('mapping'), ['unmapped', 'changed'], true) ? $request->query('mapping') : 'all';

        $query = RtsTask::query()
            ->join('rts_steps', 'rts_steps.id', '=', 'rts_tasks.step_id')
            ->where('rts_tasks.agency_id', $agencyId)->where('rts_tasks.track', $track)
            ->select('rts_tasks.*', 'rts_steps.label as step_label', 'rts_steps.position as step_position');

        match ($status) {
            'archived' => $query->onlyTrashed(),
            'all'      => $query->withTrashed(),
            'on'       => $query->where('rts_tasks.is_active', true),
            'off'      => $query->where('rts_tasks.is_active', false),
            default    => null,
        };
        if ($q !== '') {
            $like = '%' . $this->like($q) . '%';
            $query->where(fn ($w) => $w->where('rts_tasks.label', 'like', $like)->orWhere('rts_steps.label', 'like', $like));
        }
        if ($mapping === 'unmapped') {
            $query->where('rts_tasks.needs_step', true);
        } elseif ($mapping === 'changed') {
            // moved off the step / event / filter it started on (a task with no recorded default is never "changed")
            $query->whereNotNull('rts_tasks.mapping_default')->where(fn ($w) => $w
                ->whereRaw("rts_tasks.step_id <> JSON_EXTRACT(rts_tasks.mapping_default, '$.step_id')")
                ->orWhereRaw("NOT (rts_tasks.event_key <=> JSON_UNQUOTE(JSON_EXTRACT(rts_tasks.mapping_default, '$.event_key')))")
                ->orWhereRaw("NOT (rts_tasks.event_filter <=> JSON_EXTRACT(rts_tasks.mapping_default, '$.event_filter'))")
                ->orWhereRaw("rts_tasks.needs_step <> COALESCE(JSON_EXTRACT(rts_tasks.mapping_default, '$.needs_step'), 0)"));
        }
        if ($type !== 'all') {
            $query->where('rts_tasks.type', $type);
        }
        if ($stepId > 0) {
            $query->where('rts_tasks.step_id', $stepId);
        }
        if ($unit !== 'all') {
            $query->where('rts_tasks.recurrence_unit', $unit);
        }
        match ($sort) {
            'label' => $query->orderBy('rts_tasks.label'),
            'unit'  => $query->orderByRaw("FIELD(rts_tasks.recurrence_unit,'hour','day','week','month')")->orderBy('rts_tasks.label'),
            'type'  => $query->orderBy('rts_tasks.type')->orderBy('rts_tasks.label'),
            default => $query->orderBy('rts_steps.position')->orderBy('rts_tasks.sort_order')->orderBy('rts_tasks.id'),
        };

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        $levelsByTask = RtsLevelTask::query()->where('agency_id', $agencyId)->whereIn('task_id', $page->getCollection()->pluck('id'))
            ->with('level')->get()->groupBy('task_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => $r->level?->name)->filter()->values()->all());

        return [
            'rows'         => $page,
            'levelsByTask' => $levelsByTask,
            'steps'        => $this->steps($agencyId, $track),
            'filters'      => compact('q', 'type', 'unit', 'status', 'sort', 'mapping') + ['stepId' => $stepId],
            'emptyFiltered' => $page->total() === 0 && ($q !== '' || $type !== 'all' || $stepId > 0 || $unit !== 'all' || $status !== 'live' || $mapping !== 'all'),
            'mappingSvc'   => app(TaskMappingService::class),
            'mappingLog'   => RtsTaskMappingAuditEntry::query()->where('agency_id', $agencyId)->where('track', $track)->with('changedBy')->latest('id')->limit(8)->get(),
        ];
    }

    /** @return array<string,mixed> */
    private function stepsData(Request $request, int $agencyId, string $track): array
    {
        $q      = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['on', 'off', 'archived', 'all'], true) ? $request->query('status') : 'live';
        $sort   = in_array($request->query('sort'), ['position', 'label'], true) ? $request->query('sort') : 'position';

        $query = RtsStep::query()->where('agency_id', $agencyId)->where('track', $track)->withCount('tasks');
        match ($status) {
            'archived' => $query->onlyTrashed(),
            'all'      => $query->withTrashed(),
            'on'       => $query->where('is_enabled', true),
            'off'      => $query->where('is_enabled', false),
            default    => null,
        };
        if ($q !== '') {
            $query->where('label', 'like', '%' . $this->like($q) . '%');
        }
        $sort === 'label' ? $query->orderBy('label') : $query->orderBy('position')->orderBy('id');

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return [
            'rows'    => $page,
            'filters' => compact('q', 'status', 'sort'),
            'emptyFiltered' => $page->total() === 0 && ($q !== '' || $status !== 'live'),
        ];
    }

    /** @return array<string,mixed> */
    private function levelsData(Request $request, int $agencyId, string $track): array
    {
        $q      = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['on', 'off', 'archived', 'all'], true) ? $request->query('status') : 'live';
        $sort   = in_array($request->query('sort'), ['number', 'name'], true) ? $request->query('sort') : 'number';

        $query = RtsLevel::query()->where('agency_id', $agencyId)->where('track', $track)->withCount('levelTasks');
        match ($status) {
            'archived' => $query->onlyTrashed(),
            'all'      => $query->withTrashed(),
            'on'       => $query->where('is_active', true),
            'off'      => $query->where('is_active', false),
            default    => null,
        };
        if ($q !== '') {
            $like = '%' . $this->like($q) . '%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)
                ->orWhereIn('id', RtsLevelTask::query()->where('agency_id', $agencyId)
                    ->whereIn('task_id', RtsTask::query()->where('agency_id', $agencyId)->where('label', 'like', $like)->select('id'))->select('level_id')));
        }
        $sort === 'name' ? $query->orderBy('name') : $query->orderBy('number');

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return [
            'rows'    => $page,
            'filters' => compact('q', 'status', 'sort'),
            'emptyFiltered' => $page->total() === 0 && ($q !== '' || $status !== 'live'),
        ];
    }

    // ── Forms ────────────────────────────────────────────────────────────

    private function taskForm(Request $request, ?RtsTask $task, int $agencyId): View
    {
        return view('road-to-sold.settings.recipe.task-form', [
            'tabs'        => SettingsController::tabsFor($request->user(), 'recipe'),
            'rb'          => Routing::base(),
            'task'        => $task,
            'steps'       => $this->steps($agencyId, Routing::track()),
            'events'      => RecipeCatalogue::eventChoices(),
            'eventFilters' => collect(RecipeCatalogue::EVENTS)->map(fn ($m) => $m['filters'])->all(),
        ]);
    }

    private function levelForm(Request $request, ?RtsLevel $level, int $agencyId): View
    {
        $track = Routing::track();
        $tasks = RtsTask::query()->join('rts_steps', 'rts_steps.id', '=', 'rts_tasks.step_id')
            ->where('rts_tasks.agency_id', $agencyId)->where('rts_tasks.track', $track)
            ->select('rts_tasks.*', 'rts_steps.label as step_label', 'rts_steps.position as step_position')
            ->orderBy('rts_steps.position')->orderBy('rts_tasks.sort_order')->get();

        $inLevel = $level
            ? RtsLevelTask::query()->where('agency_id', $agencyId)->where('level_id', $level->id)->get()->keyBy('task_id')
            : collect();

        return view('road-to-sold.settings.recipe.level-form', [
            'tabs'    => SettingsController::tabsFor($request->user(), 'recipe'),
            'rb'      => Routing::base(),
            'level'   => $level,
            'tasks'   => $tasks,
            'inLevel' => $inLevel,
            'maxTarget' => RtsLevelTask::MAX_TARGET,
            'maxPoints' => RtsLevelTask::MAX_POINTS,
        ]);
    }

    /** @return array<string,mixed> the level form, with a checkbox that is absent when unticked read as "keep as it was" for is_active only on edit */
    private function levelInput(Request $request): array
    {
        $input = $request->only(['name', 'description', 'tasks']);
        $input['tasks'] = (array) ($request->input('tasks') ?? []);
        if ($request->has('is_active_present')) {
            $input['is_active'] = $request->boolean('is_active');
        }

        return $input;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return \Illuminate\Support\Collection<int,RtsStep> */
    private function steps(int $agencyId, string $track)
    {
        return RtsStep::query()->where('agency_id', $agencyId)->where('track', $track)->orderBy('position')->orderBy('id')->get();
    }

    private function agencyId(Request $request): int
    {
        return (int) ($request->user()->effectiveAgencyId() ?: 0);
    }

    private function requireAgency(Request $request): int
    {
        $agencyId = $this->agencyId($request);
        abort_if($agencyId <= 0, 403, 'Switch into an agency first - the recipe belongs to one agency.');

        return $agencyId;
    }

    /** Direct-URL access by id is blocked: another agency's (or another track's) row is a 404 even for an owner with no scope to lean on. */
    private function own(object $model, Request $request): object
    {
        abort_unless(
            (int) $model->agency_id === $this->agencyId($request) && (string) $model->track === Routing::track(),
            404
        );

        return $model;
    }

    private function back(string $section, string $message): RedirectResponse
    {
        return redirect()->route(Routing::base() . 'recipe.index', ['section' => $section])->with('success', $message);
    }

    private function notStarted(): RedirectResponse
    {
        return redirect()->route(Routing::base() . 'recipe.index')->with('error', 'Start with the standard recipe first - then you can add to it.');
    }

    private function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
