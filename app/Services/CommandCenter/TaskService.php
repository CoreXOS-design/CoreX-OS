<?php

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\CommandTask;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Collection;

class TaskService
{
    /**
     * Base query narrowed to what this user's role may see
     * (command_center.tasks.view → own | branch | all).
     */
    private function visibleQuery(User $user)
    {
        return CommandTask::query()->visibleTo($user, PermissionService::taskScope($user));
    }

    /**
     * Create a task.
     */
    public function create(array $data, ?User $assignedBy = null): CommandTask
    {
        return CommandTask::create(array_merge($data, [
            'assigned_by' => $assignedBy?->id ?? $data['assigned_by'] ?? null,
            'status'      => $data['status'] ?? CommandTask::STATUS_TODO,
        ]));
    }

    /**
     * Get open tasks for a user, ordered by priority then due date.
     */
    public function getOpenTasks(User $user, int $limit = 20): Collection
    {
        return $this->visibleQuery($user)
            ->open()
            ->with(['property', 'contact'])
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('due_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Get overdue tasks for a user.
     */
    public function getOverdueTasks(User $user, int $limit = 10): Collection
    {
        return $this->visibleQuery($user)
            ->overdue()
            ->with(['property', 'contact'])
            ->orderBy('due_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Get tasks by status (for kanban board).
     */
    public function getTasksByStatus(User $user): array
    {
        $tasks = $this->visibleQuery($user)
            ->whereNotIn('status', [CommandTask::STATUS_DISMISSED])
            ->with(['property', 'contact', 'assignee'])
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderBy('due_date')
            ->get();

        return [
            'todo'        => $tasks->where('status', CommandTask::STATUS_TODO)->values(),
            'in_progress' => $tasks->where('status', CommandTask::STATUS_IN_PROGRESS)->values(),
            'awaiting'    => $tasks->where('status', CommandTask::STATUS_AWAITING)->values(),
            'done'        => $tasks->where('status', CommandTask::STATUS_DONE)
                                   ->sortByDesc('completed_at')
                                   ->take(20)
                                   ->values(),
        ];
    }

    /**
     * Get task counts for dashboard summary.
     */
    public function getSummary(User $user): array
    {
        $base = $this->visibleQuery($user);

        return [
            'today'    => (clone $base)->dueToday()->count(),
            'overdue'  => (clone $base)->overdue()->count(),
            'thisWeek' => (clone $base)->thisWeek()->count(),
            'open'     => (clone $base)->open()->count(),
        ];
    }

    /**
     * Update task status.
     */
    public function updateStatus(CommandTask $task, string $status): CommandTask
    {
        $updates = ['status' => $status];

        if ($status === CommandTask::STATUS_IN_PROGRESS && !$task->started_at) {
            $updates['started_at'] = now();
        }

        if ($status === CommandTask::STATUS_DONE) {
            $updates['completed_at'] = now();
        }

        $task->update($updates);
        return $task->fresh();
    }

    /**
     * Update a task.
     */
    public function update(CommandTask $task, array $data): CommandTask
    {
        $task->update($data);
        return $task->fresh();
    }

    /**
     * Soft-delete a task.
     */
    public function delete(CommandTask $task): void
    {
        $task->delete();
    }
}
