<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use Illuminate\Http\Request;

class DealPipelineStepController extends Controller
{
    public function store(Request $request, DealPipelineTemplate $template)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $data = $this->validateStep($request, $template);
        $data = $this->applyCompletionConfig($data, null);

        $data['pipeline_template_id'] = $template->id;
        $data['position'] = ($template->steps()->max('position') ?? 0) + 1;

        $step = DealPipelineStep::create($data);
        $step->load('triggerStep');

        return response()->json([
            'success' => true,
            'step' => $this->formatStep($step),
        ]);
    }

    public function update(Request $request, DealPipelineStep $step)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $template = $step->template;
        $data = $this->validateStep($request, $template);

        // Locked steps: restrict what can be changed. The document-type binding
        // (completion_config.document_type_id) is config, not structure, so it
        // stays editable even on locked steps — an agency can bind "Electrical
        // COC" (locked) to its COC doc type for auto-completion (WS3 · D4).
        if ($step->is_locked) {
            $data = array_intersect_key($data, array_flip([
                'name', 'description', 'days_offset',
                'rag_amber_days', 'rag_red_days',
                'notify_agent', 'notify_bm', 'notify_admin',
                'trigger_step_id',
                'status_trigger', 'negative_status_trigger', 'negative_outcome_label',
                'requires_bm_approval', 'document_type_id',
            ]));
        }

        $data = $this->applyCompletionConfig($data, $step);

        $step->update($data);
        $step->load('triggerStep');

        return response()->json([
            'success' => true,
            'step' => $this->formatStep($step),
        ]);
    }

    public function destroy(DealPipelineStep $step)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        if ($step->is_locked) {
            return response()->json([
                'success' => false,
                'message' => 'Locked steps cannot be removed.',
            ], 422);
        }

        // Check for dependent steps
        $dependentCount = DealPipelineStep::where('trigger_step_id', $step->id)->count();
        if ($dependentCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Cannot remove — {$dependentCount} other step(s) depend on this step. Reassign their triggers first.",
            ], 422);
        }

        $step->delete();

        return response()->json(['success' => true]);
    }

    public function reorder(Request $request, DealPipelineTemplate $template)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $request->validate([
            'steps' => ['required', 'array'],
            'steps.*.id' => ['required', 'integer'],
            'steps.*.position' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($request->input('steps') as $item) {
            DealPipelineStep::where('id', $item['id'])
                ->where('pipeline_template_id', $template->id)
                ->update(['position' => $item['position']]);
        }

        return response()->json(['success' => true]);
    }

    private function validateStep(Request $request, DealPipelineTemplate $template): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_locked' => ['boolean'],
            'is_milestone' => ['boolean'],
            'completion_type' => ['required', 'in:manual_tick,date_input,amount_input,document_upload,document_signed,text_input,multi_field,auto_from_linked_deal'],
            'trigger_type' => ['required', 'in:on_creation,after_step,manual,on_date'],
            'trigger_step_id' => ['nullable', 'integer', 'exists:deal_pipeline_steps,id'],
            'days_offset' => ['required', 'integer', 'min:0'],
            // AT-158 WS7 — two-threshold RAG (Johan): amber_days + red_days only.
            // green is derived ("not yet amber"), no configurable green threshold.
            // rag_green_days column is retained (the calendar tile resolver reads it)
            // but is no longer set from the pipeline-setup UI; new steps take the
            // column default. Reconciles to derived-green once AT-164 lands.
            'rag_amber_days' => ['required', 'integer', 'min:1'],
            'rag_red_days' => ['required', 'integer', 'min:1'],
            'notify_agent' => ['boolean'],
            'notify_bm' => ['boolean'],
            'notify_admin' => ['boolean'],
            'status_trigger' => ['nullable', 'in:granted,completed,cancelled'],
            'negative_status_trigger' => ['nullable', 'in:cancelled'],
            'negative_outcome_label' => ['nullable', 'string', 'max:255', 'required_with:negative_status_trigger'],
            'requires_bm_approval' => ['boolean'],
            // WS3 (D4) — which document type satisfies this step. Only meaningful
            // for document_upload / document_signed steps; drives config-driven
            // auto-completion when a matching document is filed against the deal.
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
        ]);
    }

    /**
     * Fold the standalone document_type_id field into the step's
     * completion_config JSON (merging with any existing config), then drop the
     * loose key so it never reaches a non-existent column. A non-document
     * completion type clears the binding.
     */
    private function applyCompletionConfig(array $data, ?DealPipelineStep $step): array
    {
        $documentTypeId = $data['document_type_id'] ?? null;
        unset($data['document_type_id']);

        $completionType = $data['completion_type'] ?? $step?->completion_type;
        $isDocStep = in_array($completionType, ['document_upload', 'document_signed'], true);

        $config = $step?->completion_config ?? [];
        if ($isDocStep && $documentTypeId) {
            $config['document_type_id'] = (int) $documentTypeId;
        } else {
            unset($config['document_type_id']);
        }

        $data['completion_config'] = $config ?: null;

        return $data;
    }

    private function formatStep(DealPipelineStep $step): array
    {
        return [
            'id' => $step->id,
            'name' => $step->name,
            'description' => $step->description,
            'position' => $step->position,
            'is_locked' => $step->is_locked,
            'is_milestone' => $step->is_milestone,
            'completion_type' => $step->completion_type,
            'trigger_type' => $step->trigger_type,
            'trigger_step_id' => $step->trigger_step_id,
            'trigger_step_name' => $step->triggerStep ? $step->triggerStep->name : null,
            'days_offset' => $step->days_offset,
            'rag_green_days' => $step->rag_green_days,
            'rag_amber_days' => $step->rag_amber_days,
            'rag_red_days' => $step->rag_red_days,
            'notify_agent' => $step->notify_agent,
            'notify_bm' => $step->notify_bm,
            'notify_admin' => $step->notify_admin,
            'status_trigger' => $step->status_trigger,
            'negative_status_trigger' => $step->negative_status_trigger,
            'negative_outcome_label' => $step->negative_outcome_label,
            'requires_bm_approval' => $step->requires_bm_approval,
            'expected_document_type_id' => data_get($step->completion_config, 'document_type_id'),
        ];
    }
}
