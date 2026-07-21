<?php

namespace App\Http\Controllers\CommandCenter;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CommandTask;
use App\Services\CommandCenter\TaskService;
use App\Services\PermissionService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    protected TaskService $service;

    public function __construct(TaskService $service)
    {
        $this->service = $service;
    }

    /**
     * AT-267 H1 — per-record guard. update/destroy/complete/updateStatus bound a task by id with no
     * owner check → any user could edit/delete/reassign any task in the agency. Gate through the same
     * scope the list uses; an assistant is clamped to 'own' (the assigned agent's task list).
     */
    private function authorizeTask(CommandTask $task): void
    {
        $user  = $task ? auth()->user() : null;
        $scope = $user?->is_assistant ? 'own' : PermissionService::taskScope($user);
        abort_unless(CommandTask::visibleTo($user, $scope)->whereKey($task->getKey())->exists(), 403);
    }

    /**
     * Task board page (kanban + list).
     */
    public function index(Request $request)
    {
        $user     = $request->user();
        $view     = $request->get('view', 'kanban');
        $columns  = $this->service->getTasksByStatus($user);
        $summary  = $this->service->getSummary($user);

        return view('command-center.tasks.index', [
            'user'    => $user,
            'columns' => $columns,
            'summary' => $summary,
            'currentView' => $view,
        ]);
    }

    /**
     * Store a new task.
     */
    public function store(Request $request)
    {
        $request->validate([
            'title'       => 'required|string|max:255',
            'task_type'   => 'nullable|string|max:50',
            'priority'       => 'nullable|in:low,normal,high,critical',
            'due_date'       => 'nullable|date',
            'description'    => 'nullable|string',
            'assigned_to'    => 'nullable|exists:users,id',
            'property_id'    => 'nullable|exists:properties,id',
            'contact_id'     => 'nullable|exists:contacts,id',
            'send_reminder'  => 'nullable|boolean',
        ]);

        $data = $request->all();
        // AT-267 — an assistant's task is filed as the AGENT (ownershipUserId), never assigned to the
        // assistant or a caller-supplied user; everyone else keeps the existing "default to self".
        $data['assigned_to']    = $request->user()->is_assistant
            ? $request->user()->ownershipUserId()
            : ($data['assigned_to'] ?? $request->user()->id);
        $data['task_type']      = $data['task_type'] ?? 'custom';
        $data['send_reminder']  = $request->boolean('send_reminder');

        $task = $this->service->create($data, $request->user());

        if ($request->wantsJson()) {
            return response()->json($task, 201);
        }

        return back()->with('success', 'Task created.');
    }

    /**
     * Update a task.
     */
    public function update(Request $request, CommandTask $task)
    {
        $this->authorizeTask($task);
        $request->validate([
            'title'    => 'sometimes|required|string|max:255',
            'status'   => 'nullable|in:todo,in_progress,awaiting,done,dismissed',
            'priority' => 'nullable|in:low,normal,high,critical',
            'due_date' => 'nullable|date',
        ]);

        if ($request->has('status') && count($request->all()) === 1) {
            $task = $this->service->updateStatus($task, $request->status);
        } else {
            $task = $this->service->update($task, $request->all());
        }

        if ($request->wantsJson()) {
            return response()->json($task);
        }

        return back()->with('success', 'Task updated.');
    }

    /**
     * Soft-delete a task.
     */
    public function destroy(Request $request, CommandTask $task)
    {
        $this->authorizeTask($task);
        $this->service->delete($task);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Task removed.');
    }

    /**
     * Quick-complete a task (from dashboard checkbox).
     */
    public function complete(CommandTask $task)
    {
        $this->authorizeTask($task);
        // Missed-feedback tasks: redirect to calendar feedback modal instead of just marking done
        if ($task->source_type === 'calendar:missed_feedback' && $task->calendar_event_id) {
            return redirect()
                ->route('command-center.calendar', ['view' => 'day', 'capture_feedback' => $task->calendar_event_id])
                ->with('info', 'Capture feedback to complete this task.');
        }

        $task->markDone();

        if (request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Task completed.');
    }

    /**
     * Update task status via AJAX (for drag-and-drop) or form POST (card buttons).
     */
    public function updateStatus(Request $request, CommandTask $task)
    {
        $this->authorizeTask($task);
        $request->validate(['status' => 'required|in:todo,in_progress,awaiting,done,dismissed']);

        $task = $this->service->updateStatus($task, $request->status);

        if ($request->wantsJson()) {
            return response()->json($task);
        }

        return back()->with('success', 'Task moved to ' . str_replace('_', ' ', $task->status) . '.');
    }

    /**
     * Archive all Done tasks for the user — soft-delete them off the board.
     */
    public function archiveDone(Request $request)
    {
        $count = CommandTask::forUser($request->user()->id)
            ->where('status', CommandTask::STATUS_DONE)
            ->get()
            ->each(fn ($t) => $t->delete())
            ->count();

        return back()->with('success', "Archived {$count} done task(s).");
    }

    /**
     * Archived tasks view — soft-deleted tasks grouped by the day they were archived.
     */
    public function archived(Request $request)
    {
        $user = $request->user();

        $tasks = CommandTask::onlyTrashed()
            ->visibleTo($user, PermissionService::taskScope($user))
            ->with(['property', 'contact'])
            ->orderByDesc('deleted_at')
            ->get();

        $grouped = $tasks->groupBy(fn ($t) => optional($t->deleted_at)->toDateString());

        return view('command-center.tasks.archived', [
            'user'    => $user,
            'grouped' => $grouped,
            'total'   => $tasks->count(),
        ]);
    }

    /**
     * Restore a soft-deleted task back to the Done column.
     */
    public function restore(int $taskId)
    {
        $task = CommandTask::onlyTrashed()->findOrFail($taskId);
        $task->restore();

        return back()->with('success', 'Task restored to Done column.');
    }
}
