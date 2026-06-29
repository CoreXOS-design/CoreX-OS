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
     * Per-column hydration cap for the kanban board. A board renders one card
     * per task, so the page must never hydrate an unbounded result set — an
     * agency with thousands of open document-chase tasks would otherwise blow
     * PHP's per-request memory_limit before the view renders (root cause of the
     * /command-center/tasks 500 on staging, 18k todo tasks). The board shows
     * the highest-priority / soonest-due slice; deeper retrieval is the job of
     * the List view + filters, not the board.
     */
    public const KANBAN_COLUMN_LIMIT = 200;

    /**
     * Get tasks by status (for kanban board).
     *
     * Each column is queried and capped at the database level so total
     * hydration is bounded (≤ ~3×limit + 20 rows) regardless of table size.
     */
    public function getTasksByStatus(User $user): array
    {
        $column = function (string $status, bool $recentFirst = false) use ($user) {
            $query = $this->visibleQuery($user)
                ->where('status', $status)
                ->with(['property', 'contact', 'assignee']);

            if ($recentFirst) {
                // Done column: most-recently-completed first, small fixed cap.
                return $query->orderByDesc('completed_at')->limit(20)->get();
            }

            return $query
                ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->orderBy('due_date')
                ->limit(self::KANBAN_COLUMN_LIMIT)
                ->get();
        };

        return [
            'todo'        => $column(CommandTask::STATUS_TODO),
            'in_progress' => $column(CommandTask::STATUS_IN_PROGRESS),
            'awaiting'    => $column(CommandTask::STATUS_AWAITING),
            'done'        => $column(CommandTask::STATUS_DONE, true),
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
