<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SplitterDocType;
use App\Services\Compliance\AgencyComplianceDocTypeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SplitterDocTypeController extends Controller
{
    public function __construct(
        private AgencyComplianceDocTypeService $compliance = new AgencyComplianceDocTypeService(),
    ) {
    }

    public function index()
    {
        $types = SplitterDocType::orderBy('sort_order')->get();
        $context = request()->routeIs('admin.settings.*') ? 'settings' : 'splitter';

        // Per-agency marketing-compliance flags. document_types is a global
        // catalogue; the "compliance required" toggle is stored per-agency so
        // one agency's choices never dictate another's.
        $agencyId = $this->currentAgencyId();
        if ($agencyId) {
            $this->compliance->ensureDefaults($agencyId);
        }
        $complianceMap = $agencyId ? $this->compliance->complianceMapFor($agencyId) : [];

        // AT-105 — effective per-agency "Save To" destinations, keyed by
        // document_type_id (stored choice merged over the grouping default).
        $destinationMap = $agencyId ? $this->compliance->destinationMapFor($agencyId) : [];

        // AT-105 enh — effective per-agency contact_role + fica_slot routing,
        // keyed by document_type_id (override merged over catalogue default).
        $routingMap = $agencyId ? $this->compliance->routingMapFor($agencyId) : [];

        return view('admin.splitter.doc-types', compact('types', 'context', 'complianceMap', 'destinationMap', 'routingMap'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'label'           => 'required|string|max:100',
            'contact_roles'   => ['nullable', 'array'],
            'contact_roles.*' => [Rule::in(SplitterDocType::CONTACT_ROLES)],
            'fica_slot'       => ['nullable', Rule::in(SplitterDocType::FICA_SLOTS)],
        ]);

        $slug = Str::slug($request->input('label'), '_');

        if (SplitterDocType::where('slug', $slug)->exists()) {
            return back()->withErrors(['label' => "A type with slug '{$slug}' already exists."]);
        }

        $maxSort = SplitterDocType::max('sort_order') ?? 0;
        $roles   = array_values(array_filter((array) $request->input('contact_roles', []), fn ($r) => in_array($r, SplitterDocType::CONTACT_ROLES, true)));

        SplitterDocType::create([
            'slug'          => $slug,
            'label'         => $request->input('label'),
            'sort_order'    => $maxSort + 1,
            'is_active'     => true,
            'contact_roles' => $roles,
            'fica_slot'     => $request->input('fica_slot', 'none') ?: 'none',
        ]);

        return back()->with('success', 'Document type added.');
    }

    public function update(Request $request, SplitterDocType $doc_type)
    {
        $request->validate([
            'label'      => 'required|string|max:100',
            'sort_order' => 'required|integer|min:0',
            'is_active'  => 'required|boolean',
        ]);

        $doc_type->update([
            'label'      => $request->input('label'),
            'sort_order' => $request->input('sort_order'),
            'is_active'  => $request->boolean('is_active'),
        ]);

        return back()->with('success', "'{$doc_type->label}' updated.");
    }

    public function destroy(SplitterDocType $doc_type)
    {
        $label = $doc_type->label;
        $doc_type->delete();

        return back()->with('success', "'{$label}' archived.");
    }

    public function bulkSave(Request $request)
    {
        $request->validate([
            'types'                       => 'required|array',
            'types.*.id'                  => 'required|integer|exists:document_types,id',
            'types.*.label'               => 'required|string|max:100',
            'types.*.sort_order'          => 'required|integer|min:0',
            'types.*.is_active'           => 'required',
            'types.*.listing_types'       => 'nullable|array',
            'types.*.listing_types.*'     => 'in:sale,rental',
            'types.*.compliance_required' => 'nullable',
            'types.*.save_to_property'    => 'nullable',
            'types.*.save_to_contact'     => 'nullable',
            'types.*.contact_roles'       => ['nullable', 'array'],
            'types.*.contact_roles.*'     => [Rule::in(SplitterDocType::CONTACT_ROLES)],
            'types.*.fica_slot'           => ['nullable', Rule::in(SplitterDocType::FICA_SLOTS)],
        ]);

        // Per-agency compliance flag target. An unchecked checkbox is simply
        // absent from the payload, so each row is explicitly set true/false.
        $agencyId = $this->currentAgencyId();

        foreach ($request->input('types') as $data) {
            $docType = SplitterDocType::find($data['id']);
            if (! $docType) continue;

            $listingTypes = array_values(array_filter($data['listing_types'] ?? []));

            $docType->update([
                'label'         => $data['label'],
                'sort_order'    => $data['sort_order'],
                'is_active'     => filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN),
                'listing_types' => !empty($listingTypes) ? $listingTypes : null,
            ]);

            if ($agencyId) {
                $required = filter_var($data['compliance_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $this->compliance->setRequired($agencyId, (int) $data['id'], $required);

                // AT-105 — persist the per-agency "Save To" destination. An
                // unchecked box is absent from the payload, so each flag is set
                // explicitly true/false.
                $saveToProperty = filter_var($data['save_to_property'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $saveToContact  = filter_var($data['save_to_contact'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $this->compliance->setDestination($agencyId, (int) $data['id'], $saveToProperty, $saveToContact);

                // AT-105 enh — persist the per-agency contact_roles SET +
                // fica_slot routing. Unticked roles are absent from the payload,
                // so the set is rebuilt explicitly (never inherits a stale value).
                $roles    = array_values(array_filter((array) ($data['contact_roles'] ?? []), fn ($r) => in_array($r, SplitterDocType::CONTACT_ROLES, true)));
                $ficaSlot = $data['fica_slot'] ?? 'none';
                $ficaSlot = in_array($ficaSlot, SplitterDocType::FICA_SLOTS, true) ? $ficaSlot : 'none';
                $this->compliance->setRoleConfig($agencyId, (int) $data['id'], $roles, $ficaSlot);
            }
        }

        return back()->with('success', 'All changes saved.');
    }

    /**
     * Effective agency for the current user. Falls back to the first agency so
     * the per-agency compliance column still renders for a cross-agency owner
     * who has not switched into a specific agency.
     */
    private function currentAgencyId(): ?int
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $id = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);

        if ($id) {
            return (int) $id;
        }

        return (int) (DB::table('agencies')->orderBy('id')->value('id') ?? 0) ?: null;
    }
}
