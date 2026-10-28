<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;
use App\Models\RoadToSold\RtsTaskMappingAuditEntry;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Facades\DB;

/**
 * Road to Sold - which step each task sits in and what credits it, as the agency sees it (road-to-sold.md 22.24).
 *
 * A task's "mapping" is its step, its event binding (key + filter) and whether anyone has chosen a step for it yet (needs_step). The
 * mapping the task STARTED on is kept on the task (rts_tasks.mapping_default) so an admin can put it back, and every change or restore is
 * one append-only audit row (rts_task_mapping_audit). Nothing here rewrites history: past completions are untouched.
 */
class TaskMappingService
{
    /** @return array{step_id:int,event_key:?string,event_filter:?array,needs_step:bool} */
    public function snapshot(RtsTask $task): array
    {
        return [
            'step_id'      => (int) $task->step_id,
            'event_key'    => $task->event_key ?: null,
            'event_filter' => $task->event_filter ?: null,
            'needs_step'   => (bool) $task->needs_step,
        ];
    }

    /** What is stored as a task's default: the snapshot without empty keys (so SQL can compare a missing key with NULL), plus the reason. */
    public function defaultPayload(array $snapshot, ?string $note = null): array
    {
        return array_filter($snapshot + ['note' => $note], fn ($v, $k) => $k === 'step_id' || $k === 'needs_step' || ($v !== null && $v !== []), ARRAY_FILTER_USE_BOTH);
    }

    /** Has the task moved off the mapping it started on? A task with no recorded default is never "changed". */
    public function isChanged(RtsTask $task): bool
    {
        $d = $task->mapping_default;
        if (! is_array($d) || ! isset($d['step_id'])) {
            return false;
        }

        return $this->snapshot($task) !== $this->normalise($d);
    }

    public function hasDefault(RtsTask $task): bool
    {
        return is_array($task->mapping_default) && isset($task->mapping_default['step_id']);
    }

    /** Write the audit row for a save that changed the mapping (no row when nothing it covers changed). */
    public function recordChange(RtsTask $task, array $before, ?int $userId): void
    {
        $after = $this->snapshot($task);
        if ($after === $before) {
            return;
        }
        $this->audit($task, 'changed', $userId, $before, $after, null);
    }

    /** Remember the mapping the task starts on (and why), without changing the task. */
    public function recordDefault(RtsTask $task, ?string $note = null, ?int $userId = null): void
    {
        $task->forceFill(['mapping_default' => $this->defaultPayload($this->snapshot($task), $note)])->save();
        $this->audit($task, 'default_set', $userId, null, $this->snapshot($task), $note);
    }

    /** Put the task back on the mapping it started on. False when it has no recorded default. */
    public function restoreDefault(RtsTask $task, ?int $userId): bool
    {
        if (! $this->hasDefault($task)) {
            return false;
        }
        $before = $this->snapshot($task);
        $d = $this->normalise($task->mapping_default);

        $stepOk = RtsStep::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
            ->where('agency_id', $task->agency_id)->where('id', $d['step_id'])->exists();
        if (! $stepOk) {
            return false;   // the default step no longer exists in this agency
        }

        $task->forceFill(['step_id' => $d['step_id'], 'event_key' => $d['event_key'], 'event_filter' => $d['event_filter'], 'needs_step' => $d['needs_step']])->save();
        if ($before !== $this->snapshot($task)) {
            $this->audit($task, 'restored', $userId, $before, $this->snapshot($task), null);
        }

        return true;
    }

