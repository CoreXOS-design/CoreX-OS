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
        abort_unless(auth()->user()->hasPermission('manage_agency_compliance'), 403);

        $docTypes = AgencyDocumentTypeConfig::active()->ordered()->get();

        // For each configured type, load the latest active provision
        $activeProvisions = AgencyComplianceProvision::with(['creator', 'documentType'])
            ->where('status', 'active')
            ->get()
            ->keyBy('document_type_config_id');

        $typeCards = $docTypes->map(function ($type) use ($activeProvisions) {
            $provision = $activeProvisions->get($type->id);
            return (object) [
                'config'    => $type,
                'provision' => $provision,
            ];
        });

        return view('compliance.agency.index', compact('typeCards'));
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('manage_agency_compliance'), 403);

        $agencyId = auth()->user()->effectiveAgencyId();

        $validated = $request->validate([
            'document_type_config_id' => [
                'required', 'integer',
                Rule::exists('agency_document_type_configs', 'id')->where('agency_id', $agencyId),
            ],
            'document'            => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'policy_reference'    => ['nullable', 'string', 'max:200'],
            'effective_from'      => ['required', 'date'],
            'effective_until'     => ['nullable', 'date', 'after:effective_from'],
            'notes'               => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('document');
        $path = $file->store('agency-compliance', 'public');

        // Supersede any existing active provision of the same type for this agency
        AgencyComplianceProvision::where('document_type_config_id', $validated['document_type_config_id'])
            ->where('status', 'active')
            ->update(['status' => 'superseded']);

        $provision = AgencyComplianceProvision::create([
            'document_type_config_id' => $validated['document_type_config_id'],
            'provision_type'          => '', // deprecated column, kept for schema compat
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

        logger()->info('Agency compliance provision created', [
            'provision_id'           => $provision->id,
            'document_type_config_id' => $validated['document_type_config_id'],
            'created_by'             => auth()->id(),
        ]);

        return redirect()->route('compliance.agency-settings.index')
            ->with('success', "{$typeName} uploaded successfully.");
    }

    public function edit(AgencyComplianceProvision $provision)
    {
        abort_unless(auth()->user()->hasPermission('manage_agency_compliance'), 403);

        $provision->load('documentType');

        return view('compliance.agency.edit', compact('provision'));
    }

    public function update(Request $request, AgencyComplianceProvision $provision)
    {
        abort_unless(auth()->user()->hasPermission('manage_agency_compliance'), 403);

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

        return redirect()->route('compliance.agency-settings.index')
            ->with('success', 'Document updated.');
    }

    public function destroy(AgencyComplianceProvision $provision)
    {
        abort_unless(auth()->user()->hasPermission('manage_agency_compliance'), 403);

        $provision->update(['status' => 'expired']);
        $provision->delete();

        logger()->info('Agency compliance provision ended', [
            'provision_id' => $provision->id,
            'ended_by'     => auth()->id(),
        ]);

        return redirect()->route('compliance.agency-settings.index')
            ->with('success', 'Document removed.');
    }
}
