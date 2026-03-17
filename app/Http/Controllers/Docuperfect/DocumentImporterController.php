<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\AgencySigningParty;
use App\Models\Docuperfect\FieldCorrection;
use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\ImportDraft;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\DocumentTemplateGenerator;
use Illuminate\Http\Request;

class DocumentImporterController extends Controller
{
    /**
     * Show the upload form.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        // Load unfinished drafts for the current user (max 5, newest first)
        $drafts = ImportDraft::where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get(['id', 'filename', 'fields_json', 'updated_at']);

        // Compute tag/linked counts for each draft
        $drafts->each(function ($draft) {
            $data = json_decode($draft->fields_json, true) ?? [];
            $tags = $data['tags'] ?? [];
            $mappings = $data['mappings'] ?? [];

            $draft->tag_count = count($tags);
            $draft->linked_count = 0;

            foreach ($tags as $tag) {
                $m = $mappings[$tag['id']] ?? [];
                $type = $tag['type'] ?? '';
                $complete = false;

                if ($type === 'input') {
                    $mt = $m['mappingType'] ?? '';
                    if ($mt === 'manual' && !empty($m['manualLabel'])) $complete = true;
                    elseif ($mt === 'named_field' && !empty($m['namedFieldId'])) $complete = true;
                    elseif ($mt === 'field_group' && !empty($m['fieldGroupId'])) $complete = true;
                } elseif ($type === 'signature' || $type === 'initial') {
                    if (!empty($m['party'])) $complete = true;
                }

                if ($complete) $draft->linked_count++;
            }

            $draft->template_name = pathinfo($draft->filename, PATHINFO_FILENAME);
        });

        return view('docuperfect.importer.index', [
            'drafts' => $drafts,
        ]);
    }

    /**
     * Accept uploaded .docx file and parse synchronously.
     * Returns JSON with redirect URL on success.
     */
    public function parse(Request $request): \Illuminate\Http\JsonResponse
    {
        \Log::info('[DocumentImporter] parse() called', [
            'has_file_document' => $request->hasFile('document'),
            'has_file_docx_file' => $request->hasFile('docx_file'),
            'all_files' => array_keys($request->allFiles()),
            'content_type' => $request->header('Content-Type'),
        ]);

        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            \Log::warning('[DocumentImporter] 403 — no manage_templates permission');
            abort(403);
        }

        // Validate — check both field names for safety
        $file = $request->file('document') ?? $request->file('docx_file');
        if (!$file) {
            \Log::error('[DocumentImporter] No file found in request');
            return response()->json([
                'error' => 'No file uploaded. Expected field name: document'
            ], 422);
        }

