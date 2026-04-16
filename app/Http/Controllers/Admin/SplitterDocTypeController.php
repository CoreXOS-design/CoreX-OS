<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SplitterDocType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SplitterDocTypeController extends Controller
{
    public function index()
    {
        $types = SplitterDocType::orderBy('sort_order')->get();
        $context = request()->routeIs('admin.settings.*') ? 'settings' : 'splitter';

        return view('admin.splitter.doc-types', compact('types', 'context'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'label' => 'required|string|max:100',
        ]);

        $slug = Str::slug($request->input('label'), '_');

        if (SplitterDocType::where('slug', $slug)->exists()) {
            return back()->withErrors(['label' => "A type with slug '{$slug}' already exists."]);
        }

        $maxSort = SplitterDocType::max('sort_order') ?? 0;

        SplitterDocType::create([
            'slug'       => $slug,
            'label'      => $request->input('label'),
            'sort_order' => $maxSort + 1,
            'is_active'  => true,
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
            'types'                     => 'required|array',
            'types.*.id'                => 'required|integer|exists:document_types,id',
            'types.*.label'             => 'required|string|max:100',
            'types.*.sort_order'        => 'required|integer|min:0',
            'types.*.is_active'         => 'required',
            'types.*.listing_types'     => 'nullable|array',
            'types.*.listing_types.*'   => 'in:sale,rental',
        ]);

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
        }

        return back()->with('success', 'All changes saved.');
    }
}
