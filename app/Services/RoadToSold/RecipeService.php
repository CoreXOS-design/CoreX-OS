<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AT-454 phase 1 - the one writer of an agency's recipe: steps, tasks, levels and the tasks inside a level
 * (road-to-sold.md section 5.4 / 5.5 / 10.4 / 10.5).
 *
 *  - Reading NEVER writes (STANDARDS Rule 17): an agency that has not started shows RecipeCatalogue's neutral defaults as a preview.
 *    The recipe is created by start() - the "Start with the standard recipe" button, or the first save on the Recipe tab - never on read
 *    and never by an event.
 *  - Soft delete only. Every unique key ignores soft deletes, so a write that meets a trashed row RESTORES it instead of inserting a
 *    duplicate (BUILD_STANDARD 5a): create -> archive -> create again never errors and never duplicates.
 *  - Input is validated with plain-language messages; a refused save writes nothing.
 */
class RecipeService
{
    /** Has this agency started its recipe (any step row, even archived)? Archived rows count: start() must never overwrite them. */
    public function isStarted(int $agencyId, string $track): bool
    {
        return RtsStep::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->where('agency_id', $agencyId)->where('track', $track)->exists();
    }

    /**
     * Create the neutral recipe for an agency: the 13 steps, the task catalogue, three levels (Level 1 holding a small set).
     * Idempotent - only what is missing is created and nothing existing is changed, so a second press does nothing.
     *
     * @return array{steps:int,tasks:int,levels:int}  how many rows were created
     */
    public function start(int $agencyId, string $track = 'sales', bool $withDefaultTasks = true): array
    {
        $made = ['steps' => 0, 'tasks' => 0, 'levels' => 0];

        DB::transaction(function () use ($agencyId, $track, $withDefaultTasks, &$made) {
            $position = 0;
            $stepIds  = [];
            foreach (RecipeCatalogue::STEPS as $key => [$group, $label]) {
                $position++;
                $step = RtsStep::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                    ->where('agency_id', $agencyId)->where('track', $track)->where('key', $key)->first();
                if (! $step) {
                    $step = RtsStep::create(['agency_id' => $agencyId, 'track' => $track, 'key' => $key, 'group' => $group,
                        'position' => $position, 'label' => $label, 'is_enabled' => true]);
                    $made['steps']++;
                }
                $stepIds[$key] = $step->id;
            }

            $levels = [];
            foreach (RecipeCatalogue::DEFAULT_LEVELS as $number => [$name, $description]) {
                $level = RtsLevel::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                    ->where('agency_id', $agencyId)->where('track', $track)->where('number', $number)->first();
                if (! $level) {
                    $level = RtsLevel::create(['agency_id' => $agencyId, 'track' => $track, 'number' => $number,
                        'name' => $name, 'description' => $description, 'is_active' => true]);
                    $made['levels']++;
                }
                $levels[$number] = $level;
            }

            $sort = 0;
            foreach ($withDefaultTasks ? RecipeCatalogue::defaultTasks() : [] as $def) {
                $sort++;
                $task = RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                    ->where('agency_id', $agencyId)->where('track', $track)->where('key', $def['key'])->first();
                if (! $task) {
                    $task = RtsTask::create([
                        'agency_id' => $agencyId, 'track' => $track, 'step_id' => $stepIds[$def['step']], 'key' => $def['key'],
                        'label' => $def['label'], 'type' => RtsTask::TYPE_CORE, 'event_key' => $def['event'],
                        'event_filter' => $def['filter'] ?: null, 'recurrence_unit' => $def['unit'], 'capture_kind' => 'none',
                        'evidence_required' => 'none', 'is_system' => true, 'is_active' => true, 'sort_order' => $sort,
                        'mapping_default' => app(TaskMappingService::class)->defaultPayload([
                            'step_id' => $stepIds[$def['step']], 'event_key' => $def['event'], 'event_filter' => $def['filter'] ?: null, 'needs_step' => false,
                        ]),
                    ]);
                    $made['tasks']++;

                    if ($def['level1'] !== null) {
                        RtsLevelTask::create(['agency_id' => $agencyId, 'level_id' => $levels[1]->id, 'task_id' => $task->id,
                            'target' => $def['level1'][0], 'points' => $def['level1'][1], 'required_for_signoff' => false]);
                    }
                }
            }
        });

        return $made;
    }

