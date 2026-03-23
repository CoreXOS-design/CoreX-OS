<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\Pack;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Property;
use App\Models\Rental\RentalProperty;
use App\Services\CandidatePractitionerService;
use App\Services\Docuperfect\SignatureService;

use App\Services\WebTemplateDataService;
use Illuminate\Http\Request;

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
        ]);
    }

    /**
     * Create a new flow from step 1 and redirect to step 2.
     */
    public function store(Request $request)
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
            // Web Pack flow — merge multiple templates
            $pack = \App\Models\Docuperfect\WebPack::with('items.template')
                ->findOrFail($packId);

            // Use resolved template IDs if provided (slot selection)
            $resolvedIds = $request->input('resolved_template_ids');
            if (!empty($resolvedIds) && is_array($resolvedIds)) {
                // Filter and order items by the resolved selection
                $templates = collect($resolvedIds)
                    ->map(fn($id) => Template::find($id))
                    ->filter();
            } else {
                $templates = $pack->items->sortBy('sort_order')
                    ->map(fn($item) => $item->template)
                    ->filter(); // remove any null templates
            }

            if ($templates->isEmpty()) {
                return response()->json(['error' => 'This web pack has no templates.'], 422);
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

        // Recipients from step data — handle double-nested structure
        // Must run BEFORE autoFillFields so contact-sourced fields can resolve
        $recipientsData = $stepData['recipients'] ?? [];
        $recipients = isset($recipientsData['recipients']) && is_array($recipientsData['recipients'])
            ? $recipientsData['recipients']
            : (is_array($recipientsData) && !empty($recipientsData) && isset($recipientsData[0]) ? $recipientsData : []);

        // Auto-populate linked contacts from property if no non-agent recipients exist
        $hasNonAgent = collect($recipients)->contains(fn($r) => ($r['role'] ?? '') !== 'agent');
        if (!$hasNonAgent && $step >= 3) {
            $propertyId = $stepData['property']['property_id'] ?? null;
            $propertySource = $stepData['property']['_property_source'] ?? null;
            if ($propertyId && $propertySource === 'properties') {
                $prop = Property::with(['contacts' => fn($q) => $q->withPivot('role')])->find($propertyId);
                if ($prop) {
                    // Determine correct fallback role based on template type
                    $defaultOwnerRole = $template->isSalesDocument() ? 'seller' : 'landlord';

                    // Agent is always first recipient (added by JS), so just add linked contacts
                    foreach ($prop->contacts as $contact) {
                        $pivotRole = $contact->pivot->role ?? $defaultOwnerRole;
                        // Map generic roles to match template field prefixes
                        if ($template->isSalesDocument() && in_array($pivotRole, ['landlord', 'owner', 'lessor'])) {
                            $pivotRole = 'seller';
                        }

                        $recipients[] = [
                            'order'       => count($recipients) + 1,
                            'role'        => $pivotRole,
                            'name'        => $contact->first_name . ' ' . $contact->last_name,
                            'first_name'  => $contact->first_name ?? '',
                            'last_name'   => $contact->last_name ?? '',
                            'id_number'   => $contact->id_number ?? '',
                            'email'       => $contact->email ?? '',
                            'cell'        => $contact->phone ?? '',
                            'address'     => $contact->address ?? '',
                            '_contact_id' => $contact->id,
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
        }

        // Update stepData recipients so autoFillFields can see auto-populated contacts
        if (!empty($recipients)) {
            $stepData['recipients'] = ['recipients' => $recipients];
        }

        // Auto-fill fields from wizard step data (property, recipients, details)
        // Contact fields with multiple contacts of the same role (e.g., 2 lessors)
        // are concatenated with ' & ' (e.g., "Koos Kombuis & Lienkie Kombuis")
        $fields = $this->autoFillFields($fields, $stepData);

        // Pre-fill field values from WebTemplateDataService (resolved from step_data)
        $resolvedValues = [];
        if ($template && ($template->render_type ?? 'pdf') === 'web') {
            $resolvedValues = app(WebTemplateDataService::class)
                ->resolve($template->id, $stepData, $request->user());
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
        $allWizardFields = $this->autoFillFieldGroupDisplays($allWizardFields, $stepData);

        return view('docuperfect.esign.wizard', [
            'flow'           => $flow,
            'step'           => $step,
            'template'       => $template,
            'fields'         => $fields,
            'creatorFields'  => $creatorFields,
            'signerFields'   => $signerFields,
            'allWizardFields' => $allWizardFields,
            'pageImages'     => $pageImages,
            'recipients'     => $recipients,
            'stepData'       => $stepData,
            'templates'      => $templates,
            'documentTypes'  => $documentTypes,
            'drafts'         => collect(),
            'currentStep'    => $step,
            'isWebTemplate'  => ($template->render_type ?? 'pdf') === 'web',
            'templateId'     => $flow->template_id,
            'flowId'         => $flow->id,
            'manualFields'   => $manualFields,
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
        $stepData[$stepKey] = $data;

        // For step 5 (fill_review): merge field values and party overrides back into the main fields array
        if ($stepKey === 'fill_review') {
            $fields = $stepData['fields'] ?? [];

            if (!empty($data['fieldValues'])) {
                foreach ($data['fieldValues'] as $fieldId => $value) {
                    foreach ($fields as &$field) {
                        if (($field['id'] ?? null) == $fieldId) {
                            $field['value'] = $value;
                            break;
                        }
                    }
                    unset($field);
                }
            }

            if (!empty($data['partyOverrides'])) {
                foreach ($data['partyOverrides'] as $fieldId => $party) {
                    foreach ($fields as &$field) {
                        if (($field['id'] ?? null) == $fieldId) {
                            $field['assignedTo'] = $party;
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
        if ($stepKey === 'recipients' && !empty($data['recipients'])) {
            // Link first non-agent recipient's contact_id
            foreach ($data['recipients'] as $r) {
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
                    foreach ($fields as &$field) {
                        if (($field['id'] ?? null) == $fieldId) {
                            $field['value'] = $value;
                            break;
                        }
                    }
                    unset($field);
                }
            }

            if (!empty($data['partyOverrides'])) {
                foreach ($data['partyOverrides'] as $fieldId => $party) {
                    foreach ($fields as &$field) {
                        if (($field['id'] ?? null) == $fieldId) {
                            $field['assignedTo'] = $party;
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

        // 1. Search main properties table
        $properties = Property::where(function ($query) use ($q) {
            $query->where('address', 'like', "%{$q}%")
                ->orWhere('suburb', 'like', "%{$q}%")
                ->orWhere('title', 'like', "%{$q}%")
                ->orWhere('property_number', 'like', "%{$q}%")
                ->orWhere('complex_name', 'like', "%{$q}%");
        })
            ->limit(10)
            ->get();

        foreach ($properties as $p) {
            // Get linked contacts (lessor/landlord) via pivot
            $lessor = $p->contacts()
                ->wherePivot('role', 'lessor')
                ->orWherePivot('role', 'landlord')
                ->first();

            $results[] = [
                'id'                => $p->id,
                'source'            => 'properties',
                'address'           => $p->address ?: $p->title,
                'suburb'            => $p->suburb ?? '',
                'erf_no'            => $p->property_number ?? '',
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
                'display'           => trim(($p->address ?: $p->title) . ', ' . ($p->suburb ?? ''), ', '),
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
            // Avoid duplicating if already found in properties by address match
            $results[] = [
                'id'                => $rp->id,
                'source'            => 'rental_properties',
                'address'           => $rp->full_address ?: $rp->address_line_1,
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
                'display'           => $rp->full_address ?: $rp->address_line_1,
            ];
        }

        return response()->json(array_slice($results, 0, 10));
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

        $query = Contact::where(function ($qb) use ($q) {
            $qb->where('first_name', 'like', "%{$q}%")
                ->orWhere('last_name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('id_number', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%");
        });

        // Filter by contact type role if provided
        $role = $request->input('role');
        if ($role) {
            $roleMap = [
                'landlord' => 'Lessor', 'lessor' => 'Lessor',
                'tenant' => 'Lessee', 'lessee' => 'Lessee',
                'buyer' => 'Buyer', 'seller' => 'Seller',
                'witness' => 'Witness', 'spouse' => 'Spouse',
            ];
            $typeName = $roleMap[strtolower($role)] ?? null;
            if ($typeName) {
                $typeId = DB::table('contact_types')->where('name', $typeName)->value('id');
                if ($typeId) {
                    $query->where('contact_type_id', $typeId);
                }
            }
        }

        $contacts = $query->with('type')->limit(10)->get();

        return response()->json($contacts->map(function ($c) {
            return [
                'id'                  => $c->id,
                'first_name'          => $c->first_name,
                'last_name'           => $c->last_name,
                'full_name'           => $c->first_name . ' ' . $c->last_name,
                'email'               => $c->email ?? '',
                'phone'               => $c->phone ?? '',
                'id_number'           => $c->id_number ?? '',
                'address'             => $c->address ?? '',
                'contact_type'        => $c->type?->name ?? '',
                'bank_name'           => $c->bank_name ?? '',
                'bank_account_name'   => $c->bank_account_name ?? '',
                'bank_account_number' => $c->bank_account_number ?? '',
                'bank_branch_name'    => $c->bank_branch_name ?? '',
                'bank_branch_code'    => $c->bank_branch_code ?? '',
                'bank_account_type'   => $c->bank_account_type ?? '',
            ];
        }));
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
                        $tplData['document_context'] = $tpl->isSalesDocument() ? 'sales' : 'rental';
                    }
                    $html = view($tpl->blade_view, $tplData)->render();
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

                // Overlay fill_review field values (field_id → field_name → blade variable)
                $frValues = $stepData['fill_review']['fieldValues'] ?? [];
                if (!empty($frValues)) {
                    $fieldsJson = $stepData['fields'] ?? ($template->fields_json ?? []);
                    foreach ($fieldsJson as $field) {
                        $fieldId = $field['id'] ?? null;
                        $fieldName = $field['field_name'] ?? null;
                        if ($fieldId && $fieldName && isset($frValues[$fieldId]) && $frValues[$fieldId] !== '') {
                            $viewData[$fieldName] = $frValues[$fieldId];
                        }
                    }
                }
            }

            // Web templates render full HTML documents (DOCTYPE/html/head/body).
            // Strip to inner body content so it can be injected via x-html.
            if (!empty($template->signing_parties)) {
                $viewData['signing_parties'] = $template->signing_parties;
                $viewData['document_context'] = $template->isSalesDocument() ? 'sales' : 'rental';
            }
            $fullHtml = view($template->blade_view, $viewData)->render();
            $bodyHtml = $fullHtml;
            if (preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $m)) {
                $bodyHtml = trim($m[1]);
            }
            // Also extract <style> blocks from <head> and prepend them
            $styles = '';
            if (preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches)) {
                $styles = implode("\n", $styleMatches[0]);
            }

            return response()->json([
                'render_type'   => 'web',
                'blade_view'    => $template->blade_view,
                'html'          => $styles . $bodyHtml,
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

        // HARD BLOCK: Sale agreements cannot enter the e-sign pipeline (Alienation of Land Act)
        if ($template->isEsignBlocked()) {
            return redirect()->route('docuperfect.esign.step', [$flowId, 6])
                ->with('error', 'Sale agreements and OTPs must be signed with wet ink per the Alienation of Land Act. E-signing is not permitted for this document type.');
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
            foreach ($fields as &$field) {
                if (($field['id'] ?? null) == $fieldId) {
                    $field['assignedTo'] = $party;
                }
            }
            unset($field);
        }

        $recipients = $stepData['recipients']['recipients'] ?? [];
        // Support both old format (array of entries) and new format ({delivery_mode, parties: [...]})
        $signingSetupRaw = $stepData['signing_setup'] ?? [];
        $signingSetup = isset($signingSetupRaw['parties']) ? $signingSetupRaw['parties'] : $signingSetupRaw;
        $propertyAddress = $stepData['property']['address'] ?? $stepData['property']['title'] ?? '';

        // Build document name
        $firstRecipientName = '';
        foreach ($recipients as $r) {
            if (($r['role'] ?? '') !== 'agent' && !empty($r['name'])) {
                $firstRecipientName = $r['name'];
                break;
            }
        }
        $isPackFlow = !empty($stepData['is_pack_flow']);
        $isPdfPack = !empty($stepData['is_pdf_pack']);
        $docName = $isPackFlow ? ($stepData['pack_name'] ?? $template->name)
                 : ($isPdfPack ? ($stepData['pdf_pack_name'] ?? $template->name) : $template->name);
        if ($firstRecipientName) $docName .= ' — ' . $firstRecipientName;
        $docName .= ' — ' . now()->format('Y-m-d');

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

            foreach ($templateIds as $idx => $tplId) {
                $tpl = Template::find($tplId);
                if (!$tpl || !$tpl->blade_view) continue;

                $tplData = $webTemplateDataService->resolve($tplId, $stepData, $user);
                if (!empty($tpl->signing_parties)) {
                    $tplData['signing_parties'] = $tpl->signing_parties;
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

                $bodyHtml = $this->injectFieldValues($bodyHtml, $tplData);
                $mergedHtml .= $styles . $bodyHtml . $pageBreak;
                $packTemplateData[$tplId] = $tplData;
            }

            $webTemplateData = [
                'merged_html'        => $mergedHtml,
                'template_ids'       => $templateIds,
                'pack_id'            => $stepData['pack_id'] ?? null,
                'pack_template_data' => $packTemplateData,
            ];
        } elseif ($template->render_type === 'web' && $template->blade_view) {
            $webTemplateData = $webTemplateDataService->resolve($template->id, $stepData, $user);

            // Build parties list for initials/signature processing
            $partiesForSigning = [];
            $partiesForSigning[] = [
                'role' => 'agent',
                'name' => $user->name,
                'display' => $user->name,
            ];
            foreach ($stepData['recipients']['recipients'] ?? [] as $r) {
                $partiesForSigning[] = [
                    'role' => $r['role'],
                    'name' => $r['name'],
                    'display' => $r['name'],
                ];
            }

            // Render full HTML for single web template (same as pack flow)
            $viewData = $webTemplateData;
            if (!empty($template->signing_parties)) {
                $viewData['signing_parties'] = $template->signing_parties;
                $viewData['document_context'] = $template->isSalesDocument() ? 'sales' : 'rental';
            }

            // Build party_names for signature-block component (non-agent recipients first, agent last)
            $partyNames = [];
            foreach ($recipients as $r) {
                if (($r['role'] ?? '') === 'agent') continue;
                $partyNames[] = $r['name'] ?? '';
            }
            $partyNames[] = $user->name;
            $viewData['party_names'] = $partyNames;

            // Build recipients_by_role for signature-line component (inline sigs)
            $recipientsByRole = [];
            foreach ($recipients as $r) {
                $role = $r['role'] ?? '';
                $baseRole = preg_replace('/_\d+$/', '', $role);
                $recipientsByRole[$baseRole][] = $r;
            }
            $viewData['recipients_by_role'] = $recipientsByRole;
            $viewData['is_candidate_flow'] = $isCandidateFlow;
            if ($isCandidateFlow) {
                $viewData['supervisor_name'] = 'Authorised Practitioner (shared queue)';
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

            // Process the HTML: inject initials and resolve signature names
            $bodyHtml = $this->injectInitialsBlocks($bodyHtml, $partiesForSigning);
            $bodyHtml = $this->resolveSignatureNames($bodyHtml, $webTemplateData, $partiesForSigning);
            $bodyHtml = $this->injectFieldValues($bodyHtml, $webTemplateData);

            // Inject additional clauses from wizard step 5
            $selectedClauses = $stepData['fill_review']['clauses'] ?? [];
            if (!empty($selectedClauses)) {
                $clauseHtml = '<div class="corex-additional-clauses" style="margin-top:16pt;">';
                $clauseHtml .= '<h3 style="font-weight:bold;margin-top:12pt;margin-bottom:8pt;">Additional Conditions</h3>';
                foreach ($selectedClauses as $idx => $clause) {
                    $num = $idx + 1;
                    $clauseHtml .= '<div style="margin:6pt 0;">';
                    $clauseHtml .= '<p><strong>' . $num . '.</strong> '
                        . e($clause['text'] ?? $clause['content'] ?? '') . '</p>';
                    $clauseHtml .= '</div>';
                }
                $clauseHtml .= '</div>';
                $bodyHtml .= $clauseHtml;
            }

            // Store as merged_html so SignatureController uses it directly
            $webTemplateData['merged_html'] = $styles . $bodyHtml;

            // Store field_mappings with editable_by so the signing view knows
            // which fields each party role can edit (CDS templates only)
            if (!empty($template->field_mappings)) {
                $webTemplateData['field_mappings'] = $template->field_mappings;
                $webTemplateData['template_type'] = $template->template_type;
            }
        }

        $packInstanceId = ($isPackFlow || $isPdfPack) ? (int) round(microtime(true) * 1000) : null;

        // Resolve document_type: map template's DocumentType to a RentalDocumentType slug
        $resolvedDocType = $template->template_type; // fallback
        if ($template->document_type_id) {
            $template->loadMissing('documentType');
            $dtName = $template->documentType->name ?? '';
            // Map DocuPerfect DocumentType names to RentalDocumentType slugs
            $dtNameMap = [
                'Mandates' => 'mandate', 'OTPs' => 'other', 'Addendums' => 'addendum',
                'Condition Reports' => 'inspection_report', 'FICA' => 'disclosure',
                'Rental Agreements' => 'lease_agreement', 'Other' => 'other',
            ];
            $resolvedDocType = $dtNameMap[$dtName] ?? strtolower(str_replace(' ', '_', $dtName));
        }

        // Resolve property_id: use flow->property_id (pillar) or step_data rental_property_id
        $resolvedPropertyId = $flow->property_id;
        $propSource = $stepData['property']['_property_source'] ?? 'properties';
        if (!$resolvedPropertyId && $propSource === 'rental_properties' && !empty($stepData['property']['property_id'])) {
            $resolvedPropertyId = $stepData['property']['property_id'];
        }

        $result = DB::transaction(function () use ($user, $flow, $template, $fields, $recipients, $signingSetup, $docName, $propertyAddress, $signatureService, $webTemplateData, $packInstanceId, $resolvedDocType, $resolvedPropertyId, $candidateService, $isCandidateFlow) {
            // 1. Create Document
            $document = Document::create([
                'name'             => $docName,
                'template_id'      => $template->id,
                'fields_json'      => $fields,
                'owner_id'         => $user->id,
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

            // Use signing_setup order if available (respects drag-reorder from step 6)
            $orderedRecipients = $recipients;
            if (!empty($signingSetup) && !empty($signingSetup[0]['signing_order'] ?? null)) {
                // Rebuild recipient list from signing_setup order (skip agent entries)
                $orderedRecipients = [];
                foreach ($signingSetup as $ss) {
                    if (($ss['role'] ?? '') === 'agent') continue;
                    // Match signing_setup entry to original recipient by role+name
                    foreach ($recipients as $r) {
                        if (($r['role'] ?? '') === ($ss['role'] ?? '') && ($r['name'] ?? '') === ($ss['name'] ?? '')) {
                            $orderedRecipients[] = $r;
                            break;
                        }
                    }
                }
                // Fallback: if matching failed, use original order
                if (empty($orderedRecipients)) $orderedRecipients = $recipients;
            }

            // Per V2 spec: each person is a SEPARATE signer in the chain.
            // Two sellers = two separate parties with unique keys (seller, seller_2).
            $roleCounts = [];
            $recipientPartyKeys = [];
            foreach ($orderedRecipients as $i => $r) {
                $baseRole = $roleAliases[$r['role'] ?? 'other'] ?? ($r['role'] ?? 'other');
                if ($baseRole === 'agent') continue;

                // Generate unique party key: seller, seller_2, seller_3, etc.
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

                // Rebuild signing order: agent → supervisor → external parties
                $externalParties = array_filter($signingOrder, fn($r) => $r !== 'agent');
                $signingOrder = array_merge(['agent', 'supervisor'], array_values($externalParties));

                // Also add supervisor_final as the last step (after all external parties)
                $signingOrder[] = 'supervisor_final';
                $parties[] = [
                    'role'       => 'supervisor_final',
                    'role_label' => 'supervisor',
                    'name'       => 'Authorised Practitioner',
                    'email'      => '',
                    'id_number'  => '',
                ];
            }

            $documentHash = hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $sigTemplate = SignatureTemplate::create([
                'document_id'         => $document->id,
                'document_hash'       => $documentHash,
                'status'              => SignatureTemplate::STATUS_DRAFT,
                'parties_json'        => $parties,
                'signing_order_json'  => $signingOrder,
                'created_by'          => $user->id,
                'is_candidate_flow'   => $isCandidateFlow,
                'supervisor_user_id'  => null,
                'sections_json'       => $template->sections,
            ]);

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

            foreach ($orderedRecipients as $i => $r) {
                $baseRole = $roleAliases[$r['role'] ?? 'other'] ?? ($r['role'] ?? 'other');
                if ($baseRole === 'agent') continue;
                $partyKey = $recipientPartyKeys[$i] ?? $baseRole;

                // Find matching signing_setup entry for this recipient
                $matchedSetup = null;
                foreach ($signingSetup as $ss) {
                    if (($ss['role'] ?? '') === ($r['role'] ?? '') && ($ss['name'] ?? '') === ($r['name'] ?? '')) {
                        $matchedSetup = $ss;
                        break;
                    }
                }
                $skipEmail = !empty($matchedSetup['skipEmail'] ?? false);
                $email = $matchedSetup['email'] ?? $r['email'] ?? '';
                $signingAction = $matchedSetup['action'] ?? 'send_after';

                $sigReq = $signatureService->createSigningRequest(
                    $sigTemplate,
                    $partyKey,
                    $r['name'] ?? '',
                    $skipEmail ? '' : $email,
                    $r['id_number'] ?? null,
                    null,
                    $user
                );

                // Mark as deferred if "sign_later" was selected and party has no details
                if ($signingAction === 'sign_later' && (empty($r['name']) || empty($email) || $skipEmail)) {
                    $sigReq->update(['status' => \App\Models\Docuperfect\SignatureRequest::STATUS_DEFERRED]);
                }
            }

            // Candidate flow: create supervisor_final request (last in chain)
            // Shared queue — any eligible authoriser can claim.
            if ($isCandidateFlow) {
                $signatureService->createSigningRequest(
                    $sigTemplate,
                    'supervisor_final',
                    'Authorised Practitioner',
                    '',
                    null,
                    null,
                    $user
                );
            }

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

            // 4e. Create signature zones for all template types.
            // Zones provide bounding boxes that the setup screen renders as
            // draggable/resizable regions. The setup screen JS may refine
            // positions from DOM measurements, but zones must always exist
            // server-side first so the setup screen has something to display.
            $signatureService->createZonesFromParties(
                $sigTemplate,
                $parties,
                max(1, count($webTemplateData['template_ids'] ?? [1])),
                $isCandidateFlow
            );

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

        // Redirect to signature setup (Step 2: marker placement) — agent places markers before signing
        $signingUrl = route('docuperfect.signatures.setup', ['document' => $result->id]);

        return redirect($signingUrl);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('PREPARE_SIGNING_FAILED', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->route('docuperfect.esign.create')
                ->withErrors(['error' => 'Failed to prepare signing: ' . $e->getMessage()]);
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

        $recipients = $stepData['recipients']['recipients'] ?? [];
        $nextRecipient = null;
        foreach ($recipients as $r) {
            if (($r['role'] ?? '') !== 'agent' && !empty($r['email'])) {
                $nextRecipient = $r;
                break;
            }
        }

        // Mark flow as completed
        $flow->status = 'completed';
        $flow->save();

        return view('docuperfect.esign.signing-complete', [
            'flow'          => $flow,
            'document'      => $document,
            'sigTemplate'   => $sigTemplate,
            'nextRecipient' => $nextRecipient,
            'template'      => $flow->template,
        ]);
    }

    /**
     * Auto-fill template fields from wizard step data.
     *
     * Uses source_type/source_column/source_contact_type from
     * docuperfect_named_fields to resolve each field's value.
     */
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
                        'bank_name'           => $r['bank_name'] ?: ($dbContact->bank_name ?? ''),
                        'bank_account_name'   => $r['bank_account_name'] ?: ($dbContact->bank_account_name ?? ''),
                        'bank_account_number' => $r['bank_account_number'] ?: ($dbContact->bank_account_number ?? ''),
                        'bank_branch_name'    => $r['bank_branch_name'] ?: ($dbContact->bank_branch_name ?? ''),
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
                    $field['value'] = $this->numberToWords((int) $commVal);
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

                // Use first contact only — indexed fields (seller_1_phone etc.)
                // are resolved separately via WebTemplateDataService
                $contact = $contacts[0] ?? [];
                return $this->resolveContactValue($sourceColumn, $contact);

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
            'price_in_words' => $price ? $this->numberToWords((int) $price) : '',
            default => '',
        };
    }

    private function numberToWords(int $number): string
    {
        if ($number === 0) return 'zero';

        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine',
                 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen',
                 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

        $convert = function (int $n) use (&$convert, $ones, $tens): string {
            if ($n < 20) return $ones[$n];
            if ($n < 100) return $tens[(int)($n / 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
            if ($n < 1000) return $ones[(int)($n / 100)] . ' hundred' . ($n % 100 ? ' and ' . $convert($n % 100) : '');
            if ($n < 1000000) return $convert((int)($n / 1000)) . ' thousand' . ($n % 1000 ? ' ' . $convert($n % 1000) : '');
            return $convert((int)($n / 1000000)) . ' million' . ($n % 1000000 ? ' ' . $convert($n % 1000000) : '');
        };

        return ucfirst($convert($number));
    }

    /**
     * Inject initials blocks at the bottom of every non-last page div.
     */
    private function injectInitialsBlocks(string $html, array $parties): string
    {
        // Build initials row HTML with inline styles
        $blocks = '';
        foreach ($parties as $n => $party) {
            $role = strtolower($party['role']);
            $blocks .= '<div class="initial-block" '
                . 'data-marker-party="' . $role . '" '
                . 'data-marker-type="initial" '
                . 'data-marker-index="' . $n . '" '
                . 'style="display:inline-block;text-align:center;margin:0 6pt;">'
                . '<div class="initial-line" style="border-bottom:1pt solid #1a1a1a;width:40pt;margin-bottom:2pt;"></div>'
                . '</div>';
        }

        $initialsRow = '<div class="initials-row" style="display:flex;justify-content:flex-end;'
            . 'gap:12pt;margin-top:8pt;padding-top:8pt;border-top:0.5pt solid #ccc;">'
            . $blocks
            . '</div>';

        // Split HTML on page div openings to identify pages
        // Pattern matches <div class="page"> or <div class="page page-break">
        $parts = preg_split('/(<div\s+class="page[^"]*">)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        // Count how many page divs we have
        $pageCount = 0;
        foreach ($parts as $part) {
            if (preg_match('/^<div\s+class="page[^"]*">/i', $part)) {
                $pageCount++;
            }
        }

        if ($pageCount <= 1) {
            // Single page — no initials needed
            return $html;
        }

        // Reassemble: for each page except the last, inject initials before its closing </div>
        $currentPage = 0;
        $result = '';
        for ($i = 0; $i < count($parts); $i++) {
            $part = $parts[$i];

            if (preg_match('/^<div\s+class="page[^"]*">/i', $part)) {
                $currentPage++;
                $result .= $part;
                continue;
            }

            // This part contains the content of a page div (after the opening tag)
            if ($currentPage > 0 && $currentPage < $pageCount) {
                // Find the last </div> in this part (the page's closing div)
                $lastDivPos = strrpos($part, '</div>');
                if ($lastDivPos !== false) {
                    $part = substr($part, 0, $lastDivPos) . $initialsRow . substr($part, $lastDivPos);
                }
            }

            $result .= $part;
        }

        return $result;
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
            $editableBy = $m['filled_by'] ?? $m['editable_by'] ?? 'agent';
            if (is_array($editableBy)) {
                $editableBy = $editableBy[0] ?? 'agent';
            }

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
                'assignedTo'      => $editableBy,
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

    // ──────────────────────────────────────────────
    // Pack Chaining (Multi-Document Flow)
    // ──────────────────────────────────────────────

    /**
     * Initialize a chained pack flow: creates Flow records for each template in the pack.
     * Called when user selects a pack and starts the wizard.
     */
    public function initPackChain(Request $request)
    {
        $user = $request->user();
        $packId = $request->input('pack_id');
        $packType = $request->input('pack_type', 'web'); // 'web' or 'pdf'
        $ficaPerParty = $request->boolean('fica_per_party');

        // Load templates from pack
        if ($packType === 'web') {
            $pack = \App\Models\Docuperfect\WebPack::with('items.template')->findOrFail($packId);
            $templates = $pack->items->sortBy('sort_order')
                ->map(fn($item) => $item->template)
                ->filter()
                ->values();
        } else {
            $pack = \App\Models\Docuperfect\Pack::with('templates')->findOrFail($packId);
            $templates = $pack->templates
                ->filter(fn($t) => $t->is_esign)
                ->values();
        }

        if ($templates->isEmpty()) {
            return response()->json(['error' => 'Pack has no eligible templates.'], 422);
        }

        // Create the parent flow (first template in the pack)
        $parentFlow = Flow::create([
            'type' => 'esign',
            'template_id' => $templates[0]->id,
            'user_id' => $user->id,
            'current_step' => 2,
            'step_data' => [
                'template' => ['template_id' => $templates[0]->id],
                'fields' => $templates[0]->fields_json ?? [],
                'pack_chain' => true,
                'pack_chain_templates' => $templates->map(fn($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'party_mode' => $t->party_mode ?? null,
                ])->toArray(),
            ],
            'status' => 'active',
            'pack_id' => $packId,
            'pack_type' => $packType,
            'flow_sequence' => 0,
            'parent_flow_id' => null,
            'pack_status' => 'in_progress',
        ]);

        // Create child flows for remaining templates
        foreach ($templates->slice(1) as $idx => $tpl) {
            Flow::create([
                'type' => 'esign',
                'template_id' => $tpl->id,
                'user_id' => $user->id,
                'current_step' => 5, // Start at Fill & Review (skip property/contact/details)
                'step_data' => [
                    'template' => ['template_id' => $tpl->id],
                    'fields' => $tpl->fields_json ?? [],
                    'pack_chain' => true,
                    'carry_forward_from' => $parentFlow->id,
                ],
                'status' => 'draft', // Inactive until parent flow reaches this doc
                'pack_id' => $packId,
                'pack_type' => $packType,
                'flow_sequence' => $idx + 1,
                'parent_flow_id' => $parentFlow->id,
                'pack_status' => null,
            ]);
        }

        return response()->json([
            'ok' => true,
            'flow_id' => $parentFlow->id,
            'template_count' => $templates->count(),
            'redirect' => route('docuperfect.esign.create') . '?flow_id=' . $parentFlow->id,
        ]);
    }

    /**
     * Advance to the next document in a pack chain after the current one is signed.
     * Called after agent completes signing on a pack doc.
     */
    public function nextPackDocument(Request $request, $flowId)
    {
        $user = $request->user();
        $currentFlow = Flow::where('user_id', $user->id)->findOrFail($flowId);

        if (!$currentFlow->isPackFlow()) {
            return response()->json(['error' => 'Not a pack flow.'], 422);
        }

        // Mark current flow as completed
        $currentFlow->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Find the next flow in the pack chain
        $nextFlow = $currentFlow->nextPackFlow();

        if (!$nextFlow) {
            // All docs in the pack are done
            // Update parent flow pack_status
            $parentId = $currentFlow->parent_flow_id ?? $currentFlow->id;
            Flow::where('id', $parentId)->update(['pack_status' => 'completed']);

            return response()->json([
                'ok' => true,
                'pack_complete' => true,
                'message' => 'All documents in the pack have been signed.',
            ]);
        }

        // Carry forward shared data from the parent flow
        $sharedData = $currentFlow->getSharedPackData();
        $nextStepData = $nextFlow->step_data ?? [];

        // Merge carry-forward data into the next flow's step_data
        $nextStepData['property'] = $sharedData['property'] ?? $nextStepData['property'] ?? [];
        $nextStepData['recipients'] = $sharedData['recipients'] ?? $nextStepData['recipients'] ?? [];
        $nextStepData['details'] = $sharedData['details'] ?? $nextStepData['details'] ?? [];
        $nextStepData['rental_details'] = $sharedData['rental_details'] ?? $nextStepData['rental_details'] ?? [];
        $nextStepData['carried_forward'] = true;

        $nextFlow->update([
            'status' => 'active',
            'step_data' => $nextStepData,
            'current_step' => 5, // Fill & Review (property/contacts/details pre-filled)
            'property_id' => $currentFlow->property_id,
        ]);

        $nextTemplate = $nextFlow->template;

        return response()->json([
            'ok' => true,
            'pack_complete' => false,
            'next_flow_id' => $nextFlow->id,
            'next_template_name' => $nextTemplate->name ?? 'Next Document',
            'next_sequence' => $nextFlow->flow_sequence + 1,
            'total_in_pack' => Flow::where('pack_id', $currentFlow->pack_id)
                ->where('pack_type', $currentFlow->pack_type)
                ->count(),
            'redirect' => route('docuperfect.esign.create') . '?flow_id=' . $nextFlow->id,
        ]);
    }

    /**
     * Get pack chain status (how many docs done, what's next).
     */
    public function packStatus(Request $request, $flowId)
    {
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);

        if (!$flow->isPackFlow()) {
            return response()->json(['is_pack' => false]);
        }

        $parentId = $flow->parent_flow_id ?? $flow->id;
        $allFlows = Flow::where(function ($q) use ($parentId, $flow) {
            $q->where('id', $parentId)
              ->orWhere('parent_flow_id', $parentId);
        })
            ->where('pack_id', $flow->pack_id)
            ->orderBy('flow_sequence')
            ->with('template')
            ->get();

        $docs = $allFlows->map(function ($f) {
            return [
                'flow_id' => $f->id,
                'template_id' => $f->template_id,
                'template_name' => $f->template->name ?? 'Unknown',
                'sequence' => $f->flow_sequence,
                'status' => $f->status,
                'completed' => $f->status === 'completed',
            ];
        });

        $completedCount = $docs->where('completed', true)->count();
        $nextFlow = $allFlows->firstWhere('status', 'active');
        if (!$nextFlow) {
            $nextFlow = $allFlows->firstWhere('status', 'draft');
        }

        return response()->json([
            'is_pack' => true,
            'total' => $docs->count(),
            'completed' => $completedCount,
            'documents' => $docs,
            'current_flow_id' => $flow->id,
            'next_flow_id' => $nextFlow?->id,
            'next_template_name' => $nextFlow?->template?->name,
            'pack_complete' => $completedCount === $docs->count(),
        ]);
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

        $firstRecipientName = '';
        foreach ($recipients as $r) {
            if (($r['role'] ?? '') !== 'agent' && !empty($r['name'])) {
                $firstRecipientName = $r['name'];
                break;
            }
        }

        $docName = $template->name . ($firstRecipientName ? " — {$firstRecipientName}" : '')
            . ' — ' . now()->format('Y-m-d');

        // Render filled document HTML for web templates
        $webTemplateData = null;
        if ($renderType === 'web' && $template->blade_view) {
            $webTemplateDataService = app(WebTemplateDataService::class);
            $webTemplateData = $webTemplateDataService->resolve($template->id, $stepData, $user);

            $viewData = $webTemplateData;
            if (!empty($template->signing_parties)) {
                $viewData['signing_parties'] = $template->signing_parties;
                $viewData['document_context'] = $template->isSalesDocument() ? 'sales' : 'rental';
            }

            // Build party_names for signature-block component
            $partyNames = [];
            foreach ($recipients as $r) {
                if (($r['role'] ?? '') === 'agent') continue;
                $partyNames[] = $r['name'] ?? '';
            }
            $partyNames[] = $user->name;
            $viewData['party_names'] = $partyNames;

            // Build recipients_by_role
            $recipientsByRole = [];
            foreach ($recipients as $r) {
                $role = $r['role'] ?? '';
                $baseRole = preg_replace('/_\d+$/', '', $role);
                $recipientsByRole[$baseRole][] = $r;
            }
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

            $selectedClauses = $stepData['fill_review']['clauses'] ?? [];
            if (!empty($selectedClauses)) {
                $clauseHtml = '<div class="corex-additional-clauses" style="margin-top:16pt;">';
                $clauseHtml .= '<h3 style="font-weight:bold;margin-top:12pt;margin-bottom:8pt;">Additional Conditions</h3>';
                foreach ($selectedClauses as $idx => $clause) {
                    $num = $idx + 1;
                    $clauseHtml .= '<div style="margin:6pt 0;"><p><strong>' . $num . '.</strong> '
                        . e($clause['text'] ?? $clause['content'] ?? '') . '</p></div>';
                }
                $clauseHtml .= '</div>';
                $bodyHtml .= $clauseHtml;
            }

            $webTemplateData['merged_html'] = $styles . $bodyHtml;
        }

        $document = Document::create([
            'name' => $docName,
            'template_id' => $template->id,
            'fields_json' => $fields,
            'owner_id' => $user->id,
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
        $signingSetupRaw = $stepData['signing_setup'] ?? [];
        $signingSetup = isset($signingSetupRaw['parties']) ? $signingSetupRaw['parties'] : $signingSetupRaw;
        $propertyAddress = $stepData['property']['address'] ?? $stepData['property']['title'] ?? '';

        $firstRecipientName = '';
        foreach ($recipients as $r) {
            if (($r['role'] ?? '') !== 'agent' && !empty($r['name'])) {
                $firstRecipientName = $r['name'];
                break;
            }
        }

        $docName = $template->name . ($firstRecipientName ? " — {$firstRecipientName}" : '')
            . ' — ' . now()->format('Y-m-d');

        $signatureService = app(SignatureService::class);

        // Render filled document HTML for web templates (same as download mode)
        $webTemplateData = null;
        if ($renderType === 'web' && $template->blade_view) {
            $webTemplateDataService = app(WebTemplateDataService::class);
            $webTemplateData = $webTemplateDataService->resolve($template->id, $stepData, $user);

            $viewData = $webTemplateData;
            if (!empty($template->signing_parties)) {
                $viewData['signing_parties'] = $template->signing_parties;
                $viewData['document_context'] = $template->isSalesDocument() ? 'sales' : 'rental';
            }

            $partyNames = [];
            foreach ($recipients as $r) {
                if (($r['role'] ?? '') === 'agent') continue;
                $partyNames[] = $r['name'] ?? '';
            }
            $partyNames[] = $user->name;
            $viewData['party_names'] = $partyNames;

            $recipientsByRole = [];
            foreach ($recipients as $r) {
                $role = $r['role'] ?? '';
                $baseRole = preg_replace('/_\d+$/', '', $role);
                $recipientsByRole[$baseRole][] = $r;
            }
            $viewData['recipients_by_role'] = $recipientsByRole;

            $fullHtml = view($template->blade_view, $viewData)->render();

            preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $bodyMatch);
            $bodyHtml = $bodyMatch[1] ?? $fullHtml;
            $styles = '';
            if (preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches)) {
                $styles = implode("\n", $styleMatches[0]);
            }

            $bodyHtml = $this->injectFieldValues($bodyHtml, $webTemplateData);

            $selectedClauses = $stepData['fill_review']['clauses'] ?? [];
            if (!empty($selectedClauses)) {
                $clauseHtml = '<div class="corex-additional-clauses" style="margin-top:16pt;">';
                $clauseHtml .= '<h3 style="font-weight:bold;margin-top:12pt;margin-bottom:8pt;">Additional Conditions</h3>';
                foreach ($selectedClauses as $idx => $clause) {
                    $num = $idx + 1;
                    $clauseHtml .= '<div style="margin:6pt 0;"><p><strong>' . $num . '.</strong> '
                        . e($clause['text'] ?? $clause['content'] ?? '') . '</p></div>';
                }
                $clauseHtml .= '</div>';
                $bodyHtml .= $clauseHtml;
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

        $result = DB::transaction(function () use ($user, $flow, $template, $fields, $recipients, $signingSetup, $docName, $propertyAddress, $signatureService, $webTemplateData, $resolvedDocType, $resolvedPropertyId, $roleAliases) {
            // 1. Create Document
            $document = Document::create([
                'name'             => $docName,
                'template_id'      => $template->id,
                'fields_json'      => $fields,
                'owner_id'         => $user->id,
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

            $orderedRecipients = $recipients;
            if (!empty($signingSetup) && !empty($signingSetup[0]['signing_order'] ?? null)) {
                $orderedRecipients = [];
                foreach ($signingSetup as $ss) {
                    if (($ss['role'] ?? '') === 'agent') continue;
                    foreach ($recipients as $r) {
                        if (($r['role'] ?? '') === ($ss['role'] ?? '') && ($r['name'] ?? '') === ($ss['name'] ?? '')) {
                            $orderedRecipients[] = $r;
                            break;
                        }
                    }
                }
                if (empty($orderedRecipients)) $orderedRecipients = $recipients;
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
            ]);

            // 3. Create SignatureRequests with signing_method = 'wet_ink'
            $agentReq = $signatureService->createSigningRequest(
                $sigTemplate, 'agent', $user->name, $user->email, null, null, $user
            );
            $agentReq->update([
                'signing_method' => 'wet_ink',
                'status' => \App\Models\Docuperfect\SignatureRequest::STATUS_PENDING,
                'sent_at' => now(),
            ]);

            foreach ($orderedRecipients as $i => $r) {
                $baseRole = $roleAliases[$r['role'] ?? 'other'] ?? ($r['role'] ?? 'other');
                if ($baseRole === 'agent') continue;
                $partyKey = $recipientPartyKeys[$i] ?? $baseRole;

                $matchedSetup = null;
                foreach ($signingSetup as $ss) {
                    if (($ss['role'] ?? '') === ($r['role'] ?? '') && ($ss['name'] ?? '') === ($r['name'] ?? '')) {
                        $matchedSetup = $ss;
                        break;
                    }
                }
                $skipEmail = !empty($matchedSetup['skipEmail'] ?? false);
                $email = $matchedSetup['email'] ?? $r['email'] ?? '';

                $sigReq = $signatureService->createSigningRequest(
                    $sigTemplate, $partyKey, $r['name'] ?? '', $skipEmail ? '' : $email,
                    $r['id_number'] ?? null, null, $user
                );
                $sigReq->update(['signing_method' => 'wet_ink']);
            }

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
        $document = Document::where('owner_id', $user->id)->findOrFail($documentId);
        $document->load('template');

        $mergedHtml = $document->web_template_data['merged_html'] ?? null;

        return view('docuperfect.esign.download', [
            'document' => $document,
            'template' => $document->template,
            'mergedHtml' => $mergedHtml,
        ]);
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
        $document = $documentId ? Document::find($documentId) : null;

        return view('docuperfect.esign.wet-ink-confirmation', [
            'flow' => $flow,
            'template' => $flow->template,
            'document' => $document,
        ]);
    }
}
