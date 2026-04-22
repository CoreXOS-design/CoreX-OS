<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Compliance\AgencyDocumentTypeConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AgencyDocumentTypeConfigController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->query('filter', 'active');

        $query = AgencyDocumentTypeConfig::ordered();

        if ($filter === 'active') {
            $query->active();
        } elseif ($filter === 'archived') {
            $query->where('is_active', false);
        }

        $types = $query->get();

        $counts = [
            'active'   => AgencyDocumentTypeConfig::active()->count(),
            'archived' => AgencyDocumentTypeConfig::where('is_active', false)->count(),
            'all'      => AgencyDocumentTypeConfig::count(),
        ];

        return view('compliance.document-types.index', compact('types', 'filter', 'counts'));
    }

    public function create()
    {
        return view('compliance.document-types.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                   => 'required|string|max:100',
            'slug'                   => [
                'required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('agency_document_type_configs')->where('agency_id', auth()->user()->effectiveAgencyId()),
            ],
            'description'            => 'nullable|string|max:500',
            'has_expiry'             => 'boolean',
            'renewal_days'           => 'nullable|integer|min:1|max:3650',
            'required'               => 'boolean',
            'sort_order'             => 'integer|min:0',
        ]);

        if (empty($validated['has_expiry'])) {
            $validated['renewal_days'] = null;
        }

        AgencyDocumentTypeConfig::create($validated);

        return redirect()->route('compliance.document-types.index')
            ->with('success', "Document type \"{$validated['name']}\" created.");
    }

    public function edit(AgencyDocumentTypeConfig $slug)
    {
        return view('compliance.document-types.edit', ['type' => $slug]);
    }

    public function update(Request $request, AgencyDocumentTypeConfig $slug)
    {
        $type = $slug;

        $validated = $request->validate([
            'name'                   => 'required|string|max:100',
            'slug'                   => [
                'required', 'string', 'max:100', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('agency_document_type_configs')
                    ->where('agency_id', auth()->user()->effectiveAgencyId())
                    ->ignore($type->id),
            ],
            'description'            => 'nullable|string|max:500',
            'has_expiry'             => 'boolean',
            'renewal_days'           => 'nullable|integer|min:1|max:3650',
            'required'               => 'boolean',
            'sort_order'             => 'integer|min:0',
        ]);

        if (empty($validated['has_expiry'])) {
            $validated['renewal_days'] = null;
        }

        $type->update($validated);

        return redirect()->route('compliance.document-types.index')
            ->with('success', "Document type \"{$type->name}\" updated.");
    }

    public function archive(AgencyDocumentTypeConfig $slug)
    {
        $slug->update(['is_active' => false]);

        return redirect()->route('compliance.document-types.index', ['filter' => 'all'])
            ->with('success', "\"{$slug->name}\" archived.");
    }

    public function restore(AgencyDocumentTypeConfig $slug)
    {
        $slug->update(['is_active' => true]);

        return redirect()->route('compliance.document-types.index')
            ->with('success', "\"{$slug->name}\" restored.");
    }
}
