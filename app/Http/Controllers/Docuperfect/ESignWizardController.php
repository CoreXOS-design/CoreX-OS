<?php

namespace App\Http\Controllers\Docuperfect;

use App\Exceptions\Docuperfect\WebPackSlotException;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Pack;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Property;
use App\Models\Rental\RentalProperty;
use App\Services\CandidatePractitionerService;
use App\Services\Docuperfect\SignatureService;
use App\Services\Docuperfect\SignatureSurfaceNormalizer;
use App\Services\Docuperfect\WebPackSlotResolver;

use App\Models\FicaSubmission;
use App\Services\WebTemplateDataService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ESignWizardController extends Controller
{
    /**
     * Test render: minimal page to verify template page images + field overlays.
     */
    public function testRender(Request $request, $templateId)
    {
        $template = Template::findOrFail($templateId);

        $pageImages = [];
        for ($n = 0; $n < $template->page_count; $n++) {
            $pageImages[] = route('docuperfect.page.image', ['id' => $template->id, 'page' => $n]);
        }

        return view('docuperfect.esign.test-render', [
            'template'   => $template,
            'pageImages' => $pageImages,
            'fields'     => $template->fields_json ?? [],
        ]);
    }

    /**
     * Show the wizard — fresh create (step 1: pick template).
     */
    public function create(Request $request)
    {
        $user = $request->user();

        $templates = Template::active()
            ->visibleTo($user)
            ->where('is_esign', true)
            ->where(function ($q) {
                // PDF templates need page images; web/CDS templates need a blade view
                $q->where(function ($q2) {
                    $q2->where('render_type', 'pdf')->where('page_count', '>', 0);
                })->orWhere(function ($q2) {
                    $q2->where('render_type', 'web')->whereNotNull('blade_view');
                });
            })
            ->with(['documentType', 'branches'])
            ->orderBy('name')
            ->get();

        $webPacks = \App\Models\Docuperfect\WebPack::where('agency_id', $user->effectiveAgencyId())
            ->whereNull('deleted_at')
            ->with(['items.template'])
            ->orderBy('name')
            ->get();

        $pdfPacks = Pack::visibleTo($user)
            ->with(['templates'])
            ->get()
            ->map(function ($pack) {
                $pack->esign_eligible = $pack->templates->isNotEmpty() && $pack->templates->every(
                    fn($t) => $t->is_esign && $t->render_type === 'pdf'
                );
                return $pack;
            });

        $documentTypes = DocumentType::orderBy('sort_order')->get();

        $drafts = Flow::where('user_id', $user->id)
            ->whereIn('status', ['active', 'draft'])
            ->with('template')
            ->orderBy('updated_at', 'desc')
            ->get();

        $contactTypes = DB::table('contact_types')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('docuperfect.esign.wizard', [
            'templates'     => $templates,
            'webPacks'      => $webPacks,
            'pdfPacks'      => $pdfPacks,
            'documentTypes' => $documentTypes,
            'drafts'        => $drafts,
            'flow'          => null,
            'template'      => null,
            'fields'        => [],
            'pageImages'    => [],
            'recipients'    => [],
            'stepData'      => [],
            'currentStep'   => 1,
            'isWebTemplate' => false,
            'templateId'    => null,
            'flowId'        => null,
            'contactTypes'  => $contactTypes,
        ]);
    }

    /**
     * Create a new flow from step 1 and redirect to step 2.
     */
    public function store(Request $request, WebPackSlotResolver $slotResolver)
    {
        $packId = $request->input('pack_id');
        $isPackFlow = $request->boolean('is_pack_flow');

        $pdfPackId = $request->input('pdf_pack_id');

        // HARD BLOCK: Single template — check if sale agreement / OTP
        $templateId = $request->input('template_id');
        if ($templateId && !$isPackFlow && !$pdfPackId) {
            $selectedTemplate = Template::find($templateId);
            if ($selectedTemplate && $selectedTemplate->isEsignBlocked()) {
                return response()->json([
                    'error' => 'Sale agreements must be signed with wet ink per the Alienation of Land Act. E-signing is not permitted.',
                    'esign_blocked' => true,
                ], 422);
            }
        }

        if ($isPackFlow && $packId) {
            // Web Pack flow — merge multiple templates.
            // The pack's SLOTS decide what goes out, and the server decides the slots: the agent's
            // picks are an input to WebPackSlotResolver, never the answer. It enforces pack
            // membership, sends every required document whether or not the client asked for it,
            // takes exactly one member of each selectable group, and re-runs the e-sign legal gate
            // on the RESOLVED set — which this path never did, leaving a sale agreement inside a
            // web pack e-signable (and therefore void). See WebPackSlotResolver.
            $pack = \App\Models\Docuperfect\WebPack::findOrFail($packId);

            $resolvedIds = $request->input('resolved_template_ids');

            try {
                $templates = $slotResolver->resolve($pack, is_array($resolvedIds) ? $resolvedIds : null);
            } catch (WebPackSlotException $e) {
                return response()->json(array_filter([
                    'error'         => $e->getMessage(),
                    'esign_blocked' => $e->esignBlocked ?: null,
                ]), 422);
            }

            $primaryTemplate = $templates->first();

            // Merge fields from all templates with a template index prefix
            $mergedFields = [];
            foreach ($templates as $idx => $tpl) {
                foreach (($tpl->fields_json ?? []) as $field) {
                    $field['_pack_template_id'] = $tpl->id;
                    $field['_pack_template_index'] = $idx;
                    $mergedFields[] = $field;
                }
            }

            $flow = Flow::create([
                'type'         => 'esign',
                'template_id'  => $primaryTemplate->id,
                'user_id'      => $request->user()->id,
                'current_step' => 2,
                'step_data'    => [
                    'template' => [
                        'template_id' => (int) $primaryTemplate->id,
                    ],
                    'fields'       => $mergedFields,
                    'pack_id'      => (int) $packId,
                    'pack_name'    => $pack->name,
                    'template_ids' => $templates->pluck('id')->values()->toArray(),
                    'is_pack_flow' => true,
                ],
                'status' => 'active',
            ]);
        } elseif ($pdfPackId) {
            // PDF Pack flow — concatenate PDF template pages
            $pack = Pack::with(['templates', 'slots.template'])->findOrFail($pdfPackId);

            // Get templates: from slots (required) or legacy relationship
            if ($pack->usesSlots()) {
                $packTemplates = $pack->slots
                    ->where('slot_type', 'required')
                    ->map->template
                    ->filter()
                    ->values();
            } else {
                $packTemplates = $pack->templates;
            }

            // Filter to e-sign eligible PDF templates only
            $packTemplates = $packTemplates->filter(
                fn($t) => $t->is_esign && $t->render_type === 'pdf' && $t->page_count > 0
            )->values();

            if ($packTemplates->isEmpty()) {
                return response()->json(['error' => 'No e-sign eligible PDF templates in this pack.'], 422);
            }

            $primaryTemplate = $packTemplates->first();

            // Merge fields from all templates with page offsets
            $mergedFields = [];
            $pageOffset = 0;
            $templatePageMap = [];

            foreach ($packTemplates as $idx => $tpl) {
                $templatePageMap[$tpl->id] = [
                    'start_page'    => $pageOffset,
                    'end_page'      => $pageOffset + $tpl->page_count - 1,
                    'template_name' => $tpl->name,
                    'template_id'   => $tpl->id,
                ];

                foreach (($tpl->fields_json ?? []) as $field) {
                    // Offset the page number so fields land on the correct concatenated page
                    if (isset($field['page'])) {
                        $field['page'] = (int) $field['page'] + $pageOffset;
                    }
                    $field['_pack_template_id'] = $tpl->id;
                    $field['_pack_template_index'] = $idx;
                    $mergedFields[] = $field;
                }

                $pageOffset += $tpl->page_count;
            }

            $flow = Flow::create([
                'type'         => 'esign',
                'template_id'  => $primaryTemplate->id,
                'user_id'      => $request->user()->id,
                'current_step' => 2,
                'step_data'    => [
                    'template' => [
                        'template_id' => (int) $primaryTemplate->id,
                    ],
                    'fields'            => $mergedFields,
                    'is_pdf_pack'       => true,
                    'pdf_pack_id'       => (int) $pdfPackId,
                    'pdf_pack_name'     => $pack->name,
                    'template_ids'      => $packTemplates->pluck('id')->values()->toArray(),
                    'template_page_map' => $templatePageMap,
                    'total_pages'       => $pageOffset,
                ],
                'status' => 'active',
            ]);
        } else {
            // Single template flow (existing behaviour)
            $request->validate([
                'template_id' => 'required|exists:docuperfect_templates,id',
            ]);

            $template = Template::findOrFail($request->template_id);

            // Copy template fields into flow step_data
            // For web templates with field_mappings, build proper fields instead of copying
            // potentially skeletal fields_json (which may lack id/field_name/named_field_id)
            $fieldsJson = $template->fields_json ?? [];
            $renderType = $template->render_type ?? 'pdf';
            if (($renderType === 'web') && !empty($template->field_mappings) && (empty($fieldsJson) || $this->fieldsAreSkeletal($fieldsJson))) {
                $fieldsJson = $this->buildFieldsFromMappings($template->field_mappings);
            }

            $flow = Flow::create([
                'type'         => 'esign',
                'template_id'  => $request->template_id,
                'user_id'      => $request->user()->id,
                'current_step' => 2,
                'step_data'    => [
                    'template' => [
                        'template_id' => (int) $request->template_id,
                    ],
                    'fields' => $fieldsJson,
                ],
                'status' => 'active',
            ]);
        }

        $url = route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => 2]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'redirect' => $url]);
        }

        return redirect($url);
    }

    /**
     * Load a specific step of an existing flow.
     */
    public function showStep(Request $request, $flowId, $step)
    {
        $flow = Flow::where('user_id', $request->user()->id)
            ->findOrFail($flowId);

        $flow->load('template.documentType');

        $step = (int) $step;

        // Safety net: step 6 is the final wizard step — redirect invalid steps
        if ($step > 6) {
            return redirect()->route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => 6]);
        }

        $template = $flow->template;
        $stepData = $flow->step_data ?? [];

        // Build page image URLs (same as DocumentController edit view)
        $pageImages = [];
        if (!empty($stepData['is_pdf_pack']) && !empty($stepData['template_ids'])) {
            // PDF pack flow: concatenate page images from all templates in order
            foreach ($stepData['template_ids'] as $tplId) {
                $tpl = Template::find($tplId);
                if ($tpl && $tpl->page_count > 0) {
                    for ($n = 0; $n < $tpl->page_count; $n++) {
                        $pageImages[] = route('docuperfect.page.image', ['id' => $tplId, 'page' => $n]);
                    }
                }
            }
        } elseif ($template && $template->page_count > 0) {
            for ($n = 0; $n < $template->page_count; $n++) {
                $pageImages[] = route('docuperfect.page.image', ['id' => $template->id, 'page' => $n]);
            }
        }

        // Fields: use flow's stored copy (copied from template on creation),
        // with any values filled during wizard steps merged in
        $fields = $stepData['fields'] ?? ($template->fields_json ?? []);

        // For CDS/web templates, ALWAYS rebuild fields from field_mappings to preserve
        // document order and ensure field_names match blade data-field attributes.
        $renderType = $template->render_type ?? 'pdf';
        $isCds = ($template->template_type ?? '') === 'cds';
        if ($renderType === 'web' && !empty($template->field_mappings) && ($isCds || empty($fields) || $this->fieldsAreSkeletal($fields))) {
            $fields = $this->buildFieldsFromMappings($template->field_mappings);
            // Store into step_data so subsequent loads have the fields
            $stepData['fields'] = $fields;
            $flow->step_data = $stepData;
            $flow->save();
        }

        // Normalise web template fields so wizard JS sees consistent keys
        if ($renderType === 'web') {
            $fields = array_map(fn($f) => $this->normalizeFieldForWizard($f, $renderType), $fields);
        }

        // Backfill named_field_name from database for any field missing it
        $namedFieldIds = collect($fields)->pluck('named_field_id')->filter()->unique()->values();
        $namedFieldRecords = [];
        if ($namedFieldIds->isNotEmpty()) {
            $namedFieldRecords = NamedField::whereIn('id', $namedFieldIds)->get()->keyBy('id');
            $namedFieldMap = $namedFieldRecords->pluck('name', 'id');
            foreach ($fields as &$field) {
                $defaultLabels = ['Placeholder', 'placeholder', 'Text Field', 'Date', 'Signature', 'Initial', 'Selection', 'Tick'];

                // Find the best label from all possible keys
                $agentLabel = $field['label'] ?? '';
                $fieldName = $field['field_name'] ?? '';
                $fieldLabel = $field['field_label'] ?? '';

                // Priority 1: agent-set label from template editor (only if meaningful, not a default)
                if (!empty($agentLabel) && !in_array($agentLabel, $defaultLabels)) {
                    $field['named_field_name'] = $agentLabel;
                }
                // Priority 2: field_name key (used by signature date fields)
                elseif (!empty($fieldName) && !in_array($fieldName, $defaultLabels)) {
                    $field['named_field_name'] = $fieldName;
                }
                // Priority 3: field_label key
                elseif (!empty($fieldLabel) && !in_array($fieldLabel, $defaultLabels)) {
                    $field['named_field_name'] = $fieldLabel;
                }
                // Priority 4: DB named field name
                elseif (empty($field['named_field_name']) && !empty($field['named_field_id'])) {
                    $field['named_field_name'] = $namedFieldMap[$field['named_field_id']] ?? null;
                }
            }
            unset($field);
        }

        // Final fallback: ensure NO field ever shows a raw tag ID as its label
        foreach ($fields as &$field) {
            $currentName = $field['named_field_name'] ?? '';
            // If empty or looks like a tag ID, replace with something human-readable
            if (empty($currentName) || str_starts_with($currentName, 'tag-')) {
                $fallback = $field['label'] ?? '';
                if (empty($fallback) || str_starts_with($fallback, 'tag-')) {
                    $fallback = $field['field_label'] ?? '';
                }
                if (empty($fallback) || str_starts_with($fallback, 'tag-')) {
                    $fn = $field['field_name'] ?? '';
                    $fallback = (!empty($fn) && !str_starts_with($fn, 'tag-'))
                        ? ucwords(str_replace('_', ' ', $fn))
                        : ucfirst($field['type'] ?? 'Field');
                }
                $field['named_field_name'] = $fallback;
            }
        }
        unset($field);

        // Enrich details defaults from property record BEFORE autoFillFields
        // so manual fields (commission, deposit, rental, lease dates, price) can resolve
        if ($step >= 4 && empty($stepData['details'])) {
            $propertyId = $stepData['property']['property_id'] ?? null;
            $propertySource = $stepData['property']['_property_source'] ?? null;
            $propDefaults = [];
            if ($propertyId) {
                if ($propertySource === 'properties') {
                    $propRecord = Property::find($propertyId);
                } else {
                    $propRecord = RentalProperty::find($propertyId);
                }
                if ($propRecord) {
                    // Sales: price field
                    $price = $propRecord->price ?? null;
                    $propDefaults['price'] = ($price && (float) $price > 0) ? $price : '';

                    // Rental: rental_amount / monthly_rental
                    $rental = !empty($propRecord->rental_amount) ? $propRecord->rental_amount
                            : (!empty($propRecord->monthly_rental) ? $propRecord->monthly_rental : '');
                    $propDefaults['monthly_rental'] = ($rental && (float) $rental > 0) ? $rental : '';
                    $deposit = !empty($propRecord->deposit_amount) ? $propRecord->deposit_amount : $rental;
                    $propDefaults['deposit'] = ($deposit && (float) $deposit > 0) ? $deposit : '';
                    $propDefaults['commission'] = !empty($propRecord->commission_percent) ? $propRecord->commission_percent : '';
                    $propDefaults['marketing_fee'] = $propRecord->marketing_fee ?? '';
                }
            }
            // Fallback: use values saved in step 2 property data (from search results)
            $propStep = $stepData['property'] ?? [];
            if (empty($propDefaults['price']) && !empty($propStep['price']) && (float) $propStep['price'] > 0) {
                $propDefaults['price'] = $propStep['price'];
            }
            if (empty($propDefaults['monthly_rental']) && !empty($propStep['rental_amount']) && (float) $propStep['rental_amount'] > 0) {
                $propDefaults['monthly_rental'] = $propStep['rental_amount'];
            }
            if (empty($propDefaults['deposit']) && !empty($propStep['deposit_amount']) && (float) $propStep['deposit_amount'] > 0) {
                $propDefaults['deposit'] = $propStep['deposit_amount'];
            } elseif (empty($propDefaults['deposit']) && !empty($propDefaults['monthly_rental'])) {
                $propDefaults['deposit'] = $propDefaults['monthly_rental'];
            }
            if (empty($propDefaults['commission']) && !empty($propStep['commission_percent'])) {
                $propDefaults['commission'] = $propStep['commission_percent'];
            }
            if (empty($propDefaults['marketing_fee']) && !empty($propStep['marketing_fee'])) {
                $propDefaults['marketing_fee'] = $propStep['marketing_fee'];
            }
            // Commission default based on template context (sales=7.5, rental=10)
            if (empty($propDefaults['commission'])) {
                $templateName = strtolower($template->name ?? '');
                $isSales = str_contains($templateName, 'sell') || str_contains($templateName, 'sale')
                    || str_contains($templateName, 'authority') || str_contains($templateName, 'otp')
                    || str_contains($templateName, 'purchase') || str_contains($templateName, 'mandate to sell');
                $propDefaults['commission'] = $isSales ? '7.5' : '10';
            }
            $stepData['details'] = $propDefaults;
        }

        // Recipients: auto-populate from the property — the ONE shared
        // pipeline every body/preview render must go through (see
        // prepareRecipientsForMerge() docblock — fault 3, round 2:
        // templatePages() was computing the body from raw, un-prepared
        // step_data, so a live-refreshed preview could show different text
        // than the page's own initial load — the "two systems will drift"
        // trap Johan named, just one level up from the party-name
        // resolution itself). $stepData stays RAW/un-expanded from here on —
        // it feeds the recipients step's own editable form ('recipients'
        // view var below) and gets returned to the client; $mergeStepData is
        // the ONLY thing that ever sees an entity substituted for its
        // representative (fault 3, round 3 — see expandRecipientsForMerge()
        // docblock for why that substitution must never reach the form).
        $stepData = $this->prepareRecipientsForMerge($stepData, $template, $request->user(), $step);
        $mergeStepData = $this->expandRecipientsForMerge($stepData, $request->user());

        // Auto-fill fields from wizard step data (property, recipients, details)
        // Contact fields with multiple contacts of the same role (e.g., 2 lessors)
        // are concatenated with ' & ' (e.g., "Koos Kombuis & Lienkie Kombuis")
        $fields = $this->autoFillFields($fields, $mergeStepData);

        // Pre-fill field values from WebTemplateDataService (resolved from step_data)
        $resolvedValues = [];
        if ($template && ($template->render_type ?? 'pdf') === 'web') {
            $resolvedValues = app(WebTemplateDataService::class)
                ->resolve($template->id, $mergeStepData, $request->user());
        }

        // Build unified ordered field list for step 5 (document order, no party grouping)
        // Also separate into creator/signer for backward compat
        $creatorFields = [];
        $signerFields = [];
        $allWizardFields = [];
        foreach ($fields as $idx => $field) {
            $role = $field['assignedTo'] ?? $field['assigned_to'] ?? 'creator';
            $fieldWithIndex = $field;
            $fieldWithIndex['_index'] = $idx;

            // System fields are auto-filled — skip them from the wizard form
            if ($role === 'system') {
                continue;
            }

            // Skip non-editable field types from wizard panel
            $mappingType = $field['mapping_type'] ?? '';
            $tagType = $field['tag_type'] ?? '';
            $fieldName = $field['field_name'] ?? '';

            if ($tagType === 'signature') continue;
            // field_group_member entries from older builds — skip _Full duplicates
            if ($mappingType === 'field_group_member' && str_ends_with($fieldName, '_Full')) continue;

            // Pre-fill value from WebTemplateDataService if field_name maps to a resolved key
            // field_name from buildFieldsJson is camelCase (via columnToBladeVar),
            // but resolvedValues keys are snake_case — try both forms
            if (empty($fieldWithIndex['value'])) {
                $fieldName = $field['field_name'] ?? null;
                if ($fieldName) {
                    $snakeFieldName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $fieldName));
                    $resolved = $resolvedValues[$fieldName] ?? $resolvedValues[$snakeFieldName] ?? null;
                    if ($resolved !== null && $resolved !== '') {
                        $fieldWithIndex['value'] = (string) $resolved;
                    }
                }
            }

            $allWizardFields[] = $fieldWithIndex;

            if (in_array($role, ['creator', 'user', 'agent'])) {
                $creatorFields[] = $fieldWithIndex;
            } else {
                $signerFields[] = $fieldWithIndex;
            }
        }

        // Templates list (for step navigation back to step 1)
        $templates = Template::active()
            ->visibleTo($request->user())
            ->where(function ($q) {
                $q->where('page_count', '>', 0)
                  ->orWhere('render_type', 'web');
            })
            ->with(['documentType', 'branches'])
            ->orderBy('name')
            ->get();

        $documentTypes = DocumentType::orderBy('sort_order')->get();

        // Get manual named fields for this template (shown as dynamic inputs on step 4)
        $manualFields = [];
        $fieldNamedIds = collect($fields)->pluck('named_field_id')->filter()->unique()->values();
        if ($fieldNamedIds->isNotEmpty()) {
            $manualFields = DB::table('docuperfect_named_fields')
                ->whereIn('id', $fieldNamedIds)
                ->where('source_type', 'manual')
                ->get()
                ->map(fn($mf) => ['id' => $mf->id, 'name' => $mf->name])
                ->values()
                ->toArray();
        }

        // Sort allWizardFields in document flow order for the left panel
        // For web templates: parse the Blade file to get the order of data-field attributes
        if (($template->render_type ?? 'pdf') === 'web' && $template->blade_view) {
            $bladeViewPath = resource_path('views/' . str_replace('.', '/', $template->blade_view) . '.blade.php');
            if (file_exists($bladeViewPath)) {
                $html = file_get_contents($bladeViewPath);
                preg_match_all('/data-field="([^"]+)"/', $html, $matches);
                $fieldOrder = array_flip($matches[1]); // field_name => position
                usort($allWizardFields, function ($a, $b) use ($fieldOrder) {
                    $posA = $fieldOrder[$a['field_name'] ?? ''] ?? PHP_INT_MAX;
                    $posB = $fieldOrder[$b['field_name'] ?? ''] ?? PHP_INT_MAX;
                    return $posA - $posB;
                });
            }
        }

        // Auto-fill field group display values from recipients
        $allWizardFields = $this->autoFillFieldGroupDisplays($allWizardFields, $mergeStepData);

        // E-sign walk-fix FIX 1 + FIX 2 — expand role-bound fields per
        // recipient so a 3-seller session renders N inputs (each
        // pre-filled from THAT specific recipient's contact), not one
        // concatenated " and "-joined value. Mirrors B2.5/B3's recipient
        // loop engine on the recipient signing surface — same loop,
        // same identity convention, same chip labels. Uses $mergeStepData
        // (expanded) — an entity's representative is who actually fills
        // these fields in, not the entity itself.
        $expandedWizardFields = $this->expandWizardFieldsPerRecipient($allWizardFields, $mergeStepData);

        $contactTypes = DB::table('contact_types')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        // Fault 3, round 5 (Johan, 2026-08-24) — step 6's "Signing Order" list
        // (wizard.blade.php) iterates this SAME `recipients` array and is
        // where an agent sets each signer's email/order/skip before sending.
        // It must show who will actually SIGN — an entity's representative(s),
        // never the entity itself, which cannot sign and routinely has no
        // email (Johan's exact "Deferred, details not yet known, undeliverable"
        // finding). Steps 1-5 keep the RAW form (the company as one editable
        // row — see prepareRecipientsForMerge()'s docblock for why); only at
        // step 6, once recipient editing is done, does the view get the
        // signing-expanded array — the SAME expansion prepareSigning() now
        // uses to actually create the SignatureRequest rows, so what the
        // agent confirms here is exactly what gets created.
        $recipientsForView = $step === 6
            ? $this->expandEntityRecipients($stepData['recipients']['recipients'] ?? [], $request->user(), signersOnly: true)
            // Flow 480 (Johan, 2026-08-29) — the raw recipient rows shown on
            // steps 1-5 need _is_entity/_representation recomputed on every
            // load, not just carried from a fresh client-side pick, or the
            // "Signs via its representative(s)" preview vanishes on reopen.
            : $this->attachEntityRepresentationPreview($stepData['recipients']['recipients'] ?? [], $request->user());

        return view('docuperfect.esign.wizard', [
            'flow'           => $flow,
            'step'           => $step,
            'template'       => $template,
            'fields'         => $fields,
            'creatorFields'  => $creatorFields,
            'signerFields'   => $signerFields,
            'allWizardFields' => $allWizardFields,
            'expandedWizardFields' => $expandedWizardFields,
            'pageImages'     => $pageImages,
            'recipients'     => $recipientsForView,
            'stepData'       => $stepData,
            'templates'      => $templates,
            'documentTypes'  => $documentTypes,
            'drafts'         => collect(),
            'currentStep'    => $step,
            'isWebTemplate'  => ($template->render_type ?? 'pdf') === 'web',
            'templateId'     => $flow->template_id,
            'flowId'         => $flow->id,
            'manualFields'   => $manualFields,
            'contactTypes'   => $contactTypes,
        ]);
    }

    /**
     * Save step data and advance.
     */
    public function saveStep(Request $request, $flowId, $step)
    {
        $flow = Flow::where('user_id', $request->user()->id)
            ->findOrFail($flowId);

        $step = (int) $step;
        $stepKey = $this->stepKey($step);

        // Get JSON data
        $data = $request->input('data', []);
        if (empty($data) && $request->isJson()) {
            $data = $request->json('data', []);
        }

        // Merge step data into flow
        $stepData = $flow->step_data ?? [];

        // WET-INK FLOW-THROUGH (Johan 2026-08-06) — the Fill & Review body strike/reword amendments live under
        // step_data['fill_review']['body_strikes'], authored SERVER-SIDE by the bodyStrike endpoint. The step-5
        // save payload (getStepData case 5) carries fieldValues / clauses / other_conditions — but NOT
        // body_strikes. A wholesale $stepData['fill_review'] = $data therefore WIPED them the instant the agent
        // advanced Fill & Review -> Sign & Send (CLAUDE.md §6.1: the step posts a SUBSET), so the strike showed
        // transiently at authoring then vanished from Sign & Send, the signing view and the signed document.
        // Preserve the server-authored strikes across the wholesale save (the client never removes a strike
        // through this path; the only remover is a future dedicated endpoint, which would carry its own key).
        $preservedBodyStrikes = ($stepKey === 'fill_review' && ! isset($data['body_strikes']))
            ? ($stepData['fill_review']['body_strikes'] ?? null)
            : null;

        $stepData[$stepKey] = $data;

        if ($preservedBodyStrikes !== null) {
            $stepData['fill_review']['body_strikes'] = $preservedBodyStrikes;
        }

        // Sort recipients by SA signing convention when saving step 3
        if ($stepKey === 'recipients' && !empty($data['recipients'])) {
            $sorted = $this->sortRecipientsBySigningOrder($data['recipients']);

            // Auto-create contact records for manually entered recipients
            $propertyId = $stepData['property']['property_id'] ?? null;
            $propertySource = $stepData['property']['_property_source'] ?? 'properties';

            foreach ($sorted as &$r) {
                // Johan, 2026-08-26 — property 6060, Piet Begrafnis wrongly
                // linked as "Owner". This block (2026-03-31, ea1858bdf) exists
                // to auto-create a Contact + link them to the property when an
                // agent TYPES IN a brand-new person's name/email/ID directly on
                // the recipients screen — it reads an empty _contact_id as "no
                // record exists yet for this person". A supplier-sourced
                // recipient (bindSlotToSupplier()) deliberately ALSO carries
                // _contact_id: null — their identity lives in
                // agency_service_provider_contacts, a different book — which
                // this block cannot tell apart from "brand new person", so it
                // matched Piet to his real Contact via duplicate-detection and
                // linked THAT contact to the property as an owner. A supplier
                // standing in as a representative (or any deceased-party
                // substitute signer — _deceased_substitute_for) is never a
                // title-holder; this block was never meant to reach them.
                if (($r['_recipient_source'] ?? null) === 'supplier' || !empty($r['_deceased_substitute_for'])) {
                    continue;
                }
                // Skip agents and recipients that already have a contact linked
                if (($r['role'] ?? '') === 'agent' || ($r['readonly'] ?? false)) {
                    continue;
                }
                if (!empty($r['_contact_id'])) {
                    // AT-292 — a pre-linked Contact still gets the typed ID
                    // backfilled when its own id_number is blank (fill-if-blank),
                    // so a couple's second seller renders their ID and FICA/deals
                    // resolve it downstream.
                    $this->backfillContactIdNumber((int) $r['_contact_id'], (string) ($r['id_number'] ?? ''));
                    continue;
                }

                // Must have at least a name to create a contact
                $name = trim($r['name'] ?? '');
                if ($name === '') {
                    continue;
                }

                $email = trim($r['email'] ?? '');
                $idNumber = trim($r['id_number'] ?? '');

                // Check for existing contact by email or id_number (prevent duplicates).
                // AT-125 — resolve the email against ALL of a contact's emails
                // (child tables), not just the mirror, so a signer using a
                // secondary address still links to the existing contact.
                $existing = null;
                if ($email !== '') {
                    $existing = app(\App\Services\Communications\ContactIdentifierResolver::class)
                        ->resolve($email, (int) auth()->user()?->effectiveAgencyId());
                }
                if (!$existing && $idNumber !== '') {
                    $existing = Contact::where('id_number', $idNumber)->first();
                }

                if ($existing) {
                    $r['_contact_id'] = $existing->id;
                    // AT-292 — matched an existing Contact by email/ID; persist the
                    // typed id_number onto it when blank (fill-if-blank).
                    $this->backfillContactIdNumber((int) $existing->id, $idNumber);
                } else {
                    // Split name: first space separates first_name from last_name
                    $nameParts = explode(' ', $name, 2);
                    $firstName = $nameParts[0];
                    $lastName = $nameParts[1] ?? '';

                    // Derive contact_type_id from recipient role via esign_role mapping
                    $roleToEsignRole = [
                        'tenant' => 'lessee', 'lessee' => 'lessee',
                        'buyer' => 'buyer', 'purchaser' => 'buyer',
                        'landlord' => 'lessor', 'lessor' => 'lessor',
                        'seller' => 'seller', 'owner' => 'seller',
                        'witness' => null,
                    ];
                    $esignRole = $roleToEsignRole[strtolower($r['role'] ?? '')] ?? null;
                    $contactTypeId = null;
                    if ($esignRole) {
                        $contactTypeId = \App\Models\ContactType::where('esign_role', $esignRole)->value('id');
                    }
                    if (!$contactTypeId) {
                        // Try matching by name (for witness, spouse, etc.)
                        $contactTypeId = \App\Models\ContactType::where('name', 'like', '%' . ($r['role'] ?? '') . '%')->value('id');
                    }

                    // Duplicate detection: auto-link if match found (non-blocking in e-sign flow)
                    $dupSvc = app(\App\Services\ContactDuplicateService::class);
                    $dupAgencyId = (int) ($request->user()?->effectiveAgencyId() ?: 0);   // AT-253 Rule 17
                    $dupData = ['phone' => '', 'email' => $email ?: '', 'id_number' => $idNumber ?: ''];
                    $dupExisting = $dupSvc->findDuplicates($dupData, $dupAgencyId)->first();

                    if ($dupExisting) {
                        $contact = $dupExisting;
                        $match = $dupSvc->identifyMatch($dupData, $dupExisting, $dupAgencyId);
                        $dupSvc->logAttempt($dupAgencyId, $request->user()?->id ?? 0, 'auto_link', $match['field'], $match['value'], $dupExisting->id, $dupData, 'auto_linked');
                        // AT-292 — auto-linked to a duplicate Contact; backfill the
                        // typed id_number when blank (fill-if-blank).
                        $this->backfillContactIdNumber((int) $dupExisting->id, $idNumber);
                    } else {
                        $contact = Contact::create([
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'email' => $email ?: null,
                            'id_number' => $idNumber ?: null,
                            'contact_type_id' => $contactTypeId,
                            'created_by_user_id' => $request->user()?->id,
                        ]);
                    }

                    $r['_contact_id'] = $contact->id;

                    // Link contact to property if one is selected
                    if ($propertyId && $propertySource === 'properties') {
                        $pivotRoleMap = [
                            'tenant' => 'tenant', 'lessee' => 'tenant',
                            'buyer' => 'buyer',
                            'landlord' => 'lessor', 'lessor' => 'lessor',
                            'seller' => 'owner', 'owner' => 'owner',
                        ];
                        $pivotRole = $pivotRoleMap[strtolower($r['role'] ?? '')] ?? null;
                        $contact->properties()->syncWithoutDetaching([
                            $propertyId => ['role' => $pivotRole],
                        ]);
                    }
                }
            }
            unset($r);

            $stepData['recipients']['recipients'] = $sorted;
        }

        // For step 5 (fill_review): merge field values and party overrides back into the main fields array
        if ($stepKey === 'fill_review') {
            $fields = $stepData['fields'] ?? [];

            if (!empty($data['fieldValues'])) {
                foreach ($data['fieldValues'] as $fieldId => $value) {
                    $matched = false;
                    foreach ($fields as &$field) {
                        if (($field['id'] ?? null) == $fieldId) {
                            $field['value'] = $value;
                            $matched = true;
                            break;
                        }
                    }
                    unset($field);

                    // AT-360b — per-recipient instance edits are keyed "{base}__r{n}"; the base field
                    // holds no single value for them. Record each on the base field's instance map so
                    // fields_json audit retains every recipient's typed value (additive; base 'value'
                    // untouched for single-recipient fields).
                    if (!$matched && preg_match('/^(.*)__r(\d+)$/', (string) $fieldId, $m)) {
                        foreach ($fields as &$field) {
                            if (($field['id'] ?? null) == $m[1]) {
                                $field['instance_values'][(int) $m[2]] = $value;
                                break;
                            }
                        }
                        unset($field);
                    }
                }
            }

            if (!empty($data['partyOverrides'])) {
                foreach ($data['partyOverrides'] as $fieldId => $party) {
                    foreach ($fields as &$field) {
                        if (($field['id'] ?? null) == $fieldId) {
                            // AT multi-party — an override is now the FULL party set
                            // (array). Preserve it as editable_by (multi, signing-time)
                            // and derive the single prep-filler for assignedTo.
                            $parties = is_array($party)
                                ? array_values(array_filter($party, fn ($r) => is_string($r) && $r !== ''))
                                : (is_string($party) && $party !== '' ? [$party] : []);
                            if (!empty($parties)) {
                                $field['editableBy']  = $parties;
                                $field['editable_by'] = $parties;
                                $field['assignedTo']  = in_array('agent', $parties, true) ? 'agent' : $parties[0];
                            }
                            break;
                        }
                    }
                    unset($field);
                }
            }

            $stepData['fields'] = $fields;
        }

        // Handle property/contact linking (pillar connections)
        if ($stepKey === 'property' && !empty($data['property_id'])) {
            // Only link to flows.property_id if source is 'properties' table (not rental_properties)
            $source = $data['_property_source'] ?? 'properties';
            if ($source === 'properties') {
                $flow->property_id = $data['property_id'];
            }
        }
        if ($stepKey === 'recipients') {
            // Link first non-agent recipient's contact_id (use processed recipients with auto-created IDs)
            $processedRecipients = $stepData['recipients']['recipients'] ?? $data['recipients'] ?? [];
            foreach ($processedRecipients as $r) {
                if (!empty($r['_contact_id']) && ($r['role'] ?? '') !== 'agent') {
                    $flow->contact_id = $r['_contact_id'];
                    break;
                }
            }
        }

        // Step 6 (signing_setup): hoist delivery_mode to top level for prepareSigning
        if ($stepKey === 'signing_setup' && isset($data['delivery_mode'])) {
            $stepData['delivery_mode'] = $data['delivery_mode'];
        }

        // Persist custom document name at top level of step_data
        $documentName = $request->input('document_name');
        if ($documentName) {
            $stepData['document_name'] = $documentName;
        }

        // Assign step_data AFTER all modifications (hoisting etc.) so nothing is lost
        $flow->step_data = $stepData;

        // Step 6 is the final wizard step — save data but do NOT advance past it
        if ($step >= 6) {
            $flow->current_step = max($flow->current_step, $step);
            $flow->save();

            return response()->json([
                'success'    => true,
                'final_step' => true,
            ]);
        }

        // Advance step (only forward, never backward)
        $nextStep = $step + 1;
        $flow->current_step = max($flow->current_step, $nextStep);
        $flow->save();

        return response()->json([
            'success'   => true,
            'next_step' => $nextStep,
            'redirect'  => route('docuperfect.esign.step', ['flow' => $flow->id, 'step' => $nextStep]),
        ]);
    }

    /**
     * AT-292 — durable data fix for the couple's-mandate seller ID-drop.
     * When a wizard recipient is linked to a pre-existing / matched / auto-
     * duplicate Contact whose id_number is empty, persist the ID the signer
     * typed in the wizard onto that Contact. FILL-IF-BLANK ONLY — a non-empty
     * Contact id_number is never overwritten. This closes the drop at the data
     * source so the render, FICA and downstream deal uses all resolve the ID.
     */
    private function backfillContactIdNumber(?int $contactId, string $typedIdNumber): void
    {
        $typedIdNumber = trim($typedIdNumber);
        if ($contactId === null || $contactId <= 0 || $typedIdNumber === '') {
            return;
        }
        $contact = Contact::find($contactId);
        if ($contact === null) {
            return;
        }
        if (trim((string) $contact->id_number) === '') {
            $contact->id_number = $typedIdNumber;
            $contact->save();
        }
    }

    /**
     * Save current step as draft without advancing.
     */
    public function saveDraft(Request $request, $flowId)
    {
        $flow = Flow::where('user_id', $request->user()->id)
            ->findOrFail($flowId);

        $step = (int) $request->input('step', $flow->current_step);
        $stepKey = $this->stepKey($step);

        $data = $request->input('data', []);
        if (empty($data) && $request->isJson()) {
            $data = $request->json('data', []);
        }

        $stepData = $flow->step_data ?? [];
        $stepData[$stepKey] = $data;

        // Merge field values and party overrides for fill_review
        if ($stepKey === 'fill_review') {
            $fields = $stepData['fields'] ?? [];

            if (!empty($data['fieldValues'])) {
                foreach ($data['fieldValues'] as $fieldId => $value) {
                    $matched = false;
                    foreach ($fields as &$field) {
                        if (($field['id'] ?? null) == $fieldId) {
                            $field['value'] = $value;
                            $matched = true;
                            break;
                        }
                    }
                    unset($field);

                    // AT-360b — per-recipient instance edits are keyed "{base}__r{n}"; the base field
                    // holds no single value for them. Record each on the base field's instance map so
                    // fields_json audit retains every recipient's typed value (additive; base 'value'
                    // untouched for single-recipient fields).
                    if (!$matched && preg_match('/^(.*)__r(\d+)$/', (string) $fieldId, $m)) {
                        foreach ($fields as &$field) {
                            if (($field['id'] ?? null) == $m[1]) {
                                $field['instance_values'][(int) $m[2]] = $value;
                                break;
                            }
                        }
                        unset($field);
                    }
                }
            }

            if (!empty($data['partyOverrides'])) {
                foreach ($data['partyOverrides'] as $fieldId => $party) {
                    foreach ($fields as &$field) {
                        if (($field['id'] ?? null) == $fieldId) {
                            // AT multi-party — an override is now the FULL party set
                            // (array). Preserve it as editable_by (multi, signing-time)
                            // and derive the single prep-filler for assignedTo.
                            $parties = is_array($party)
                                ? array_values(array_filter($party, fn ($r) => is_string($r) && $r !== ''))
                                : (is_string($party) && $party !== '' ? [$party] : []);
                            if (!empty($parties)) {
                                $field['editableBy']  = $parties;
                                $field['editable_by'] = $parties;
                                $field['assignedTo']  = in_array('agent', $parties, true) ? 'agent' : $parties[0];
                            }
                            break;
                        }
                    }
                    unset($field);
                }
            }

            $stepData['fields'] = $fields;
        }

        $flow->step_data = $stepData;
        $flow->status = 'draft';
        $flow->save();

        return response()->json(['success' => true, 'message' => 'Draft saved']);
    }

    /**
     * API: search properties for autocomplete.
     *
     * Searches both `properties` (main pillar) and `rental_properties` tables.
     * Returns unified results with source indicator.
     */
    public function searchProperties(Request $request)
    {
        $q = trim($request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $results = [];

        // 1. Search main properties table (canonical search, newest-first)
        $properties = Property::searchAddress($q)
            ->with('agent')
            ->latest()
            ->limit(10)
            ->get();

        foreach ($properties as $p) {
            // Get linked contacts (lessor/landlord) — scoped to this property
            // Primary: match by pivot role
            $lessor = $p->contacts()
                ->where(function ($q) {
                    $q->where('contact_property.role', 'lessor')
                      ->orWhere('contact_property.role', 'landlord')
                      ->orWhere('contact_property.role', 'owner');
                })
                ->first();

            // Fallback: match by contact_type esign_role (for NULL pivot roles).
            // AT-79: check the primary mirror AND the multi-parent pivot.
            if (!$lessor) {
                $lessor = $p->contacts()
                    ->where(function ($w) {
                        $w->whereHas('type', fn ($q) => $q->whereIn('esign_role', ['seller', 'lessor']))
                          ->orWhereHas('parentTypes', fn ($q) => $q->whereIn('esign_role', ['seller', 'lessor']));
                    })
                    ->first();
            }

            $results[] = [
                'id'                => $p->id,
                'source'            => 'properties',
                'address'           => $p->buildDisplayAddress(),
                'suburb'            => $p->suburb ?? '',
                // B1 — the property's area also lives in town/city (set via the city/town selector).
                // The panel only carried `suburb`, so a property whose area is in `town` rendered a
                // blank TOWNSHIP. Carry both so township can resolve from whichever the agent filled.
                'town'              => $p->town ?? '',
                'city'              => $p->city ?? '',
                // AT-177 — the sf:property address components the CDS split needs to resolve.
                'street_name'       => $p->street_name ?? '',
                'district'          => $p->district ?? '',
                'erf'               => $p->property_number ?? '',
                'erf_no'            => $p->property_number ?? '',
                'property_number'   => $p->property_number ?? '',
                'complex_name'      => $p->complex_name ?? '',
                'unit_number'       => $p->unit_number ?? '',
                'property_type'     => $p->property_type ?? '',
                'price'             => $p->price,
                'rental_amount'     => $p->rental_amount,
                'deposit_amount'    => $p->deposit_amount,
                'commission_percent'=> $p->commission_percent,
                'marketing_fee'     => $p->marketing_fee,
                'lease_start_date'  => $p->lease_start_date?->format('Y-m-d'),
                'lease_end_date'    => $p->lease_end_date?->format('Y-m-d'),
                'lessor_name'       => $lessor ? ($lessor->first_name . ' ' . $lessor->last_name) : null,
                'lessor_id'         => $lessor?->id,
                'beds'              => $p->beds,
                'baths'             => $p->baths,
                'display'           => $p->buildDisplayAddress(),
                'agent'             => $p->agent?->name,
                'status'            => $p->statusBadge(),
            ];
        }

        // 2. Search rental_properties table
        $rentalProps = RentalProperty::where(function ($query) use ($q) {
            $query->where('address_line_1', 'like', "%{$q}%")
                ->orWhere('full_address', 'like', "%{$q}%")
                ->orWhere('suburb', 'like', "%{$q}%");
        })
            ->active()
            ->limit(10)
            ->get();

        foreach ($rentalProps as $rp) {
            $rpAddr = $rp->full_address ?: $rp->address_line_1;
            if (!empty($rp->suburb) && $rpAddr && !str_contains($rpAddr, $rp->suburb)) {
                $rpAddr .= ', ' . $rp->suburb;
            }

            $results[] = [
                'id'                => $rp->id,
                'source'            => 'rental_properties',
                'address'           => $rpAddr,
                'suburb'            => $rp->suburb ?? '',
                'erf_no'            => '',
                'complex_name'      => '',
                'unit_number'       => '',
                'property_type'     => $rp->property_type ?? '',
                'rental_amount'     => $rp->monthly_rental,
                'deposit_amount'    => null,
                'commission_percent'=> null,
                'marketing_fee'     => null,
                'lease_start_date'  => null,
                'lease_end_date'    => null,
                'lessor_name'       => $rp->landlord_name,
                'lessor_id'         => null,
                'beds'              => null,
                'baths'             => null,
                'display'           => $rpAddr,
            ];
        }

        return response()->json(array_slice($results, 0, 10));
    }

    /**
     * API: the recipient templates available for the "Replace this party"
     * modal (Johan, 2026-08-24 — stage 2). Agency-scoped: the agent's own
     * agency's templates plus CoreX's NULL-agency defaults for the role, via
     * RecipientTemplate::availableFor() — no interface path to see another
     * agency's templates or write agency_id from the client.
     */
    public function listRecipientTemplates(Request $request)
    {
        $role = trim((string) $request->input('role', ''));
        if ($role === '') {
            return response()->json([]);
        }

        $agencyId = $request->user()->effectiveAgencyId();

        $templates = \App\Models\RecipientTemplate::availableFor($agencyId, $role)
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'text_template' => $t->text_template,
                'party_slots' => $t->party_slots,
            ])
            ->values();

        return response()->json($templates);
    }

    /**
     * Flow 480 (Johan, 2026-08-29) — the recipients step (3) shows an entity's
     * "Signs via its representative(s)" preview off `_is_entity`/`_representation`
     * on the recipient row, but those are set client-side only at the moment a
     * contact is picked (selectContact() in wizard.blade.php) and were never
     * persisted into step_data — so the preview showed correctly on a fresh
     * pick and vanished on reopening the saved flow. Rather than persist a
     * snapshot (which can drift — the same entity's proxy/primary flags
     * changed mid-session earlier tonight), this recomputes it fresh from the
     * SAME identity resolution (Contact::signingRepresentatives()) the rest of
     * the chain work uses, factored out of searchContacts() below so there is
     * exactly one place this is computed, not a second copy.
     */
    /**
     * Johan, 2026-08-26 — the ONE place that decides "what order applies
     * to this document's representatives" from a recipient row, so every
     * caller (live pick, saved-flow reopen, generation) resolves the same
     * precedence the same way: an agent's manual reorder always wins;
     * failing that, a picked proxy goes first ("almost with certainty" —
     * Johan) and the rest follow in whatever order the code returns today;
     * with neither, today's order stands, untouched. This only decides
     * WHICH id list to pass — the actual sort is
     * Contact::applyRepresentativeOrder(), the one implementation.
     */
    private function resolveEffectiveRepOrder(array $r, ?int $overrideProxyRepId): ?array
    {
        $manualOrder = ! empty($r['_entity_rep_order']) ? array_map('intval', $r['_entity_rep_order']) : null;

        return $manualOrder ?? ($overrideProxyRepId !== null ? [$overrideProxyRepId] : null);
    }

    private function buildEntityRepresentationPreview(Contact $c, ?\App\Models\Docuperfect\EsignRecipientPreset $entityPreset, ?int $overrideProxyRepId = null, ?array $orderContactIds = null): ?array
    {
        if (! $c->isEntity()) {
            return null;
        }
        // Johan, 2026-08-29 — a per-document proxy pick lives on the flow's
        // OWN recipient (never written to contact_representatives), so it is
        // ALWAYS supplied by the caller from that recipient's own data, never
        // read back from the company/contact here. $overrideProxyRepId null
        // means "no pick made on this document" — falls through to whatever
        // is permanently on file (usually nothing, for an ordinary company).
        $signers = $c->signingRepresentatives($overrideProxyRepId);
        $signers = Contact::applyRepresentativeOrder($signers, $orderContactIds);
        $allReps = Contact::applyRepresentativeOrder($c->representatives()->get(), $orderContactIds);

        return [
            'needs_representative' => $signers->isEmpty(),
            'signers' => $signers->map(function ($rep) use ($c, $entityPreset, $overrideProxyRepId) {
                $capacity = $rep->pivot->capacity ?? null;
                $isProxy  = $overrideProxyRepId !== null ? ($rep->id === $overrideProxyRepId) : (bool) ($rep->pivot->signs_as_proxy ?? false);
                $phrase   = $entityPreset
                    ? $entityPreset->renderPhrase($c, $rep, $capacity, $isProxy)
                    : \App\Models\Docuperfect\EsignRecipientPreset::substitute(
                        $isProxy
                            ? \App\Models\Docuperfect\EsignRecipientPreset::DEFAULT_PROXY_PHRASING
                            : \App\Models\Docuperfect\EsignRecipientPreset::DEFAULT_PHRASING,
                        $c, $rep, $capacity);
                return ['rep_name' => $rep->full_name, 'capacity' => $capacity, 'is_proxy' => $isProxy, 'phrase' => $phrase];
            })->values()->all(),
            // Johan, 2026-08-29 — "signers" above is already proxy-collapsed
            // (ONE row once a proxy is picked), which is right for "who
            // actually signs" but wrong for a PICKER's own option list — the
            // agent needs to see and choose among ALL of this company's
            // representatives, not just whoever is currently chosen. Same
            // underlying relation (Contact::representatives(), the raw pivot),
            // not a second resolution of who signs. is_proxy here reflects
            // THIS DOCUMENT's pick when one was made, never the permanent
            // pivot value in that case — the picker must show what's true
            // for this flow, not silently show a different document's pick.
            // Ordered by the SAME applyRepresentativeOrder() every other
            // consumer uses, so the reorder arrows sit on rows already in
            // the order that's actually going onto the document.
            'all_representatives' => $allReps->map(function ($rep) use ($overrideProxyRepId) {
                return [
                    'contact_id' => $rep->id,
                    'name' => $rep->full_name,
                    'capacity' => $rep->pivot->capacity ?? null,
                    'is_primary' => (bool) ($rep->pivot->is_primary ?? false),
                    'is_proxy' => $overrideProxyRepId !== null ? ($rep->id === $overrideProxyRepId) : (bool) ($rep->pivot->signs_as_proxy ?? false),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Flow 480 — re-attaches _is_entity/_representation to every entity
     * recipient row before handing $recipients to the wizard view, so the
     * step-3 preview survives a reload the same way it showed on first pick.
     * Additive only: rows without a resolvable Contact are returned as-is.
     */
    private function attachEntityRepresentationPreview(array $recipients, $user): array
    {
        $entityContactIds = collect($recipients)
            ->filter(fn ($r) => ! empty($r['_contact_id']) && ($r['_recipient_source'] ?? 'contact') === 'contact')
            ->pluck('_contact_id')
            ->unique();
        if ($entityContactIds->isEmpty()) {
            return $recipients;
        }

        $contactsById = Contact::whereIn('id', $entityContactIds)->get()->keyBy('id');
        $actingAgencyId = (int) ($user?->agency_id ?? 0);
        $entityPreset = $actingAgencyId
            ? \App\Models\Docuperfect\EsignRecipientPreset::resolveFor($actingAgencyId, 'entity')
            : null;

        foreach ($recipients as &$r) {
            $contact = ! empty($r['_contact_id']) ? ($contactsById[$r['_contact_id']] ?? null) : null;
            if (! $contact) {
                continue;
            }
            // Johan, 2026-08-26 — this loop used to `continue` past a
            // resolved NATURAL-PERSON contact entirely, leaving `_is_entity`
            // simply absent on their row (never explicitly false). The
            // recipients screen's "Deceased" checkbox binds
            // :disabled="r._is_entity" — Alpine reads an undefined boolean-
            // attribute binding as disabling, not as falsy, so a resumed
            // flow's natural-person recipient (no fresh client-side
            // selectContact() to set it) came back permanently greyed out,
            // same as the entity bug this function was built to fix, just
            // for the opposite party. Every resolvable contact now gets an
            // explicit boolean either way; _representation stays null for a
            // non-entity, exactly as before.
            $r['_is_entity'] = $contact->isEntity();
            if ($contact->isEntity()) {
                // Reopening a saved flow must show THIS flow's own pick and
                // order (already sitting on the recipient row, never on the
                // contact) — not whatever the permanent pivot happens to say.
                $overrideProxyRepId = isset($r['_entity_proxy_contact_id']) ? (int) $r['_entity_proxy_contact_id'] : null;
                $r['_representation'] = $this->buildEntityRepresentationPreview($contact, $entityPreset, $overrideProxyRepId, $this->resolveEffectiveRepOrder($r, $overrideProxyRepId));
            }
        }
        unset($r);

        return $recipients;
    }

    /**
     * API: search contacts for autocomplete.
     *
     * Returns full contact data including bank details for auto-fill.
     * Optionally filter by contact_type via ?role= parameter.
     */
    public function searchContacts(Request $request)
    {
        $q = trim($request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        // AT-131 canonical contact search (all identifiers via child tables + relevance + newest-first).
        $query = Contact::query()->with(['phones', 'emails', 'type', 'agent'])->search($q);

        // Filter by contact type role if provided — uses esign_role from contact_types
        $role = $request->input('role');
        if ($role) {
            // Map incoming role to esign_role values
            $esignRoleMap = [
                'seller'   => ['seller'],
                'buyer'    => ['buyer'],
                'landlord' => ['lessor'],
                'lessor'   => ['lessor'],
                'tenant'   => ['lessee'],
                'lessee'   => ['lessee'],
                'owner_party'     => ['seller', 'lessor'],
                'acquiring_party' => ['buyer', 'lessee'],
            ];
            $esignRoles = $esignRoleMap[strtolower($role)] ?? null;
            if ($esignRoles) {
                $typeIds = DB::table('contact_types')->whereIn('esign_role', $esignRoles)->pluck('id');
                $wantsBuyer  = in_array('buyer', $esignRoles, true);
                $wantsSeller = in_array('seller', $esignRoles, true);

                if ($wantsBuyer || $wantsSeller) {
                    // contact_type_id is mostly unpopulated; buyer/seller truth
                    // lives in is_buyer / contact_property role='owner'. Match
                    // either the (rare) typed contacts OR the canonical column.
                    $query->where(function ($w) use ($typeIds, $wantsBuyer, $wantsSeller) {
                        // Entity/company recipients are ALWAYS eligible regardless of
                        // owner/buyer typing — they sign via their representative(s),
                        // which the wizard expands server-side (entity recipient builder).
                        $w->orWhere('contact_kind', Contact::TYPE_ENTITY);
                        if ($typeIds->isNotEmpty()) {
                            // Legacy single mirror OR the AT-79 multi-parent pivot.
                            $w->orWhereIn('contact_type_id', $typeIds);
                            $w->orWhereHas('parentTypes', fn ($q) => $q->whereIn('contact_types.id', $typeIds));
                        }
                        if ($wantsBuyer) {
                            $w->orWhere('is_buyer', 1);
                        }
                        if ($wantsSeller) {
                            $w->orWhereHas('properties', fn ($q) => $q->where('contact_property.role', 'owner'));
                        }
                    });
                } elseif ($typeIds->isNotEmpty()) {
                    $query->where(function ($w) use ($typeIds) {
                        $w->where('contact_kind', Contact::TYPE_ENTITY) // entities always eligible (sign via rep)
                          ->orWhereIn('contact_type_id', $typeIds)
                          ->orWhereHas('parentTypes', fn ($q) => $q->whereIn('contact_types.id', $typeIds));
                    });
                }
            } else {
                // Fallback: match by contact_type name directly (for witness, spouse, etc.)
                $typeId = DB::table('contact_types')->where('name', 'like', '%' . $role . '%')->value('id');
                if ($typeId) {
                    $query->where(fn ($w) => $w->where('contact_type_id', $typeId)
                        ->orWhere('contact_kind', Contact::TYPE_ENTITY)); // entities always eligible
                }
            }
        }

        $contacts = $query->limit(10)->get();

        // Precompute the agency's entity preset once so each entity result can
        // preview what it expands to (rep(s) + capacity + proxy + phrasing) on
        // the recipient row — the "define on setup → shown on recipient screen".
        $actingAgencyId = (int) ($request->user()?->agency_id ?? 0);
        $entityPreset = $actingAgencyId
            ? \App\Models\Docuperfect\EsignRecipientPreset::resolveFor($actingAgencyId, 'entity')
            : null;

        // Johan, 2026-08-25 — "supplier to be added, not just adding contacts."
        // One supplier book for the whole product: the SAME Deal Register v2
        // directory (agency_service_providers / agency_service_provider_contacts),
        // never a parallel e-sign-only supplier list. A recipient is either a
        // Contact or a supplier's own working contact person (the actual
        // natural person who signs — a firm itself never signs), discriminated
        // by 'source' in the response the same way the frontend already reads
        // is_entity. Not role-filtered: a supplier (attorney, conveyancer,
        // executor) can fill any recipient slot, unlike the Contact esign_role
        // mapping above which is specific to buyer/seller/landlord/tenant typing.
        $suppliers = $actingAgencyId
            ? \App\Models\DealV2\AgencyServiceProviderContact::query()
                ->withoutGlobalScopes()
                ->where('agency_service_provider_contacts.agency_id', $actingAgencyId)
                ->where('is_active', true)
                ->whereHas('firm', fn ($fq) => $fq->where('is_active', true))
                ->with('firm')
                ->where(function ($w) use ($q) {
                    $t = '%' . $q . '%';
                    $w->where('attorney_name', 'like', $t)
                      ->orWhere('contact_person', 'like', $t)
                      ->orWhere('email', 'like', $t)
                      ->orWhereHas('firm', fn ($fq) => $fq->where('name', 'like', $t)->orWhere('company', 'like', $t));
                })
                ->limit(5)
                ->get()
            : collect();

        $contactResults = $contacts->map(function ($c) use ($q, $entityPreset) {
            $representation = $this->buildEntityRepresentationPreview($c, $entityPreset);
            return [
                'id'                  => $c->id,
                'source'              => 'contact',
                'first_name'          => $c->first_name,
                'last_name'           => $c->last_name,
                // full_name via the accessor so ENTITY/company contacts show their
                // entity_name (first/last are blank for entities). Entity flags let
                // the picker badge a company — on select it expands server-side into
                // its proxy-aware signing representatives (entity recipient builder).
                'full_name'           => $c->full_name,
                'is_entity'           => $c->isEntity(),
                'entity_name'         => $c->entity_name,
                'entity_reg_no'       => $c->entity_reg_no,
                'identifier'          => $c->matchedIdentifier($q),
                'agent'               => $c->agent?->name,
                'email'               => $c->email ?? '',
                'phone'               => $c->phone ?? '',
                'id_number'           => $c->id_number ?? '',
                'address'             => $c->address ?? '',
                'contact_type'        => $c->type?->name ?? '',
                'esign_role'          => $c->type?->esign_role ?? null,
                'bank_name'           => $c->bank_name ?? '',
                'bank_account_name'   => $c->bank_account_name ?? '',
                'bank_account_number' => $c->bank_account_number ?? '',
                'bank_branch_name'    => $c->bank_branch_name ?? '',
                'bank_branch_code'    => $c->bank_branch_code ?? '',
                'bank_account_type'   => $c->bank_account_type ?? '',
                // Entity recipient preview — the rep(s)/capacity/proxy + resolved
                // "herein represented by …" phrasing this company expands into.
                'representation'      => $representation,
            ];
        });

        // Same response shape as a contact result, wherever the fields map
        // cleanly — bank fields stay '' (suppliers do not carry them; the
        // recipient row already treats a blank id_number as "not printed"
        // rather than an error, same as a Contact with none on file).
        // Address/phone fall back to the FIRM's when the specific working
        // contact has none of their own — AgencyServiceProviderContact has
        // no address field at all, only the firm does.
        //
        // Johan, 2026-08-26 — id_number now reads the REPRESENTATIVE's own
        // id_number (new 2026_08_29_000007), not the firm's
        // registration_number. Previously it borrowed the firm's number
        // (2026-08-25) as a stand-in for both concepts; now that the two
        // are genuinely separate fields, this is the one that belongs on a
        // PERSON — the firm's own registration_number is checked
        // separately, alongside this, by
        // assertSupplierRepresentativesHaveRegistrationNumber(). No
        // backfill exists for this field yet (see the migration's
        // docblock) — existing representatives show blank here until an
        // agent adds it on the supplier directory screen.
        $supplierResults = $suppliers->map(function (\App\Models\DealV2\AgencyServiceProviderContact $sc) {
            $firm = $sc->firm;
            $name = trim((string) ($sc->attorney_name ?: $sc->contact_person ?: ($firm->name ?? '')));
            $parts = preg_split('/\s+/', $name, 2);

            return [
                'id'                  => $sc->id,
                'source'              => 'supplier',
                'first_name'          => $parts[0] ?? '',
                'last_name'           => $parts[1] ?? '',
                'full_name'           => $name,
                'is_entity'           => false,
                'entity_name'         => null,
                'entity_reg_no'       => null,
                'identifier'          => $sc->email ?: $sc->phone ?: '',
                'agent'               => null,
                'email'               => $sc->email ?: '',
                'phone'               => $sc->phone ?: ($firm->phone ?? ''),
                'id_number'           => $sc->id_number ?? '',
                'address'             => $firm->address ?? '',
                'contact_type'        => 'Supplier',
                'esign_role'          => null,
                'bank_name'           => '',
                'bank_account_name'   => '',
                'bank_account_number' => '',
                'bank_branch_name'    => '',
                'bank_branch_code'    => '',
                'bank_account_type'   => '',
                'representation'      => null,
                // Supplier-only fields the recipient row/picker need to keep
                // this reusable back on a deal later (Johan's design: one
                // supplier book, the e-sign-captured supplier lives where a
                // deal can pick it up too).
                'supplier_contact_id' => $sc->id,
                'supplier_firm_id'    => $firm->id ?? null,
                // Johan, 2026-08-26 — "company leads, person underneath, and
                // where there is no company the person leads." $firm->name is
                // the firm's own required identifier and is NOT necessarily
                // the trading name an agent typed for a real company (a
                // sole-practitioner firm often has the PERSON's own name in
                // this field, e.g. "Piet Begrafnis" as both the firm's name
                // AND the contact's own name) — $firm->company is the actual
                // company name when one was captured; that's what belongs
                // here, never the raw firm identifier.
                'supplier_firm_name'  => ($firm->company ?: $firm->name) ?? '',
                // The company half of the three-part clause chain (Johan,
                // 2026-08-26) — cached on the picked recipient client-side
                // for the live document preview
                // (RecipientTemplate::resolveBoundTextFromArray()); the
                // authoritative copy frozen at Send is looked up live from
                // this same firm record (stampSupplierFirmIfAny()), never
                // trusted from this cached value.
                'supplier_firm_registration_number' => $firm->registration_number ?? '',
                'supplier_role'       => $sc->role ?? '',
            ];
        });

        return response()->json($contactResults->concat($supplierResults)->values());
    }

    /**
     * Johan, 2026-08-25 — "adding a supplier from inside the wizard." Called
     * before addSupplier() so the agent sees a possible match and can confirm
     * or dismiss it, never a silent auto-merge and never a silent miss.
     * Deliberately NOT the ID-number-only pattern used elsewhere in the
     * codebase for a different quick-add flow — a supplier's working contact
     * has no ID number at all; see AgencyServiceProviderService::
     * findPossibleDuplicateContacts() for the real, multi-field check.
     */
    public function checkSupplierDuplicate(Request $request, \App\Services\DealV2\AgencyServiceProviderService $service): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'required|string|max:255',
            'email'     => 'nullable|email|max:255',
            'phone'     => 'nullable|string|max:50',
            'firm_name' => 'nullable|string|max:255',
        ]);

        $agencyId = (int) ($request->user()?->agency_id ?? 0);
        if ($agencyId <= 0) {
            return response()->json(['matches' => []]);
        }

        $matches = $service->findPossibleDuplicateContacts(
            $agencyId,
            $validated['name'],
            $validated['email'] ?? null,
            $validated['phone'] ?? null,
            $validated['firm_name'] ?? null,
        );

        return response()->json([
            // Johan, 2026-08-26 — enriched with the same fields a picker
            // search result carries (supplier_firm_id, id_number, address)
            // so "use this one" in the quick-add screen can select the
            // match through the exact same path as a picked search result,
            // rather than a second, thinner shape.
            'matches' => $matches->map(fn ($m) => [
                'supplier_contact_id' => $m['contact']->id,
                'supplier_firm_id'    => $m['contact']->firm->id ?? null,
                'name'                => $m['contact']->attorney_name ?: $m['contact']->contact_person,
                'firm_name'           => $m['contact']->firm->name ?? '',
                'email'               => $m['contact']->email,
                'phone'               => $m['contact']->phone,
                'address'             => $m['contact']->firm->address ?? '',
                'id_number'           => $m['contact']->id_number ?? '',
                'firm_registration_number' => $m['contact']->firm->registration_number ?? '',
                'reasons'             => $m['reasons'],
            ])->values(),
        ]);
    }

    /**
     * Creates (or reuses, at the firm level) a supplier and adds a new
     * working contact under it — Johan's design: one supplier book for the
     * whole product, so a supplier captured here is the SAME record a deal
     * can pick up later, not a parallel e-sign-only entry. The agent must
     * have already seen checkSupplierDuplicate()'s result and either found
     * nothing or explicitly confirmed to proceed anyway (confirmed=true) —
     * this endpoint does not silently re-run the check and block on it,
     * since that decision belongs to the human looking at the match.
     */
    public function addSupplier(Request $request, \App\Services\DealV2\AgencyServiceProviderService $service): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'name'                 => 'required|string|max:255',
            'email'                => 'nullable|email|max:255',
            'phone'                => 'nullable|string|max:50',
            'firm_name'            => 'required|string|max:255',
            'specialty'            => 'nullable|string|max:100',
            // Johan, 2026-08-26 — "Registration/ID number field present" in
            // the quick-add screen. Reuses the SAME id_number field a
            // picked recipient already has, so the agent isn't learning a
            // second field name for the same thing.
            'registration_number'  => 'nullable|string|max:100',
        ]);

        $agencyId = (int) ($request->user()?->agency_id ?? 0);

        $firm = $service->findOrCreate($agencyId, [
            'name'                 => $validated['firm_name'],
            'specialty'            => $validated['specialty'] ?? 'other',
            'registration_number'  => $validated['registration_number'] ?? null,
        ], $request->user()?->id);

        $contact = \App\Models\DealV2\AgencyServiceProviderContact::create([
            'agency_id'            => $agencyId,
            'service_provider_id'  => $firm->id,
            'attorney_name'        => $validated['name'],
            'email'                => $validated['email'] ?? null,
            'phone'                => $validated['phone'] ?? null,
            'is_active'            => true,
            'created_by_id'        => $request->user()?->id,
        ]);

        return response()->json([
            'id'                  => $contact->id,
            'source'              => 'supplier',
            'full_name'           => $contact->attorney_name,
            'first_name'          => $contact->attorney_name,
            'last_name'           => '',
            'email'               => $contact->email ?? '',
            'phone'               => $contact->phone ?: ($firm->phone ?? ''),
            'address'             => $firm->address ?? '',
            // Johan, 2026-08-26 — this quick-add screen only ever captures
            // the FIRM's registration number, never a representative's own
            // ID (that field lives on the Deal Register supplier screen,
            // added after this contact exists). id_number here is the
            // REPRESENTATIVE's own field (blank on a brand-new contact,
            // same as any other optional field) — the firm's registration
            // number is carried separately below.
            'id_number'           => $contact->id_number ?? '',
            'contact_type'        => 'Supplier',
            'supplier_contact_id' => $contact->id,
            'supplier_firm_id'    => $firm->id,
            // Johan, 2026-08-26 — company leads over the firm's own raw
            // identifier, same rule as the picker (see searchContacts()).
            'supplier_firm_name'  => ($firm->company ?: $firm->name) ?? '',
            'supplier_firm_registration_number' => $firm->registration_number ?? '',
        ]);
    }

    /**
     * Johan, 2026-08-25 (cc4's finding — caught too late) — "Replace this
     * party" is where the agent is already thinking about the exact person
     * standing in for someone else; the missing-registration-number block
     * moved there too (see the frontend's bindSlotToRecipient()). This is
     * the single-purpose save that makes fixing it right there possible
     * without a rebuild of the full supplier directory form: JUST the
     * registration number, none of SupplierDirectoryController::update()'s
     * other required fields (name/specialty), because the agent in this
     * modal has neither on hand and re-fetching the whole firm record to
     * satisfy them would be exactly the "something elaborate" Johan said
     * not to build tonight.
     */
    public function updateSupplierRegistrationNumber(Request $request, \App\Models\DealV2\AgencyServiceProvider $firm): \Illuminate\Http\JsonResponse
    {
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? 0);
        if ($agencyId <= 0 || (int) $firm->agency_id !== $agencyId) {
            abort(403);
        }

        $validated = $request->validate([
            'registration_number' => 'required|string|max:100',
        ]);

        $firm->update(['registration_number' => $validated['registration_number']]);

        return response()->json([
            'ok' => true,
            'supplier_firm_id' => $firm->id,
            'registration_number' => $firm->registration_number,
        ]);
    }

    /**
     * Johan, 2026-08-29 — "the proxy tick essentially does nothing... it
     * should let you pick one of the parties on the company to select as
     * the proxy." The recipient row's own _is_proxy checkbox was flagging
     * the ENTITY's row, which expandEntityRecipients() discards the moment
     * it expands into the real representative rows — the flag that
     * actually matters is contact_representatives.signs_as_proxy on the
     * CHOSEN representative, which nothing in the wizard let an agent set.
     *
     * Johan, 2026-08-26 (bug found testing 913f2f102) — the FIRST version of
     * this endpoint wrote the pick to contact_representatives.signs_as_proxy/
     * is_primary directly. That is a SHARED, permanent record — a pick made
     * on one document showed up already selected on the next, unrelated
     * document for the same company, exactly the class of fault cc2 fixed
     * the same day for a supplier-executor wrongly linked onto a property as
     * owner (81f183284): a per-document choice leaking into permanent data.
     * READ-ONLY now — no write of any kind, to this contact or any other
     * record. It validates the pick against this company's real, currently-
     * linked representatives and returns a computed preview of what the
     * document would look like with that pick applied; the pick itself is
     * held by the wizard purely client-side and saved only inside THIS
     * flow's own step_data (_entity_proxy_contact_id on the recipient row),
     * the same way _is_deceased/_slot_bindings already are. Contact::
     * signingRepresentatives()/RoleBlockExpansionService::
     * composeEntityPartyText() both now accept that same per-document
     * override directly — reused, not duplicated — so the signing decision
     * and the document's clause text agree without either ever consulting
     * or touching the permanent pivot for a document-scoped pick.
     */
    public function setEntityProxy(Request $request, Contact $contact): \Illuminate\Http\JsonResponse
    {
        $agencyId = (int) ($request->user()?->effectiveAgencyId() ?? 0);
        if ($agencyId <= 0 || (int) $contact->agency_id !== $agencyId) {
            abort(403);
        }

        if (! $contact->isEntity()) {
            return response()->json(['ok' => false, 'error' => 'Only a company/entity party can have a proxy representative.'], 422);
        }

        $validated = $request->validate([
            // Nullable, not required — sending no id previews "nobody
            // chosen yet" (the wizard clears its own local pick either way).
            'representative_contact_id' => 'nullable|integer',
            // Johan, 2026-08-26 — "1st director - 1st signature position."
            // Optional ordered list of representative contact ids from the
            // recipient's own manual reorder (up/down arrows); absent means
            // "no manual order set on this document" — the caller (this
            // same wizard) still applies the proxy-first default itself via
            // buildEntityRepresentationPreview()'s existing precedence.
            'order' => 'nullable|array',
            'order.*' => 'integer',
        ]);

        $chosenId = $validated['representative_contact_id'] ?? null;
        $order = $validated['order'] ?? null;

        if ($chosenId !== null && ! $contact->representatives()->get()->contains('id', (int) $chosenId)) {
            return response()->json([
                'ok' => false,
                'error' => 'That person is not currently linked as a representative of '
                    . ($contact->entity_name ?: 'this company') . ' — refusing to set them as proxy. '
                    . 'Link them as a representative first, on the contact record.',
            ], 422);
        }

        $actingAgencyId = (int) ($request->user()?->agency_id ?? 0);
        $entityPreset = $actingAgencyId
            ? \App\Models\Docuperfect\EsignRecipientPreset::resolveFor($actingAgencyId, 'entity')
            : null;

        $overrideProxyRepId = $chosenId !== null ? (int) $chosenId : null;
        $effectiveOrder = $this->resolveEffectiveRepOrder(['_entity_rep_order' => $order], $overrideProxyRepId);

        return response()->json([
            'ok' => true,
            'representation' => $this->buildEntityRepresentationPreview($contact, $entityPreset, $overrideProxyRepId, $effectiveOrder),
        ]);
    }

    /**
     * API: get template pages + fields for preview.
     */
    public function templatePages(Request $request, $templateId)
    {
        $template = Template::findOrFail($templateId);
        $user = $request->user();

        // Check if this is a pack flow
        $flow = null;
        $stepData = [];
        $packTemplateIds = null;
        $flowId = $request->query('flow_id');
        if ($flowId) {
            $flow = Flow::where('user_id', $user->id)->find($flowId);
            if ($flow) {
                $stepData = $flow->step_data ?? [];
                if (!empty($stepData['is_pack_flow']) && !empty($stepData['template_ids'])) {
                    $packTemplateIds = $stepData['template_ids'];
                }

                // Fault 3, round 2 (Johan, 2026-08-24) — this is the LIVE-REFRESH
                // endpoint the wizard calls after every field edit (loadTemplatePreview),
                // so it must prepare recipients (auto-populate + entity expansion) the
                // SAME way showStep()'s initial page load does — see
                // prepareRecipientsForMerge()'s docblock. Without this, a refreshed
                // preview showed different — or missing — party text than the page just
                // loaded with. Safe to reassign $stepData straight to the EXPANDED form
                // here (unlike showStep()) — this endpoint only ever returns rendered
                // HTML/pages, never an editable recipients form (fault 3, round 3 — see
                // expandRecipientsForMerge()'s docblock for why that distinction matters).
                $stepData = $this->prepareRecipientsForMerge($stepData, $template, $user, (int) ($flow->current_step ?? 1));
                $stepData = $this->expandRecipientsForMerge($stepData, $user);
            }
        }

        // PDF pack flow: return concatenated page images from all templates
        if ($flow && !empty($stepData['is_pdf_pack']) && !empty($stepData['template_ids'])) {
            $allPages = [];
            $mergedFields = $stepData['fields'] ?? [];
            $totalPageCount = 0;

            foreach ($stepData['template_ids'] as $tplId) {
                $tpl = Template::find($tplId);
                if ($tpl && $tpl->page_count > 0) {
                    for ($n = 0; $n < $tpl->page_count; $n++) {
                        $allPages[] = route('docuperfect.page.image', ['id' => $tplId, 'page' => $n]);
                    }
                    $totalPageCount += $tpl->page_count;
                }
            }

            return response()->json([
                'render_type'   => 'pdf',
                'page_count'    => $totalPageCount,
                'pages'         => $allPages,
                'fields'        => $mergedFields,
                'wizard_config' => $template->wizard_config,
                'name'          => $stepData['pdf_pack_name'] ?? $template->name,
                'template_type' => $template->template_type,
                'is_pdf_pack'   => true,
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
              ->header('Pragma', 'no-cache');
        }

        if ($template->render_type === 'web' && $template->blade_view) {
            if ($packTemplateIds) {
                // Pack flow — merge all templates
                $mergedHtml = '';
                foreach ($packTemplateIds as $idx => $tplId) {
                    $tpl = Template::find($tplId);
                    if (!$tpl || !$tpl->blade_view) continue;

                    $tplData = app(WebTemplateDataService::class)
                        ->resolve($tplId, $stepData, $user);

                    // Overlay fill_review field values
                    $frValues = $stepData['fill_review']['fieldValues'] ?? [];
                    if (!empty($frValues)) {
                        $fieldsJson = $stepData['fields'] ?? ($tpl->fields_json ?? []);
                        foreach ($fieldsJson as $field) {
                            $fId = $field['id'] ?? null;
                            $fName = $field['field_name'] ?? null;
                            $fTplId = $field['_pack_template_id'] ?? null;
                            if ($fId && $fName && isset($frValues[$fId]) && $frValues[$fId] !== '') {
                                if ($fTplId === null || (int) $fTplId === (int) $tplId) {
                                    $tplData[$fName] = $frValues[$fId];
                                }
                            }
                        }
                    }

                    if (!empty($tpl->signing_parties)) {
                        $tplData['signing_parties'] = $tpl->signing_parties;
                        $propSrc = $stepData['property']['_property_source'] ?? null;
                        $tplData['document_context'] = $tpl->isSalesDocument($propSrc) ? 'sales' : 'rental';
                    }
                    // Blank-preview fix: regenerate the generated blade if its file is
                    // missing, and never blank on a render failure (stored-HTML fallback).
                    $html = app(\App\Services\Docuperfect\WebTemplateBladeEnsurer::class)->renderOrFallback($tpl, $tplData);
                    $styles = '';
                    preg_match_all('/<style[^>]*>.*?<\/style>/si', $html, $sm);
                    if (!empty($sm[0])) {
                        $styles = implode("\n", $sm[0]);
                    }
                    $bodyHtml = $html;
                    if (preg_match('/<body[^>]*>(.*)<\/body>/si', $html, $bm)) {
                        $bodyHtml = $bm[1];
                    }
                    $pageBreak = $idx < count($packTemplateIds) - 1
                        ? '<div style="page-break-after:always; border-bottom:2px dashed #ccc; margin:20px 0;"></div>'
                        : '';
                    $mergedHtml .= $styles . "\n" . $bodyHtml . $pageBreak;
                }

                // Fill & Review strike-outs — replay the agent's creation-time strikes onto the live pack
                // preview via the SAME universal engine as the single-doc preview (:replayBodyStrikes below),
                // so what the agent sees in the pack preview is byte-identical to what the signed pack carries.
                $mergedHtml = $this->replayBodyStrikes(
                    $mergedHtml,
                    $stepData,
                    $this->buildFillReviewSigningParties($stepData, $template, $user),
                );

                return response()->json([
                    'render_type' => 'web',
                    'html'        => $mergedHtml,
                ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
                  ->header('Pragma', 'no-cache');
            }

            // Single template — render normally
            $viewData = [];
            if ($flow) {
                $viewData = app(WebTemplateDataService::class)
                    ->resolve($template->id, $stepData, $user);

                // AT-360b — overlay fill_review typed values via the SAME shared method the send
                // path uses, so the wizard preview matches the signed document exactly. This also
                // carries the sanitised-var keying (AT-359b) and the per-recipient "{base}__r{n}"
                // instance handling — the previous inline loop keyed by the raw field_name and the
                // base id only, so a composite name or a multi-recipient edit did not preview.
                $stepForOverlay = $stepData;
                if (empty($stepForOverlay['fields'])) {
                    $stepForOverlay['fields'] = $template->fields_json ?? [];
                }
                $viewData = $this->overlayFillReviewValues($viewData, $stepForOverlay);
            }

            // Web templates render full HTML documents (DOCTYPE/html/head/body).
            // Strip to inner body content so it can be injected via x-html.
            if (!empty($template->signing_parties)) {
                $viewData['signing_parties'] = $template->signing_parties;
                $propSrc = $stepData['property']['_property_source'] ?? null;
                $viewData['document_context'] = $template->isSalesDocument($propSrc) ? 'sales' : 'rental';
            }
            // Blank-preview fix: regenerate the generated blade if its file is missing,
            // and never blank on a render failure (stored-HTML fallback).
            $fullHtml = app(\App\Services\Docuperfect\WebTemplateBladeEnsurer::class)->renderOrFallback($template, $viewData);
            $bodyHtml = $fullHtml;
            if (preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $m)) {
                $bodyHtml = trim($m[1]);
            }
            // Also extract <style> blocks from <head> and prepend them
            $styles = '';
            if (preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches)) {
                $styles = implode("\n", $styleMatches[0]);
            }

            // Phase 1B.5 — render insertable-block placeholders inline so the
            // wizard agent sees styled blocks instead of literal `~~~~MARKER~~~~`
            // text in the right-pane preview. The recipient-signing pipeline
            // re-renders against the live document instance at signing time;
            // here we render against a synthetic SignatureTemplate so the
            // unbound-marker fallback handles older templates too.
            $previewHtml = $styles . $bodyHtml;
            $previewSigTemplate = new \App\Models\Docuperfect\SignatureTemplate();
            $previewSigTemplate->id = 0;
            $previewSigTemplate->document_id = 0;
            $previewSigTemplate->setRelation('template', $template);
            $previewBlocks = $template->insertable_blocks ?? [];
            $previewHtml = app(\App\Services\Docuperfect\InsertableBlockRenderer::class)
                ->renderInDocument(
                    $previewHtml,
                    $previewSigTemplate,
                    is_array($previewBlocks) ? $previewBlocks : [],
                    \App\Services\Docuperfect\InsertableBlockRenderer::CONTEXT_AGENT_PREPARATION,
                    null
                );

            // E-sign walk-fix FIX 1 — run the same recipient-loop engine
            // that fires on the recipient signing surface so the wizard
            // Step 5 preview shows N seller blocks for an N-seller session
            // instead of ONE block with all sellers concatenated. We
            // build a transient Collection of SignatureRequest models
            // (in-memory only, not persisted — the wizard is still pre-
            // dispatch) from the flow's step_data recipients so the
            // expansion service has the same shape it sees at signing
            // time.
            if ($flow) {
                // Johan, 2026-08-26 — "company esign still blocks on only
                // rendering 1 seller address, tel, email" — a company
                // represented by three parties (Elize Reichel, HA Pretorius,
                // Steve Jobs). Two DIFFERENT, both-correct-in-their-own-place
                // transformations were being conflated here:
                //
                // By this point $stepData came through expandRecipientsForMerge()
                // (above, in this same method) — which itself expands the
                // entity into its 3 representative rows, but then DELIBERATELY
                // dedupeEntityRecipientsForDisplay()s them back down to ONE
                // row sharing the entity's _entity_contact_id, specifically so
                // resolveFieldGroupValue()'s "and"-joined clause names the
                // company/all-reps ONCE, not three times. That collapse is
                // correct for the clause — Johan's own words: "The CLAUSE
                // naming everyone while the signing EMAIL goes to one signer
                // is DELIBERATE and correct. Do not touch it."
                //
                // But $stepData['recipients']['recipients'] — already
                // collapsed to 1 row for that reason — is the WRONG input
                // for the address/tel/email role-block expansion below: "the
                // document's own per-recipient detail fields... must carry
                // all three." Re-expanding the already-deduped 1 row finds
                // nothing further to expand (it is now its own individual
                // contact, not the entity's) — which is exactly why an
                // earlier attempt at this fix still produced only one block.
                // The fix reaches past the dedup: expand the flow's OWN raw,
                // un-deduped recipients (the same source
                // expandRecipientsForMerge() itself expands from) fresh,
                // right here, for this one purpose only — never written back
                // to $stepData, so the clause-collapse above is untouched.
                $wizardRecipients = $this->buildTransientSignatureRequestsForPreview(
                    $flow,
                    // Johan, 2026-08-26: "all parties must show on the
                    // document, although only 1 party will actually sign."
                    // Proxy narrows WHO SIGNS (elsewhere, unchanged); it must
                    // never narrow what renders here. Display is now the
                    // default (see expandEntityRecipients() docblock) — no
                    // flag needed at this call site.
                    $this->expandEntityRecipients($flow->step_data['recipients']['recipients'] ?? [], $user),
                );
                if ($wizardRecipients->isNotEmpty()) {
                    // AT-295 — stamp the data-role-block contract onto the raw
                    // preview HTML BEFORE expansion. Imported blades carry no
                    // contract (0/39 web-templates have data-role-block; it is
                    // only stamped into merged_html at document generation), so
                    // without this the preview enters expandWithLooping with
                    // $hasContract=false and falls to the LEGACY clustering path
                    // where AT-291 ⑥'s same-party dedup never runs — the seller
                    // block renders TWICE on the agent pre-send screen. Running
                    // the same normalizer the recipient path uses routes BOTH
                    // surfaces through the one corrected contract renderer
                    // (bug-class, not instance): a single seller renders once,
                    // genuine multi-seller still expands N times.
                    $previewHtml = app(\App\Services\Docuperfect\RoleBlockNormalizer::class)
                        ->normalize($previewHtml);
                    $previewHtml = app(\App\Services\Docuperfect\RoleBlockExpansionService::class)
                        ->expandWithLooping(
                            $template,
                            $previewHtml,
                            $wizardRecipients,
                        );
                }
            }

            // AT-360c — the Fill & Review preview is a SEPARATE render surface from compose(), but it
            // has the SAME defect: expandWithLooping (above) re-resolves each recipient's contact fields
            // straight from the Contact model, clobbering the agent's per-recipient "{var}__r{n}" edits
            // (Seller 2's phone showed the DB value, not the edit). Re-assert the authoritative overlay
            // as the LAST word — the identical pass compose() runs at signing — so the preview is
            // what-you-see-equals-what-you-get with the signed document. No-op when nothing was edited.
            $previewHtml = app(\App\Services\Docuperfect\CanonicalDocumentRenderer::class)
                ->applyFillReviewAuthoritativeOverlay(
                    $previewHtml,
                    is_array($viewData['_fill_review_overlay'] ?? null) ? $viewData['_fill_review_overlay'] : [],
                );

            // Fill & Review strike-outs — replay the agent's creation-time strikes onto the live preview so
            // what they see is what the signed document carries (same universal engine as the sign screen).
            if ($flow) {
                $previewHtml = $this->replayBodyStrikes(
                    $previewHtml,
                    $stepData,
                    $this->buildFillReviewSigningParties($stepData, $template, $user),
                );
            }

            return response()->json([
                'render_type'   => 'web',
                'blade_view'    => $template->blade_view,
                'html'          => $previewHtml,
                'page_count'    => $template->page_count,
                'fields'        => $template->fields_json ?? [],
                'wizard_config' => $template->wizard_config,
                'name'          => $template->name,
                'template_type' => $template->template_type,
            ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
              ->header('Pragma', 'no-cache');
        }

        return response()->json([
            'render_type'   => 'pdf',
            'page_count'    => $template->page_count,
            'pages'         => $template->pageImages,
            'fields'        => $template->fields_json ?? [],
            'wizard_config' => $template->wizard_config,
            'name'          => $template->name,
            'template_type' => $template->template_type,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
          ->header('Pragma', 'no-cache');
    }

    /**
     * Soft-delete a draft flow (set status to abandoned).
     */
    public function destroy(Request $request, $flowId)
    {
        $flow = Flow::where('user_id', $request->user()->id)
            ->findOrFail($flowId);

        $flow->status = 'abandoned';
        $flow->save();

        return response()->json(['success' => true]);
    }

    /**
     * Silently autosave field values from step 5 (Fill & Review).
     * Merges into flow->step_data['fill_review']['fieldValues'] without full validation.
     */
    public function autosaveFields(Request $request, $flowId)
    {
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);

        $fieldValues = $request->input('fieldValues', []);
        $stepData = $flow->step_data ?? [];
        $stepData['fill_review'] = $stepData['fill_review'] ?? [];
        $stepData['fill_review']['fieldValues'] = array_merge(
            $stepData['fill_review']['fieldValues'] ?? [],
            $fieldValues
        );
        $flow->step_data = $stepData;
        $flow->save();

        return response()->json(['ok' => true]);
    }

    /**
     * FILL & REVIEW strike-out (Johan 2026-08-05) — the agent striking out an unwanted section AT CREATION
     * time. Stores the strike (highlighted text + context + mode) on the flow's step_data so it is replayed,
     * via the SAME universal engine (SelectionEditService::applyStrikeToHtml), onto every compose — the live
     * preview AND the final signed document. 'inline' = strike + reword; 'strike' = pure removal. The
     * full-width per-party initial row is authored with the strike (all parties initial the change).
     */
    public function bodyStrike(Request $request, $flowId)
    {
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);

        $v = $request->validate([
            'selected'    => ['required', 'string', 'max:8000'],
            'prefix'      => ['nullable', 'string', 'max:200'],
            'suffix'      => ['nullable', 'string', 'max:200'],
            'replacement' => ['nullable', 'string', 'max:8000', 'required_unless:mode,strike'],
            'mode'        => ['nullable', 'in:inline,strike'],
        ]);
        $mode = ($v['mode'] ?? 'inline') === 'strike' ? 'strike' : 'inline';

        $stepData = $flow->step_data ?? [];
        $stepData['fill_review'] = $stepData['fill_review'] ?? [];
        $strikes = $stepData['fill_review']['body_strikes'] ?? [];
        $strikes[] = [
            'selected'    => trim($v['selected']),
            'prefix'      => $v['prefix'] ?? '',
            'suffix'      => $v['suffix'] ?? '',
            'replacement' => $mode === 'strike' ? '' : ($v['replacement'] ?? ''),
            'mode'        => $mode,
            'at'          => now()->toIso8601String(),
        ];
        $stepData['fill_review']['body_strikes'] = $strikes;
        $flow->step_data = $stepData;
        $flow->save();

        return response()->json(['ok' => true, 'count' => count($strikes)]);
    }

    /**
     * EDIT an applied Fill & Review amendment (Johan 2026-08-06) — change its replacement text / switch it
     * between reword and pure strike-out. Keyed by the change-id the clicked mark carries (derived the same way
     * the render stamps it), so the edit reflects on every surface (preview replay now, baked at send). Other
     * amendments in the doc are untouched — each body strike is independent and replayed on its own.
     */
    public function bodyStrikeEdit(Request $request, $flowId)
    {
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);

        $v = $request->validate([
            'change_id'   => ['required', 'string', 'max:64'],
            'replacement' => ['nullable', 'string', 'max:8000', 'required_unless:mode,strike'],
            'mode'        => ['nullable', 'in:inline,strike'],
        ]);
        $mode = ($v['mode'] ?? 'inline') === 'strike' ? 'strike' : 'inline';

        $stepData = $flow->step_data ?? [];
        $strikes = $stepData['fill_review']['body_strikes'] ?? [];
        $found = false;
        foreach ($strikes as &$s) {
            if (! is_array($s)) {
                continue;
            }
            $cid = \App\Services\Docuperfect\SelectionEditService::changeId(
                (string) ($s['prefix'] ?? ''),
                (string) ($s['selected'] ?? ''),
                (string) ($s['replacement'] ?? ''),
            );
            if ($cid === $v['change_id']) {
                $s['replacement'] = $mode === 'strike' ? '' : trim((string) ($v['replacement'] ?? ''));
                $s['mode']        = $mode;
                $s['at']          = now()->toIso8601String();
                $found = true;
                break;
            }
        }
        unset($s);

        if (! $found) {
            return response()->json(['ok' => false, 'error' => 'Amendment not found.'], 404);
        }
        $stepData['fill_review']['body_strikes'] = $strikes;
        $flow->step_data = $stepData;
        $flow->save();

        return response()->json(['ok' => true]);
    }

    /**
     * REMOVE an applied Fill & Review amendment (Johan 2026-08-06) — revert that section to its original text
     * and drop its strike/insert + the per-party "Initial this change" block, everywhere. The strike is deleted
     * from step_data so the replay no longer applies it (the original source text shows through); the send bakes
     * the reduced set. Other amendments are unaffected.
     */
    public function bodyStrikeRemove(Request $request, $flowId)
    {
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);

        $v = $request->validate(['change_id' => ['required', 'string', 'max:64']]);

        $stepData = $flow->step_data ?? [];
        $strikes = $stepData['fill_review']['body_strikes'] ?? [];
        $before = is_array($strikes) ? count($strikes) : 0;
        $strikes = array_values(array_filter(is_array($strikes) ? $strikes : [], function ($s) use ($v) {
            if (! is_array($s)) {
                return false;
            }
            $cid = \App\Services\Docuperfect\SelectionEditService::changeId(
                (string) ($s['prefix'] ?? ''),
                (string) ($s['selected'] ?? ''),
                (string) ($s['replacement'] ?? ''),
            );
            return $cid !== $v['change_id'];
        }));

        $stepData['fill_review'] = $stepData['fill_review'] ?? [];
        $stepData['fill_review']['body_strikes'] = $strikes;
        $flow->step_data = $stepData;
        $flow->save();

        return response()->json(['ok' => true, 'removed' => $before - count($strikes), 'count' => count($strikes)]);
    }

    /**
     * Replay the flow's stored Fill & Review strike-outs onto a composed HTML body. Reuses the sign-screen
     * amend engine verbatim, so a creation-time strike renders identically to a returned-doc strike (struck
     * <del> + optional <ins> + the per-party initial row). Idempotent: applyStrikeToHtml skips text already
     * inside a change mark, so re-composing never double-strikes. Non-fatal — a strike that no longer locates
     * (the underlying text changed) is simply skipped.
     */
    private function replayBodyStrikes(string $html, array $stepData, array $partiesForSigning): string
    {
        $strikes = $stepData['fill_review']['body_strikes'] ?? [];
        if (empty($strikes) || ! is_array($strikes) || trim($html) === '') {
            return $html;
        }
        $parties = $this->fillReviewInitialParties($partiesForSigning);
        $svc = app(\App\Services\Docuperfect\SelectionEditService::class);
        foreach ($strikes as $s) {
            if (! is_array($s)) {
                continue;
            }
            $out = $svc->applyStrikeToHtml(
                $html,
                (string) ($s['selected'] ?? ''),
                (string) ($s['prefix'] ?? ''),
                (string) ($s['suffix'] ?? ''),
                (string) ($s['replacement'] ?? ''),
                (string) ($s['mode'] ?? 'inline'),
                $parties,
            );
            if ($out !== null) {
                $html = $out['html'];
            }
        }
        return $html;
    }

    /** Map the send-path party list [{role, name, display}] → the initial-row party set [{key, name}] (role_N for duplicates). */
    private function fillReviewInitialParties(array $partiesForSigning): array
    {
        $counts = [];
        $out = [];
        foreach ($partiesForSigning as $p) {
            $role = (string) ($p['role'] ?? 'party');
            $counts[$role] = ($counts[$role] ?? 0) + 1;
            $key = $counts[$role] > 1 ? $role . '_' . $counts[$role] : $role;
            $out[] = ['key' => $key, 'name' => (string) ($p['display'] ?? $p['name'] ?? ucfirst($role))];
        }
        return $out;
    }

    /** The signing-party set for a flow at Fill & Review: the agent + each recipient with its concrete role. */
    private function buildFillReviewSigningParties(array $stepData, Template $template, $user): array
    {
        $propSource = $stepData['property']['_property_source'] ?? null;
        $isSalesContext = ($propSource === 'properties')
            || (! $propSource && str_contains(strtolower($template->name ?? ''), 'sell'));
        $parties = [['role' => 'agent', 'name' => $user->name, 'display' => $user->name]];
        foreach ($stepData['recipients']['recipients'] ?? [] as $r) {
            $role = $r['role'] ?? 'party';
            if ($role === 'owner_party') {
                $role = $isSalesContext ? 'seller' : 'landlord';
            } elseif ($role === 'acquiring_party') {
                $role = $isSalesContext ? 'buyer' : 'tenant';
            }
            $parties[] = ['role' => $role, 'name' => $r['name'] ?? ucfirst($role), 'display' => $r['name'] ?? ucfirst($role)];
        }
        return $parties;
    }

    /**
     * AT-360 — overlay the agent's Fill & Review typed values onto a resolved web_template_data
     * array, keyed by each field's blade variable name.
     *
     * WebTemplateDataService::resolve() rebuilds web_template_data from the Property / Contact / Deal
     * pillars ONLY. A value the agent TYPED on Fill & Review for a field with no pillar source
     * (lessee alternate address, occupancy counts, escalation month, fee overrides, …) was written to
     * Document.fields_json but never reached web_template_data — so it rendered BLANK on the agent
     * signing view. The wizard PREVIEW path already applied this overlay; the document-creation path
     * (prepareSigning) did NOT. This is the one shared implementation both prepareSigning paths use so
     * they cannot drift.
     *
     * The key is sanitised to a valid PHP identifier (AT-359b), so a composite field_name such as
     * "property_address+suburb" lands under the same "property_address_suburb" the blade emits. Typed
     * values override the pillar-resolved value for the same field (the agent's explicit input wins);
     * an empty typed value is ignored so it can never clobber a resolved value.
     *
     * @param array    $data                resolved web_template_data
     * @param array    $stepData            the wizard flow step_data
     * @param int|null $onlyPackTemplateId  when set, only fields tagged for this pack template apply
     */
    private function overlayFillReviewValues(array $data, array $stepData, ?int $onlyPackTemplateId = null): array
    {
        $frValues = $stepData['fill_review']['fieldValues'] ?? [];
        if (empty($frValues)) {
            return $data;
        }

        // AT-360c — the AUTHORITATIVE fill-review overlay map. Every value written below is ALSO
        // recorded here, keyed EXACTLY as the document's data-field attribute (base `{var}` and
        // per-recipient `{var}__r{n}`). This map is persisted on web_template_data and re-applied
        // as the LAST word by CanonicalDocumentRenderer::compose() AFTER role-block expansion — so a
        // fill-review edit ALWAYS wins on the rendered document and can never be clobbered by the
        // per-recipient contact re-resolution in RoleBlockExpansionService (which pulls from the
        // Contact model and previously overwrote the agent's edit). Universal: property, details,
        // single-recipient contact AND multi-recipient contact all flow through here.
        $overlay = is_array($data['_fill_review_overlay'] ?? null) ? $data['_fill_review_overlay'] : [];

        foreach (($stepData['fields'] ?? []) as $field) {
            $fieldId   = $field['id'] ?? null;
            $fieldName = $field['field_name'] ?? null;
            if (!$fieldId || !$fieldName) {
                continue;
            }
            // NB: no early "has a value?" guard here — a role-bound field with 2+ recipients has
            // NO base-id value (only "{base}__r{n}" instance values), so an early base-only check
            // would wrongly skip it before the per-recipient loop below. The writes are conditional.
            if ($onlyPackTemplateId !== null) {
                $fTplId = $field['_pack_template_id'] ?? null;
                if ($fTplId !== null && (int) $fTplId !== $onlyPackTemplateId) {
                    continue;
                }
            }

            // Match the blade variable EXACTLY: sanitise the field_name to a valid PHP identifier
            // (same rule as TemplateController::deriveBladeName, AT-359b).
            $var = preg_replace('/[^a-zA-Z0-9_]/', '_', $fieldName);
            if ($var !== '' && is_numeric($var[0])) {
                $var = 'f_' . $var;
            }
            if ($var === '') {
                continue;
            }

            // Single-recipient / non-expanded field — value keyed by the base id.
            if (isset($frValues[$fieldId]) && $frValues[$fieldId] !== '') {
                $data[$var] = $frValues[$fieldId];
                $overlay[$var] = $frValues[$fieldId];
            }

            // AT-360b — PER-RECIPIENT instances. A role-bound field with 2+ recipients is expanded
            // in the wizard to one field per recipient, each keyed "{base_id}__r{n}" (1-based, see
            // expandWizardFieldsPerRecipient). The signing surface renders each instance as
            // "{var}__r{n}" (RoleBlockExpansionService). Without this, an edit to seller-2's field
            // was stored under "{base}__r2" but the overlay only looked up "{base}", so the change
            // never reached web_template_data. Map each instance edit to its own "{var}__r{n}".
            $prefix = $fieldId . '__r';
            foreach ($frValues as $frKey => $frVal) {
                if ($frVal === '' || ! is_string($frKey)) {
                    continue;
                }
                if (str_starts_with($frKey, $prefix)) {
                    $suffix = substr($frKey, strlen($fieldId)); // "__r{n}"
                    if (preg_match('/^__r\d+$/', $suffix)) {
                        $data[$var . $suffix] = $frVal;
                        $overlay[$var . $suffix] = $frVal;
                    }
                }
            }
        }

        $data['_fill_review_overlay'] = $overlay;

        return $data;
    }

    /**
     * Create Document + SignatureTemplate + SignatureRequests from the wizard flow,
     * then redirect to the existing agent signing interface.
     */
    public function prepareSigning(Request $request, $flowId)
    {
        try {
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);
        $flow->load('template');

        $template = $flow->template;

        // Auto-flag template as e-sign capable when used via the wizard
        if (!$template->is_esign) {
            $template->update(['is_esign' => true]);
        }

        // HARD BLOCK: Sale agreements cannot enter the e-sign pipeline (Alienation of Land Act)
        if ($template->isEsignBlocked()) {
            $blockMsg = 'Sale agreements and OTPs must be signed with wet ink per the Alienation of Land Act. E-signing is not permitted for this document type.';
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error' => $blockMsg], 422);
            }
            return redirect()->route('docuperfect.esign.step', [$flowId, 6])
                ->with('error', $blockMsg);
        }

        // This endpoint is exclusively for e-sign delivery mode.
        // Download and wet-ink modes have their own dedicated endpoints
        // (prepareDownload / prepareWetInk) — JS branches before submission.

        $stepData = $flow->step_data ?? [];
        $fields = $stepData['fields'] ?? ($template->fields_json ?? []);

        // Detect candidate practitioner status early — needed by web template rendering AND the transaction
        $candidateService = app(CandidatePractitionerService::class);
        $isCandidateFlow = $candidateService->isCandidate($user);

        // Normalise web template fields
        $renderType = $template->render_type ?? 'pdf';

        // Rebuild from field_mappings if fields are skeletal (no id/field_name)
        if ((empty($fields) || $this->fieldsAreSkeletal($fields)) && $renderType === 'web' && !empty($template->field_mappings)) {
            $fields = $this->buildFieldsFromMappings($template->field_mappings);
            $stepData['fields'] = $fields;
        }

        if ($renderType === 'web') {
            $fields = array_map(fn($f) => $this->normalizeFieldForWizard($f, $renderType), $fields);
        }

        // Auto-fill fields one final time
        $fields = $this->autoFillFields($fields, $stepData);

        // Also merge any fill_review field values
        $frValues = $stepData['fill_review']['fieldValues'] ?? [];
        foreach ($frValues as $fieldId => $value) {
            foreach ($fields as &$field) {
                if (($field['id'] ?? null) == $fieldId && $value !== '') {
                    $field['value'] = $value;
                }
            }
            unset($field);
        }

        // Apply party overrides from fill_review
        $partyOverrides = $stepData['fill_review']['partyOverrides'] ?? [];
        foreach ($partyOverrides as $fieldId => $party) {
            // AT multi-party — the override is the FULL party set (array). Preserve
            // it as editable_by (multi, what the signing view enforces) and derive
            // the single prep-filler for assignedTo, so a seller+agent field stays
            // editable by BOTH at signing.
            $parties = is_array($party)
                ? array_values(array_filter($party, fn ($r) => is_string($r) && $r !== ''))
                : (is_string($party) && $party !== '' ? [$party] : []);
            if (empty($parties)) {
                continue;
            }
            $filler = in_array('agent', $parties, true) ? 'agent' : $parties[0];
            foreach ($fields as &$field) {
                if (($field['id'] ?? null) == $fieldId) {
                    $field['editableBy']  = $parties;
                    $field['editable_by'] = $parties;
                    $field['assignedTo']  = $filler;
                }
            }
            unset($field);
        }

        $recipients = $stepData['recipients']['recipients'] ?? [];
        // Support both old format (array of entries) and new format ({delivery_mode, parties: [...]}).
        // Moved ahead of expandEntityRecipients() (Job 1, Johan/cc1, 2026-08-26)
        // — attachSigningSetupMatch() needs signingSetup while $recipients
        // still carries the ORIGINAL (pre-expansion) names, matching what
        // the frontend actually built signing_setup from.
        $signingSetupRaw = $stepData['signing_setup'] ?? [];
        $signingSetup = isset($signingSetupRaw['parties']) ? $signingSetupRaw['parties'] : $signingSetupRaw;
        $unmatchedSigningSetup = [];
        $recipients = $this->attachSigningSetupMatch($recipients, $signingSetup, $unmatchedSigningSetup);
        // Fault 3, round 5 (Johan, 2026-08-24) — the ACTUAL blocker: this raw
        // array is what the SignatureRequest-creation loop below reads
        // directly, and an entity recipient here is still the COMPANY
        // contact — which cannot sign and routinely has no email. Left
        // unexpanded, the ceremony got exactly one SignatureRequest, bound
        // to the entity itself, with an empty email — "Deferred, details
        // not yet known," undeliverable. Johan's rule, verbatim: "it will
        // always be natural person signing... An entity never signs."
        // expandEntityRecipients() replaces an entity row with its actual
        // SIGNING representative(s) — every non-proxied one, or the sole
        // proxy — each bound to THAT person's own contact_id/name/email, and
        // every one carrying the SAME (correctly all-reps-listed)
        // _party_clause_text so whichever of them opens their link sees the
        // company's full representation named correctly in the document
        // body. This is the generation-time twin of expandRecipientsForMerge()
        // — same expansion, no dedup: signing genuinely needs one row per
        // actual signer, unlike the preview body's single mention.
        //
        // Johan, 2026-08-26 (escalation of cc5's 547863fbb) — signersOnly:
        // true is deliberate and correct HERE (this narrowed $recipients
        // feeds the SignatureRequest-creation loop below: only a proxy, or
        // an unproxied rep, may actually receive/sign). It must NOT be
        // reused for anything a human reads — see $bodyStepData below,
        // which now does its OWN, separate, display-mode expansion instead
        // of inheriting this narrowed array.
        $recipientsPreExpansion = $recipients;
        $recipients = $this->expandEntityRecipients($recipients, $user, signersOnly: true);
        // Flow 480 (Johan, 2026-08-29) — an entity's signing_setup entries
        // name its representatives (step 6's own preview shows expanded
        // names, "Fault 3, round 5" above), so they can never match the
        // pre-expansion array above. Retry any still-unmatched entries now
        // that expansion has happened; this is where entity rows actually
        // resolve.
        $recipients = $this->matchUnmatchedSigningSetupPostExpansion($recipients, $unmatchedSigningSetup);
        // Sort recipients by SA signing convention: Agent → Tenant/Buyer → Landlord/Seller → Witness
        $recipients = $this->sortRecipientsBySigningOrder($recipients);
        // HARD BLOCK (Johan, 2026-08-25): a deceased party never signs
        // (SignatureRequest::isSigningParticipant()) — the document must
        // not be sendable unless someone else is bound to sign in their
        // place. "Certain problem = hard block, not a warning."
        $this->assertDeceasedRecipientsHaveSubstituteSigner($recipients);
        $this->assertSupplierRepresentativesHaveRegistrationNumber($recipients);
        $this->assertChainPartiesHaveIdNumbers($recipients);

        // GENERATED-DOCUMENT BODY (Johan, 2026-08-25 — cc1's finding on
        // 93a10b6a2 — REVISED 2026-08-26, escalation of cc5's 547863fbb):
        // the document actually going out must read the SAME resolved
        // clause the SignatureRequest rows carry — an entity's "Company
        // (Reg: X), herein represented by Rep (ID, Capacity)" is computed
        // by expandEntityRecipients(), which never writes back into
        // $stepData itself.
        //
        // The original version of this comment said "never a second
        // expandEntityRecipients() call, never a second source of truth"
        // and reused the SAME $recipients the SignatureRequest loop above
        // had already narrowed with signersOnly:true. That was the bug: a
        // human reading the document (agent signing screen, final PDF)
        // only ever saw the proxy — every OTHER representative's own
        // address/phone/email vanished. DISPLAY and SIGNING are different
        // questions and must use different expansions of the SAME
        // pre-expansion recipients ($recipientsPreExpansion, captured
        // above, before signersOnly narrowing). This second call is
        // intentional, not drift: one source of truth (the same
        // recipients, same function), two honestly different call-time
        // arguments for two honestly different audiences.
        // $stepData itself is untouched — every other consumer in this
        // function (partiesForSigning, property/details, etc.) still reads
        // the original, unexpanded step_data exactly as before.
        $bodyStepData = $stepData;
        $displayRecipients = $this->expandEntityRecipients($recipientsPreExpansion, $user);
        $bodyStepData['recipients']['recipients'] = $this->dedupeEntityRecipientsForDisplay($displayRecipients);

        $propertyAddress = $stepData['property']['address'] ?? $stepData['property']['title'] ?? '';

        // Build document name — use custom name from wizard if set, else auto-build
        $isPackFlow = !empty($stepData['is_pack_flow']);
        $isPdfPack = !empty($stepData['is_pdf_pack']);
        $docName = $stepData['document_name'] ?? null;
        if (empty($docName)) {
            // Johan, 2026-08-27 (found on the late-estate walkthrough) — a
            // deceased party is not a party to the agreement (Elize's
            // ruling); naming the document, and therefore the "Please sign"
            // email subject, after the deceased ("...— Anine Van der
            // Westhuizen") instead of a living owner or the executor reads
            // as a genuine error on an executed legal document, not a
            // cosmetic one.
            $firstRecipientName = '';
            foreach ($recipients as $r) {
                if (($r['role'] ?? '') !== 'agent' && empty($r['_is_deceased']) && !empty($r['name'])) {
                    $firstRecipientName = $r['name'];
                    break;
                }
            }
            $docName = $isPackFlow ? ($stepData['pack_name'] ?? $template->name)
                     : ($isPdfPack ? ($stepData['pdf_pack_name'] ?? $template->name) : $template->name);
            if ($firstRecipientName) $docName .= ' — ' . $firstRecipientName;
            $docName .= ' — ' . now()->format('Y-m-d');
        }

        $signatureService = app(SignatureService::class);
        $webTemplateDataService = app(WebTemplateDataService::class);

        // Resolve web template data
        $webTemplateData = null;
        if ($isPdfPack && !empty($stepData['template_ids'])) {
            // PDF Pack flow: store template map so signing view can render all pages
            $webTemplateData = [
                'is_pdf_pack'      => true,
                'template_ids'     => $stepData['template_ids'],
                'template_page_map' => $stepData['template_page_map'] ?? [],
                'total_pages'      => $stepData['total_pages'] ?? 0,
                'pdf_pack_id'      => $stepData['pdf_pack_id'] ?? null,
                'pdf_pack_name'    => $stepData['pdf_pack_name'] ?? '',
            ];
        } elseif ($isPackFlow && !empty($stepData['template_ids'])) {
            // Pack flow: merge all templates into one document
            $templateIds = $stepData['template_ids'];
            $mergedHtml = '';
            $packTemplateData = [];
            // AT-360c — accumulate every segment's authoritative fill-review overlay map so the pack
            // Document (whose web_template_data is a fresh 4-key array below) still carries it, and
            // compose() can re-assert fill-review edits on the expanded pack HTML. Keyed by data-field
            // attribute (base + __r{n}); union across segments (per-template scoping already applied).
            $packFillReviewOverlay = [];

            foreach ($templateIds as $idx => $tplId) {
                $tpl = Template::find($tplId);
                if (!$tpl || !$tpl->blade_view) continue;

                $tplData = $webTemplateDataService->resolve($tplId, $bodyStepData, $user);
                // AT-360 — same Fill & Review typed-value overlay as the single-doc path, scoped to
                // this pack template's fields so a value only lands on the document it was typed for.
                $tplData = $this->overlayFillReviewValues($tplData, $stepData, (int) $tplId);
                $packFillReviewOverlay = array_merge($packFillReviewOverlay, $tplData['_fill_review_overlay'] ?? []);
                $segIsSales = false;
                if (!empty($tpl->signing_parties)) {
                    $tplData['signing_parties'] = $tpl->signing_parties;
                    // Parity with the single-doc path (see ~line 1610): the
                    // signature-block component maps owner_party→Seller/Lessor
                    // off document_context. The pack loop never set it, so
                    // EVERY pack template baked "Lessor" even for a sales
                    // pack. Resolve per template (category/template_type
                    // aware) so each segment of a mixed pack is correct.
                    $propSrc = $stepData['property']['_property_source'] ?? null;
                    $segIsSales = $tpl->isSalesDocument($propSrc);
                    $tplData['document_context'] = $segIsSales ? 'sales' : 'rental';
                }

                // Full single-doc parity (mirrors ~1613-1631 & 1651). The
                // signature-block partial keys data-marker-party off
                // signing_parties/document_context, but it needs
                // recipients_by_role + party_names to emit the right number
                // of signer cells with names, and resolveSignatureNames() to
                // resolve residual Blade tokens / signed-at inputs. Without
                // these the pack segments rendered inconsistently with the
                // standalone template (root of the missing-signable bug).
                // Signature-block inputs — SIGNING participants only, same
                // reason as the single-doc path below (flow 330 Finding A).
                $segSigningParticipantRecipients = $this->filterToSigningParticipants($recipients);

                $segPartyNames = [];
                foreach ($segSigningParticipantRecipients as $r) {
                    if (($r['role'] ?? '') === 'agent') continue;
                    $segPartyNames[] = $r['name'] ?? '';
                }
                $segPartyNames[] = $user->name;
                $tplData['party_names'] = $segPartyNames;

                // §20 pack parity (mirror single-doc :1681-1700): key
                // recipients_by_role by the CONCRETE role the signature
                // component looks up (seller/buyer or landlord/tenant per
                // THIS segment's sales context) — NOT the generic
                // owner_party — so the per-recipient signature loop fires
                // in the pack exactly as single-doc (two sellers => seller
                // + seller_2). Raw-role keying made the lookup miss and
                // collapse N sellers into one cell.
                $segOwnerCanon = $segIsSales ? 'seller' : 'landlord';
                $segAcqCanon   = $segIsSales ? 'buyer'  : 'tenant';
                $segOwnerTerms = ['owner_party', 'owner', 'lessor', 'landlord', 'seller'];
                $segAcqTerms   = ['acquiring_party', 'lessee', 'tenant', 'buyer', 'purchaser'];
                $segAgentTerms = ['agent', 'property_practitioner'];
                $segRecipientsByRole = [];
                foreach ($segSigningParticipantRecipients as $r) {
                    $rb = strtolower(preg_replace('/_\d+$/', '', $r['role'] ?? ''));
                    if (in_array($rb, $segOwnerTerms, true)) {
                        $rk = $segOwnerCanon;
                    } elseif (in_array($rb, $segAcqTerms, true)) {
                        $rk = $segAcqCanon;
                    } elseif (in_array($rb, $segAgentTerms, true)) {
                        $rk = 'agent';
                    } else {
                        $rk = $rb !== '' ? $rb : 'other';
                    }
                    $segRecipientsByRole[$rk][] = $r;
                }
                $segRecipientsByRole['agent'] = [['name' => $user->name, 'role' => 'agent', 'email' => $user->email ?? '']];
                $tplData['recipients_by_role'] = $segRecipientsByRole;
                // Candidate flow — every pack segment gets the authorising-practitioner
                // parity block too (single-doc path sets this at ~1904; the pack loop
                // must mirror it or a candidate PACK renders NO authoriser surface and
                // the authoriser's marks land nowhere). Neutral until the claiming
                // practitioner's designation binds at sign time; bound by role-identity.
                $tplData['is_candidate_flow'] = $isCandidateFlow;
                if ($isCandidateFlow) {
                    $tplData['authorising_designation'] = 'Authorising Practitioner';
                    $tplData['authorising_identity']    = 'supervisor';
                }

                $segParties = [['role' => 'agent', 'name' => $user->name, 'display' => $user->name]];
                foreach ($recipients as $r) {
                    $resolvedRole = $r['role'] ?? '';
                    if ($resolvedRole === 'owner_party') {
                        $resolvedRole = $segIsSales ? 'seller' : 'landlord';
                    } elseif ($resolvedRole === 'acquiring_party') {
                        $resolvedRole = $segIsSales ? 'buyer' : 'tenant';
                    }
                    $segParties[] = ['role' => $resolvedRole, 'name' => $r['name'] ?? '', 'display' => $r['name'] ?? ''];
                }

                // Render the template and extract styles + body
                $fullHtml = view($tpl->blade_view, $tplData)->render();
                $bodyHtml = $fullHtml;
                if (preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $m)) {
                    $bodyHtml = trim($m[1]);
                }
                $styles = '';
                if (preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches)) {
                    $styles = implode("\n", $styleMatches[0]);
                }

                // Page-break between templates (not after last)
                $pageBreak = $idx < count($templateIds) - 1
                    ? '<div style="page-break-after:always;"></div>'
                    : '';

                $bodyHtml = $this->resolveSignatureNames($bodyHtml, $tplData, $segParties);
                $bodyHtml = $this->injectFieldValues($bodyHtml, $tplData);

                // BL-2c: a pack template that yields no signable surface even
                // after normalisation produces an unsignable document. Fail
                // loud (surfaced via BL-2a/2b) rather than shipping a doc the
                // signer can open but never complete. Normalising here also
                // stores a guaranteed-signable fragment (idempotent — the
                // signing engine re-normalises at read time anyway).
                $bodyHtml = SignatureSurfaceNormalizer::normalize($bodyHtml);

                // §20 pack parity (mirror single-doc :1763): run the
                // SigningSurfaceResolver PER segment — re-key every marker
                // to a canonical recipient key AND inject a signature
                // surface for any recipient this segment's (possibly
                // stale/hand-authored) blade omitted. Runs BEFORE the
                // no-surface guard so an injected surface counts.
                // normalizePackMarkerParties (after the loop) remains an
                // idempotent whole-merge safety re-key.
                $bodyHtml = app(\App\Services\Docuperfect\SigningSurfaceResolver::class)
                    ->resolve($bodyHtml, $recipients, $user->name, $segIsSales);

                if ($this->countSignableSurfaces($bodyHtml) === 0) {
                    throw new \RuntimeException(
                        "Pack template \"{$tpl->name}\" has no signable signature block "
                        . "(no [data-marker-party][data-marker-type=\"signature\"] surface). "
                        . "This document cannot be e-signed — fix the template before sending."
                    );
                }

                $mergedHtml .= $styles . $bodyHtml . $pageBreak;
                $packTemplateData[$tplId] = $tplData;
            }

            // STEP 1 found the signature-block partial keys data-marker-party
            // off each template's OWN signing_parties/document_context, not
            // the recipients — so merged segments carry inconsistent owner/
            // acquiring synonyms (lessor vs seller). The external scan only
            // makes a surface interactive when its key resolves to the
            // signer's role, so a lessor-keyed segment is skipped for a
            // seller signer. Unify EVERY data-marker-party across the whole
            // merged document to the canonical recipient role keys so every
            // segment is signable by the actual recipients.
            $mergedHtml = $this->normalizePackMarkerParties($mergedHtml, $recipients);

            // Candidate flow — guarantee the authorising practitioner a full-parity
            // signature surface on EVERY signing segment, even imported segments that
            // do NOT render the shared mandate signature-block component (a Mandatory
            // Disclosure / Addendum). Idempotent: component segments already carrying an
            // authoriser surface are skipped. Without this the authoriser's ink binds to
            // nothing on such segments and drops from the final document.
            if ($isCandidateFlow) {
                $mergedHtml = app(\App\Services\Docuperfect\CandidateAuthoriserSurfaceInjector::class)
                    ->inject($mergedHtml, 'supervisor', 'Authorising Practitioner');
            }

            // §20 — stamp each segment's .corex-document-wrapper with an
            // instance-stable docKey so the client keys disclosure rows
            // intrinsically per document (disclosure_<docKey>_<n>). One
            // unique token per wrapper => two of the same template in a
            // pack get distinct, stable keys; never DOM-position-derived.
            $mergedHtml = $this->stampDisclosureDocKeys($mergedHtml);

            // PER-DOCUMENT other-conditions: scope each segment's OTHER_CONDITIONS
            // marker to its wrapper docKey so a condition added to one pack document
            // never bleeds into another (independent frames + initials per segment).
            $mergedHtml = $this->scopePackOtherConditionsMarkers($mergedHtml);

            // Fill & Review strike-outs — bake the agent's creation-time strikes into the FINAL pack body via
            // the SAME universal engine the single-doc path uses (:replayBodyStrikes at the single-doc merge
            // below), so a strike authored on a pack renders identically on the signed pack document — struck
            // <del> + optional <ins> + the full-width per-party initial row. Runs on the fully-assembled merge
            // (after marker scoping / docKeys / authoriser injection) so a strike can land in ANY segment.
            $mergedHtml = $this->replayBodyStrikes(
                $mergedHtml,
                $stepData,
                $this->buildFillReviewSigningParties($stepData, $template, $user),
            );

            $webTemplateData = [
                'merged_html'         => $mergedHtml,
                'template_ids'        => $templateIds,
                'pack_id'             => $stepData['pack_id'] ?? null,
                'pack_template_data'  => $packTemplateData,
                '_fill_review_overlay' => $packFillReviewOverlay,
            ];
        } elseif ($template->render_type === 'web' && $template->blade_view) {
            $webTemplateData = $webTemplateDataService->resolve($template->id, $bodyStepData, $user);

            // AT-360 — overlay the agent's Fill & Review typed values (pillar-less fields the
            // agent hand-typed) so they reach the signed document, not just Document.fields_json.
            $webTemplateData = $this->overlayFillReviewValues($webTemplateData, $stepData);

            // Build parties list for initials/signature processing
            // Resolve generic roles (owner_party, acquiring_party) to concrete roles
            // based on property source so downstream code uses seller/landlord/buyer/tenant
            $propSource = $stepData['property']['_property_source'] ?? null;
            $isSalesContext = ($propSource === 'properties')
                || (!$propSource && str_contains(strtolower($template->name ?? ''), 'sell'));
            $partiesForSigning = [];
            $partiesForSigning[] = [
                'role' => 'agent',
                'name' => $user->name,
                'display' => $user->name,
            ];
            foreach ($stepData['recipients']['recipients'] ?? [] as $r) {
                $resolvedRole = $r['role'];
                if ($resolvedRole === 'owner_party') {
                    $resolvedRole = $isSalesContext ? 'seller' : 'landlord';
                } elseif ($resolvedRole === 'acquiring_party') {
                    $resolvedRole = $isSalesContext ? 'buyer' : 'tenant';
                }
                $partiesForSigning[] = [
                    'role' => $resolvedRole,
                    'name' => $r['name'],
                    'display' => $r['name'],
                ];
            }

            // Render full HTML for single web template (same as pack flow)
            $viewData = $webTemplateData;
            if (!empty($template->signing_parties)) {
                $viewData['signing_parties'] = $template->signing_parties;
                $propSrc = $stepData['property']['_property_source'] ?? null;
                $viewData['document_context'] = $template->isSalesDocument($propSrc) ? 'sales' : 'rental';
            }

            // Signature-block inputs — SIGNING participants only (Johan,
            // 2026-08-26, flow 330 Finding A). filterToSigningParticipants()
            // excludes a deceased/proxy-collapsed row here specifically —
            // they still name in full elsewhere (the "I/We ..." clause,
            // the domicilium block), just never get a blank, unexecutable
            // signature line of their own.
            $signingParticipantRecipients = $this->filterToSigningParticipants($recipients);

            // Build party_names for signature-block component (non-agent recipients first, agent last)
            $partyNames = [];
            foreach ($signingParticipantRecipients as $r) {
                if (($r['role'] ?? '') === 'agent') continue;
                $partyNames[] = $r['name'] ?? '';
            }
            $partyNames[] = $user->name;
            $viewData['party_names'] = $partyNames;

            // Build recipients_by_role for the signature-line / signature-block
            // component loop (inline + terminal sigs). The component looks up
            // the CONCRETE role it derives from signing_parties+document_context
            // (seller/buyer or landlord/tenant) — NOT the generic owner_party.
            // Key by that concrete role FIRST so the EXISTING per-recipient
            // loop fires: two sellers => recipients_by_role['seller'] has 2 =>
            // loop emits seller + seller_2 (keyed identically to
            // signature_requests.party_role). Without this the lookup misses
            // and the loop collapses N sellers into one cell. Sales vs rental
            // follows the SAME classifier that sets document_context above.
            $isSalesForKeying = $template->isSalesDocument($propSource);
            $ownerCanon = $isSalesForKeying ? 'seller' : 'landlord';
            $acqCanon   = $isSalesForKeying ? 'buyer'  : 'tenant';
            $ownerTerms = ['owner_party', 'owner', 'lessor', 'landlord', 'seller'];
            $acqTerms   = ['acquiring_party', 'lessee', 'tenant', 'buyer', 'purchaser'];
            $agentTerms = ['agent', 'property_practitioner'];
            $recipientsByRole = [];
            foreach ($signingParticipantRecipients as $r) {
                $base = strtolower(preg_replace('/_\d+$/', '', $r['role'] ?? ''));
                if (in_array($base, $ownerTerms, true)) {
                    $key = $ownerCanon;
                } elseif (in_array($base, $acqTerms, true)) {
                    $key = $acqCanon;
                } elseif (in_array($base, $agentTerms, true)) {
                    $key = 'agent';
                } else {
                    $key = $base !== '' ? $base : 'other';
                }
                $recipientsByRole[$key][] = $r;
            }
            // Always include agent from authenticated user — recipients step doesn't have an agent entry
            $recipientsByRole['agent'] = [['name' => $user->name, 'role' => 'agent', 'email' => $user->email ?? '']];
            $viewData['recipients_by_role'] = $recipientsByRole;
            $viewData['is_candidate_flow'] = $isCandidateFlow;
            if ($isCandidateFlow) {
                // The authorising practitioner is drawn from the shared authorisation
                // queue at sign time, so no specific person/designation is known at
                // document creation — a neutral role label renders until the claiming
                // practitioner's DESIGNATION + name are stamped when their ink bakes
                // (CanonicalInkComposer). The authoriser binds by ROLE-IDENTITY, never
                // this label, so a placeholder here can never mis-bind their marks.
                $viewData['authorising_designation'] = 'Authorising Practitioner';
                $viewData['authorising_identity']    = 'supervisor';
            }
            $fullHtml = view($template->blade_view, $viewData)->render();

            // Extract body HTML (between <body> and </body>)
            preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $bodyMatch);
            $bodyHtml = $bodyMatch[1] ?? $fullHtml;

            // Extract styles
            $styles = '';
            if (preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches)) {
                $styles = implode("\n", $styleMatches[0]);
            }

            // Process the HTML: resolve signature names and field values
            // Page breaks and initials are now handled client-side (a4-page-styles.blade.php)
            // via paginateDocument() which measures actual rendered element heights.
            $bodyHtml = $this->resolveSignatureNames($bodyHtml, $webTemplateData, $partiesForSigning);
            $bodyHtml = $this->injectFieldValues($bodyHtml, $webTemplateData);

            // Inject additional clauses from wizard step 5 (unified text field)
            $otherConditionsText = trim($stepData['fill_review']['other_conditions_text'] ?? '');
            if (empty($otherConditionsText)) {
                // Fallback: build from legacy selectedClauses array
                $selectedClauses = $stepData['fill_review']['clauses'] ?? [];
                if (!empty($selectedClauses)) {
                    $otherConditionsText = implode("\n\n", array_map(fn($c) => $c['text'] ?? $c['content'] ?? '', $selectedClauses));
                }
            }
            // Step 2 (Johan) — skip the legacy static "Additional Conditions"
            // injection when the template carries an ~~~~OTHER_CONDITIONS~~~~
            // marker: the InsertableBlockRenderer expands that marker into the
            // structured condition rows (with per-party initials), so injecting
            // here as well would render the conditions TWICE. No-marker (legacy)
            // templates keep the injection as their only way to show conditions.
            if (!empty($otherConditionsText) && ! str_contains($bodyHtml, 'OTHER_CONDITIONS')) {
                // Split by double-newline for individual clause blocks
                $clauseBlocks = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $otherConditionsText))));
                $clauseHtml = '<div class="corex-additional-clauses" style="margin-top:16pt;">';
                $clauseHtml .= '<h3 style="font-weight:bold;margin-top:12pt;margin-bottom:8pt;">Additional Conditions</h3>';
                foreach ($clauseBlocks as $idx => $block) {
                    $num = $idx + 1;
                    $clauseHtml .= '<div class="clause-block" data-clause-index="' . $idx . '" style="margin:6pt 0;">';
                    $clauseHtml .= '<p><strong>' . $num . '.</strong> ' . e($block) . '</p>';
                    $clauseHtml .= '</div>';
                }
                $clauseHtml .= '</div>';

                // Insert BEFORE the signature section so additional conditions
                // appear in the document body, not after signatures.
                $bodyHtml = $this->insertBeforeSignatureSection($bodyHtml, $clauseHtml);
            }

            // §20 — single signing-surface resolver (APPROVED). The single-doc
            // path renders the on-disk CDS blade, which is hand-authored and
            // can be stale relative to the persisted field config (#119: blade
            // SIG 1 is agent-only though field_mappings holds [Agent,Buyer,
            // Seller]). Re-keys every marker to a canonical recipient key AND
            // injects a signature surface for any recipient the stale blade
            // omitted — recipient-driven, so non-recipient ticks are inert.
            // Standalone generalisation of normalizePackMarkerParties (pack-
            // only + re-key only). The pack path is intentionally left on its
            // existing normaliser for now (retired in a follow-up).
            $bodyHtml = app(\App\Services\Docuperfect\SigningSurfaceResolver::class)
                ->resolve($bodyHtml, $recipients, $user->name, $isSalesContext);

            // Candidate flow — same authoriser-surface guarantee as the pack path:
            // an imported single document that omits the mandate signature-block
            // component still yields exactly one full-parity authoriser surface
            // (idempotent where the component already rendered one).
            if ($isCandidateFlow) {
                $bodyHtml = app(\App\Services\Docuperfect\CandidateAuthoriserSurfaceInjector::class)
                    ->inject($bodyHtml, 'supervisor', 'Authorising Practitioner');
            }

            // §20 — stamp the document's .corex-document-wrapper with an
            // instance-stable docKey (same scheme as the pack path) so a
            // single doc keys disclosure rows identically to when it is a
            // pack segment — full position-independence.
            $bodyHtml = $this->stampDisclosureDocKeys($bodyHtml);

            // Store as merged_html so SignatureController uses it directly
            $webTemplateData['merged_html'] = $styles . $bodyHtml;

            // Fill & Review strike-outs — bake the agent's creation-time strikes into the FINAL document body
            // so they persist onto the signed document (identical engine to the preview + the sign-screen amend).
            $webTemplateData['merged_html'] = $this->replayBodyStrikes(
                $webTemplateData['merged_html'],
                $stepData,
                $partiesForSigning,
            );

            // Store field_mappings with editable_by so the signing view knows
            // which fields each party role can edit (CDS templates only)
            if (!empty($template->field_mappings)) {
                $fm = $template->field_mappings;
                // AT multi-party — overlay each field's per-send editable_by (the
                // fill&review "who this field belongs to" checkboxes) onto the
                // template's field_mappings, so the multi party set chosen for THIS
                // send governs signing-time edit rights. Non-overridden fields carry
                // the template's own editable_by unchanged (idempotent).
                if (is_array($fm)) {
                    foreach ($fields as $wf) {
                        $tagId = $wf['id'] ?? null;
                        $eb = $wf['editable_by'] ?? $wf['editableBy'] ?? null;
                        if ($tagId !== null && isset($fm[$tagId]) && is_array($fm[$tagId])
                            && is_array($eb) && !empty($eb)) {
                            $fm[$tagId]['editable_by'] = array_values($eb);
                        }
                    }
                }
                $webTemplateData['field_mappings'] = $fm;
                $webTemplateData['template_type'] = $template->template_type;
            }
        }

        // CONVERGENCE (Johan 2026-08-06) — persist the Fill & Review creation-time strikes onto the
        // document so compose() (the ONE serve path every surface routes through) can replay them AFTER
        // role-block expansion. The send-time bake into merged_html above STAYS (it handles within-clause
        // strikes), but it runs on the UN-expanded merged_html: a selection that spans a role-block — the
        // whole Seller domicilium block, whose per-recipient instances (Seller 1/Seller 2) only exist
        // after expandWithLooping — cannot locate there, so the strike silently drops at send time and the
        // Seller block renders un-struck on the signing + recipient views. Replaying inside compose(),
        // after expansion, locates against the exact expanded structure the agent authored on, so a
        // whole-Seller-block strike survives to every served surface. Empty when no strikes were authored.
        $webTemplateData['body_strikes'] = $stepData['fill_review']['body_strikes'] ?? [];

        $packInstanceId = ($isPackFlow || $isPdfPack) ? (int) round(microtime(true) * 1000) : null;

        // Resolve document_type: map template's DocumentType to a RentalDocumentType slug
        $resolvedDocType = $template->template_type; // fallback
        if ($template->document_type_id) {
            $template->loadMissing('documentType');
            $dtName = $template->documentType->name ?? '';
            // Map unified DocumentType labels to RentalDocumentType slugs
            $dtNameMap = [
                'Mandate' => 'mandate', 'Mandates' => 'mandate',
                'Offer to Purchase' => 'other', 'OTPs' => 'other',
                'Addendum' => 'addendum', 'Addendums' => 'addendum',
                'Condition Report' => 'inspection_report', 'Condition Reports' => 'inspection_report',
                'FICA' => 'disclosure',
                'Rental Agreement' => 'lease_agreement', 'Rental Agreements' => 'lease_agreement',
                'Other' => 'other',
            ];
            $resolvedDocType = $dtNameMap[$dtName] ?? strtolower(str_replace(' ', '_', $dtName));
        }

        // Resolve property_id: use flow->property_id (pillar) or step_data rental_property_id
        $resolvedPropertyId = $flow->property_id;
        $propSource = $stepData['property']['_property_source'] ?? 'properties';
        if (!$resolvedPropertyId && $propSource === 'rental_properties' && !empty($stepData['property']['property_id'])) {
            $resolvedPropertyId = $stepData['property']['property_id'];
        }

        $result = DB::transaction(function () use ($user, $flow, $template, $fields, $recipients, $signingSetup, $docName, $propertyAddress, $signatureService, $webTemplateData, $packInstanceId, $resolvedDocType, $resolvedPropertyId, $candidateService, $isCandidateFlow, $stepData) {
            // 1. Create Document
            $document = Document::create([
                'name'             => $docName,
                'template_id'      => $template->id,
                'fields_json'      => $fields,
                // AT-267 / AUDIT 2026-07-26 (F3) — an assistant's document files under the AGENT.
                // Document::scopeVisibleTo() resolves an agent's 'own' as [agent] only, so an
                // assistant-owned OTP/mandate was invisible to the practitioner it was prepared
                // for. ownershipUserId() returns $user->id for everyone who is not an assistant.
                // multi-agent addendum §6.1 — honours an explicit "Acting for" choice.
                'owner_id'         => $user->ownershipUserId(request()->integer('acting_for_user_id') ?: null),
                'branch_id'        => $user->effectiveBranchId(),
                'property_address' => $propertyAddress,
                'property_id'      => $resolvedPropertyId,
                'document_type'    => $resolvedDocType,
                'web_template_data' => $webTemplateData,
                'pack_instance_id' => $packInstanceId,
            ]);

            // 2. Create SignatureTemplate
            $roleAliases = [
                'landlord' => 'landlord', 'tenant' => 'tenant',
                'buyer' => 'buyer', 'seller' => 'seller',
                'agent' => 'agent', 'witness' => 'witness',
                'spouse' => 'spouse', 'other' => 'other',
            ];

            // Agent is always first party (signing_order=1)
            $parties = [
                ['role' => 'agent', 'role_label' => 'agent', 'name' => $user->name, 'email' => $user->email, 'id_number' => ''],
            ];
            $signingOrder = ['agent'];

            // Use signing_setup order if available (respects drag-reorder from step 6).
            // Job 1 (Johan/cc1, 2026-08-26) — this used to re-match each
            // signing_setup entry to a recipient by role+NAME, which silently
            // dropped any entry whose name changed under expansion (a
            // represented party's row now carries the REPRESENTATIVE's name,
            // never the original party's). attachSigningSetupMatch() already
            // tagged every recipient (including expanded representative rows)
            // with _matched_signing_setup_index BEFORE expansion, while names
            // still agreed — so this reorder now matches on that stable index
            // instead. A signing_setup entry that still finds nothing here
            // means expansion itself dropped every representative for that
            // party (should already be impossible — assertDeceasedRecipients
            // HaveSubstituteSigner() and the _entity_needs_representative
            // pass-through both run first) — loud failure, never a silently
            // missing signer.
            $orderedRecipients = $recipients;
            if (!empty($signingSetup) && !empty($signingSetup[0]['signing_order'] ?? null)) {
                $orderedRecipients = [];
                $usedRecipientKeys = [];
                foreach ($signingSetup as $ssIndex => $ss) {
                    if (($ss['role'] ?? '') === 'agent') continue;
                    $matchedAny = false;
                    foreach ($recipients as $rKey => $r) {
                        if (($r['_matched_signing_setup_index'] ?? null) === $ssIndex) {
                            $orderedRecipients[] = $r;
                            $usedRecipientKeys[$rKey] = true;
                            $matchedAny = true;
                        }
                    }
                    if (! $matchedAny) {
                        $name = trim((string) ($ss['name'] ?? '')) ?: 'This party';
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'recipients' => "The signing order lists {$name} but that party did not survive representative expansion. Check for a data-entry mismatch before re-sending.",
                        ]);
                    }
                }
                // A recipient never tied to a signing_setup entry (e.g. added
                // after step 6 was configured) is still sent — appended in
                // original order, never silently dropped.
                foreach ($recipients as $rKey => $r) {
                    if (empty($usedRecipientKeys[$rKey])) {
                        $orderedRecipients[] = $r;
                    }
                }
            }

            // Recipient Loop Engine B1 — each person is a SEPARATE signer in
            // the chain. Two sellers = two parties. party_role stays clean
            // (just 'seller'); role_index distinguishes 1 vs 2. The legacy
            // suffixed party_key shape ('seller_2') is kept on $recipientPartyKeys
            // for downstream callers that still expect it — but SignatureService
            // splits the suffix on insert so the persisted column is always clean.
            $roleCounts = [];
            $recipientPartyKeys = [];
            foreach ($orderedRecipients as $i => $r) {
                $baseRole = $roleAliases[$r['role'] ?? 'other'] ?? ($r['role'] ?? 'other');
                if ($baseRole === 'agent') continue;

                $roleCounts[$baseRole] = ($roleCounts[$baseRole] ?? 0) + 1;
                $roleIndex = $roleCounts[$baseRole];
                // Legacy suffixed key — kept so 8 callers downstream still get
                // a unique party_key string. SignatureService::createSigningRequest
                // splits the suffix back into (party_role, role_index) at insert.
                $partyKey = $roleIndex === 1 ? $baseRole : $baseRole . '_' . $roleIndex;

                $recipientPartyKeys[$i] = $partyKey;
                $parties[] = [
                    'role'       => $partyKey,
                    'role_label' => $baseRole,
                    'role_index' => $roleIndex,  // B1 — explicit index for downstream consumers
                    'name'       => $r['name'] ?? '',
                    'email'      => $r['email'] ?? '',
                    'id_number'  => $r['id_number'] ?? '',
                ];
                $signingOrder[] = $partyKey;
            }

            // ── Candidate Practitioner Flow: auto-inject authorisation steps ──
            // Shared queue: no specific supervisor assigned. ANY eligible authoriser
            // in the branch can claim and authorise. Notifications sent to all.
            // ($candidateService and $isCandidateFlow defined before web template rendering block)

            if ($isCandidateFlow) {
                // Verify at least one authoriser exists (throws if none)
                $candidateService->getEligibleAuthorisers($user);

                // Insert authorisation step as signing_order 2 (right after agent, before external parties)
                $parties[] = [
                    'role'       => 'supervisor',
                    'role_label' => 'supervisor',
                    'name'       => 'Authorised Practitioner',
                    'email'      => '',
                    'id_number'  => '',
                ];

                // Rebuild signing order: agent → supervisor → external parties.
                //
                // No supervisor_final (confirmed model, 2026-08-03): the authoriser co-signs ONCE at
                // the initial 'supervisor' review, right after the candidate signs. After all external
                // parties sign, the CANDIDATE's own final approval completes the document — with no
                // edits, nothing changed from what the authoriser already co-signed, so the final
                // version IS what the authoriser signed. (approveAndAdvance falls through to
                // completeDocument when no supervisor_final request exists.)
                $externalParties = array_filter($signingOrder, fn($r) => $r !== 'agent');
                $signingOrder = array_merge(['agent', 'supervisor'], array_values($externalParties));
            }

            $documentHash = hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $sigTemplate = SignatureTemplate::create([
                'document_id'         => $document->id,
                'document_hash'       => $documentHash,
                'status'              => SignatureTemplate::STATUS_DRAFT,
                'parties_json'        => $parties,
                'signing_order_json'  => $signingOrder,
                // HD-6 (§4) — a MANDATE signs `sellers → agent`: joint sellers are one group, so the
                // agent is not asked to authorise the gap between two people signing the same document
                // for the same reason. Scoped to mandates ON PURPOSE — every other ceremony (leases in
                // particular) gets NULL and keeps today's checkpoint-after-every-party behaviour
                // unchanged, because changing how a live lease flow checkpoints is not a side effect
                // this ticket is entitled to.
                'group_order_json'    => $this->groupOrderForCeremony($template),
                'created_by'          => $user->id,
                'is_candidate_flow'   => $isCandidateFlow,
                'supervisor_user_id'  => null,
                'sections_json'       => $template->sections,
                'other_conditions_text' => trim($stepData['fill_review']['other_conditions_text'] ?? '') ?: null,
            ]);

            // Phase 1B.5 / Step 2 (Johan) — persist the agent's other-conditions
            // into structured document_conditions rows so the signing surface
            // (which reads those rows, not other_conditions_text) renders them,
            // one row per condition (each initialled per-party). Prefer the
            // discrete FRAMES the wizard now submits (one frame = one row, with
            // clause-library provenance); fall back to the legacy text bridge
            // only when no frames are present (older step data / other flows).
            try {
                $frames = $stepData['fill_review']['other_condition_frames'] ?? [];
                if (is_array($frames) && $frames !== []) {
                    app(\App\Services\Docuperfect\LegacyOtherConditionsBridge::class)
                        ->syncFramesToStructuredRows($sigTemplate, $frames);
                } else {
                    app(\App\Services\Docuperfect\LegacyOtherConditionsBridge::class)
                        ->syncToStructuredRows($sigTemplate);
                }
            } catch (\Throwable $e) {
                \Log::warning('LegacyOtherConditionsBridge sync failed (non-fatal)', [
                    'sig_template_id' => $sigTemplate->id,
                    'error'           => $e->getMessage(),
                ]);
            }

            // 3. Create SignatureRequests — agent first (signing_order=1), then supervisor (if candidate), then recipients
            $signatureService->createSigningRequest(
                $sigTemplate,
                'agent',
                $user->name,
                $user->email,
                null,
                null,
                $user
            );

            // Candidate flow: create supervisor request (signing_order=2, right after agent)
            // Shared queue — no specific person assigned. Any eligible authoriser can claim.
            if ($isCandidateFlow) {
                $signatureService->createSigningRequest(
                    $sigTemplate,
                    'supervisor',
                    'Authorised Practitioner',
                    '',
                    null,
                    null,
                    $user
                );
            }

            $chainBindings = [];

            foreach ($orderedRecipients as $i => $r) {
                $baseRole = $roleAliases[$r['role'] ?? 'other'] ?? ($r['role'] ?? 'other');
                if ($baseRole === 'agent') continue;
                $partyKey = $recipientPartyKeys[$i] ?? $baseRole;

                // Job 1 (Johan/cc1, 2026-08-26) — read the index attachSigningSetupMatch()
                // tagged pre-expansion, never re-match by role+name against the
                // post-expansion array (silently loses skipEmail/email-override/
                // FICA for any expanded representative row — same shape as the
                // reorder bug above).
                $matchedSetup = isset($r['_matched_signing_setup_index'])
                    ? ($signingSetup[$r['_matched_signing_setup_index']] ?? null)
                    : null;
                $skipEmail = !empty($matchedSetup['skipEmail'] ?? false);
                $email = $matchedSetup['email'] ?? $r['email'] ?? '';
                $signingAction = $matchedSetup['action'] ?? 'send_after';
                $ficaRequired = !empty($matchedSetup['fica_required'] ?? false);
                $contactId = !empty($r['_contact_id']) ? (int) $r['_contact_id'] : null;

                // cc2, 2026-08-25 (Flow 409, part 2 — "make the right document
                // easy") — expandEntityRecipients() froze _party_clause_text
                // onto $r back when the recipient array was FIRST built. Time
                // passes between then and here (FICA lookups, signing_setup
                // matching, reordering) during which the represented party's
                // real representative can change on the underlying record —
                // exactly the gap Flow 409 fell through. See
                // resolveFreshPartyClauseText()'s own docblock.
                //
                // Gated on _party_clause_text already being set, not just
                // _entity_contact_id — a rep-less entity carries
                // _entity_contact_id too (_entity_needs_representative=true,
                // no clause, handled by its own existing prompt) and must
                // NOT be pulled into this recompute; there is nothing to
                // recompute FROM when nobody represents them yet.
                if (!empty($r['_entity_contact_id']) && !empty($r['_party_clause_text'])) {
                    $r['_party_clause_text'] = $this->resolveFreshPartyClauseText(
                        (int) $r['_entity_contact_id'],
                        $contactId,
                        (string) ($r['name'] ?? ''),
                        // Johan, 2026-08-26 — this recompute must carry the
                        // SAME per-document proxy/order choice expandEntity
                        // Recipients() already resolved, or it silently falls
                        // back to the permanent pivot's own state and undoes
                        // both features right before the clause is frozen.
                        isset($r['_entity_proxy_contact_id']) ? (int) $r['_entity_proxy_contact_id'] : null,
                        $r['_entity_rep_order'] ?? null,
                    );
                }

                // Auto-create FICA submission if required and contact has none approved
                $ficaSubId = null;
                if ($ficaRequired && $contactId) {
                    $hasApprovedFica = FicaSubmission::where('contact_id', $contactId)
                        ->whereIn('status', ['submitted', 'under_review', 'agent_approved', 'approved'])
                        ->exists();
                    if (! $hasApprovedFica) {
                        $existingDraft = FicaSubmission::where('contact_id', $contactId)
                            ->whereIn('status', ['draft', 'submitted', 'under_review', 'agent_approved'])
                            ->orderByDesc('created_at')
                            ->orderByDesc('id')
                            ->first();
                        if ($existingDraft) {
                            // Reused FICA submissions (seeder/wet-ink/legacy)
                            // may carry a NULL token AND a foreign
                            // requested_by / NULL agency_id — which keeps the
                            // completed FICA OUT of this agent's compliance
                            // pipeline (non-CO index filters requested_by;
                            // AgencyScope is strict on NULL agency_id).
                            // Backfill the token AND reassign ownership/scope
                            // to the e-sign agent (parity with
                            // FicaController::store) so the submitted FICA
                            // lands in this agent's "Awaiting Agent Review".
                            if (empty($existingDraft->token)) {
                                $existingDraft->token = Str::random(64);
                                $existingDraft->token_expires_at = now()->addDays(14);
                            }
                            $existingDraft->requested_by = $user->id;
                            if (empty($existingDraft->agency_id)) {
                                $existingDraft->agency_id = $user->effectiveAgencyId()
                                    ?? Contact::find($contactId)?->agency_id;
                            }
                            if (empty($existingDraft->branch_id)) {
                                $existingDraft->branch_id = $user->effectiveBranchId();
                            }
                            $existingDraft->save();
                            $ficaSubId = $existingDraft->id;
                        } else {
                            // Parity with FicaController::store:135-139 —
                            // resolve agency from the agent, fall back to the
                            // contact's agency, and NEVER create a
                            // scope-orphaned (NULL agency_id) submission the
                            // pipeline query can't see: log loudly and skip.
                            $ficaAgencyId = $user->effectiveAgencyId()
                                ?? Contact::find($contactId)?->agency_id;
                            if (! $ficaAgencyId) {
                                \Illuminate\Support\Facades\Log::warning(
                                    'E-sign FICA not created — unresolved agency_id (scope-orphan prevented)',
                                    ['contact_id' => $contactId, 'user_id' => $user->id]
                                );
                            } else {
                                $ficaSub = FicaSubmission::create([
                                    'contact_id'       => $contactId,
                                    'agency_id'        => $ficaAgencyId,
                                    'branch_id'        => $user->effectiveBranchId(),
                                    'requested_by'     => $user->id,
                                    'token'            => Str::random(64),
                                    'token_expires_at' => now()->addDays(14),
                                    'status'           => 'draft',
                                ]);
                                $ficaSubId = $ficaSub->id;
                            }
                        }
                    }
                }

                $sigReq = $signatureService->createSigningRequest(
                    $sigTemplate,
                    $partyKey,
                    $r['name'] ?? '',
                    $skipEmail ? '' : $email,
                    $r['id_number'] ?? null,
                    null,
                    $user,
                    $ficaRequired,
                    $contactId,
                    $ficaSubId,
                    signerCaption: $r['_signature_caption'] ?? null,
                    partyClauseText: $r['_party_clause_text'] ?? null,
                    isDeceased: (bool) ($r['_is_deceased'] ?? false),
                    isProxy: (bool) ($r['_is_proxy'] ?? false),
                    recipientLocalKey: $r['_recipient_local_key'] ?? null,
                    // cc2, 2026-08-25 (cc4's row 1506) — the identity the
                    // guard checks against (Contact ids via
                    // contact_representatives), never the clause text.
                    // Only set for a row that actually represents someone;
                    // null for the ordinary plain party.
                    representedContactId: isset($r['_entity_contact_id']) ? (int) $r['_entity_contact_id'] : null,
                    // Johan, 2026-08-28 — the recipient card's phone/address
                    // are always editable whether or not a Contact was ever
                    // selected via search; whatever the agent typed must
                    // reach the document, not just the wizard's own screen.
                    signerPhone: $r['cell'] ?? null,
                    signerAddress: $r['address'] ?? null,
                );

                $this->stampSupplierFirmIfAny($sigReq, $r);

                // "Replace this party" (Johan, 2026-08-24) — a recipient whose party is
                // being replaced (e.g. deceased, represented by a chain) carries a
                // recipient template + slot bindings from the wizard. Resolved in a
                // SEPARATE pass, after every recipient in this send has been created,
                // because a chain can bind to ANOTHER recipient in this same batch
                // (Piet's executor slot binds to Koos's recipient_local_key) — that
                // key only exists once Koos's own createSigningRequest() call above
                // has run, which may be later in this same loop.
                if (! empty($r['_recipient_template_id']) && ! empty($r['_slot_bindings'])) {
                    $chainBindings[] = [
                        'signature_request_id' => $sigReq->id,
                        'recipient_template_id' => (int) $r['_recipient_template_id'],
                        'slot_bindings' => $r['_slot_bindings'],
                    ];
                }

                // Mark as deferred if "sign_later" was selected and party has no details
                if ($signingAction === 'sign_later' && (empty($r['name']) || empty($email) || $skipEmail)) {
                    $sigReq->update(['status' => \App\Models\Docuperfect\SignatureRequest::STATUS_DEFERRED]);
                }
            }

            // "Replace this party" — resolve every chain binding now that every
            // recipient in this send has a recipient_local_key (including ones a
            // chain might point AT, which may have been created later in the loop
            // above than the recipient whose party is being replaced). Shared with
            // prepareWetInk() — see resolveChainBindings().
            $this->resolveChainBindings($chainBindings, $user->id);

            // No supervisor_final request (confirmed model, 2026-08-03) — the authoriser co-signs ONCE
            // at the initial 'supervisor' review; the candidate's final approval completes the doc.
            // NOTE for the future edit path (wet-ink re-sign): if the document is later EDITED, the
            // authoriser must re-sign wherever the candidate signs and recipients re-sign — reopen the
            // 'supervisor' checkpoint at that point; do not resurrect a distinct supervisor_final step.

            // 4a. Set required flags on sign/initial fields based on contact count per role
            $fields = $this->setSignatureRequiredFlags($fields, $recipients);
            $document->update(['fields_json' => $fields]);

            // For web templates (CDS), the setup view JS auto-detects markers from
            // data-marker-party DOM attributes (signatures) and data-marker-type="initial"
            // (initials). Server-side marker creation is skipped — it gets cleared by JS anyway.
            // For PDF templates, create markers server-side as they rely on stored coordinates.
            $isWebRenderType = ($template->render_type ?? 'pdf') === 'web';

            if (!$isWebRenderType) {
                // 4b. Convert template signature zones to markers
                $markerCount = $signatureService->convertZonesToMarkers($sigTemplate);

                // Fallback: create markers from fields_json sign/initial fields
                if ($markerCount === 0) {
                    $markerCount = $signatureService->convertFieldsJsonToMarkers($sigTemplate, $fields);
                }

                // Final fallback: create one default signature marker per party
                if ($markerCount === 0) {
                    $signatureService->createDefaultMarkers($sigTemplate);
                }

                // 4c. Expand role-based markers to individual party markers.
                // Marker creation uses generic roles (e.g. "seller") but we need
                // separate markers for each person (e.g. "seller", "seller_2").
                $this->expandMarkersToIndividualParties($sigTemplate, $signingOrder);

                // 4d. Auto-place initial markers on every page except the last
                // for every signing party (per V2 spec).
                $this->autoPlaceInitialMarkers($sigTemplate, $signingOrder, $template);
            }

            // 4e. Create signature zones for PDF templates only.
            // Web/CDS templates define marker positions via data-marker-party
            // attributes in their rendered HTML. The setup screen JS reads
            // those exact DOM positions and creates zones from them — no
            // server-side estimation needed. This works for ANY template
            // because positions come from the template author's layout.
            if (!$isWebRenderType) {
                $signatureService->createZonesFromParties(
                    $sigTemplate,
                    $parties,
                    max(1, count($webTemplateData['template_ids'] ?? [1])),
                    $isCandidateFlow
                );
            }

            // 5. Keep template in ready status so agent can place markers and sign in-app.
            // sendForSigning() fires later via the send-confirmation page after agent completes signing.
            $sigTemplate->update(['status' => SignatureTemplate::STATUS_READY]);

            // Mark agent request as pending so the signing view knows they are the active signer
            $agentReq = $sigTemplate->requests()->where('party_role', 'agent')->first();
            if ($agentReq) {
                $agentReq->update([
                    'status' => \App\Models\Docuperfect\SignatureRequest::STATUS_PENDING,
                    'sent_at' => now(),
                ]);
            }

            // Johan, 2026-08-28 — "it's one document that flows like a printed
            // page... why would you rebuild it every single time it moves to
            // the next screen." Every real SignatureRequest row now exists —
            // compose the canonical body ONCE, right here, and store it.
            // CanonicalDocumentRenderer::forDisplay() now trusts ANY stored
            // canonical_html (see that method) and never recomputes it — so
            // this is the ONE and ONLY time the parties/clause/Domicilium get
            // derived for this document. Every later surface (agent signing
            // screen, recipient ceremony, PDF) reads this exact artifact and
            // only ever ADDS to it (ink, ceremony marks) — it never rebuilds
            // it. This is what makes "correct at Fill & Review, wrong at Sign
            // & Send" structurally impossible: there is no second derivation
            // left to disagree with the first.
            app(\App\Services\Docuperfect\CanonicalDocumentRenderer::class)->composeAndStore($sigTemplate);

            // 6. Link document to flow
            $flowStepData = $flow->step_data ?? [];
            $flowStepData['document_id'] = $document->id;
            $flowStepData['signature_template_id'] = $sigTemplate->id;
            $flow->step_data = $flowStepData;
            $flow->current_step = 6; // Step 6 is the final wizard step — do not advance past it
            $flow->save();

            return $document;
        });

        // Store wizard context in session so signComplete redirects back to wizard
        session(['esign_wizard_flow_id' => $flow->id]);

        // All template types go to setup first — agent reviews markers and can add ad-hoc ones.
        // Web templates show embedded signature elements; PDF templates show overlay markers.
        $setupUrl = route('docuperfect.signatures.setup', ['document' => $result->id]);
        // The wizard JS submits via fetch (Accept: application/json) so it can
        // surface failure in the UI instead of a blind native navigation
        // (audit BL-2b). Direct browser hits still get the redirect.
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'redirect' => $setupUrl]);
        }
        return redirect()->to($setupUrl);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PREPARE_SIGNING_FAILED', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            $message = 'Failed to prepare signing: ' . $e->getMessage();
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error' => $message], 422);
            }
            return redirect()->route('docuperfect.esign.create')
                ->withErrors(['error' => $message]);
        }
    }

    /**
     * Expand role-based markers to individual party markers.
     * E.g. a "seller" signature marker becomes separate markers for "seller" and "seller_2".
     */
    private function expandMarkersToIndividualParties(SignatureTemplate $sigTemplate, array $signingOrder): void
    {
        // Build base role → [unique_key_1, unique_key_2, ...]
        $roleToKeys = [];
        foreach ($signingOrder as $key) {
            $baseRole = preg_replace('/_\d+$/', '', $key);
            $roleToKeys[$baseRole][] = $key;
        }

        $markers = $sigTemplate->markers()->get();
        foreach ($markers as $marker) {
            $assignedParty = $marker->assigned_party;
            $keys = $roleToKeys[$assignedParty] ?? [$assignedParty];

            if (count($keys) <= 1) continue;

            // Multiple people for this role: update first marker, duplicate for rest.
            // Compress y-offset so duplicates stay within page bounds (max 90%).
            $numCopies = count($keys);
            $yStep = min(6, (90 - $marker->y_position) / max(1, $numCopies - 1));
            $yStep = max(2, $yStep); // at least 2% apart

            $marker->update(['assigned_party' => $keys[0]]);
            for ($j = 1; $j < $numCopies; $j++) {
                $newMarker = $marker->replicate();
                $newMarker->assigned_party = $keys[$j];
                $newMarker->y_position = min(90, round($marker->y_position + ($j * $yStep), 2));
                $newMarker->sort_order = $marker->sort_order + ($j * 100);
                $newMarker->save();
            }
        }
    }

    /**
     * Auto-place initial markers on every page except the last for every signing party.
     * Per V2 spec, each page gets initials from each signer at bottom-right.
     */
    private function autoPlaceInitialMarkers(SignatureTemplate $sigTemplate, array $signingOrder, Template $template): void
    {
        // Estimate page count from the web template's CDS data or default to 1
        $pageCount = 1;
        $cdsData = $template->cds_json ?? [];
        if (!empty($cdsData['sections'])) {
            // Estimate pages from content lines (~45 lines per A4 page)
            $lineCount = 0;
            foreach ($cdsData['sections'] as $section) {
                $type = $section['type'] ?? '';
                if ($type === 'signature_section') { $lineCount += 15; }
                elseif ($type === 'table') { $lineCount += max(3, count($section['rows'] ?? []) + 2); }
                elseif ($type === 'page_initials') { $lineCount += 2; }
                else {
                    $text = '';
                    foreach ($section['content'] ?? [] as $item) { $text .= $item['value'] ?? ''; }
                    $lineCount += max(1, (int) ceil(mb_strlen($text) / 80));
                }
            }
            $pageCount = max(1, (int) ceil($lineCount / 45));
        }
        // Also check template page_count if set
        if ($template->page_count && $template->page_count > $pageCount) {
            $pageCount = $template->page_count;
        }

        // Don't place initials if only 1 page (signature page IS the only page)
        if ($pageCount <= 1) return;

        // Place initials on pages 1 through (pageCount - 1) for every party.
        // Start at 85% y, max 90% — compress interval if many parties.
        $sortBase = 10000;
        $partyCount = count($signingOrder);
        $startY = 85;
        $maxY = 90;
        $interval = $partyCount > 1 ? min(3, ($maxY - $startY) / ($partyCount - 1)) : 0;

        foreach ($signingOrder as $partyIdx => $partyKey) {
            $yPos = round($startY + ($partyIdx * $interval), 2);
            for ($page = 1; $page < $pageCount; $page++) {
                SignatureMarker::create([
                    'signature_template_id' => $sigTemplate->id,
                    'page_number'           => $page,
                    'x_position'            => 85,
                    'y_position'            => $yPos,
                    'width'                 => 12,
                    'height'                => 3,
                    'type'                  => SignatureMarker::TYPE_INITIAL,
                    'assigned_party'        => $partyKey,
                    'label'                 => ucfirst(preg_replace('/_\d+$/', '', $partyKey)) . ' Initial — Pg ' . $page,
                    'sort_order'            => $sortBase + ($page * 100) + $partyIdx,
                    'required'              => true,
                ]);
            }
        }
    }

    /**
     * Success page after agent completes signing via wizard flow.
     */
    public function signingComplete(Request $request, $flowId)
    {
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);
        $flow->load('template');

        $stepData = $flow->step_data ?? [];
        $documentId = $stepData['document_id'] ?? null;
        $document = $documentId ? Document::find($documentId) : null;
        $sigTemplate = $document ? $document->signatureTemplate : null;

        // Flow 330 (Johan, 2026-08-26) — this used to walk the RAW wizard
        // recipients array and guess "the first non-agent one with an
        // email" as who got notified. That is a completely disconnected
        // read from what actually happened — a deceased party still has an
        // email on file and was still first in the array, so the page told
        // the agent "Sent to <the deceased party>" while nobody was ever
        // emailed. Query the ACTUAL SignatureRequest that transitioned to
        // PENDING — the status sendSigningRequest() sets ONLY on a genuine
        // dispatch (SignatureService.php ~995-998) — so this line can never
        // name someone who wasn't actually sent something. Null when
        // nobody currently is (fully complete, held for agent review, or
        // every remaining recipient turned out non-required) — the blade's
        // existing @if($nextRecipient) guard correctly shows nothing rather
        // than a false claim.
        $nextRecipientRequest = $sigTemplate
            ? $sigTemplate->requests()
                ->where('status', SignatureRequest::STATUS_PENDING)
                ->whereNotIn('party_role', ['agent', 'supervisor', 'supervisor_final'])
                ->orderBy('signing_order', 'asc')
                ->first()
            : null;

        $nextRecipient = $nextRecipientRequest ? [
            'name'  => $nextRecipientRequest->signer_name,
            'role'  => $nextRecipientRequest->party_role,
            'email' => $nextRecipientRequest->signer_email,
        ] : null;

        // Mark flow as completed
        $flow->status = 'completed';
        $flow->save();

        // Get signing requests for dev testing links
        $signingRequests = $sigTemplate
            ? $sigTemplate->requests()->orderBy('signing_order')->get()
            : collect();

        return view('docuperfect.esign.signing-complete', [
            'flow'            => $flow,
            'document'        => $document,
            'sigTemplate'     => $sigTemplate,
            'nextRecipient'   => $nextRecipient,
            'template'        => $flow->template,
            'signingRequests' => $signingRequests,
        ]);
    }

    /**
     * Auto-fill template fields from wizard step data.
     *
     * Uses source_type/source_column/source_contact_type from
     * docuperfect_named_fields to resolve each field's value.
     */

    /**
     * HD-6 (§4) — the locked group order for this ceremony, or null to leave it ungrouped.
     *
     * Only a MANDATE is grouped today (`sellers → agent`). Everything else returns null and keeps the
     * checkpoint-after-every-party behaviour it has now — silently changing how a live lease ceremony
     * checkpoints is not something this ticket is entitled to do as a side effect.
     *
     * Matched on the classified document_type slug FIRST (DocumentTypeClassifier owns that answer, and
     * a classified mandate stays a mandate whatever it is renamed), with the template's own type
     * string as the fallback for templates that predate classification.
     */
    private function groupOrderForCeremony(?Template $template): ?array
    {
        if (! $template) {
            return null;
        }

        $slug = strtolower(trim((string) ($template->documentType?->slug ?? $template->template_type ?? '')));

        return $slug === 'mandate' ? SignatureTemplate::GROUP_ORDER_MANDATE : null;
    }

    /**
     * Fault 3, round 2 (Johan, 2026-08-24) — the ONE recipient-preparation
     * pipeline (auto-populate from the property + expand any entity into its
     * representative(s)) every body/preview render goes through. Extracted
     * from showStep() so templatePages() — the live-refresh endpoint the
     * wizard calls after a field edit — computes the body from the SAME
     * prepared data as the page's own initial load, not raw, un-prepared
     * step_data. Before this, showStep() alone ran this pipeline: the first
     * page load showed a company's correct "entity, herein represented by
     * rep" clause, but any subsequent live refresh (templatePages()) fed
     * WebTemplateDataService the UNEXPANDED entity row instead — dropping
     * the representative and, for a flow with no saved recipients yet,
     * resolving to nothing at all. Exactly the "two systems will drift"
     * trap Johan named — this time one level up, in what feeds the party-
     * name resolution rather than the resolution itself.
     *
     * Returns $stepData with 'recipients' set to the prepared array (mirrors
     * the auto-populate/expand result showStep() always wrote back).
     */
    private function prepareRecipientsForMerge(array $stepData, ?Template $template, $user, int $step): array
    {
        // Recipients from step data — handle double-nested structure.
        $recipientsData = $stepData['recipients'] ?? [];
        $recipients = isset($recipientsData['recipients']) && is_array($recipientsData['recipients'])
            ? $recipientsData['recipients']
            : (is_array($recipientsData) && !empty($recipientsData) && isset($recipientsData[0]) ? $recipientsData : []);

        // Auto-populate linked contacts from property if no non-agent recipients exist
        $hasNonAgent = collect($recipients)->contains(fn($r) => ($r['role'] ?? '') !== 'agent');
        if (!$hasNonAgent && $step >= 3 && $template) {
            $propertyId = $stepData['property']['property_id'] ?? null;
            $propertySource = $stepData['property']['_property_source'] ?? null;

            // Load contacts from properties table (rental_properties has no contacts relationship)
            if ($propertyId && $propertySource === 'properties') {
                $prop = Property::with(['contacts' => fn($q) => $q->withPivot('role')])->find($propertyId);
                if ($prop) {
                    // Determine correct fallback role from template signing_parties, then document context
                    $signingParties = $template->signing_parties ?? [];
                    $defaultOwnerRole = collect($signingParties)->first(fn($r) => $r !== 'agent' && $r !== 'creator')
                        ?? ($template->isSalesDocument($propertySource) ? 'seller' : 'landlord');

                    // Build allowed esign_roles from template's signing_parties
                    $allowedEsignRoles = $this->buildAllowedEsignRoles($signingParties);

                    // Fault 3 (Johan, 2026-08-24) — a company AND its own director are
                    // routinely BOTH linked to the same property (contact_property) for
                    // CRM lookup purposes; that is correct data, not a duplicate. But the
                    // document has ONE seller (the company) — the director only signs FOR
                    // it, surfaced below by expandEntityRecipients(). Auto-populating the
                    // director a SECOND time as their own independent recipient produced
                    // two "seller" rows for the same legal signer: the entity's clause
                    // ("1502 BEAUMONT PROP CC, herein represented by HA Pretorius") AND a
                    // bare "HA Pretorius" row, both merging into the body as if they were
                    // two separate owners ("HA Pretorius ... and HA Pretorius ..."). Skip
                    // any linked contact who represents an entity ALSO linked here.
                    $entityContactIds = $prop->contacts->filter(fn ($c) => $c->isEntity())->pluck('id')->all();

                    // Agent is always first recipient (added by JS), so just add linked contacts
                    foreach ($prop->contacts as $contact) {
                        if (!$contact->isEntity() && !empty($entityContactIds)
                            && $contact->representedEntities()->whereIn('contacts.id', $entityContactIds)->exists()) {
                            continue; // Already covered via their entity's own expansion below.
                        }

                        $recipientRole = $this->resolveLinkedContactRole($contact, $allowedEsignRoles, $defaultOwnerRole);
                        if ($recipientRole === null) {
                            continue; // Not a party this document needs.
                        }

                        $recipients[] = [
                            'order'       => count($recipients) + 1,
                            'role'        => $recipientRole,
                            'name'        => $contact->first_name . ' ' . $contact->last_name,
                            'first_name'  => $contact->first_name ?? '',
                            'last_name'   => $contact->last_name ?? '',
                            'id_number'   => $contact->id_number ?? '',
                            'email'       => $contact->email ?? '',
                            'cell'        => $contact->phone ?? '',
                            'address'     => $contact->address ?? '',
                            '_contact_id' => $contact->id,
                            // Johan, 2026-08-26 — auto-populated-from-property rows
                            // never carried this (only a fresh search-pick via
                            // selectContact() did), so the "deceased" checkbox's
                            // :disabled="r._is_entity" read an undefined value on
                            // first load — an Alpine quirk where an undefined
                            // boolean-attribute binding disables rather than
                            // leaving enabled, greying the tick out for a company
                            // AND a natural person alike whenever neither had ever
                            // been re-picked via search.
                            '_is_entity'  => $contact->isEntity(),
                            'bank_name'           => $contact->bank_name ?? '',
                            'bank_account_name'   => $contact->bank_account_name ?? '',
                            'bank_account_number' => $contact->bank_account_number ?? '',
                            'bank_branch_name'    => $contact->bank_branch_name ?? '',
                            'bank_branch_code'    => $contact->bank_branch_code ?? '',
                            'bank_account_type'   => $contact->bank_account_type ?? '',
                        ];
                    }
                }
            }
            // BL-3: rental/letting docs select a rental_properties row, which
            // has NO contact_property pivot and NO contacts relationship —
            // only the denormalised landlord_name/landlord_email/landlord_phone
            // scalars (no tenant data exists on that table). Before this branch
            // the block above was gated on source==='properties', so letting
            // e-sign started with zero recipients. Synthesise the landlord
            // recipient from those scalars, gated by the template's allowed
            // esign roles, in the same shape as the sales branch. Tenant cannot
            // be auto-resolved from rental_properties — manual-add covers it.
            elseif ($propertyId && $propertySource === 'rental_properties') {
                $rentalProp = RentalProperty::find($propertyId);
                if ($rentalProp && (!empty($rentalProp->landlord_name) || !empty($rentalProp->landlord_email))) {
                    $signingParties = $template->signing_parties ?? [];
                    $defaultOwnerRole = collect($signingParties)->first(fn($r) => $r !== 'agent' && $r !== 'creator')
                        ?? ($template->isSalesDocument($propertySource) ? 'seller' : 'landlord');
                    $allowedEsignRoles = $this->buildAllowedEsignRoles($signingParties);

                    // The landlord maps to esign_role 'lessor'. Skip only if the
                    // template explicitly restricts roles and excludes lessor.
                    $landlordAllowed = empty($allowedEsignRoles) || in_array('lessor', $allowedEsignRoles, true);
                    if ($landlordAllowed) {
                        $name = trim($rentalProp->landlord_name ?? '');
                        $nameParts = $name !== '' ? preg_split('/\s+/', $name, 2) : ['', ''];
                        $recipients[] = [
                            'order'       => count($recipients) + 1,
                            'role'        => $defaultOwnerRole,
                            'name'        => $name,
                            'first_name'  => $nameParts[0] ?? '',
                            'last_name'   => $nameParts[1] ?? '',
                            'id_number'   => '',
                            'email'       => $rentalProp->landlord_email ?? '',
                            'cell'        => $rentalProp->landlord_phone ?? '',
                            'address'     => $rentalProp->full_address ?? '',
                            '_contact_id' => null,
                        ];
                    }
                }
            }
        }

        // Deliberately NO entity expansion here — see expandRecipientsForMerge().
        // This method's output feeds the recipients STEP'S OWN editable form
        // (the 'recipients' view var showStep() seeds the Alpine list from,
        // and what gets saved back on "Next"). Fault 3, round 3 (Johan,
        // 2026-08-24): expansion used to happen HERE and get written back
        // into $stepData['recipients'] — which this same array fed straight
        // into that editable form. The agent's screen (and the client-side
        // "Signs via its representative" preview) still looked right, but
        // the underlying row had silently become the REPRESENTATIVE's own
        // identity (first_name/last_name/_contact_id all HA Pretorius, not
        // the company) with only the display `name` field still holding the
        // composed clause. The agent clicked Next, that row got saved
        // AS THE RECIPIENT, and the company was permanently gone from the
        // data — flow 279's exact failure. Expansion is a presentation-layer
        // operation for document-body merge purposes ONLY; it must never
        // reach anything that becomes what gets edited or saved as "the
        // recipient."
        if (!empty($recipients)) {
            $stepData['recipients'] = ['recipients' => $recipients];
        }

        return $stepData;
    }

    /**
     * ENTITY RECIPIENT EXPANSION (Johan 2026-08-15, re-scoped 2026-08-24) —
     * replace any entity/company recipient with its proxy-aware signing
     * representative(s), each rendered "{entity}, herein represented by
     * {rep} ({capacity})". Takes an ALREADY-prepared $stepData (see
     * prepareRecipientsForMerge()) and returns a NEW copy with expansion
     * applied — the caller's own $stepData is untouched, so a form fed from
     * it never sees the expanded/substituted identities. Use this ONLY for
     * document-body/preview merge calls (autoFillFields, WebTemplateDataService
     * ::resolve(), field-group/per-recipient field expansion) — never for
     * anything that becomes editable state or gets saved back.
     */
    private function expandRecipientsForMerge(array $stepData, $user): array
    {
        $recipients = $stepData['recipients']['recipients'] ?? [];
        $expanded = $this->expandEntityRecipients($recipients, $user);
        $deduped = $this->dedupeEntityRecipientsForDisplay($expanded);

        if (!empty($deduped)) {
            $stepData['recipients'] = ['recipients' => $deduped];
        }

        return $stepData;
    }

    /**
     * Fault 3, round 5 (Johan, 2026-08-24) — expandEntityRecipients()
     * correctly produces one row per SIGNER (needed for the signature-
     * request loop: every non-proxied representative signs, so every one
     * needs their own row there). But for DISPLAY purposes — the document
     * body, the wizard preview — the entity is ONE party: three signer
     * rows for the same company, each carrying the SAME (correctly
     * all-reps-listed) _party_clause_text, still read as three separate
     * "sellers" to resolveFieldGroupValue()'s "and"-join, tripling the
     * identical clause. Collapse every row sharing the same
     * _entity_contact_id down to its first occurrence.
     *
     * Extracted (Johan, 2026-08-25 — cc1's finding on 93a10b6a2) so
     * prepareSigning()/prepareWetInk() can feed the document-generation
     * body render the SAME expansion + dedup expandRecipientsForMerge()
     * already computes for the wizard preview — never a second
     * expandEntityRecipients() call for the same request, never two dedup
     * implementations that could drift. The un-collapsed $expanded array
     * itself is what the real signing-request loop must still use — this
     * only ever narrows a DISPLAY copy.
     */
    private function dedupeEntityRecipientsForDisplay(array $expanded): array
    {
        $seenEntities = [];
        $deduped = [];
        foreach ($expanded as $r) {
            $entityId = $r['_entity_contact_id'] ?? null;
            if ($entityId !== null) {
                if (isset($seenEntities[$entityId])) {
                    continue;
                }
                $seenEntities[$entityId] = true;
            }
            $deduped[] = $r;
        }

        return $deduped;
    }

    /**
     * Flow 330, Finding A (Johan, 2026-08-26) — a signature block is a
     * place TO SIGN, not a display of the party (that's the "I/We ..."
     * clause and the domicilium block, which correctly keep naming a
     * deceased/proxy-collapsed party in full — untouched here). This array
     * feeds party_names/recipients_by_role, which the signature-block
     * component (signature-block.blade.php) reads directly to decide how
     * many "Thus done and signed by the Seller..." lines to render. Left
     * unfiltered, a deceased party who is correctly NOT_REQUIRED got her
     * own blank, unexecutable signature block anyway — a mandate that
     * looks incomplete to a conveyancer, and an open invitation to fill it
     * in by hand on the wet-ink path.
     *
     * Mirrors SignatureRequest::isSigningParticipant()/nonSigningReason()'s
     * exact two rules — deceased is absolute; a proxy elsewhere in the SAME
     * role group collapses everyone else in it — against the WIZARD ARRAY's
     * own _is_deceased/_is_proxy flags rather than calling that method
     * directly: this runs BEFORE the SignatureRequest rows exist (it builds
     * the very HTML those rows are later created from), so there is nothing
     * to call it ON yet. If that rule ever changes, this must change with
     * it — same two rules, same order, just read from array data instead of
     * DB columns because of when in the pipeline this runs.
     */
    private function filterToSigningParticipants(array $recipients): array
    {
        $proxyRoles = [];
        foreach ($recipients as $r) {
            if (!empty($r['_is_proxy'])) {
                $proxyRoles[strtolower($r['role'] ?? '')] = true;
            }
        }

        return array_values(array_filter($recipients, function (array $r) use ($proxyRoles) {
            if (!empty($r['_is_deceased'])) {
                return false;
            }
            $role = strtolower($r['role'] ?? '');
            if (!empty($proxyRoles[$role]) && empty($r['_is_proxy'])) {
                return false; // collapsed by a proxy elsewhere in this same role group
            }

            return true;
        }));
    }

    /**
     * The role a property-linked contact is offered to sign as — from the PROPERTY-LINK role.
     *
     * §2.1 doctrine (esign-ceremony-v3): a party's role is the role they hold ON THIS
     * PROPERTY, not the type they carry globally. A contact typed "Seller" because they sold
     * a different house last year, but linked to THIS property as a buyer, is a BUYER here.
     * The global contact type answers "what is this person, generally?" — a question the
     * document never asks. Only the property link knows who they are to THIS document.
     *
     * The global type therefore survives as a FALLBACK for one case only: a link that predates
     * the role being mandatory and so carries no role at all. It is never the primary source.
     *
     * Returns the recipient role label (seller|buyer|lessor|lessee, matching the ContactType
     * canon the rest of the wizard speaks), or NULL to skip the contact entirely — they are a
     * lead, or they hold no role this template signs.
     */
    /**
     * True when a recipient's own Contact needs representative expansion
     * before it can be treated as a plain pass-through party.
     *
     * Flow 330 (Johan, 2026-08-26) — cc2's finding: expandEntityRecipients()
     * gated purely on isEntity(), so a NATURAL-PERSON party who is
     * represented (Piet: a natural person represented by an entity, itself
     * represented by a natural person — Koos) never reached expansion at
     * all. WHO ACTUALLY RECEIVED THE SIGNING REQUEST stayed wrong even
     * after the document-body text was fixed on the display side
     * (RoleBlockExpansionService::resolveDocumentRepresentatives(),
     * 2026-08-25) — Piet's OWN (possibly absent/wrong) contact details
     * would have been used to create the SignatureRequest, never Koos's.
     *
     * isEntity() is kept, not replaced — an entity with ZERO representatives
     * linked must still enter expansion so the existing
     * _entity_needs_representative prompt still fires (unchanged, pre-
     * existing behaviour).
     *
     * Flow 509 (Johan, 2026-08-26) — representatives()->exists() ALONE is no
     * longer sufficient for a natural person. ensureChainRelationshipsExist()
     * (cffa56c49, this morning) made "Replace this party" write a real,
     * PERMANENT contact_representatives row every time an agent picks a
     * representative — correctly, that record is the guard's backing
     * evidence and must survive. But this gate then read that same
     * permanent row as "this person is represented, always" — so Anine,
     * picked with two DIFFERENT executors on two EARLIER, separate,
     * legitimate documents, came up "herein represented by [both]" on a
     * THIRD, brand-new document where Johan had ticked nothing at all.
     * $isDocumentRepresented is per-recipient, from THIS document's own
     * step_data (_is_deceased) — the same per-document-flag mechanism cc3
     * just used for the proxy pick (_entity_proxy_contact_id, dce9ec0c2),
     * not a second invention. A natural person's stored relationships stay
     * exactly where they are; they just stop being sufficient on their own
     * to decide what THIS document prints.
     */
    private function partyNeedsRepresentativeExpansion(Contact $contact, bool $isDocumentRepresented = false): bool
    {
        if ($contact->isEntity()) {
            return true;
        }

        return $isDocumentRepresented && $contact->representatives()->exists();
    }

    /**
     * cc2, 2026-08-25 (Flow 409, part 2 — "make the right document easy,
     * not just refuse the wrong one") — expandEntityRecipients() freezes
     * _party_clause_text early, once, when the recipient array is first
     * built. A represented party's real representative can genuinely
     * change on the underlying record between that moment and the moment
     * this row is actually turned into a SignatureRequest (real minutes
     * apart on a real document — Anna's own POA link moved between two
     * wizard steps on Flow 409). Recomposing here, live, right before the
     * value is frozen for good, closes that window: same entity + same
     * pivot state always produces the same clause, so an agent who hasn't
     * touched anything gets back the identical string (a true no-op) —
     * only a genuine change since expansion produces a different, CORRECT
     * result. This is why the free-text path can now compose correctly
     * instead of merely being refused: a legitimate late correction to who
     * represents someone is picked up automatically, not rejected.
     *
     * $contactId not currently among the entity's representatives at all
     * is a different case — there is no clause this can legitimately
     * compose (naming someone who was never actually linked as a
     * representative), so it refuses with the specific names involved
     * rather than silently keeping the stale text SignatureRequest::
     * assertClauseNamesSigner() would go on to reject anyway.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    private function resolveFreshPartyClauseText(int $entityContactId, ?int $contactId, string $recipientName, ?int $overrideProxyRepId = null, ?array $orderContactIds = null): ?string
    {
        $entityContact = Contact::withoutGlobalScopes()->find($entityContactId);
        if (! $entityContact) {
            return null; // dangling entity reference — nothing to compose from; unchanged behaviour.
        }

        if ($contactId === null) {
            return null; // no signer resolved yet (e.g. deferred) — nothing to verify against.
        }

        // cc2, 2026-08-26 — reuses SignatureRequest::assertSignerIsCurrentRepresentative()
        // directly rather than re-walking the chain here. Two membership
        // checks against the same relationship is the identical two-
        // implementations shape as the clause/signer split this whole task
        // exists to close, one level down — a one-hop check here would have
        // refused Anna's genuinely correct multi-hop chain (Ben → Chris)
        // exactly the way cc4 caught it doing.
        try {
            \App\Models\Docuperfect\SignatureRequest::assertSignerIsCurrentRepresentative($contactId, $entityContactId);
        } catch (\App\Exceptions\PartyClauseSignerMismatchException $e) {
            // entity_name is only populated for an actual entity — a
            // represented NATURAL PERSON (Anna's own case) has none, so
            // fall back to full_name the same way composeEntityPartyText()
            // itself already does.
            $partyName = (string) ($entityContact->entity_name ?: $entityContact->full_name);
            throw \Illuminate\Validation\ValidationException::withMessages([
                'recipients' => "\"{$recipientName}\" is no longer linked as a representative of "
                    . "\"{$partyName}\" — re-link them (or pick the correct "
                    . 'representative) on the recipient screen before sending.',
            ]);
        }

        return app(\App\Services\Docuperfect\RoleBlockExpansionService::class)->composeEntityPartyText($entityContact, true, $overrideProxyRepId, $orderContactIds);
    }

    /**
     * ESIGN RECIPIENT BUILDER (Johan 2026-08-15) — expand any ENTITY/company
     * recipient into its proxy-aware signing representative(s). Consumes the
     * shared foundation Contact::signingRepresentatives() (proxy → 1 signer;
     * else all reps) and the agency phrasing template (EsignRecipientPreset).
     *
     * Each produced signer:
     *  - name  = "{entity}, herein represented by {rep} ({capacity})" (party-name
     *    field + signature attribution render the representation directly);
     *  - first/last/id_number/email/cell/bank = the REP (natural person signs and
     *    is emailed the signing link);
     *  - _entity_contact_id / _entity_name / _capacity / _signature_caption carried
     *    for downstream render.
     * A rep-less entity is kept as-is with _entity_needs_representative=true so the
     * recipient screen can prompt "link a representative first" — it cannot sign.
     * A recipient with no representative link at all (the ordinary case) passes
     * through unchanged (order renumbered) — see partyNeedsRepresentativeExpansion().
     * A represented NATURAL PERSON (Johan, 2026-08-26 — the "Piet" case) now takes
     * this SAME branch: Contact::signingRepresentatives() recurses through any
     * entity intermediary down to the real natural-person signer(s), so the
     * produced rows are unchanged in shape whether the original party was an
     * entity or a represented natural person.
     */
    /**
     * Job 1 (Johan/cc1, 2026-08-26) — signing_setup (step 6's drag-reorder,
     * skip-email, FICA-required, custom-email overrides) is built by the
     * FRONTEND against the ORIGINAL recipient names, before this controller
     * ever runs expandEntityRecipients(). Re-matching a signing_setup entry
     * to a recipient by role+name AFTER expansion silently fails for any
     * entity/represented-party row, because expansion replaces that row's
     * name with the REPRESENTATIVE's name — the frontend never sees the
     * substitution. The failed match used to just drop the slot with no
     * error: the represented party's signing request was never created,
     * nobody was emailed, and the endpoint still returned 200 "ok":true.
     *
     * Matching happens HERE, before expansion, while names still agree with
     * what the frontend built signing_setup from. The resulting index
     * survives expansion because expandEntityRecipients() copies
     * _matched_signing_setup_index onto every representative row it
     * produces from a matched original recipient — so every downstream
     * lookup (reorder, skip-email, FICA, email override) reads the index
     * instead of re-matching a name expansion already changed.
     *
     * A signing_setup entry that cannot be matched to any recipient is a
     * genuine data mismatch, not something to paper over — it throws
     * instead of silently vanishing a party from the send. Same shape as
     * flow 330's silent chain-advance stop: a legal signing chain must
     * never drop a participant without telling anyone.
     */
    /**
     * cc2, 2026-08-26 (Johan's real case, flow 480 — a company represented
     * by three parties) — "Job 1" (a07e0927f) assumed signing_setup is
     * ALWAYS built from the pre-expansion (original party) name. True for
     * the natural-person-chain case it reproduced; false for an entity:
     * step 6's own preview (ESignWizardController.php ~L616-630, "Fault 3,
     * round 5", 2026-08-24 — OLDER than Job 1) already shows the EXPANDED
     * representative names for an entity, so signing_setup for a company
     * genuinely contains each representative's own name — which can never
     * match the entity's single pre-expansion row, for ANY entity, always,
     * not only when it has multiple representatives. Confirmed directly
     * against Johan's real flow 480 data before writing this.
     *
     * Rather than re-deciding match timing globally (bigger, riskier
     * change under the clock), this stays additive: try the pre-expansion
     * match first (unchanged — still the fix for the natural-person-chain
     * case Job 1 targeted), and hand back whatever DIDN'T match instead of
     * throwing immediately. The caller retries those specific entries
     * against the expanded array (matchUnmatchedSigningSetupPostExpansion(),
     * right after expandEntityRecipients()) before giving up for real.
     * Anything that matched here before still matches here, unchanged.
     *
     * @param array<int, array> $unmatched OUT param — signing_setup entries this pass could not place.
     */
    private function attachSigningSetupMatch(array $recipients, array $signingSetup, array &$unmatched = []): array
    {
        $unmatched = [];
        if (empty($signingSetup)) {
            return $recipients;
        }

        $consumed = array_fill(0, count($recipients), false);

        foreach ($signingSetup as $ssIndex => $ss) {
            if (($ss['role'] ?? '') === 'agent') continue;

            $matched = false;
            foreach ($recipients as $i => &$r) {
                if ($consumed[$i]) continue;
                if (($r['role'] ?? '') === ($ss['role'] ?? '') && ($r['name'] ?? '') === ($ss['name'] ?? '')) {
                    $r['_matched_signing_setup_index'] = $ssIndex;
                    $consumed[$i] = true;
                    $matched = true;
                    break;
                }
            }
            unset($r);

            if (! $matched) {
                $unmatched[$ssIndex] = $ss;
            }
        }

        return $recipients;
    }

    /**
     * The fallback pass — see attachSigningSetupMatch()'s docblock. Matches
     * whatever didn't resolve pre-expansion against the NOW-expanded array
     * (an entity's real representative rows exist here), by the same
     * role+name rule. Still throws, still names the specific party, if a
     * signing_setup entry genuinely matches nothing anywhere — a real
     * data-entry mismatch must never vanish a party silently.
     *
     * @param array<int, array> $unmatched from attachSigningSetupMatch()'s out param, keyed by original signing_setup index
     */
    private function matchUnmatchedSigningSetupPostExpansion(array $recipients, array $unmatched): array
    {
        if (empty($unmatched)) {
            return $recipients;
        }

        $consumed = array_fill(0, count($recipients), false);
        foreach ($recipients as $i => $r) {
            // Already matched pre-expansion — index 0 is a valid match, so
            // check the value, not truthiness. expandEntityRecipients()
            // pre-populates every row (including unmatched ones) with this
            // key set to null, so array_key_exists() alone wrongly marks
            // every expanded row as already consumed (flow 480: threw here).
            $consumed[$i] = array_key_exists('_matched_signing_setup_index', $r)
                && $r['_matched_signing_setup_index'] !== null;
        }

        foreach ($unmatched as $ssIndex => $ss) {
            $matched = false;
            foreach ($recipients as $i => &$r) {
                if ($consumed[$i]) continue;
                if (($r['role'] ?? '') === ($ss['role'] ?? '') && ($r['name'] ?? '') === ($ss['name'] ?? '')) {
                    $r['_matched_signing_setup_index'] = $ssIndex;
                    $consumed[$i] = true;
                    $matched = true;
                    break;
                }
            }
            unset($r);

            if (! $matched) {
                $name = trim((string) ($ss['name'] ?? '')) ?: 'This party';
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'recipients' => "The signing order lists {$name} but no matching recipient was found on this document. Check for a data-entry mismatch between the recipient list and the signing order (step 6) before re-sending.",
                ]);
            }
        }

        return $recipients;
    }

    /**
     * Johan, 2026-08-26 (cc5's proxy fix, 547863fbb, escalated) — DISPLAY and
     * SIGNING are different questions with different answers, decided ONCE,
     * here, not per call site: a human reading this document — Fill &
     * Review, the agent signing screen, the generated document, the PDF —
     * must see every representative's own name/address/phone/email,
     * regardless of proxy; only the code that decides who receives a
     * signing request and who actually signs narrows to the proxy (or the
     * sole non-proxied rep). $forDisplay defaulted to false and was wired
     * into exactly one call site (the wizard preview) — every OTHER
     * consumer, including the ones that bake the address/phone/email
     * sections into the document that actually gets sent, inherited the
     * narrowed default and only ever showed the proxy. Inverted: the
     * default is now "show everyone" (what any new call site gets without
     * having to know this distinction exists); $signersOnly=true is the
     * one, explicit, opt-in for the two places that must stay narrowed —
     * the Signing Order list and the SignatureRequest-creation loop itself.
     */
    private function expandEntityRecipients(array $recipients, $user, bool $signersOnly = false): array
    {
        $contactIds = collect($recipients)->pluck('_contact_id')->filter()->unique()->values();
        if ($contactIds->isEmpty()) {
            return $recipients;
        }

        $contacts = Contact::withoutGlobalScopes()->whereIn('id', $contactIds)->get()->keyBy('id');
        // Flow 509 — per-recipient now (not per-contact): whether a natural
        // person needs expansion depends on THIS document's own _is_deceased
        // flag, so the early-exit must look at each recipient row, not just
        // the distinct contact set. An entity is still unconditional.
        $needsExpansion = false;
        foreach ($recipients as $r) {
            $cid = $r['_contact_id'] ?? null;
            $c = $cid ? ($contacts[$cid] ?? null) : null;
            if ($c && $this->partyNeedsRepresentativeExpansion($c, ! empty($r['_is_deceased']))) {
                $needsExpansion = true;
                break;
            }
        }
        if (! $needsExpansion) {
            return $recipients; // no entities, and nothing else has a representative linked → nothing to expand
        }

        $agencyId = $user->agency_id ?? optional($contacts->first())->agency_id;
        // The APPLICABLE recipient preset (agency-defined on the setup screen):
        // an 'entity'/'all' default, falling back to the agency default.
        $preset   = $agencyId ? \App\Models\Docuperfect\EsignRecipientPreset::resolveFor((int) $agencyId, 'entity') : null;

        $out = [];
        $order = 0;
        foreach ($recipients as $r) {
            $cid     = $r['_contact_id'] ?? null;
            $contact = $cid ? ($contacts[$cid] ?? null) : null;

            // cc3's finding, cc2 2026-08-26 (Johan scenario 5, live regression
            // caught minutes before landing) — a recipient already carrying
            // _slot_bindings/_recipient_template_id is already spoken for by
            // resolveChainBindings() (the deceased-substitute / "Replace this
            // party" mechanism). Once that binding is legitimately backed by
            // a real contact_representatives row (as tonight's identity guard
            // now requires), this generic entity-expansion pass would ALSO
            // fire on the same contact and silently consume/rewrite the
            // deceased party's own row before the slot-binding pass ever
            // runs — losing is_deceased and the row's own recipient_local_key,
            // and leaving the executor with two separate SignatureRequest
            // rows instead of one. Leave an already-bound recipient alone
            // entirely; it is not this pass's row to touch.
            $alreadyBoundByChain = ! empty($r['_slot_bindings']) || ! empty($r['_recipient_template_id']);
            if (! $contact || $alreadyBoundByChain || ! $this->partyNeedsRepresentativeExpansion($contact, ! empty($r['_is_deceased']))) {
                $r['order'] = ++$order;
                $out[] = $r;
                continue;
            }

            // Johan, 2026-08-26 (bug found testing 913f2f102) — the proxy
            // pick lives on THIS recipient's own row only, never on the
            // contact/company — set purely client-side by the wizard's
            // picker and carried through step_data like _is_deceased/
            // _slot_bindings already are. Never read back from
            // contact_representatives for this purpose.
            $overrideProxyRepId = isset($r['_entity_proxy_contact_id']) ? (int) $r['_entity_proxy_contact_id'] : null;

            // Johan, 2026-08-26 — "1st director - 1st signature position, 1
            // address section, 1st recipient to sign." Same per-document,
            // never-on-the-contact rule as the proxy pick itself.
            $effectiveOrder = $this->resolveEffectiveRepOrder($r, $overrideProxyRepId);

            // Full representative list by default — every one renders their
            // own address/phone/email; a proxy pick must never make the
            // other representatives' details disappear from a document a
            // human reads. Proxy-narrowed (who actually signs/receives the
            // request) ONLY when the caller explicitly asks for that.
            $signers = $signersOnly ? $contact->signingRepresentatives($overrideProxyRepId) : $contact->representatives()->get();
            $signers = Contact::applyRepresentativeOrder($signers, $effectiveOrder);
            if ($signers->isEmpty()) {
                $r['order']                        = ++$order;
                $r['_entity_contact_id']           = (int) $contact->id;
                $r['_entity_name']                 = (string) $contact->entity_name;
                $r['_entity_needs_representative']  = true;
                $out[] = $r;
                continue;
            }

            foreach ($signers as $rep) {
                $capacity = $rep->pivot->capacity ?? null;
                // A PROXY signer renders with the distinct proxy wording —
                // this document's own pick when one was made, else whatever
                // is permanently on file (ordinarily nothing, for a company).
                $isProxy  = $overrideProxyRepId !== null ? ($rep->id === $overrideProxyRepId) : (bool) ($rep->pivot->signs_as_proxy ?? false);
                $label    = $preset
                    ? $preset->renderPhrase($contact, $rep, $capacity, $isProxy)
                    : \App\Models\Docuperfect\EsignRecipientPreset::substitute(
                        $isProxy
                            ? \App\Models\Docuperfect\EsignRecipientPreset::DEFAULT_PROXY_PHRASING
                            : \App\Models\Docuperfect\EsignRecipientPreset::DEFAULT_PHRASING,
                        $contact, $rep, $capacity);
                $caption  = $preset ? $preset->renderCaption($contact, $rep, $capacity, $isProxy) : '';

                // SNAPSHOT (Johan, 2026-08-24) — the document-body wording is
                // resolved ONCE, here, at generation time, and stored on the
                // SignatureRequest (see the createSigningRequest() call
                // below). A wording template edited after this point must
                // never change what an already-sent document says.
                $partyClauseText = app(\App\Services\Docuperfect\RoleBlockExpansionService::class)
                    ->composeEntityPartyText($contact, true, $overrideProxyRepId, $effectiveOrder);

                $out[] = [
                    'order'                 => ++$order,
                    'role'                  => $r['role'] ?? '',
                    // cc1's audit, escalated by Johan 2026-08-24 — this used
                    // to be $label (the FULL document-body clause: entity +
                    // every representative + IDs + capacity), and it fed
                    // createSigningRequest()'s $signerName param DIRECTLY
                    // below — meaning the real email greeting read "Hi 1502
                    // BEAUMONT PROP CC, herein represented by Elize
                    // Reichel..." instead of "Hi Elize." The clause belongs
                    // in the document body (_party_clause_text, already
                    // correct); the SIGNER RECORD needs the natural
                    // person's own name and nothing else — resolved
                    // directly from the representative Contact, the same
                    // way a natural-person recipient's own 'name' always
                    // has been (never the entity's, never the clause).
                    'name'                  => (string) $rep->full_name,
                    'first_name'            => $rep->first_name ?? '',
                    'last_name'             => $rep->last_name ?? '',
                    'id_number'             => $rep->id_number ?? '',
                    'email'                 => $rep->email ?? '',
                    'cell'                  => $rep->phone ?? '',
                    'address'               => $rep->address ?? '',
                    '_contact_id'           => (int) $rep->id,
                    '_entity_contact_id'    => (int) $contact->id,
                    '_entity_name'          => (string) $contact->entity_name,
                    '_capacity'             => $capacity,
                    '_is_proxy'             => $isProxy,
                    '_representation_label' => $label,
                    '_signature_caption'    => $caption,
                    '_party_clause_text'    => $partyClauseText,
                    '_matched_signing_setup_index' => $r['_matched_signing_setup_index'] ?? null,
                    // Johan, 2026-08-26 — must survive expansion: prepareSigning()'s
                    // own "recompute fresh right before freezing" step (Flow 409)
                    // reruns composeEntityPartyText() a second time later using
                    // ONLY what's still on this row — without these, that recompute
                    // would silently drop back to the permanent pivot's own order/
                    // proxy state and undo everything just set above.
                    '_entity_proxy_contact_id' => $overrideProxyRepId,
                    '_entity_rep_order'     => $effectiveOrder,
                    'bank_name'             => $rep->bank_name ?? '',
                    'bank_account_name'     => $rep->bank_account_name ?? '',
                    'bank_account_number'   => $rep->bank_account_number ?? '',
                    'bank_branch_name'      => $rep->bank_branch_name ?? '',
                    'bank_branch_code'      => $rep->bank_branch_code ?? '',
                    'bank_account_type'     => $rep->bank_account_type ?? '',
                ];
            }
        }

        return $out;
    }

    /**
     * HARD BLOCK (Johan, 2026-08-25): "for every party added to a document
     * there is a way to replace this party... the signer is ALWAYS a
     * natural person." A recipient flagged deceased never signs
     * (SignatureRequest::isSigningParticipant() — is_deceased is absolute),
     * so the document must not be sendable unless a real substitute signer
     * is bound in their place. This is the same "signing link in the
     * chain" contract RecipientTemplate.php's own docblock describes for a
     * type:'recipient' slot binding — the ONLY binding type that produces
     * an actual SignatureRequest for the bound party (see
     * RecipientTemplate::resolveSlotDisplayName()). A type:'contact'
     * binding is display-only by design and never receives a signing
     * request, so it does not satisfy this rule; nor does a type:'self'
     * binding pointing back at the deceased row itself, nor a binding to
     * another recipient who is themselves deceased.
     *
     * A certain problem is a hard block, not a warning — this throws
     * before any DB writes, naming the specific party, rather than letting
     * a document with an unsignable party go out.
     */
    private function assertDeceasedRecipientsHaveSubstituteSigner(array $recipients): void
    {
        $byLocalKey = [];
        foreach ($recipients as $r) {
            $key = $r['_recipient_local_key'] ?? null;
            if ($key !== null) {
                $byLocalKey[$key] = $r;
            }
        }

        foreach ($recipients as $r) {
            if (empty($r['_is_deceased'])) {
                continue;
            }

            $ownKey = $r['_recipient_local_key'] ?? null;
            $bindings = $r['_slot_bindings'] ?? [];
            $hasSubstitute = false;

            if (is_array($bindings)) {
                foreach ($bindings as $binding) {
                    if (! is_array($binding) || ($binding['type'] ?? null) !== 'recipient') {
                        continue;
                    }
                    $boundKey = $binding['recipient_local_key'] ?? null;
                    if ($boundKey === null || $boundKey === $ownKey) {
                        continue;
                    }
                    $bound = $byLocalKey[$boundKey] ?? null;
                    if ($bound !== null && empty($bound['_is_deceased'])) {
                        $hasSubstitute = true;
                        break;
                    }
                }
            }

            if (! $hasSubstitute) {
                $name = trim((string) ($r['name'] ?? '')) ?: 'This party';
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'recipients' => "{$name} is marked deceased but no substitute signer has been chosen. Open \u{201c}Replace this party\u{201d} and choose who signs in their place before sending.",
                ]);
            }
        }
    }

    /**
     * HARD BLOCK (Johan, 2026-08-25 — "so add a registration field on
     * suppliers"; split 2026-08-26 — "split registration and ID into two
     * fields on supplier... a company registration number, and the
     * representative's ID number. The clause needs both"): a supplier
     * bound as someone's representative via a type:'recipient' slot
     * binding (the same "Replace this party" chain
     * assertDeceasedRecipientsHaveSubstituteSigner() above checks) must
     * carry BOTH numbers — the FIRM's registration number
     * (AgencyServiceProvider::registration_number) and the REPRESENTATIVE's
     * own ID number (AgencyServiceProviderContact::id_number, new
     * 2026_08_29_000007) — because the clause names both: the company by
     * its registration, the person signing by their own ID. Checked
     * against the real, current DB records (not the wizard's own
     * flattened recipient-array snapshot, which only ever carried one
     * borrowed value) so a number added moments ago on the supplier
     * directory screen is picked up immediately. An ordinary supplier
     * recipient who is NOT standing in as anyone's representative is
     * untouched — existing suppliers with no number on file are fine
     * right up until the moment one is actually used this way, per
     * Johan's explicit "not required retrospectively" instruction.
     *
     * The message names the specific supplier, which number(s) are
     * missing, and where to add them, per Johan's "if you block, say so"
     * steer.
     */
    private function assertSupplierRepresentativesHaveRegistrationNumber(array $recipients): void
    {
        $byLocalKey = [];
        foreach ($recipients as $r) {
            $key = $r['_recipient_local_key'] ?? null;
            if ($key !== null) {
                $byLocalKey[$key] = $r;
            }
        }

        foreach ($recipients as $r) {
            $bindings = $r['_slot_bindings'] ?? [];
            if (! is_array($bindings)) {
                continue;
            }

            foreach ($bindings as $binding) {
                if (! is_array($binding) || ($binding['type'] ?? null) !== 'recipient') {
                    continue;
                }
                $boundKey = $binding['recipient_local_key'] ?? null;
                if ($boundKey === null) {
                    continue;
                }
                $bound = $byLocalKey[$boundKey] ?? null;
                if ($bound === null || ($bound['_recipient_source'] ?? null) !== 'supplier') {
                    continue;
                }

                $supplierContactId = $bound['_supplier_contact_id'] ?? null;
                $representative = $supplierContactId
                    ? \App\Models\DealV2\AgencyServiceProviderContact::withoutGlobalScopes()->with('firm')->find($supplierContactId)
                    : null;

                $supplierName = trim((string) ($bound['name'] ?? '')) ?: 'This supplier';
                $firmName = trim((string) ($bound['_supplier_firm_name'] ?? ($representative->firm->name ?? '')));
                $where = $firmName !== '' ? "the supplier directory entry for {$firmName}" : 'the supplier directory';

                $missingCompanyReg = $representative === null || trim((string) ($representative->firm->registration_number ?? '')) === '';
                $missingRepId = $representative === null || trim((string) ($representative->id_number ?? '')) === '';

                if (! $missingCompanyReg && ! $missingRepId) {
                    continue;
                }

                $missing = array_filter([
                    $missingCompanyReg ? 'the company registration number' : null,
                    $missingRepId ? "{$supplierName}'s own ID number" : null,
                ]);
                $missingText = implode(' and ', $missing);

                throw \Illuminate\Validation\ValidationException::withMessages([
                    'recipients' => "{$supplierName} is standing in as a representative on this document but {$missingText} " . (count($missing) > 1 ? 'are' : 'is') . " missing. Add " . (count($missing) > 1 ? 'them' : 'it') . " in {$where} (Deal Register \u{2192} Suppliers) before sending.",
                ]);
            }
        }
    }

    /**
     * HARD BLOCK (Johan, 2026-08-26 — correcting an earlier, wrong
     * self-answer): "the ruling is BLOCK... Silent degradation is exactly
     * the failure pattern we have spent this whole night removing. So:
     * BLOCK when the deceased contact has no ID. Same for the person
     * signing on the executor side. The refusal names which person is
     * missing an ID and where to add it." Deliberately NOT
     * RecipientTemplate::withIdSuffix()'s existing graceful-degradation
     * pattern (omit the suffix, never block) — that pattern is correct
     * for an ordinary party's optional ID; it is the wrong pattern here,
     * because the clause's whole legal purpose is naming who died and who
     * stands for them, by ID.
     *
     * Checks every slot of a chain-bound recipient's template (not just
     * "deceased"/"executor" by name — whatever the template declares),
     * skipping only: an entity/company contact (its ID concept is a
     * registration number, a different check, not this one) and a
     * supplier-sourced binding (already fully covered, correctly, by
     * assertSupplierRepresentativesHaveRegistrationNumber() just above —
     * checking it again here would risk a second, differently-worded
     * block on the exact same missing number).
     */
    private function assertChainPartiesHaveIdNumbers(array $recipients): void
    {
        $byLocalKey = [];
        foreach ($recipients as $r) {
            $key = $r['_recipient_local_key'] ?? null;
            if ($key !== null) {
                $byLocalKey[$key] = $r;
            }
        }

        foreach ($recipients as $r) {
            if (empty($r['_recipient_template_id']) || empty($r['_slot_bindings']) || ! is_array($r['_slot_bindings'])) {
                continue;
            }

            $recipientTemplate = \App\Models\RecipientTemplate::find($r['_recipient_template_id']);
            if ($recipientTemplate === null) {
                continue;
            }

            foreach ($recipientTemplate->party_slots ?? [] as $slot) {
                $slotKey = $slot['key'] ?? null;
                $slotLabel = $slot['label'] ?? $slotKey;
                if ($slotKey === null) {
                    continue;
                }
                $binding = $r['_slot_bindings'][$slotKey] ?? null;
                if (! is_array($binding)) {
                    continue; // dangling bindings are blocked elsewhere (resolveChainBindings)
                }

                $type = $binding['type'] ?? null;
                $name = null;
                $idNumber = null;
                $where = 'on the recipient';

                if ($type === 'self') {
                    $name = trim((string) ($r['name'] ?? '')) ?: 'This party';
                    $idNumber = $r['id_number'] ?? null;
                } elseif ($type === 'contact') {
                    $contact = Contact::withoutGlobalScopes()->find($binding['contact_id'] ?? null);
                    if ($contact === null || $contact->isEntity()) {
                        continue; // dangling handled elsewhere; a company has no personal ID to check here
                    }
                    $name = $contact->full_name;
                    $idNumber = $contact->id_number;
                    $where = 'on their contact record';
                } elseif ($type === 'recipient') {
                    $bound = $byLocalKey[$binding['recipient_local_key'] ?? null] ?? null;
                    if ($bound === null || ($bound['_recipient_source'] ?? null) === 'supplier') {
                        continue; // dangling, or already covered above
                    }
                    $name = trim((string) ($bound['name'] ?? '')) ?: 'This party';
                    $idNumber = $bound['id_number'] ?? null;
                } else {
                    continue;
                }

                if (trim((string) $idNumber) !== '') {
                    continue;
                }

                throw \Illuminate\Validation\ValidationException::withMessages([
                    'recipients' => "{$name} is named as \"{$slotLabel}\" in this document's clause but has no ID number on file. Add it {$where} before sending.",
                ]);
            }
        }
    }

    /**
     * Johan, 2026-08-26 — the three-part clause chain's middle piece: when
     * $r is a supplier-sourced recipient (standing in as someone's
     * representative), freeze the FIRM's name and registration number onto
     * $sigReq's own row at the moment it's created — see
     * 2026_08_29_000008_add_supplier_firm_to_signature_requests_table.
     * RecipientTemplate::resolveSlotDisplayName()'s type:'recipient' branch
     * reads these back later to build "Firm (Reg: NNN) represented by
     * Person (ID: NNN)" instead of just the person.
     *
     * Looked up LIVE from the real AgencyServiceProvider row via
     * _supplier_firm_id, never trusted from the wizard's own
     * _supplier_firm_name/_supplier_firm_registration_number — same
     * live-DB-over-client-payload discipline
     * assertSupplierRepresentativesHaveRegistrationNumber() already uses,
     * so what freezes onto a legal document's clause is never something the
     * browser merely claimed.
     */
    private function stampSupplierFirmIfAny(\App\Models\Docuperfect\SignatureRequest $sigReq, array $r): void
    {
        if (($r['_recipient_source'] ?? null) !== 'supplier' || empty($r['_supplier_firm_id'])) {
            return;
        }

        $firm = \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()->find((int) $r['_supplier_firm_id']);
        if ($firm === null) {
            return;
        }

        $sigReq->update([
            // Johan, 2026-08-26 — company leads, person underneath, and where
            // there is no company the person leads. $firm->name is the firm's
            // own required identifier and is often the PERSON's own name for
            // a sole-practitioner firm (e.g. "Piet Begrafnis" as both the
            // firm's name and the contact's own name) — $firm->company is the
            // real company name when one was captured. Same rule as the
            // picker/search fix in searchContacts()/addSupplier() above; this
            // is the value that actually freezes onto the clause at send time.
            'supplier_firm_name' => $firm->company ?: $firm->name,
            'supplier_firm_registration_number' => $firm->registration_number,
            // Johan, 2026-08-27 — cc4 gave suppliers a real business address
            // (AgencyServiceProvider->address, same plain-string shape as
            // Contact->address, 1407ef455). A supplier-sourced recipient has
            // no linked Contact, so the domicilium address block had no
            // source at all until now — frozen here the same way the firm
            // name/reg number already are.
            'supplier_firm_address' => $firm->address,
        ]);
    }

    /**
     * "Replace this party" — resolves every collected chain binding into its
     * frozen party_clause_text, once, at generation time. Shared by BOTH send
     * paths (prepareSigning() and prepareWetInk(), Johan 2026-08-25) so a
     * deceased party's clause — "Late Estate of {deceased} herein represented
     * by {executor}" — prints identically whether the document goes out for
     * e-signature or to paper. One resolution, never a second implementation
     * that could drift from it.
     *
     * $chainBindings shape: list of ['signature_request_id', 'recipient_template_id', 'slot_bindings'].
     * A dangling binding (a slot's recipient/contact no longer resolves)
     * blocks the send entirely rather than freezing a half-built clause.
     */
    private function resolveChainBindings(array $chainBindings, ?int $assertingUserId = null): void
    {
        foreach ($chainBindings as $binding) {
            $sigReq = \App\Models\Docuperfect\SignatureRequest::find($binding['signature_request_id']);
            $recipientTemplate = \App\Models\RecipientTemplate::find($binding['recipient_template_id']);
            if (! $sigReq || ! $recipientTemplate) {
                continue;
            }

            try {
                $resolvedText = $recipientTemplate->resolveBoundText($sigReq, $binding['slot_bindings']);
            } catch (\App\Exceptions\DanglingSlotBindingException $e) {
                // Block the send with a message naming the specific slot — never a
                // half-built clause on a document that goes on to be signed or printed.
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'recipients' => $e->getMessage(),
                ]);
            }

            // cc2, 2026-08-25 — Flow 409's other half: this rebind only ever
            // freezes party_clause_text; it never touches the signer identity
            // ($sigReq->signer_name was set once, back at createSigningRequest()
            // time, and could be for a DIFFERENT party slot than the one this
            // binding just resolved to). Re-deriving the terminal signer from a
            // chain binding is a bigger change than this task covers tonight —
            // the honest, safe move per Johan's own rule is to refuse the
            // rebind outright rather than let it freeze a clause that no
            // longer names the signer already on this row.
            //
            // Corrected same night (cc4, row 1506) — the first version of
            // this check compared $resolvedText against $sigReq->signer_name
            // as TEXT, which a name that merely LOOKS related ("Chris" inside
            // "Christopher") can satisfy without being the same person. This
            // checks IDENTITY instead: at least one bound slot must resolve,
            // by Contact id / recipient_local_key — never by name — to
            // $sigReq itself. See slotBindingResolvesToSigner()'s docblock.
            if (! $this->slotBindingResolvesToSigner($sigReq, $binding['slot_bindings'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'recipients' => 'This party replacement does not resolve, by identity, to '
                        . "the signer already bound to this document ({$sigReq->signer_name}). "
                        . 'Replace the signer too, not just the clause, before sending.',
                ]);
            }

            // Johan, 2026-08-26 — "picking someone in 'Replace this party'
            // CREATES the relationship." Run BEFORE the identity check
            // below: an agent binding a slot (deceased→executor, etc.) IS
            // the real-world assertion of that relationship, not a claim
            // that one must already exist on file. This only ever fills a
            // gap (firstOrCreate) — an already-legitimate pair is untouched.
            $recipientTemplate->ensureChainRelationshipsExist($sigReq, $binding['slot_bindings'], $assertingUserId);

            // cc2, 2026-08-26 (cc4's stranger-rebind finding, corrected
            // twice the same night — cc4's real reproduction, document 959
            // / signature_request 1578, proved the first version wrong: it
            // only ever validated party_slots[0] — "deceased" — which was
            // bound to self on that exact row, so the check exempted
            // itself and NEVER looked at "executor", the slot naming the
            // stranger. Checking a slot because it happens to be first was
            // the bug. RecipientTemplate::assertChainIsLegitimate()
            // validates every adjacent pair the template declares — the
            // FULL chain, not one position — using the same canonical
            // identity check the create path already uses
            // (SignatureRequest::assertSignerIsCurrentRepresentative()).
            // Still runs unconditionally: a signer bound to a slot that was
            // NOT resolved through this chain at all (never went through
            // ensureChainRelationshipsExist() above) is still refused here
            // exactly as before — this only ever adds a record, it never
            // substitutes for the check.
            try {
                $recipientTemplate->assertChainIsLegitimate($sigReq, $binding['slot_bindings']);
            } catch (\App\Exceptions\DanglingSlotBindingException $e) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'recipients' => $e->getMessage(),
                ]);
            } catch (\App\Exceptions\PartyClauseSignerMismatchException $e) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'recipients' => 'This party replacement names someone who is not currently linked '
                        . 'as a genuine representative — pick someone who actually stands in that '
                        . 'relationship before sending. (' . $e->getMessage() . ')',
                ]);
            }

            // Persisted onto the row so a LATER re-check
            // (SignatureRequest::isSigningBlocked(), at sign time) has
            // something to re-verify against — the same identity just
            // validated above, not recomputed a third way.
            $representedContactId = $recipientTemplate->resolveRepresentedContactIdFor($sigReq, $binding['slot_bindings']);

            $sigReq->update([
                'recipient_template_id' => $recipientTemplate->id,
                'slot_bindings' => $binding['slot_bindings'],
                'party_clause_text' => $resolvedText,
                'represented_contact_id' => $representedContactId,
            ]);
        }
    }

    /**
     * cc2, 2026-08-25 (cc4's row 1506) — is $sigReq, BY IDENTITY, one of the
     * parties this chain binding actually resolved to? A binding's own
     * $type/$contact_id/$recipient_local_key are the SAME primary keys
     * RecipientTemplate::resolveSlotDisplayName() resolves display text
     * from — reused here directly rather than re-deriving from the text
     * that method produces. 'self' means the binding points at $sigReq by
     * construction; 'contact'/'recipient' are checked against $sigReq's own
     * contact_id / recipient_local_key — never against signer_name.
     */
    private function slotBindingResolvesToSigner(\App\Models\Docuperfect\SignatureRequest $sigReq, array $slotBindings): bool
    {
        foreach ($slotBindings as $binding) {
            $type = $binding['type'] ?? null;

            if ($type === 'self') {
                return true;
            }
            if ($type === 'contact' && $sigReq->contact_id !== null
                && (int) ($binding['contact_id'] ?? 0) === (int) $sigReq->contact_id) {
                return true;
            }
            if ($type === 'recipient' && ($binding['recipient_local_key'] ?? null) === $sigReq->recipient_local_key) {
                return true;
            }
        }

        return false;
    }

    private function resolveLinkedContactRole(Contact $contact, array $allowedEsignRoles, string $defaultOwnerRole): ?string
    {
        $pivotRole = strtolower(trim((string) ($contact->pivot->role ?? '')));

        // PRIMARY — the property link states who they are here.
        $linkRole = Property::esignRoleForPivotRole($pivotRole);

        if ($linkRole === null && in_array($pivotRole, Property::PIVOT_NON_SIGNING_ROLES, true)) {
            // An explicit "not a party" (a portal lead who enquired about the listing). This is
            // a statement, not a gap — never fall through to the global type, which would offer
            // a P24 lead typed "Buyer" as a purchaser on the mandate.
            return null;
        }

        if ($linkRole !== null) {
            if (!empty($allowedEsignRoles) && !in_array($linkRole, $allowedEsignRoles, true)) {
                return null; // Real party on this property, but not one this template signs.
            }

            return $linkRole;
        }

        // FALLBACK — the link carries no usable role (a pre-mandatory-role legacy row, or
        // free-text the backfill flagged AMBIGUOUS and refused to guess at). Only now do we ask
        // what the contact is globally. AT-79: a contact may hold several parent types, so we
        // take the one whose esign_role this template actually needs.
        $parentRows = DB::table('contact_contact_type as cct')
            ->join('contact_types as ct', 'ct.id', '=', 'cct.contact_type_id')
            ->where('cct.contact_id', $contact->id)
            ->whereNull('ct.deleted_at')
            ->orderBy('ct.sort_order')
            ->get(['ct.name', 'ct.esign_role']);

        if ($parentRows->isEmpty() && $contact->contact_type_id) {
            $legacy = DB::table('contact_types')->where('id', $contact->contact_type_id)->first(['name', 'esign_role']);
            if ($legacy) {
                $parentRows = collect([$legacy]);
            }
        }

        if (!empty($allowedEsignRoles)) {
            $chosen = $parentRows->first(fn ($r) => $r->esign_role && in_array($r->esign_role, $allowedEsignRoles, true));
            if (!$chosen) {
                return null; // Contact has no role this template needs.
            }
        } else {
            $chosen = $parentRows->first();
        }

        $recipientRole = $chosen ? strtolower(trim((string) $chosen->name)) : '';

        return $recipientRole !== '' ? $recipientRole : $defaultOwnerRole;
    }

    /**
     * Map template signing_parties to allowed esign_role values on contact_types.
     * Returns empty array if signing_parties is null/empty (= show all contacts, legacy fallback).
     */
    private function buildAllowedEsignRoles(array|string|null $signingParties): array
    {
        if (empty($signingParties)) return [];

        // Handle JSON string (legacy or un-cast data)
        if (is_string($signingParties)) {
            $signingParties = json_decode($signingParties, true) ?? [];
        }

        if (!is_array($signingParties)) return [];

        $roleMap = [
            'owner_party' => ['seller', 'lessor'],
            'seller'      => ['seller'],
            'buyer'       => ['buyer'],
            'landlord'    => ['lessor'],
            'lessor'      => ['lessor'],
            'tenant'      => ['lessee'],
            'lessee'      => ['lessee'],
        ];

        $allowed = [];
        foreach ($signingParties as $party) {
            $party = strtolower(trim($party));
            if ($party === 'agent' || $party === 'creator') continue;
            if ($party === 'acquiring_party') {
                $allowed = array_merge($allowed, ['buyer', 'lessee']);
            } elseif (isset($roleMap[$party])) {
                $allowed = array_merge($allowed, $roleMap[$party]);
            }
        }

        return array_unique($allowed);
    }

    /**
     * Count signable surfaces in rendered HTML using the exact selector the
     * signing engine uses ([data-marker-party][data-marker-type="signature"]
     * — sign.blade.php / external/sign.blade.php / embedSignaturesIntoHtml).
     * Used by the BL-2c pack guard. Fail-open (parse error => 0).
     */
    private function countSignableSurfaces(string $html): int
    {
        if (trim($html) === '') return 0;
        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?>' . $html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
            );
            $xpath = new \DOMXPath($dom);
            return $xpath->query('//*[@data-marker-party][@data-marker-type="signature"]')->length;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Pack signing-role fix. The signature-block partial keys
     * data-marker-party off each template's own signing_parties +
     * document_context (Step 1 finding), NOT the recipients. In a merged
     * pack, segments therefore carry inconsistent owner/acquiring
     * synonyms (e.g. lessor vs seller). The external signing scan only
     * makes a surface interactive when its key resolves to the signer's
     * role, so a lessor-keyed segment is silently skipped for a seller
     * signer. Normalise EVERY data-marker-party across the whole merged
     * document to the canonical recipient role keys — family-collapsed,
     * numeric suffix preserved (seller_2 etc.) — so every segment is
     * signable by the actual recipients. Fail-open (any error => original).
     */
    private function normalizePackMarkerParties(string $mergedHtml, array $recipients): string
    {
        if (trim($mergedHtml) === '') {
            return $mergedHtml;
        }

        $ownerTerms     = ['owner_party', 'owner', 'lessor', 'landlord', 'seller'];
        $acquiringTerms = ['acquiring_party', 'lessee', 'tenant', 'buyer', 'purchaser'];
        $agentTerms     = ['agent', 'property_practitioner'];

        // Canonical owner/acquiring keys from the pack's actual recipients
        // (mirrors the single-doc owner_party→seller/landlord resolution).
        $roles = array_map(
            fn ($r) => strtolower(preg_replace('/_\d+$/', '', $r['role'] ?? '')),
            $recipients
        );
        $isRental = (bool) array_intersect($roles, ['landlord', 'lessor', 'tenant', 'lessee'])
                 && ! array_intersect($roles, ['seller', 'buyer']);
        $ownerCanon = $isRental ? 'landlord' : 'seller';
        $acqCanon   = $isRental ? 'tenant'   : 'buyer';

        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?>' . $mergedHtml,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
            );
            $xpath = new \DOMXPath($dom);
            $changed = false;

            foreach ($xpath->query('//*[@data-marker-party]') as $node) {
                /** @var \DOMElement $node */
                $raw = $node->getAttribute('data-marker-party');
                if ($raw === '') {
                    continue;
                }
                $suffix = preg_match('/_(\d+)$/', $raw, $mm) ? '_' . $mm[1] : '';
                $base = strtolower(preg_replace('/_\d+$/', '', $raw));

                if (in_array($base, $ownerTerms, true)) {
                    $new = $ownerCanon . $suffix;
                } elseif (in_array($base, $acquiringTerms, true)) {
                    $new = $acqCanon . $suffix;
                } elseif (in_array($base, $agentTerms, true)) {
                    $new = 'agent';
                } else {
                    continue; // unknown role — leave untouched
                }

                if ($new !== $raw) {
                    $node->setAttribute('data-marker-party', $new);
                    $changed = true;
                }
            }

            if (! $changed) {
                return $mergedHtml;
            }

            $result = $dom->saveHTML();
            return trim(preg_replace('/^<\?xml encoding="utf-8"\?>/', '', $result));
        } catch (\Throwable $e) {
            \Log::warning('PACK_MARKER_PARTY_NORMALIZE_FAILED', ['error' => $e->getMessage()]);
            return $mergedHtml;
        }
    }

    /**
     * §20 — stamp every .corex-document-wrapper with a unique,
     * instance-stable data-disclosure-doc token (alnum, no underscores)
     * so the signing client derives disclosure keys intrinsically per
     * document (disclosure_<docKey>_<n>) — never from DOM position,
     * wrapper order, or a cross-document cursor. Frozen into the persisted
     * merged_html, so the token is immutable for that document instance;
     * two of the same template in a pack get distinct, stable tokens.
     * Idempotent (a wrapper already stamped is left unchanged). Fail-open.
     */
    private function stampDisclosureDocKeys(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }
        try {
            $out = preg_replace_callback(
                '/<div\b[^>]*\bclass\s*=\s*"[^"]*\bcorex-document-wrapper\b[^"]*"[^>]*>/i',
                function ($m) {
                    if (stripos($m[0], 'data-disclosure-doc') !== false) {
                        return $m[0];
                    }
                    $key = \Illuminate\Support\Str::random(10);
                    return preg_replace('/^<div\b/i', '<div data-disclosure-doc="' . $key . '"', $m[0], 1);
                },
                $html
            );
            return $out ?? $html;
        } catch (\Throwable $e) {
            \Log::warning('STAMP_DISCLOSURE_DOCKEY_FAILED', ['error' => $e->getMessage()]);
            return $html;
        }
    }

    /**
     * PER-DOCUMENT other-conditions in a PACK. Each pack SEGMENT
     * (`.corex-document-wrapper[data-disclosure-doc]`) has its own
     * `~~~~OTHER_CONDITIONS~~~~` marker. Left bare, every segment resolves to the
     * SAME `other_conditions` block_id, so a condition added to one document bleeds
     * into all of them. This scopes each segment's marker to its wrapper's docKey —
     * `~~~~OTHER_CONDITIONS__<docKey>~~~~` — so InsertableBlockRenderer derives an
     * independent block_id per document (its own frames + per-frame initials +
     * re-engagement, no cross-document bleed).
     *
     * Forward pass: each marker takes the nearest PRECEDING `data-disclosure-doc`
     * (its enclosing wrapper, opened just before it in document order). Only runs
     * for real packs (≥2 wrappers); a single document keeps the bare marker so its
     * `other_conditions` block_id is unchanged (backward-compatible).
     */
    private function scopePackOtherConditionsMarkers(string $html): string
    {
        if (trim($html) === '' || substr_count($html, 'corex-document-wrapper') < 2) {
            return $html;
        }
        $currentKey = null;
        $out = preg_replace_callback(
            '/data-disclosure-doc="([^"]+)"|~{4,}\s*OTHER_CONDITIONS\s*~{4,}/i',
            function ($m) use (&$currentKey) {
                if (isset($m[1]) && $m[1] !== '') {
                    $currentKey = preg_replace('/[^A-Za-z0-9_]/', '', $m[1]);
                    return $m[0];
                }
                if ($currentKey === null || $currentKey === '') {
                    return $m[0];
                }
                return '~~~~OTHER_CONDITIONS__' . $currentKey . '~~~~';
            },
            $html
        );
        return $out ?? $html;
    }

    private function autoFillFields(array $fields, array $stepData): array
    {
        // Load named field source mappings (non-manual for auto-resolve)
        $namedFieldMappings = DB::table('docuperfect_named_fields')
            ->whereNotNull('source_type')
            ->where('source_type', '!=', 'manual')
            ->get()
            ->keyBy('id');

        // Load manual-type named fields (resolved from details step data)
        $manualFieldMappings = DB::table('docuperfect_named_fields')
            ->where('source_type', 'manual')
            ->get()
            ->keyBy('id');

        // Build data pools from step_data
        $property   = $stepData['property'] ?? [];
        $recipients = $stepData['recipients']['recipients'] ?? [];
        $details    = $stepData['details'] ?? [];
        $agent      = auth()->user();

        // Build contact lookup by role as arrays (supports multiple contacts per role)
        // If recipient has _contact_id, enrich with full DB data (bank details etc.)
        $contactsByRole = [];
        foreach ($recipients as $r) {
            $role = ucfirst($r['role'] ?? '');
            if (!$role) continue;

            // Enrich from DB if linked to a Contact record
            $contactId = $r['_contact_id'] ?? null;
            if ($contactId) {
                $dbContact = Contact::find($contactId);
                if ($dbContact) {
                    $r = array_merge($r, [
                        'bank_name'           => ($r['bank_name'] ?? '') ?: ($dbContact->bank_name ?? ''),
                        'bank_account_name'   => ($r['bank_account_name'] ?? '') ?: ($dbContact->bank_account_name ?? ''),
                        'bank_account_number' => ($r['bank_account_number'] ?? '') ?: ($dbContact->bank_account_number ?? ''),
                        'bank_branch_name'    => ($r['bank_branch_name'] ?? '') ?: ($dbContact->bank_branch_name ?? ''),
                    ]);
                }
            }

            if (!isset($contactsByRole[$role])) {
                $contactsByRole[$role] = [];
            }
            $contactsByRole[$role][] = $r;
        }

        // Role aliases: wizard uses "landlord"/"tenant", DB uses "Lessor"/"Lessee"
        $roleAliases = [
            'Landlord' => 'Lessor', 'Tenant' => 'Lessee',
            'Lessor' => 'Lessor', 'Lessee' => 'Lessee',
            'Seller' => 'Seller', 'Buyer' => 'Buyer',
        ];

        // Merge aliased roles into contactsByRole
        foreach ($roleAliases as $wizardRole => $dbRole) {
            if (isset($contactsByRole[$wizardRole]) && !isset($contactsByRole[$dbRole])) {
                $contactsByRole[$dbRole] = $contactsByRole[$wizardRole];
            }
        }

        foreach ($fields as &$field) {
            if (!empty($field['value'])) {
                continue; // Don't overwrite existing values
            }

            $namedFieldId = $field['named_field_id'] ?? null;
            if (!$namedFieldId || !isset($namedFieldMappings[$namedFieldId])) {
                continue;
            }

            $mapping = $namedFieldMappings[$namedFieldId];
            $sourceType   = $mapping->source_type;
            $sourceColumn = $mapping->source_column;
            $contactType  = $mapping->source_contact_type;

            // Strip numeric suffix from contact type (e.g., "Lessor 2" → "Lessor")
            if ($contactType && preg_match('/^(.+?)\s+\d+$/', $contactType, $m)) {
                $contactType = $m[1];
            }

            $value = $this->resolveFieldValue($sourceType, $sourceColumn, $contactType, $property, $contactsByRole, $details, $agent);

            if ($value !== null && $value !== '') {
                $field['value'] = (string) $value;
            }
        }
        unset($field);

        // Resolve manual-type fields from the details step data
        foreach ($fields as &$field) {
            if (!empty($field['value'])) {
                continue; // Don't overwrite existing values
            }

            $namedFieldId = $field['named_field_id'] ?? null;
            if (!$namedFieldId || !isset($manualFieldMappings[$namedFieldId])) {
                continue;
            }

            $mapping = $manualFieldMappings[$namedFieldId];

            // Map known manual field names to their detail-step keys
            $manualKeyMap = [
                'Lease Comm %'   => 'commission',
                'Commission'     => 'commission',
                'Deposit'        => 'deposit',
                'Marketing Fee'  => 'marketing_fee',
                'Monthly Rental' => 'monthly_rental',
                'Lease Start'    => 'lease_start',
                'Lease End'      => 'lease_end',
            ];

            $key = $manualKeyMap[$mapping->name] ?? $mapping->source_column ?? 'named_field_' . $namedFieldId;

            // Manual fields resolve from details step data using the resolved key
            if (isset($details[$key]) && $details[$key] !== '') {
                $field['value'] = (string) $details[$key];
            }
        }
        unset($field);

        // Resolve manual fields by field_name when named_field_id is null
        // (e.g., "% num" → manual_num, "% alpha" → manual_alpha from template tagging)
        $manualFieldNameMap = [
            'manual_num'   => 'commission',
            'manual_alpha' => '_commission_words',
        ];
        foreach ($fields as &$field) {
            if (!empty($field['value'])) continue;
            if (($field['mapping_type'] ?? '') !== 'manual') continue;

            $fn = $field['field_name'] ?? '';
            $detailKey = $manualFieldNameMap[$fn] ?? null;
            if (!$detailKey) continue;

            if ($detailKey === '_commission_words') {
                $commVal = $details['commission'] ?? '';
                if ($commVal !== '' && is_numeric($commVal)) {
                    $field['value'] = $this->numberToWords($commVal);
                }
            } elseif (isset($details[$detailKey]) && $details[$detailKey] !== '') {
                $field['value'] = (string) $details[$detailKey];
            }
        }
        unset($field);

        return $fields;
    }

    /**
     * Set the 'required' flag on sign/initial fields based on contact count per role.
     *
     * For each role (landlord, tenant, etc.), the Nth signature block is required
     * only if there are ≥N contacts assigned to that role. The first block is
     * always required; the second only if ≥2 contacts, etc.
     * Agent signature blocks are always required.
     */
    private function setSignatureRequiredFlags(array $fields, array $recipients): array
    {
        // Count contacts per role (lowercase)
        $contactCountByRole = [];
        foreach ($recipients as $r) {
            $role = strtolower($r['role'] ?? '');
            if (!$role || $role === 'agent') continue;
            $contactCountByRole[$role] = ($contactCountByRole[$role] ?? 0) + 1;
        }

        // Group sign/initial field indices by assignedTo
        $signFieldsByParty = [];
        foreach ($fields as $idx => $field) {
            $type = strtolower($field['type'] ?? '');
            if (!in_array($type, ['sign', 'initial'])) continue;

            $party = strtolower($field['assignedTo'] ?? $field['assigned_to'] ?? 'agent');
            $signFieldsByParty[$party][] = $idx;
        }

        // For each party, mark fields required based on contact count
        foreach ($signFieldsByParty as $party => $indices) {
            if ($party === 'agent') {
                // Agent blocks are always required
                foreach ($indices as $idx) {
                    $fields[$idx]['required'] = true;
                }
                continue;
            }

            $contactCount = $contactCountByRole[$party] ?? 1;

            foreach ($indices as $position => $idx) {
                // Position is 0-based: first block → position 0, needs ≥1 contact
                $fields[$idx]['required'] = ($position + 1) <= $contactCount;
            }
        }

        return $fields;
    }

    /**
     * Resolve a single field's value from its source mapping.
     */
    private function resolveFieldValue(string $sourceType, ?string $sourceColumn, ?string $contactType, array $property, array $contactsByRole, array $details, $agent)
    {
        if (!$sourceColumn) {
            return null;
        }

        switch ($sourceType) {
            case 'property':
                return $this->resolvePropertyValue($sourceColumn, $property, $details);

            case 'contact':
                $contacts = $contactsByRole[$contactType] ?? [];
                if (empty($contacts)) return null;

                // Bug 1: concatenate this column across ALL contacts of the
                // role (e.g. two sellers' IDs → "3112 and 6789"), the same
                // ' and ' join field groups use, so plain contact fields
                // (ID, address, email, phone) stay consistent with the
                // field-grouped name field. One contact → single value.
                $parts = [];
                foreach ($contacts as $c) {
                    $v = trim((string) $this->resolveContactValue($sourceColumn, $c));
                    if ($v !== '') {
                        $parts[] = $v;
                    }
                }
                return implode(' and ', $parts);

            case 'agent':
                if ($sourceColumn === 'name') return $agent->name ?? '';
                return null;

            case 'computed':
                return $this->resolveComputedValue($sourceColumn, $property, $details);

            case 'static':
                return $sourceColumn; // The column IS the literal value

            default:
                return null;
        }
    }

    private function resolvePropertyValue(string $column, array $property, array $details)
    {
        return match ($column) {
            'address'           => $property['address'] ?? $property['title'] ?? '',
            'suburb'            => $property['suburb'] ?? '',
            'address+suburb'    => trim(($property['address'] ?? $property['title'] ?? '') . ', ' . ($property['suburb'] ?? ''), ', '),
            'rental_amount'     => $details['monthly_rental'] ?? $property['rental_amount'] ?? '',
            'deposit_amount'    => $details['deposit'] ?? $property['deposit_amount'] ?? '',
            'commission_percent'=> $details['commission'] ?? $details['commission_percent'] ?? '',
            'lease_start_date'  => $details['lease_start'] ?? '',
            'lease_end_date'    => $details['lease_end'] ?? '',
            'property_number'   => $property['erf'] ?? $property['erf_number'] ?? $property['property_number'] ?? '',
            'complex_name'      => $property['complex_name'] ?? '',
            'unit_number'       => $property['unit_number'] ?? '',
            'district'          => $property['district'] ?? '',
            'price'             => $details['price'] ?? $property['price'] ?? '',
            'expiry_date'       => $details['expiry_date'] ?? $property['expiry_date'] ?? '',
            default             => '',
        };
    }

    private function resolveContactValue(string $column, array $contact)
    {
        return match ($column) {
            'first_name+last_name', 'full_name', 'name' => $contact['name'] ?? trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')),
            'last_name', 'surname'  => $contact['last_name'] ?? '',
            'first_name'            => $contact['first_name'] ?? '',
            'address'               => $contact['address'] ?? '',
            'id_number'             => $contact['id_number'] ?? '',
            'email'                 => $contact['email'] ?? '',
            'phone', 'cell'         => $contact['cell'] ?? $contact['phone'] ?? '',
            'bank_name'             => $contact['bank_name'] ?? '',
            'bank_account_name'     => $contact['bank_account_name'] ?? '',
            'bank_account_number'   => $contact['bank_account_number'] ?? '',
            'bank_branch_name'      => $contact['bank_branch_name'] ?? '',
            default                 => $contact[$column] ?? '',
        };
    }

    private function resolveComputedValue(string $column, array $property, array $details)
    {
        $leaseStart = $details['lease_start'] ?? '';
        $price = $details['price'] ?? $details['monthly_rental'] ?? $property['price'] ?? '';

        return match ($column) {
            'lease_start_day' => $leaseStart ? (int) date('d', strtotime($leaseStart)) : '',
            'lease_start_month' => $leaseStart ? date('F', strtotime($leaseStart)) : '',
            'lease_start_year' => $leaseStart ? date('Y', strtotime($leaseStart)) : '',
            'price_in_words' => $price ? $this->numberToWords($price) : '',
            default => '',
        };
    }

    /**
     * HD-4 — one converter, one rounding rule. Delegates to App\Support\AmountInWords (rounds
     * half-up to whole rands, appends " Rand", no cents — Johan's document house rule). This was a
     * byte-identical copy of WebTemplateDataService's; both now share the one implementation.
     */
    private function numberToWords(int|float|string|null $number): string
    {
        return \App\Support\AmountInWords::rands($number);
    }

    /**
     * Inject initials blocks at page boundaries.
     * For paged templates: injects at the bottom of every non-last page div.
     * For continuous web templates: estimates page breaks based on content length
     * and inserts page-break markers with initials for all signing parties.
     */
    private function injectInitialsBlocks(string $html, array $parties): string
    {
        // Build initials row HTML with inline styles
        $blocks = '';
        foreach ($parties as $n => $party) {
            $role = strtolower($party['role']);
            $label = ucfirst(str_replace('_', ' ', $role));
            $blocks .= '<div class="corex-page-initials" '
                . 'data-marker-party="' . $role . '" '
                . 'data-marker-type="initial" '
                . 'data-marker-index="' . $n . '" '
                . 'style="display:inline-block;text-align:center;margin:0 6pt;width:60px;height:30px;'
                . 'border:1px solid #94a3b8;font-size:9px;color:#64748b;cursor:pointer;'
                . 'line-height:30px;">'
                . '<span class="initial-placeholder">' . $label . '</span>'
                . '</div>';
        }

        $initialsRow = '<div class="initials-row" style="display:flex;justify-content:flex-end;'
            . 'align-items:center;gap:12px;padding:8px 0;">'
            . $blocks
            . '</div>';

        // Split HTML on page div openings to identify pages
        // Pattern matches <div class="page">, <div class="page page-break">, or <div class="corex-page">
        $parts = preg_split('/(<div\s+class="(?:corex-)?page[^"]*">)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Count how many page divs we have
        $pageCount = 0;
        foreach ($parts as $part) {
            if (preg_match('/^<div\s+class="(?:corex-)?page[^"]*">/i', $part)) {
                $pageCount++;
            }
        }

        // Paged templates: inject page-break marker at bottom of each non-last page
        if ($pageCount > 1) {
            // Build a proper .corex-page-break marker (not just initials row)
            $pageBreakHtml = $this->buildPageBreakMarker($parties);

            $currentPage = 0;
            $result = '';
            for ($i = 0; $i < count($parts); $i++) {
                $part = $parts[$i];

                if (preg_match('/^<div\s+class="(?:corex-)?page[^"]*">/i', $part)) {
                    $currentPage++;
                    $result .= $part;
                    continue;
                }

                if ($currentPage > 0 && $currentPage < $pageCount) {
                    $lastDivPos = strrpos($part, '</div>');
                    if ($lastDivPos !== false) {
                        $part = substr($part, 0, $lastDivPos) . $pageBreakHtml . substr($part, $lastDivPos);
                    }
                }

                $result .= $part;
            }

            return $result;
        }

        // Continuous web template: estimate page breaks based on text content length
        return $this->injectPageBreaksForContinuousHtml($html, $parties);
    }

    /**
     * For continuous web template HTML (no page divs), estimate page boundaries
     * and insert page-break markers with initials blocks.
     * Uses visible text length as a proxy for rendered height.
     * A4 printable area ≈ 50 lines × 80 chars ≈ 4000 chars of visible text per page.
     */
    private function injectPageBreaksForContinuousHtml(string $html, array $parties): string
    {
        $charsPerPage = 3500;
        $breakTags = ['</p>', '</div>', '</tr>', '</table>', '</section>', '</ul>', '</ol>', '</blockquote>'];

        // --- Step 1: Find the signature section start in HTML ---
        // Must match actual HTML element, not CSS selectors in <style> blocks.
        // corex-signature-section = "THUS DONE AND SIGNED" title clause (part of document body)
        // sig-section = actual signature blocks with input fields (the real boundary)
        // Use sig-section as preferred boundary; fall back to corex-signature-section.
        $posCorex = strpos($html, 'class="corex-signature-section"');
        $posSig = strpos($html, 'class="sig-section"');

        // Prefer sig-section (the actual interactive signing blocks).
        // corex-signature-section is just a document clause ("THUS DONE AND SIGNED")
        // that appears before the real signature blocks — it's still pageable content.
        $sigSectionPos = $posSig !== false ? $posSig : $posCorex;

        // Walk backward to the opening < of the element containing the class
        if ($sigSectionPos !== false) {
            $sigSectionStart = strrpos(substr($html, 0, $sigSectionPos), '<');
            if ($sigSectionStart === false) {
                $sigSectionStart = $sigSectionPos;
            }
        } else {
            $sigSectionStart = strlen($html);
        }

        // --- Step 2: Walk HTML once, count visible chars, record block-end candidates ---
        // Each candidate = [htmlPos => position after the closing tag, visibleCharCount => chars so far]
        $candidates = [];
        $visibleCharCount = 0;
        $inTag = false;
        $len = strlen($html);

        for ($i = 0; $i < $len; $i++) {
            $char = $html[$i];

            if ($char === '<') {
                $inTag = true;
            } elseif ($char === '>') {
                $inTag = false;

                // Check if we just closed a block-level tag (before sig section)
                if ($i < $sigSectionStart) {
                    foreach ($breakTags as $tag) {
                        $tagLen = strlen($tag);
                        $startPos = $i + 1 - $tagLen; // position where this tag would start
                        if ($startPos >= 0 && substr($html, $startPos, $tagLen) === $tag) {
                            $candidates[] = [
                                'htmlPos' => $i + 1, // insert AFTER the closing tag
                                'visibleChars' => $visibleCharCount,
                            ];
                            break; // only record once per position
                        }
                    }
                }
            } elseif (!$inTag) {
                if (trim($char) !== '') {
                    $visibleCharCount++;
                }
            }
        }

        // Visible chars before sig section (for page count)
        $contentChars = $visibleCharCount;
        // If sig section was found, measure only chars before it
        if ($sigSectionPos !== false) {
            // Find the last candidate at or before sigSectionStart
            $contentChars = 0;
            foreach ($candidates as $c) {
                if ($c['htmlPos'] <= $sigSectionStart) {
                    $contentChars = $c['visibleChars'];
                }
            }
            // If no candidates before sig section, count manually
            if ($contentChars === 0) {
                $contentChars = $visibleCharCount;
            }
        }

        $estimatedPages = (int) ceil($contentChars / $charsPerPage);
        if ($estimatedPages <= 1) {
            return $html;
        }

        // --- Step 3: Determine target break positions in visible-char space ---
        $breaksNeeded = $estimatedPages - 1;
        $targetPositions = [];
        for ($b = 1; $b <= $breaksNeeded; $b++) {
            $targetPositions[] = $b * $charsPerPage;
        }

        // --- Step 4: For each target, find the closest block-end candidate ---
        $insertPositions = []; // HTML positions where we'll insert page breaks
        foreach ($targetPositions as $target) {
            $bestCandidate = null;
            $bestDistance = PHP_INT_MAX;
            foreach ($candidates as $c) {
                $distance = abs($c['visibleChars'] - $target);
                if ($distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestCandidate = $c;
                }
            }
            if ($bestCandidate !== null) {
                // Avoid duplicate positions
                if (!in_array($bestCandidate['htmlPos'], $insertPositions)) {
                    $insertPositions[] = $bestCandidate['htmlPos'];
                }
            }
        }

        if (empty($insertPositions)) {
            return $html;
        }

        // --- Step 5: Sort positions descending (insert from end backward) ---
        rsort($insertPositions);

        $pageBreakHtml = $this->buildPageBreakMarker($parties);

        // Insert from the end so earlier positions remain valid
        $result = $html;
        foreach ($insertPositions as $pos) {
            $result = substr($result, 0, $pos) . $pageBreakHtml . substr($result, $pos);
        }

        return $result;
    }

    /**
     * Build a page-break marker div with initials placeholders for all signing parties.
     */
    private function buildPageBreakMarker(array $parties): string
    {
        $blocks = '';
        foreach ($parties as $n => $party) {
            $role = strtolower($party['role']);
            $label = ucfirst(str_replace('_', ' ', $role));
            $blocks .= '<div class="corex-page-initials" '
                . 'data-marker-party="' . $role . '" '
                . 'data-marker-type="initial" '
                . 'data-marker-index="' . $n . '" '
                . 'style="width:60px;height:30px;border:1px solid #94a3b8;display:flex;'
                . 'align-items:center;justify-content:center;font-size:9px;color:#64748b;cursor:pointer;">'
                . '<span class="initial-placeholder">' . $label . '</span>'
                . '</div>';
        }

        return '<div class="corex-page-break" style="margin:16px 0;">'
            . '<div class="corex-page-initials-row" style="display:flex;justify-content:flex-end;align-items:center;gap:8px;padding:12px 0 4px 0;">'
            . $blocks
            . '</div>'
            . '<div style="border-top:2px dashed #cbd5e1;margin:8px 0;position:relative;">'
            . '<span style="position:absolute;right:0;top:-10px;font-size:10px;color:#94a3b8;font-style:italic;background:white;padding:0 4px;">Page Break</span>'
            . '</div>'
            . '</div>';
    }

    /**
     * Resolve signature names and add marker attributes in sig-block HTML.
     */
    private function resolveSignatureNames(string $html, array $viewData, array $parties): string
    {
        // Step 1: Replace {{ $varName ?? 'fallback' }} Blade syntax with actual values from $viewData
        $html = preg_replace_callback(
            '/\{\{\s*\$(\w+)\s*\?\?\s*[\'"]([^"\']*?)[\'"]\s*\}\}/',
            function ($m) use ($viewData) {
                $key = $m[1];
                // Convert camelCase to snake_case for lookup
                $snakeKey = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
                return $viewData[$snakeKey] ?? $viewData[$key] ?? $m[2];
            },
            $html
        );

        // Also handle {{ $varName ?? '' }} with empty fallback
        $html = preg_replace_callback(
            "/\{\{\s*\\$(\w+)\s*\?\?\s*''\s*\}\}/",
            function ($m) use ($viewData) {
                $key = $m[1];
                $snakeKey = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key));
                return $viewData[$snakeKey] ?? $viewData[$key] ?? '';
            },
            $html
        );

        // Step 2: (removed) sig-block processing — signature-block.blade.php now emits
        // data-marker-party attributes directly, making post-render DOM manipulation unnecessary.

        // Step 3: Replace signed-at field spans with editable inputs
        $html = preg_replace(
            '/<span\s+class="field\s+field-tiny"\s*>(\{\{[^}]*\}\}|)\s*<\/span>/i',
            '<span class="field field-tiny signing-input" data-field-key="signed_at" contenteditable="true"></span>',
            $html
        );

        return $html;
    }

    /**
     * Inject field values into data-field spans in merged HTML.
     * New-format imported templates use <span data-field="..."> instead of Blade variables.
     */
    private function injectFieldValues(string $html, array $data): string
    {
        $prefixMap = [
            'Lessor'   => 'lessor',
            'Lessor 2' => 'lessor2',
            'Lessee'   => 'lessee',
            'Lessee 2' => 'lessee2',
            'Agent'    => 'agent',
            'Buyer'    => 'buyer',
            'Seller'   => 'seller',
        ];

        $suffixMap = [
            'first_name+last_name' => 'name',
            'id_number'            => 'id_number',
            'email'                => 'email',
            'phone'                => 'cell',
            'address'              => 'address',
            'bank_name'            => 'bank_name',
            'bank_account_name'    => 'bank_account_name',
            'bank_account_number'  => 'bank_account_number',
            'bank_branch_name'     => 'bank_branch_name',
        ];

        return preg_replace_callback(
            '/<span([^>]*data-field="([^"]+)"[^>]*)><\/span>/i',
            function ($matches) use ($data, $prefixMap, $suffixMap) {
                $attrs     = $matches[1];
                $dataField = $matches[2];
                $fullTag   = $matches[0];

                // Skip manual fields and signing fields
                if (str_starts_with($dataField, 'manual.')) return $fullTag;
                if (preg_match('/data-field-key=/', $attrs)) return $fullTag;

                // Extract data-contact-type if present
                $contactType = null;
                if (preg_match('/data-contact-type="([^"]+)"/', $attrs, $cm)) {
                    $contactType = $cm[1];
                }

                $value = null;

                if ($contactType && isset($prefixMap[$contactType])) {
                    $prefix = $prefixMap[$contactType];
                    $col    = str_replace('contact.', '', $dataField);
                    $suffix = $suffixMap[$col] ?? str_replace(['+', '.'], '_', $col);
                    $key    = $prefix . '_' . $suffix;
                    $value  = $data[$key] ?? null;

                    // For primary Lessor/Lessee: join co-owner name/ID when a _2 variant exists
                    if ($value && in_array($contactType, ['Lessor', 'Lessee']) && in_array($suffix, ['name', 'id_number'])) {
                        $coOwnerKey = $prefix . '_' . $suffix . '_2';
                        $coOwnerVal = $data[$coOwnerKey] ?? null;
                        if (!empty($coOwnerVal)) {
                            $value = $value . ' & ' . $coOwnerVal;
                        }
                    }

                    if (empty($value) && str_contains($col, 'bank')) {
                        $altKey = $prefix . '_bank_' . str_replace('bank_', '', $suffix);
                        $value  = $data[$altKey] ?? null;
                    }
                } elseif (str_starts_with($dataField, 'agent.')) {
                    $col   = str_replace('agent.', '', $dataField);
                    $value = $data['agent_' . $col] ?? $data[$col] ?? null;
                } elseif (str_starts_with($dataField, 'property.')) {
                    $col = str_replace('property.', '', $dataField);

                    if ($col === 'address+suburb') {
                        $value = $data['property_address']
                              ?? $data['street_address']
                              ?? null;
                    } elseif ($col === 'rental_amount') {
                        $raw   = $data['rental_amount'] ?? $data['monthly_rental'] ?? null;
                        $value = $raw ? number_format((float) $raw, 0, '.', ',') : null;
                    } else {
                        $snake = str_replace(['+', '.'], '_', $col);
                        $value = $data[$snake]
                              ?? $data['property_' . $snake]
                              ?? null;
                    }
                }

                if (!empty($value)) {
                    return '<span' . $attrs . '>' . htmlspecialchars((string) $value) . '</span>';
                }

                return $fullTag;
            },
            $html
        );
    }

    /**
     * Insert content before the signature section in HTML.
     * Looks for corex-signature-section first, falls back to sig-section, then appends at end.
     */
    private function insertBeforeSignatureSection(string $html, string $content): string
    {
        $sigSectionPos = strpos($html, '<div class="corex-signature-section">');
        if ($sigSectionPos === false) {
            $sigSectionPos = strpos($html, 'class="sig-section"');
            if ($sigSectionPos !== false) {
                $sigSectionPos = strrpos(substr($html, 0, $sigSectionPos), '<');
            }
        }
        if ($sigSectionPos !== false) {
            return substr($html, 0, $sigSectionPos) . $content . substr($html, $sigSectionPos);
        }
        return $html . $content;
    }

    /**
     * Normalise a web template field so the wizard JS sees the same keys as PDF fields.
     *
     * Web template fields (from DocumentTemplateGenerator::buildFieldsJson) use tag_type
     * instead of type, and may lack assignedTo on field_group_member entries.
     * The wizard's fieldInputType() JS reads f.type — this method ensures it exists.
     */
    private function normalizeFieldForWizard(array $field, string $renderType): array
    {
        // Already has a type key — nothing to do
        if (!empty($field['type'])) {
            return $field;
        }

        // Map tag_type → type (matching what fieldInputType() expects)
        $tagType = $field['tag_type'] ?? '';
        $field['type'] = match ($tagType) {
            'input'       => 'placeholder',
            'date'        => 'date',
            'signature'   => 'signature',
            'initial'     => 'initial',
            'selection'   => 'selection',
            'tick'        => 'tick',
            default       => 'placeholder',
        };

        // Ensure assignedTo exists (field_group_member entries only have party)
        if (empty($field['assignedTo']) && !empty($field['party'])) {
            $field['assignedTo'] = $field['party'];
        }

        return $field;
    }

    /**
     * Sort recipients by signing order: Agent → Acquiring party → Owner party → Witness.
     * In SA practice, tenant/buyer always signs before landlord/seller.
     */
    private function sortRecipientsBySigningOrder(array $recipients): array
    {
        $rolePriority = [
            'agent' => 1,
            // Acquiring party signs first among external parties
            'tenant' => 10, 'lessee' => 10, 'buyer' => 10, 'purchaser' => 10, 'co_buyer' => 10,
            // Owner party signs after acquiring party
            'landlord' => 20, 'lessor' => 20, 'seller' => 20, 'owner' => 20, 'co_seller' => 20, 'spouse' => 20,
            // Witnesses always last
            'witness' => 90,
        ];

        usort($recipients, function ($a, $b) use ($rolePriority) {
            $roleA = strtolower(trim($a['role'] ?? 'other'));
            $roleB = strtolower(trim($b['role'] ?? 'other'));
            $priorityA = $rolePriority[$roleA] ?? 50;
            $priorityB = $rolePriority[$roleB] ?? 50;
            return $priorityA <=> $priorityB;
        });

        foreach ($recipients as $i => &$r) {
            $r['signing_order'] = $i + 1;
        }
        unset($r);

        return $recipients;
    }

    private function stepKey(int $step): string
    {
        return match ($step) {
            1 => 'template',
            2 => 'property',
            3 => 'recipients',
            4 => 'details',
            5 => 'fill_review',
            6 => 'signing_setup',
            default => "step_{$step}",
        };
    }

    /**
     * Build proper wizard fields from template field_mappings.
     * Used when fields_json is empty or skeletal (no id/field_name/named_field_id).
     * Looks up named fields from DB to derive blade-matching field_names.
     *
     * Field groups are emitted as SINGLE entries with field_name matching the blade
     * slug (e.g. "seller_name_surname_id") and type "field_group_display".
     * The actual value is resolved later by autoFillFieldGroups().
     */
    private function buildFieldsFromMappings(array $fieldMappings): array
    {
        // Pre-load all referenced named fields for proper field_name derivation
        $namedFieldIds = collect($fieldMappings)->pluck('namedFieldId')->filter()->unique()->values();
        $namedFieldRecords = [];
        if ($namedFieldIds->isNotEmpty()) {
            $namedFieldRecords = DB::table('docuperfect_named_fields')
                ->whereIn('id', $namedFieldIds)
                ->get()
                ->keyBy('id');
        }

        // Pre-load all referenced field groups
        $fieldGroupIds = collect($fieldMappings)
            ->filter(fn($m) => ($m['mappingType'] ?? $m['mapping_type'] ?? '') === 'field_group')
            ->map(fn($m) => $m['fieldGroupId'] ?? $m['field_group_id'] ?? null)
            ->filter()->unique()->values();
        $fieldGroupMap = collect();
        if ($fieldGroupIds->isNotEmpty()) {
            $fieldGroupMap = \App\Models\Docuperfect\FieldGroup::whereIn('id', $fieldGroupIds)->get()->keyBy('id');
        }

        // Track used field_names to avoid duplicates (append _2, _3, etc.)
        $usedFieldNames = [];

        return collect($fieldMappings)->filter(function ($m) {
            // Skip ghost fields: no label AND no named field AND not a field group
            $mappingType = $m['mappingType'] ?? $m['mapping_type'] ?? '';
            if ($mappingType === 'field_group') return true; // Always keep groups
            if (empty($m['label']) && empty($m['namedFieldId'])) {
                return false;
            }
            return true;
        })->map(function ($m, $i) use ($namedFieldRecords, &$usedFieldNames, $fieldGroupMap) {
            $mappingType = $m['mappingType'] ?? $m['mapping_type'] ?? '';

            // Field groups → emit as a single display field with the blade slug as field_name
            if ($mappingType === 'field_group') {
                $fgId = $m['fieldGroupId'] ?? $m['field_group_id'] ?? null;
                $fg = $fgId ? $fieldGroupMap->get($fgId) : null;
                $groupLabel = $m['label'] ?? ($fg ? $fg->name : 'Field Group');
                $varName = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $groupLabel), '_'));

                // Deduplicate
                if (isset($usedFieldNames[$varName])) {
                    $usedFieldNames[$varName]++;
                    $varName .= '_' . $usedFieldNames[$varName];
                } else {
                    $usedFieldNames[$varName] = 1;
                }

                $editableBy = $m['filled_by'] ?? $m['editable_by'] ?? 'agent';
                if (is_array($editableBy)) {
                    $editableBy = $editableBy[0] ?? 'agent';
                }

                $id = is_string($i) ? $i : ($m['id'] ?? ('mapping_' . $i));

                return [
                    'id'              => $id,
                    'field_name'      => $varName,
                    'name'            => $varName,
                    'label'           => $groupLabel,
                    'named_field_name'=> $groupLabel,
                    'named_field_id'  => null,
                    'type'            => 'field_group_display',
                    'tag_type'        => 'field_group_display',
                    'assignedTo'      => $editableBy,
                    'source'          => 'field_group',
                    'mapping_type'    => 'field_group',
                    'field_group_id'  => (int) $fgId,
                    'field_group_name'=> $fg ? $fg->name : $groupLabel,
                    'party'           => $m['party'] ?? '',
                ];
            }

            $namedFieldId = $m['namedFieldId'] ?? null;
            $namedField = $namedFieldId ? ($namedFieldRecords[$namedFieldId] ?? null) : null;

            // Derive field_name that matches blade data-field attributes:
            // 1. From named field source properties (best match)
            // 2. From mapping field_name if present and not a tag ID
            // 3. From label as fallback
            $varName = '';

            if ($namedField) {
                $varName = $this->deriveBladeName(
                    $namedField->source_type ?? $m['sourceType'] ?? 'manual',
                    $namedField->source_column ?? '',
                    $namedField->source_contact_type ?? $m['sourceContactType'] ?? ''
                );
            }

            if (empty($varName)) {
                $fieldName = $m['field_name'] ?? '';
                if (!empty($fieldName) && !str_starts_with($fieldName, 'tag-')) {
                    $varName = str_replace('.', '_', $fieldName);
                    $varName = preg_replace('/[^a-zA-Z0-9_]/', '_', $varName);
                }
            }

            if (empty($varName)) {
                $label = $m['label'] ?? $m['manualLabel'] ?? '';
                if (!empty($label)) {
                    $varName = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
                } else {
                    $varName = 'field_' . (is_string($i) ? substr(md5($i), 0, 8) : $i);
                }
            }

            // Deduplicate field_names (e.g., two "property_street" fields)
            if (isset($usedFieldNames[$varName])) {
                $usedFieldNames[$varName]++;
                $varName .= '_' . $usedFieldNames[$varName];
            } else {
                $usedFieldNames[$varName] = 1;
            }

            $id = is_string($i) ? $i : ($m['id'] ?? ('mapping_' . $i));
            $source = $m['source'] ?? $m['sourceType'] ?? 'manual';
            if (($m['mappingType'] ?? '') === 'manual') {
                $source = 'manual';
            }
            // Fix A — preserve full editable_by array.
            // Pre-fix this method collapsed the array to its first element so
            // Step 5 only ever rendered ONE chip per field. The full array is
            // now preserved as $editableByArray and emitted on the entry as
            // `editableBy` (the new field the Step 5 chip render iterates).
            // `assignedTo` keeps the legacy single-value contract (first
            // element) so the existing 8+ JS call sites in wizard.blade.php
            // and the field-party SELECT continue to work unchanged.
            $rawEditableBy = $m['filled_by'] ?? $m['editable_by'] ?? 'agent';
            $editableByArray = is_array($rawEditableBy)
                ? array_values(array_filter($rawEditableBy, fn ($v) => is_string($v) && $v !== ''))
                : (is_string($rawEditableBy) && $rawEditableBy !== '' ? [$rawEditableBy] : []);
            if (empty($editableByArray)) {
                $editableByArray = ['agent'];
            }
            // AT multi-party — assignedTo is the single derived PREP-FILLER, not a
            // silent "first tick". Agent fills when they are one of the parties,
            // else the first party. The full multi set rides on `editableBy` below.
            $editableBy = in_array('agent', $editableByArray, true) ? 'agent' : $editableByArray[0];

            // Label: derive from named field if this is a group member with no override
            $label = $m['label'] ?? $m['manualLabel'] ?? '';
            if (empty($label) && $namedField) {
                $label = $namedField->name ?? '';
            }
            if (empty($label)) {
                // For fields without labels (signatures, initials), use the type
                $tagType = $m['tag_type'] ?? $m['type'] ?? 'input';
                $label = ucfirst($tagType === 'input' ? 'Field' : $tagType);
            }

            $entry = [
                'id'              => $id,
                'field_name'      => $varName,
                'name'            => $varName,
                'label'           => $label,
                'named_field_name'=> $label,
                'named_field_id'  => $namedFieldId,
                'type'            => $m['type'] ?? 'placeholder',
                'tag_type'        => $m['type'] ?? 'input',
                'assignedTo'      => $editableBy,        // legacy single-value (first of editableBy)
                'editableBy'      => $editableByArray,   // Fix A — full editable_by array for multi-chip render
                'source'          => $source,
                'mapping_type'    => $m['mappingType'] ?? $m['mapping_type'] ?? '',
            ];

            return $entry;
        })->toArray();
    }

    /**
     * Auto-fill field_group_display entries in allWizardFields from recipient data.
     *
     * For each field group display entry, looks up the group's member named fields,
     * determines the matching role from the group's member contact types, then
     * formats one line per recipient of that role:
     *   "FirstName LastName (ID: xxx) and FirstName LastName (ID: xxx)"
     *
     * Fully systemic — works for any role (seller, buyer, landlord, tenant, lessor, lessee).
     */
    /**
     * E-sign walk-fix FIX 1 + FIX 2 — expand role-bound fields per recipient.
     *
     * For each wizard field whose `editableBy` array names a role with
     * N>1 recipients in this signing session, emit N copies of the field
     * with unique ids (`{field_id}__r{n}`), instance-index metadata, and
     * a per-instance value resolved from THAT specific recipient's
     * contact (not the " and "-joined concatenation produced by the
     * legacy `autoFillFields` path).
     *
     * Each expanded copy carries:
     *   _instance_index       1-based ordinal within the role
     *   _total_instances      N (so the chip can render "Seller 2" vs "Seller")
     *   _recipient_role       wizard role token (seller, buyer, lessor, etc.)
     *   _recipient_name       signer name for the chip label
     *   _recipient_index      array index into the role's recipient list
     *   instance_label        pre-computed display label (e.g. "Seller 2: Steve Jobs")
     *
     * Single-recipient roles + creator/agent fields pass through
     * untouched — single field, single chip, single value, no
     * suffix on the id.
     */
    private function expandWizardFieldsPerRecipient(array $allWizardFields, array $stepData): array
    {
        $recipients = $stepData['recipients']['recipients'] ?? [];
        if (empty($recipients) || empty($allWizardFields)) {
            return $allWizardFields;
        }

        // Bucket recipients by canonical role-token for fast lookup. Wizard
        // emits 'seller' / 'buyer' / 'lessor' / 'tenant' etc.; the
        // canonical-to-wizard alias chain mirrors the same map used by
        // RoleBlockExpansionService::CANONICAL_FOR_VIEWER on the
        // recipient-signing side.
        // Johan, 2026-08-28 (the exact Domicilium off-by-one root cause) —
        // this position numbering ("__r{n}") is what an agent's Fill &
        // Review edit gets keyed and saved under, and that saved key is
        // later matched, EXACT-KEY, against the document's own "__r{n}"
        // stamps by CanonicalDocumentRenderer::applyFillReviewAuthoritativeOverlay().
        // Those document stamps come from RoleBlockExpansionService's
        // role-block cloning, which EXCLUDES a deceased recipient from the
        // position count (Domicilium never lists them — Elize's ruling).
        // This method was including them — so "instance 1" meant the
        // deceased party here but the FIRST LIVING party on the actual
        // document, and an edit the agent made to (what they saw as) the
        // second seller's address saved under a key that, on the real
        // document, belongs to the first. One position-numbering rule,
        // used everywhere a recipient's position is assigned: exclude the
        // deceased, exactly as the document itself does.
        $byRole = [];
        foreach ($recipients as $r) {
            if (! empty($r['_is_deceased'])) {
                continue;
            }
            $role = strtolower(trim((string) ($r['role'] ?? '')));
            if ($role === '') continue;
            $byRole[$role] ??= [];
            $byRole[$role][] = $r;
        }
        if (empty($byRole)) {
            return $allWizardFields;
        }

        // Batch-load named-field source columns once so the per-instance
        // value resolution below doesn't hit the DB N times per field.
        $namedFieldIds = collect($allWizardFields)
            ->pluck('named_field_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $namedFieldMap = [];
        if (!empty($namedFieldIds)) {
            $rows = DB::table('docuperfect_named_fields')
                ->whereIn('id', $namedFieldIds)
                ->get(['id', 'source_type', 'source_column']);
            foreach ($rows as $row) {
                $namedFieldMap[$row->id] = $row;
            }
        }

        $expanded = [];
        foreach ($allWizardFields as $field) {
            $editableBy = $field['editableBy'] ?? null;
            if (!is_array($editableBy) || empty($editableBy)) {
                $expanded[] = $field;
                continue;
            }

            // Per-recipient split is ONLY valid for CONTACT-sourced fields — those whose value
            // genuinely differs per recipient (name, id, phone, email, address of that person).
            // A document-level field (property / deal / computed / manual / static / agent source)
            // carries ONE value shared by all recipients and renders as a single base occurrence;
            // splitting it into "{id}__r{n}" instances strands any edit on an instance the document
            // never renders (the commission-% bug: editableBy names owner_party, so a property-source
            // rate was cloned per seller and the edited "__r2" value never reached the base var the
            // blade prints). editableBy governs WHO may edit, not HOW MANY instances exist. Resolve
            // the authoritative source_type (named-field catalogue first, coarse field 'source' as
            // fallback) and skip expansion for anything that is not contact-sourced.
            $sourceType = strtolower((string) ($field['source'] ?? ''));
            $nfId = $field['named_field_id'] ?? null;
            if ($nfId && isset($namedFieldMap[$nfId])) {
                $sourceType = strtolower((string) $namedFieldMap[$nfId]->source_type);
            }
            if ($sourceType !== 'contact') {
                $expanded[] = $field;
                continue;
            }

            // Pick the primary recipient-bearing role from editableBy.
            // Skip 'agent' — agent fields are single, never per-instance.
            $primaryRole = null;
            $recipientList = [];
            foreach ($editableBy as $token) {
                $token = strtolower((string) $token);
                if ($token === 'agent' || $token === 'creator') continue;
                $wizardRoles = $this->canonicalToWizardRoleAliases($token);
                foreach ($wizardRoles as $wRole) {
                    if (!empty($byRole[$wRole])) {
                        $primaryRole = $wRole;
                        $recipientList = $byRole[$wRole];
                        break 2;
                    }
                }
            }

            if ($primaryRole === null || count($recipientList) <= 1) {
                $expanded[] = $field;
                continue;
            }

            $n = count($recipientList);
            foreach ($recipientList as $idx => $recipient) {
                $instance = $idx + 1;
                $copy = $field;
                $copy['id'] = ($field['id'] ?? 'field') . '__r' . $instance;
                $copy['_original_id'] = $field['id'] ?? null;
                $copy['_instance_index'] = $instance;
                $copy['_total_instances'] = $n;
                $copy['_recipient_role'] = $primaryRole;
                $copy['_recipient_name'] = (string) ($recipient['name'] ?? '');
                $copy['_recipient_index'] = $idx;
                $copy['instance_label']   = $this->formatInstanceLabel($primaryRole, $instance, $n, $recipient);

                // Resolve a per-instance value rather than the
                // concatenated form autoFillFields produced. Use the
                // batch-loaded namedFieldMap to avoid N+1 DB hits.
                $sourceColumn = $field['source_column'] ?? null;
                if (!$sourceColumn) {
                    $namedFieldId = $field['named_field_id'] ?? null;
                    if ($namedFieldId && isset($namedFieldMap[$namedFieldId])) {
                        $nf = $namedFieldMap[$namedFieldId];
                        if ($nf->source_type === 'contact') {
                            $sourceColumn = $nf->source_column;
                        }
                    }
                }
                if ($sourceColumn) {
                    $perInstanceValue = $this->resolveContactValue($sourceColumn, $recipient);
                    $copy['value'] = is_scalar($perInstanceValue) ? (string) $perInstanceValue : '';
                }

                $expanded[] = $copy;
            }
        }
        return $expanded;
    }

    /**
     * Build a transient `Collection<SignatureRequest>` from the flow's
     * step_data recipients so the wizard preview can run through
     * RoleBlockExpansionService without persisting anything. The
     * SignatureRequest instances are NOT saved — they exist in memory
     * only so the expansion service has the same shape it sees at
     * signing time (party_role + role_index + contact_id + signer_name).
     *
     * @param  list<array<string, mixed>> $recipients
     * @return \Illuminate\Support\Collection<int, \App\Models\Docuperfect\SignatureRequest>
     */
    private function buildTransientSignatureRequestsForPreview(\App\Models\Docuperfect\Flow $flow, array $recipients): \Illuminate\Support\Collection
    {
        $out = collect();
        $counts = [];
        foreach ($recipients as $r) {
            $role = strtolower(trim((string) ($r['role'] ?? '')));
            if ($role === '') continue;
            $counts[$role] = ($counts[$role] ?? 0) + 1;
            $req = new \App\Models\Docuperfect\SignatureRequest();
            $req->party_role  = $role;
            $req->role_index  = $counts[$role];
            $req->signer_name = (string) ($r['name'] ?? '');
            $req->signer_email = (string) ($r['email'] ?? '');
            $req->contact_id  = $r['_contact_id'] ?? null;
            // Johan, 2026-08-28 — same no-Contact fallback the real send now
            // carries (signer_phone/signer_address); without this the
            // preview shows blank for what the agent typed while the sent
            // document (once this fix landed) would not.
            $req->signer_phone   = (string) ($r['cell'] ?? '');
            $req->signer_address = (string) ($r['address'] ?? '');
            // Johan, 2026-08-26 — RoleBlockExpansionService::expandWithLooping()'s
            // attestation-block split reads is_proxy/is_deceased straight off
            // these transient rows (never the DB — nothing here is persisted)
            // to decide which representative's signature block is the real one
            // vs. a display-only entry. Without these, every entity
            // representative in the preview looked like a signer.
            $req->is_proxy    = (bool) ($r['_is_proxy'] ?? false);
            $req->is_deceased = (bool) ($r['_is_deceased'] ?? false);
            // Johan, 2026-08-27 — a supplier-sourced recipient (an executor
            // standing in from the supplier directory) has no linked Contact,
            // so its domicilium address block resolves from
            // supplier_firm_address (mutateCloneForInstance()'s no-Contact
            // fallback) the same way the real, sent SignatureRequest row
            // does — see stampSupplierFirmIfAny(). Without this the preview
            // shows blank while the sent document (once frozen) would not,
            // the exact "steps screen wrong, agent screen right" divergence
            // this task exists to close. Live-looked-up, not trusted from
            // the wizard's own payload — same discipline as
            // stampSupplierFirmIfAny().
            if (($r['_recipient_source'] ?? null) === 'supplier' && !empty($r['_supplier_firm_id'])) {
                $firm = \App\Models\DealV2\AgencyServiceProvider::withoutGlobalScopes()->find((int) $r['_supplier_firm_id']);
                if ($firm !== null) {
                    $req->supplier_firm_address = $firm->address;
                }
            }
            $out->push($req);
        }
        return $out;
    }

    /**
     * Map a canonical role token (owner_party / acquiring_party / agent)
     * back to the wizard-side aliases that may carry recipients.
     *
     * @return list<string>
     */
    private function canonicalToWizardRoleAliases(string $token): array
    {
        return match (strtolower($token)) {
            'owner_party'      => ['seller', 'lessor', 'landlord', 'owner_party'],
            'acquiring_party'  => ['buyer', 'lessee', 'tenant', 'acquiring_party'],
            'seller', 'lessor', 'landlord' => [$token],
            'buyer', 'lessee', 'tenant'    => [$token],
            'agent'            => ['agent'],
            'witness'          => ['witness'],
            default            => [$token],
        };
    }

    /**
     * Build the display label for an expanded field instance — used as
     * the Step 5 chip / heading: "Seller 2: Steve Jobs", "Lessor 1: Liam".
     */
    private function formatInstanceLabel(string $role, int $instance, int $total, array $recipient): string
    {
        $base = ucfirst(str_replace('_', ' ', $role));
        $heading = $total > 1 ? "{$base} {$instance}" : $base;
        $name = trim((string) ($recipient['name'] ?? ''));
        return $name === '' ? $heading : "{$heading}: {$name}";
    }

    private function autoFillFieldGroupDisplays(array $allWizardFields, array $stepData): array
    {
        // Build recipients lookup by role (supports multiple contacts per role)
        $recipients = $stepData['recipients']['recipients'] ?? [];
        $contactsByRole = [];
        foreach ($recipients as $r) {
            $role = strtolower($r['role'] ?? '');
            if (!$role) continue;
            $contactsByRole[$role][] = $r;
        }
        // Aliases: wizard roles → DB roles
        $aliasMap = [
            'landlord' => 'lessor', 'tenant' => 'lessee',
            'lessor' => 'lessor', 'lessee' => 'lessee',
            'seller' => 'seller', 'buyer' => 'buyer',
        ];
        foreach ($aliasMap as $from => $to) {
            if (isset($contactsByRole[$from]) && !isset($contactsByRole[$to])) {
                $contactsByRole[$to] = $contactsByRole[$from];
            }
        }

        foreach ($allWizardFields as &$field) {
            if (($field['type'] ?? '') !== 'field_group_display') continue;
            if (!empty($field['value'])) continue; // Already filled

            $fgId = $field['field_group_id'] ?? null;
            if (!$fgId) continue;

            $fg = \App\Models\Docuperfect\FieldGroup::find($fgId);
            if (!$fg || empty($fg->fields)) continue;

            // Load the group's member named fields to determine columns and contact type
            $memberNfIds = collect($fg->fields)->pluck('named_field_id')->filter()->unique()->values();
            $memberNfs = DB::table('docuperfect_named_fields')->whereIn('id', $memberNfIds)->get()->keyBy('id');

            // Determine contact type from member named fields (e.g. "Seller", "Lessor", "Tenant")
            $contactType = '';
            $memberColumns = [];
            foreach ($fg->fields as $member) {
                $nfId = $member['named_field_id'] ?? null;
                $nf = $nfId ? ($memberNfs[$nfId] ?? null) : null;
                if (!$nf) continue;

                $column = $nf->source_column ?? '';
                $memberColumns[] = $column;

                if (empty($contactType) && !empty($nf->source_contact_type)) {
                    // Strip numeric suffix: "Seller 2" → "Seller"
                    $contactType = preg_replace('/\s+\d+$/', '', $nf->source_contact_type);
                }
            }

            if (empty($contactType) || empty($memberColumns)) continue;

            // Resolve contacts: try the exact contact type, then alias
            $roleLookup = strtolower($contactType);
            $contacts = $contactsByRole[$roleLookup] ?? [];

            // Also try the party from the mapping if no contacts found
            if (empty($contacts)) {
                $party = strtolower($field['party'] ?? '');
                // Handle compound parties like "owner_party" → try "seller", "lessor"
                if (str_contains($party, 'owner')) {
                    $contacts = $contactsByRole['seller'] ?? $contactsByRole['lessor'] ?? [];
                } elseif (str_contains($party, 'tenant') || str_contains($party, 'lessee')) {
                    $contacts = $contactsByRole['tenant'] ?? $contactsByRole['lessee'] ?? [];
                } else {
                    $contacts = $contactsByRole[$party] ?? [];
                }
            }

            if (empty($contacts)) continue;

            // Format each contact: "FirstName LastName (ID: xxx)"
            $displayParts = [];
            foreach ($contacts as $contact) {
                $nameParts = [];
                $idNumber = '';
                foreach ($memberColumns as $col) {
                    $val = $contact[$col] ?? '';
                    if (empty($val)) continue;
                    if ($col === 'id_number') {
                        $idNumber = $val;
                    } else {
                        $nameParts[] = $val;
                    }
                }
                $line = implode(' ', $nameParts);
                if (!empty($idNumber)) {
                    $line .= ' (ID: ' . $idNumber . ')';
                }
                if (!empty(trim($line))) {
                    $displayParts[] = trim($line);
                }
            }

            $field['value'] = implode(' and ', $displayParts);
        }
        unset($field);

        return $allWizardFields;
    }

    /**
     * Derive the blade variable name from named field source properties.
     * Maps {source_type, source_column, contact_type} to standard blade data-field names.
     */
    private function deriveBladeName(string $sourceType, string $sourceColumn, ?string $contactType): ?string
    {
        if (empty($sourceColumn)) return null;

        if ($sourceType === 'contact' && $contactType) {
            $role = strtolower(preg_replace('/\s+\d+$/', '', trim($contactType)));
            $prefixMap = ['landlord' => 'lessor', 'tenant' => 'lessee'];
            $prefix = $prefixMap[$role] ?? $role;
            $attrMap = [
                'first_name+last_name' => 'name', 'full_name' => 'name', 'name' => 'name',
                'last_name' => 'last_name', 'surname' => 'last_name',
                'first_name' => 'first_name',
                'id_number' => 'id_number', 'address' => 'address',
                'phone' => in_array($prefix, ['seller', 'buyer']) ? 'phone' : 'cell',
                'cell' => in_array($prefix, ['seller', 'buyer']) ? 'phone' : 'cell',
                'email' => 'email',
            ];
            $suffix = $attrMap[$sourceColumn] ?? $sourceColumn;
            return $prefix . '_' . $suffix;
        }

        if ($sourceType === 'property') {
            $propMap = [
                'property_number' => 'property_erf_number', 'erf_number' => 'property_erf_number',
                'address' => 'property_street', 'street' => 'property_street',
                'suburb' => 'property_township', 'township' => 'property_township',
                'district' => 'property_district', 'complex_name' => 'property_complex_name',
                'price' => 'price', 'rental_amount' => 'monthly_rental',
                'expiry_date' => 'mandate_expiry',
            ];
            return $propMap[$sourceColumn] ?? 'property_' . $sourceColumn;
        }

        if ($sourceType === 'computed') return $sourceColumn;
        if ($sourceType === 'deal') return $sourceColumn;
        if ($sourceType === 'agent') return 'agent_' . $sourceColumn;

        return null;
    }

    /**
     * Check if fields array is skeletal (entries lack id and field_name).
     */
    private function fieldsAreSkeletal(array $fields): bool
    {
        return !empty($fields) && empty($fields[0]['id'] ?? null) && empty($fields[0]['field_name'] ?? null);
    }

    /**
     * Handle FICA per-party duplication within a pack flow.
     * Duplicates a FICA template once per contact/recipient.
     */
    public function duplicateFicaPerParty(Request $request, $flowId)
    {
        $user = $request->user();
        $parentFlow = Flow::where('user_id', $user->id)->findOrFail($flowId);

        if (!$parentFlow->isPackFlow()) {
            return response()->json(['error' => 'Not a pack flow.'], 422);
        }

        $recipients = $parentFlow->step_data['recipients']['recipients'] ?? [];
        $externalRecipients = collect($recipients)->filter(fn($r) => ($r['role'] ?? '') !== 'agent');

        if ($externalRecipients->isEmpty()) {
            return response()->json(['error' => 'No external recipients found.'], 422);
        }

        // Find all FICA flows in this pack that have party_mode = 'per_party'
        $ficaFlows = Flow::where('pack_id', $parentFlow->pack_id)
            ->where('pack_type', $parentFlow->pack_type)
            ->whereHas('template', function ($q) {
                $q->where('party_mode', 'per_party');
            })
            ->get();

        $createdFlows = [];

        foreach ($ficaFlows as $ficaFlow) {
            $ficaTemplate = $ficaFlow->template;
            $baseSequence = $ficaFlow->flow_sequence;

            // Remove the original FICA flow (will be replaced by per-party copies)
            $ficaFlow->delete();

            // Create one flow per external recipient
            foreach ($externalRecipients->values() as $idx => $recipient) {
                $recipientName = $recipient['name'] ?? 'Party';
                $perPartyStepData = $ficaFlow->step_data ?? [];
                $perPartyStepData['fica_for_party'] = $recipient;
                $perPartyStepData['fica_party_name'] = $recipientName;

                // Carry forward shared data
                $sharedData = $parentFlow->getSharedPackData();
                $perPartyStepData['property'] = $sharedData['property'] ?? [];
                $perPartyStepData['recipients'] = ['recipients' => [$recipient]]; // Only this person
                $perPartyStepData['details'] = $sharedData['details'] ?? [];
                $perPartyStepData['carried_forward'] = true;

                $newFlow = Flow::create([
                    'type' => 'esign',
                    'template_id' => $ficaTemplate->id,
                    'user_id' => $user->id,
                    'current_step' => 5,
                    'step_data' => $perPartyStepData,
                    'status' => 'draft',
                    'pack_id' => $parentFlow->pack_id,
                    'pack_type' => $parentFlow->pack_type,
                    'flow_sequence' => $baseSequence + ($idx * 0.1), // Sub-sequence for ordering
                    'parent_flow_id' => $parentFlow->parent_flow_id ?? $parentFlow->id,
                    'pack_status' => null,
                    'property_id' => $parentFlow->property_id,
                ]);

                $createdFlows[] = [
                    'flow_id' => $newFlow->id,
                    'template_name' => $ficaTemplate->name,
                    'for_party' => $recipientName,
                ];
            }
        }

        // Re-sequence all pack flows to have clean integer sequences
        $allFlows = Flow::where('pack_id', $parentFlow->pack_id)
            ->where('pack_type', $parentFlow->pack_type)
            ->orderBy('flow_sequence')
            ->get();

        foreach ($allFlows as $seqIdx => $f) {
            $f->update(['flow_sequence' => $seqIdx]);
        }

        return response()->json([
            'ok' => true,
            'created_flows' => $createdFlows,
            'total_pack_docs' => $allFlows->count(),
        ]);
    }

    /**
     * Prepare a download-only document (no signing pipeline).
     * Creates the document record and generates a PDF for download.
     */
    private function prepareDownloadOnly(Request $request, Flow $flow, Template $template)
    {
        // Auto-flag template as e-sign capable when used via the wizard
        if (!$template->is_esign) {
            $template->update(['is_esign' => true]);
        }

        $user = $request->user();
        $stepData = $flow->step_data ?? [];
        $fields = $stepData['fields'] ?? ($template->fields_json ?? []);
        $renderType = $template->render_type ?? 'pdf';

        // Rebuild from field_mappings if fields are skeletal
        if ((empty($fields) || $this->fieldsAreSkeletal($fields)) && $renderType === 'web' && !empty($template->field_mappings)) {
            $fields = $this->buildFieldsFromMappings($template->field_mappings);
        }

        if ($renderType === 'web') {
            $fields = array_map(fn($f) => $this->normalizeFieldForWizard($f, $renderType), $fields);
        }

        // Auto-fill fields
        $fields = $this->autoFillFields($fields, $stepData);

        // Merge fill_review field values
        $frValues = $stepData['fill_review']['fieldValues'] ?? [];
        foreach ($frValues as $fieldId => $value) {
            foreach ($fields as &$field) {
                if (($field['id'] ?? null) == $fieldId && $value !== '') {
                    $field['value'] = $value;
                }
            }
            unset($field);
        }

        $recipients = $stepData['recipients']['recipients'] ?? [];
        $propertyAddress = $stepData['property']['address'] ?? $stepData['property']['title'] ?? '';

        // Johan, 2026-08-27 — a deceased party is not a party to the
        // agreement; never name the document after them.
        $firstRecipientName = '';
        foreach ($recipients as $r) {
            if (($r['role'] ?? '') !== 'agent' && empty($r['_is_deceased']) && !empty($r['name'])) {
                $firstRecipientName = $r['name'];
                break;
            }
        }

        $docName = $stepData['document_name'] ?? null;
        if (empty($docName)) {
            $docName = $template->name . ($firstRecipientName ? " — {$firstRecipientName}" : '')
                . ' — ' . now()->format('Y-m-d');
        }

        // Render filled document HTML for web templates
        $webTemplateData = null;
        if ($renderType === 'web' && $template->blade_view) {
            $webTemplateDataService = app(WebTemplateDataService::class);
            $webTemplateData = $webTemplateDataService->resolve($template->id, $stepData, $user);

            $viewData = $webTemplateData;
            if (!empty($template->signing_parties)) {
                $viewData['signing_parties'] = $template->signing_parties;
                $propSrc = $stepData['property']['_property_source'] ?? null;
                $viewData['document_context'] = $template->isSalesDocument($propSrc) ? 'sales' : 'rental';
            }

            // Signature-block inputs — SIGNING participants only (Johan,
            // 2026-08-26, flow 330 Finding A) — same rule as prepareSigning()
            // and prepareWetInk(). This was the 4th of the four build sites
            // and was missed in the original fix: Download Only rendered
            // straight off $recipients with no filter, so a deceased/
            // proxy-collapsed party still got a blank, unexecutable
            // signature block on the printed PDF.
            $signingParticipantRecipients = $this->filterToSigningParticipants($recipients);

            // Build party_names for signature-block component
            $partyNames = [];
            foreach ($signingParticipantRecipients as $r) {
                if (($r['role'] ?? '') === 'agent') continue;
                $partyNames[] = $r['name'] ?? '';
            }
            $partyNames[] = $user->name;
            $viewData['party_names'] = $partyNames;

            // Build recipients_by_role
            $recipientsByRole = [];
            foreach ($signingParticipantRecipients as $r) {
                $role = $r['role'] ?? '';
                $baseRole = preg_replace('/_\d+$/', '', $role);
                $recipientsByRole[$baseRole][] = $r;
            }
            // Always include agent from authenticated user — recipients step doesn't have an agent entry
            $recipientsByRole['agent'] = [['name' => $user->name, 'role' => 'agent', 'email' => $user->email ?? '']];
            $viewData['recipients_by_role'] = $recipientsByRole;

            $fullHtml = view($template->blade_view, $viewData)->render();

            // Extract body + styles
            preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $bodyMatch);
            $bodyHtml = $bodyMatch[1] ?? $fullHtml;
            $styles = '';
            if (preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches)) {
                $styles = implode("\n", $styleMatches[0]);
            }

            // Inject field values and clauses
            $bodyHtml = $this->injectFieldValues($bodyHtml, $webTemplateData);

            $otherConditionsText2 = trim($stepData['fill_review']['other_conditions_text'] ?? '');
            if (empty($otherConditionsText2)) {
                $legacyClauses = $stepData['fill_review']['clauses'] ?? [];
                if (!empty($legacyClauses)) {
                    $otherConditionsText2 = implode("\n\n", array_map(fn($c) => $c['text'] ?? $c['content'] ?? '', $legacyClauses));
                }
            }
            // Step 2 (Johan) — skip legacy injection when a marker is present
            // (renderer expands it to rows; injecting here too = double render).
            if (!empty($otherConditionsText2) && ! str_contains($bodyHtml, 'OTHER_CONDITIONS')) {
                $clauseBlocks = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $otherConditionsText2))));
                $clauseHtml = '<div class="corex-additional-clauses" style="margin-top:16pt;">';
                $clauseHtml .= '<h3 style="font-weight:bold;margin-top:12pt;margin-bottom:8pt;">Additional Conditions</h3>';
                foreach ($clauseBlocks as $idx => $block) {
                    $num = $idx + 1;
                    $clauseHtml .= '<div class="clause-block" data-clause-index="' . $idx . '" style="margin:6pt 0;"><p><strong>' . $num . '.</strong> '
                        . e($block) . '</p></div>';
                }
                $clauseHtml .= '</div>';

                $bodyHtml = $this->insertBeforeSignatureSection($bodyHtml, $clauseHtml);
            }

            $webTemplateData['merged_html'] = $styles . $bodyHtml;
        }

        $document = Document::create([
            'name' => $docName,
            'template_id' => $template->id,
            'fields_json' => $fields,
            'owner_id' => $user->ownershipUserId(request()->integer('acting_for_user_id') ?: null), // AT-267 / AUDIT 2026-07-26 (F3) — files as the agent
            'branch_id' => $user->effectiveBranchId(),
            'document_type' => $template->template_type,
            'property_address' => $propertyAddress,
            'property_id' => $stepData['property']['property_id'] ?? null,
            'web_template_data' => $webTemplateData,
        ]);

        // Update flow
        $stepData['document_id'] = $document->id;
        $stepData['delivery_mode'] = 'download';
        $flow->update([
            'step_data' => $stepData,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return redirect()->route('docuperfect.esign.downloadDocument', $document->id)
            ->with('success', 'Document ready for download.');
    }

    /**
     * Prepare download-only delivery (public endpoint hit by wizard JS).
     * Delegates to the existing prepareDownloadOnly() helper.
     */
    public function prepareDownload(Request $request, $flowId)
    {
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);
        $flow->load('template');

        return $this->prepareDownloadOnly($request, $flow, $flow->template);
    }

    /**
     * Prepare wet-ink delivery — creates full signing records (Document,
     * SignatureTemplate, SignatureRequests) so external parties receive
     * wet-ink portal links, but skips marker/zone creation since signatures
     * are collected on paper.
     */
    public function prepareWetInk(Request $request, $flowId)
    {
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);
        $flow->load('template');

        $template = $flow->template;

        // Auto-flag template as e-sign capable when used via the wizard
        if (!$template->is_esign) {
            $template->update(['is_esign' => true]);
        }

        $stepData = $flow->step_data ?? [];
        $fields = $stepData['fields'] ?? ($template->fields_json ?? []);
        $renderType = $template->render_type ?? 'pdf';

        // Rebuild from field_mappings if fields are skeletal
        if ((empty($fields) || $this->fieldsAreSkeletal($fields)) && $renderType === 'web' && !empty($template->field_mappings)) {
            $fields = $this->buildFieldsFromMappings($template->field_mappings);
        }

        if ($renderType === 'web') {
            $fields = array_map(fn($f) => $this->normalizeFieldForWizard($f, $renderType), $fields);
        }

        // Auto-fill fields
        $fields = $this->autoFillFields($fields, $stepData);

        // Merge fill_review field values
        $frValues = $stepData['fill_review']['fieldValues'] ?? [];
        foreach ($frValues as $fieldId => $value) {
            foreach ($fields as &$field) {
                if (($field['id'] ?? null) == $fieldId && $value !== '') {
                    $field['value'] = $value;
                }
            }
            unset($field);
        }

        $recipients = $stepData['recipients']['recipients'] ?? [];
        // Moved ahead of expandEntityRecipients() (Job 1, Johan/cc1, 2026-08-26)
        // — see prepareSigning() for full rationale: attachSigningSetupMatch()
        // needs signingSetup while $recipients still carries the ORIGINAL
        // (pre-expansion) names.
        $signingSetupRaw = $stepData['signing_setup'] ?? [];
        $signingSetup = isset($signingSetupRaw['parties']) ? $signingSetupRaw['parties'] : $signingSetupRaw;
        $unmatchedSigningSetup = [];
        $recipients = $this->attachSigningSetupMatch($recipients, $signingSetup, $unmatchedSigningSetup);
        // Entity/company expansion (Johan, 2026-08-25 — cc1's finding on
        // 93a10b6a2, and the same "an entity never signs" rule prepareSigning()
        // already applies at ESignWizardController.php:2028). Missing here
        // meant a company/CC/trust seller in a wet-ink send never got a real
        // representative signer — createSigningRequest() would have been
        // called with the ENTITY's own raw contact row, not the natural
        // person who actually signs. Same call, same place in the pipeline,
        // as the e-sign path.
        //
        // Johan, 2026-08-26 (escalation of cc5's 547863fbb) — signersOnly:
        // true here is deliberate: this narrowed $recipients feeds the
        // signing-request creation below. It must not be reused for the
        // printed document body — see $bodyStepData below.
        $recipientsPreExpansion = $recipients;
        $recipients = $this->expandEntityRecipients($recipients, $user, signersOnly: true);
        // Flow 480 (Johan, 2026-08-29) — see prepareSigning() for full
        // rationale: entity signing_setup entries name representatives, so
        // they can only match after expansion.
        $recipients = $this->matchUnmatchedSigningSetupPostExpansion($recipients, $unmatchedSigningSetup);
        $recipients = $this->sortRecipientsBySigningOrder($recipients);
        // HARD BLOCK (Johan, 2026-08-25) — the MORE dangerous of the two send
        // paths: wet-ink puts a physical document in someone's hand to sign
        // on paper, with no server-side catch after this point. A deceased
        // party with no substitute must never reach print. Same predicate as
        // the e-sign path — see assertDeceasedRecipientsHaveSubstituteSigner().
        $this->assertDeceasedRecipientsHaveSubstituteSigner($recipients);
        $this->assertSupplierRepresentativesHaveRegistrationNumber($recipients);
        $this->assertChainPartiesHaveIdNumbers($recipients);

        // GENERATED-DOCUMENT BODY — same reasoning as prepareSigning()
        // (ESignWizardController.php ~2586-2610): the printed document must
        // read the SAME resolved clause the SignatureRequest rows carry, but
        // every representative — not just the proxy who signs — must render
        // their own address/phone/email. A fresh, separate display-mode
        // expansion of the pre-narrowing recipients, not a reuse of the
        // signersOnly-narrowed $recipients above. $stepData itself is
        // untouched; every other consumer below still reads the original,
        // unexpanded step_data.
        $bodyStepData = $stepData;
        $displayRecipients = $this->expandEntityRecipients($recipientsPreExpansion, $user);
        $bodyStepData['recipients']['recipients'] = $this->dedupeEntityRecipientsForDisplay($displayRecipients);

        $propertyAddress = $stepData['property']['address'] ?? $stepData['property']['title'] ?? '';

        // Johan, 2026-08-27 — a deceased party is not a party to the
        // agreement; never name the document after them.
        $firstRecipientName = '';
        foreach ($recipients as $r) {
            if (($r['role'] ?? '') !== 'agent' && empty($r['_is_deceased']) && !empty($r['name'])) {
                $firstRecipientName = $r['name'];
                break;
            }
        }

        $docName = $stepData['document_name'] ?? null;
        if (empty($docName)) {
            $docName = $template->name . ($firstRecipientName ? " — {$firstRecipientName}" : '')
                . ' — ' . now()->format('Y-m-d');
        }

        $signatureService = app(SignatureService::class);

        // Render filled document HTML for web templates (same as download mode)
        $webTemplateData = null;
        if ($renderType === 'web' && $template->blade_view) {
            $webTemplateDataService = app(WebTemplateDataService::class);
            $webTemplateData = $webTemplateDataService->resolve($template->id, $bodyStepData, $user);

            $viewData = $webTemplateData;
            if (!empty($template->signing_parties)) {
                $viewData['signing_parties'] = $template->signing_parties;
                $propSrc = $stepData['property']['_property_source'] ?? null;
                $viewData['document_context'] = $template->isSalesDocument($propSrc) ? 'sales' : 'rental';
            }

            // Signature-block inputs — SIGNING participants only (Johan,
            // 2026-08-26, flow 330 Finding A) — same rule, print path.
            $signingParticipantRecipients = $this->filterToSigningParticipants($recipients);

            $partyNames = [];
            foreach ($signingParticipantRecipients as $r) {
                if (($r['role'] ?? '') === 'agent') continue;
                $partyNames[] = $r['name'] ?? '';
            }
            $partyNames[] = $user->name;
            $viewData['party_names'] = $partyNames;

            $recipientsByRole = [];
            foreach ($signingParticipantRecipients as $r) {
                $role = $r['role'] ?? '';
                $baseRole = preg_replace('/_\d+$/', '', $role);
                $recipientsByRole[$baseRole][] = $r;
            }
            // Always include agent from authenticated user — recipients step doesn't have an agent entry
            $recipientsByRole['agent'] = [['name' => $user->name, 'role' => 'agent', 'email' => $user->email ?? '']];
            $viewData['recipients_by_role'] = $recipientsByRole;

            $fullHtml = view($template->blade_view, $viewData)->render();

            preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $bodyMatch);
            $bodyHtml = $bodyMatch[1] ?? $fullHtml;
            $styles = '';
            if (preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches)) {
                $styles = implode("\n", $styleMatches[0]);
            }

            $bodyHtml = $this->injectFieldValues($bodyHtml, $webTemplateData);

            $otherConditionsText3 = trim($stepData['fill_review']['other_conditions_text'] ?? '');
            if (empty($otherConditionsText3)) {
                $legacyClauses = $stepData['fill_review']['clauses'] ?? [];
                if (!empty($legacyClauses)) {
                    $otherConditionsText3 = implode("\n\n", array_map(fn($c) => $c['text'] ?? $c['content'] ?? '', $legacyClauses));
                }
            }
            // Step 2 (Johan) — skip legacy injection when a marker is present
            // (renderer expands it to rows; injecting here too = double render).
            if (!empty($otherConditionsText3) && ! str_contains($bodyHtml, 'OTHER_CONDITIONS')) {
                $clauseBlocks = array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $otherConditionsText3))));
                $clauseHtml = '<div class="corex-additional-clauses" style="margin-top:16pt;">';
                $clauseHtml .= '<h3 style="font-weight:bold;margin-top:12pt;margin-bottom:8pt;">Additional Conditions</h3>';
                foreach ($clauseBlocks as $idx => $block) {
                    $num = $idx + 1;
                    $clauseHtml .= '<div class="clause-block" data-clause-index="' . $idx . '" style="margin:6pt 0;"><p><strong>' . $num . '.</strong> '
                        . e($block) . '</p></div>';
                }
                $clauseHtml .= '</div>';

                $bodyHtml = $this->insertBeforeSignatureSection($bodyHtml, $clauseHtml);
            }

            $webTemplateData['merged_html'] = $styles . $bodyHtml;
        }

        // Resolve property_id and document_type (same as prepareSigning)
        $resolvedPropertyId = $flow->property_id;
        $propSource = $stepData['property']['_property_source'] ?? 'properties';
        if (!$resolvedPropertyId && $propSource === 'rental_properties' && !empty($stepData['property']['property_id'])) {
            $resolvedPropertyId = $stepData['property']['property_id'];
        }

        $resolvedDocType = $template->template_type;
        if ($template->document_type_id) {
            $template->loadMissing('documentType');
            $dtName = $template->documentType->name ?? '';
            $dtNameMap = [
                'Mandates' => 'mandate', 'OTPs' => 'other', 'Addendums' => 'addendum',
                'Condition Reports' => 'inspection_report', 'FICA' => 'disclosure',
                'Rental Agreements' => 'lease_agreement', 'Other' => 'other',
            ];
            $resolvedDocType = $dtNameMap[$dtName] ?? strtolower(str_replace(' ', '_', $dtName));
        }

        $roleAliases = [
            'landlord' => 'landlord', 'tenant' => 'tenant',
            'buyer' => 'buyer', 'seller' => 'seller',
            'agent' => 'agent', 'witness' => 'witness',
            'spouse' => 'spouse', 'other' => 'other',
        ];

        $result = DB::transaction(function () use ($user, $flow, $template, $fields, $recipients, $signingSetup, $docName, $propertyAddress, $signatureService, $webTemplateData, $resolvedDocType, $resolvedPropertyId, $roleAliases, $stepData) {
            // 1. Create Document
            $document = Document::create([
                'name'             => $docName,
                'template_id'      => $template->id,
                'fields_json'      => $fields,
                'owner_id'         => $user->ownershipUserId(request()->integer('acting_for_user_id') ?: null), // AT-267 / AUDIT 2026-07-26 (F3) — files as the agent
                'branch_id'        => $user->effectiveBranchId(),
                'property_address' => $propertyAddress,
                'property_id'      => $resolvedPropertyId,
                'document_type'    => $resolvedDocType,
                'web_template_data' => $webTemplateData,
            ]);

            // 2. Create SignatureTemplate
            $parties = [
                ['role' => 'agent', 'role_label' => 'agent', 'name' => $user->name, 'email' => $user->email, 'id_number' => ''],
            ];
            $signingOrder = ['agent'];

            // Job 1 (Johan/cc1, 2026-08-26) — same fix as prepareSigning():
            // match on _matched_signing_setup_index (set pre-expansion by
            // attachSigningSetupMatch(), survives expansion), never role+name
            // against the post-expansion array. See prepareSigning() for the
            // full rationale.
            $orderedRecipients = $recipients;
            if (!empty($signingSetup) && !empty($signingSetup[0]['signing_order'] ?? null)) {
                $orderedRecipients = [];
                $usedRecipientKeys = [];
                foreach ($signingSetup as $ssIndex => $ss) {
                    if (($ss['role'] ?? '') === 'agent') continue;
                    $matchedAny = false;
                    foreach ($recipients as $rKey => $r) {
                        if (($r['_matched_signing_setup_index'] ?? null) === $ssIndex) {
                            $orderedRecipients[] = $r;
                            $usedRecipientKeys[$rKey] = true;
                            $matchedAny = true;
                        }
                    }
                    if (! $matchedAny) {
                        $name = trim((string) ($ss['name'] ?? '')) ?: 'This party';
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'recipients' => "The signing order lists {$name} but that party did not survive representative expansion. Check for a data-entry mismatch before re-sending.",
                        ]);
                    }
                }
                foreach ($recipients as $rKey => $r) {
                    if (empty($usedRecipientKeys[$rKey])) {
                        $orderedRecipients[] = $r;
                    }
                }
            }

            $roleCounts = [];
            $recipientPartyKeys = [];
            foreach ($orderedRecipients as $i => $r) {
                $baseRole = $roleAliases[$r['role'] ?? 'other'] ?? ($r['role'] ?? 'other');
                if ($baseRole === 'agent') continue;

                if (!isset($roleCounts[$baseRole])) {
                    $roleCounts[$baseRole] = 1;
                    $partyKey = $baseRole;
                } else {
                    $roleCounts[$baseRole]++;
                    $partyKey = $baseRole . '_' . $roleCounts[$baseRole];
                }

                $recipientPartyKeys[$i] = $partyKey;
                $parties[] = [
                    'role'       => $partyKey,
                    'role_label' => $baseRole,
                    'name'       => $r['name'] ?? '',
                    'email'      => $r['email'] ?? '',
                    'id_number'  => $r['id_number'] ?? '',
                ];
                $signingOrder[] = $partyKey;
            }

            $documentHash = hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $sigTemplate = SignatureTemplate::create([
                'document_id'         => $document->id,
                'document_hash'       => $documentHash,
                'status'              => SignatureTemplate::STATUS_READY,
                'parties_json'        => $parties,
                'signing_order_json'  => $signingOrder,
                'created_by'          => $user->id,
                'sections_json'       => $template->sections,
                'other_conditions_text' => trim($stepData['fill_review']['other_conditions_text'] ?? '') ?: null,
            ]);

            // Phase 1B.5 / Step 2 (Johan) — persist to structured
            // document_conditions rows; prefer discrete frames (one row per
            // condition + clause-library provenance), fall back to the legacy
            // text bridge when no frames were submitted.
            try {
                $frames = $stepData['fill_review']['other_condition_frames'] ?? [];
                if (is_array($frames) && $frames !== []) {
                    app(\App\Services\Docuperfect\LegacyOtherConditionsBridge::class)
                        ->syncFramesToStructuredRows($sigTemplate, $frames);
                } else {
                    app(\App\Services\Docuperfect\LegacyOtherConditionsBridge::class)
                        ->syncToStructuredRows($sigTemplate);
                }
            } catch (\Throwable $e) {
                \Log::warning('LegacyOtherConditionsBridge sync failed (wet-ink path)', [
                    'sig_template_id' => $sigTemplate->id,
                    'error'           => $e->getMessage(),
                ]);
            }

            // 3. Create SignatureRequests with signing_method = 'wet_ink'
            $agentReq = $signatureService->createSigningRequest(
                $sigTemplate, 'agent', $user->name, $user->email, null, null, $user
            );
            $agentReq->update([
                'signing_method' => 'wet_ink',
                'status' => \App\Models\Docuperfect\SignatureRequest::STATUS_PENDING,
                'sent_at' => now(),
            ]);

            // "Replace this party" chain bindings (Johan, 2026-08-25) — same
            // mechanism and same reason as prepareSigning(): a deceased
            // party's clause chains to another recipient in this same batch,
            // whose recipient_local_key only exists once THAT recipient's
            // own createSigningRequest() call below has run.
            $chainBindings = [];

            foreach ($orderedRecipients as $i => $r) {
                $baseRole = $roleAliases[$r['role'] ?? 'other'] ?? ($r['role'] ?? 'other');
                if ($baseRole === 'agent') continue;
                $partyKey = $recipientPartyKeys[$i] ?? $baseRole;

                // Job 1 (Johan/cc1, 2026-08-26) — see prepareSigning() for
                // the full rationale: read the pre-expansion-matched index,
                // never re-match by role+name post-expansion.
                $matchedSetup = isset($r['_matched_signing_setup_index'])
                    ? ($signingSetup[$r['_matched_signing_setup_index']] ?? null)
                    : null;
                $skipEmail = !empty($matchedSetup['skipEmail'] ?? false);
                $email = $matchedSetup['email'] ?? $r['email'] ?? '';
                // Johan, 2026-08-26 — the printed document's notices clause
                // (address, phone) only ever reaches the page through the
                // linked Contact (RoleBlockExpansionService::resolveContact(),
                // gated on SignatureRequest.contact_id — SignatureRequest
                // itself has no address/phone columns of its own). This call
                // never passed it, so every wet-ink signing row was created
                // with contact_id NULL, for every recipient, and the notices
                // clause rendered blank regardless of what was typed in the
                // wizard. Same one-liner prepareSigning() already uses
                // (~line 2757) — the reference implementation, unchanged.
                $contactId = !empty($r['_contact_id']) ? (int) $r['_contact_id'] : null;

                $sigReq = $signatureService->createSigningRequest(
                    $sigTemplate, $partyKey, $r['name'] ?? '', $skipEmail ? '' : $email,
                    $r['id_number'] ?? null, null, $user,
                    contactId: $contactId,
                    signerCaption: $r['_signature_caption'] ?? null,
                    partyClauseText: $r['_party_clause_text'] ?? null,
                    isDeceased: (bool) ($r['_is_deceased'] ?? false),
                    isProxy: (bool) ($r['_is_proxy'] ?? false),
                    recipientLocalKey: $r['_recipient_local_key'] ?? null,
                    representedContactId: isset($r['_entity_contact_id']) ? (int) $r['_entity_contact_id'] : null,
                    // Johan, 2026-08-28 — see prepareSigning()'s identical fix.
                    signerPhone: $r['cell'] ?? null,
                    signerAddress: $r['address'] ?? null,
                );
                $sigReq->update(['signing_method' => 'wet_ink']);
                $this->stampSupplierFirmIfAny($sigReq, $r);

                if (! empty($r['_recipient_template_id']) && ! empty($r['_slot_bindings'])) {
                    $chainBindings[] = [
                        'signature_request_id' => $sigReq->id,
                        'recipient_template_id' => (int) $r['_recipient_template_id'],
                        'slot_bindings' => $r['_slot_bindings'],
                    ];
                }
            }

            // Printed output names every party in full exactly as the e-sign
            // body does (Johan, 2026-08-25) — resolves "Late Estate of {X}
            // herein represented by {Y}" onto party_clause_text before this
            // document is ever handed over to be signed on paper.
            $this->resolveChainBindings($chainBindings, $user->id);

            // Johan, 2026-08-28 — see prepareSigning()'s identical fix: compose
            // the canonical body ONCE, now, while every SignatureRequest row is
            // freshly and consistently created. No-op (fail-safe) for a
            // document with no composable web body.
            app(\App\Services\Docuperfect\CanonicalDocumentRenderer::class)->composeAndStore($sigTemplate);

            // No markers or zones needed — wet ink is signed on paper

            // 4. Link document to flow
            $flowStepData = $flow->step_data ?? [];
            $flowStepData['document_id'] = $document->id;
            $flowStepData['signature_template_id'] = $sigTemplate->id;
            $flowStepData['delivery_mode'] = 'wet_ink';
            $flow->update([
                'step_data' => $flowStepData,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            return ['document' => $document, 'sigTemplate' => $sigTemplate];
        });

        return redirect()->route('docuperfect.esign.wetInkConfirmation', $flow->id)
            ->with('success', 'Document created for wet-ink signing.');
    }

    /**
     * Show filled document in a print-friendly view for download mode.
     */
    public function downloadDocument(Request $request, $documentId)
    {
        $user = $request->user();
        // AT-267 / AUDIT 2026-07-26 (F3) — documents an assistant builds are owned by the AGENT,
        // so an owner_id === self lookup 404s the assistant on their own work. dataIdentityIds()
        // is [self] for everyone else, so this is a no-op outside the assistant case.
        $document = Document::whereIn('owner_id', $user->dataIdentityIds())->findOrFail($documentId);
        $document->load('template');

        $mergedHtml = $document->web_template_data['merged_html'] ?? null;

        return view('docuperfect.esign.download', [
            'document' => $document,
            'template' => $document->template,
            'mergedHtml' => $mergedHtml,
        ]);
    }

    /**
     * Generate and download a PDF for a download-only document.
     * Uses SigningController::generatePdfFromHtml() for consistent rendering.
     */
    public function downloadDocumentPdf(Request $request, $documentId)
    {
        set_time_limit(120);

        $user = $request->user();
        $document = Document::whereIn('owner_id', $user->dataIdentityIds())->findOrFail($documentId); // AT-267 / AUDIT 2026-07-26 (F3)
        $mergedHtml = $document->web_template_data['merged_html'] ?? '';

        if (empty($mergedHtml)) {
            abort(404, 'Document content not available for PDF generation.');
        }

        $signingController = app(SigningController::class);
        $outputPath = $signingController->generatePdfFromHtml($mergedHtml, $document->id);

        if (!$outputPath || !file_exists($outputPath) || filesize($outputPath) === 0) {
            @unlink($outputPath);
            abort(500, 'PDF generation failed.');
        }

        $docName = $document->name ?? 'Document';
        $safeDocName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $docName);
        $filename = $safeDocName . '_' . date('Y-m-d') . '.pdf';

        return response()->download($outputPath, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Show wet-ink confirmation page with print/download instructions.
     */
    public function wetInkConfirmation(Request $request, $flowId)
    {
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);
        $flow->load('template');

        $stepData = $flow->step_data ?? [];
        $documentId = $stepData['document_id'] ?? null;
        $document = $documentId ? Document::with('signatureTemplate.requests')->find($documentId) : null;

        $mergedHtml = $document ? ($document->web_template_data['merged_html'] ?? null) : null;

        // Find the agent's signature request and all recipients
        $agentRequest = null;
        $recipientRequests = collect();
        $sigTemplate = null;
        if ($document && $document->signatureTemplate) {
            $sigTemplate = $document->signatureTemplate;
            $allRequests = $sigTemplate->requests;
            $agentRequest = $allRequests->where('party_role', 'agent')->first();
            $recipientRequests = $allRequests->where('party_role', '!=', 'agent')->values();
        }

        // Determine current state
        // 1 = download & sign, 2 = upload, 3 = approve & send, 4 = awaiting recipient, 5 = review recipient, 6 = complete
        $state = 1;
        if ($agentRequest) {
            if ($agentRequest->status === SignatureRequest::STATUS_COMPLETED) {
                // Agent done — check recipient status
                $pendingRecipient = $recipientRequests->first(fn($r) => in_array($r->wet_ink_status, [
                    SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW,
                ]));
                $completedAll = $recipientRequests->every(fn($r) => $r->status === SignatureRequest::STATUS_COMPLETED);

                if ($completedAll && $recipientRequests->isNotEmpty()) {
                    $state = 6; // All done
                } elseif ($pendingRecipient) {
                    $state = 5; // Review recipient upload
                } else {
                    $state = 4; // Awaiting recipient
                }
            } elseif ($agentRequest->wet_ink_status === SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW) {
                $state = 3; // Uploaded, ready to approve & send
            } elseif ($agentRequest->wet_ink_upload_path && json_decode($agentRequest->wet_ink_upload_path, true)) {
                $state = 3;
            } else {
                $state = 1; // Download & sign
            }
        }

        return view('docuperfect.esign.wet-ink-confirmation', [
            'flow' => $flow,
            'template' => $flow->template,
            'document' => $document,
            'mergedHtml' => $mergedHtml,
            'agentRequest' => $agentRequest,
            'sigTemplate' => $sigTemplate,
            'recipientRequests' => $recipientRequests,
            'state' => $state,
        ]);
    }

    /**
     * Agent uploads their signed wet-ink document.
     * Auth-gated, no token/session verification needed.
     */
    public function wetInkAgentUpload(Request $request, $documentId)
    {
        $user = $request->user();
        $document = Document::whereIn('owner_id', $user->dataIdentityIds()) // AT-267 / AUDIT 2026-07-26 (F3)
            ->with('signatureTemplate.requests')
            ->findOrFail($documentId);

        $request->validate([
            'files'   => 'required|array|min:1',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:20480',
        ]);

        $sigTemplate = $document->signatureTemplate;
        $agentRequest = $sigTemplate?->requests->where('party_role', 'agent')->first();

        if (!$agentRequest) {
            return back()->with('error', 'No agent signing request found.');
        }

        $paths = [];
        foreach ($request->file('files') as $file) {
            $paths[] = $file->store("docuperfect/wet-ink-uploads/{$agentRequest->id}", 'local');
        }

        $agentRequest->update([
            'signing_method'      => 'wet_ink',
            'wet_ink_upload_path' => json_encode($paths),
            'wet_ink_status'      => SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW,
        ]);

        \App\Models\Docuperfect\SignatureAuditLog::log(
            $sigTemplate,
            \App\Models\Docuperfect\SignatureAuditLog::ACTION_WET_INK_UPLOADED,
            \App\Models\Docuperfect\SignatureAuditLog::ACTOR_USER,
            $user->name,
            $user->email,
            $user->id,
            $agentRequest->id,
            $request->ip(),
            $request->userAgent(),
            ['file_count' => count($paths), 'agent_self_upload' => true],
        );

        // Create version records
        foreach ($paths as $path) {
            \App\Models\Docuperfect\SignedDocumentVersion::create([
                'document_id'          => $document->id,
                'signature_request_id' => $agentRequest->id,
                'version_number'       => \App\Models\Docuperfect\SignedDocumentVersion::nextVersion($document->id),
                'file_path'            => $path,
                'file_type'            => pathinfo($path, PATHINFO_EXTENSION),
                'uploaded_by_name'     => $user->name,
                'uploaded_at'          => now(),
                'ip_address'           => $request->ip(),
            ]);
        }

        return back()->with('status', 'Signed document uploaded. Review and send to recipient.');
    }

    /**
     * Agent approves their own wet-ink upload and advances to the next party.
     * Uses the same logic as SignatureService::approveUploadOnBehalf.
     */
    public function wetInkAgentApprove(Request $request, $documentId)
    {
        $user = $request->user();
        $document = Document::whereIn('owner_id', $user->dataIdentityIds()) // AT-267 / AUDIT 2026-07-26 (F3)
            ->with('signatureTemplate.requests')
            ->findOrFail($documentId);

        $sigTemplate = $document->signatureTemplate;
        $agentRequest = $sigTemplate?->requests->where('party_role', 'agent')->first();

        if (!$agentRequest || !$agentRequest->wet_ink_upload_path) {
            return back()->with('error', 'No uploaded document to approve.');
        }

        $signatureService = app(\App\Services\Docuperfect\SignatureService::class);
        $signatureService->approveUploadOnBehalf($agentRequest, $user);

        return back()->with('status', 'Approved and sent to recipient for signing.');
    }

    /**
     * My E-Sign Documents — dashboard with grouped status sections (mirrors rental signatures page).
     */
    public function myDocuments(Request $request)
    {
        $user = $request->user();

        // All e-sign documents for this user (rental exclusion removed — all document types shown)
        $allTemplates = SignatureTemplate::with(['document.template', 'requests', 'creator'])
            ->where('created_by', $user->id)
            ->whereHas('document')
            ->orderByDesc('created_at')
            ->get();

        // Awaiting statuses (external parties signing)
        $awaitingStatuses = [
            SignatureTemplate::STATUS_SIGNING,
            SignatureTemplate::STATUS_AWAITING_TENANT,
            SignatureTemplate::STATUS_AWAITING_LANDLORD,
            SignatureTemplate::STATUS_AWAITING_BUYER,
            SignatureTemplate::STATUS_AWAITING_SELLER,
            SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
            SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
            SignatureTemplate::STATUS_AWAITING_DEFERRED,
            // AT-373 (re-circulation surfacing) — after the agent APPROVES a recipient's amendment the
            // document is sent BACK to a prior recipient to re-initial the change (amendment_initialing), or
            // — if a chain node rejected the amendment — to the editor to re-accept (editor_reacceptance).
            // Both are genuinely OUT WITH A PARTY (a recipient holds the pen), yet neither was in ANY bucket
            // — same orphaned-state defect class as the AT-299 flagged-doc and BUG-2 returned-doc gaps above
            // — so the doc VANISHED from My E-Sign Documents the moment it re-circulated, and the agent lost
            // all visibility of an outstanding flow. Surface them as Awaiting Signatures (in-progress): the
            // per-party progress render already shows the re-activated recipient holding it. They leave
            // "outstanding" only on genuine completion (STATUS_COMPLETED) or cancellation.
            SignatureTemplate::STATUS_AMENDMENT_INITIALING,
            SignatureTemplate::STATUS_EDITOR_REACCEPTANCE,
        ];

        // Group templates by status category
        $groups = [
            // AT-299 — a document frozen by a recipient's clause flag
            // (STATUS_AMENDMENT_REVIEW) was in NO bucket, so it fell out of the
            // list entirely and the agent could not see the frozen ceremony.
            // Surface it FIRST as "FLAGGED — review required".
            'flagged'          => $allTemplates->where('status', SignatureTemplate::STATUS_AMENDMENT_REVIEW)
                ->each(function ($tpl) {
                    // AT-300 — attach the pending flag amendment so the list CTA
                    // deep-links to the FLAG-RESOLVE view (AmendmentController::review).
                    // The doc-level signatures.review REJECTS an AMENDMENT_REVIEW
                    // status (redirects "not pending approval") — that is why the
                    // Review Flag button did nothing.
                    $tpl->flag_amendment_id = \App\Models\Docuperfect\DocumentAmendment::query()
                        ->where('signature_template_id', $tpl->id)
                        ->where('amendment_type', \App\Models\Docuperfect\DocumentAmendment::TYPE_FLAG_RAISED)
                        ->where('status', \App\Models\Docuperfect\DocumentAmendment::STATUS_PENDING)
                        ->latest('id')->value('id');
                })
                ->values(),
            // BUG 2 (Johan 2026-08-04) — a candidate-flow doc SENT BACK by the authoriser
            // (STATUS_RETURNED_TO_CANDIDATE) was in NO bucket, so it fell out of the list
            // entirely and the candidate could not find their own returned document to fix.
            // Same defect class as the AT-299 flagged-doc gap. Surface it FIRST as
            // "Returned — needs fixing", deep-linking to the sign screen to fix + re-sign.
            'returned'         => $allTemplates->where('status', SignatureTemplate::STATUS_RETURNED_TO_CANDIDATE)->values(),
            // AT-373 (Issue C surfacing) — a recipient's amendment RETURNED to the agent for approval
            // (STATUS_AMENDMENT_CHAIN_REVIEW) was in NO bucket, so — exactly like the AT-299 flagged-doc
            // gap above — it fell out of the list entirely: the agent had no entry point to see or approve
            // it while the ceremony sat held. Surface it FIRST as an actionable "Amendment approval"
            // bucket (Review & Approve deep-links to the agent amendment-approval surface). Only the docs
            // whose CURRENT chain node is the agent/prep (the creator's turn) belong here; a candidate
            // flow whose current node is a supervisor surfaces in Needs Authorisation below.
            'amendment_approval' => $allTemplates->where('status', SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW)
                ->each(function ($tpl) {
                    $tpl->amendment_node_role = optional(
                        app(SignatureService::class)->currentAmendmentChainNode($tpl)
                    )->party_role;
                })
                ->filter(fn ($tpl) => ($tpl->amendment_node_role ?? 'agent') === 'agent')
                ->values(),
            'pending_approval' => $allTemplates->where('status', SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL)->values(),
            'draft'            => $allTemplates->where('status', SignatureTemplate::STATUS_DRAFT)->values(),
            'ready_to_sign'    => $allTemplates->where('status', SignatureTemplate::STATUS_READY)->values(),
            'awaiting'         => $allTemplates->whereIn('status', $awaitingStatuses)->values(),
            'completed'        => $allTemplates->where('status', SignatureTemplate::STATUS_COMPLETED)->values(),
            'cancelled'        => $allTemplates->where('status', SignatureTemplate::STATUS_CANCELLED)->values(),
        ];

        // Candidate documents needing authorisation (shared queue for full-status users)
        $candidateService = new \App\Services\CandidatePractitionerService();
        $needsAuthorisation = collect();

        if ($candidateService->canAuthorise($user)) {
            $needsAuthorisation = SignatureTemplate::with(['document.template', 'requests', 'creator'])
                ->where('is_candidate_flow', true)
                ->whereIn('status', [
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
                    SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
                    // AT-373 — a candidate-flow recipient amendment whose CURRENT chain node is a
                    // supervisor authoriser belongs in the authoriser queue too (the agent-node docs
                    // surface on the creator's Amendment approval bucket above).
                    SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW,
                ])
                ->orderByDesc('created_at')
                ->get()
                ->filter(function ($tpl) {
                    if ($tpl->status !== SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW) {
                        return true;
                    }
                    return app(SignatureService::class)->isAuthoriserRole(
                        optional(app(SignatureService::class)->currentAmendmentChainNode($tpl))->party_role
                    );
                })
                ->values();
        }

        $groups['needs_authorisation'] = $needsAuthorisation;

        // Recipient supporting-document uploads (SignedDocumentVersion kind='supporting') —
        // the optional docs recipients attach on the signing screen. Surface them to the
        // agent HERE (badge on the doc + a dedicated section), not just in the audit log.
        $docIds = $allTemplates->pluck('document.id')->filter()->values();
        $supporting = collect();
        if ($docIds->isNotEmpty()) {
            $supporting = \App\Models\Docuperfect\SignedDocumentVersion::whereIn('document_id', $docIds->all())
                ->where('kind', \App\Models\Docuperfect\SignedDocumentVersion::KIND_SUPPORTING)
                ->orderByDesc('uploaded_at')
                ->orderByDesc('id')
                ->get();
        }
        $templatesByDocId = $allTemplates->keyBy(fn ($t) => $t->document?->id);
        // Badge counts only UNFILED uploads (the ones still needing to be worked / filed).
        $supportingByDoc = $supporting->whereNull('filed_at')->groupBy('document_id');

        // BATCH BY RECIPIENT (Johan item 5) — one row per signing request. FILED state (Part A): a
        // batch is "filed" once EVERY upload in it carries filed_at; those drop off the working
        // "to file" list into the "Filed additional docs" archive. A later re-upload (an unfiled
        // version on a filed request) flips the whole batch back to "to file" until re-filed.
        // Part B — resolve the property prefill once per batch so "Send to splitter" hands the
        // splitter what CoreX already knows (property_id + the batch's version ids).
        $prefillResolver = app(\App\Services\Docuperfect\SupportingBatchPrefillResolver::class);
        // Split PER-DOCUMENT by filed state (not all-or-nothing per request): the splitter may file
        // only SOME of a batch (its qpdf page-count check skips a PDF), so the docs it DID file must
        // still move to "Filed additional docs" — only the not-yet-filed stragglers stay "to file".
        $buildBatch = function ($versions, $requestId, bool $isFiled) use ($templatesByDocId, $prefillResolver) {
            $first = $versions->first();
            $tpl = $templatesByDocId->get($first->document_id);
            if (! $tpl || ! $tpl->document) {
                return null;
            }
            $prefill = $prefillResolver->forDocument($tpl->document);
            return (object) [
                'request_id'   => (int) $requestId,
                'document'     => $tpl->document,
                'template'     => $tpl,
                'signer_name'  => $first->uploaded_by_name ?: 'Recipient',
                'count'        => $versions->count(),
                'latest_at'    => $versions->pluck('uploaded_at')->filter()->max(),
                'filed_at'     => $isFiled ? $versions->pluck('filed_at')->filter()->max() : null,
                // Splitter hand-off + view/download scope: this row's own version ids.
                'version_ids'  => $versions->pluck('id')->all(),
                'prefill_property_id' => $prefill['property_id'] ?? null,
            ];
        };
        $withRequest = $supporting->filter(fn ($v) => $v->signature_request_id !== null);
        $supportingToFile = $withRequest->whereNull('filed_at')
            ->groupBy('signature_request_id')
            ->map(fn ($versions, $rid) => $buildBatch($versions, $rid, false))
            ->filter()->sortByDesc('latest_at')->values();
        $supportingFiled = $withRequest->whereNotNull('filed_at')
            ->groupBy('signature_request_id')
            ->map(fn ($versions, $rid) => $buildBatch($versions, $rid, true))
            ->filter()->sortByDesc('filed_at')->values();

        $counts = [
            'flagged'             => $groups['flagged']->count(), // AT-299
            'returned'            => $groups['returned']->count(), // BUG 2 — returned-to-candidate
            'amendment_approval'  => $groups['amendment_approval']->count(), // AT-373 — recipient amendment returned to agent
            'needs_authorisation' => $groups['needs_authorisation']->count(),
            'pending_approval'    => $groups['pending_approval']->count(),
            'draft'               => $groups['draft']->count(),
            'ready_to_sign'       => $groups['ready_to_sign']->count(),
            'awaiting_signatures' => $groups['awaiting']->count(),
            'completed'           => $groups['completed']->count(),
            'cancelled'           => $groups['cancelled']->count(),
        ];

        return view('docuperfect.esign.my-documents', [
            'groups' => $groups,
            'counts' => $counts,
            'user'   => $user,
            'showOnlyAuthorisation' => $request->query('filter') === 'authorisation',
            'supportingByDoc'   => $supportingByDoc,
            'supportingToFile'  => $supportingToFile,
            'supportingFiled'   => $supportingFiled,
        ]);
    }

    /**
     * Cancel / void an e-sign document — sets template + all pending requests to cancelled.
     * Requires a cancellation reason. Notifies all waiting/pending parties.
     */
    public function cancelDocument(Request $request, SignatureTemplate $signatureTemplate)
    {
        $user = $request->user();

        // Only the creator can cancel
        if ((int) $signatureTemplate->created_by !== (int) $user->id) {
            return back()->withErrors(['You do not have permission to cancel this document.']);
        }

        // Cannot cancel already completed or already cancelled docs
        if (in_array($signatureTemplate->status, [
            SignatureTemplate::STATUS_COMPLETED,
            SignatureTemplate::STATUS_CANCELLED,
        ])) {
            return back()->withErrors(['This document cannot be cancelled — it is already ' . $signatureTemplate->status . '.']);
        }

        $request->validate([
            'cancellation_reason' => 'required|string|min:3|max:1000',
        ]);

        $reason = $request->input('cancellation_reason');

        // Collect pending requests BEFORE cancelling (for notification)
        $pendingRequests = $signatureTemplate->requests()
            ->whereIn('status', ['waiting', 'pending', 'viewed', 'partially_signed'])
            ->get();

        DB::transaction(function () use ($signatureTemplate, $user, $request, $reason) {
            // Cancel all pending/waiting signature requests
            $signatureTemplate->requests()
                ->whereIn('status', ['waiting', 'pending', 'viewed', 'partially_signed'])
                ->update(['status' => 'cancelled']);

            // Set template status to cancelled with reason
            $signatureTemplate->update([
                'status' => SignatureTemplate::STATUS_CANCELLED,
                'cancellation_reason' => $reason,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ]);

            // Audit log
            SignatureAuditLog::log(
                $signatureTemplate,
                SignatureAuditLog::ACTION_CANCELLED,
                SignatureAuditLog::ACTOR_USER,
                $user->name,
                $user->email,
                $user->id,
                null,
                $request->ip(),
                $request->userAgent(),
                ['reason' => $reason]
            );
        });

        // Notify all pending/waiting parties of the cancellation
        $documentName = $signatureTemplate->document->name ?? 'Untitled';
        foreach ($pendingRequests as $sigReq) {
            if (!empty($sigReq->signer_email)) {
                try {
                    \Illuminate\Support\Facades\Mail::to($sigReq->signer_email)->send(
                        (new \App\Mail\Signatures\DocumentCancelledMail(
                            signerName: $sigReq->signer_name ?? 'Signer',
                            documentName: $documentName,
                            agentName: $user->name,
                            cancellationReason: $reason,
                        ))->fromAgent($user)
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to send cancellation email', [
                        'request_id' => $sigReq->id,
                        'signer_email' => $sigReq->signer_email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return back()->with('status', 'Document "' . $documentName . '" has been cancelled. ' . $pendingRequests->count() . ' waiting parties notified.');
    }
}
