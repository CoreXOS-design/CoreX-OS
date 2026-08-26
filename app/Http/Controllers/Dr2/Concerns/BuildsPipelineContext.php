<?php

namespace App\Http\Controllers\Dr2\Concerns;

use App\Models\Deal;
use App\Models\DealV2\DealCondition;
use App\Models\DealV2\DealStepInstance;
use App\Models\DealV2\DealStepWorkOrder;
use App\Services\Deal\Dr1PipelineService;

/**
 * Pipeline Dashboard — the ONE assembly of everything the shared deal-context tabs (Deal Structure /
 * Supplier Work Orders / Documents / Email Parties / Proforma) and the step cards need, so the Timeline
 * and List views render the exact same panels the board used. Extracted from PipelineController::show
 * so the three surfaces never drift. Self-contained (resolves its services) — usable from any of them.
 */
trait BuildsPipelineContext
{
    protected function pipelineContext(Deal $deal): array
    {
        $pipelines = app(Dr1PipelineService::class);
        $lock      = app(\App\Services\Deal\DealPipelineLockService::class);

        $deal->loadMissing(['pipelineSteps.comments.user']);

        $steps = $deal->pipelineSteps->map(function (DealStepInstance $s) use ($pipelines) {
            $terminal = in_array($s->status, ['completed', 'skipped'], true);
            $rag = $terminal ? 'grey' : $pipelines->calculateRag($s);
            return [
                'model'   => $s,
                'rag'     => $rag,
                'colour'  => Dr1PipelineService::ragColour($rag),
                'blocked' => $s->blockedByLabel(),
                'na'      => $s->status === 'skipped' && ! empty($s->na_reason),
            ];
        });

        $hasPipeline = $steps->isNotEmpty();

        $removedSteps = DealStepInstance::onlyTrashed()
            ->where('dr1_deal_id', $deal->id)->orderBy('position')->orderBy('id')->get();

        $locked = $lock->isLocked($deal);

        $conditionCatalog = app(\App\Services\DealV2\Dr2ConditionCatalog::class)->conditions();
        $dealConditions   = DealCondition::where('deal_id', $deal->id)->get()->keyBy('key');
        $isNewModel       = app(\App\Services\DealV2\DealDateCascade::class)->isNewModel($deal);

        $awaitingWos      = DealStepWorkOrder::where('dr1_deal_id', $deal->id)
            ->where('status', 'awaiting_supplier')->get();

        return [
            'deal'             => $deal,
            'steps'            => $steps,
            'hasPipeline'      => $hasPipeline,
            'removedSteps'     => $removedSteps,
            'locked'           => $locked,
            'lockReason'       => $locked ? $lock->reason($deal) : null,
            'unlockHint'       => $locked ? $lock->unlockHint() : null,
            'conditionCatalog' => $conditionCatalog,
            'dealConditions'   => $dealConditions,
            'isNewModel'       => $isNewModel,
            'woNeedsAttention' => $awaitingWos->isNotEmpty(),
            'awaitingStepIds'  => $awaitingWos->pluck('deal_step_instance_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
            // Status-badge palette the step tiles read.
            'statusStyles'     => [
                'not_started'    => ['Not started', '#6b7280', '#f3f4f6'],
                'active'         => ['Active',       '#1d4ed8', '#dbeafe'],
                'completed'      => ['Completed',    '#047857', '#d1fae5'],
                'not_applicable' => ['N/A',          '#6b7280', '#f3f4f6'],
                'skipped'        => ['Skipped',      '#6b7280', '#f3f4f6'],
            ],
        ];
    }
}
