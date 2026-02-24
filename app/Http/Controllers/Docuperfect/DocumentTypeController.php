<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\DocumentType;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $types = DocumentType::orderBy('sort_order')->orderBy('name')->get();

        return view('docuperfect.settings.types', compact('types'));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        DocumentType::create([
            'name' => $request->input('name'),
            'sort_order' => $request->input('sort_order') ?? ((int) DocumentType::max('sort_order') + 10),
        ]);

        return redirect()->route('docuperfect.settings.types')
            ->with('status', "Document type \"{$request->input('name')}\" created.");
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $type = DocumentType::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $type->update([
            'name' => $request->input('name'),
            'sort_order' => $request->input('sort_order', 0),
        ]);

        return redirect()->route('docuperfect.settings.types')
            ->with('status', "Document type \"{$type->name}\" updated.");
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $type = DocumentType::findOrFail($id);

        if ($type->templates()->count() > 0) {
            return redirect()->route('docuperfect.settings.types')
                ->with('error', "Cannot delete \"{$type->name}\" — it has templates assigned.");
        }

        $name = $type->name;
        $type->delete();

        return redirect()->route('docuperfect.settings.types')
            ->with('status', "Document type \"{$name}\" deleted.");
    }

    public function reorder(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:docuperfect_document_types,id',
        ]);

        foreach ($request->input('order') as $index => $id) {
            DocumentType::where('id', $id)->update(['sort_order' => $index * 10]);
        }

        return response()->json(['ok' => true]);
    }
}
