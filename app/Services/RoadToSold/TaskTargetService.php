<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsLevel;
use App\Models\RoadToSold\RtsLevelTask;
use App\Models\RoadToSold\RtsManagerAction;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsUserTaskOverride;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AT-454 v2 phase 5 - the audited writer of an agent's own target for a task (road-to-sold.md sections 5.2, 9.4, 10.4 "Task targets per user").
 *
 * A personal target beats the level's (RecipeProgressService, AgentLevelService both read it). Everything that changes one goes through
 * here - the manager's screen and the "accept this suggestion" button alike - so there is one rule, one scope check and one audit:
 *  - the viewer needs `road_to_sold.manage_targets` AND the agent must be inside their breadth (an agent outside it is a 404);
 *  - a target is a whole number from 0 (0 = nothing required this period) to RtsLevelTask::MAX_TARGET;
 *  - setting writes one row per (agent, task, level) - a trashed row is restored, never duplicated (BUILD_STANDARD 5a);
 *  - clearing archives the row (soft delete; the level's own target applies again) and restoring brings it back;
 *  - every change appends a row to rts_manager_actions (who, what, before, after, why); nothing here deletes a row for good.
 */
class TaskTargetService
{
    public function set(User $by, User $agent, RtsTask $task, RtsLevel $level, mixed $target, ?string $note = null): RtsUserTaskOverride
    {
        $value = $this->validTarget($target);
        $this->guard($by, $agent, $task, $level);

        return DB::transaction(function () use ($by, $agent, $task, $level, $value, $note) {
            $row = $this->row($agent, $task, $level, true);
            $before = $row && ! $row->trashed() ? $row->target : $this->effective($agent, $task, $level);
            if (! $row) {
                $row = RtsUserTaskOverride::withoutAgencyStamping(fn () => RtsUserTaskOverride::create([
                    'agency_id' => $agent->agency_id, 'user_id' => $agent->id, 'task_id' => $task->id, 'level_id' => $level->id,
                    'level_key' => (int) $level->id, 'target' => $value, 'set_by_user_id' => $by->id,
                ]));
            } else {
                if ($row->trashed()) {
                    $row->restore();
                }
                $row->forceFill(['target' => $value, 'set_by_user_id' => $by->id])->save();
            }
            $this->audit($by, $agent, $task, RtsManagerAction::TARGET_SET, $before, $value, $note);

            return $row;
        });
    }

    /** Archive the personal target: the level's own target applies again. False when there was nothing to clear. */
    public function clear(User $by, User $agent, RtsTask $task, RtsLevel $level, ?string $note = null): bool
    {
        $this->guard($by, $agent, $task, $level);

        return DB::transaction(function () use ($by, $agent, $task, $level, $note) {
            $row = $this->row($agent, $task, $level, false);
            if (! $row || $row->target === null) {
                return false;
            }
            $before = $row->target;
            $row->forceFill(['set_by_user_id' => $by->id])->save();
            $row->delete();
            $this->audit($by, $agent, $task, RtsManagerAction::TARGET_CLEARED, $before, $this->effective($agent, $task, $level), $note);

            return true;
        });
    }

    /** Bring an archived personal target back. False when there was none. */
    public function restore(User $by, User $agent, RtsTask $task, RtsLevel $level): bool
    {
        $this->guard($by, $agent, $task, $level);

        return DB::transaction(function () use ($by, $agent, $task, $level) {
            $row = RtsUserTaskOverride::query()->withoutGlobalScope(AgencyScope::class)->onlyTrashed()
                ->where('agency_id', $agent->agency_id)->where('user_id', $agent->id)->where('task_id', $task->id)->where('level_key', (int) $level->id)->first();
            if (! $row || $row->target === null) {
                return false;
            }
            $before = $this->effective($agent, $task, $level);
            $row->restore();
            $row->forceFill(['set_by_user_id' => $by->id])->save();
            $this->audit($by, $agent, $task, RtsManagerAction::TARGET_SET, $before, (int) $row->target, 'Restored');

            return true;
        });
    }

    /** The target in force for this agent on this task: their own, else the level's. */
    public function effective(User $agent, RtsTask $task, RtsLevel $level): int
    {
        $own = $this->row($agent, $task, $level, false);
        if ($own && $own->target !== null) {
            return (int) $own->target;
        }

        return (int) (RtsLevelTask::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agent->agency_id)->where('level_id', $level->id)->where('task_id', $task->id)->value('target') ?? 0);
    }

    private function row(User $agent, RtsTask $task, RtsLevel $level, bool $withTrashed): ?RtsUserTaskOverride
    {
        $q = RtsUserTaskOverride::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $agent->agency_id)->where('user_id', $agent->id)->where('task_id', $task->id)->where('level_key', (int) $level->id);

        return ($withTrashed ? $q->withTrashed() : $q)->first();
    }

    private function validTarget(mixed $target): int
    {
        $target = is_string($target) ? trim($target) : $target;
        if (! is_numeric($target) || floor((float) $target) !== (float) $target) {
            throw ValidationException::withMessages(['target' => 'A target is a whole number - for example 5. Use 0 for "nothing required".']);
        }
        $value = (int) $target;
        if ($value < 0 || $value > RtsLevelTask::MAX_TARGET) {
            throw ValidationException::withMessages(['target' => 'A target is between 0 and ' . number_format(RtsLevelTask::MAX_TARGET) . '.']);
        }

        return $value;
    }

    private function guard(User $by, User $agent, RtsTask $task, RtsLevel $level): void
    {
        abort_unless($by->hasPermission('road_to_sold.manage_targets'), 403);
        abort_unless(VisibleAgents::canSee($by, (int) $agent->id), 404);
        abort_unless((int) $task->agency_id === (int) $agent->agency_id && (int) $level->agency_id === (int) $agent->agency_id, 404);
    }

    private function audit(User $by, User $agent, RtsTask $task, string $kind, ?int $from, ?int $to, ?string $note): void
    {
        RtsManagerAction::withoutAgencyStamping(fn () => RtsManagerAction::create([
            'agency_id' => $agent->agency_id, 'branch_id' => $agent->branch_id, 'track' => $task->track, 'user_id' => $agent->id, 'by_user_id' => $by->id,
            'kind' => $kind, 'subject_key' => (string) $task->id, 'note' => $note !== null ? mb_substr(trim($note), 0, 2000) : null,
            'value_from' => $from, 'value_to' => $to, 'acted_at' => now(),
        ]));
    }
}
