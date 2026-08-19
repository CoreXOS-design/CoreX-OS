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

        $this->normalizeOptionalTriggers($request);
        $data = $this->validateStep($request, $template);
        $data = $this->applyCompletionConfig($data, null);

        $data['pipeline_template_id'] = $template->id;
        $data['position'] = ($template->steps()->max('position') ?? 0) + 1;

        $step = DealPipelineStep::create($data);
        $this->syncWorkOrders($step, $request);
        $step->load(['triggerStep', 'workOrders']);

        return response()->json([
            'success' => true,
            'step' => $this->formatStep($step),
        ]);
    }

    /**
     * AT-229 hotfix — a legitimate step must never be blocked from saving by a legacy/None
     * value in an OPTIONAL "trigger" enum. The builder loads a step's stored value into the
     * edit form; when that value is not one the current select can render (e.g. an old
     * `negative_status_trigger = 'declined'` on the "Bond Approved" steps, which the select
     * only offers as None/Cancelled), it is silently re-posted and `in:` rejects it.
     *
     * So: before validation, coerce every optional trigger field to null (or its default)
     * whenever the posted value is not in the current allowed set. "None"/empty is always
     * valid; an unrenderable legacy value cleanly becomes None. Class-wide over all of them.
     */
    private function normalizeOptionalTriggers(Request $request): void
    {
        $coerce = static function ($val, array $allowed, $default = null) {
            $val = is_string($val) ? trim($val) : $val;
            return in_array($val, $allowed, true) ? $val : $default;
        };

        $merge = [];
        if ($request->has('status_trigger')) {
            $merge['status_trigger'] = $coerce($request->input('status_trigger'), ['granted', 'completed', 'cancelled']);
        }
        if ($request->has('negative_status_trigger')) {
            $merge['negative_status_trigger'] = $coerce($request->input('negative_status_trigger'), ['cancelled']);
        }
        if ($request->has('work_order_trigger_point')) {
            $merge['work_order_trigger_point'] = $coerce($request->input('work_order_trigger_point'), ['activated', 'completed'], 'activated');
        }
        if ($request->has('trigger_step_id') && in_array($request->input('trigger_step_id'), ['', 0, '0'], true)) {
            $merge['trigger_step_id'] = null;
        }
        // A negative-outcome label only means anything WITH a negative status trigger.
        $neg = array_key_exists('negative_status_trigger', $merge)
            ? $merge['negative_status_trigger']
            : $request->input('negative_status_trigger');
        if ($neg === null || $neg === '') {
            $merge['negative_outcome_label'] = null;
        }

        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    public function update(Request $request, DealPipelineStep $step)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $template = $step->template;
        $this->normalizeOptionalTriggers($request);
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
                // AT-229 — work-order config is per-step convenience, not structure;
                // stays editable on a locked step (e.g. the locked "Electrical COC" step
                // is exactly where an agency turns work orders on).
                'sends_work_order', 'work_order_service_type', 'work_order_trigger_point',
            ]));
        }

        $data = $this->applyCompletionConfig($data, $step);

        $step->update($data);
        $this->syncWorkOrders($step, $request);
        $step->load(['triggerStep', 'workOrders']);

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
            // AT-229 — per-step work-order config (Q1: set in pipeline setup, no hard
            // setting). Legacy single fields kept for backward-compat; the multi-entry
            // COLLECTION below is the source of truth (a step can trigger several COCs).
            'sends_work_order' => ['boolean'],
            'work_order_service_type' => ['nullable', 'string', 'max:40'],
            'work_order_trigger_point' => ['nullable', 'in:activated,completed'],
            // AT-229 multi — N work-order entries, each its own service + trigger timing.
            'work_orders' => ['nullable', 'array'],
            'work_orders.*.service_type' => ['nullable', 'string', 'max:40'],
            'work_orders.*.trigger_point' => ['nullable', 'in:activated,completed'],
        ]);
    }

    /**
     * AT-229 — sync the step's work-order collection from the posted `work_orders` array.
     * No hard deletes: entries removed in the UI are soft-deleted; the set is rebuilt from
     * the post. The legacy single columns are mirrored from the first entry so any old
     * reader still sees a sensible value during the transition.
     */
    private function syncWorkOrders(DealPipelineStep $step, Request $request): void
    {
        if (! $request->has('work_orders')) {
            return; // nothing posted — leave the collection untouched
        }

        $entries = collect($request->input('work_orders', []))
            ->filter(fn ($e) => ! empty($e['service_type']))
            ->values();

        // Soft-delete the current set, then recreate from the post (config rows, low volume).
        $step->workOrders()->get()->each->delete();

        foreach ($entries as $i => $e) {
            $step->workOrders()->create([
                'agency_id'     => $step->agency_id,
                'service_type'  => $e['service_type'],
                'trigger_point' => ($e['trigger_point'] ?? 'activated') === 'completed' ? 'completed' : 'activated',
                'sort_order'    => $i,
            ]);
        }

        // Mirror legacy single columns from the first entry (backward-compat).
        $first = $entries->first();
        $step->forceFill([
            'sends_work_order'         => $entries->isNotEmpty(),
            'work_order_service_type'  => $first['service_type'] ?? null,
            'work_order_trigger_point' => ($first['trigger_point'] ?? 'activated') ?: 'activated',
        ])->save();
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
            // AT-229 — per-step work-order config (round-trips into the builder editForm).
            'sends_work_order' => (bool) $step->sends_work_order,
            'work_order_service_type' => $step->work_order_service_type,
            'work_order_trigger_point' => $step->work_order_trigger_point ?: 'activated',
            // AT-229 multi — the work-order collection (the source of truth for the UI).
            'work_orders' => $step->relationLoaded('workOrders')
                ? $step->workOrders->map(fn ($w) => [
                    'id' => $w->id,
                    'service_type' => $w->service_type,
                    'trigger_point' => $w->trigger_point ?: 'activated',
                ])->values()->all()
                : [],
        ];
    }
}
