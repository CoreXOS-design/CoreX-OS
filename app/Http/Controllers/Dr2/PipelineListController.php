<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\PipelineUserPreference;
use App\Services\Deal\DealPipelineLockService;
use App\Services\Deal\Dr1PipelineService;
use App\Services\Deal\Pipeline\PipelineEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Pipeline Dashboard Phase 3 — the LIST view (View 2): the same steps + activity as the timeline, as a
 * vertical list with grab-to-reorder (DISPLAY position ONLY — never rewires dependencies or dates,
 * decision 4), sequence-click, inline Edit-dates (start + end), and the full action set (Complete /
 * Edit dates / Sequence / N-A / Remove / Comment). Actions reuse the board routes with ?from=list.
 * QA1 only; a pure overlay — nothing here changes DR1 deal state.
 */
class PipelineListController extends Controller
{
    public function __construct(
        private readonly Dr1PipelineService $pipelines,
        private readonly DealPipelineLockService $lock,
        private readonly PipelineEventService $events,
    ) {
    }

    public function show(Deal $deal): View
    {
        $deal->load(['pipelineSteps.comments.user']);

        if ($uid = auth()->id()) {
            PipelineUserPreference::setViewForUser($uid, 'list');
        }

        $rows = $deal->pipelineSteps
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values()
            ->map(function (DealStepInstance $s) {
                $terminal = in_array($s->status, ['completed', 'skipped'], true);
                $rag = $terminal ? 'grey' : $this->pipelines->calculateRag($s);
                return [
                    'model'    => $s,
                    'rag'      => $rag,
                    'colour'   => Dr1PipelineService::ragColour($rag),
                    'blocked'  => $s->blockedByLabel(),
                    'na'       => $s->status === 'skipped' && ! empty($s->na_reason),
                    'duration' => $s->duration_days,
                ];
            });

        // Same normalized activity as the timeline (comments now; email/WhatsApp later), newest first.
        $activity = $this->events->eventsForDeal($deal)->reverse()->values();

        $removedSteps = DealStepInstance::onlyTrashed()
            ->where('dr1_deal_id', $deal->id)
            ->orderBy('position')->orderBy('id')->get();

        $locked = $this->lock->isLocked($deal);

        return view('dr2.pipeline-list', [
            'deal'         => $deal,
            'rows'         => $rows,
            'activity'     => $activity,
            'removedSteps' => $removedSteps,
            'locked'       => $locked,
            'lockReason'   => $locked ? $this->lock->reason($deal) : null,
            'unlockHint'   => $locked ? $this->lock->unlockHint() : null,
        ]);
    }

    /**
     * Grab-to-reorder — persists DISPLAY position ONLY. It writes nothing but `position`, so
     * dependencies (trigger_step_instance_id, the deps table) and every date (planned_start_date,
     * due_date) are untouched — the dependency graph stays the one scheduling truth (decision 4).
     */
    public function reorder(Request $request, Deal $deal): JsonResponse
    {
        try {
            $this->lock->assertUnlocked($deal, 'Reorder pipeline steps');
        } catch (\App\Exceptions\Deal\PipelineLockedException $e) {
            return response()->json(['ok' => false, 'error' => 'This deal\'s pipeline is locked.'], 423);
        }

        $data = $request->validate([
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['integer'],
        ]);

        // Only ids that actually belong to this deal (guards against foreign/forged ids).
        $ownIds = DealStepInstance::where('dr1_deal_id', $deal->id)
            ->whereIn('id', $data['order'])->pluck('id')->all();
        $ordered = array_values(array_filter($data['order'], fn ($id) => in_array((int) $id, $ownIds, true)));

        DB::transaction(function () use ($ordered, $deal) {
            foreach ($ordered as $i => $id) {
                DealStepInstance::where('id', $id)
                    ->where('dr1_deal_id', $deal->id)
                    ->update(['position' => $i + 1]); // position ONLY — no deps, no dates
            }
        });

        return response()->json(['ok' => true, 'count' => count($ordered)]);
    }

    /**
     * Inline Edit-dates — set the step's planned START and END explicitly (decision 2: duration is
     * editable via Edit-dates). Both are pinned (manual flags) so re-projection/activation won't
     * clobber them. End must not precede start. Never touches dependencies.
     */
    public function editDates(Request $request, Deal $deal, DealStepInstance $step): RedirectResponse
    {
        abort_unless((int) $step->dr1_deal_id === (int) $deal->id, 404);
        $this->lock->assertStepUnlocked($step, "Edit dates for \"{$step->name}\"");

        $data = $request->validate([
            'planned_start_date' => ['required', 'date'],
            'due_date'           => ['required', 'date', 'after_or_equal:planned_start_date'],
        ]);

        $step->forceFill([
            'planned_start_date'   => $data['planned_start_date'],
            'planned_start_manual' => true,
            'due_date'             => $data['due_date'],
            'due_date_manual'      => true,
            'current_rag'          => $this->pipelines->calculateRag($step, \Carbon\Carbon::parse($data['due_date'])),
        ])->save();

        return redirect()->route('deals-dr2.pipeline.list', $deal)
            ->with('info', "Dates updated for \"{$step->name}\".");
    }
}
