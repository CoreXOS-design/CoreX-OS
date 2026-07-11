<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use App\Services\Deal\Dr1PipelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-216 (DR2 · WS-PIPELINE) — the pipeline experience on the DR2 register.
 *
 * PURE TRACKING OVERLAY (Johan-locked, option (a)): viewing/attaching a pipeline and
 * completing its steps NEVER changes the DR1 deal's state — only the pipeline's own step
 * rows and the deal's pipeline pointer. DR1 keeps modelling its lifecycle through
 * settlements. Kept separate from Dr2\DealRegisterController so it never collides with
 * AT-217's capture edits.
 */
class PipelineController extends Controller
{
    public function __construct(private readonly Dr1PipelineService $pipelines)
    {
    }

    /** A deal's pipeline board — steps with LIVE RAG, or the attach form when none is set. */
    public function show(Deal $deal): View
    {
        $deal->load('pipelineSteps');

        $steps = $deal->pipelineSteps->map(function (DealStepInstance $s) {
            $rag = $this->pipelines->calculateRag($s); // live, not the stored snapshot
            return [
                'model'   => $s,
                'rag'     => $rag,
                'colour'  => Dr1PipelineService::ragColour($rag),
                'blocked' => $s->blockedByLabel(),
            ];
        });

        // Templates are only offered when nothing is attached yet (single pipeline per deal).
        // The default pre-selection follows the deal's deal_type (m3's capture writes it):
        // deal_type → the agency's is_default template of that type — agency-configurable,
        // and the user can still change it in the attach form.
        $templates        = $deal->deal_pipeline_template_id ? collect() : $this->activeTemplates($deal);
        $defaultTemplateId = $deal->deal_pipeline_template_id ? null : optional($this->defaultTemplateFor($deal, $templates))->id;

        return view('dr2.pipeline', compact('deal', 'steps', 'templates', 'defaultTemplateId'));
    }

    /** Attach a template's pipeline to the deal (the service guards against double-attach). */
    public function attach(Deal $deal, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => ['nullable', 'integer'],
        ]);

        // Honour the user's pick (changeable at attach); if none, fall back to the
        // deal_type → agency-default template.
        $template = ! empty($data['template_id'])
            ? $this->activeTemplates($deal)->firstWhere('id', (int) $data['template_id'])
            : $this->defaultTemplateFor($deal);

        if (! $template) {
            return back()->with('error', 'That pipeline template is not available for this deal.');
        }

        try {
            $this->pipelines->createPipeline($deal, $template->id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('deals-dr2.pipeline', $deal)
            ->with('info', "Pipeline \"{$template->name}\" attached.");
    }

    /**
     * Mark a step complete — cascades to its ready successors (step-level only; the DR1
     * deal is never touched). Guards that the step belongs to THIS deal and is active.
     */
    public function completeStep(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        if ($step->status !== 'active') {
            return back()->with('error', 'Only an active step can be completed.');
        }

        $notes = trim((string) $request->input('notes', ''));
        $this->pipelines->completeStep($step, $request->user()?->id, $notes !== '' ? ['notes' => $notes] : []);

        return redirect()->route('deals-dr2.pipeline', $deal)
            ->with('info', "Step \"{$step->name}\" completed.");
    }

    /** This agency's active pipeline templates (is_default first). */
    private function activeTemplates(Deal $deal)
    {
        return DealPipelineTemplate::where('agency_id', $deal->agency_id)
            ->where('is_active', true)
            ->orderByDesc('is_default')->orderBy('name')
            ->get();
    }

    /**
     * The default template for a deal by its deal_type (m3's capture writes deal.deal_type):
     * the agency's is_default template OF THAT TYPE (agency-configurable). Falls back to any
     * template of that type, then any is_default, then the first — so attach never dead-ends.
     */
    private function defaultTemplateFor(Deal $deal, $templates = null): ?DealPipelineTemplate
    {
        $templates = $templates ?? $this->activeTemplates($deal);

        return $templates->first(fn ($t) => $t->deal_type === $deal->deal_type && $t->is_default)
            ?? $templates->first(fn ($t) => $t->deal_type === $deal->deal_type)
            ?? $templates->first(fn ($t) => (bool) $t->is_default)
            ?? $templates->first();
    }
}
