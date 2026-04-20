<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\AgencyComplianceProvision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AgencyComplianceSettingsController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()->hasPermission('manage_agency_compliance'), 403);

        $provisions = AgencyComplianceProvision::with('creator')
            ->orderByDesc('created_at')
            ->get();

        $types = AgencyComplianceProvision::TYPES;
        $typeLabels = AgencyComplianceProvision::TYPE_LABELS;

        // Group active provisions by type for quick lookup
        $activeByType = $provisions->where('status', 'active')
            ->filter(fn ($p) => !$p->effective_until || $p->effective_until->gte(now()))
            ->keyBy('provision_type');

        $branches = \App\Models\Branch::orderBy('name')->get(['id', 'name']);
        $roles = \App\Models\Role::orderBy('label')->get(['id', 'name', 'label']);

        return view('compliance.agency.index', compact(
            'provisions',
            'types',
            'typeLabels',
            'activeByType',
            'branches',
            'roles'
        ));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_agency_compliance'), 403);

        $validated = $request->validate([
            'provision_type'      => ['required', 'string', 'in:' . implode(',', AgencyComplianceProvision::TYPES)],
            'document'            => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'policy_reference'    => ['nullable', 'string', 'max:200'],
            'effective_from'      => ['required', 'date'],
            'effective_until'     => ['nullable', 'date', 'after:effective_from'],
            'applies_to_roles'    => ['nullable', 'array'],
            'applies_to_roles.*'  => ['string'],
            'applies_to_branches' => ['nullable', 'array'],
            'applies_to_branches.*' => ['string'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ]);

        $data = [
            'provision_type'      => $validated['provision_type'],
            'policy_reference'    => $validated['policy_reference'] ?? null,
            'effective_from'      => $validated['effective_from'],
            'effective_until'     => $validated['effective_until'] ?? null,
            'applies_to_roles'    => $validated['applies_to_roles'] ?? null,
            'applies_to_branches' => $validated['applies_to_branches'] ?? null,
            'notes'               => $validated['notes'] ?? null,
            'status'              => 'active',
            'created_by'          => auth()->id(),
        ];

        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $path = $file->store('agency-compliance', 'public');
            $data['document_path'] = $path;
            $data['document_original_name'] = $file->getClientOriginalName();
        }

        // Supersede any existing active provision of the same type for this agency
        AgencyComplianceProvision::where('provision_type', $validated['provision_type'])
            ->where('status', 'active')
            ->update(['status' => 'superseded']);

        $provision = AgencyComplianceProvision::create($data);

        logger()->info('Agency compliance provision created', [
            'provision_id' => $provision->id,
            'type' => $provision->provision_type,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('compliance.agency-settings.index')
            ->with('success', AgencyComplianceProvision::TYPE_LABELS[$provision->provision_type] . ' provision added.');
    }

    public function edit(AgencyComplianceProvision $provision)
    {
        abort_unless(auth()->user()->hasPermission('manage_agency_compliance'), 403);

        $typeLabels = AgencyComplianceProvision::TYPE_LABELS;
        $branches = \App\Models\Branch::orderBy('name')->get(['id', 'name']);
        $roles = \App\Models\Role::orderBy('label')->get(['id', 'name', 'label']);

        return view('compliance.agency.edit', compact('provision', 'typeLabels', 'branches', 'roles'));
    }

    public function update(Request $request, AgencyComplianceProvision $provision)
    {
        abort_unless(auth()->user()->hasPermission('manage_agency_compliance'), 403);

        $validated = $request->validate([
            'document'            => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'policy_reference'    => ['nullable', 'string', 'max:200'],
            'effective_from'      => ['required', 'date'],
            'effective_until'     => ['nullable', 'date', 'after:effective_from'],
            'applies_to_roles'    => ['nullable', 'array'],
            'applies_to_roles.*'  => ['string'],
            'applies_to_branches' => ['nullable', 'array'],
            'applies_to_branches.*' => ['string'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ]);

        $provision->fill([
            'policy_reference'    => $validated['policy_reference'] ?? null,
            'effective_from'      => $validated['effective_from'],
            'effective_until'     => $validated['effective_until'] ?? null,
            'applies_to_roles'    => $validated['applies_to_roles'] ?? null,
            'applies_to_branches' => $validated['applies_to_branches'] ?? null,
            'notes'               => $validated['notes'] ?? null,
        ]);

        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $path = $file->store('agency-compliance', 'public');
            $provision->document_path = $path;
            $provision->document_original_name = $file->getClientOriginalName();
        }

        $provision->save();

        logger()->info('Agency compliance provision updated', [
            'provision_id' => $provision->id,
            'updated_by' => auth()->id(),
        ]);

        return redirect()->route('compliance.agency-settings.index')
            ->with('success', 'Provision updated.');
    }

    public function destroy(AgencyComplianceProvision $provision)
    {
        abort_unless(auth()->user()->hasPermission('manage_agency_compliance'), 403);

        $provision->update(['status' => 'expired']);
        $provision->delete();

        logger()->info('Agency compliance provision ended', [
            'provision_id' => $provision->id,
            'ended_by' => auth()->id(),
        ]);

        return redirect()->route('compliance.agency-settings.index')
            ->with('success', 'Provision ended.');
    }
}
