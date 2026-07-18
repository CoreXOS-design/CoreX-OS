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

                    // Agent is always first recipient (added by JS), so just add linked contacts
                    foreach ($prop->contacts as $contact) {
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

        // E-sign walk-fix FIX 1 + FIX 2 — expand role-bound fields per
        // recipient so a 3-seller session renders N inputs (each
        // pre-filled from THAT specific recipient's contact), not one
        // concatenated " and "-joined value. Mirrors B2.5/B3's recipient
        // loop engine on the recipient signing surface — same loop,
        // same identity convention, same chip labels.
        $expandedWizardFields = $this->expandWizardFieldsPerRecipient($allWizardFields, $stepData);

        $contactTypes = DB::table('contact_types')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

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
        $stepData[$stepKey] = $data;

        // Sort recipients by SA signing convention when saving step 3
        if ($stepKey === 'recipients' && !empty($data['recipients'])) {
            $sorted = $this->sortRecipientsBySigningOrder($data['recipients']);

            // Auto-create contact records for manually entered recipients
            $propertyId = $stepData['property']['property_id'] ?? null;
            $propertySource = $stepData['property']['_property_source'] ?? 'properties';

            foreach ($sorted as &$r) {
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
                        $w->whereIn('contact_type_id', $typeIds)
                          ->orWhereHas('parentTypes', fn ($q) => $q->whereIn('contact_types.id', $typeIds));
                    });
                }
            } else {
                // Fallback: match by contact_type name directly (for witness, spouse, etc.)
                $typeId = DB::table('contact_types')->where('name', 'like', '%' . $role . '%')->value('id');
                if ($typeId) {
                    $query->where('contact_type_id', $typeId);
                }
            }
        }

        $contacts = $query->limit(10)->get();

        return response()->json($contacts->map(function ($c) use ($q) {
            return [
                'id'                  => $c->id,
                'first_name'          => $c->first_name,
                'last_name'           => $c->last_name,
                'full_name'           => $c->first_name . ' ' . $c->last_name,
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
                        $propSrc = $stepData['property']['_property_source'] ?? null;
                        $tplData['document_context'] = $tpl->isSalesDocument($propSrc) ? 'sales' : 'rental';
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
                $propSrc = $stepData['property']['_property_source'] ?? null;
                $viewData['document_context'] = $template->isSalesDocument($propSrc) ? 'sales' : 'rental';
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
                $wizardRecipients = $this->buildTransientSignatureRequestsForPreview(
                    $flow,
                    $stepData['recipients']['recipients'] ?? [],
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
        // Sort recipients by SA signing convention: Agent → Tenant/Buyer → Landlord/Seller → Witness
        $recipients = $this->sortRecipientsBySigningOrder($recipients);
        // Support both old format (array of entries) and new format ({delivery_mode, parties: [...]})
        $signingSetupRaw = $stepData['signing_setup'] ?? [];
        $signingSetup = isset($signingSetupRaw['parties']) ? $signingSetupRaw['parties'] : $signingSetupRaw;
        $propertyAddress = $stepData['property']['address'] ?? $stepData['property']['title'] ?? '';

        // Build document name — use custom name from wizard if set, else auto-build
        $isPackFlow = !empty($stepData['is_pack_flow']);
        $isPdfPack = !empty($stepData['is_pdf_pack']);
        $docName = $stepData['document_name'] ?? null;
        if (empty($docName)) {
            $firstRecipientName = '';
            foreach ($recipients as $r) {
                if (($r['role'] ?? '') !== 'agent' && !empty($r['name'])) {
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

            foreach ($templateIds as $idx => $tplId) {
                $tpl = Template::find($tplId);
                if (!$tpl || !$tpl->blade_view) continue;

                $tplData = $webTemplateDataService->resolve($tplId, $stepData, $user);
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
                $segPartyNames = [];
                foreach ($recipients as $r) {
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
                foreach ($recipients as $r) {
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

            // §20 — stamp each segment's .corex-document-wrapper with an
            // instance-stable docKey so the client keys disclosure rows
            // intrinsically per document (disclosure_<docKey>_<n>). One
            // unique token per wrapper => two of the same template in a
            // pack get distinct, stable keys; never DOM-position-derived.
            $mergedHtml = $this->stampDisclosureDocKeys($mergedHtml);

            $webTemplateData = [
                'merged_html'        => $mergedHtml,
                'template_ids'       => $templateIds,
                'pack_id'            => $stepData['pack_id'] ?? null,
                'pack_template_data' => $packTemplateData,
            ];
        } elseif ($template->render_type === 'web' && $template->blade_view) {
            $webTemplateData = $webTemplateDataService->resolve($template->id, $stepData, $user);

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

            // Build party_names for signature-block component (non-agent recipients first, agent last)
            $partyNames = [];
            foreach ($recipients as $r) {
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
            foreach ($recipients as $r) {
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
            if (!empty($otherConditionsText)) {
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

            // §20 — stamp the document's .corex-document-wrapper with an
            // instance-stable docKey (same scheme as the pack path) so a
            // single doc keys disclosure rows identically to when it is a
            // pack segment — full position-independence.
            $bodyHtml = $this->stampDisclosureDocKeys($bodyHtml);

            // Store as merged_html so SignatureController uses it directly
            $webTemplateData['merged_html'] = $styles . $bodyHtml;

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

            // Phase 1B.5 — bridge the legacy textarea content into structured
            // document_conditions rows so the recipient-signing surface (which
            // reads from those rows, not other_conditions_text) renders them.
            try {
                app(\App\Services\Docuperfect\LegacyOtherConditionsBridge::class)
                    ->syncToStructuredRows($sigTemplate);
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
                $ficaRequired = !empty($matchedSetup['fica_required'] ?? false);
                $contactId = !empty($r['_contact_id']) ? (int) $r['_contact_id'] : null;

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
                    $ficaSubId
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
        $byRole = [];
        foreach ($recipients as $r) {
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

        $firstRecipientName = '';
        foreach ($recipients as $r) {
            if (($r['role'] ?? '') !== 'agent' && !empty($r['name'])) {
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
            if (!empty($otherConditionsText2)) {
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
        $recipients = $this->sortRecipientsBySigningOrder($recipients);
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
            $webTemplateData = $webTemplateDataService->resolve($template->id, $stepData, $user);

            $viewData = $webTemplateData;
            if (!empty($template->signing_parties)) {
                $viewData['signing_parties'] = $template->signing_parties;
                $propSrc = $stepData['property']['_property_source'] ?? null;
                $viewData['document_context'] = $template->isSalesDocument($propSrc) ? 'sales' : 'rental';
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
            if (!empty($otherConditionsText3)) {
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
                'other_conditions_text' => trim($stepData['fill_review']['other_conditions_text'] ?? '') ?: null,
            ]);

            // Phase 1B.5 — bridge to structured document_conditions rows
            try {
                app(\App\Services\Docuperfect\LegacyOtherConditionsBridge::class)
                    ->syncToStructuredRows($sigTemplate);
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
     * Generate and download a PDF for a download-only document.
     * Uses SigningController::generatePdfFromHtml() for consistent rendering.
     */
    public function downloadDocumentPdf(Request $request, $documentId)
    {
        set_time_limit(120);

        $user = $request->user();
        $document = Document::where('owner_id', $user->id)->findOrFail($documentId);
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
        $document = Document::where('owner_id', $user->id)
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
        $document = Document::where('owner_id', $user->id)
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
        ];

        // Group templates by status category
        $groups = [
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
                ])
                ->orderByDesc('created_at')
                ->get();
        }

        $groups['needs_authorisation'] = $needsAuthorisation;

        $counts = [
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
