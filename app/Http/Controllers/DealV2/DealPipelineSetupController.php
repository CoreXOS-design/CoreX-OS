<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealPipelineTemplate;
use App\Services\DealV2\DealPipelineTemplateProvisioner;
use Illuminate\Http\Request;

class DealPipelineSetupController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $query = DealPipelineTemplate::withCount('steps')->with('branch');

        if ($search = $request->input('search')) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        if ($dealType = $request->input('deal_type')) {
            $query->where('deal_type', $dealType);
        }

        $templates = $query->orderBy('deal_type')->orderBy('name')->paginate(15)->withQueryString();

        return view('deals-v2.pipeline-setup.index', compact('templates'));
    }

    public function create()
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $branches = Branch::orderBy('name')->get();

        return view('deals-v2.pipeline-setup.create', compact('branches'));
    }

    /**
     * AT-158 WS-R1 — "Load standard templates" affordance.
     *
     * (Re-)provisions the three shipped default templates (Standard Bond Sale,
     * Cash Sale, Sale of Second Property) for the CURRENT user's own agency via
     * the shared idempotent provisioner. Existing templates are never touched;
     * missing ones are created, fresh templates get their steps. Removes the
     * "blank register, no way to get the defaults" dead-end.
     */
    public function loadDefaults(Request $request, DealPipelineTemplateProvisioner $provisioner)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $user = auth()->user();
        // Own agency only — never a request-supplied id — so this can never
        // provision another tenant.
        $agencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : $user->agency_id;

        if (!$agencyId) {
            return back()->with('error', 'No active agency — switch into an agency first, then load the standard templates.');
        }

        try {
            $result = $provisioner->provisionDefaultsForAgency((int) $agencyId, $user->id);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['created'] === 0 && $result['steps_created'] === 0) {
            return back()->with('status', 'Standard templates are already in place — nothing to add.');
        }

        $msg = sprintf(
            'Loaded standard templates: %d added, %d already present (%d steps created).',
            $result['created'],
            $result['skipped'],
            $result['steps_created']
        );

        return redirect()->route('deals-v2.pipeline.index')->with('status', $msg);
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'deal_type' => ['required', 'in:bond,cash,sale_of_2nd'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'is_default' => ['boolean'],
        ]);

        $data['created_by_id'] = auth()->id();
        $data['is_default'] = $request->boolean('is_default');

        if ($data['is_default']) {
            $this->unsetOtherDefaults($data['deal_type'], $data['branch_id'] ?? null);
        }

        $template = DealPipelineTemplate::create($data);

        return redirect()->route('deals-v2.pipeline.edit', $template)
            ->with('status', 'Template created. Now add your pipeline steps.');
    }

    public function edit(DealPipelineTemplate $template)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $template->load(['steps' => fn ($q) => $q->orderBy('position'), 'steps.triggerStep', 'branch']);
        $branches = Branch::orderBy('name')->get();

        $stepsJson = $template->steps->map(function ($s) {
            return [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
                'position' => $s->position,
                'is_locked' => $s->is_locked,
                'is_milestone' => $s->is_milestone,
                'completion_type' => $s->completion_type,
                'trigger_type' => $s->trigger_type,
                'trigger_step_id' => $s->trigger_step_id,
                'trigger_step_name' => $s->triggerStep ? $s->triggerStep->name : null,
                'days_offset' => $s->days_offset,
                'rag_green_days' => $s->rag_green_days,
                'rag_amber_days' => $s->rag_amber_days,
                'rag_red_days' => $s->rag_red_days,
                'notify_agent' => $s->notify_agent,
                'notify_bm' => $s->notify_bm,
                'notify_admin' => $s->notify_admin,
                'status_trigger' => $s->status_trigger,
                'negative_status_trigger' => $s->negative_status_trigger,
                'negative_outcome_label' => $s->negative_outcome_label,
                'requires_bm_approval' => $s->requires_bm_approval,
                'expected_document_type_id' => data_get($s->completion_config, 'document_type_id'), // WS3 (D4)
            ];
        })->values();

        $documentTypes = \App\Models\DocumentType::query()->where('is_active', true)
            ->orderBy('sort_order')->get(['id', 'label']);

        return view('deals-v2.pipeline-setup.edit', compact('template', 'branches', 'stepsJson', 'documentTypes'));
    }

    public function update(Request $request, DealPipelineTemplate $template)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'deal_type' => ['required', 'in:bond,cash,sale_of_2nd'],
            'branch_id' => ['nullable', 'exists:branches,id'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $data['is_default'] = $request->boolean('is_default');
        $data['is_active'] = $request->boolean('is_active', $template->is_active);

        if ($data['is_default']) {
            $this->unsetOtherDefaults($data['deal_type'], $data['branch_id'] ?? null, $template->id);
        }

        $template->update($data);

        return back()->with('status', 'Template updated.');
    }

    public function destroy(DealPipelineTemplate $template)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $activeCount = $template->deals()->where('status', 'active')->count();
        if ($activeCount > 0) {
            return back()->with('error', "Cannot archive — {$activeCount} active deal(s) use this template.");
        }

        $template->delete();

        return redirect()->route('deals-v2.pipeline.index')->with('status', 'Template archived.');
    }

    public function duplicate(DealPipelineTemplate $template)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_pipeline'), 403);

        $template->load('steps');

        $newTemplate = $template->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
        $newTemplate->name = $template->name . ' (Copy)';
        $newTemplate->is_default = false;
        $newTemplate->created_by_id = auth()->id();
        $newTemplate->save();

        // Map old step IDs to new step IDs for trigger references
        $stepMap = [];

        foreach ($template->steps as $step) {
            $newStep = $step->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
            $newStep->pipeline_template_id = $newTemplate->id;
            $newStep->trigger_step_id = null; // resolve in second pass
            $newStep->save();
            $stepMap[$step->id] = $newStep->id;
        }

        // Second pass: resolve trigger references
        foreach ($template->steps as $step) {
            if ($step->trigger_step_id && isset($stepMap[$step->trigger_step_id])) {
                DealPipelineStep::where('id', $stepMap[$step->id])
                    ->update(['trigger_step_id' => $stepMap[$step->trigger_step_id]]);
            }
        }

        return redirect()->route('deals-v2.pipeline.edit', $newTemplate)
            ->with('status', 'Template duplicated. You can now customise it.');
    }

    private function unsetOtherDefaults(string $dealType, ?int $branchId, ?int $excludeId = null): void
    {
        $query = DealPipelineTemplate::where('deal_type', $dealType)
            ->where('is_default', true);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        } else {
            $query->whereNull('branch_id');
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $query->update(['is_default' => false]);
    }
}
