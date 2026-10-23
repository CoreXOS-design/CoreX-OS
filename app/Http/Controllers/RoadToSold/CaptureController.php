<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsTaskCompletion;
use App\Models\User;
use App\Services\RoadToSold\TaskCaptureService;
use App\Services\RoadToSold\TaskCompletionService;
use App\Services\RoadToSold\Track;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * AT-454 v2 phase 2 - save, change, archive and restore a captured task (road-to-sold.md sections 5.1, 5.3, 10.4).
 *
 * A capture is always for the signed-in person's own list: an agent's, or the agent an assistant works for - never an id from the
 * request. Changing, archiving and restoring act only on a completion inside the viewer's breadth (own / branch / agency, applied in
 * the query); an id outside it is a 404. Nothing is hard deleted.
 */
class CaptureController extends Controller
{
    public function __construct(
        private readonly TaskCaptureService $capture,
        private readonly TaskCompletionService $completions,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        $agent = User::query()->find($actor->ownershipUserId());
        abort_unless($agent && (int) $agent->effectiveAgencyId() === (int) $actor->effectiveAgencyId(), 403);

        $result = $this->capture->capture($actor, $agent, $request->only(['task_id', 'token', 'area_label', 'occurred_at', 'note', 'quantity', 'properties', 'contacts']) + ['photos' => $request->file('photos', [])], Track::fromRequest($request));

        if ($result['duplicate']) {
            return back()->with('status', 'That capture was already saved.');
        }

        return back()->with('status', $this->doneMessage($result));
    }

    public function update(Request $request, int $completion): RedirectResponse
    {
        $row = $this->find($request, $completion);
        $this->capture->updateNote($row, $request->input('note'));

        return back()->with('status', 'Note saved.');
    }

    public function archive(Request $request, int $completion): RedirectResponse
    {
        $row = $this->find($request, $completion);
        $this->capture->archive($row);

        return back()->with('status', 'Archived. It no longer counts toward your targets; restore it from the Archived list.');
    }

    public function restore(Request $request, int $completion): RedirectResponse
    {
        $row = $this->find($request, $completion);
        $this->capture->restore($row);

        return back()->with('status', 'Restored. It counts toward your targets again.');
    }

    /** One photo of a capture's evidence, only to someone who may see the capture. */
    public function photo(Request $request, int $completion, int $n): StreamedResponse
    {
        $row = $this->find($request, $completion);
        $path = $row->evidence['photos'][$n] ?? null;
        abort_unless($path && str_starts_with($path, 'rts-evidence/' . $row->agency_id . '/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    private function find(Request $request, int $id): RtsTaskCompletion
    {
        return $this->completions->visibleTo($request->user())->withTrashed()->whereKey($id)->firstOrFail();
    }

    /** @param array{completion:?RtsTaskCompletion,properties:int,contacts:int,listed:int,created:int} $r */
    private function doneMessage(array $r): string
    {
        $parts = [];
        if ($r['properties'] > 0) {
            $parts[] = $r['properties'] . ($r['properties'] === 1 ? ' property' : ' properties') . ($r['created'] > 0 ? " ({$r['created']} new)" : ' (already known)');
        }
        if ($r['contacts'] > 0) {
            $parts[] = $r['contacts'] . ($r['contacts'] === 1 ? ' person' : ' people');
        }
        $m = 'Saved' . ($parts ? ': ' . implode(' and ', $parts) . ' recorded' : '') . '.';
        if ($r['listed'] > 0) {
            $m .= ' ' . $r['listed'] . ' added to your list.';
        }

        return $m;
    }
}
