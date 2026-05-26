<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CommandTask;
use App\Models\CommandCenter\CommandTaskNote;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CommandTaskNotesController extends Controller
{
    private function authorizeTask(Request $request, CommandTask $task): void
    {
        $user = $request->user();
        abort_unless($user, 401);
        if ($task->assigned_to !== $user->id && $task->assigned_by !== $user->id) {
            abort_unless((int) $task->agency_id === (int) ($user->effectiveAgencyId() ?? 0), 403);
        }
    }

    // ─── Notes ────────────────────────────────────────────────

    public function index(Request $request, CommandTask $task)
    {
        $this->authorizeTask($request, $task);

        $notes = $task->notes()->with('user:id,name')->get()->map(fn ($n) => [
            'id'         => $n->id,
            'body'       => $n->body,
            'user_id'    => $n->user_id,
            'user_name'  => $n->user?->name,
            'created_at' => $n->created_at?->toIso8601String(),
            'updated_at' => $n->updated_at?->toIso8601String(),
        ]);

        return response()->json(['notes' => $notes]);
    }

    public function store(Request $request, CommandTask $task)
    {
        $this->authorizeTask($request, $task);

        $data = $request->validate([
            'body' => 'required|string|max:10000',
        ]);

        $note = $task->notes()->create([
            'user_id'   => $request->user()->id,
            'body'      => $data['body'],
            'agency_id' => $task->agency_id,
        ]);

        $note->load('user:id,name');

        return response()->json([
            'id'         => $note->id,
            'body'       => $note->body,
            'user_id'    => $note->user_id,
            'user_name'  => $note->user?->name,
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ], 201);
    }

    public function update(Request $request, CommandTask $task, CommandTaskNote $note)
    {
        $this->authorizeTask($request, $task);
        abort_unless($note->command_task_id === $task->id, 404);
        abort_unless($note->user_id === $request->user()->id, 403);

        $data = $request->validate(['body' => 'required|string|max:10000']);
        $note->update($data);

        return response()->json([
            'id'         => $note->id,
            'body'       => $note->body,
            'user_id'    => $note->user_id,
            'updated_at' => $note->updated_at?->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, CommandTask $task, CommandTaskNote $note)
    {
        $this->authorizeTask($request, $task);
        abort_unless($note->command_task_id === $task->id, 404);
        abort_unless($note->user_id === $request->user()->id, 403);

        $note->delete();
        return response()->json(['ok' => true]);
    }

    // ─── Checklist ────────────────────────────────────────────
    // Stored on command_tasks.checklist as array of { id, text, done }

    public function checklistIndex(Request $request, CommandTask $task)
    {
        $this->authorizeTask($request, $task);
        return response()->json(['items' => $task->checklist ?? []]);
    }

    public function checklistStore(Request $request, CommandTask $task)
    {
        $this->authorizeTask($request, $task);
        $data = $request->validate(['text' => 'required|string|max:500']);

        $items = $task->checklist ?? [];
        $item = [
            'id'   => (string) Str::uuid(),
            'text' => $data['text'],
            'done' => false,
        ];
        $items[] = $item;
        $task->update(['checklist' => $items]);

        return response()->json($item, 201);
    }

    public function checklistUpdate(Request $request, CommandTask $task, string $itemId)
    {
        $this->authorizeTask($request, $task);
        $data = $request->validate([
            'text' => 'sometimes|string|max:500',
            'done' => 'sometimes|boolean',
        ]);

        $items = $task->checklist ?? [];
        $found = false;
        foreach ($items as &$it) {
            if (($it['id'] ?? null) === $itemId) {
                if (array_key_exists('text', $data)) $it['text'] = $data['text'];
                if (array_key_exists('done', $data)) $it['done'] = (bool) $data['done'];
                $found = true;
                $updated = $it;
                break;
            }
        }
        unset($it);
        abort_unless($found, 404);

        $task->update(['checklist' => $items]);
        return response()->json($updated);
    }

    public function checklistDestroy(Request $request, CommandTask $task, string $itemId)
    {
        $this->authorizeTask($request, $task);

        $items = collect($task->checklist ?? [])
            ->reject(fn ($it) => ($it['id'] ?? null) === $itemId)
            ->values()
            ->all();

        $task->update(['checklist' => $items]);
        return response()->json(['ok' => true]);
    }
}
