<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\AgencySigningParty;
use App\Models\Docuperfect\CdsDraft;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateSignatureZone;
use Illuminate\Http\Request;
use App\Services\Docuperfect\CdsRendererService;
use Illuminate\Support\Facades\Artisan;
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

        // Category filter (sales/rentals)
        if ($cat = $request->input('category')) {
            if ($cat === 'none') {
                $query->whereNull('category');
            } else {
                $query->where('category', $cat);
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

        // CDS templates route to the CDS builder (DB-backed draft)
        if ($template->template_type === 'cds') {
            // Reuse existing unsaved draft for this template+user (prevents data loss)
            $draft = CdsDraft::where('source_template_id', $template->id)
                ->where('user_id', auth()->id())
                ->where('status', 'draft')
                ->latest()
                ->first();

            if (!$draft) {
                $draft = CdsDraft::create([
                    'user_id' => auth()->id(),
                    'agency_id' => auth()->user()->agency_id ?? null,
                    'template_name' => $template->name,
                    'cds_json' => $template->cds_json,
                    'tags' => $template->editor_state['tags'] ?? null,
                    'mappings' => $template->editor_state['mappings'] ?? null,
                    'tagged_html' => $template->editor_state['tagged_html'] ?? null,
                    'settings' => [
                        'is_esign' => $template->is_esign,
                        'party_mode' => $template->party_mode,
                        'allowed_delivery_modes' => $template->allowed_delivery_modes,
                        'security_tier' => $template->security_tier,
                        'category' => $template->category,
                        'document_type_id' => $template->document_type_id,
                    ],
                    'source_template_id' => $template->id,
                    'status' => 'draft',
                ]);
            }

            return redirect()->route('docuperfect.cds.builder', $draft);
        }

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
        if ($request->has('category')) {
            $val = $request->input('category');
            $data['category'] = in_array($val, ['sales', 'rentals']) ? $val : null;
        }
        if ($request->has('is_global')) {
            $data['is_global'] = $request->boolean('is_global');
        }
        if ($request->has('is_esign')) {
            $data['is_esign'] = $request->boolean('is_esign');
        }
        if ($request->has('party_mode')) {
            $allowed = ['shared', 'per_party'];
            $val = $request->input('party_mode');
            $data['party_mode'] = in_array($val, $allowed) ? $val : 'shared';
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

    // ===== CDS Document Engine — DB-backed draft pipeline =====

    public function cdsBuilder(CdsDraft $draft)
    {
        $user = auth()->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }
        abort_if($draft->user_id !== $user->id, 403);

        $renderer = app(CdsRendererService::class);
        $html = $renderer->render($draft->cds_json);

        // Determine if this is a restore (has saved tags/mappings)
        $hasSavedState = !empty($draft->tags) && !empty($draft->mappings);

        // Extract field summary from CDS for the right panel
        $fields = $this->extractFieldsFromCds($draft->cds_json);

        // Load named fields grouped by source_type + contact_type
        $namedFields = NamedField::whereNull('deleted_at')
            ->orderBy('source_type')
            ->orderBy('source_contact_type')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'source_type', 'source_column', 'source_contact_type', 'field_type']);

        $groupedFields = [];
        foreach ($namedFields as $nf) {
            if ($nf->source_type === 'contact' && $nf->source_contact_type) {
                $key = 'contact_' . strtolower($nf->source_contact_type);
            } else {
                $key = $nf->source_type ?? 'manual';
            }
            $groupedFields[$key][] = [
                'id' => $nf->id,
                'name' => $nf->name,
                'source_type' => $nf->source_type,
                'source_column' => $nf->source_column,
                'source_contact_type' => $nf->source_contact_type,
                'field_type' => $nf->field_type,
            ];
        }

        // Load field groups
        $agencyId = $user->effectiveAgencyId() ?? null;
        $namedFieldMap = $namedFields->keyBy('id');

        $fieldGroups = FieldGroup::whereNull('deleted_at')
            ->where(function ($q) use ($agencyId) {
                $q->where('is_global', true);
                if ($agencyId) {
                    $q->orWhere('agency_id', $agencyId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'layout', 'fields', 'is_global'])
            ->map(function ($g) use ($namedFieldMap) {
                $resolvedFields = collect($g->fields ?? [])->map(function ($f) use ($namedFieldMap) {
                    $nf = $namedFieldMap->get($f['named_field_id'] ?? null);
                    return [
                        'named_field_id' => $f['named_field_id'] ?? null,
                        'label' => $f['label_override'] ?? ($nf ? $nf->name : 'Unknown'),
                        'source_type' => $nf ? $nf->source_type : 'manual',
                        'source_contact_type' => $nf ? $nf->source_contact_type : '',
                    ];
                })->values()->all();

                return [
                    'id' => $g->id,
                    'name' => $g->name,
                    'description' => $g->description,
                    'layout' => $g->layout,
                    'is_global' => $g->is_global,
                    'fields' => $resolvedFields,
                ];
            })->values()->all();

        // Load signing parties for agency
        $agencyParties = $this->loadAgencyParties($user);

        // Load source template for fallback values (category, document_type_id)
        $sourceTemplate = $draft->source_template_id
            ? Template::find($draft->source_template_id)
            : null;

        return view('docuperfect.templates.cds-builder', [
            'draftId' => $draft->id,
            'cds' => $draft->cds_json,
            'html' => $html,
            'fields' => $fields,
            'title' => $draft->template_name,
            'templateName' => $draft->template_name,
            'sourceTemplateId' => $draft->source_template_id,
            'sourceTemplate' => $sourceTemplate,
            'groupedFields' => $groupedFields,
            'fieldGroups' => $fieldGroups,
            'namedFieldsAll' => $namedFields->map(fn($nf) => [
                'id' => $nf->id,
                'name' => $nf->name,
                'source_type' => $nf->source_type,
                'source_contact_type' => $nf->source_contact_type,
            ])->values()->all(),
            'signingParties' => $agencyParties,
            'hasSavedState' => $hasSavedState,
            'savedTags' => $draft->tags ?? [],
            'savedMappings' => $draft->mappings ?? (object)[],
            'savedTaggedHtml' => $draft->tagged_html ?? '',
            'savedSettings' => $draft->settings ?? [],
        ]);
    }

    public function cdsSaveMappings(Request $request)
    {
        $draft = CdsDraft::findOrFail($request->input('draft_id'));
        abort_if($draft->user_id !== auth()->id(), 403);

        $draft->update([
            'tags' => $request->input('tags'),
            'mappings' => $request->input('mappings'),
            'tagged_html' => $request->input('tagged_html'),
        ]);

        return response()->json(['status' => 'saved']);
    }

    public function cdsSaveDraft(Request $request)
    {
        $draft = CdsDraft::findOrFail($request->input('draft_id'));
        abort_if($draft->user_id !== auth()->id(), 403);

        Log::info('CDS_SAVE_DRAFT', [
            'draft_id' => $draft->id,
            'has_tags' => !empty($request->input('tags')),
            'tag_count' => is_array($request->input('tags')) ? count($request->input('tags')) : 0,
            'has_mappings' => !empty($request->input('mappings')),
            'mapping_keys' => is_array($request->input('mappings')) ? array_keys($request->input('mappings')) : [],
            'sample_mapping' => is_array($request->input('mappings')) ? array_slice($request->input('mappings'), 0, 1, true) : null,
            'has_settings' => !empty($request->input('settings')),
        ]);

        $draft->update([
            'template_name' => $request->input('template_name', $draft->template_name),
            'tags' => $request->input('tags'),
            'mappings' => $request->input('mappings'),
            'tagged_html' => $request->input('tagged_html'),
            'settings' => $request->input('settings'),
        ]);

        return response()->json([
            'status' => 'saved',
            'saved_at' => now()->toIso8601String(),
        ]);
    }

    public function cdsGenerate(Request $request)
    {
        $user = auth()->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $draft = CdsDraft::findOrFail($request->input('draft_id'));
        abort_if($draft->user_id !== $user->id, 403);

        $templateData = [
            'name' => $request->input('template_name', $draft->template_name),
            'render_type' => 'web',
            'template_type' => 'cds',
            'cds_json' => $draft->cds_json,
            'field_mappings' => $draft->mappings,
            'fields_json' => $this->convertMappingsToFieldsJson($draft->mappings ?? []),
            'is_esign' => $request->boolean('is_esign', true),
            'party_mode' => $request->input('party_mode', 'shared'),
            'allowed_delivery_modes' => $request->input('allowed_delivery_modes', 'esign,wet_ink,download'),
            'security_tier' => $request->input('security_tier', 'enhanced'),
            'signing_parties' => $request->input('signing_parties') ? json_decode($request->input('signing_parties'), true) : null,
            'category' => in_array($request->input('category'), ['sales', 'rentals']) ? $request->input('category') : null,
            'document_type_id' => $request->input('document_type_id') ?: null,
            'is_global' => true,
            'owner_id' => $user->id,
            'editor_state' => [
                'tags' => $draft->tags,
                'mappings' => $draft->mappings,
                'tagged_html' => $draft->tagged_html,
            ],
        ];

        if ($draft->source_template_id) {
            $template = Template::findOrFail($draft->source_template_id);
            $template->update($templateData);
        } else {
            $template = Template::create($templateData);
        }

        // Generate blade view — use tagged_html (user-edited) as source, fall back to cds_json
        $bladeView = $this->generateCdsBladeView(
            $draft->cds_json,
            $draft->mappings ?? [],
            $template->id,
            $template->name,
            $template->signing_parties,
            $draft->tagged_html,
            $draft->tags ?? []
        );
        $template->update(['blade_view' => $bladeView]);

        // Clear compiled view cache so the e-sign wizard renders the fresh blade file
        Artisan::call('view:clear');

        // E-sign reset Commit 5 (Q1) — drop tag-ids that no longer
        // appear in the saved tagged_html / cds_json. Without this
        // step a delete of "Seller 2" block in the visual builder
        // leaves the 5 corresponding field_mappings entries behind,
        // and the next builder load reads them and re-renders the
        // deleted block — the bug Johan saw as "save 1 seller, reload
        // 4 sellers".
        $prunedCount = $template->fresh()->pruneOrphanFieldMappings();
        if ($prunedCount > 0) {
            \Illuminate\Support\Facades\Log::info('cdsGenerate: pruned orphan field_mappings', [
                'template_id' => $template->id,
                'removed'     => $prunedCount,
            ]);
        }

        // E-sign reset Commit 5 (Q1) — draft lifecycle cleanup.
        // The draft system should hold ONE in-progress draft per
        // (user, template). The investigation found 5 CdsDraft rows
        // for template 111 — every save left another saved draft
        // behind, and the canonical accessor's tier-1 lookup could
        // serve any of them. Soft-delete the applied draft AND any
        // older saved/draft rows for the same (user, source_template)
        // pair so the canonical accessor falls through cleanly to the
        // freshly-saved editor_state next time.
        $draft->update(['status' => 'saved']);
        $draft->delete();
        if ($draft->source_template_id) {
            CdsDraft::where('user_id', $user->id)
                ->where('source_template_id', $draft->source_template_id)
                ->where('id', '!=', $draft->id)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);
        }

        return redirect()->route('docuperfect.templates.index')
            ->with('success', 'Template saved: ' . $template->name);
    }

    public function cdsDestroyDraft(CdsDraft $draft)
    {
        abort_if($draft->user_id !== auth()->id(), 403);
        $draft->delete(); // soft delete

        return redirect()->route('docuperfect.import.index')
            ->with('info', 'Draft discarded.');
    }

    private function extractFieldsFromCds(array $cds): array
    {
        $fields = [];
        $index = 0;

        foreach ($cds['sections'] ?? [] as $section) {
            $this->collectFieldsFromContent($section, $fields, $index);
        }

        return $fields;
    }

    private function collectFieldsFromContent(array $section, array &$fields, int &$index): void
    {
        foreach ($section['content'] ?? [] as $item) {
            if (($item['type'] ?? '') === 'field_placeholder') {
                $fields[] = [
                    'index' => $index++,
                    'label' => $item['label'] ?? 'FIELD',
                    'field_name' => $item['field_name'] ?? '',
                    'field_type' => $item['field_type'] ?? 'text',
                    'source' => $this->inferSource($item['field_name'] ?? ''),
                    'filled_by' => $this->inferFilledBy($item['field_name'] ?? ''),
                ];
            }
        }

        // Check label_value_group pairs
        foreach ($section['pairs'] ?? [] as $pair) {
            foreach ($pair['fields'] ?? [] as $item) {
                if (($item['type'] ?? '') === 'field_placeholder') {
                    $fields[] = [
                        'index' => $index++,
                        'label' => $item['label'] ?? $pair['label'] ?? 'FIELD',
                        'field_name' => $item['field_name'] ?? '',
                        'field_type' => $item['field_type'] ?? 'text',
                        'source' => $this->inferSource($item['field_name'] ?? ''),
                        'filled_by' => $this->inferFilledBy($item['field_name'] ?? ''),
                    ];
                }
            }
        }
    }

    private function inferSource(string $fieldName): string
    {
        if (str_starts_with($fieldName, 'property.')) return 'property';
        if (str_starts_with($fieldName, 'contact.')) return 'contact';
        if (str_starts_with($fieldName, 'deal.')) return 'deal';
        if (str_starts_with($fieldName, 'banking.')) return 'contact';
        if (str_starts_with($fieldName, 'signing.')) return 'manual';
        return 'manual';
    }

    private function inferFilledBy(string $fieldName): string
    {
        if (str_starts_with($fieldName, 'banking.')) return 'recipient';
        if (str_contains($fieldName, 'email')) return 'recipient';
        if (str_contains($fieldName, 'phone')) return 'recipient';
        if (str_starts_with($fieldName, 'property.')) return 'agent';
        if (str_starts_with($fieldName, 'deal.')) return 'agent';
        return 'agent';
    }

    private function convertMappingsToFieldsJson(array $mappings): array
    {
        return collect($mappings)
            ->filter(fn($m) => ($m['tag_type'] ?? 'input') === 'input')
            ->map(fn($m) => [
                'key' => $m['field_name'] ?? '',
                'label' => $m['label'] ?? '',
                'type' => $m['field_type'] ?? 'text',
                'assignedTo' => $m['filled_by'] ?? 'agent',
            ])->values()->toArray();
    }

    /**
     * Generate a blade view file for a CDS template.
     *
     * When $taggedHtml is provided (user-edited content from the CDS builder),
     * it is used as the document body. Field tag spans are replaced with Blade
     * variables using the same derivation as WebTemplateDataService.
     *
     * When $taggedHtml is null, falls back to rendering from CDS JSON via
     * CdsRendererService (original path, for backward compatibility).
     */
    private function generateCdsBladeView(
        array $cds,
        array $fieldMappings,
        int $templateId,
        string $templateName,
        ?array $signingParties = null,
        ?string $taggedHtml = null,
        ?array $tags = null
    ): string {
        // Determine document body HTML
        if (!empty($taggedHtml)) {
            $html = $this->processTaggedHtmlToBladeBody($taggedHtml, $fieldMappings);
        } else {
            // Legacy path: render from CDS JSON
            $filteredCds = $cds;
            $filteredCds['sections'] = array_values(array_filter(
                $cds['sections'] ?? [],
                fn($s) => !in_array($s['type'] ?? '', ['company_header', 'title'])
            ));

            // Enrich CDS JSON inline_signature sections with multi-party assignments
            // from field_mappings when available (field_mappings override CDS section parties)
            $sigMappingParties = $this->extractSigPartiesFromMappings($fieldMappings);
            if (!empty($sigMappingParties)) {
                $sigIdx = 0;
                foreach ($filteredCds['sections'] as &$section) {
                    if (($section['type'] ?? '') === 'inline_signature') {
                        if (isset($sigMappingParties[$sigIdx])) {
                            $section['parties'] = array_map(
                                fn($role) => ['role' => $role, 'label' => ucfirst(str_replace('_', ' ', $role))],
                                $sigMappingParties[$sigIdx]
                            );
                        }
                        $sigIdx++;
                    }
                }
                unset($section);
            }

            $renderer = app(CdsRendererService::class);
            $rawHtml = $renderer->render($filteredCds);

            // Replace CDS inline signature sections with Blade signature-line includes.
            // Each corex-signature-block within an inline section becomes a per-party include.
            $html = preg_replace_callback(
                '/<div\s+class="corex-signature-section"[^>]*>.*?<\/div>\s*<\/div>\s*<\/div>/s',
                function ($m) {
                    // Extract all data-marker-party values from the section
                    $parties = [];
                    if (preg_match_all('/data-marker-party="([^"]*)"/', $m[0], $pMatches)) {
                        $parties = array_unique($pMatches[1]);
                    }
                    if (!empty($parties)) {
                        $includes = [];
                        foreach ($parties as $p) {
                            $includes[] = '@include("docuperfect.web-templates.components.signature-line", [\'party\' => \'' . addslashes($p) . '\'])';
                        }
                        return implode("\n", $includes);
                    }
                    return '@include("docuperfect.web-templates.components.signature-line")';
                },
                $rawHtml
            );

            // Replace marker-based signature/initial placeholders (preserving party param)
            $html = preg_replace_callback(
                '/<span\s+class="corex-field"[^>]*data-marker-type="signature"[^>]*>.*?<\/span>/s',
                function ($m) {
                    $party = null;
                    if (preg_match('/data-marker-party="([^"]*)"/', $m[0], $pm)) {
                        $party = $pm[1];
                    }
                    if ($party) {
                        return '@include("docuperfect.web-templates.components.signature-line", [\'party\' => \'' . addslashes($party) . '\'])';
                    }
                    return '@include("docuperfect.web-templates.components.signature-line")';
                },
                $html
            );
            $html = preg_replace_callback(
                '/<span\s+class="corex-field"[^>]*data-marker-type="initial"[^>]*>.*?<\/span>/s',
                function ($m) {
                    $party = null;
                    if (preg_match('/data-marker-party="([^"]*)"/', $m[0], $pm)) {
                        $party = $pm[1];
                    }
                    if ($party) {
                        return '@include("docuperfect.web-templates.components.initials-line", [\'party\' => \'' . addslashes($party) . '\'])';
                    }
                    return '@include("docuperfect.web-templates.components.initials-line")';
                },
                $html
            );

            // Replace corex-field spans with Blade variable output
            $html = preg_replace_callback(
                '/<span\s+class="corex-field"[^>]*data-field-name="([^"]*)"[^>]*>.*?<\/span>/s',
                function ($matches) {
                    $fieldName = $matches[1];
                    if (empty(trim($fieldName))) {
                        return '<span class="corex-field-value" style="border-bottom:1px solid #333; min-width:80pt; display:inline-block;">&nbsp;</span>';
                    }
                    $varName = str_replace('.', '_', $fieldName);
                    $varName = preg_replace('/[^a-zA-Z0-9_]/', '_', $varName);
                    if (is_numeric($varName[0] ?? '')) {
                        $varName = 'f_' . $varName;
                    }
                    return '<span class="corex-field-value" data-field="' . e($varName) . '">'
                        . '{{ $' . $varName . ' ?? \'\' }}</span>';
                },
                $html
            );
        }

        // Build the blade template wrapper
        $title = e($cds['title'] ?? $templateName);

        $blade = <<<'BLADE'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
BLADE;
        $blade .= "\n    <title>{$title}</title>\n";
        $blade .= '    <link href="/css/corex-document.css" rel="stylesheet">' . "\n";
        $blade .= "    <link href=\"https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap\" rel=\"stylesheet\">\n";
        $blade .= "</head>\n<body>\n";
        $blade .= '<div class="corex-document-wrapper">' . "\n";
        $blade .= '<div class="corex-page">' . "\n\n";
        $blade .= '@include("docuperfect.web-templates.components.company-header")' . "\n\n";

        $blade .= $html . "\n\n";

        // Signature block — use the authoritative sales/rental classifier
        // (category/template_type aware) so a sales CDS template bakes
        // Seller/Buyer blocks, not Lessor/Lessee. The Template row is saved
        // before this runs (saveCds), so it resolves here. Name heuristic
        // only as a last resort for a not-yet-persisted template.
        $nameLower = strtolower($templateName);
        $tplForCtx = Template::find($templateId);
        $isSalesDoc = $tplForCtx
            ? $tplForCtx->isSalesDocument()
            : (str_contains($nameLower, 'sell') || str_contains($nameLower, 'sale')
                || str_contains($nameLower, 'authority') || str_contains($nameLower, 'otp')
                || str_contains($nameLower, 'purchase'));

        if (!empty($signingParties)) {
            $displayParties = Template::mapSigningPartyKeys($signingParties, $isSalesDoc);
        } elseif ($isSalesDoc) {
            $isAuthorityDoc = str_contains($nameLower, 'authority') || str_contains($nameLower, 'mandate');
            $displayParties = $isAuthorityDoc ? ['Seller', 'Agent'] : ['Seller', 'Buyer', 'Agent'];
        } else {
            $displayParties = ['Lessor', 'Lessee', 'Agent'];
        }

        $partiesPhp = '["' . implode('", "', $displayParties) . '"]';
        $blade .= '@include("docuperfect.web-templates.components.signature-block", ["parties" => ' . $partiesPhp . '])' . "\n\n";

        $blade .= "</div>\n</div>\n\n";
        $blade .= "</body>\n</html>\n";

        // Write to disk
        $viewDir = resource_path('views/docuperfect/web-templates/cds');
        if (!is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
        }

        $filename = "template-{$templateId}.blade.php";
        file_put_contents("{$viewDir}/{$filename}", $blade);

        return "docuperfect.web-templates.cds.template-{$templateId}";
    }

    /**
     * Convert tagged_html from the CDS builder into Blade-ready HTML.
     *
     * Replaces .doc-tag spans with Blade {{ $var }} output, using the same
     * variable name derivation as WebTemplateDataService::resolve().
     * Signature/initial tags become @include directives.
     */
    private function processTaggedHtmlToBladeBody(string $taggedHtml, array $mappings): string
    {
        // Pre-load all NamedFields referenced by mappings
        $namedFieldIds = collect($mappings)->pluck('namedFieldId')->filter()->unique()->values()->all();
        $namedFields = NamedField::whereIn('id', $namedFieldIds)->get()->keyBy('id');

        // Replace doc-tag spans with Blade output
        $processed = preg_replace_callback(
            '/<span[^>]*class="[^"]*doc-tag[^"]*"[^>]*>.*?<\/span>/s',
            function ($matches) use ($mappings, $namedFields) {
                $span = $matches[0];

                // Signature tags → @include component (preserve ALL parties from mappings)
                if (str_contains($span, 'doc-tag-sig')) {
                    $parties = $this->extractPartiesFromTagSpan($span, $mappings);
                    if (!empty($parties)) {
                        // Emit one signature-line per assigned party so all sign at this position
                        $includes = [];
                        foreach ($parties as $p) {
                            $includes[] = '@include("docuperfect.web-templates.components.signature-line", [\'party\' => \'' . addslashes($p) . '\'])';
                        }
                        return implode("\n", $includes);
                    }
                    return '@include("docuperfect.web-templates.components.signature-line")';
                }
                // Initial tags → @include component (preserve ALL parties from mappings)
                if (str_contains($span, 'doc-tag-ini')) {
                    $parties = $this->extractPartiesFromTagSpan($span, $mappings);
                    if (!empty($parties)) {
                        $includes = [];
                        foreach ($parties as $p) {
                            $includes[] = '@include("docuperfect.web-templates.components.initials-line", [\'party\' => \'' . addslashes($p) . '\'])';
                        }
                        return implode("\n", $includes);
                    }
                    return '@include("docuperfect.web-templates.components.initials-line")';
                }

                // Extract tag ID to look up the mapping
                if (!preg_match('/data-tag-id="([^"]*)"/', $span, $idMatch)) {
                    return '<span class="corex-field-value" style="border-bottom:1px solid #333; min-width:80pt; display:inline-block;">&nbsp;</span>';
                }
                $tagId = $idMatch[1];

                $mapping = $mappings[$tagId] ?? null;
                if (!$mapping) {
                    return '<span class="corex-field-value" style="border-bottom:1px solid #333; min-width:80pt; display:inline-block;">&nbsp;</span>';
                }

                // Derive blade variable name (same logic as WebTemplateDataService)
                $varName = $this->deriveBladeVarName($mapping, $namedFields);
                if (empty($varName)) {
                    return '<span class="corex-field-value" style="border-bottom:1px solid #333; min-width:80pt; display:inline-block;">&nbsp;</span>';
                }

                return '<span class="corex-field-value" data-field="' . e($varName) . '">'
                    . '{{ $' . $varName . ' ?? \'\' }}</span>';
            },
            $taggedHtml
        );

        return $processed;
    }

    /**
     * Extract party role from a doc-tag span using its data-tag-id and the mappings array.
     * The CDS builder stores party assignments in mappings[tagId].parties = ['Seller'].
     * Returns lowercase party name (e.g. 'seller') or null if not assigned.
     */
    /**
     * Extract ALL assigned parties from a tag span's mapping.
     * Returns an array of resolved party role strings (e.g. ['seller', 'agent']).
     */
    private function extractPartiesFromTagSpan(string $span, array $mappings): array
    {
        if (!preg_match('/data-tag-id="([^"]*)"/', $span, $idMatch)) {
            return [];
        }
        $tagId = $idMatch[1];
        $mapping = $mappings[$tagId] ?? null;
        if (!$mapping || empty($mapping['parties']) || !is_array($mapping['parties'])) {
            return [];
        }

        $resolveMap = [
            'owner_party' => 'seller',
            'acquiring_party' => 'buyer',
            'lessor' => 'landlord',
            'lessee' => 'tenant',
        ];

        $resolved = [];
        foreach ($mapping['parties'] as $party) {
            if (!$party) continue;
            $p = strtolower($party);
            $resolved[] = $resolveMap[$p] ?? $p;
        }

        return $resolved;
    }

    /**
     * Extract a single party from a tag span (backward compat — uses first party).
     */
    private function extractPartyFromTagSpan(string $span, array $mappings): ?string
    {
        $parties = $this->extractPartiesFromTagSpan($span, $mappings);
        return $parties[0] ?? null;
    }

    /**
     * Extract resolved party arrays from field_mappings for signature-type tags.
     * Returns indexed array: [0 => ['seller', 'agent'], 1 => ['agent', 'seller'], ...].
     * Used to enrich CDS JSON inline_signature sections with correct multi-party assignments.
     */
    private function extractSigPartiesFromMappings(array $fieldMappings): array
    {
        $resolveMap = [
            'owner_party' => 'seller',
            'acquiring_party' => 'buyer',
            'lessor' => 'landlord',
            'lessee' => 'tenant',
        ];

        $result = [];
        foreach ($fieldMappings as $mapping) {
            $parties = $mapping['parties'] ?? [];
            if (empty($parties) || !is_array($parties)) {
                continue;
            }
            // Check if this mapping is for a signature-type tag
            // (has parties array — only sig/initial tags have party assignments)
            $resolved = [];
            foreach ($parties as $p) {
                if (!$p) continue;
                $lower = strtolower($p);
                $resolved[] = $resolveMap[$lower] ?? $lower;
            }
            if (!empty($resolved)) {
                $result[] = $resolved;
            }
        }

        return $result;
    }

    /**
     * Derive the Blade variable name for a field mapping entry.
     * Mirrors the derivation in WebTemplateDataService::resolve() so that
     * the blade template variables match the data the wizard provides.
     */
    private function deriveBladeVarName(array $mapping, $namedFields): ?string
    {
        $namedFieldId = $mapping['namedFieldId'] ?? null;
        $namedField = $namedFieldId ? ($namedFields[$namedFieldId] ?? null) : null;

        if ($namedField) {
            $sourceType = $namedField->source_type ?? '';
            $sourceColumn = $namedField->source_column ?? '';
            $contactType = $namedField->source_contact_type ?? $mapping['sourceContactType'] ?? '';
            $varName = $this->deriveBladeName($sourceType, $sourceColumn, $contactType);
            if ($varName) {
                return $varName;
            }
        }

        // Fallback: derive from label
        $label = $mapping['label'] ?? $mapping['manualLabel'] ?? '';
        if (!empty($label)) {
            return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
        }

        return null;
    }

    /**
     * Map source_type + source_column + contact_type to a blade variable name.
     * Exact copy of WebTemplateDataService::deriveBladeName() to ensure
     * the blade file uses the same variable names the wizard resolves.
     */
    private function deriveBladeName(string $sourceType, string $sourceColumn, ?string $contactType): ?string
    {
        if (empty($sourceColumn)) {
            return null;
        }

        if ($sourceType === 'contact' && $contactType) {
            $role = strtolower(preg_replace('/\s+\d+$/', '', trim($contactType)));
            $prefixMap = ['landlord' => 'lessor', 'tenant' => 'lessee'];
            $prefix = $prefixMap[$role] ?? $role;

            $attrMap = [
                'first_name+last_name' => 'name', 'full_name' => 'name', 'name' => 'name',
                'last_name' => 'last_name', 'surname' => 'last_name',
                'first_name' => 'first_name',
                'id_number' => 'id_number',
                'address' => 'address',
                'phone' => in_array($prefix, ['seller', 'buyer']) ? 'phone' : 'cell',
                'cell' => in_array($prefix, ['seller', 'buyer']) ? 'phone' : 'cell',
                'email' => 'email',
                'bank_name' => 'bank_name', 'bank_account_name' => 'bank_account_name',
                'bank_account_number' => 'bank_account_number', 'bank_branch_name' => 'bank_branch_name',
            ];
            $suffix = $attrMap[$sourceColumn] ?? $sourceColumn;
            return $prefix . '_' . $suffix;
        }

        if ($sourceType === 'property') {
            $propMap = [
                'property_number' => 'property_erf_number', 'erf_number' => 'property_erf_number',
                'erf' => 'property_erf_number',
                'address' => 'property_street', 'street' => 'property_street',
                'suburb' => 'property_township', 'township' => 'property_township',
                'district' => 'property_district',
                'complex_name' => 'property_complex_name',
                'unit_number' => 'unit_no',
                'price' => 'price', 'rental_amount' => 'monthly_rental',
                'deposit_amount' => 'deposit_amount',
                'expiry_date' => 'mandate_expiry',
            ];
            return $propMap[$sourceColumn] ?? 'property_' . $sourceColumn;
        }

        if ($sourceType === 'deal') {
            return $sourceColumn;
        }

        if ($sourceType === 'computed') {
            return $sourceColumn;
        }

        if ($sourceType === 'agent') {
            return 'agent_' . $sourceColumn;
        }

        return null;
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
            $viewData['document_context'] = $template->isSalesDocument() ? 'sales' : 'rental';
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

    protected function loadAgencyParties($user): array
    {
        $agencyId = $user->effectiveAgencyId();

        $parties = AgencySigningParty::forAgency($agencyId)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'sort_order', 'is_default']);

        // effectiveAgencyId() is nullable; seedDefaultsForAgency() requires a
        // concrete int. With no resolvable agency we cannot seed agency-scoped
        // defaults — load the editor with whatever exists rather than 500.
        if ($parties->isEmpty() && $agencyId !== null) {
            AgencySigningParty::seedDefaultsForAgency($agencyId);
            $parties = AgencySigningParty::forAgency($agencyId)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'sort_order', 'is_default']);
        }

        return $parties->toArray();
    }

    /**
     * Show the E-Sign Wizard Setup page for a template.
     */
    public function wizardConfig(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $template = Template::with(['branches', 'documentType'])->findOrFail($id);

        return view('docuperfect.templates.wizard-config', [
            'template' => $template,
        ]);
    }

    /**
     * Save the E-Sign Wizard configuration for a template.
     */
    public function saveWizardConfig(Request $request, $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $template = Template::findOrFail($id);

        $template->update([
            'wizard_config' => $request->input('wizard_config'),
            'signing_parties' => $request->input('signing_parties'),
            'sections' => $request->input('sections'),
            'allowed_delivery_modes' => $request->input('allowed_delivery_modes', 'esign,wet_ink,download'),
        ]);

        return response()->json(['status' => 'saved', 'saved_at' => now()->toIso8601String()]);
    }
}
