<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\WebPack;
use App\Models\Docuperfect\WebPackItem;
use Illuminate\Http\Request;

class WebPackController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $agencyId = $user->effectiveAgencyId();

        $webPacks = WebPack::where('agency_id', $agencyId)
            ->with(['items.template', 'createdBy'])
            ->orderBy('name')
            ->get();

        $canManage = $user->hasPermission('access_docuperfect_packs');

        return view('docuperfect.web-packs.index', compact('webPacks', 'canManage'));
    }

    public function create(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('access_docuperfect_packs')) {
            abort(403);
        }

        $templates = Template::where('render_type', 'web')
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        return view('docuperfect.web-packs.form', compact('templates'));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('access_docuperfect_packs')) {
            abort(403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.template_id' => 'required|exists:docuperfect_templates,id',
            'items.*.slot_type' => 'nullable|in:required,selectable,optional',
            'items.*.slot_group' => 'nullable|integer|min:1|max:99',
            'items.*.slot_label' => 'nullable|string|max:255',
        ]);

        $webPack = WebPack::create([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'agency_id' => $user->effectiveAgencyId(),
            'created_by' => $user->id,
        ]);

        foreach ($request->input('items') as $i => $itemData) {
            WebPackItem::create([
                'web_pack_id' => $webPack->id,
                'template_id' => $itemData['template_id'],
                'sort_order' => $i * 10,
                'slot_type' => $itemData['slot_type'] ?? 'required',
                'slot_group' => $itemData['slot_group'] ?? null,
                'slot_label' => $itemData['slot_label'] ?? null,
            ]);
        }

        return redirect()->route('docuperfect.web-packs.index')
            ->with('status', "Web Pack \"{$webPack->name}\" created.");
    }

    public function edit(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('access_docuperfect_packs')) {
            abort(403);
        }

        $webPack = WebPack::with('items.template')->findOrFail($id);

        $templates = Template::where('render_type', 'web')
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();

        return view('docuperfect.web-packs.form', compact('webPack', 'templates'));
    }

    public function update(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('access_docuperfect_packs')) {
            abort(403);
        }

        $webPack = WebPack::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.template_id' => 'required|exists:docuperfect_templates,id',
            'items.*.slot_type' => 'nullable|in:required,selectable,optional',
            'items.*.slot_group' => 'nullable|integer|min:1|max:99',
            'items.*.slot_label' => 'nullable|string|max:255',
        ]);

        $webPack->update([
            'name' => $request->input('name'),
            'description' => $request->input('description'),
        ]);

        // Delete old items and recreate
        $webPack->items()->delete();

        foreach ($request->input('items') as $i => $itemData) {
            WebPackItem::create([
                'web_pack_id' => $webPack->id,
                'template_id' => $itemData['template_id'],
                'sort_order' => $i * 10,
                'slot_type' => $itemData['slot_type'] ?? 'required',
                'slot_group' => $itemData['slot_group'] ?? null,
                'slot_label' => $itemData['slot_label'] ?? null,
            ]);
        }

        return redirect()->route('docuperfect.web-packs.index')
            ->with('status', "Web Pack \"{$webPack->name}\" updated.");
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('access_docuperfect_packs')) {
            abort(403);
        }

        $webPack = WebPack::findOrFail($id);
        $name = $webPack->name;
        $webPack->delete(); // soft delete

        return redirect()->route('docuperfect.web-packs.index')
            ->with('status', "Web Pack \"{$name}\" archived.");
    }
}
