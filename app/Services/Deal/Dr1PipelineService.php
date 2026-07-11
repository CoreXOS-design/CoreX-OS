<?php

namespace App\Services\Deal;

use App\Models\Deal;
use App\Models\DealV2\DealActivityLog;
use App\Models\DealV2\DealPipelineTemplate;
use App\Models\DealV2\DealStepInstance;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AT-216 (DR2 · WS-PIPELINE) — the DR1-anchored pipeline overlay.
 *
 * DR2 doctrine (Johan): DR2 is an exact duplicate of DR1 on the SAME tables, plus a
 * pipeline. The AT-158 engine (App\Services\DealV2\DealPipelineService) is bound to
 * `deals_v2` and its status vocabulary; it SUNSETS with the deals-v2 module. Rather than
 * entangle that engine with two models, this is a parallel overlay that anchors a pipeline
 * to a DR1 `deals` row via `deal_step_instances.dr1_deal_id`.
 *
 * ── What this overlay OWNS (this increment) ──────────────────────────────────────────
 *   • createPipeline()  — instantiate a template's steps against a DR1 deal, resolve the
 *                         trigger + AND-gate dependency graph, activate on_creation steps,
 *                         stamp the deal's pipeline pointer, and audit it.
 *   • activateStep()    — set a step active, compute its due date + RAG.
 *   • activateDownstreamSteps() / dependencyReadiness() — advance the chain as steps complete.
 *   • completeStep()    — mark a step done and cascade to its successors (step-level only).
 *   • calculateRag() / ragColour() — pure, model-agnostic RAG maths (identical to DR2).
 *
 * ── DELIBERATELY DEFERRED (needs a DR1 status-model design pass — do NOT fake it) ────
 *   The DR2 engine also drives DEAL-LEVEL status: advanceStage / DealStageMove (auto|prompt),
 *   suspensive-gate → 'granted', negative → 'declined'/'cancelled', overall_rag, and
 *   expected/actual_registration. DR1 `deals` has NONE of those columns (no `status`,
 *   `overall_rag`, or `expected_registration`) — DR1 models its lifecycle through
 *   settlements, not a pipeline status enum. Wiring stage advancement onto DR1 is a
 *   separate increment that first decides how (or whether) a pipeline drives a DR1 deal's
 *   state. Until then this overlay is a pure step-tracking layer: it never mutates the
 *   DR1 deal beyond its own pipeline pointer, so it can be attached to a live DR1 deal
 *   without changing that deal's existing behaviour.
 *
 * BM-approval, prompt/auto stage gates, and cross-pillar distribution (DealStepCompleted)
 * ride on that deferred deal-status layer and are therefore out of scope here too.
 */
