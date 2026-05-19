<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\LeaseRecord;
use App\Models\Docuperfect\Signature;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\SignatureZone;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\WetInkInspection;
use App\Services\Docuperfect\DocumentFlattener;
use App\Services\Docuperfect\SignaturePdfService;
use App\Models\Docuperfect\NamedField;
use App\Services\Docuperfect\SignatureService;
use App\Services\Docuperfect\LetterheadRefresher;
use App\Services\Docuperfect\SignatureSurfaceNormalizer;
use App\Services\PermissionService;
use App\Services\WebTemplateFieldPartyMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SignatureController extends Controller
{
    protected SignatureService $signatureService;

    public function __construct(SignatureService $signatureService)
    {
        $this->signatureService = $signatureService;
    }

    // ──────────────────────────────────────────────
    // Rental Dashboard (fully implemented)
    // ──────────────────────────────────────────────

    /**
     * Show the rental documents dashboard grouped by status.
     */
    public function rentalDashboard(Request $request)
    {
        $user = $request->user();
        $data = $this->signatureService->getRentalDashboardData($user);

        return view('docuperfect.rental.dashboard', [
            'groups' => $data['groups'],
            'signatureTemplates' => $data['signatureTemplates'],
            'fieldStatus' => $data['fieldStatus'],
            'counts' => $data['counts'],
            'upcomingRenewals' => $data['upcomingRenewals'],
            'expiredLeases' => $data['expiredLeases'],
            'activeLeases' => $data['activeLeases'],
            'activeLeaseCount' => $data['activeLeaseCount'],
            'lastUpdate' => $data['lastUpdate'] ?? '',
            'user' => $user,
        ]);
    }

    // ──────────────────────────────────────────────
    // Rental Upload & Send (standalone flow)
    // ──────────────────────────────────────────────

    /**
     * Show the rental upload-and-send form.
     */
    public function showUploadAndSend()
    {
        return view('rental.upload-and-send');
    }

    /**
     * Process rental upload-and-send: create document, flatten, build signing chain, send.
     */
    public function processUploadAndSend(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'document_name'           => 'required|string|max:255',
            'uploaded_file'           => 'required|file|mimes:pdf,doc,docx|max:20480',
            'property_reference'      => 'nullable|string|max:255',
            'recipients'              => 'required|array|min:1',
            'recipients.*.name'       => 'required|string|max:255',
            'recipients.*.email'      => 'required|email',
            'recipients.*.role'       => 'required|string|max:100',
            'recipients.*.id_number'  => 'required|string|max:20',
            'message'                 => 'nullable|string|max:1000',
        ]);

        $filePath = $request->file('uploaded_file')->store('docuperfect/rental-upload-send', 'local');

        $document = null;

        try {
            DB::transaction(function () use ($request, $user, $filePath, &$document) {
                // 1. Create a Docuperfect Document record for this standalone upload
                $document = Document::create([
                    'name'             => $request->input('document_name'),
                    'owner_id'         => $user->id,
                    'branch_id'        => $user->branch_id,
                    'document_type'    => 'rental_upload_send',
                    'property_address' => $request->input('property_reference'),
                ]);

                // 2. Build signing order from recipients
                $recipientData = $request->input('recipients');
                $signingOrder = ['agent'];
                foreach ($recipientData as $r) {
                    $signingOrder[] = strtolower($r['role']);
                }

                // 3. Build parties_json (agent + recipients)
                $parties = [
                    [
                        'role'  => 'agent',
                        'name'  => $user->name,
                        'email' => $user->email,
                    ],
                ];
                foreach ($recipientData as $r) {
                    $parties[] = [
                        'role'      => strtolower($r['role']),
                        'name'      => $r['name'],
                        'email'     => $r['email'],
                        'id_number' => $r['id_number'],
                    ];
                }

                // 4. Create SignatureTemplate
                $template = SignatureTemplate::create([
                    'document_id'        => $document->id,
                    'status'             => SignatureTemplate::STATUS_DRAFT,
                    'created_by'         => $user->id,
                    'signing_order_json' => $signingOrder,
                    'parties_json'       => $parties,
                ]);

                // 5. Flatten the uploaded file into page images
                $flattener = app(DocumentFlattener::class);
                $flattener->flattenWetInkScan($template, [$filePath]);

                // 6. Create SignatureRequests — agent first (pre-completed), then recipients
                $agentRequest = SignatureRequest::create([
                    'signature_template_id' => $template->id,
                    'party_role'            => 'agent',
                    'signing_order'         => 1,
                    'signer_name'           => $user->name,
                    'signer_email'          => $user->email,
                    'token'                 => Str::random(64),
                    'token_expires_at'      => now()->addDays(30),
                    'status'                => SignatureRequest::STATUS_COMPLETED,
                    'signing_method'        => 'wet_ink',
                    'completed_at'          => now(),
                    'sent_by'               => $user->id,
                ]);

                foreach ($recipientData as $index => $r) {
                    $order = $index + 2; // agent is 1

                    SignatureRequest::create([
                        'signature_template_id' => $template->id,
                        'party_role'            => strtolower($r['role']),
                        'signing_order'         => $order,
                        'signer_name'           => $r['name'],
                        'signer_email'          => $r['email'],
                        'signer_id_number'      => $r['id_number'],
                        'token'                 => Str::random(64),
                        'token_expires_at'      => now()->addDays(14),
                        'status'                => SignatureRequest::STATUS_WAITING,
                        'sent_by'               => $user->id,
                        'message'               => $request->input('message'),
                    ]);
                }

                // 7. Mark template as ready — agent will place markers on setup page before sending
                $template->update(['status' => SignatureTemplate::STATUS_READY]);
            });
        } catch (\Throwable $e) {
            Log::error('processUploadAndSend failed', [
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
                'user_id' => $user->id,
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to process document: ' . $e->getMessage());
        }

        // 8. Redirect to setup page — agent places signature markers, then sends from there
        return redirect()->route('docuperfect.signatures.setup', $document)
            ->with('status', 'Document uploaded. Place signature markers and click Send for Signing.');
    }

    // ──────────────────────────────────────────────
    // Signature setup
    // ──────────────────────────────────────────────

    /**
     * Show signature setup page for a document.
     */
    public function setup(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        // Field completion gate
        $validation = $this->signatureService->validateFieldCompletion($document);
        if (!$validation['valid']) {
            $filled = count(($document->fields_json ?? []));
            $total = $filled + count($validation['missing']);
            return redirect()->back()->with('error',
                "Complete all document fields before setting up signatures. {$filled}/{$total} fields completed."
            )->with('missing_fields', $validation['missing']);
        }

        // Get or create signature template with dynamic signing order from template config
        $docTpl = $document->template;
        $defaultSignOrder = $this->buildDefaultSigningOrder($docTpl);
        $template = SignatureTemplate::firstOrCreate(
            ['document_id' => $document->id],
            [
                'status' => SignatureTemplate::STATUS_DRAFT,
                'created_by' => $user->id,
                'signing_order_json' => $defaultSignOrder,
            ]
        );

        // Auto-convert template signature zones to markers (idempotent)
        if ($template->isDraft()) {
            $this->signatureService->convertZonesToMarkers($template);
        }

        // Load existing markers (including any just created from zones)
        $markers = $template->markers()->orderBy('page_number')->orderBy('sort_order')->get();

        // Load dynamic signature zones
        $zones = $template->zones()->orderBy('page_number')->orderBy('sort_order')->get();

        // Build page image URLs — use flattened images when available
        $docTemplate = $document->template;
        $flattenedPages = $template->flattened_pages_json ?? [];
        $hasFlattened = !empty($flattenedPages);
        $pageImages = [];

        // Detect web template rendering — but check for flattened document images first
        $isWebTemplate = false;
        $webTemplateHtml = '';
        $webTemplateData = $document->web_template_data ?? [];
        $hasDocumentPages = !empty($webTemplateData['flattened_page_count']);

        if ($hasDocumentPages) {
            // Web template was flattened to page images — treat as PDF from here
            $pageCount = (int) $webTemplateData['flattened_page_count'];

            for ($n = 0; $n < $pageCount; $n++) {
                $pageImages[] = route('docuperfect.documents.pageImage', ['id' => $document->id, 'page' => $n]);
            }
        } elseif ($docTemplate && $docTemplate->render_type === 'web' && $docTemplate->blade_view) {
            // Fallback: web template without flattening — use iframe (legacy path)
            $isWebTemplate = true;

            if (!empty($webTemplateData['merged_html'])) {
                $webTemplateHtml = $webTemplateData['merged_html'];
                $pageCount = count($webTemplateData['template_ids'] ?? [1]);
            } else {
                // Single template — render blade view normally
                $viewData = $webTemplateData;
                if (!empty($docTemplate->signing_parties)) {
                    $viewData['signing_parties'] = $docTemplate->signing_parties;
                }
                $fullHtml = view($docTemplate->blade_view, $viewData)->render();
                $bodyHtml = $fullHtml;
                if (preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $m)) {
                    $bodyHtml = trim($m[1]);
                }
                $styles = '';
                if (preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches)) {
                    $styles = implode("\n", $styleMatches[0]);
                }
                $webTemplateHtml = $styles . $bodyHtml;
                $pageCount = 1;
            }

            // Markers/setup uniquely bypassed the signing-path normalisation
            // that every other web-template render runs (cf. lines ~926-927,
            // SigningController). Two consequences this fixes:
            //   1. Stale letterhead: the stored merged_html snapshot is frozen
            //      with whatever agency data existed at prepareSigning (old
            //      FFC / "Mandate Company"). LetterheadRefresher swaps in the
            //      live company-header (current HFC agency).
            //   2. Layout regression: the snapshot can carry unbalanced <div>
            //      tags; injected raw via {!! !!} those stray closes climb the
            //      DOM and break the flex two-column row (panel drops below) —
            //      min-w-0 cannot defend a broken DOM. LetterheadRefresher's
            //      DOMDocument round-trip re-serialises BALANCED markup, so the
            //      document can no longer over-close its column regardless of
            //      what the template HTML contains.
            if ($webTemplateHtml !== '') {
                $webTemplateHtml = SignatureSurfaceNormalizer::normalize($webTemplateHtml);
                $webTemplateHtml = LetterheadRefresher::refresh($webTemplateHtml);
            }
        } else {
            $pageCount = $hasFlattened ? count($flattenedPages) : ($docTemplate ? $docTemplate->page_count : 0);

            for ($n = 0; $n < $pageCount; $n++) {
                if ($hasFlattened && isset($flattenedPages[$n])) {
                    $pageImages[] = route('docuperfect.signatures.flattenedPage', ['templateId' => $template->id, 'page' => $n]);
                } elseif ($docTemplate) {
                    $pageImages[] = route('docuperfect.page.image', ['id' => $docTemplate->id, 'page' => $n]);
                }
            }
        }

        // Determine which step to show
        $parties = $template->parties_json ?? [];
        $step = !empty($parties) ? 2 : 1;

        // If step query param is provided, allow going back to step 1
        if ($request->query('step') === '1') {
            $step = 1;
        }

        // Determine template type for dynamic party labels (buyer/seller vs tenant/landlord)
        // Use isSalesDocument() for layered detection (signing_parties > name) instead of raw template_type
        $templateType = ($docTemplate && $docTemplate->isSalesDocument()) ? 'sales' : 'rentals';

        // E-sign wizard context — allows back navigation to the wizard
        $esignFlowId = session('esign_wizard_flow_id');

        return view('docuperfect.signatures.setup', [
            'document' => $document,
            'template' => $template,
            'sigTemplate' => $template,
            'markers' => $markers,
            'zones' => $zones,
            'parties' => $parties,
            'pageImages' => $pageImages,
            'pageCount' => $pageCount,
            'hasFlattened' => $hasFlattened,
            'isWebTemplate' => $isWebTemplate,
            'webTemplateHtml' => $webTemplateHtml,
            'step' => $step,
            'user' => $user,
            'templateType' => $templateType,
            'esignFlowId' => $esignFlowId,
        ]);
    }

    /**
     * Upload a pre-signed (wet ink) document scan.
     * Creates/updates the signature template with flattened page images
     * so the agent section is treated as already signed.
     */
    public function uploadPresigned(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $request->validate([
            'presigned_files' => 'required|array|min:1',
            'presigned_files.*' => 'required|file|mimes:pdf,jpg,jpeg,png|max:20480',
        ]);

        // Store uploaded files
        $uploadPaths = [];
        foreach ($request->file('presigned_files') as $file) {
            $uploadPaths[] = $file->store("docuperfect/presigned-uploads/{$document->id}", 'local');
        }

        // Get or create signature template with dynamic signing order
        $docTpl = $document->template;
        $defaultSignOrder = $this->buildDefaultSigningOrder($docTpl);
        $template = SignatureTemplate::firstOrCreate(
            ['document_id' => $document->id],
            [
                'status' => SignatureTemplate::STATUS_DRAFT,
                'created_by' => $user->id,
                'signing_order_json' => $defaultSignOrder,
            ]
        );

        // Flatten uploaded files into page images
        $flattener = app(DocumentFlattener::class);
        $flattener->flattenWetInkScan($template, $uploadPaths);

        // Mark template as ready for party/marker setup
        $template->update(['status' => SignatureTemplate::STATUS_READY]);

        return redirect()->route('docuperfect.signatures.setup', $document)
            ->with('status', 'Document uploaded. Set up signing parties and markers.');
    }

    /**
     * Save parties for a document's signature template.
     */
    public function saveParties(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        // Determine template type for party role labels
        $isSales = $document->template ? $document->template->isSalesDocument() : false;

        // Party role labels differ by template type
        $partyOneRole = $isSales ? 'buyer' : 'tenant';
        $partyTwoRole = $isSales ? 'seller' : 'landlord';

        $partyOneNotRequired = $request->boolean("{$partyOneRole}_not_required");
        $partyTwoNotRequired = $request->boolean("{$partyTwoRole}_not_required");

        // Build validation rules — only validate active parties
        $rules = [
            'agent_name' => 'required|string|max:255',
            'agent_email' => 'required|email|max:255',
            "{$partyOneRole}_not_required" => 'nullable|boolean',
            "{$partyTwoRole}_not_required" => 'nullable|boolean',
        ];

        if (!$partyOneNotRequired) {
            $rules["{$partyOneRole}_name"] = 'required|string|max:255';
            $rules["{$partyOneRole}_email"] = 'required|email|max:255';
            $rules["{$partyOneRole}_id_number"] = 'required|string|max:20';
            $rules["add_{$partyOneRole}_witness"] = 'nullable|boolean';
            $rules["{$partyOneRole}_witness_name"] = "required_if:add_{$partyOneRole}_witness,1|nullable|string|max:255";
            $rules["{$partyOneRole}_witness_email"] = "required_if:add_{$partyOneRole}_witness,1|nullable|email|max:255";
        }

        if (!$partyTwoNotRequired) {
            $rules["{$partyTwoRole}_name"] = 'required|string|max:255';
            $rules["{$partyTwoRole}_email"] = 'required|email|max:255';
            $rules["{$partyTwoRole}_id_number"] = 'required|string|max:20';
            $rules["add_{$partyTwoRole}_witness"] = 'nullable|boolean';
            $rules["{$partyTwoRole}_witness_name"] = "required_if:add_{$partyTwoRole}_witness,1|nullable|string|max:255";
            $rules["{$partyTwoRole}_witness_email"] = "required_if:add_{$partyTwoRole}_witness,1|nullable|email|max:255";
        }

        $request->validate($rules);

        // Build parties array — only include active parties
        $parties = [
            ['role' => 'agent', 'name' => $request->agent_name, 'email' => $request->agent_email, 'id_number' => null],
        ];

        $signingOrder = ['agent'];

        if (!$partyOneNotRequired) {
            $parties[] = [
                'role' => $partyOneRole,
                'name' => $request->input("{$partyOneRole}_name"),
                'email' => $request->input("{$partyOneRole}_email"),
                'id_number' => $request->input("{$partyOneRole}_id_number"),
            ];
            $signingOrder[] = $partyOneRole;

            if ($request->boolean("add_{$partyOneRole}_witness")) {
                $parties[] = [
                    'role' => "{$partyOneRole}_witness",
                    'name' => $request->input("{$partyOneRole}_witness_name"),
                    'email' => $request->input("{$partyOneRole}_witness_email"),
                    'id_number' => null,
                ];
            }
        }

        if (!$partyTwoNotRequired) {
            $parties[] = [
                'role' => $partyTwoRole,
                'name' => $request->input("{$partyTwoRole}_name"),
                'email' => $request->input("{$partyTwoRole}_email"),
                'id_number' => $request->input("{$partyTwoRole}_id_number"),
            ];
            $signingOrder[] = $partyTwoRole;

            if ($request->boolean("add_{$partyTwoRole}_witness")) {
                $parties[] = [
                    'role' => "{$partyTwoRole}_witness",
                    'name' => $request->input("{$partyTwoRole}_witness_name"),
                    'email' => $request->input("{$partyTwoRole}_witness_email"),
                    'id_number' => null,
                ];
            }
        }

        // All core (non-witness) roles
        $coreRoles = ['agent', 'tenant', 'landlord', 'buyer', 'seller'];

        // Get or create template
        $template = SignatureTemplate::firstOrCreate(
            ['document_id' => $document->id],
            [
                'status' => SignatureTemplate::STATUS_DRAFT,
                'created_by' => $user->id,
                'signing_order_json' => $signingOrder,
            ]
        );

        // Generate document hash
        $hash = $this->signatureService->generateDocumentHash($document);

        $template->update([
            'parties_json' => $parties,
            'signing_order_json' => $signingOrder,
            'document_hash' => $hash,
        ]);

        // Create signing requests for active core parties only
        // Track used request IDs to handle co-owners (multiple parties with same role)
        $activeRoles = collect($parties)->pluck('role')->intersect($coreRoles)->all();
        $usedRequestIds = [];

        foreach ($parties as $party) {
            // Only create requests for core signing roles
            if (!in_array($party['role'], $coreRoles)) {
                continue;
            }

            $existing = $template->requests()
                ->where('party_role', $party['role'])
                ->whereNotIn('id', $usedRequestIds)
                ->first();

            if ($existing) {
                $existing->update([
                    'signer_name' => $party['name'],
                    'signer_email' => $party['email'],
                    'signer_id_number' => $party['id_number'] ?? null,
                ]);
                $usedRequestIds[] = $existing->id;
            } else {
                $req = $this->signatureService->createSigningRequest(
                    $template,
                    $party['role'],
                    $party['name'],
                    $party['email'],
                    $party['id_number'] ?? null,
                    sentBy: $user,
                );
                $usedRequestIds[] = $req->id;
            }
        }

        // Remove signing requests for parties that are no longer active
        // Keep all requests that were just created/updated, remove others for removable roles
        $removableRoles = array_diff($coreRoles, ['agent']);
        $template->requests()
            ->whereIn('party_role', $removableRoles)
            ->whereNotIn('id', $usedRequestIds)
            ->delete();

        // Remove markers assigned to parties that are no longer active
        $template->markers()
            ->whereIn('assigned_party', $removableRoles)
            ->whereNotIn('assigned_party', $activeRoles)
            ->delete();

        // If pre-signed upload exists, mark agent's request as completed (wet ink)
        if (!empty($template->flattened_pages_json)) {
            $agentReq = $template->requests()
                ->where('party_role', 'agent')
                ->first();
            if ($agentReq && $agentReq->status !== SignatureRequest::STATUS_COMPLETED) {
                $agentReq->update([
                    'status' => SignatureRequest::STATUS_COMPLETED,
                    'signing_method' => 'wet_ink',
                    'completed_at' => now(),
                ]);
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('docuperfect.signatures.setup', $document)
            ->with('status', 'Parties saved. Now place signature markers on the document.');
    }

    /**
     * Save markers (JSON API).
     */
    public function saveMarkers(Request $request, Document $document)
    {
        $this->authorizeDocument($request->user(), $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        // Build allowed parties from the template's active parties (all roles including numbered suffixes)
        $allowedParties = collect($template->parties_json ?? [])
            ->pluck('role')
            ->unique()
            ->implode(',');

        $request->validate([
            'markers' => 'required|array',
            'markers.*.page_number' => 'required|integer|min:1',
            'markers.*.x_position' => 'required|numeric|min:0|max:100',
            'markers.*.y_position' => 'required|numeric|min:0|max:100',
            'markers.*.width' => 'required|numeric|min:0|max:100',
            'markers.*.height' => 'required|numeric|min:0|max:100',
            'markers.*.type' => 'required|string|in:signature,initial,date,text',
            'markers.*.assigned_party' => 'required|string|in:' . $allowedParties,
            'markers.*.assigned_email' => 'nullable|email|max:255',
            'markers.*.label' => 'nullable|string|max:255',
        ]);

        try {
            $count = $this->signatureService->saveMarkers($template, $request->input('markers'));
        } catch (\LogicException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'count' => $count]);
    }

    /**
     * Update markers (same as save — PUT variant).
     */
    public function updateMarkers(Request $request, Document $document)
    {
        return $this->saveMarkers($request, $document);
    }

    // ──────────────────────────────────────────────
    // Dynamic Signature Zones
    // ──────────────────────────────────────────────

    /**
     * Get all zones for a document's signature template (JSON API).
     */
    public function getZones(Request $request, Document $document)
    {
        $this->authorizeDocument($request->user(), $document);
        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        $zones = $template->zones()
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->get()
            ->map(function (SignatureZone $zone) {
                return [
                    'id' => $zone->id,
                    'zone_type' => $zone->zone_type,
                    'party_role' => $zone->party_role,
                    'page_number' => $zone->page_number,
                    'x_position' => (float) $zone->x_position,
                    'y_position' => (float) $zone->y_position,
                    'width' => (float) $zone->width,
                    'height' => (float) $zone->height,
                    'is_auto_placed' => $zone->is_auto_placed,
                    'source' => $zone->source,
                    'label' => $zone->label,
                    'marker_count' => $zone->expandedMarkers()->count(),
                ];
            });

        return response()->json(['ok' => true, 'zones' => $zones]);
    }

    /**
     * Create a new zone (JSON API — user drew a bounding box on setup screen).
     */
    public function storeZone(Request $request, Document $document)
    {
        $this->authorizeDocument($request->user(), $document);
        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        $request->validate([
            'zone_type' => 'required|in:signature,initial',
            'party_role' => 'required|string|max:50',
            'assigned_parties' => 'nullable|array',
            'assigned_parties.*' => 'string|max:50',
            'page_number' => 'required|integer|min:1',
            'x_position' => 'required|numeric|min:0|max:100',
            'y_position' => 'required|numeric|min:0|max:100',
            'width' => 'required|numeric|min:3|max:100',
            'height' => 'required|numeric|min:2|max:100',
            'label' => 'nullable|string|max:255',
        ]);

        $zone = $this->signatureService->saveZone($template, $request->only([
            'zone_type', 'party_role', 'assigned_parties', 'page_number',
            'x_position', 'y_position', 'width', 'height', 'label',
        ]));

        $markers = $zone->expandedMarkers()->get()->map(fn ($m) => [
            'id' => $m->id,
            'page_number' => $m->page_number,
            'x_position' => (float) $m->x_position,
            'y_position' => (float) $m->y_position,
            'width' => (float) $m->width,
            'height' => (float) $m->height,
            'type' => $m->type,
            'assigned_party' => $m->assigned_party,
            'label' => $m->label,
        ]);

        return response()->json([
            'ok' => true,
            'zone' => [
                'id' => $zone->id,
                'zone_type' => $zone->zone_type,
                'party_role' => $zone->party_role,
                'assigned_parties' => $zone->assigned_parties ?? [$zone->party_role],
                'page_number' => $zone->page_number,
                'x_position' => (float) $zone->x_position,
                'y_position' => (float) $zone->y_position,
                'width' => (float) $zone->width,
                'height' => (float) $zone->height,
                'label' => $zone->label,
            ],
            'markers' => $markers,
        ]);
    }

    /**
     * Batch-create zones from DOM positions (JSON API).
     * Used by setup screen JS to create all zones in one request after
     * scanning actual DOM element positions.
     */
    public function batchStoreZones(Request $request, Document $document)
    {
        $this->authorizeDocument($request->user(), $document);
        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        $request->validate([
            'zones' => 'required|array|min:1',
            'zones.*.zone_type' => 'required|in:signature,initial',
            'zones.*.party_role' => 'required|string|max:50',
            'zones.*.page_number' => 'required|integer|min:1',
            'zones.*.x_position' => 'required|numeric|min:0|max:100',
            'zones.*.y_position' => 'required|numeric|min:0|max:100',
            'zones.*.width' => 'required|numeric|min:1|max:100',
            'zones.*.height' => 'required|numeric|min:1|max:100',
            'zones.*.label' => 'nullable|string|max:255',
        ]);

        // Clear existing auto-placed zones before recreating from DOM
        $template->zones()->where('is_auto_placed', true)->get()->each(function ($z) use ($template) {
            $template->markers()->where('from_zone_id', $z->id)->forceDelete();
            $z->delete();
        });

        $createdZones = [];
        $allMarkers = [];

        foreach ($request->input('zones') as $zoneData) {
            $zone = $this->signatureService->saveZone($template, array_merge($zoneData, [
                'is_auto_placed' => true,
                'source' => 'dom',
            ]));

            $zoneMarkers = $zone->expandedMarkers()->get()->map(fn ($m) => [
                'id' => $m->id,
                'page_number' => $m->page_number,
                'x_position' => (float) $m->x_position,
                'y_position' => (float) $m->y_position,
                'width' => (float) $m->width,
                'height' => (float) $m->height,
                'type' => $m->type,
                'assigned_party' => $m->assigned_party,
                'label' => $m->label,
                'from_zone_id' => $m->from_zone_id,
            ]);

            $createdZones[] = [
                'id' => $zone->id,
                'zone_type' => $zone->zone_type,
                'party_role' => $zone->party_role,
                'page_number' => $zone->page_number,
                'x_position' => (float) $zone->x_position,
                'y_position' => (float) $zone->y_position,
                'width' => (float) $zone->width,
                'height' => (float) $zone->height,
                'is_auto_placed' => true,
                'source' => 'dom',
                'label' => $zone->label,
                'marker_count' => $zoneMarkers->count(),
                'markers' => $zoneMarkers->toArray(),
            ];

            $allMarkers = array_merge($allMarkers, $zoneMarkers->toArray());
        }

        return response()->json([
            'ok' => true,
            'zones' => $createdZones,
            'markers' => $allMarkers,
        ]);
    }

    /**
     * Update a zone (resize/move — JSON API).
     */
    public function updateZone(Request $request, Document $document, SignatureZone $zone)
    {
        $this->authorizeDocument($request->user(), $document);

        $request->validate([
            'zone_type' => 'sometimes|in:signature,initial',
            'party_role' => 'sometimes|string|max:50',
            'page_number' => 'sometimes|integer|min:1',
            'x_position' => 'sometimes|numeric|min:0|max:100',
            'y_position' => 'sometimes|numeric|min:0|max:100',
            'width' => 'sometimes|numeric|min:3|max:100',
            'height' => 'sometimes|numeric|min:2|max:100',
            'label' => 'nullable|string|max:255',
        ]);

        $zone = $this->signatureService->updateZone($zone, $request->only([
            'zone_type', 'party_role', 'page_number',
            'x_position', 'y_position', 'width', 'height', 'label',
        ]));

        $markers = $zone->expandedMarkers()->get()->map(fn ($m) => [
            'id' => $m->id,
            'page_number' => $m->page_number,
            'x_position' => (float) $m->x_position,
            'y_position' => (float) $m->y_position,
            'width' => (float) $m->width,
            'height' => (float) $m->height,
            'type' => $m->type,
            'assigned_party' => $m->assigned_party,
            'label' => $m->label,
            'from_zone_id' => $m->from_zone_id,
        ]);

        return response()->json(['ok' => true, 'zone' => $zone, 'markers' => $markers]);
    }

    /**
     * Delete a zone and its expanded markers (JSON API).
     */
    public function deleteZone(Request $request, Document $document, SignatureZone $zone)
    {
        $this->authorizeDocument($request->user(), $document);
        $this->signatureService->deleteZone($zone);

        return response()->json(['ok' => true]);
    }

    // ──────────────────────────────────────────────
    // Internal signing
    // ──────────────────────────────────────────────

    /**
     * Show the internal signing page (for agent).
     */
    public function sign(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        $docTemplate = $document->template;

        // Must have markers placed — but web templates use embedded document
        // elements instead of markers, so skip this check for them.
        $isWebRenderType = $docTemplate && ($docTemplate->render_type ?? 'pdf') === 'web';
        if (!$isWebRenderType && $template->markers()->count() === 0) {
            return redirect()->route('docuperfect.signatures.setup', $document)
                ->with('error', 'Place signature markers before signing.');
        }

        // Get all markers with their signatures
        $allMarkers = $template->markers()
            ->with(['signatures' => fn($q) => $q->select('id', 'signature_marker_id', 'signature_data', 'signature_type', 'signed_at')])
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->get();

        // Agent markers count
        $agentMarkers = $allMarkers->where('assigned_party', 'agent');
        $signedCount = $agentMarkers->filter(fn($m) => $m->signatures->isNotEmpty())->count();
        $totalAgent = $agentMarkers->count();

        // Build page image URLs — check for flattened document pages first
        $flattenedPages = $template->flattened_pages_json ?? [];
        $hasFlattened = !empty($flattenedPages);
        $pageImages = [];
        $webTemplateData = $document->web_template_data ?? [];
        $hasDocumentPages = !empty($webTemplateData['flattened_page_count']);
        $isWebTemplate = false;
        $webTemplateHtml = '';

        if ($hasDocumentPages) {
            // Flattened web template — treat as PDF (page images + overlay fields)
            $pageCount = (int) $webTemplateData['flattened_page_count'];
            for ($n = 0; $n < $pageCount; $n++) {
                if ($hasFlattened && isset($flattenedPages[$n])) {
                    $pageImages[] = route('docuperfect.signatures.flattenedPage', ['templateId' => $template->id, 'page' => $n]);
                } else {
                    $pageImages[] = route('docuperfect.documents.pageImage', ['id' => $document->id, 'page' => $n]);
                }
            }
        } elseif ($docTemplate && $docTemplate->render_type === 'web' && $docTemplate->blade_view) {
            // Fallback: web template without flattening — use iframe (legacy path)
            $isWebTemplate = true;

            // Merge filled field values from fields_json into web template data
            $webTemplateData = $this->mergeFieldsIntoWebTemplateData(
                $webTemplateData,
                $document->fields_json ?? []
            );

            if (!empty($webTemplateData['merged_html'])) {
                $webTemplateHtml = $webTemplateData['merged_html'];
                $pageCount = count($webTemplateData['template_ids'] ?? [1]);
            } else {
                $pageCount = 1;
                try {
                    if (!empty($docTemplate->signing_parties)) {
                        $webTemplateData['signing_parties'] = $docTemplate->signing_parties;
                    }
                    $fullHtml = view($docTemplate->blade_view, $webTemplateData)->render();
                    $styles = '';
                    preg_match_all('/<style[^>]*>.*?<\/style>/si', $fullHtml, $styleMatches);
                    if (!empty($styleMatches[0])) {
                        $styles = implode("\n", $styleMatches[0]);
                    }
                    $bodyHtml = '';
                    if (preg_match('/<body[^>]*>(.*)<\/body>/si', $fullHtml, $bodyMatch)) {
                        $bodyHtml = $bodyMatch[1];
                    } else {
                        $bodyHtml = $fullHtml;
                    }
                    $webTemplateHtml = trim($styles . "\n" . $bodyHtml);
                } catch (\Exception $e) {
                    $webTemplateHtml = '<p>Document preview unavailable.</p>';
                }
            }

            // Make inline-template signature blocks signable for the agent's
            // first-signer pass (same engine selector as the external signer);
            // additive + idempotent, never touches the template files (BL-5/6).
            $webTemplateHtml = SignatureSurfaceNormalizer::normalize($webTemplateHtml);
            $webTemplateHtml = LetterheadRefresher::refresh($webTemplateHtml);
        } else {
            $pageCount = $hasFlattened ? count($flattenedPages) : ($docTemplate ? $docTemplate->page_count : 0);
            for ($n = 0; $n < $pageCount; $n++) {
                if ($hasFlattened && isset($flattenedPages[$n])) {
                    $pageImages[] = route('docuperfect.signatures.flattenedPage', ['templateId' => $template->id, 'page' => $n]);
                } elseif ($docTemplate) {
                    $pageImages[] = route('docuperfect.page.image', ['id' => $docTemplate->id, 'page' => $n]);
                }
            }
        }

        // Section-by-section signing data
        $sections = $template->sections_json ?? [];
        $sectionAcceptances = [];
        if (!empty($sections)) {
            $agentRequest = $template->requests()->where('party_role', 'agent')->first();
            if ($agentRequest) {
                $sectionAcceptances = \App\Models\Docuperfect\SectionAcceptance::where('signature_request_id', $agentRequest->id)
                    ->get()
                    ->keyBy('section_index')
                    ->toArray();
            }
        }

        // Pass wizard flow ID so the sign page can include it in the webSignComplete request
        $esignFlowId = session('esign_wizard_flow_id');

        // Build signing parties for client-side pagination initials
        // Deduplicate supervisor/supervisor_final — same person, one initial block
        $signingParties = collect($template->parties_json ?? [])->filter(function ($p) {
            return ($p['role'] ?? '') !== 'supervisor_final';
        })->map(fn($p) => [
            'role' => $p['role'] ?? 'unknown',
            'label' => ucfirst(str_replace('_', ' ', $p['role_label'] ?? $p['role'] ?? 'unknown')),
        ])->unique('role')->values()->toArray();

        return view('docuperfect.signatures.sign', [
            'document' => $document,
            'template' => $template,
            'allMarkers' => $allMarkers,
            'signedCount' => $signedCount,
            'totalAgent' => $totalAgent,
            'allAgentSigned' => $signedCount >= $totalAgent && $totalAgent > 0,
            'pageImages' => $pageImages,
            'pageCount' => $pageCount,
            'hasFlattened' => $hasFlattened,
            'isWebTemplate' => $isWebTemplate,
            'webTemplateHtml' => $webTemplateHtml,
            'user' => $user,
            'sections' => $sections,
            'sectionAcceptances' => $sectionAcceptances,
            'isSalesTemplate' => $docTemplate ? $docTemplate->isSalesDocument() : false,
            'esignFlowId' => $esignFlowId,
            'signingParties' => $signingParties,
            'storedInitials' => $webTemplateData['signed_initials'] ?? [],
        ]);
    }

    /**
     * Capture a signature on a marker (internal).
     */
    public function captureSignature(Request $request, Document $document, SignatureMarker $marker)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $request->validate([
            'signature_data' => 'nullable|string',
            'text_value' => 'nullable|string|max:1000',
            'signature_type' => 'nullable|string|in:drawn,typed',
        ]);

        // At least one of signature_data or text_value must be provided
        if (!$request->input('signature_data') && !$request->input('text_value')) {
            return response()->json(['ok' => false, 'error' => 'Signature data or text value required.'], 422);
        }

        // Verify marker belongs to this document's template
        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        if ((int) $marker->signature_template_id !== (int) $template->id) {
            abort(403);
        }

        // Verify marker is assigned to agent
        if ($marker->assigned_party !== 'agent') {
            abort(403, 'This marker is not assigned to you.');
        }

        // Verify marker hasn't already been signed
        if ($marker->signatures()->exists()) {
            return response()->json(['ok' => false, 'error' => 'Already signed'], 409);
        }

        $signingRequest = SignatureRequest::where('signature_template_id', $template->id)
            ->where('signer_email', $user->email)
            ->first();

        $signature = $this->signatureService->captureSignature(
            $marker,
            $request->input('signature_data'),
            $user->name,
            $user->email,
            $request->ip(),
            $request->userAgent(),
            $signingRequest,
            $user,
            $request->input('signature_type', 'drawn'),
            $request->input('text_value'),
        );

        // Check if all agent markers are now signed
        $allAgentSigned = $this->signatureService->isPartyComplete($template, 'agent');
        $signedCount = $template->signatures()
            ->whereHas('marker', fn($q) => $q->where('assigned_party', 'agent'))
            ->count();
        $totalAgent = $template->markers()
            ->where('assigned_party', 'agent')
            ->where('required', true)
            ->count();

        return response()->json([
            'ok' => true,
            'signature_id' => $signature->id,
            'all_agent_signed' => $allAgentSigned,
            'signed_count' => $signedCount,
            'total_agent' => $totalAgent,
        ]);
    }

    /**
     * Save agent-assigned field values during agent signing.
     */
    public function saveAgentFields(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $submittedFields = $request->input('fields', []);
        $currentFields = $document->fields_json ?? [];

        // Only update fields where assignedTo === 'agent'
        foreach ($submittedFields as $submitted) {
            if (($submitted['assignedTo'] ?? 'creator') !== 'agent') {
                continue;
            }
            foreach ($currentFields as &$field) {
                if (($field['id'] ?? null) === ($submitted['id'] ?? null) && ($field['assignedTo'] ?? 'creator') === 'agent') {
                    // Update mutable values based on type
                    $type = $field['type'] ?? 'placeholder';
                    if (in_array($type, ['placeholder', 'date'])) {
                        $field['value'] = $submitted['value'] ?? '';
                    } elseif (in_array($type, ['tick', 'selection'])) {
                        $field['selectedValue'] = $submitted['selectedValue'] ?? null;
                    } elseif ($type === 'condition') {
                        $field['text'] = $submitted['text'] ?? '';
                    } elseif ($type === 'strikethrough') {
                        $field['active'] = !empty($submitted['active']);
                    }
                    break;
                }
            }
            unset($field);
        }

        $document->fields_json = $currentFields;
        $document->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Save agent-editable web template field values during internal signing.
     */
    public function saveAgentWebFields(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $incomingFields = $request->input('fields', []);
        $allowedFields = WebTemplateFieldPartyMap::getEditableFields('agent');

        $existingData = $document->web_template_data ?? [];
        $updated = false;

        foreach ($incomingFields as $fieldName => $value) {
            if (!is_string($fieldName) || !in_array($fieldName, $allowedFields, true)) {
                continue;
            }
            $existingData[$fieldName] = $value;
            $updated = true;
        }

        if ($updated) {
            $document->update(['web_template_data' => $existingData]);
        }

        $template = SignatureTemplate::where('document_id', $document->id)->first();
        if ($template) {
            SignatureAuditLog::create([
                'signature_template_id' => $template->id,
                'action' => 'web_fields_saved',
                'actor_type' => SignatureAuditLog::ACTOR_AGENT,
                'actor_name' => $user->name,
                'actor_email' => $user->email,
                'actor_ip_address' => $request->ip(),
                'actor_user_agent' => $request->userAgent(),
                'metadata_json' => ['party_role' => 'agent', 'field_count' => count($incomingFields)],
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Complete internal signing for a document.
     */
    public function signComplete(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)
            ->with(['document.template', 'markers.signatures'])
            ->firstOrFail();

        // Verify all agent markers signed
        if (!$this->signatureService->isPartyComplete($template, 'agent')) {
            return redirect()->back()->with('error', 'Sign all your markers before completing.');
        }

        // Validate required fields assigned to agent are completed
        $docFields = $document->fields_json ?? [];
        $docTemplate = $document->template;
        $templateFields = $docTemplate ? ($docTemplate->fields_json ?? []) : [];
        $missingFields = [];
        foreach ($templateFields as $tField) {
            if (empty($tField['required'])) continue;
            if (($tField['assignedTo'] ?? 'creator') !== 'agent') continue;

            $fieldId = $tField['id'] ?? null;
            if (!$fieldId) continue;

            $docField = collect($docFields)->firstWhere('id', $fieldId);
            $hasValue = false;
            if ($docField) {
                $type = $tField['type'] ?? 'placeholder';
                if (in_array($type, ['placeholder', 'date'])) {
                    $hasValue = !empty(trim((string) ($docField['value'] ?? '')));
                } elseif ($type === 'condition') {
                    $hasValue = !empty(trim((string) ($docField['text'] ?? '')));
                } elseif (in_array($type, ['selection', 'tick'])) {
                    $hasValue = !empty($docField['selectedValue']);
                } else {
                    $hasValue = true;
                }
            }
            if (!$hasValue) {
                $missingFields[] = $tField['field_label'] ?? $tField['field_name'] ?? 'Required field';
            }
        }
        if (!empty($missingFields)) {
            return redirect()->back()->with('error', 'Complete all required fields: ' . implode(', ', $missingFields));
        }

        // FLATTEN: Bake field values + agent signatures into page images
        // From this point forward, external signers see flattened images only.
        $flattener = app(DocumentFlattener::class);
        $flattener->flattenFields($template);

        // Flatten agent-assigned fields (filled by agent during signing)
        $template->refresh();
        $flattener->flattenSignerFields($template, 'agent');

        // Now flatten all agent signatures onto the already-flattened field images
        $agentMarkers = $template->markers->where('assigned_party', 'agent');
        foreach ($agentMarkers as $marker) {
            $sig = $marker->signatures->first();
            if ($sig) {
                $template->refresh(); // reload flattened_pages_json after each flatten
                $flattener->flattenSignature($template, $marker, $sig);
            }
        }

        // Mark agent request as completed
        $agentRequest = $template->requests()
            ->where('party_role', 'agent')
            ->where('status', '!=', SignatureRequest::STATUS_COMPLETED)
            ->first();

        if ($agentRequest) {
            $agentRequest->update([
                'status' => SignatureRequest::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        }

        // Determine next party from signing order
        $signingOrder = $template->signing_order_json ?? ['agent'];
        $nextPartyRole = null;
        foreach ($signingOrder as $role) {
            if ($role !== 'agent') {
                $nextPartyRole = $role;
                break;
            }
        }

        // Set status based on the next party
        // Strip numeric suffix (e.g. seller_2, landlord_3) so the map lookup works
        $baseNextRole = $nextPartyRole ? preg_replace('/_\d+$/', '', $nextPartyRole) : null;
        $statusMap = [
            'tenant' => SignatureTemplate::STATUS_AWAITING_TENANT,
            'landlord' => SignatureTemplate::STATUS_AWAITING_LANDLORD,
            'buyer' => SignatureTemplate::STATUS_AWAITING_BUYER,
            'seller' => SignatureTemplate::STATUS_AWAITING_SELLER,
        ];
        $nextStatus = $baseNextRole ? ($statusMap[$baseNextRole] ?? SignatureTemplate::STATUS_SIGNING) : SignatureTemplate::STATUS_COMPLETED;
        $template->update(['status' => $nextStatus]);

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_COMPLETED,
            SignatureAuditLog::ACTOR_USER,
            $user->name,
            $user->email,
            $user->id,
            $agentRequest?->id,
            $request->ip(),
            $request->userAgent(),
            [
                'phase' => 'agent_signing',
                'total_agent_signatures' => $template->signatures()
                    ->whereHas('marker', fn($q) => $q->where('assigned_party', 'agent'))
                    ->count(),
            ],
        );

        // Determine next party label for the success message
        $nextPartyLabel = $nextPartyRole ? ucfirst($nextPartyRole) : 'the next party';

        // If signing was initiated from the e-sign wizard, auto-send to the next party
        // (wizard flow bypasses the manual sendConfirmation page)
        $wizardFlowId = session()->pull('esign_wizard_flow_id');
        if ($wizardFlowId) {
            if ($nextPartyRole) {
                $nextRequest = $template->requests()
                    ->where('party_role', $nextPartyRole)
                    ->where('status', SignatureRequest::STATUS_WAITING)
                    ->first();

                if ($nextRequest) {
                    $this->signatureService->sendSigningRequest($nextRequest);
                }
            }

            return redirect()->route('docuperfect.esign.signingComplete', ['flow' => $wizardFlowId]);
        }

        return redirect()->route('docuperfect.signatures.sendConfirmation', $document)
            ->with('success', "You have signed all your markers. Now send to {$nextPartyLabel}.");
    }

    /**
     * Web template sign complete — stores signatures from interactive document elements,
     * then injects them into the merged_html and completes the agent signing phase.
     */
    public function webSignComplete(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        try {
            $signatures = $request->input('signatures', []);
            $initials = $request->input('initials', []);
            $partyRole = $request->input('party_role', 'agent');

            $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

            // Store each signature as a Signature record linked to the document
            foreach ($signatures as $sigKey => $sigData) {
                // Create a marker record for each web sig element (for audit trail)
                $marker = SignatureMarker::create([
                    'signature_template_id' => $template->id,
                    'page_number' => 1,
                    'x_position' => 0,
                    'y_position' => 0,
                    'width' => 20,
                    'height' => 5,
                    'type' => 'signature',
                    'assigned_party' => $partyRole,
                    'label' => 'Web element: ' . $sigKey,
                    'required' => true,
                    'sort_order' => 0,
                ]);

                Signature::create([
                    'signature_template_id' => $template->id,
                    'signature_marker_id' => $marker->id,
                    'signature_request_id' => $template->requests()->where('party_role', $partyRole)->value('id'),
                    'signature_data' => $sigData,
                    'signature_type' => 'drawn',
                    'signer_name' => $user->name,
                    'signer_email' => $user->email,
                    'signed_at' => now(),
                    'signer_ip_address' => $request->ip(),
                    'signer_user_agent' => $request->userAgent(),
                ]);
            }

            // Store each initial as a Signature record for audit trail
            foreach ($initials as $initKey => $initData) {
                $marker = SignatureMarker::create([
                    'signature_template_id' => $template->id,
                    'page_number' => 1,
                    'x_position' => 0,
                    'y_position' => 0,
                    'width' => 15,
                    'height' => 8,
                    'type' => 'initial',
                    'assigned_party' => $partyRole,
                    'label' => 'Page initial: ' . $initKey,
                    'required' => true,
                    'sort_order' => 0,
                ]);

                Signature::create([
                    'signature_template_id' => $template->id,
                    'signature_marker_id' => $marker->id,
                    'signature_request_id' => $template->requests()->where('party_role', $partyRole)->value('id'),
                    'signature_data' => $initData,
                    'signature_type' => 'drawn',
                    'signer_name' => $user->name,
                    'signer_email' => $user->email,
                    'signed_at' => now(),
                    'signer_ip_address' => $request->ip(),
                    'signer_user_agent' => $request->userAgent(),
                ]);
            }

            // Store signatures, initials, and ceremony values in web_template_data
            $webData = $document->web_template_data ?? [];
            $webData['agent_signatures'] = $signatures;
            // Store initials keyed by party role so subsequent viewers can restore them
            $existingInitials = $webData['signed_initials'] ?? [];
            $existingInitials[$partyRole] = $initials;
            $webData['signed_initials'] = $existingInitials;
            $ceremonyValues = $request->input('ceremony_values', []);
            if (!empty($ceremonyValues)) {
                $webData['ceremony_values'] = array_merge($webData['ceremony_values'] ?? [], $ceremonyValues);
            }

            // §19.7 — adopt the agent's EXACT signed-and-paginated DOM as the
            // merged_html base (per-document .corex-a4-page + per-page initial
            // slots) so the next signer + the filed PDF/split see exactly what
            // the agent saw. The server MUST NOT re-paginate.
            $paginatedHtml = (string) $request->input('paginated_html', '');
            if (trim($paginatedHtml) !== '' && (
                    str_contains($paginatedHtml, 'corex-a4-page') ||
                    str_contains($paginatedHtml, 'corex-document-wrapper'))) {
                $webData['merged_html'] = $paginatedHtml;
            }

            // Embed agent signature images and initials into merged_html so next signer sees them
            if (!empty($webData['merged_html'])) {
                $html = $webData['merged_html'];
                $html = $this->embedSignaturesIntoHtml($html, $signatures, $partyRole, $user->name);
                if (!empty($initials)) {
                    $html = $this->embedInitialsIntoHtml($html, $initials, $partyRole, $user->name);
                }
                if (!empty($ceremonyValues)) {
                    $html = $this->embedCeremonyValuesIntoHtml($html, $ceremonyValues);
                }
                $webData['merged_html'] = $html;
            }

            $document->update(['web_template_data' => $webData]);

            // Find agent request for audit logging
            $agentRequest = $template->requests()
                ->where('party_role', 'agent')
                ->where('status', '!=', SignatureRequest::STATUS_COMPLETED)
                ->first();

            SignatureAuditLog::log(
                $template,
                SignatureAuditLog::ACTION_COMPLETED,
                SignatureAuditLog::ACTOR_USER,
                $user->name,
                $user->email,
                $user->id,
                $agentRequest?->id,
                $request->ip(),
                $request->userAgent(),
                [
                    'phase' => 'agent_web_signing',
                    'total_signatures' => count($signatures),
                ],
            );

            // Use the unified chain advancement logic — handles candidate flows,
            // supervisor routing, approval gates, and all status transitions.
            $this->signatureService->handlePartyCompletion($template, $partyRole, $agentRequest);

            // If signing was initiated from the e-sign wizard, redirect to completion page.
            // Accept flow ID from request body (reliable) or session (fallback).
            $wizardFlowId = $request->input('esign_flow_id') ?: session()->pull('esign_wizard_flow_id');
            if ($wizardFlowId) {
                // Clear session key if it wasn't already pulled
                session()->forget('esign_wizard_flow_id');

                return response()->json([
                    'ok' => true,
                    'redirect' => route('docuperfect.esign.signingComplete', ['flow' => $wizardFlowId]),
                ]);
            }

            return response()->json([
                'ok' => true,
                'redirect' => route('docuperfect.signatures.sendConfirmation', $document),
            ]);
        } catch (\Throwable $e) {
            \Log::error('WEB_SIGN_COMPLETE_EXCEPTION', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
            ]);

            return response()->json([
                'ok' => false,
                'error' => 'Server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Embed signature images into HTML by finding data-marker-party elements
     * and replacing their content with <img> tags.
     */
    public function embedSignaturesIntoHtml(string $html, array $signatures, string $partyRole, string $signerName = ''): string
    {
        try {
            // Role aliases: the signing code uses keys like "agent-sig-0", "landlord-sig-1"
            // The HTML has data-marker-party="agent", data-marker-party="lessor", etc.
            // Frontend sets data-sig-id on interactive elements with global index.
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
            $xpath = new \DOMXPath($dom);

            // Map party role to possible marker-party values in the HTML
            $agentAliases = ['agent', 'property_practitioner'];
            $ownerAliases = ['owner_party', 'lessor', 'seller', 'landlord', 'owner'];
            $acquiringAliases = ['acquiring_party', 'lessee', 'buyer', 'tenant', 'purchaser'];

            $partyAliases = match (true) {
                in_array($partyRole, $agentAliases) => $agentAliases,
                in_array($partyRole, $ownerAliases) => $ownerAliases,
                in_array($partyRole, $acquiringAliases) => $acquiringAliases,
                default => [$partyRole],
            };

            // Strategy 1: Match by data-sig-id attribute (set by frontend _makeWebElementsInteractive)
            // Signature keys from frontend match data-sig-id values exactly
            $matched = [];
            foreach ($signatures as $sigKey => $sigData) {
                $els = $xpath->query('//*[@data-sig-id="' . htmlspecialchars($sigKey) . '"]');
                if ($els->length > 0) {
                    $this->embedSigIntoElement($dom, $els->item(0), $sigData, $partyRole, $signerName);
                    $matched[$sigKey] = true;
                }
            }

            // Strategy 2: For any unmatched signatures, fall back to party-based sequential matching
            $unmatched = array_diff_key($signatures, $matched);
            if (!empty($unmatched)) {
                $sigElements = $xpath->query('//*[@data-marker-party][@data-marker-type="signature"]');
                $sigIdx = 0;

                foreach ($sigElements as $el) {
                    // Skip elements already embedded via Strategy 1
                    if ($el->getAttribute('data-signed') === 'true') continue;

                    $elParty = strtolower($el->getAttribute('data-marker-party'));
                    if (in_array($elParty, $partyAliases) || $elParty === $partyRole) {
                        $sigData = null;
                        foreach ($unmatched as $key => $data) {
                            if (preg_match('/sig-(\d+)$/', $key, $m) && (int)$m[1] === $sigIdx) {
                                $sigData = $data;
                                break;
                            }
                        }

                        if (!$sigData && $sigIdx === 0) {
                            $sigData = reset($unmatched);
                        }

                        if ($sigData) {
                            $this->embedSigIntoElement($dom, $el, $sigData, $partyRole, $signerName);
                        }

                        $sigIdx++;
                    }
                }
            }

            $result = $dom->saveHTML();
            $result = preg_replace('/^<\?xml encoding="utf-8"\?>/', '', $result);
            return trim($result);
        } catch (\Throwable $e) {
            \Log::error('EMBED_SIGNATURES_HTML_FAILED', [
                'party_role' => $partyRole,
                'sig_count' => count($signatures),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return $html; // Return original HTML on failure
        }
    }

    /**
     * Insert a signature image into a DOM element and mark it as signed.
     */
    private function embedSigIntoElement(\DOMDocument $dom, \DOMElement $el, string $sigData, string $partyRole, string $signerName = ''): void
    {
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $img = $dom->createElement('img');
        $img->setAttribute('src', $sigData);
        $img->setAttribute('class', 'web-sig-signed-img');
        $img->setAttribute('alt', 'Signature');
        $img->setAttribute('style', 'display:block;max-height:50px;margin:2px auto;object-fit:contain;');
        $el->appendChild($img);
        $el->setAttribute('data-signed', 'true');

        $label = $dom->createElement('div');
        $label->setAttribute('style', 'font-size:8px;color:#059669;text-align:center;font-weight:600;');
        $label->textContent = 'Signed by ' . ($signerName ?: ucfirst($partyRole));
        $el->appendChild($label);
    }

    /**
     * Embed initial images into HTML elements that have data-marker-type="initial".
     * Initials are keyed as "{party}-init-{index}" from the frontend.
     *
     * @param string $html       The merged HTML
     * @param array  $initials   Keyed as "{party}-init-{N}" => base64 data URI
     * @param string $partyRole  The signer's party role
     * @param string $signerName The signer's display name
     */
    public function embedInitialsIntoHtml(string $html, array $initials, string $partyRole, string $signerName = ''): string
    {
        if (empty($initials)) return $html;

        try {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
            $xpath = new \DOMXPath($dom);

            // Map party role to possible aliases (same as embedSignaturesIntoHtml)
            $agentAliases = ['agent', 'property_practitioner'];
            $ownerAliases = ['owner_party', 'lessor', 'seller', 'landlord', 'owner'];
            $acquiringAliases = ['acquiring_party', 'lessee', 'buyer', 'tenant', 'purchaser'];

            $partyAliases = match (true) {
                in_array($partyRole, $agentAliases) => $agentAliases,
                in_array($partyRole, $ownerAliases) => $ownerAliases,
                in_array($partyRole, $acquiringAliases) => $acquiringAliases,
                default => [$partyRole],
            };

            // Find all initial elements with a party attribute
            $initialElements = $xpath->query('//*[@data-marker-type="initial"][@data-marker-party]');
            $partyCounters = [];

            foreach ($initialElements as $el) {
                if ($el->getAttribute('data-signed') === 'true') continue;

                $elParty = strtolower($el->getAttribute('data-marker-party'));
                if (!in_array($elParty, $partyAliases) && $elParty !== $partyRole) continue;

                // Build the key to match frontend format: "{party}-init-{N}"
                if (!isset($partyCounters[$elParty])) $partyCounters[$elParty] = 0;
                $initKey = $elParty . '-init-' . $partyCounters[$elParty];
                $partyCounters[$elParty]++;

                $initData = $initials[$initKey] ?? null;
                if (!$initData) continue;

                // Clear placeholder content and embed the initial image
                while ($el->firstChild) {
                    $el->removeChild($el->firstChild);
                }
                $img = $dom->createElement('img');
                $img->setAttribute('src', $initData);
                $img->setAttribute('class', 'web-sig-signed-img');
                $img->setAttribute('alt', 'Initial');
                $img->setAttribute('style', 'display:block;max-height:28px;margin:1px auto;object-fit:contain;');
                $el->appendChild($img);
                $el->setAttribute('data-signed', 'true');
                $el->classList !== null && $el->setAttribute('class', ($el->getAttribute('class') ?: '') . ' initial-signed');
            }

            $result = $dom->saveHTML();
            $result = preg_replace('/^<\?xml encoding="utf-8"\?>/', '', $result);
            return trim($result);
        } catch (\Throwable $e) {
            \Log::error('EMBED_INITIALS_HTML_FAILED', [
                'party_role' => $partyRole,
                'init_count' => count($initials),
                'error' => $e->getMessage(),
            ]);
            return $html;
        }
    }

    /**
     * Embed ceremony field values into HTML by finding data-marker-type elements
     * and setting their text content.
     */
    public function embedCeremonyValuesIntoHtml(string $html, array $ceremonyValues): string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);

        foreach ($ceremonyValues as $key => $value) {
            if (empty($value)) continue;

            // Keys are like "agent_location", "agent_day", etc.
            $parts = explode('_', $key, 2);
            if (count($parts) < 2) continue;

            $party = $parts[0];
            $fieldType = $parts[1];

            $elements = $xpath->query("//*[@data-marker-party][@data-marker-type='{$fieldType}']");
            foreach ($elements as $el) {
                $elParty = strtolower($el->getAttribute('data-marker-party'));
                if ($elParty === $party || str_starts_with($elParty, $party)) {
                    $el->textContent = $value;
                    $el->setAttribute('style', ($el->getAttribute('style') ?: '') . 'font-weight:500;');
                }
            }
        }

        $result = $dom->saveHTML();
        $result = preg_replace('/^<\?xml encoding="utf-8"\?>/', '', $result);
        return trim($result);
    }

    /**
     * Show the send confirmation page (before sending to next party).
     */
    public function sendConfirmation(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        $parties = $template->parties_json ?? [];
        $signingOrder = $template->signing_order_json ?? [];

        // Find the first non-agent party (tenant/landlord for rentals, buyer/seller for sales)
        $nextPartyRole = null;
        foreach ($signingOrder as $role) {
            if ($role !== 'agent') {
                $nextPartyRole = $role;
                break;
            }
        }
        $nextParty = $nextPartyRole ? collect($parties)->firstWhere('role', $nextPartyRole) : null;

        return view('docuperfect.signatures.send-confirmation', [
            'document' => $document,
            'template' => $template,
            'tenant' => $nextParty, // keep 'tenant' key for backward compat with existing view
            'nextParty' => $nextParty,
            'nextPartyRole' => $nextPartyRole,
            'user' => $user,
        ]);
    }

    // ──────────────────────────────────────────────
    // Send + reminders
    // ──────────────────────────────────────────────

    /**
     * Send document for signature (handles initial send OR agent-complete → next party send).
     */
    public function sendForSignature(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        $templateType = $document->template?->template_type ?? 'rentals';
        $isSales = $templateType === 'sales';

        // If template is awaiting a party, send to that party
        $awaitingStatuses = [
            SignatureTemplate::STATUS_AWAITING_TENANT,
            SignatureTemplate::STATUS_AWAITING_LANDLORD,
            SignatureTemplate::STATUS_AWAITING_BUYER,
            SignatureTemplate::STATUS_AWAITING_SELLER,
        ];

        if (in_array($template->status, $awaitingStatuses)) {
            $currentRole = $template->currentPartyRole();
            $partyRequest = $currentRole
                ? $template->requests()->where('party_role', $currentRole)->first()
                : null;

            if ($partyRequest && $partyRequest->status === SignatureRequest::STATUS_WAITING) {
                if ($request->filled('message')) {
                    $partyRequest->update(['message' => $request->input('message')]);
                }
                $this->signatureService->sendSigningRequest($partyRequest);
            }

            $partyLabel = $currentRole ? ucfirst($currentRole) : 'next party';

            if ($document->document_type === 'rental_upload_send') {
                return redirect()->route('docuperfect.esign.myDocuments')
                    ->with('success', "Document sent to {$partyLabel} for signing.");
            }

            $dashboardRoute = $isSales ? 'docuperfect.sales' : 'docuperfect.rental';

            return redirect()->route($dashboardRoute)
                ->with('status', "Document sent to {$partyLabel} for signing.");
        }

        // Otherwise, initial send flow (draft/ready → signing)
        $validation = $this->signatureService->validateFieldCompletion($document);
        if (!$validation['valid']) {
            return redirect()->back()->withErrors([
                'fields' => 'Missing required fields: ' . implode(', ', $validation['missing']),
            ]);
        }

        // Validate every non-agent party has at least one signature marker.
        // Skip for web templates — they use embedded HTML elements, not DB markers.
        $docTemplate = $document->template;
        $isWebRenderType = $docTemplate && ($docTemplate->render_type ?? 'pdf') === 'web';
        $hasWebMergedHtml = !empty($document->web_template_data['merged_html'] ?? null);

        if (!$isWebRenderType && !$hasWebMergedHtml) {
            $markerValidation = $this->validatePartyMarkers($template);
            if (!$markerValidation['valid']) {
                return redirect()->back()->withErrors([
                    'markers' => $markerValidation['message'],
                ]);
            }
        }

        try {
            $this->signatureService->sendForSigning($template, $user);
        } catch (\LogicException $e) {
            return redirect()->back()->withErrors(['error' => $e->getMessage()]);
        }

        if ($document->document_type === 'rental_upload_send') {
            return redirect()->route('docuperfect.esign.myDocuments')
                ->with('success', 'Document sent for signing.');
        }

        return redirect()->back()->with('status', 'Document sent for signing.');
    }

    /**
     * Send a manual reminder to a signer.
     */
    public function sendReminder(Request $request, Document $document, SignatureRequest $signatureRequest)
    {
        $this->authorizeDocument($request->user(), $document);

        if (in_array($signatureRequest->status, [
            SignatureRequest::STATUS_COMPLETED,
            SignatureRequest::STATUS_EXPIRED,
            SignatureRequest::STATUS_DECLINED,
        ])) {
            return redirect()->back()->with('error', 'Cannot send reminder — request is already ' . $signatureRequest->status . '.');
        }

        $this->signatureService->sendManualReminder($signatureRequest, $request->user());

        return redirect()->back()->with('status', "Reminder sent to {$signatureRequest->signer_name}.");
    }

    // ──────────────────────────────────────────────
    // Audit & download
    // ──────────────────────────────────────────────

    /**
     * Show audit trail for a document's signatures.
     */
    public function audit(Request $request, Document $document)
    {
        $this->authorizeDocument($request->user(), $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        $template->loadMissing(['requests', 'markers', 'signatures', 'creator', 'amendments.acceptances']);

        $logs = $template->auditLogs()
            ->orderBy('created_at', 'desc')
            ->get();

        $progress = $template->partyProgress();

        // Get amendments for the audit trail
        $amendments = $template->amendments()
            ->with(['acceptances.signingRequest'])
            ->orderBy('created_at')
            ->get();

        // Get consent logs
        $consentLogs = \App\Models\Docuperfect\ESignConsentLog::where('document_id', $document->id)
            ->orderBy('created_at')
            ->get();

        // Get document versions
        $versions = \App\Models\Docuperfect\SignedDocumentVersion::where('document_id', $document->id)
            ->orderBy('version_number')
            ->get();

        return view('docuperfect.signatures.audit-log', [
            'document' => $document,
            'template' => $template,
            'logs' => $logs,
            'progress' => $progress,
            'user' => $request->user(),
            'amendments' => $amendments,
            'consentLogs' => $consentLogs,
            'versions' => $versions,
        ]);
    }

    /**
     * Download signed document.
     */
    public function download(Request $request, Document $document)
    {
        $this->authorizeDocument($request->user(), $document);

        $template = SignatureTemplate::where('document_id', $document->id)
            ->where('status', SignatureTemplate::STATUS_COMPLETED)
            ->firstOrFail();

        if (!$template->signed_pdf_path) {
            return redirect()->back()->with('error', 'Signed PDF has not been generated yet.');
        }

        $pdfPath = storage_path("app/{$template->signed_pdf_path}");

        if (!file_exists($pdfPath)) {
            return redirect()->back()->with('error', 'Signed PDF file not found.');
        }

        $filename = "Signed - {$document->name}.pdf";

        return response()->download($pdfPath, $filename);
    }

    // ──────────────────────────────────────────────
    // Leases
    // ──────────────────────────────────────────────

    /**
     * List lease records.
     */
    public function leases(Request $request)
    {
        $user = $request->user();

        $leases = LeaseRecord::visibleTo($user)
            ->with(['document', 'signatureTemplate'])
            ->orderByDesc('lease_end_date')
            ->paginate(20);

        return view('docuperfect.signatures.placeholder', [
            'title' => 'Lease Records',
            'leases' => $leases,
        ]);
    }

    // ──────────────────────────────────────────────
    // Wet ink inspection
    // ──────────────────────────────────────────────

    /**
     * Show wet ink inspection page for a signing request.
     */
    public function wetInkReview(Request $request, Document $document, SignatureRequest $signingRequest)
    {
        $this->authorizeDocument($request->user(), $document);

        $template = $signingRequest->template;

        // Get this party's required markers
        $markers = $template->markers()
            ->where('assigned_party', $signingRequest->party_role)
            ->where('required', true)
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->get();

        // Get uploaded files
        $uploadPaths = [];
        if ($signingRequest->wet_ink_upload_path) {
            $decoded = json_decode($signingRequest->wet_ink_upload_path, true);
            $uploadPaths = is_array($decoded) ? $decoded : [$signingRequest->wet_ink_upload_path];
        }

        // Build file info with URLs
        $uploadFiles = [];
        foreach ($uploadPaths as $path) {
            $uploadFiles[] = [
                'path' => $path,
                'name' => basename($path),
                'extension' => pathinfo($path, PATHINFO_EXTENSION),
                'exists' => Storage::disk('local')->exists($path),
            ];
        }

        return view('docuperfect.signatures.wet-ink-review', [
            'document' => $document,
            'signingRequest' => $signingRequest,
            'template' => $template,
            'markers' => $markers,
            'uploadFiles' => $uploadFiles,
            'previousInspections' => $signingRequest->inspections()->with('inspector')->latest()->get(),
            'user' => $request->user(),
        ]);
    }

    /**
     * Serve a wet ink uploaded file for review.
     */
    public function wetInkFile(Request $request, Document $document, SignatureRequest $signingRequest, $fileIndex)
    {
        $this->authorizeDocument($request->user(), $document);

        $uploadPaths = [];
        if ($signingRequest->wet_ink_upload_path) {
            $decoded = json_decode($signingRequest->wet_ink_upload_path, true);
            $uploadPaths = is_array($decoded) ? $decoded : [$signingRequest->wet_ink_upload_path];
        }

        $index = (int) $fileIndex;
        if (!isset($uploadPaths[$index])) {
            abort(404);
        }

        $path = $uploadPaths[$index];
        if (!Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    /**
     * Agent uploads a signed document on behalf of a party.
     */
    public function uploadOnBehalf(Request $request, Document $document, SignatureRequest $signingRequest)
    {
        $this->authorizeDocument($request->user(), $document);

        $request->validate([
            'files'          => 'required|array|min:1',
            'files.*'        => 'file|mimes:pdf,jpg,jpeg,png|max:20480',
            'receive_method' => 'required|in:whatsapp,email,in_person',
        ]);

        // Store uploaded files
        $paths = [];
        foreach ($request->file('files') as $file) {
            $paths[] = $file->store("docuperfect/wet-ink-uploads/{$signingRequest->id}", 'local');
        }

        $signingRequest->update([
            'signing_method'      => 'wet_ink',
            'wet_ink_upload_path' => json_encode($paths),
            'wet_ink_status'      => SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW,
        ]);

        $template = $signingRequest->template;

        SignatureAuditLog::log(
            $template,
            SignatureAuditLog::ACTION_WET_INK_UPLOADED,
            SignatureAuditLog::ACTOR_USER,
            $request->user()->name,
            $request->user()->email,
            $request->user()->id,
            $signingRequest->id,
            $request->ip(),
            $request->userAgent(),
            [
                'uploaded_on_behalf' => true,
                'receive_method' => $request->input('receive_method'),
                'file_count' => count($paths),
            ],
        );

        // Auto-approve: skip the wet-ink review step, approve immediately
        if ($request->boolean('auto_approve')) {
            $this->signatureService->approveUploadOnBehalf($signingRequest, $request->user());

            $templateType = $document->template?->template_type ?? 'rentals';
            $dashboardRoute = $templateType === 'sales' ? 'docuperfect.sales' : 'docuperfect.rental';

            return redirect()->route($dashboardRoute)
                ->with('status', 'Uploaded and approved for ' . $signingRequest->signer_name . '. Signing advanced.');
        }

        return redirect()->route('docuperfect.signatures.wetInkReview', [
            'document' => $document->id,
            'signingRequest' => $signingRequest->id,
        ])->with('status', 'Document uploaded on behalf of ' . $signingRequest->signer_name . '. Please review.');
    }

    /**
     * Process wet ink approval/rejection decision.
     */
    public function wetInkDecision(Request $request, Document $document, SignatureRequest $signingRequest)
    {
        $this->authorizeDocument($request->user(), $document);

        $request->validate([
            'checklist' => 'required|array',
            'checklist.*.marker_id' => 'required|integer',
            'checklist.*.status' => 'required|in:present,missing,unclear',
            'result' => 'required|in:approved,rejected',
            'notes' => 'nullable|string|max:2000',
            'rejection_note' => 'required_if:result,rejected|nullable|string|max:2000',
        ]);

        $result = $request->input('result');
        $notes = $result === 'rejected'
            ? $request->input('rejection_note')
            : $request->input('notes');

        $this->signatureService->submitInspection(
            $signingRequest,
            $request->user(),
            $result,
            $request->input('checklist'),
            $notes,
        );

        $message = $result === 'approved'
            ? "Wet ink approved for {$signingRequest->signer_name}. Signing flow advanced."
            : "Rejection sent to {$signingRequest->signer_name} with instructions to re-sign.";

        // Redirect to the appropriate dashboard based on template type
        $templateType = $document->template?->template_type ?? 'rentals';
        $dashboardRoute = $templateType === 'sales' ? 'docuperfect.sales' : 'docuperfect.rental';

        return redirect()->route($dashboardRoute)
            ->with('status', $message);
    }

    // ──────────────────────────────────────────────
    // Agent approval gate
    // ──────────────────────────────────────────────

    /**
     * Show the agent review page for a completed party's signatures.
     */
    public function review(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        // Accept pending_agent_approval (normal flow) AND supervisor statuses (candidate flow)
        $reviewableStatuses = [
            SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
            SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
            SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
        ];
        if (!in_array($template->status, $reviewableStatuses)) {
            return redirect()->route('docuperfect.rental')
                ->with('error', 'This document is not pending approval.');
        }

        $template->loadMissing(['requests', 'markers.signatures', 'signatures']);

        // Find the most recently completed non-agent request
        $completedRequest = $template->requests
            ->where('status', SignatureRequest::STATUS_COMPLETED)
            ->where('party_role', '!=', 'agent')
            ->sortByDesc('completed_at')
            ->first();

        // Determine the next party — fallback to dynamic order from document template
        $order = $template->signing_order_json ?? $this->buildDefaultSigningOrder($document->template);
        $completedParties = $template->requests
            ->where('status', SignatureRequest::STATUS_COMPLETED)
            ->pluck('party_role')
            ->toArray();

        $nextParty = null;
        foreach ($order as $party) {
            if ($party !== 'agent' && !in_array($party, $completedParties)) {
                $nextParty = $party;
                break;
            }
        }

        // Get progress for the completed party
        $progress = $template->partyProgress();

        // Build page image URLs — use flattened or document-level images
        $docTemplate = $document->template;
        $flattenedPages = $template->flattened_pages_json ?? [];
        $hasFlattened = !empty($flattenedPages);
        $pageImages = [];
        $webTemplateData = $document->web_template_data ?? [];
        $hasDocPages = !empty($webTemplateData['flattened_page_count']);

        // Detect web template with merged_html — render inline HTML instead of page images
        $isWebTemplate = false;
        $webTemplateHtml = null;
        if (!empty($webTemplateData['merged_html'])) {
            $isWebTemplate = true;
            $webTemplateHtml = $webTemplateData['merged_html'];
        }

        if (!$isWebTemplate) {
            if ($hasDocPages && !$hasFlattened) {
                $pageCount = (int) $webTemplateData['flattened_page_count'];
                for ($n = 0; $n < $pageCount; $n++) {
                    $pageImages[] = route('docuperfect.documents.pageImage', ['id' => $document->id, 'page' => $n]);
                }
            } else {
                $pageCount = !empty($flattenedPages) ? count($flattenedPages) : ($docTemplate ? $docTemplate->page_count : 0);
                if ($pageCount < 1 && $hasDocPages) {
                    $pageCount = (int) $webTemplateData['flattened_page_count'];
                }
                for ($n = 0; $n < $pageCount; $n++) {
                    if ($hasFlattened && isset($flattenedPages[$n])) {
                        $pageImages[] = route('docuperfect.signatures.flattenedPage', ['templateId' => $template->id, 'page' => $n]);
                    } elseif ($hasDocPages) {
                        $pageImages[] = route('docuperfect.documents.pageImage', ['id' => $document->id, 'page' => $n]);
                    } elseif ($docTemplate) {
                        $pageImages[] = route('docuperfect.page.image', ['id' => $docTemplate->id, 'page' => $n]);
                    }
                }
            }
        }
        $pageCount = $isWebTemplate ? 0 : ($pageCount ?? 0);

        // Get all markers with signatures for display
        $allMarkers = $template->markers()
            ->with('signatures')
            ->orderBy('page_number')
            ->orderBy('sort_order')
            ->get();

        // Candidate flow context for the view
        $isCandidateFlow = $template->is_candidate_flow ?? false;
        $candidateName = null;
        if ($isCandidateFlow) {
            $candidateName = $template->creator?->name ?? 'Candidate';
        }

        // Extract signing data from web_template_data for the summary panel
        $disclosureAnswers = $webTemplateData['disclosure_answers'] ?? [];
        $ceremonyValues = $webTemplateData['ceremony_values'] ?? [];
        $clauseFlags = $webTemplateData['clause_flags'] ?? [];

        // Deduplicate supervisor/supervisor_final — same person, one initial block
        $signingParties = collect($template->parties_json ?? [])->filter(function ($p) {
            return ($p['role'] ?? '') !== 'supervisor_final';
        })->map(fn($p) => [
            'role' => $p['role'] ?? 'unknown',
            'label' => ucfirst(str_replace('_', ' ', $p['role_label'] ?? $p['role'] ?? 'unknown')),
        ])->unique('role')->values()->toArray();

        return view('docuperfect.signatures.review', [
            'document' => $document,
            'template' => $template,
            'completedRequest' => $completedRequest,
            'nextParty' => $nextParty,
            'progress' => $progress,
            'pageImages' => $pageImages,
            'pageCount' => $pageCount,
            'allMarkers' => $allMarkers,
            'hasFlattened' => $hasFlattened,
            'user' => $user,
            'isCandidateFlow' => $isCandidateFlow,
            'candidateName' => $candidateName,
            'isWebTemplate' => $isWebTemplate,
            'webTemplateHtml' => $webTemplateHtml,
            'disclosureAnswers' => $disclosureAnswers,
            'ceremonyValues' => $ceremonyValues,
            'clauseFlags' => $clauseFlags,
            'signingParties' => $signingParties,
            'storedInitials' => $webTemplateData['signed_initials'] ?? [],
        ]);
    }

    /**
     * Redirect supervisor to the external signing view for candidate flow authorisation.
     * Generates a token on the supervisor's SignatureRequest so they can sign.
     */
    public function authoriseSigning(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        // Only allow for candidate flows awaiting supervisor
        $supervisorStatuses = [
            SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
            SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
        ];
        if (!in_array($template->status, $supervisorStatuses)) {
            return redirect()->route('docuperfect.signatures.review', $document)
                ->with('error', 'This document is not awaiting supervisor authorisation.');
        }

        // Determine which supervisor request to use
        $supervisorRole = $template->status === SignatureTemplate::STATUS_AWAITING_SUPERVISOR
            ? 'supervisor'
            : 'supervisor_final';

        $supervisorRequest = $template->requests()
            ->where('party_role', $supervisorRole)
            ->first();

        if (!$supervisorRequest) {
            return redirect()->route('docuperfect.signatures.review', $document)
                ->with('error', 'No supervisor signing request found.');
        }

        // Generate a token if one doesn't exist
        if (empty($supervisorRequest->token)) {
            $supervisorRequest->update([
                'token' => \Illuminate\Support\Str::random(64),
                'signer_name' => $user->name,
                'signer_email' => $user->email,
                'status' => SignatureRequest::STATUS_PENDING,
            ]);
        } else {
            // Update signer info for the supervisor claiming this request
            $supervisorRequest->update([
                'signer_name' => $user->name,
                'signer_email' => $user->email,
                'status' => SignatureRequest::STATUS_PENDING,
            ]);
        }

        // Redirect to the external signing view with the token
        return redirect()->route('signatures.external', $supervisorRequest->token);
    }

    /**
     * Approve and advance to the next party (or complete the document).
     */
    public function approveAndAdvance(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        $reviewableStatuses = [
            SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL,
            SignatureTemplate::STATUS_AWAITING_SUPERVISOR,
            SignatureTemplate::STATUS_AWAITING_SUPERVISOR_FINAL,
        ];
        if (!in_array($template->status, $reviewableStatuses)) {
            $templateType = $document->template?->template_type ?? 'rentals';
            $dashboardRoute = $templateType === 'sales' ? 'docuperfect.sales' : 'docuperfect.rental';
            return redirect()->route($dashboardRoute)
                ->with('error', 'This document is not pending approval.');
        }

        $result = $this->signatureService->approveAndAdvance($template);

        $templateType = $document->template?->template_type ?? 'rentals';
        $dashboardRoute = $templateType === 'sales' ? 'docuperfect.sales' : 'docuperfect.rental';

        if ($result['action'] === 'sent') {
            $nextName = $result['next_name'] ?? ucfirst($result['next_party']);
            return redirect()->route($dashboardRoute)
                ->with('status', "Approved. Document sent to {$nextName} ({$result['next_party']}) for signing.");
        }

        return redirect()->route($dashboardRoute)
            ->with('status', 'All signatures approved. Document completed!');
    }

    /**
     * Return a document to the candidate practitioner with supervisor notes.
     * Only available in candidate practitioner flows.
     */
    public function returnToCandidate(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $request->validate(['notes' => 'required|string|max:2000']);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        if (!$template->is_candidate_flow) {
            return back()->with('error', 'This action is only available for candidate practitioner documents.');
        }

        $result = $this->signatureService->returnToCandidate($template, $request->input('notes'), $user);

        $templateType = $document->template?->template_type ?? 'rentals';
        $dashboardRoute = $templateType === 'sales' ? 'docuperfect.sales' : 'docuperfect.rental';

        return redirect()->route($dashboardRoute)
            ->with('status', "Document returned to {$result['candidate_name']} with your notes.");
    }

    /**
     * Status check endpoint for dashboard polling.
     */
    public function statusCheck(Request $request)
    {
        $user = $request->user();

        $pendingApproval = SignatureTemplate::where('status', SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL)
            ->visibleTo($user)
            ->count();

        $lastUpdate = SignatureTemplate::visibleTo($user)
            ->max('updated_at');

        return response()->json([
            'pending_approval_count' => $pendingApproval,
            'last_update' => $lastUpdate,
        ]);
    }

    // ──────────────────────────────────────────────
    // Flattened page image serving
    // ──────────────────────────────────────────────

    /**
     * Serve a flattened page image for authenticated users.
     */
    public function flattenedPageImage(Request $request, $templateId, $page)
    {
        $template = SignatureTemplate::findOrFail($templateId);
        $this->authorizeDocument($request->user(), $template->document);

        $flattenedPages = $template->flattened_pages_json ?? [];
        $pageNum = (int) $page;

        if (!isset($flattenedPages[$pageNum])) {
            abort(404, 'Flattened page not found.');
        }

        $path = Storage::disk('local')->path($flattenedPages[$pageNum]);
        if (!file_exists($path)) {
            abort(404, 'Flattened page file not found.');
        }

        return response()->file($path, [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    // ──────────────────────────────────────────────
    // Authorization helper
    // ──────────────────────────────────────────────

    /**
     * Validate that every non-agent party in the signing order has at least
     * one signature or initial marker assigned to them.
     */
    private function validatePartyMarkers(SignatureTemplate $template): array
    {
        $signingOrder = $template->signing_order_json ?? [];
        $parties = $template->parties_json ?? [];
        $markers = $template->markers()->get();

        $missing = [];

        foreach ($signingOrder as $role) {
            if ($role === 'agent') continue;

            $partyHasMarker = $markers->contains(function ($marker) use ($role) {
                return strtolower($marker->assigned_party) === strtolower($role)
                    && in_array($marker->type, ['signature', 'initial']);
            });

            if (!$partyHasMarker) {
                $partyName = collect($parties)->firstWhere('role', $role)['name'] ?? ucfirst($role);
                $missing[] = $partyName . ' (' . ucfirst($role) . ')';
            }
        }

        if (!empty($missing)) {
            return [
                'valid'   => false,
                'message' => 'The following parties have no signature markers assigned: ' . implode(', ', $missing) . '. Please go back and add signature fields for each party.',
            ];
        }

        return ['valid' => true, 'message' => ''];
    }

    /**
     * Build default signing order from template signing_parties, resolving generic roles.
     */
    private function buildDefaultSigningOrder(?Template $docTemplate): array
    {
        $order = ['agent'];
        if ($docTemplate && !empty($docTemplate->signing_parties)) {
            $isSales = $docTemplate->isSalesDocument();
            foreach ($docTemplate->signing_parties as $party) {
                if ($party === 'agent') continue;
                if ($party === 'owner_party') {
                    $order[] = $isSales ? 'seller' : 'landlord';
                } elseif ($party === 'acquiring_party') {
                    $order[] = $isSales ? 'buyer' : 'tenant';
                } else {
                    $order[] = $party;
                }
            }
        } else {
            $order = ['agent', 'tenant', 'landlord'];
        }
        return $order;
    }

    private function authorizeDocument($user, Document $document): void
    {
        $scope = PermissionService::getDataScope($user, 'documents');

        if ($scope === 'all') {
            return;
        }

        if ($scope === 'branch') {
            if ($document->branch_id !== $user->effectiveBranchId()) {
                abort(403);
            }
            return;
        }

        if ((int) $document->owner_id !== (int) $user->id) {
            abort(403);
        }
    }

    // ──────────────────────────────────────────────
    // Document Rejection / Redo
    // ──────────────────────────────────────────────

    public function reject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string|min:5',
            'action' => 'required|in:archive,revise',
        ]);

        $document = Document::findOrFail($id);
        $this->authorizeDocument($request->user(), $document);

        $template = $document->signatureTemplate;

        if (!$template) {
            return back()->with('error', 'No signature template found for this document.');
        }

        // 1. Mark as rejected
        $template->update([
            'status' => SignatureTemplate::STATUS_REJECTED,
            'rejected_at' => now(),
            'rejection_reason' => $request->rejection_reason,
            'rejected_by' => auth()->id(),
        ]);

        // 2. Invalidate all pending signing requests
        $template->requests()
            ->whereIn('status', ['waiting', 'pending', 'viewed', 'partially_signed'])
            ->update([
                'status' => 'expired',
            ]);

        // 3. Log in audit trail
        SignatureAuditLog::log(
            $template,
            'document_rejected',
            SignatureAuditLog::ACTOR_USER,
            auth()->user()->name,
            auth()->user()->email,
            auth()->id(),
            null,
            $request->ip(),
            $request->userAgent(),
            [
                'reason' => $request->rejection_reason,
                'action' => $request->action,
            ]
        );

        // 4. If "Create revised version" — clone the document
        if ($request->action === 'revise') {
            $newDocument = $this->cloneDocumentForRevision($document);

            return redirect()->route('docuperfect.documents.edit', $newDocument->id)
                ->with('status', 'Document rejected. A revised copy has been created for editing.');
        }

        // 5. Archive action — just leave it rejected
        return redirect()->route('docuperfect.esign.myDocuments')
            ->with('status', 'Document rejected and archived.');
    }

    private function cloneDocumentForRevision(Document $original): Document
    {
        $new = $original->replicate(['archived_at']);
        $new->name = $original->name . ' (Revised)';
        $new->created_at = now();
        $new->save();

        // Copy page images if they exist
        $originalDir = "docuperfect/documents/{$original->id}/pages";
        $newDir = "docuperfect/documents/{$new->id}/pages";

        if (Storage::disk('local')->exists($originalDir)) {
            foreach (Storage::disk('local')->files($originalDir) as $file) {
                $filename = basename($file);
                Storage::disk('local')->copy($file, "{$newDir}/{$filename}");
            }
        }

        // Do NOT copy: signature_templates, signing_requests, signatures
        // The new document starts fresh for signing

        return $new;
    }

    /**
     * Merge filled field values from fields_json into web template data.
     *
     * Maps NamedField source metadata to blade variable names so that
     * values entered during the wizard fill step appear in web template rendering.
     */
    private function mergeFieldsIntoWebTemplateData(array $webTemplateData, array $fieldsJson): array
    {
        // Collect named_field_ids to batch-load
        $namedFieldIds = collect($fieldsJson)
            ->pluck('named_field_id')
            ->filter()
            ->unique()
            ->values();

        $namedFields = $namedFieldIds->isNotEmpty()
            ? NamedField::whereIn('id', $namedFieldIds)->get()->keyBy('id')
            : collect();

        foreach ($fieldsJson as $field) {
            $value = $field['value'] ?? null;
            if ($value === null || $value === '') continue;

            // Map via NamedField source metadata
            if (!empty($field['named_field_id']) && $namedFields->has($field['named_field_id'])) {
                $nf = $namedFields->get($field['named_field_id']);
                $bladeKey = $this->namedFieldToBladeKey($nf);
                if ($bladeKey && !isset($webTemplateData[$bladeKey])) {
                    $webTemplateData[$bladeKey] = $value;
                }
            }

            // Map via field_name (some fields use blade-compatible names directly)
            if (!empty($field['field_name'])) {
                $key = str_replace(' ', '_', strtolower($field['field_name']));
                if (!isset($webTemplateData[$key])) {
                    $webTemplateData[$key] = $value;
                }
            }
        }

        return $webTemplateData;
    }

    /**
     * Convert a NamedField's source metadata to its blade variable key.
     */
    private function namedFieldToBladeKey(NamedField $nf): ?string
    {
        $col = $nf->source_column;
        $type = $nf->source_type;
        $contactType = strtolower($nf->source_contact_type ?? '');

        if ($type === 'contact' && $contactType) {
            // Map composite column names to blade key suffixes
            if ($col === 'first_name+last_name') {
                $col = 'name';
            }
            return $contactType . '_' . $col;
        }

        if ($type === 'property') {
            // Property columns map directly (rental_amount, complex_name, etc.)
            return $col;
        }

        if ($type === 'agent') {
            return 'agent_' . $col;
        }

        if ($type === 'manual') {
            // Manual fields — snake_case the display name
            return str_replace(' ', '_', strtolower($nf->name));
        }

        return null;
    }

    // ──────────────────────────────────────────────
    // Deferred Signing
    // ──────────────────────────────────────────────

    /**
     * Resume a deferred signing request — agent provides party details.
     */
    public function resumeDeferred(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();

        if (!in_array($template->status, [
            SignatureTemplate::STATUS_AWAITING_DEFERRED,
            SignatureTemplate::STATUS_PARTIAL,
        ])) {
            return back()->with('error', 'This document does not have any deferred signers.');
        }

        $request->validate([
            'signer_name' => 'required|string|max:255',
            'signer_email' => 'required|email|max:255',
            'signer_id_number' => 'nullable|string|max:20',
            'signer_cell' => 'nullable|string|max:20',
            'request_id' => 'required|integer',
        ]);

        $deferredRequest = $template->requests()
            ->where('id', $request->request_id)
            ->where('status', SignatureRequest::STATUS_DEFERRED)
            ->firstOrFail();

        $result = $this->signatureService->resumeDeferredSigning(
            $template,
            $deferredRequest,
            $request->signer_name,
            $request->signer_email,
            $request->signer_id_number,
            $request->signer_cell
        );

        $templateType = $document->template?->template_type ?? 'rentals';
        $dashboardRoute = $templateType === 'sales' ? 'docuperfect.sales' : 'docuperfect.rental';

        return redirect()->route($dashboardRoute)
            ->with('status', "Signing resumed — {$request->signer_name} will be sent the document for signing.");
    }

    /**
     * Show property documents with signing status (property document dashboard).
     */
    public function propertyDocuments(Request $request, $propertyId)
    {
        $user = $request->user();
        $property = \App\Models\Property::findOrFail($propertyId);

        $documents = Document::where('property_id', $propertyId)
            ->with(['signatureTemplate.requests'])
            ->orderByDesc('created_at')
            ->get();

        $documentRows = $documents->map(function ($doc) {
            $sigTemplate = $doc->signatureTemplate;
            if (!$sigTemplate) return null;

            $parties = $sigTemplate->parties_json ?? [];
            $partyStatuses = [];

            foreach ($parties as $party) {
                $req = $sigTemplate->requests->firstWhere('party_role', $party['role']);
                $partyStatuses[] = [
                    'role' => $party['role'],
                    'role_label' => $party['role_label'] ?? $party['role'],
                    'name' => $party['name'] ?? '',
                    'status' => $req?->status ?? 'unknown',
                    'is_deferred' => $req?->status === SignatureRequest::STATUS_DEFERRED,
                    'is_complete' => $req?->status === SignatureRequest::STATUS_COMPLETED,
                    'request_id' => $req?->id,
                ];
            }

            return [
                'document' => $doc,
                'template' => $sigTemplate,
                'party_statuses' => $partyStatuses,
                'is_complete' => $sigTemplate->isComplete(),
                'is_deferred' => $sigTemplate->status === SignatureTemplate::STATUS_AWAITING_DEFERRED,
            ];
        })->filter();

        return view('docuperfect.signatures.property-documents', [
            'property' => $property,
            'documentRows' => $documentRows,
        ]);
    }

    // ──────────────────────────────────────────────
    // Section-by-Section Signing (Agent/Internal)
    // ──────────────────────────────────────────────

    /**
     * Accept a section (agent signing).
     */
    public function acceptSection(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        $agentRequest = $template->requests()->where('party_role', 'agent')->firstOrFail();

        $request->validate([
            'section_index' => 'required|integer|min:0',
            'section_label' => 'required|string|max:255',
            'initial_image' => 'nullable|string',
        ]);

        $acceptance = \App\Models\Docuperfect\SectionAcceptance::updateOrCreate(
            [
                'signature_request_id' => $agentRequest->id,
                'section_index' => $request->section_index,
            ],
            [
                'section_label' => $request->section_label,
                'accepted' => true,
                'rejected' => false,
                'rejection_reason' => null,
                'initialled_at' => now(),
                'initial_image' => $request->initial_image,
            ]
        );

        return response()->json(['success' => true, 'acceptance' => $acceptance]);
    }

    /**
     * Get section progress for agent signing.
     */
    public function getSectionProgress(Request $request, Document $document)
    {
        $user = $request->user();
        $this->authorizeDocument($user, $document);

        $template = SignatureTemplate::where('document_id', $document->id)->firstOrFail();
        $agentRequest = $template->requests()->where('party_role', 'agent')->firstOrFail();
        $agentRequest->loadMissing('sectionAcceptances');

        $sections = $template->sections_json ?? [];
        $progress = [];

        foreach ($sections as $idx => $section) {
            $acceptance = $agentRequest->sectionAcceptances->firstWhere('section_index', $idx);
            $progress[] = [
                'index' => $idx,
                'label' => $section['label'] ?? "Section " . ($idx + 1),
                'accepted' => $acceptance?->accepted ?? false,
                'rejected' => $acceptance?->rejected ?? false,
                'rejection_reason' => $acceptance?->rejection_reason,
                'initialled_at' => $acceptance?->initialled_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'sections' => $sections,
            'progress' => $progress,
            'total' => count($sections),
            'accepted' => collect($progress)->where('accepted', true)->count(),
        ]);
    }

    // ──────────────────────────────────────────────
    // Amendment Management (Agent/Internal)
    // ──────────────────────────────────────────────

    /**
     * List amendments for a document (JSON for review page).
     */
    public function amendments(Request $request, Document $document)
    {
        $template = $document->signatureTemplate;
        if (!$template) {
            return response()->json(['amendments' => []]);
        }

        $amendments = $this->signatureService->getAmendmentsWithStatus($template);

        return response()->json(['amendments' => $amendments]);
    }

    /**
     * Agent accepts or rejects a specific amendment.
     */
    public function amendmentAction(Request $request, Document $document, $amendmentId)
    {
        $amendment = \App\Models\Docuperfect\DocumentAmendment::where('document_id', $document->id)
            ->findOrFail($amendmentId);

        $action = $request->input('action'); // 'accept' or 'reject'
        $reason = $request->input('reason');

        if (!in_array($action, ['accept', 'reject'])) {
            return response()->json(['ok' => false, 'error' => 'Invalid action.'], 422);
        }

        $this->signatureService->agentAmendmentAction($amendment, $action, $reason);

        return response()->json([
            'ok' => true,
            'action' => $action,
            'amendment_id' => $amendment->id,
        ]);
    }
}
