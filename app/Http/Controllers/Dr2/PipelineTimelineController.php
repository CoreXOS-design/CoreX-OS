<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\PipelineUserPreference;
use App\Services\Deal\DealPipelineLockService;
use App\Services\Deal\Pipeline\PipelineRescheduleService;
use App\Services\Deal\Pipeline\PipelineTimelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pipeline Dashboard Phase 2 — the TIMELINE view (View 1): steps as duration bars on a horizontal time
 * axis, auto-stacked, with milestone gates, derived phase bands, a today line, and the activity lane.
 * Reads the Phase-1 foundation (planned_start_date, the event normalizer). Step actions reuse the
 * existing deals-dr2.pipeline.step.* routes; horizontal drag reschedules via PipelineRescheduleService.
 * QA1 only; a pure overlay — completing/rescheduling never changes DR1 deal state.
 */
class PipelineTimelineController extends Controller
{
    use \App\Http\Controllers\Dr2\Concerns\BuildsPipelineContext;

    public function __construct(
        private readonly PipelineTimelineService $timeline,
        private readonly PipelineRescheduleService $reschedule,
        private readonly DealPipelineLockService $lock,
    ) {
    }

    /** The timeline dashboard for a deal — deal-context tabs on top + the Gantt timeline. */
    public function show(Deal $deal): View
    {
        // Remember the agent's choice so this becomes their landing view.
        if ($uid = auth()->id()) {
            PipelineUserPreference::setViewForUser($uid, 'timeline');
        }

        // The same deal-context data (for the collapsible top tabs) + the approved-mockup board payload.
        $ctx = $this->pipelineContext($deal);
        $ctx['board'] = $this->timeline->buildBoard($deal);

        return view('dr2.pipeline-timeline', $ctx);
    }

    /**
     * Drag-to-reschedule. POST { new_start: 'YYYY-MM-DD', commit: bool }.
     * commit=false → a dry-run PREVIEW (moved + held lists) for the confirm dialog; commit=true applies.
     */
    public function reschedule(Request $request, Deal $deal, DealStepInstance $step): JsonResponse
    {
        abort_unless((int) $step->dr1_deal_id === (int) $deal->id, 404);

        $data = $request->validate([
            'new_start' => ['required', 'date'],
            'commit'    => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $this->reschedule->reschedule(
                $step,
                \Carbon\Carbon::parse($data['new_start']),
                (bool) ($data['commit'] ?? false),
                auth()->id(),
            );
        } catch (\App\Exceptions\Deal\PipelineLockedException $e) {
            return response()->json(['ok' => false, 'error' => 'This deal\'s pipeline is locked.'], 423);
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $result);
    }
}
