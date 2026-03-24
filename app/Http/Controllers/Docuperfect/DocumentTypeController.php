<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DocumentTypeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $types = DocumentType::orderBy('sort_order')->orderBy('label')->get();

        return view('docuperfect.settings.types', compact('types'));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $label = $request->input('name');
        $slug = Str::slug($label, '_');

        DocumentType::create([
            'label' => $label,
            'slug' => $slug,
            'sort_order' => $request->input('sort_order') ?? ((int) DocumentType::max('sort_order') + 10),
            'is_active' => true,
        ]);

        return back()->with('status', "Document type \"{$label}\" created.");
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $type = DocumentType::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $type->update([
            'label' => $request->input('name'),
            'sort_order' => $request->input('sort_order', 0),
        ]);

        return back()->with('status', "Document type \"{$type->name}\" updated.");
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $type = DocumentType::findOrFail($id);

        if ($type->templates()->count() > 0) {
            return back()->with('error', "Cannot delete \"{$type->name}\" — it has templates assigned.");
        }

        $name = $type->name;
        $type->delete();

        return back()->with('status', "Document type \"{$name}\" archived.");
    }

    public function reorder(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:document_types,id',
        ]);

        foreach ($request->input('order') as $index => $id) {
            DocumentType::where('id', $id)->update(['sort_order' => $index * 10]);
        }

        return response()->json(['ok' => true]);
    }

    // ── Restore soft-deleted ──

    public function restore($id)
    {
        abort_unless(auth()->user()->hasPermission('manage_templates'), 403);
        $record = DocumentType::onlyTrashed()->findOrFail($id);
        $record->restore();
        return redirect()->back()->with('success', 'Record restored.');
    }
}
