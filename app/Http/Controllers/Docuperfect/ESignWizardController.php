<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\NamedField;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\Property;
use App\Models\Rental\RentalProperty;
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
            ->where(function ($q) {
                $q->where('page_count', '>', 0)
                  ->orWhere('render_type', 'web');
            })
            ->with(['documentType', 'branches'])
            ->orderBy('name')
            ->get();

        $webPacks = \App\Models\Docuperfect\WebPack::where('agency_id', $user->effectiveAgencyId())
            ->whereNull('deleted_at')
            ->with(['items.template'])
            ->orderBy('name')
            ->get();

        $documentTypes = DocumentType::orderBy('sort_order')->get();

        $drafts = Flow::where('user_id', $user->id)
            ->whereIn('status', ['active', 'draft'])
            ->with('template')
            ->orderBy('updated_at', 'desc')
            ->get();

        return view('docuperfect.esign.wizard', [
            'templates'     => $templates,
            'webPacks'      => $webPacks,
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

        if ($isPackFlow && $packId) {
            // Web Pack flow — merge multiple templates
            $pack = \App\Models\Docuperfect\WebPack::with('items.template')
                ->findOrFail($packId);

            $templates = $pack->items->sortBy('sort_order')
                ->map(fn($item) => $item->template)
                ->filter(); // remove any null templates

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
        } else {
            // Single template flow (existing behaviour)
            $request->validate([
                'template_id' => 'required|exists:docuperfect_templates,id',
            ]);

            $template = Template::findOrFail($request->template_id);

            // Copy template fields into flow step_data (same as DocumentController::store)
            $fieldsJson = $template->fields_json ?? [];

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
        $template = $flow->template;
        $stepData = $flow->step_data ?? [];

        // Build page image URLs (same as DocumentController edit view)
        $pageImages = [];
        if ($template && $template->page_count > 0) {
            for ($n = 0; $n < $template->page_count; $n++) {
                $pageImages[] = route('docuperfect.page.image', ['id' => $template->id, 'page' => $n]);
            }
        }

        // Fields: use flow's stored copy (copied from template on creation),
        // with any values filled during wizard steps merged in
        $fields = $stepData['fields'] ?? ($template->fields_json ?? []);

        // Auto-fill fields from wizard step data (property, recipients, details)
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

            // Pre-fill value from WebTemplateDataService if field_name maps to a resolved key
            $fieldName = $field['field_name'] ?? null;
            if ($fieldName && isset($resolvedValues[$fieldName]) && $resolvedValues[$fieldName] !== '') {
                $fieldWithIndex['value'] = (string) $resolvedValues[$fieldName];
            }

            $allWizardFields[] = $fieldWithIndex;

            if (in_array($role, ['creator', 'user', 'agent'])) {
                $creatorFields[] = $fieldWithIndex;
            } else {
                $signerFields[] = $fieldWithIndex;
            }
        }

        // Enrich details defaults from property record (commission=10, rental & deposit from property)
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
                    // Use rental_amount, monthly_rental, or price (whichever has a value > 0)
                    $rental = !empty($propRecord->rental_amount) ? $propRecord->rental_amount
                            : (!empty($propRecord->monthly_rental) ? $propRecord->monthly_rental
                            : (!empty($propRecord->price) ? $propRecord->price : ''));
                    // Don't populate 0 values — leave blank for agent to fill
                    $propDefaults['monthly_rental'] = ($rental && (float) $rental > 0) ? $rental : '';
                    $deposit = !empty($propRecord->deposit_amount) ? $propRecord->deposit_amount : $rental;
                    $propDefaults['deposit'] = ($deposit && (float) $deposit > 0) ? $deposit : '';
                    $propDefaults['commission'] = !empty($propRecord->commission_percent) ? $propRecord->commission_percent : '10';
                    $propDefaults['marketing_fee'] = $propRecord->marketing_fee ?? '';
                }
            }
            // Fallback: use values saved in step 2 property data (from search results)
            $propStep = $stepData['property'] ?? [];
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
            if (empty($propDefaults['commission'])) {
                $propDefaults['commission'] = '10';
            }
            $stepData['details'] = $propDefaults;
        }

        // Recipients from step data — auto-populate linked contacts from property if empty
        $recipients = $stepData['recipients']['recipients'] ?? [];
        if (empty($recipients) && $step >= 3) {
            $propertyId = $stepData['property']['property_id'] ?? null;
            $propertySource = $stepData['property']['_property_source'] ?? null;
            if ($propertyId && $propertySource === 'properties') {
                $prop = Property::with(['contacts' => fn($q) => $q->withPivot('role')])->find($propertyId);
                if ($prop) {
                    // Agent is always first recipient (added by JS), so just add linked contacts
                    foreach ($prop->contacts as $contact) {
                        $recipients[] = [
                            'order'       => count($recipients) + 1,
                            'role'        => $contact->pivot->role ?? 'landlord',
                            'name'        => $contact->first_name . ' ' . $contact->last_name,
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

        $flow->step_data = $stepData;

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
                'rental_amount'     => $p->rental_amount ?: $p->price,
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
                'witness' => 'Witness',
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
                ]);
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
            ]);
        }

        return response()->json([
            'render_type'   => 'pdf',
            'page_count'    => $template->page_count,
            'pages'         => $template->pageImages,
            'fields'        => $template->fields_json ?? [],
            'wizard_config' => $template->wizard_config,
            'name'          => $template->name,
            'template_type' => $template->template_type,
        ]);
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
        $user = $request->user();
        $flow = Flow::where('user_id', $user->id)->findOrFail($flowId);
        $flow->load('template');

        $template = $flow->template;
        $stepData = $flow->step_data ?? [];
        $fields = $stepData['fields'] ?? ($template->fields_json ?? []);

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
        $signingSetup = $stepData['signing_setup'] ?? [];
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
        $docName = $isPackFlow ? ($stepData['pack_name'] ?? $template->name) : $template->name;
        if ($firstRecipientName) $docName .= ' — ' . $firstRecipientName;
        $docName .= ' — ' . now()->format('Y-m-d');

        $signatureService = app(SignatureService::class);
        $webTemplateDataService = app(WebTemplateDataService::class);

        // Resolve web template data
        $webTemplateData = null;
        if ($isPackFlow && !empty($stepData['template_ids'])) {
            // Pack flow: merge all templates into one document
            $templateIds = $stepData['template_ids'];
            $mergedHtml = '';
            $packTemplateData = [];

            foreach ($templateIds as $idx => $tplId) {
                $tpl = Template::find($tplId);
                if (!$tpl || !$tpl->blade_view) continue;

                $tplData = $webTemplateDataService->resolve($tplId, $stepData, $user);

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
        }

        $packInstanceId = $isPackFlow ? (int) round(microtime(true) * 1000) : null;

        $result = DB::transaction(function () use ($user, $flow, $template, $fields, $recipients, $signingSetup, $docName, $propertyAddress, $signatureService, $webTemplateData, $packInstanceId) {
            // 1. Create Document
            $document = Document::create([
                'name'             => $docName,
                'template_id'      => $template->id,
                'fields_json'      => $fields,
                'owner_id'         => $user->id,
                'branch_id'        => $user->effectiveBranchId(),
                'property_address' => $propertyAddress,
                'property_id'      => $flow->property_id,
                'document_type'    => $template->template_type,
                'web_template_data' => $webTemplateData,
                'pack_instance_id' => $packInstanceId,
            ]);

            // 2. Create SignatureTemplate
            $roleAliases = [
                'landlord' => 'landlord', 'tenant' => 'tenant',
                'buyer' => 'buyer', 'seller' => 'seller',
                'agent' => 'agent', 'witness' => 'witness',
            ];

            // Agent is always first party (signing_order=1)
            $parties = [
                ['role' => 'agent', 'name' => $user->name, 'email' => $user->email, 'id_number' => ''],
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

            foreach ($orderedRecipients as $r) {
                $role = $roleAliases[$r['role'] ?? 'other'] ?? ($r['role'] ?? 'other');
                if ($role === 'agent') continue;
                $parties[] = [
                    'role'      => $role,
                    'name'      => $r['name'] ?? '',
                    'email'     => $r['email'] ?? '',
                    'id_number' => $r['id_number'] ?? '',
                ];
                $signingOrder[] = $role;
            }

            $documentHash = hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $sigTemplate = SignatureTemplate::create([
                'document_id'        => $document->id,
                'document_hash'      => $documentHash,
                'status'             => SignatureTemplate::STATUS_DRAFT,
                'parties_json'       => $parties,
                'signing_order_json' => $signingOrder,
                'created_by'         => $user->id,
            ]);

            // 3. Create SignatureRequests — agent first (signing_order=1), then recipients
            $signatureService->createSigningRequest(
                $sigTemplate,
                'agent',
                $user->name,
                $user->email,
                null,
                null,
                $user
            );

            foreach ($orderedRecipients as $i => $r) {
                $role = $roleAliases[$r['role'] ?? 'other'] ?? ($r['role'] ?? 'other');
                if ($role === 'agent') continue;
                // Match to signing_setup by index (agent is index 0 in setup, so offset by 1)
                $setupIdx = $i;
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

                // skipEmail = in-person signing (no email sent)
                if ($skipEmail) {
                    $action = 'sign_later';
                }
                // No email = deferred
                if (empty($email)) {
                    $action = 'sign_later';
                }

                $signatureService->createSigningRequest(
                    $sigTemplate,
                    $role,
                    $r['name'] ?? '',
                    $skipEmail ? '' : $email,
                    $r['id_number'] ?? null,
                    null,
                    $user
                );
            }

            // 4. Convert template signature zones to markers
            $markerCount = $signatureService->convertZonesToMarkers($sigTemplate);

            // Fallback: create markers from fields_json sign/initial fields
            if ($markerCount === 0) {
                $markerCount = $signatureService->convertFieldsJsonToMarkers($sigTemplate, $fields);
            }

            // Final fallback: create one default signature marker per party
            if ($markerCount === 0) {
                $signatureService->createDefaultMarkers($sigTemplate);
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
            $flow->current_step = 7;
            $flow->save();

            return $document;
        });

        // Store wizard context in session so signComplete redirects back to wizard
        session(['esign_wizard_flow_id' => $flow->id]);

        // Redirect to existing signature setup interface (agent places markers, then signs)
        $signingUrl = route('docuperfect.signatures.setup', ['document' => $result->id]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'redirect' => $signingUrl]);
        }

        return redirect($signingUrl);
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
        // Load named field source mappings
        $namedFieldMappings = DB::table('docuperfect_named_fields')
            ->whereNotNull('source_type')
            ->where('source_type', '!=', 'manual')
            ->get()
            ->keyBy('id');

        // Build data pools from step_data
        $property   = $stepData['property'] ?? [];
        $recipients = $stepData['recipients']['recipients'] ?? [];
        $details    = $stepData['details'] ?? [];
        $agent      = auth()->user();

        // Build contact lookup by role (ucfirst to match source_contact_type)
        // If recipient has _contact_id, enrich with full DB data (bank details etc.)
        $contactsByRole = [];
        foreach ($recipients as $r) {
            $role = ucfirst($r['role'] ?? '');
            if (!$role || isset($contactsByRole[$role])) continue;

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

            $contactsByRole[$role] = $r;
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

            $value = $this->resolveFieldValue($sourceType, $sourceColumn, $contactType, $property, $contactsByRole, $details, $agent);

            if ($value !== null && $value !== '') {
                $field['value'] = (string) $value;
            }
        }
        unset($field);

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
                $contact = $contactsByRole[$contactType] ?? null;
                if (!$contact) return null;
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
            'first_name+last_name' => $contact['name'] ?? '',
            'address'              => $contact['address'] ?? '',
            'id_number'            => $contact['id_number'] ?? '',
            'email'                => $contact['email'] ?? '',
            'phone'                => $contact['cell'] ?? $contact['phone'] ?? '',
            'bank_name'            => $contact['bank_name'] ?? '',
            'bank_account_name'    => $contact['bank_account_name'] ?? '',
            'bank_account_number'  => $contact['bank_account_number'] ?? '',
            'bank_branch_name'     => $contact['bank_branch_name'] ?? '',
            default                => '',
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
}
