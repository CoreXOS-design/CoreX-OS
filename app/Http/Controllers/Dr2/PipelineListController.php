<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dr2\Concerns\BuildsPipelineContext;
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
 * Pipeline Dashboard — the LIST view (View 2): the deal-context tabs ON TOP, then the SAME step CARDS
 * as the board (dr2._pipeline-step-tile) stacked vertically, with grab-to-reorder (DISPLAY position
 * ONLY — never rewires dependencies or dates) + sequence-click + inline edit + the full action set.
 * QA1 only; a pure overlay — nothing here changes DR1 deal state.
 */
class PipelineListController extends Controller
{
    use BuildsPipelineContext;

    public function __construct(
        private readonly Dr1PipelineService $pipelines,
        private readonly DealPipelineLockService $lock,
        private readonly PipelineEventService $events,
        private readonly \App\Services\Deal\Pipeline\PipelineTimelineService $timeline,
    ) {
    }

    public function show(Deal $deal): View
    {
        if ($uid = auth()->id()) {
            PipelineUserPreference::setViewForUser($uid, 'list');
        }

        $ctx = $this->pipelineContext($deal);
        // The PHASED read-model — anchor → Stage 1 condition groups → GRANTED gate → Stage 2, plus the
        // step-scoped comment feed (each comment carries its step id). The List renders the phased
        // two-panel layout off this (spec "LIST + PROGRESSION build 2026-07-28").
        $ctx['board'] = $this->timeline->buildPhased($deal);

        return view('dr2.pipeline-list', $ctx);
    }

    /**
     * Grab-to-reorder — persists DISPLAY position ONLY. It writes nothing but `position`, so
     * dependencies (trigger_step_instance_id, the deps table) and every date (planned_start_date,
     * due_date) are untouched — the dependency graph stays the one scheduling truth.
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
     * Inline Edit-dates — set the step's planned START and END explicitly. Both are pinned (manual
     * flags) so re-projection/activation won't clobber them. End must not precede start. Never touches
     * dependencies. (The board's "Edit due" only sets the due date; this list control sets both.)
     *
     * This is currently the Timeline's edge-resize + Set-dates modal endpoint (the List view has no
     * inline date editor wired up yet) — the ONLY caller today is pipeline-timeline.blade.php's
     * postDates()/dr2tlSaveDates(). Production-line rule (Johan): a date change re-anchors downstream
     * steps, same as complete/reopen/N-A already do (PipelineController::reopenStep() etc.). Recompute
     * runs AFTER the save and is itself guarded (DealDateCascade::recompute() never overwrites a
     * due_date_manual step — see the final write loop there) — so the just-edited step's manual pin
     * from above is untouched; only its downstream non-manual successors move.
     */
    public function editDates(Request $request, Deal $deal, DealStepInstance $step, \App\Services\DealV2\DealDateCascade $cascade): RedirectResponse
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

        $cascade->recompute($deal);

        return redirect()->route('deals-dr2.pipeline.list', $deal)
            ->with('info', "Dates updated for \"{$step->name}\".");
    }
}