class Dr1PipelineService
{
    /**
     * Attach a pipeline to an existing DR1 deal: materialise the template's steps,
     * wire their trigger + AND-gate dependencies, activate the on-creation steps, and
     * stamp the deal's pipeline pointer. Idempotent guard: refuses if the deal already
     * carries a pipeline (call detach/replace in a later increment, never silently double).
     *
     * @param  array  $opts  ['from_date' => Y-m-d anchor for on_creation steps, defaults to now]
     */
    public function createPipeline(Deal $deal, int $templateId, array $opts = []): Deal
    {
        if ($deal->deal_pipeline_template_id) {
            throw new \RuntimeException(
                "DR1 deal {$deal->id} already has pipeline template {$deal->deal_pipeline_template_id}; refusing to double-attach."
            );
        }

        $template = DealPipelineTemplate::with('steps.dependencies')->findOrFail($templateId);
        $fromDate = $opts['from_date'] ?? null;

        return DB::transaction(function () use ($deal, $template, $fromDate) {
            $stepMap = []; // template_step_id => instance_id

            foreach ($template->steps as $templateStep) {
                $instance = DealStepInstance::create([
                    'agency_id'                => $deal->agency_id,
                    'deal_id'                  => null,          // DR1-anchored: legacy deals_v2 pointer stays null
                    'dr1_deal_id'              => $deal->id,
                    'pipeline_step_id'         => $templateStep->id,
                    'name'                     => $templateStep->name,
                    'description'              => $templateStep->description,
                    'position'                 => $templateStep->position,
                    'is_locked'                => $templateStep->is_locked,
                    'is_milestone'             => $templateStep->is_milestone,
                    'is_suspensive'            => $templateStep->is_suspensive,
                    'completion_type'          => $templateStep->completion_type,
                    'completion_config'        => $templateStep->completion_config,
                    'status'                   => 'not_started',
                    'trigger_type'             => $templateStep->trigger_type,
                    'days_offset'              => $templateStep->days_offset,
                    'rag_green_days'           => $templateStep->rag_green_days,
                    'rag_amber_days'           => $templateStep->rag_amber_days,
                    'rag_red_days'             => $templateStep->rag_red_days,
                    'current_rag'              => 'grey',
                    'notify_agent'             => $templateStep->notify_agent,
                    'notify_bm'                => $templateStep->notify_bm,
                    'notify_admin'             => $templateStep->notify_admin,
                    'status_trigger'           => $templateStep->status_trigger,
                    'negative_status_trigger'  => $templateStep->negative_status_trigger,
                    'negative_outcome_label'   => $templateStep->negative_outcome_label,
                    'requires_bm_approval'     => $templateStep->requires_bm_approval,
                    'approval_status'          => 'not_required',
                ]);

                $stepMap[$templateStep->id] = $instance->id;
            }

            // Resolve the single primary trigger (template step → instance).
            foreach ($template->steps as $templateStep) {
                if ($templateStep->trigger_step_id && isset($stepMap[$templateStep->trigger_step_id])) {
                    DealStepInstance::where('id', $stepMap[$templateStep->id])->update([
                        'trigger_step_instance_id' => $stepMap[$templateStep->trigger_step_id],
                    ]);
                }
            }

            // Resolve additional AND-gate dependencies (predecessors beyond the primary trigger).
            $dependencyRows = [];
            foreach ($template->steps as $templateStep) {
                if ($templateStep->dependencies->isEmpty()) {
                    continue;
                }
                $dependentInstanceId = $stepMap[$templateStep->id] ?? null;
                if (! $dependentInstanceId) {
                    continue;
                }
                foreach ($templateStep->dependencies as $depTemplateStep) {
                    $depInstanceId = $stepMap[$depTemplateStep->id] ?? null;
                    if (! $depInstanceId || $depInstanceId === $dependentInstanceId) {
                        continue; // unknown or self — never gate a step on itself
                    }
                    $dependencyRows[] = [
                        'agency_id'                    => $deal->agency_id,
                        'deal_step_instance_id'        => $dependentInstanceId,
                        'depends_on_step_instance_id'  => $depInstanceId,
                        'created_at'                   => now(),
                        'updated_at'                   => now(),
                    ];
                }
            }
            if ($dependencyRows) {
                DB::table('deal_step_instance_dependencies')->insert($dependencyRows);
            }

            // Stamp the DR1 deal's pipeline pointer (the ONLY mutation this overlay makes
            // to the deal — see the deferred-status note in the class docblock).
            $deal->forceFill([
                'deal_pipeline_template_id' => $template->id,
                'pipeline_started_at'       => now(),
            ])->save();

            // Activate the on-creation steps.
            $deal->load('pipelineSteps');
            foreach ($deal->pipelineSteps as $instance) {
                if ($instance->trigger_type === 'on_creation') {
                    $this->activateStep($instance, $fromDate);
                }
            }

            $this->logActivity($deal, null, null, 'pipeline_started',
                "Pipeline \"{$template->name}\" attached ({$template->steps->count()} steps)");

            return $deal->fresh('pipelineSteps');
        });
    }

    /**
     * Activate a step — set it active, compute its due date (relative to $fromDate or now,
     * unless an explicit due_date was set) and its RAG.
     */
    public function activateStep(DealStepInstance $step, $fromDate = null): void
    {
        $baseDate = $fromDate ? Carbon::parse($fromDate) : now();
        $dueDate  = $step->due_date ?? $baseDate->copy()->addDays($step->days_offset);

        $step->update([
            'status'       => 'active',
            'activated_at' => now(),
            'due_date'     => $dueDate,
            'current_rag'  => $this->calculateRag($step, $dueDate),
        ]);

        $this->logActivity($step->dr1Deal, $step, null, 'step_activated',
            "Step \"{$step->name}\" activated — due " . Carbon::parse($dueDate)->format('d M Y'));
    }

