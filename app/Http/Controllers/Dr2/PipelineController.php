<?php

namespace App\Http\Controllers\Dr2;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepComment;
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
    public function __construct(
        private readonly Dr1PipelineService $pipelines,
        private readonly \App\Services\Deal\DealPipelineLockService $lock,
    ) {
    }

    /** A deal's pipeline board — steps with LIVE RAG, or the attach form when none is set. */
    public function show(Deal $deal): View
    {
        $deal->load(['pipelineSteps.comments.user']);

        $steps = $deal->pipelineSteps->map(function (DealStepInstance $s) {
            $terminal = in_array($s->status, ['completed', 'skipped'], true);
            $rag = $terminal ? 'grey' : $this->pipelines->calculateRag($s); // live, not the stored snapshot
            return [
                'model'   => $s,
                'rag'     => $rag,
                'colour'  => Dr1PipelineService::ragColour($rag),
                'blocked' => $s->blockedByLabel(),
                'na'      => $s->status === 'skipped' && ! empty($s->na_reason),
            ];
        });

        // Templates are only offered when nothing is attached yet (single pipeline per deal).
        // The default pre-selection follows the deal's deal_type (m3's capture writes it):
        // deal_type → the agency's is_default template of that type — agency-configurable,
        // and the user can still change it in the attach form.
        $templates        = $deal->deal_pipeline_template_id ? collect() : $this->activeTemplates($deal);
        $defaultTemplateId = $deal->deal_pipeline_template_id ? null : optional($this->defaultTemplateFor($deal, $templates))->id;

        // R2 — soft-deleted steps, so they can be restored (nobody strands a pipeline).
        $removedSteps = DealStepInstance::onlyTrashed()
            ->where('dr1_deal_id', $deal->id)
            ->orderBy('position')->orderBy('id')
            ->get();

        // AT-244 — a not-proceeding (Declined) deal renders its pipeline READ-ONLY: it stays
        // visible as history, but every action is withdrawn. The lock is stated, never silent,
        // and it carries its own way out (STANDARDS — "No Silent Locks").
        $locked      = $this->lock->isLocked($deal);
        $lockReason  = $locked ? $this->lock->reason($deal) : null;
        $unlockHint  = $locked ? $this->lock->unlockHint() : null;

        // AT-334 — Deal Structure tab: the composable-condition picker.
        $conditionCatalog = app(\App\Services\DealV2\Dr2ConditionCatalog::class)->conditions();
        $dealConditions   = \App\Models\DealV2\DealCondition::where('deal_id', $deal->id)->get()->keyBy('key');
        $hasPipeline      = $steps->isNotEmpty();
        // AT-334 Phase 5 — the per-step "Follows + offset" control only applies to
        // composable (new-model) deals; old-model/template deals never show it.
        $isNewModel       = app(\App\Services\DealV2\DealDateCascade::class)->isNewModel($deal);

        // AT-334 Phase 5b — new-model deals render as a PHASED / GROUPED board (anchor →
        // Stage 1 condition groups → Granted gate → Stage 2 transfer). Classification is
        // 100% field-driven (see DealStepPhaseGrouper). Old-model deals keep the flat render.
        $phases = ($isNewModel && $steps->isNotEmpty())
            ? app(\App\Services\DealV2\DealStepPhaseGrouper::class)->group($deal->pipelineSteps)
            : null;

        // AT-334 P3 — work orders held at the trigger for want of a supplier. Drives the RED
        // warnings: the Supplier Work Orders tab turns red, and each held WO's own step row
        // turns red with "no supplier has been set".
        $awaitingWos      = \App\Models\DealV2\DealStepWorkOrder::where('dr1_deal_id', $deal->id)
            ->where('status', 'awaiting_supplier')->get();
        $woNeedsAttention = $awaitingWos->isNotEmpty();
        $awaitingStepIds  = $awaitingWos->pluck('deal_step_instance_id')
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        return view('dr2.pipeline', compact(
            'deal', 'steps', 'templates', 'defaultTemplateId', 'removedSteps',
            'locked', 'lockReason', 'unlockHint',
            'conditionCatalog', 'dealConditions', 'hasPipeline', 'isNewModel', 'phases',
            'woNeedsAttention', 'awaitingStepIds',
        ));
    }

    /**
     * AT-334 — build (or, later, restructure) a deal's pipeline from the chosen
     * suspensive conditions. New-model path; the assembler refuses if a pipeline
     * already exists (Restructure is a later phase).
     */
    public function saveStructure(Deal $deal, Request $request, \App\Services\DealV2\DealStructureAssembler $assembler): RedirectResponse
    {
        abort_unless($request->user()?->hasPermission('create_deals'), 403);
        if ($this->lock->isLocked($deal)) {
            return back()->with('error', 'This pipeline is locked and cannot be structured.');
        }

        $catalog    = app(\App\Services\DealV2\Dr2ConditionCatalog::class);
        $in         = (array) $request->input('conditions', []);
        $selections = [];
        foreach (array_keys($catalog->conditions()) as $key) {
            if (empty($in[$key]['on'])) {
                continue;
            }
            $opts = [];
            if ($key === 'bond') {
                $opts['deposit'] = ! empty($in[$key]['deposit']);
            }
            if ($key === 'cash') {
                $opts['payments'] = max(1, min(6, (int) ($in[$key]['payments'] ?? 1)));
            }
            $selections[$key] = $opts;
        }

        if (empty($selections)) {
            return back()->with('error', 'Pick at least one suspensive condition to build the pipeline.');
        }

        try {
            $assembler->assemble($deal, $selections);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('deals-dr2.pipeline', $deal)
            ->with('info', 'Deal structure saved — pipeline assembled.');
    }

    /** Attach a template's pipeline to the deal (the service guards against double-attach). */
    public function attach(Deal $deal, Request $request): RedirectResponse
    {
        // A declined deal does not get a NEW pipeline started on it. (The capture-time
        // auto-attach in DealRegisterController is deliberately NOT gated — a deal that is
        // auto-declined at birth by the Wave 2 capture-after-grant rule still materialises
        // its steps in that same request, and they simply render locked.)
        $this->lock->assertUnlocked($deal, 'Attach a pipeline');

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
        $isNewModel = app(\App\Services\DealV2\DealDateCascade::class)->isNewModel($deal);
        // Old-model steps complete in strict order (active only). New-model (composable) deals
        // let the agent mark ANY not-started/active step done — real deals rarely complete in
        // order, and the Due cascade re-baselines downstream off the actual date. The deal-level
        // lock (declined deals) still applies, enforced by the service's assertStepUnlocked.
        if ($step->status !== 'active' && ! ($isNewModel && $step->status === 'not_started')) {
            return back()->with('error', 'Only an active step can be completed.');
        }

        $notes = trim((string) $request->input('notes', ''));
        // AT-229 6b — a decision step may complete with a NEGATIVE outcome (e.g. "Bond Declined");
        // only honour it when the step actually has a negative branch configured.
        $outcome = $request->input('outcome') === 'negative' && $step->negative_status_trigger ? 'negative' : 'positive';

        $completion = [];
        if ($notes !== '') { $completion['notes'] = $notes; }
        if ($outcome === 'negative') { $completion['outcome'] = 'negative'; }

        // Wave 2 granted-uniqueness — a step whose trigger would GRANT this deal
        // is blocked when the property already carries a granted deal. The
        // service throws inside the transaction (step completion rolls back);
        // surface it to the user instead of silently swallowing it.
        try {
            $this->pipelines->completeStep($step, $request->user()?->id, $completion);
        } catch (\App\Exceptions\Deal\DuplicateGrantException $e) {
            $other = $e->existingGrantedDeal;
            return back()->with('error', sprintf(
                'Step not completed — it would grant this deal, but deal #%s already carries a %s status on this property. Resolve that deal first.',
                (string) ($other->deal_no ?? $other->id),
                $e->existingStatusLabel(),
            ));
        }

        // AT-334 P1 — new-model: honour an editable actual_date (defaults to today; a user can
        // back-date "bond was actually approved on 1 Aug") and re-cascade downstream Dues off it
        // (Due = predecessor Actual-if-set else Due + offset). RAG recomputes live on render.
        if ($isNewModel) {
            $actual = $request->input('actual_date');
            $actualDate = $actual
                ? \Illuminate\Support\Carbon::parse($actual)->toDateString()
                : now()->toDateString();
            $step->forceFill(['actual_date' => $actualDate])->saveQuietly();
            app(\App\Services\DealV2\DealDateCascade::class)->recompute($deal);
        }

        return redirect()->route('deals-dr2.pipeline', $deal)
            ->with('info', "Step \"{$step->name}\" completed.");
    }

    /**
     * AT-334 P1 — reopen a completed step (composable deals only): clear actual_date /
     * completed_at, return it to not_started, and re-cascade downstream Dues. Reversible;
     * the deal-level lock still applies. Direct successors that this completion activated are
     * left as-is (reopen them individually if needed).
     */
    public function reopenStep(
        Deal $deal,
        DealStepInstance $step,
        Request $request,
        \App\Services\DealV2\DealDateCascade $cascade
    ): RedirectResponse {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        if ($this->lock->isLocked($deal)) {
            return back()->with('error', 'This deal is not proceeding — its pipeline is locked.');
        }
        if (! $cascade->isNewModel($deal)) {
            return back()->with('error', 'Reopen applies to composable deals only.');
        }
        if (! in_array($step->status, ['completed', 'skipped'], true)) {
            return back()->with('error', 'Only a completed step can be reopened.');
        }

        $step->forceFill([
            'status'          => 'not_started',
            'actual_date'     => null,
            'completed_at'    => null,
            'completed_by_id' => null,
            'completion_data' => null,
            'current_rag'     => 'grey',
        ])->save();

        $cascade->recompute($deal);

        return redirect()->route('deals-dr2.pipeline', $deal)
            ->with('info', "Step \"{$step->name}\" reopened.");
    }

    /** V1.1 — mark a step Not Applicable (kept, visibly excused; reason recorded + audited). */
    public function markNa(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $reason = trim((string) $request->input('reason', ''));
        $this->pipelines->markNotApplicable($step, $request->user()?->id, $reason !== '' ? $reason : null);

        return redirect()->route('deals-dr2.pipeline', $deal)->with('info', "Step \"{$step->name}\" marked N/A.");
    }

    /** V1.1 — remove a step (soft-delete; audited). */
    public function removeStep(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $this->pipelines->removeStep($step, $request->user()?->id);

        return redirect()->route('deals-dr2.pipeline', $deal)->with('info', "Step \"{$step->name}\" removed.");
    }

    /** V1.1 — add a custom step: name + due date + position (relative to an existing step). */
    public function addStep(Deal $deal, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'due_date'      => ['nullable', 'date'],
            'after_step_id' => ['nullable', 'integer'],
        ]);

        $after = ! empty($data['after_step_id'])
            ? DealStepInstance::where('dr1_deal_id', $deal->id)->find($data['after_step_id'])
            : null;

        $this->pipelines->addCustomStep($deal, trim($data['name']), $data['due_date'] ?? null, $after, $request->user()?->id);

        return redirect()->route('deals-dr2.pipeline', $deal)->with('info', 'Step added.');
    }

    /** R2 — edit a step's due date inline (audited; RAG recalcs off the edited date). */
    public function editDue(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $data = $request->validate(['due_date' => ['nullable', 'date']]);
        $this->pipelines->updateStepDueDate($step, $data['due_date'] ?? null, $request->user()?->id);

        return redirect()->route('deals-dr2.pipeline', $deal)->with('info', "Due date updated for \"{$step->name}\".");
    }

    /**
     * AT-334 Phase 5 — set a step's "follows" (predecessor) + offset (days). Re-cascades
     * the Due dates off the new predecessor, then reorders the pipeline so the step sits
     * right after the step it follows (dependency chains stay contiguous). New-model deals
     * only; existing deals are never touched.
     */
    public function editFollows(
        Deal $deal,
        DealStepInstance $step,
        Request $request,
        \App\Services\DealV2\DealDateCascade $cascade,
        \App\Services\DealV2\DealStepReorderService $reorder
    ): RedirectResponse {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        if (! $cascade->isNewModel($deal)) {
            return redirect()->route('deals-dr2.pipeline', $deal)
                ->with('error', 'Sequence editing applies to composable deals only.');
        }

        $data = $request->validate([
            'follows' => ['nullable', 'integer'],
            'offset'  => ['nullable', 'integer', 'min:0', 'max:3650'],
        ]);

        // The predecessor must be another LIVE step of THIS deal (never self).
        $follows = $data['follows'] ?? null;
        if ($follows) {
            $ok = DealStepInstance::where('dr1_deal_id', $deal->id)->whereNull('deleted_at')
                ->where('id', $follows)->where('id', '!=', $step->id)->exists();
            if (! $ok) {
                $follows = null;
            }
        }

        // Cycle guard — walking the follows-chain up from the chosen predecessor must
        // never reach this step (that would create a loop).
        if ($follows) {
            $cursor = $follows;
            $seen   = [];
            while ($cursor && ! isset($seen[$cursor])) {
                if ((int) $cursor === (int) $step->id) {
                    return redirect()->route('deals-dr2.pipeline', $deal)
                        ->with('error', 'That would make the step follow itself (a loop).');
                }
                $seen[$cursor] = true;
                $cursor = DealStepInstance::where('id', $cursor)->value('trigger_step_instance_id');
            }
        }

        $step->forceFill([
            'trigger_step_instance_id' => $follows,
            'days_offset'              => (int) ($data['offset'] ?? 0),
            'trigger_type'             => $follows ? 'after_step' : 'on_creation',
        ])->save();

        $cascade->recompute($deal);          // dates
        $reorder->reorderByFollows($deal);   // visual order

        return redirect()->route('deals-dr2.pipeline', $deal)
            ->with('info', "Sequence updated for \"{$step->name}\".");
    }

    /** R2 — restore a removed (soft-deleted) step to its original position. */
    public function restoreStep(Deal $deal, Request $request): RedirectResponse
    {
        $data = $request->validate(['step_id' => ['required', 'integer']]);
        $step = $this->pipelines->restoreRemovedStep($deal, (int) $data['step_id'], $request->user()?->id);

        return redirect()->route('deals-dr2.pipeline', $deal)
            ->with($step ? 'info' : 'error', $step ? "Step \"{$step->name}\" restored." : 'That step could not be restored.');
    }

    /** R2 — reinstate an N/A'd step back to a live step. */
    public function reinstateStep(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $this->pipelines->reinstateStep($step, $request->user()?->id);

        return redirect()->route('deals-dr2.pipeline', $deal)->with('info', "Step \"{$step->name}\" reinstated.");
    }

    /** V1.1 — add a comment to a step's thread. */
    public function addComment(Deal $deal, DealStepInstance $step, Request $request): RedirectResponse
    {
        if ((int) $step->dr1_deal_id !== (int) $deal->id) {
            abort(404);
        }
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        DealStepComment::create([
            'agency_id'             => $deal->agency_id,
            'deal_step_instance_id' => $step->id,
            'user_id'               => $request->user()?->id,
            'body'                  => trim($data['body']),
        ]);

        return redirect()->route('deals-dr2.pipeline', $deal)->with('info', 'Comment added.');
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