    // ── Steps ────────────────────────────────────────────────────────────

    /** @param array{label?:mixed,is_enabled?:mixed,position?:mixed} $input  only the keys present are changed */
    public function saveStep(RtsStep $step, array $input): RtsStep
    {
        $data = Validator::make($input, [
            'label'    => ['sometimes', 'required', 'string', 'max:120'],
            'position' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
        ], [
            'label.required' => 'Give the step a name.',
            'label.max'      => 'A step name can be up to 120 characters.',
            'position.integer' => 'The position must be a whole number.',
            'position.min'   => 'The position must be 1 or more.',
            'position.max'   => 'The position can be up to 99.',
        ])->validate();

        if (array_key_exists('label', $data)) {
            $step->label = trim((string) $data['label']);
        }
        if (array_key_exists('position', $data) && $data['position'] !== null) {
            $step->position = (int) $data['position'];
        }
        if (array_key_exists('is_enabled', $input)) {
            $step->is_enabled = filter_var($input['is_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        $step->save();

        return $step;
    }

    public function archiveStep(RtsStep $step): void
    {
        $step->delete();
    }

    public function restoreStep(RtsStep $step): void
    {
        $step->restore();
    }

    // ── Tasks ────────────────────────────────────────────────────────────

    /**
     * Create ($task = null) or change a task.
     *
     * @param array<string,mixed> $input label, step_id, type, event_key, filter_fact, filter_value, recurrence_unit, capture_kind,
     *                                   evidence_required, is_active
     */
    public function saveTask(int $agencyId, string $track, ?RtsTask $task, array $input): RtsTask
    {
        $stepIds = RtsStep::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->pluck('id')->all();

        $data = Validator::make($input, [
            'label'             => ['required', 'string', 'max:160'],
            'step_id'           => ['required', 'integer', Rule::in($stepIds)],
            'type'              => ['required', Rule::in([RtsTask::TYPE_CORE, RtsTask::TYPE_CUSTOM])],
            'event_key'         => ['nullable', 'string', 'max:190'],
            'filter_fact'       => ['nullable', 'string', 'max:60'],
            'filter_value'      => ['nullable', 'string', 'max:120'],
            'recurrence_unit'   => ['required', Rule::in(array_keys(RtsTask::UNITS))],
            'capture_kind'      => ['nullable', Rule::in(array_keys(RtsTask::CAPTURE_KINDS))],
            'evidence_required' => ['nullable', Rule::in(array_keys(RtsTask::EVIDENCE))],
        ], [
            'label.required'           => 'Give the task a name.',
            'label.max'                => 'A task name can be up to 160 characters.',
            'step_id.required'         => 'Pick the step this task belongs to.',
            'step_id.in'               => 'Pick one of the steps in your recipe.',
            'type.required'            => 'Choose whether the task is completed automatically or captured by the agent.',
            'type.in'                  => 'Choose whether the task is completed automatically or captured by the agent.',
            'recurrence_unit.required' => 'Pick how often the task repeats: hour, day, week or month.',
            'recurrence_unit.in'       => 'Pick how often the task repeats: hour, day, week or month.',
            'capture_kind.in'          => 'Pick what the agent captures: notes, properties, contacts or both.',
            'evidence_required.in'     => 'Pick what evidence is required: none, a note, or a note or a photo.',
        ])->validate();

        $type = $data['type'];
        $eventKey = null;
        $filter   = null;
        if ($type === RtsTask::TYPE_CORE) {
            // An automatic task can only be bound to an event that really fires (spec 10.5).
            // A task moved over from the old daily activities is credited by CoreX's own detectors, not by a bound event: it may stay so.
            $creditedByCoreX = $task && $task->legacy_activity_definition_id && trim((string) ($data['event_key'] ?? '')) === '';
            if (! $creditedByCoreX && ! RecipeCatalogue::isSelectable($data['event_key'] ?? null)) {
                throw ValidationException::withMessages(['event_key' => 'Pick what completes this task from the list of things CoreX records.']);
            }
            $eventKey = $creditedByCoreX ? null : $data['event_key'];
            $fact     = trim((string) ($data['filter_fact'] ?? ''));
            $value    = trim((string) ($data['filter_value'] ?? ''));
            if ($fact !== '' || $value !== '') {
                if (! array_key_exists($fact, RecipeCatalogue::filtersFor($eventKey))) {
                    throw ValidationException::withMessages(['filter_fact' => 'That event cannot be narrowed that way - pick one of the listed options or leave it empty.']);
                }
                if ($value === '') {
                    throw ValidationException::withMessages(['filter_value' => 'Write what the value must be, or clear the option.']);
                }
                $filter = [$fact => $value];
            }
        }

        $attributes = [
            'label'             => trim((string) $data['label']),
            'step_id'           => (int) $data['step_id'],
            'type'              => $type,
            'event_key'         => $eventKey,
            'event_filter'      => $filter,
            'recurrence_unit'   => $data['recurrence_unit'],
            'capture_kind'      => $type === RtsTask::TYPE_CUSTOM ? ($data['capture_kind'] ?? 'none') : 'none',
            'evidence_required' => $type === RtsTask::TYPE_CUSTOM ? ($data['evidence_required'] ?? 'none') : 'none',
        ];
        if (array_key_exists('is_active', $input)) {
            $attributes['is_active'] = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);
        }

        return DB::transaction(function () use ($agencyId, $track, $task, $attributes) {
            if ($task) {
                // Saving the form means someone chose the step (it is a required field), so "not mapped" ends here. Any change to the
                // step or what credits the task is one audit row (22.24).
                $mapping = app(TaskMappingService::class);
                $before  = $mapping->snapshot($task);
                $task->fill($attributes + ['needs_step' => false])->save();
                $mapping->recordChange($task, $before, auth()->id() ? (int) auth()->id() : null);

                return $task;
            }

            // Create: the same label as an archived task brings that task back (restore, never a duplicate).
            $base     = Str::slug($attributes['label']) ?: 'task';
            $base     = Str::limit($base, 50, '');
            $existing = RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                ->where('agency_id', $agencyId)->where('track', $track)->where('key', $base)->first();
            if ($existing && $existing->trashed()) {
                $existing->restore();
                $existing->fill($attributes + ['is_active' => true])->save();

                return $existing;
            }

            $key = $base;
            $n   = 1;
            while (RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                ->where('agency_id', $agencyId)->where('track', $track)->where('key', $key)->exists()) {
                $n++;
                $key = $base . '-' . $n;
            }
            $sort = (int) RtsTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                ->where('agency_id', $agencyId)->where('track', $track)->max('sort_order');

            return RtsTask::create($attributes + [
                'agency_id' => $agencyId, 'track' => $track, 'key' => $key, 'is_system' => false,
                'is_active' => true, 'sort_order' => $sort + 1,
            ]);
        });
    }

    public function archiveTask(RtsTask $task): void
    {
        $task->delete();
    }

    public function restoreTask(RtsTask $task): void
    {
        $task->restore();
    }

    // ── Levels ───────────────────────────────────────────────────────────

    /**
     * Create ($level = null) or change a level and, when `tasks` is given, the tasks inside it.
     *
     * @param array{name?:mixed,description?:mixed,is_active?:mixed,tasks?:array<int,array{in?:mixed,target?:mixed,points?:mixed,required?:mixed}>} $input
     *        `tasks` is keyed by task id; a task posted with `in` set is placed in the level, one not posted (or `in` empty) is taken out.
     *        A blank target or points keeps the stored value (0 for a task new to the level).
     */
    public function saveLevel(int $agencyId, string $track, ?RtsLevel $level, array $input): RtsLevel
    {
        $data = Validator::make($input, [
            'name'        => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required'   => 'Give the level a name.',
            'name.max'        => 'A level name can be up to 80 characters.',
            'description.max' => 'The description can be up to 255 characters.',
        ])->validate();

        $taskRows = null;
        if (array_key_exists('tasks', $input)) {
            $taskRows = $this->validatedLevelTasks($agencyId, $track, (array) $input['tasks']);
        }

        return DB::transaction(function () use ($agencyId, $track, $level, $input, $data, $taskRows) {
            if (! $level) {
                $next  = 1 + (int) RtsLevel::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                    ->where('agency_id', $agencyId)->where('track', $track)->max('number');
                $level = RtsLevel::create(['agency_id' => $agencyId, 'track' => $track, 'number' => $next,
                    'name' => trim((string) $data['name']), 'description' => ($data['description'] ?? null) ?: null, 'is_active' => true]);
            } else {
                $level->name        = trim((string) $data['name']);
                $level->description = ($data['description'] ?? null) ?: null;
                if (array_key_exists('is_active', $input)) {
                    $level->is_active = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN);
                }
                $level->save();
            }

            if ($taskRows !== null) {
                $this->syncLevelTasks($agencyId, $level, $taskRows);
            }

            return $level;
        });
    }

    public function archiveLevel(RtsLevel $level): void
    {
        $level->delete();
    }

    public function restoreLevel(RtsLevel $level): void
    {
        $level->restore();
    }

    /**
     * @param  array<int|string,mixed> $posted
     * @return array<int,array{target:?int,points:?float,required:bool}> task id => values, only the tasks that are IN the level
     */
    private function validatedLevelTasks(int $agencyId, string $track, array $posted): array
    {
        $validTaskIds = RtsTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agencyId)->where('track', $track)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $rows   = [];
        $errors = [];
        foreach ($posted as $taskId => $row) {
            $taskId = (int) $taskId;
            $row    = is_array($row) ? $row : [];
            if (empty($row['in']) || ! in_array($taskId, $validTaskIds, true)) {
                continue;
            }

            $target = $row['target'] ?? null;
            $points = $row['points'] ?? null;
            $target = is_string($target) ? trim($target) : $target;
            $points = is_string($points) ? trim(str_replace(',', '.', $points)) : $points;

            if ($target !== null && $target !== '') {
                if (! preg_match('/^\d+$/', (string) $target) || (int) $target > RtsLevelTask::MAX_TARGET) {
                    $errors["tasks.$taskId.target"] = 'A target is a whole number from 0 to ' . number_format(RtsLevelTask::MAX_TARGET, 0, '.', ' ') . '.';
                    continue;
                }
            }
            if ($points !== null && $points !== '') {
                if (! is_numeric($points) || (float) $points < 0 || (float) $points > RtsLevelTask::MAX_POINTS || round((float) $points, 2) !== (float) $points) {
                    $errors["tasks.$taskId.points"] = 'Points are a number from 0 to ' . number_format(RtsLevelTask::MAX_POINTS, 0, '.', ' ') . ', with at most two decimals.';
                    continue;
                }
            }

            $rows[$taskId] = [
                'target'   => ($target === null || $target === '') ? null : (int) $target,
                'points'   => ($points === null || $points === '') ? null : (float) $points,
                'required' => ! empty($row['required']),
            ];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /** @param array<int,array{target:?int,points:?float,required:bool}> $rows */
    private function syncLevelTasks(int $agencyId, RtsLevel $level, array $rows): void
    {
        $existing = RtsLevelTask::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->where('agency_id', $agencyId)->where('level_id', $level->id)->get()->keyBy('task_id');

        foreach ($rows as $taskId => $values) {
            $row = $existing->get($taskId);
            if ($row) {
                if ($row->trashed()) {
                    $row->restore();
                }
                $row->fill([
                    'target'               => $values['target'] ?? $row->target,
                    'points'               => $values['points'] ?? $row->points,
                    'required_for_signoff' => $values['required'],
                ])->save();
            } else {
                RtsLevelTask::create(['agency_id' => $agencyId, 'level_id' => $level->id, 'task_id' => $taskId,
                    'target' => $values['target'] ?? 0, 'points' => $values['points'] ?? 0, 'required_for_signoff' => $values['required']]);
            }
        }

        foreach ($existing as $taskId => $row) {
            if (! array_key_exists((int) $taskId, $rows) && ! $row->trashed()) {
                $row->delete();
            }
        }
    }
}
