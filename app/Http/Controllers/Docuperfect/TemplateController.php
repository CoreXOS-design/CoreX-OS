<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateSignatureZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $query = Template::visibleTo($user)->with(['owner', 'branches', 'documentType']);

        // Status filter (active/archived)
        $status = $request->input('status', 'active');
        if ($status === 'archived') {
            $query->archived();
        } else {
            $query->active();
        }

        // Search
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Document type filter
        if ($docType = $request->input('document_type')) {
            if ($docType === 'none') {
                $query->whereNull('document_type_id');
            } else {
                $query->where('document_type_id', $docType);
            }
        }

        // Template type filter (sales/rental/compliance)
        if ($type = $request->input('type')) {
            $query->where('template_type', $type);
        }

        // Visibility filter
        if ($visibility = $request->input('visibility')) {
            if ($visibility === 'global') {
                $query->where('is_global', true);
            } elseif ($visibility === 'branch') {
                $query->where('is_global', false);
            }
        }

        // Sort
        $sortField = in_array($request->input('sort'), ['name', 'created_at', 'page_count']) ? $request->input('sort') : 'name';
        $sortDir = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $query->orderBy($sortField, $sortDir);

        $templates = $query->paginate(20)->withQueryString();

        $documentTypes = DocumentType::orderBy('sort_order')->get();
        $showArchived = $status === 'archived';

        return view('docuperfect.templates.index', compact('templates', 'showArchived', 'documentTypes', 'user'));
    }

    public function upload(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
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
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $template = Template::with(['branches', 'documentType'])->findOrFail($id);

        if ($template->render_type === 'web') {
            return $this->editWeb($template);
        }

        $branches = \App\Models\Branch::orderBy('name')->get();
        $documentTypes = DocumentType::orderBy('sort_order')->get();
        $namedFields = NamedField::orderBy('sort_order')->get();

        // Load source-mapped named fields grouped by source type for System Fields panel
        $systemFields = DB::table('docuperfect_named_fields')
            ->whereNull('deleted_at')
            ->whereNotNull('source_type')
            ->where('source_type', '!=', 'manual')
            ->orderBy('source_type')
            ->orderBy('source_contact_type')
            ->orderBy('name')
            ->get()
            ->groupBy(function ($f) {
                if ($f->source_type === 'contact') {
                    return 'contact_' . strtolower($f->source_contact_type ?? 'other');
                }
                return $f->source_type;
            });

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

        return view('docuperfect.templates.edit', compact('template', 'branches', 'documentTypes', 'namedFields', 'systemFields', 'signatureZones', 'user'));
    }

    public function saveFields(Request $request, $id)
    {
        $user = $request->user();
        Log::info('TemplateController: saveFields called', [
            'template_id' => $id,
            'user_id' => $user->id ?? null,
        ]);

        $canManage = $user->hasPermission('manage_templates');
        Log::info('TemplateController: permission check', ['can_manage' => $canManage]);

        if (!$canManage) {
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
        if ($request->has('header_display')) {
            $allowed = ['first_page', 'all_pages', 'none'];
            $val = $request->input('header_display');
            $data['header_display'] = in_array($val, $allowed) ? $val : 'first_page';
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
        if (!$user->hasPermission('manage_templates')) {
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
        if (!$user->hasPermission('manage_templates')) {
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
        if (!$user->hasPermission('manage_templates')) {
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
        if (!$user->hasPermission('manage_templates')) {
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

    private function editWeb(Template $template)
    {
        $branches = \App\Models\Branch::orderBy('name')->get();
        $documentTypes = DocumentType::orderBy('sort_order')->get();
        $namedFields = NamedField::orderBy('sort_order')->get();

        return view('docuperfect.templates.edit-web', compact('template', 'branches', 'documentTypes', 'namedFields'));
    }

    public function webPreview(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $template = Template::findOrFail($id);

        if (!$template->blade_view) {
            abort(404, 'No blade view configured for this template.');
        }

        // Build placeholder values from fields_json
        $viewData = [];
        foreach ($template->fields_json ?? [] as $field) {
            $varName = $field['field_name'] ?? '';
            if (empty($varName)) {
                $varName = str_replace('.', '_', $field['id'] ?? '');
            }
            if (empty($varName)) {
                continue;
            }
            $label = $field['label'] ?? '';
            if (empty($label)) {
                $label = $varName;
            }
            $viewData[$varName] = '[' . $label . ']';
        }

        // Pass signing_parties so the signature-block component renders correct parties
        if (!empty($template->signing_parties)) {
            $viewData['signing_parties'] = $template->signing_parties;
        }

        // Pass header_display so company-header component respects template setting
        $viewData['header_display'] = $template->header_display ?? 'first_page';

        $html = view($template->blade_view, $viewData)->render();

        return response($html)->header('Content-Type', 'text/html');
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $template = Template::findOrFail($id);
        $name = $template->name;

        // Soft delete — page images preserved on disk for potential restore
        $template->delete();

        return redirect()->route('docuperfect.templates.index')
            ->with('status', "Template \"{$name}\" archived.");
    }
}