    /**
     * Complete a step (step-level only): mark it done and activate its ready successors.
     *
     * NOTE: this is the pure step-tracking completion. The DR2 engine additionally drives
     * BM-approval holds and deal-status stage gates off completion — both deferred here
     * (see the class docblock). A DR1 pipeline step therefore completes cleanly and cascades
     * to its successors without touching the DR1 deal's state.
     */
    public function completeStep(DealStepInstance $step, ?int $userId = null, array $completionData = []): void
    {
        DB::transaction(function () use ($step, $userId, $completionData) {
            $step->update([
                'status'          => 'completed',
                'completed_at'    => now(),
                'completed_by_id' => $userId,
                'completion_data' => $completionData ?: null,
                'current_rag'     => 'grey',
            ]);

            $notes = ! empty($completionData['notes']) ? " — {$completionData['notes']}" : '';
            $this->logActivity($step->dr1Deal, $step, $userId, 'step_completed',
                "Step \"{$step->name}\" completed{$notes}");

            $this->activateDownstreamSteps($step);
        });
    }

    /**
     * Activate every not-started step whose FULL predecessor set is now complete.
     * AND-gate: a candidate names the completed step as its primary trigger OR an additional
     * dependency; it activates only when its primary trigger AND every dependency are done,
     * with its relative clock anchored to the LATEST of those completions.
     */
    public function activateDownstreamSteps(DealStepInstance $completedStep): void
    {
        $primaryDependentIds = DealStepInstance::where('trigger_step_instance_id', $completedStep->id)
            ->where('status', 'not_started')
            ->pluck('id');

        $andGateDependentIds = DB::table('deal_step_instance_dependencies')
            ->where('depends_on_step_instance_id', $completedStep->id)
            ->pluck('deal_step_instance_id');

        $candidateIds = $primaryDependentIds->merge($andGateDependentIds)->unique()->values();

        if ($candidateIds->isEmpty()) {
            return;
        }

        $candidates = DealStepInstance::whereIn('id', $candidateIds)
            ->where('status', 'not_started')
            ->get();

        foreach ($candidates as $candidate) {
            [$met, $fromDate] = $this->dependencyReadiness($candidate);
            if ($met) {
                $this->activateStep($candidate, $fromDate);
            }
        }
    }

    /**
     * Is every predecessor of $step complete?
     *
     * @return array{0:bool,1:?string} [met, fromDate] — fromDate is the LATEST predecessor
     *         completion (Y-m-d), the anchor for this step's relative clock. [false, null]
     *         when a predecessor is still open or the step has no predecessors at all.
     */
    private function dependencyReadiness(DealStepInstance $step): array
    {
        $preds = collect();

        if ($step->trigger_step_instance_id) {
            $primary = DealStepInstance::find($step->trigger_step_instance_id);
            if ($primary) {
                $preds->push($primary);
            }
        }
        foreach ($step->dependencies()->get() as $dep) {
            $preds->push($dep);
        }

        $preds = $preds->unique('id');

        if ($preds->isEmpty()) {
            return [false, null];
        }
        // A predecessor is "resolved" when it's completed, marked N/A, or skipped — any of
        // these clears the gate (an excused step must not block its successors). Removed
        // steps are soft-deleted, so they never appear here at all.
        if ($preds->contains(fn ($p) => ! self::isResolved($p->status))) {
            return [false, null];
        }

        $latest   = $preds->map(fn ($p) => $p->completed_at)->filter()->max();
        $fromDate = $latest ? Carbon::parse($latest)->format('Y-m-d') : now()->format('Y-m-d');

        return [true, $fromDate];
    }

    /**
     * RAG for a step — days-remaining against its due date. Model-agnostic; identical maths
     * to App\Services\DealV2\DealPipelineService::calculateRag.
     */
    public function calculateRag(DealStepInstance $step, $dueDate = null): string
    {
        $due = $dueDate ? Carbon::parse($dueDate) : ($step->due_date ? Carbon::parse($step->due_date) : null);
        if (! $due || $step->status === 'completed') {
            return 'grey';
        }

        $daysRemaining = (int) now()->startOfDay()->diffInDays($due->startOfDay(), false);

        if ($daysRemaining < 0) {
            return 'overdue';
        }
        if ($daysRemaining <= $step->rag_red_days) {
            return 'red';
        }
        if ($daysRemaining <= $step->rag_amber_days) {
            return 'amber';
        }
        return 'green';
    }