        if (!$file->isValid()) {
            \Log::error('[DocumentImporter] File upload invalid', [
                'error' => $file->getErrorMessage(),
            ]);
            return response()->json([
                'error' => 'Upload failed: ' . $file->getErrorMessage()
            ], 422);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        \Log::info('[DocumentImporter] File received', [
            'original_name' => $file->getClientOriginalName(),
            'extension' => $ext,
            'size' => $file->getSize(),
        ]);

        if ($ext !== 'docx') {
            return response()->json([
                'error' => 'Only .docx files are supported.'
            ], 422);
        }

        // Store file
        \Log::info('[DocumentImporter] Saving temp file...');
        $filename = uniqid('import_') . '.docx';
        $dir = storage_path('app/public/imports/temp');

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fullPath = $dir . DIRECTORY_SEPARATOR . $filename;
        $file->move($dir, $filename);

        if (!file_exists($fullPath)) {
            \Log::error('[DocumentImporter] File save failed', ['path' => $fullPath]);
            return response()->json([
                'error' => 'Failed to save uploaded file.'
            ], 500);
        }

        \Log::info('[DocumentImporter] File saved', [
            'path' => $fullPath,
            'size' => filesize($fullPath),
        ]);

        // Parse synchronously
        try {
            \Log::info('[DocumentImporter] Calling DocxParserService...');
            $parser = new \App\Services\Docuperfect\DocxParserService();
            $result = $parser->parse($fullPath);

            \Log::info('[DocumentImporter] Parse returned', [
                'html_length' => strlen($result['html'] ?? ''),
                'field_count' => count($result['fields'] ?? []),
                'warnings' => $result['warnings'] ?? [],
            ]);

            // Auto-cleanup: hard-delete drafts older than 4 hours for this user
            ImportDraft::where('user_id', $user->id)
                ->where('created_at', '<', now()->subHours(4))
                ->forceDelete();

            // Store in database instead of session — survives session expiry
            $claudeOriginals = collect($result['fields'])->map(fn($f, $i) => [
                'index' => $i,
                'context' => $f['context'] ?? '',
                'suggested_key' => $f['suggested_key'] ?? '',
                'suggested_label' => $f['suggested_label'] ?? '',
            ])->toArray();

            $draft = ImportDraft::create([
                'user_id'     => $user->id,
                'filename'    => $file->getClientOriginalName(),
                'html'        => $result['html'],
                'fields_json' => json_encode([
                    'fields'           => $result['fields'],
                    'claude_originals' => $claudeOriginals,
                ]),
            ]);

            // Store only the draft ID in session (tiny — won't expire easily)
            session(['import_draft_id' => $draft->id]);

            // Clean up temp file
            @unlink($fullPath);

            $redirect = route('docuperfect.import.review');
            \Log::info('[DocumentImporter] SUCCESS — draft saved to DB', [
                'draft_id' => $draft->id,
                'redirect' => $redirect,
            ]);

            return response()->json([
                'success'  => true,
                'redirect' => $redirect,
                'warnings' => $result['warnings'] ?? [],
            ]);

        } catch (\Throwable $e) {
            @unlink($fullPath);

            \Log::error('[DocumentImporter] EXCEPTION in parse', [
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * Render the review view from database draft.
     */
    public function review(Request $request)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        // Accept draft_id from query string (resume) or fall back to session/latest
        $draftId = $request->query('draft_id');
        if ($draftId) {
            $draft = ImportDraft::where('id', $draftId)
                ->where('user_id', $user->id)
                ->first();
            if ($draft) {
                session(['import_draft_id' => $draft->id]);
            }
        } else {
            $draft = $this->loadDraft($user->id);
        }

        if (!$draft) {
            return redirect()
                ->route('docuperfect.import.index')
                ->with('error',
                    'No parsed document found. ' .
                    'Please upload again.');
        }

        $fieldsData = json_decode($draft->fields_json, true) ?? [];
        $fields = $fieldsData['fields'] ?? [];
        $filename = $draft->filename;

        // Extract saved tagging state (if user previously saved draft)
        $savedTags      = $fieldsData['tags'] ?? [];
        $savedMappings  = $fieldsData['mappings'] ?? (object)[];
        $savedTaggedHtml = $fieldsData['tagged_html'] ?? '';
        $hasSavedState  = !empty($savedTags) && !empty($savedTaggedHtml);

        // Load real named fields from DB, grouped by source_type + contact_type
        $namedFields = NamedField::whereNull('deleted_at')
            ->orderBy('source_type')
            ->orderBy('source_contact_type')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'source_type', 'source_column', 'source_contact_type', 'field_type']);

        // Group into categories matching the type dropdown
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

        // Load field groups with resolved field names
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

        return view('docuperfect.importer.review', [
            'parsed' => [
                'html'              => $draft->html,
                'fields'            => $fields,
                'template_name'     => pathinfo($filename, PATHINFO_FILENAME),
                'original_filename' => $filename,
            ],
            'templateName' => pathinfo($filename, PATHINFO_FILENAME),
            'fields' => $fields,
            'draftId' => $draft->id,
            'groupedFields' => $groupedFields,
            'fieldGroups' => $fieldGroups,
            'namedFieldsAll' => $namedFields->map(fn($nf) => [
                'id' => $nf->id,
                'name' => $nf->name,
                'source_type' => $nf->source_type,
                'source_contact_type' => $nf->source_contact_type,
            ])->values()->all(),
            'signingParties' => $agencyParties,
            'hasSavedState'  => $hasSavedState,
            'savedTags'      => $savedTags,
            'savedMappings'  => $savedMappings,
            'savedTaggedHtml' => $savedTaggedHtml,
            'sourceTemplateId' => $fieldsData['source_template_id'] ?? null,
        ]);
    }

    /**
     * Save tags and field mappings from the tagging/linking editor (AJAX).
     */
    public function saveMappings(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $validated = $request->validate([
            'draft_id' => ['required', 'integer'],
            'tags' => ['required', 'array'],
            'mappings' => ['nullable', 'array'],
            'tagged_html' => ['nullable', 'string'],
        ]);

        $draft = ImportDraft::where('id', $validated['draft_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$draft) {
            return response()->json(['error' => 'Draft not found'], 404);
        }

        $fieldsData = json_decode($draft->fields_json, true) ?? [];
        $fieldsData['tags'] = $validated['tags'];
        $fieldsData['mappings'] = $validated['mappings'] ?? [];
        if (!empty($validated['tagged_html'])) {
            $fieldsData['tagged_html'] = $validated['tagged_html'];
        }

        $draft->update([
            'fields_json' => json_encode($fieldsData),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Generate a template from tagged & linked draft.
     * All data comes from the draft's fields_json (tags, mappings, tagged_html).
     */
    public function generate(Request $request, DocumentTemplateGenerator $generator)
    {
        \Log::info('DocumentImporter: generate() called');

        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $validated = $request->validate([
            'template_name' => ['required', 'string', 'max:255'],
            'draft_id' => ['nullable', 'integer'],
        ]);

        // Load draft from database
        $draftId = $validated['draft_id'] ?? session('import_draft_id');
        $draft = $draftId
            ? ImportDraft::where('id', $draftId)->where('user_id', $user->id)->first()
            : null;

        if (!$draft) {
            \Log::warning('DocumentImporter: no draft found', ['draft_id' => $draftId]);
            return redirect()->route('docuperfect.import.index')
                ->with('error', 'No import data found. Please upload a document first.');
        }

        // Hard-delete all older drafts for this user (prevents accumulation)
        ImportDraft::where('user_id', $user->id)
            ->where('id', '<', $draft->id)
            ->forceDelete();

        $templateName = $validated['template_name'];

        // Debug: log draft state so we can diagnose failures
        $fieldsData = json_decode($draft->fields_json, true) ?? [];
        \Log::info('DocumentImporter: generate() — draft state', [
            'draft_id'           => $draft->id,
            'template_name'      => $templateName,
            'has_tagged_html'    => !empty($fieldsData['tagged_html']),
            'tagged_html_length' => strlen($fieldsData['tagged_html'] ?? ''),
            'tag_count'          => count($fieldsData['tags'] ?? []),
            'mapping_count'      => count($fieldsData['mappings'] ?? []),
        ]);

        try {
            $template = $generator->generate($draft, $templateName, $user->id);

            // Log AI corrections BEFORE deleting the draft (needs draft data)
            $this->logFieldCorrections($draft, $user->id);

            // Only delete draft AFTER confirmed success
            $draft->delete();
            session()->forget('import_draft_id');

            $isUpdate = !empty($fieldsData['source_template_id']);
            $successMsg = $isUpdate
                ? 'Template "' . $template->name . '" updated successfully.'
                : 'Template "' . $template->name . '" created successfully. Review and activate below.';

            return redirect()
                ->route('docuperfect.templates.edit', ['id' => $template->id])
                ->with('success', $successMsg);

        } catch (\Exception $e) {
            // Draft is preserved — user can retry
            \Log::error('DocumentImporter: generate failed', [
                'draft_id' => $draft->id,
                'user_id'  => $user->id,
                'error'    => $e->getMessage(),
                'file'     => $e->getFile() . ':' . $e->getLine(),
            ]);

            return redirect()
                ->route('docuperfect.import.review', ['draft_id' => $draft->id])
                ->with('error', 'Generation failed: ' . $e->getMessage() . ' — Your draft has been preserved. Please try again.');
        }
    }

    /**
     * Create a new import draft from an existing web template for re-editing.
     */
    public function editFromTemplate(Request $request, int $id)
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $template = Template::findOrFail($id);

        if (!$template->blade_view) {
            return redirect()->route('docuperfect.templates.edit', ['id' => $id])
                ->with('error', 'This template has no blade view to edit.');
        }

        // RESTORE PATH — use stored editor state if available (lossless round-trip)
        $editorState = $template->editor_state;
        if ($editorState
            && !empty($editorState['tags'])
            && !empty($editorState['tagged_html'])) {

            $draft = ImportDraft::create([
                'user_id'     => $user->id,
                'filename'    => $template->name,
                'html'        => $editorState['tagged_html'],
                'fields_json' => json_encode([
                    'tags'               => $editorState['tags'],
                    'mappings'           => (object) ($editorState['mappings'] ?? []),
                    'tagged_html'        => $editorState['tagged_html'],
                    'fields'             => [],
                    'source_template_id' => $template->id,
                ]),
            ]);

            session(['import_draft_id' => $draft->id]);

            return redirect()->route('docuperfect.import.review', [
                'draft_id' => $draft->id,
            ])->with('info', 'Editing template: ' . $template->name . ' — Generate will update the existing template.');
        }

        // FALLBACK PATH — no editor_state (old templates before this fix)
        // Reverse-engineer the blade HTML to reconstruct mappings

        // Read the blade file and extract the document body (between header and signature includes)
        $bladePath = resource_path(
            'views/' . str_replace('.', '/', $template->blade_view) . '.blade.php'
        );

        if (!file_exists($bladePath)) {
            return redirect()->route('docuperfect.templates.edit', ['id' => $id])
                ->with('error', 'Blade file not found on disk.');
        }

        $bladeContent = file_get_contents($bladePath);

        // Extract the body HTML between the company-header include and the end marker
        $bodyHtml = '';
        $headerMarker = "@include('docuperfect.web-templates.components.company-header')";
        $sigMarker = "@include('docuperfect.web-templates.components.signature-block'";
        $bodyEndMarker = '</div>' . "\n" . '</body>';

        $headerPos = strpos($bladeContent, $headerMarker);

        if ($headerPos !== false) {
            $start = $headerPos + strlen($headerMarker);
            $sigPos = strpos($bladeContent, $sigMarker);

            if ($sigPos !== false) {
                // Template has signature-block component — extract between markers
                $bodyHtml = trim(substr($bladeContent, $start, $sigPos - $start));
            } else {
                // Template has inline sig blocks — extract to </div></body>
                $endPos = strpos($bladeContent, $bodyEndMarker, $start);
                if ($endPos !== false) {
                    $bodyHtml = trim(substr($bladeContent, $start, $endPos - $start));
                } else {
                    // Last resort — extract everything after header include, strip closing tags
                    $bodyHtml = trim(substr($bladeContent, $start));
                    $bodyHtml = preg_replace('/<\/div>\s*<\/body>\s*<\/html>\s*$/i', '', $bodyHtml);
                }
            }
        }

        if (empty($bodyHtml)) {
            return redirect()->route('docuperfect.templates.edit', ['id' => $id])
                ->with('error', 'Could not extract document content from template.');
        }

        // Convert field spans back to tag spans for re-editing
        $tagNumber = 0;
        $tags = [];
        $mappings = [];

        // Pre-load all named fields keyed by "source_type.source_column" to avoid N+1
        $allNamedFields = NamedField::whereNull('deleted_at')
            ->get(['id', 'name', 'source_type', 'source_column', 'source_contact_type'])
            ->keyBy(fn($nf) => $nf->source_type . '.' . $nf->source_column);

        // Step 1: Convert field-group spans FIRST (before individual fields).
        // Generator outputs: <span class="field-group" data-group-id="3" data-layout="horizontal">
        //   <span class="field" ...>...</span> <span class="field" ...>...</span>
        // </span>
        // Must replace the entire group as ONE tag before individual field regex picks up inner spans.
        // Pattern: match outer <span class="field-group"...> then inner <span>...</span> children
        // then the outer closing </span>. Inner spans contain {{ ... }} blade vars.
        $bodyHtml = preg_replace_callback(
            '/<span\s+class="field-group"[^>]*data-group-id="(\d+)"[^>]*>(?:\s*<span\s+class="field"[^>]*>\{\{[^}]*\}\}<\/span>\s*)+<\/span>/s',
            function ($m) use (&$tagNumber, &$tags, &$mappings) {
                $tagNumber++;
                $tagId = 'tag-edit-' . $tagNumber;
                $groupId = (int) $m[1];

                $group = FieldGroup::find($groupId);
                $label = $group?->name ?? ('Field Group ' . $tagNumber);

                $tags[] = [
                    'id' => $tagId,
                    'type' => 'input',
                    'number' => $tagNumber,
                    'label' => $label,
                ];

                $mappings[$tagId] = [
                    'mappingType' => 'field_group',
                    'typeKey' => 'fg:' . $groupId,
                    'fieldGroupId' => $groupId,
                    'label' => $label,
                    'party' => 'auto',
                ];

                return '<span class="tag-span" data-tag-id="' . $tagId . '">[INPUT ' . $tagNumber . ']</span>';
            },
            $bodyHtml
        );

        // Step 2: Convert individual .field spans to INPUT tags (groups already replaced above)
        $bodyHtml = preg_replace_callback(
            '/<span\s+class="field[^"]*"\s+data-field="([^"]*)"([^>]*)>\{\{[^}]*\}\}<\/span>/',
            function ($m) use (&$tagNumber, &$tags, &$mappings, $allNamedFields) {
                $tagNumber++;
                $tagId = 'tag-edit-' . $tagNumber;
                $dataField = $m[1];

                // Try to extract contact-type from attributes
                $contactType = '';
                if (preg_match('/data-contact-type="([^"]*)"/', $m[2], $ct)) {
                    $contactType = $ct[1];
                }

                // Parse "source_type.source_column" from data-field
                $parts = explode('.', $dataField, 2);
                $sourceType = $parts[0] ?? null;
                $sourceColumn = $parts[1] ?? null;

                if ($sourceType === 'manual') {
                    // Manual field — use data-label if present, else humanize slug
                    $manualSlug = $sourceColumn ?? 'custom';
                    $manualLabel = '';
                    if (preg_match('/data-label="([^"]*)"/', $m[2], $ml)) {
                        $manualLabel = $ml[1];
                    }
                    if (!$manualLabel) {
                        $manualLabel = ucwords(str_replace('_', ' ', $manualSlug));
                    }
                    $tags[] = [
                        'id' => $tagId,
                        'type' => 'input',
                        'number' => $tagNumber,
                        'label' => $manualLabel,
                    ];
                    $mappings[$tagId] = [
                        'mappingType' => 'manual',
                        'typeKey' => 'sf:manual',
                        'namedFieldId' => null,
                        'manualLabel' => $manualLabel,
                        'dataField' => $dataField,
                        'label' => $manualLabel,
                        'party' => 'auto',
                    ];
                } else {
                    // Resolve NamedField from pre-loaded collection
                    $namedField = $allNamedFields->get($dataField);

                    // Fallback label: use data-label attr, then source_column humanized, then generic
                    $label = $namedField?->name;
                    if (!$label && preg_match('/data-label="([^"]*)"/', $m[2], $dl)) {
                        $label = $dl[1];
                    }
                    if (!$label && $sourceColumn) {
                        $label = ucwords(str_replace('_', ' ', $sourceColumn));
                    }
                    if (!$label) {
                        $label = 'Field ' . $tagNumber;
                    }

                    $tags[] = [
                        'id' => $tagId,
                        'type' => 'input',
                        'number' => $tagNumber,
                        'label' => $label,
                    ];
                    // Build typeKey for Alpine dropdown
                    $resolvedContactType = $contactType ?: ($namedField?->source_contact_type ?? '');
                    if ($namedField) {
                        if ($namedField->source_type === 'contact' && $resolvedContactType) {
                            $typeKey = 'sf:contact_' . strtolower($resolvedContactType);
                        } else {
                            $typeKey = 'sf:' . ($namedField->source_type ?: 'manual');
                        }
                    } else {
                        $typeKey = 'sf:manual';
                    }

                    $mappings[$tagId] = [
                        'mappingType' => $namedField ? 'named_field' : 'manual',
                        'typeKey' => $typeKey,
                        'namedFieldId' => $namedField?->id,
                        'dataField' => $dataField,
                        'sourceContactType' => $resolvedContactType,
                        'label' => $label,
                        'party' => 'auto',
                        'sourceType' => $sourceType,
                        'sourceColumn' => $sourceColumn,
                    ];
                }

                return '<span class="tag-span" data-tag-id="' . $tagId . '">[INPUT ' . $tagNumber . ']</span>';
            },
            $bodyHtml
        );

        // Convert sig-block divs to SIG tags
        $bodyHtml = preg_replace_callback(
            '/<div\s+class="sig-block"[^>]*>.*?<\/div>\s*<\/div>\s*<\/div>/s',
            function ($m) use (&$tagNumber, &$tags, &$mappings) {
                $tagNumber++;
                $tagId = 'tag-edit-' . $tagNumber;

                // Extract variant and parties from data attributes
                preg_match('/data-variant="([^"]*)"/', $m[0], $variantMatch);
                $variant = $variantMatch[1] ?? 'sig_full';

                preg_match('/data-parties="([^"]*)"/', $m[0], $partiesMatch);
                $partiesJson = html_entity_decode($partiesMatch[1] ?? '[]');
                $parties = json_decode($partiesJson, true) ?? [];

                $tags[] = [
                    'id' => $tagId,
                    'type' => 'signature',
                    'number' => $tagNumber,
                    'label' => 'SIG ' . $tagNumber,
                ];

                $mappings[$tagId] = [
                    'parties' => $parties,
                    'variant' => $variant,
                ];

                return '<span class="tag-span" data-tag-id="' . $tagId . '">[SIG ' . $tagNumber . ']</span>';
            },
            $bodyHtml
        );

        // Convert ini-placeholder spans to INI tags
        $bodyHtml = preg_replace_callback(
            '/<span\s+class="ini-placeholder"[^>]*>.*?<\/span>/',
            function ($m) use (&$tagNumber, &$tags, &$mappings) {
                $tagNumber++;
                $tagId = 'tag-edit-' . $tagNumber;

                // Extract parties from data attribute
                preg_match('/data-parties="([^"]*)"/', $m[0], $partiesMatch);
                $partiesJson = html_entity_decode($partiesMatch[1] ?? '[]');
                $parties = json_decode($partiesJson, true) ?? [];

                $tags[] = [
                    'id' => $tagId,
                    'type' => 'initial',
                    'number' => $tagNumber,
                    'label' => 'INI ' . $tagNumber,
                ];

                $mappings[$tagId] = [
                    'parties' => $parties,
                ];

                return '<span class="tag-span" data-tag-id="' . $tagId . '">[INI ' . $tagNumber . ']</span>';
            },
            $bodyHtml
        );

        // Create new import draft from blade-parsed data
        $draft = ImportDraft::create([
            'user_id' => $user->id,
            'filename' => $template->name,
            'html' => $bodyHtml,
            'fields_json' => json_encode([
                'tags' => $tags,
                'mappings' => (object) $mappings,
                'tagged_html' => $bodyHtml,
                'fields' => [],
                'source_template_id' => $template->id,
            ]),
        ]);

        session(['import_draft_id' => $draft->id]);

        return redirect()->route('docuperfect.import.review')
            ->with('info', 'Editing template: ' . $template->name . ' — Generate will update the existing template.');
    }

    /**
     * Save the current draft state without generating (AJAX).
     */
    public function saveDraft(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $validated = $request->validate([
            'draft_id' => ['required', 'integer'],
            'tags' => ['required', 'array'],
            'mappings' => ['nullable', 'array'],
            'tagged_html' => ['nullable', 'string'],
            'template_name' => ['nullable', 'string', 'max:255'],
        ]);

        $draft = ImportDraft::where('id', $validated['draft_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$draft) {
            return response()->json(['error' => 'Draft not found'], 404);
        }

        $fieldsData = json_decode($draft->fields_json, true) ?? [];
        $fieldsData['tags'] = $validated['tags'];
        $fieldsData['mappings'] = $validated['mappings'] ?? [];
        if (!empty($validated['tagged_html'])) {
            $fieldsData['tagged_html'] = $validated['tagged_html'];
        }

        $updateData = ['fields_json' => json_encode($fieldsData)];

        if (!empty($validated['template_name'])) {
            // Update filename to reflect template name changes
            $ext = pathinfo($draft->filename, PATHINFO_EXTENSION) ?: 'docx';
            $updateData['filename'] = $validated['template_name'] . '.' . $ext;
        }

        $draft->update($updateData);

        return response()->json([
            'success' => true,
            'saved_at' => $draft->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Soft-delete a draft (AJAX).
     */
    public function destroyDraft(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $draft = ImportDraft::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$draft) {
            return response()->json(['error' => 'Draft not found'], 404);
        }

        $draft->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Load the current user's import draft from the database.
     */
    protected function loadDraft(int $userId): ?ImportDraft
    {
        $draftId = session('import_draft_id');

        if ($draftId) {
            $draft = ImportDraft::where('id', $draftId)
                ->where('user_id', $userId)
                ->first();
            if ($draft) return $draft;
        }

        // Fallback: find the user's most recent draft
        return ImportDraft::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first();
    }

    // ===== SIGNING PARTY MANAGEMENT =====

    /**
     * Return JSON list of signing parties for the current user's agency.
     * Seeds defaults if agency has none.
     */
    public function getParties(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        return response()->json($this->loadAgencyParties($user));
    }

    /**
     * Create a new signing party for the agency.
     */
    public function storeParty(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $agencyId = $user->effectiveAgencyId();
        $maxSort = AgencySigningParty::forAgency($agencyId)->max('sort_order') ?? -1;

        $party = AgencySigningParty::create([
            'agency_id'  => $agencyId,
            'name'       => $validated['name'],
            'sort_order' => $maxSort + 1,
            'is_default' => false,
        ]);

        return response()->json($party, 201);
    }

    /**
     * Update name of a signing party. Verify agency ownership.
     */
    public function updateParty(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $party = AgencySigningParty::where('id', $id)
            ->forAgency($user->effectiveAgencyId())
            ->first();

        if (!$party) {
            return response()->json(['error' => 'Party not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $party->update(['name' => $validated['name']]);

        return response()->json($party);
    }

    /**
     * Soft-delete a signing party. Prevent if only 1 remains.
     */
    public function destroyParty(Request $request, int $id): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $agencyId = $user->effectiveAgencyId();
        $count = AgencySigningParty::forAgency($agencyId)->count();

        if ($count <= 1) {
            return response()->json(['error' => 'Cannot delete the last signing party.'], 422);
        }

        $party = AgencySigningParty::where('id', $id)
            ->forAgency($agencyId)
            ->first();

        if (!$party) {
            return response()->json(['error' => 'Party not found'], 404);
        }

        $party->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Bulk update sort_order for reordering.
     */
    public function reorderParties(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user->hasPermission('manage_templates')) {
            abort(403);
        }

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer'],
        ]);

        $agencyId = $user->effectiveAgencyId();

        foreach ($validated['order'] as $item) {
            AgencySigningParty::where('id', $item['id'])
                ->forAgency($agencyId)
                ->update(['sort_order' => $item['sort_order']]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Load agency signing parties, seeding defaults if empty.
     */
    protected function loadAgencyParties($user): array
    {
        $agencyId = $user->effectiveAgencyId();

        $parties = AgencySigningParty::forAgency($agencyId)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'sort_order', 'is_default']);

        if ($parties->isEmpty()) {
            AgencySigningParty::seedDefaultsForAgency($agencyId);
            $parties = AgencySigningParty::forAgency($agencyId)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'sort_order', 'is_default']);
        }

        return $parties->toArray();
    }

    /**
     * Compare user's final field assignments against Claude's original suggestions.
     * Store corrections so future imports learn from them.
     */
    /**
     * Log AI field corrections from the draft's fields_json.
     * Compares claude_originals (AI suggestions) with mappings (user's final choices).
     */
    protected function logFieldCorrections(ImportDraft $draft, int $userId): void
    {
        $fieldsData = json_decode($draft->fields_json, true) ?? [];
        $claudeOriginals = $fieldsData['claude_originals'] ?? [];
        $mappings = $fieldsData['mappings'] ?? [];
        $documentType = $draft->filename ?? 'unknown';

        if (empty($claudeOriginals) || empty($mappings)) {
            return;
        }

        // Build a lookup of claude originals by their index
        $originalsByIndex = [];
        foreach ($claudeOriginals as $orig) {
            $idx = $orig['index'] ?? null;
            if ($idx !== null) {
                $originalsByIndex[$idx] = $orig;
            }
        }

        $correctionCount = 0;

        foreach ($mappings as $tagId => $userMapping) {
            $claudeKey = $userMapping['claude_suggested_key'] ?? '';
            $claudeLabel = $userMapping['claude_suggested_label'] ?? '';
            $userKey = $userMapping['key'] ?? $userMapping['named_field'] ?? '';
            $userLabel = $userMapping['label'] ?? '';
            $context = $userMapping['context'] ?? '';

            // If mapping doesn't have claude data inline, try matching by tag number
            if (empty($claudeKey)) {
                $tagNumber = $userMapping['tag_number'] ?? null;
                if ($tagNumber !== null && isset($originalsByIndex[$tagNumber])) {
                    $orig = $originalsByIndex[$tagNumber];
                    $claudeKey = $orig['suggested_key'] ?? '';
                    $claudeLabel = $orig['suggested_label'] ?? '';
                    if (empty($context)) {
                        $context = $orig['context'] ?? '';
                    }
                }
            }

            // Skip if no AI suggestion or user didn't change it
            if (empty($claudeKey) || $claudeKey === $userKey) continue;
            if (empty($userKey)) continue;

            FieldCorrection::create([
                'context' => mb_substr($context, 0, 500),
                'claude_suggested_key' => $claudeKey,
                'claude_suggested_label' => $claudeLabel,
                'user_corrected_key' => $userKey,
                'user_corrected_label' => $userLabel,
                'correction_reason' => $userMapping['correction_reason'] ?? null,
                'document_type' => $documentType,
                'user_id' => $userId,
            ]);
            $correctionCount++;
        }

        if ($correctionCount > 0) {
            \Log::info('DocumentImporter: logged field corrections', [
                'draft_id' => $draft->id,
                'correction_count' => $correctionCount,
            ]);
        }
    }
}
