<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateSignatureZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $showArchived = $request->boolean('archived');

        $query = Template::visibleTo($user)->with(['owner', 'branches', 'documentType']);
        $templates = $showArchived
            ? $query->archived()->orderByDesc('archived_at')->get()
            : $query->active()->orderBy('name')->get();

        $documentTypes = DocumentType::orderBy('sort_order')->get();

        return view('docuperfect.templates.index', compact('templates', 'showArchived', 'documentTypes', 'user'));
    }

    public function upload(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:51200',
            'name' => 'nullable|string|max:255',
        ]);

        $file = $request->file('pdf');
        $name = $request->input('name') ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $template = Template::create([
            'name' => $name,
            'template_type' => 'sales',
            'page_count' => 0,
            'fields_json' => [],
            'is_global' => false,
            'owner_id' => $user->id,
        ]);

        return redirect()->route('docuperfect.templates.edit', $template->id)
            ->with('status', 'Template created. Upload page images from the editor.');
    }

    public function edit(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::with(['branches', 'documentType'])->findOrFail($id);
        $branches = \App\Models\Branch::orderBy('name')->get();
        $documentTypes = DocumentType::orderBy('sort_order')->get();
        $namedFields = NamedField::orderBy('sort_order')->get();

        // Load signature zones for the JS editor
        $signatureZones = $template->signatureZones()
            ->orderBy('page_index')
            ->orderBy('sort_order')
            ->get()
            ->map(function ($z) {
                return [
                    '_id' => 'db_' . $z->id,
                    'id' => $z->id,
                    'page_index' => $z->page_index,
                    'x_position' => (float) $z->x_position,
                    'y_position' => (float) $z->y_position,
                    'width' => (float) $z->width,
                    'height' => (float) $z->height,
                    'type' => $z->type,
                    'assigned_parties' => $z->assigned_parties ?? [],
                    'label' => $z->label,
                    'required' => (bool) $z->required,
                ];
            })
            ->values();

        return view('docuperfect.templates.edit', compact('template', 'branches', 'documentTypes', 'namedFields', 'signatureZones', 'user'));
    }

    public function saveFields(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::findOrFail($id);

        $data = [];

        if ($request->has('fields')) {
            $data['fields_json'] = $request->input('fields');
        }
        if ($request->has('name')) {
            $data['name'] = $request->input('name');
        }
        if ($request->has('template_type')) {
            $data['template_type'] = $request->input('template_type');
        }
        if ($request->has('document_type_id')) {
            $data['document_type_id'] = $request->input('document_type_id') ?: null;
        }
        if ($request->has('is_global')) {
            $data['is_global'] = $request->boolean('is_global');
        }

        if (!empty($data)) {
            $template->update($data);
        }

        if ($request->has('allowed_branches')) {
            if ($request->boolean('is_global')) {
                $template->branches()->detach();
            } else {
                $template->branches()->sync($request->input('allowed_branches', []));
            }
        }

        // Save signature zones (replace-all pattern)
        if ($request->has('signature_zones')) {
            $template->signatureZones()->delete();

            $zones = $request->input('signature_zones', []);
            foreach ($zones as $i => $zoneData) {
                TemplateSignatureZone::create([
                    'template_id' => $template->id,
                    'page_index' => $zoneData['page_index'] ?? 0,
                    'x_position' => $zoneData['x_position'] ?? 0,
                    'y_position' => $zoneData['y_position'] ?? 0,
                    'width' => $zoneData['width'] ?? 25,
                    'height' => $zoneData['height'] ?? 6,
                    'type' => $zoneData['type'] ?? 'signature',
                    'assigned_parties' => $zoneData['assigned_parties'] ?? [],
                    'label' => $zoneData['label'] ?? null,
                    'required' => $zoneData['required'] ?? true,
                    'sort_order' => $i,
                ]);
            }
        }

        return response()->json(['ok' => true]);
    }

    public function uploadPageImages(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::findOrFail($id);

        $request->validate([
            'pages' => 'required|array',
            'pages.*' => 'required|string',
        ]);

        $pages = $request->input('pages');
        $dir = "docuperfect/templates/{$template->id}";

        foreach ($pages as $i => $base64) {
            $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#', '', $base64));
            Storage::put("{$dir}/page-{$i}.png", $imageData);
        }

        $template->update(['page_count' => count($pages)]);

        return response()->json(['ok' => true, 'page_count' => count($pages)]);
    }

    public function archive(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::findOrFail($id);
        $template->update(['archived_at' => now()]);

        return redirect()->route('docuperfect.templates.index')
            ->with('status', "Template \"{$template->name}\" archived.");
    }

    public function restore(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::findOrFail($id);
        $template->update(['archived_at' => null]);

        return redirect()->route('docuperfect.templates.index')
            ->with('status', "Template \"{$template->name}\" restored.");
    }

    public function copy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $original = Template::with(['branches', 'signatureZones'])->findOrFail($id);

        $copy = $original->replicate();
        $copy->name = $original->name . ' (Copy)';
        $copy->owner_id = $user->id;
        $copy->archived_at = null;
        $copy->save();

        // Copy branch associations
        $copy->branches()->sync($original->branches->pluck('id'));

        // Copy page images
        $srcDir = "docuperfect/templates/{$original->id}";
        $dstDir = "docuperfect/templates/{$copy->id}";
        for ($i = 0; $i < $original->page_count; $i++) {
            $srcPath = "{$srcDir}/page-{$i}.png";
            if (Storage::exists($srcPath)) {
                Storage::copy($srcPath, "{$dstDir}/page-{$i}.png");
            }
        }

        // Copy signature zones
        foreach ($original->signatureZones as $zone) {
            $zoneCopy = $zone->replicate();
            $zoneCopy->template_id = $copy->id;
            $zoneCopy->save();
        }

        return redirect()->route('docuperfect.templates.edit', $copy->id)
            ->with('status', "Template duplicated as \"{$copy->name}\".");
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->isAdmin() && !$user->isBranchManager()) {
            abort(403);
        }

        $template = Template::findOrFail($id);
        $name = $template->name;

        // Delete page images
        $dir = "docuperfect/templates/{$template->id}";
        Storage::deleteDirectory($dir);

        $template->delete();

        return redirect()->route('docuperfect.templates.index')
            ->with('status', "Template \"{$name}\" deleted.");
    }
}