    /** Canonical RAG → hex map (mirrors the DR2 engine so tiles agree across DR1/DR2). */
    public static function ragColour(string $rag): string
    {
        return match ($rag) {
            'overdue' => '#dc2626',
            'red'     => '#ef4444',
            'amber'   => '#f59e0b',
            'green'   => '#10b981',
            default   => '#9ca3af',
        };
    }

    /** A step whose outcome no longer blocks its successors. */
    public static function isResolved(?string $status): bool
    {
        return in_array($status, ['completed', 'not_applicable', 'skipped'], true);
    }

    /**
     * V1.1 — mark a step Not Applicable: it's KEPT (visibly excused, e.g. gas CoC on a
     * property with no gas) with a recorded reason, and no longer blocks its successors.
     * Audited on the step.
     */
    public function markNotApplicable(DealStepInstance $step, ?int $userId, ?string $reason): void
    {
        DB::transaction(function () use ($step, $userId, $reason) {
            $step->update([
                'status'      => 'not_applicable',
                'na_reason'   => $reason,
                'current_rag' => 'grey',
            ]);
            $this->logActivity($step->dr1Deal, $step, $userId, 'step_not_applicable',
                "Step \"{$step->name}\" marked N/A" . ($reason ? " — {$reason}" : ''));

            // Successors gated on this step can now proceed.
            $this->activateDownstreamSteps($step);
        });
    }

    /**
     * V1.1 — remove a step (soft-delete per no-hard-delete doctrine). Audited on the step;
     * any successors gated on it are re-evaluated so removal never strands the chain.
     */
    public function removeStep(DealStepInstance $step, ?int $userId): void
    {
        DB::transaction(function () use ($step, $userId) {
            $this->logActivity($step->dr1Deal, $step, $userId, 'step_removed',
                "Step \"{$step->name}\" removed");
            // Advance anything that was waiting on this step BEFORE it disappears from queries.
            $this->activateDownstreamSteps($step);
            $step->delete(); // soft-delete
        });
    }

    /**
     * V1.1 — add a custom (agent-authored) step to an attached pipeline: name + due date +
     * position (insert after $afterStep, or at the end). Immediately active + RAG-tracked.
     */
    public function addCustomStep(Deal $deal, string $name, ?string $dueDate, ?DealStepInstance $afterStep, ?int $userId): DealStepInstance
    {
        return DB::transaction(function () use ($deal, $name, $dueDate, $afterStep, $userId) {
            $position = $afterStep
                ? $afterStep->position
                : ((int) DealStepInstance::where('dr1_deal_id', $deal->id)->max('position') + 1);

            $step = DealStepInstance::create([
                'agency_id'     => $deal->agency_id,
                'deal_id'       => null,
                'dr1_deal_id'   => $deal->id,
                'name'          => $name,
                'position'      => $position,
                'is_custom'     => true,
                'is_locked'     => false,
                'is_milestone'  => false,
                'completion_type' => 'manual',
                'status'        => 'active',
                'trigger_type'  => 'manual',
                'due_date'      => $dueDate ?: null,
                'activated_at'  => now(),
                'rag_green_days' => 7,
                'rag_amber_days' => 3,
                'rag_red_days'   => 1,
                'current_rag'   => 'grey',
                'notify_agent'  => true,
                'approval_status' => 'not_required',
            ]);
            $step->update(['current_rag' => $this->calculateRag($step)]);

            $this->logActivity($deal, $step, $userId, 'step_added',
                "Custom step \"{$name}\" added" . ($dueDate ? " (due {$dueDate})" : '')
                . ($afterStep ? " after \"{$afterStep->name}\"" : ''));

            return $step;
        });
    }

    /**
     * Append an audit row anchored to the DR1 deal. Writes to the shared `deal_activity_log`
     * via dr1_deal_id (deal_id → deals_v2 stays null for DR1-anchored pipelines).
     */
    protected function logActivity(?Deal $deal, ?DealStepInstance $step, ?int $userId, string $action, string $description): void
    {
        if (! $deal) {
            return; // a detached step with no DR1 deal — nothing to anchor the audit to
        }

        DealActivityLog::create([
            'agency_id'             => $deal->agency_id,
            'deal_id'               => null,
            'dr1_deal_id'           => $deal->id,
            'deal_step_instance_id' => $step?->id,
            'user_id'               => $userId,
            'action'                => $action,
            'description'           => $description,
            'created_at'            => now(),
        ]);
    }
}