    /**
     * Apply a set of decided mappings for one agency (the decisions file) and record every task's starting mapping.
     *
     * Each decision: task (name), step (a step key) and/or needs_step, note. A decision moves the task, then its default is recorded as the
     * decided mapping. Tasks without a decision get their CURRENT mapping recorded as their default (only when none is recorded yet).
     * Idempotent: running it again changes nothing.
     *
     * @param  array<int,array{task:string,step?:string,needs_step?:bool,note?:string}>  $decisions
     * @return array<int,array{task:string,result:string}>
     */
    public function applyDecisions(int $agencyId, string $track, array $decisions, bool $dry = false, ?int $userId = null): array
    {
        $steps = RtsStep::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $track)->pluck('id', 'key');
        $tasks = RtsTask::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $track)->get();
        $byLabel = $tasks->groupBy(fn ($t) => mb_strtolower(trim($t->label)));
        $out = [];
        $decided = [];

        foreach ($decisions as $d) {
            $found = $byLabel->get(mb_strtolower(trim($d['task'])), collect());
            if ($found->isEmpty()) {
                $out[] = ['task' => $d['task'], 'result' => 'not in this agency - skipped'];
                continue;
            }
            $stepId = isset($d['step']) ? ($steps[$d['step']] ?? null) : null;
            if (isset($d['step']) && $stepId === null) {
                $out[] = ['task' => $d['task'], 'result' => "this agency has no step '{$d['step']}'"];
                continue;
            }
            foreach ($found as $task) {
                $decided[$task->id] = true;
                $want = ['step_id' => $stepId ?: (int) $task->step_id, 'needs_step' => (bool) ($d['needs_step'] ?? false)];
                $already = $this->hasDefault($task) && (int) $task->step_id === $want['step_id'] && (bool) $task->needs_step === $want['needs_step']
                    && ($task->mapping_default['note'] ?? null) === ($d['note'] ?? null);
                if ($already) {
                    $out[] = ['task' => $task->label, 'result' => 'already set'];
                    continue;
                }
                $out[] = ['task' => $task->label, 'result' => ($dry ? 'would set: ' : 'set: ') . ($stepId ? "step {$d['step']}" : 'step unchanged') . (($d['needs_step'] ?? false) ? ', not mapped - choose a step' : '')];
                if ($dry) {
                    continue;
                }
                DB::transaction(function () use ($task, $want, $d, $userId) {
                    $before = $this->snapshot($task);
                    $task->forceFill(['step_id' => $want['step_id'], 'needs_step' => $want['needs_step']])->save();
                    if ($before !== $this->snapshot($task)) {
                        $this->audit($task, 'changed', $userId, $before, $this->snapshot($task), $d['note'] ?? null);
                    }
                    $task->forceFill(['mapping_default' => $this->defaultPayload($this->snapshot($task), $d['note'] ?? null)])->save();
                });
            }
        }

        $recorded = 0;
        foreach ($tasks as $task) {
            if (isset($decided[$task->id]) || $this->hasDefault($task)) {
                continue;
            }
            $recorded++;
            if (! $dry) {
                $task->forceFill(['mapping_default' => $this->defaultPayload($this->snapshot($task))])->save();
            }
        }
        if ($recorded > 0) {
            $out[] = ['task' => '(' . $recorded . ' other tasks)', 'result' => ($dry ? 'would record' : 'recorded') . ' their current step as their default'];
        }

        return $out;
    }

    /** @param array<string,mixed> $d @return array{step_id:int,event_key:?string,event_filter:?array,needs_step:bool} */
    private function normalise(array $d): array
    {
        return [
            'step_id'      => (int) $d['step_id'],
            'event_key'    => ($d['event_key'] ?? null) ?: null,
            'event_filter' => ($d['event_filter'] ?? null) ?: null,
            'needs_step'   => (bool) ($d['needs_step'] ?? false),
        ];
    }

    private function audit(RtsTask $task, string $action, ?int $userId, ?array $old, ?array $new, ?string $note): void
    {
        RtsTaskMappingAuditEntry::withoutGlobalScopes()->create([
            'agency_id' => $task->agency_id, 'track' => $task->track, 'task_id' => $task->id, 'task_label' => $task->label,
            'action' => $action, 'changed_by_user_id' => $userId, 'old_values' => $old, 'new_values' => $new, 'note' => $note, 'changed_at' => now(),
        ]);
    }
}
