<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\NamedField;
use Illuminate\Http\Request;

class NamedFieldController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $fields = NamedField::orderBy('sort_order')->orderBy('name')->get();

        return view('docuperfect.settings.named-fields', compact('fields'));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'field_type' => 'required|in:text,date,selection',
            'default_options' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $options = null;
        if ($request->input('field_type') === 'selection' && $request->input('default_options')) {
            $options = array_map('trim', explode(',', $request->input('default_options')));
        }

        NamedField::create([
            'name' => $request->input('name'),
            'field_type' => $request->input('field_type'),
            'default_options' => $options,
            'sort_order' => $request->input('sort_order') ?? ((int) NamedField::max('sort_order') + 10),
        ]);

        return redirect()->route('docuperfect.settings.namedFields')
            ->with('status', "Named field \"{$request->input('name')}\" created.");
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $field = NamedField::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'field_type' => 'required|in:text,date,selection',
            'default_options' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $options = null;
        if ($request->input('field_type') === 'selection' && $request->input('default_options')) {
            $options = array_map('trim', explode(',', $request->input('default_options')));
        }

        $field->update([
            'name' => $request->input('name'),
            'field_type' => $request->input('field_type'),
            'default_options' => $options,
            'sort_order' => $request->input('sort_order', 0),
        ]);

        return redirect()->route('docuperfect.settings.namedFields')
            ->with('status', "Named field \"{$field->name}\" updated.");
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $field = NamedField::findOrFail($id);
        $name = $field->name;
        $field->delete();

        return redirect()->route('docuperfect.settings.namedFields')
            ->with('status', "Named field \"{$name}\" deleted.");
    }

    public function reorder(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403);
        }

        $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:docuperfect_named_fields,id',
        ]);

        foreach ($request->input('order') as $index => $id) {
            NamedField::where('id', $id)->update(['sort_order' => $index * 10]);
        }

        return response()->json(['ok' => true]);
    }
}
