<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\AgencyComplianceProvision;
use App\Models\Compliance\AgencyDocumentTypeConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AgencyComplianceSettingsController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $isAdmin = $user->hasPermission('manage_agency_compliance');
        $isBranchManager = $user->hasPermission('manage_branch_compliance');

        abort_unless($isAdmin || $isBranchManager, 403);

        $agencyId = $user->effectiveAgencyId();
        $matrix = AgencyComplianceProvision::stateMatrixForAgency($agencyId);

        $userBranchId = $user->effectiveBranchId();

        return view('compliance.agency.index', compact('matrix', 'isAdmin', 'isBranchManager', 'userBranchId'));
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        $agencyId = $user->effectiveAgencyId();
        $branchId = $request->input('branch_id') ?: null;

        // Permission check
        if ($branchId === null) {
            abort_unless($user->hasPermission('manage_agency_compliance'), 403, 'Only admin/CO can upload company-wide documents.');
        } else {
            $canAdmin = $user->hasPermission('manage_agency_compliance');
            $canBranch = $user->hasPermission('manage_branch_compliance')
                && (int) $branchId === $user->effectiveBranchId();
            abort_unless($canAdmin || $canBranch, 403, 'You can only upload documents for your own branch.');
        }

        $validated = $request->validate([
            'document_type_config_id' => [
                'required', 'integer',
                Rule::exists('agency_document_type_configs', 'id')->where('agency_id', $agencyId),
            ],
            'branch_id' => [
                'nullable', 'integer',
                Rule::exists('branches', 'id')->where('agency_id', $agencyId),
            ],
            'document'         => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'policy_reference' => ['nullable', 'string', 'max:200'],
            'effective_from'   => ['required', 'date'],
            'effective_until'  => ['nullable', 'date', 'after:effective_from'],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('document');
        $path = $file->store('agency-compliance', 'public');

        // Supersede previous active provision for same (type, branch) tuple
        AgencyComplianceProvision::where('document_type_config_id', $validated['document_type_config_id'])
            ->where('status', 'active')
            ->where(function ($q) use ($branchId) {
                $branchId ? $q->where('branch_id', $branchId) : $q->whereNull('branch_id');
            })
            ->update(['status' => 'superseded']);

        $provision = AgencyComplianceProvision::create([
            'document_type_config_id' => $validated['document_type_config_id'],
            'branch_id'               => $branchId,
            'provision_type'          => '',
            'policy_reference'        => $validated['policy_reference'] ?? null,
            'effective_from'          => $validated['effective_from'],
            'effective_until'         => $validated['effective_until'] ?? null,
            'notes'                   => $validated['notes'] ?? null,
            'status'                  => 'active',
            'created_by'              => auth()->id(),
            'document_path'           => $path,
            'document_original_name'  => $file->getClientOriginalName(),
        ]);

        $typeName = AgencyDocumentTypeConfig::find($validated['document_type_config_id'])?->name ?? 'Document';
        $label = $branchId
            ? "{$typeName} (branch override) uploaded."
            : "{$typeName} uploaded.";

        logger()->info('Agency compliance provision created', [
            'provision_id'            => $provision->id,
            'document_type_config_id' => $validated['document_type_config_id'],
            'branch_id'               => $branchId,
            'created_by'              => auth()->id(),
        ]);

        return redirect()->route('compliance.agency-settings.index')->with('success', $label);
    }

    public function edit(AgencyComplianceProvision $provision)
    {
        $user = auth()->user();
        $isAdmin = $user->hasPermission('manage_agency_compliance');
        $isBranchOwner = $user->hasPermission('manage_branch_compliance')
            && $provision->branch_id === $user->effectiveBranchId();

        abort_unless($isAdmin || $isBranchOwner, 403);

        $provision->load('documentType');

        return view('compliance.agency.edit', compact('provision'));
    }

    public function update(Request $request, AgencyComplianceProvision $provision)
    {
        $user = auth()->user();
        $isAdmin = $user->hasPermission('manage_agency_compliance');
        $isBranchOwner = $user->hasPermission('manage_branch_compliance')
            && $provision->branch_id === $user->effectiveBranchId();

        abort_unless($isAdmin || $isBranchOwner, 403);

        $validated = $request->validate([
            'document'         => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'policy_reference' => ['nullable', 'string', 'max:200'],
            'effective_from'   => ['required', 'date'],
            'effective_until'  => ['nullable', 'date', 'after:effective_from'],
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);

        $provision->fill([
            'policy_reference' => $validated['policy_reference'] ?? null,
            'effective_from'   => $validated['effective_from'],
            'effective_until'  => $validated['effective_until'] ?? null,
            'notes'            => $validated['notes'] ?? null,
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
            'updated_by'   => auth()->id(),
        ]);

        return redirect()->route('compliance.agency-settings.index')->with('success', 'Document updated.');
    }

    public function destroy(AgencyComplianceProvision $provision)
    {
        $user = auth()->user();
        $isAdmin = $user->hasPermission('manage_agency_compliance');
        $isBranchOwner = $user->hasPermission('manage_branch_compliance')
            && $provision->branch_id === $user->effectiveBranchId();

        abort_unless($isAdmin || $isBranchOwner, 403);

        $provision->update(['status' => 'expired']);
        $provision->delete();

        logger()->info('Agency compliance provision ended', [
            'provision_id' => $provision->id,
            'ended_by'     => auth()->id(),
        ]);

        return redirect()->route('compliance.agency-settings.index')->with('success', 'Document removed.');
    }
}
