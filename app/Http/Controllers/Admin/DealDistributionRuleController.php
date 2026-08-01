<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DealV2\DealPipelineStep;
use App\Models\DealV2\DealStageDocumentRule;
use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AT-158 DR2 · WS4 (§8.1) — the distribution matrix editor (settings surface).
 *
 * Agency-configurable rules: STAGE × DOCUMENT TYPE × PARTY ROLE →
 * {delivery_mode, auto_on_stage_tick}. Reached from the document-types settings
 * page ("Deal distribution rules"); gated by deals_v2.manage_distribution_rules.
 */
class DealDistributionRuleController extends Controller
{
    /** The party-role vocabulary (mirrors deal_v2_contacts.role). */
    public const PARTY_ROLES = [
        'seller', 'co_seller', 'buyer', 'co_buyer',
        'conveyancer', 'transfer_attorney', 'bond_attorney', 'bond_originator', 'originator',
        'electrician_coc', 'entomologist', 'service_provider', 'external_agency', 'other',
    ];

    public function index()
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_distribution_rules'), 403);
        $agencyId = $this->agencyId();

        $rules = DealStageDocumentRule::query()
            ->where('agency_id', $agencyId)
            ->with(['documentType', 'pipelineStep.template'])
            ->orderBy('pipeline_step_id')
            ->get();

        $documentTypes = DocumentType::where('is_active', true)->orderBy('sort_order')->get(['id', 'label']);
        // Stages = the agency's pipeline template steps (NULL option = any stage).
        $steps = DealPipelineStep::query()
            ->whereHas('template', fn ($q) => $q->where('agency_id', $agencyId))
            ->with('template:id,name')
            ->orderBy('pipeline_template_id')->orderBy('position')
            ->get(['id', 'pipeline_template_id', 'name', 'position']);

        // Feature 2 — the agency-level ad-hoc distribution switch (default off).
        $adhocEnabled = (bool) optional(\App\Models\Agency::withoutGlobalScopes()->find($agencyId))->adhoc_document_distribution_enabled;

        return view('admin.deal-distribution-rules.index', [
            'rules'         => $rules,
            'documentTypes' => $documentTypes,
            'steps'         => $steps,
            'partyRoles'    => self::PARTY_ROLES,
            'adhocEnabled'  => $adhocEnabled,
        ]);
    }

    /**
     * Feature 2 — toggle the agency-level ad-hoc document-distribution switch (default off). When on,
     * the "Send documents to any email" affordance appears on Email Parties; when off, it is hidden.
     */
    public function toggleAdhoc(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_distribution_rules'), 403);
        $agency = \App\Models\Agency::withoutGlobalScopes()->findOrFail($this->agencyId());
        $agency->adhoc_document_distribution_enabled = $request->boolean('adhoc_document_distribution_enabled');
        $agency->save();

        return back()->with('success',
            'Ad-hoc document distribution ' . ($agency->adhoc_document_distribution_enabled ? 'enabled' : 'disabled') . '.');
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_distribution_rules'), 403);
        $agencyId = $this->agencyId();

        $data = $request->validate([
            'pipeline_step_id'   => ['nullable', 'integer', 'exists:deal_pipeline_steps,id'],
            'document_type_id'   => ['required', 'integer', 'exists:document_types,id'],
            'party_role'         => ['required', Rule::in(self::PARTY_ROLES)],
            'delivery_mode'      => ['required', Rule::in(['secure_link', 'direct_attachment'])],
            'auto_on_stage_tick' => ['nullable', 'boolean'],
        ]);

        // Upsert (un-archive a soft-deleted match) so the unique key never 500s.
        $existing = DealStageDocumentRule::withTrashed()
            ->where('agency_id', $agencyId)
            ->where('pipeline_step_id', $data['pipeline_step_id'] ?? null)
            ->where('document_type_id', $data['document_type_id'])
            ->where('party_role', $data['party_role'])
            ->first();

        if ($existing) {
            $existing->restore();
            $existing->update([
                'delivery_mode'      => $data['delivery_mode'],
                'auto_on_stage_tick' => (bool) ($data['auto_on_stage_tick'] ?? false),
                'is_active'          => true,
            ]);
        } else {
            DealStageDocumentRule::create([
                'agency_id'          => $agencyId,
                'pipeline_step_id'   => $data['pipeline_step_id'] ?? null,
                'document_type_id'   => $data['document_type_id'],
                'party_role'         => $data['party_role'],
                'delivery_mode'      => $data['delivery_mode'],
                'auto_on_stage_tick' => (bool) ($data['auto_on_stage_tick'] ?? false),
                'is_active'          => true,
                'created_by_id'      => auth()->id(),
            ]);
        }

        return back()->with('status', 'Distribution rule saved.');
    }

    public function destroy(DealStageDocumentRule $rule)
    {
        abort_unless(auth()->user()?->hasPermission('deals_v2.manage_distribution_rules'), 403);
        abort_unless((int) $rule->agency_id === (int) $this->agencyId(), 403);

        $rule->delete(); // soft delete — no hard deletes

        return back()->with('status', 'Distribution rule removed.');
    }

    private function agencyId(): int
    {
        return (int) (auth()->user()?->effectiveAgencyId() ?? auth()->user()?->agency_id ?? 0);
    }
}
